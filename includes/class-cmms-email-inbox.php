<?php
/**
 * CMMS Email-to-Task Inbox
 *
 * Receives forwarded emails as JSON via REST endpoint and turns them
 * into tasks. The flow:
 *
 *   Email sent to: <slug>@tasks.cmms.co.il
 *      ↓
 *   CloudFlare Email Worker parses MIME, builds JSON payload
 *      ↓
 *   POST /wp-json/cmms-light/v1/email-inbox/<slug>
 *   with Header: X-CMMS-Signature: hmac-sha256(secret, body)
 *      ↓
 *   This class validates the signature, resolves slug → form,
 *   maps email fields to task fields, delegates to
 *   CMMS_Forms::process_submission (same code path as webhooks
 *   and public form submissions — single source of truth).
 *
 * Design rationale (1.14.98):
 *   - Slug is the public identifier (the email address local-part).
 *     Unguessable: 12 random chars, lowercase + digits.
 *   - HMAC secret is shared between the CloudFlare Worker and us.
 *     One secret per account (stored in account options) so the
 *     Worker doesn't need per-form keys.
 *   - We accept emails even WITHOUT a signature in 'open' mode
 *     (for testing with cURL) — production deployments should
 *     require signatures via filter.
 *   - Email fields → task fields:
 *       subject  → title
 *       body     → description
 *       from     → appended to description ("From: sender@x.com")
 *       attachments → task attachments (downloaded server-side)
 *     Dynamic form fields stay empty — we can't parse them from
 *     free-form email text.
 *
 * Why HMAC and not Bearer token:
 *   The Worker is a generic forwarder — it doesn't know per-form
 *   keys, only per-account secrets. HMAC lets us prove the request
 *   came from the Worker without exposing per-form secrets.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Email_Inbox {

    const ROUTE_NAMESPACE = 'cmms-light/v1';

    /**
     * Hook into WordPress REST API. Called from CMMS_Plugin::run via
     * the 'rest_api_init' action.
     */
    public static function register_routes() {
        register_rest_route( self::ROUTE_NAMESPACE, '/email-inbox/(?P<slug>[a-z0-9-]+)', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_request' ),
            // We do our own auth (HMAC signature in header). REST permission
            // callback must return true so WordPress doesn't reject the
            // request before we read the signature header.
            'permission_callback' => '__return_true',
            'args'                => array(
                'slug' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ),
            ),
        ) );
    }

    // ─────────────────────────────────────────────────────────────────
    // SLUG MANAGEMENT (CRUD for email routes)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Generate (or rotate) an email slug for a form. Returns the slug.
     *
     * @return string|WP_Error
     */
    public static function generate_slug( $form_id, $account_id ) {
        global $wpdb;
        $form_id    = (int) $form_id;
        $account_id = (int) $account_id;

        // Confirm the form belongs to the account (defense in depth).
        $form = CMMS_Forms::get( $form_id );
        if ( ! $form || (int) $form->account_id !== $account_id ) {
            return new WP_Error( 'invalid_form', __( 'Form not found', 'cmms-light' ) );
        }

        // Slug format: <name-hint>-<random>. We use the form slug as a
        // hint for human readability ("elevators-x7k2") but append a
        // random suffix so the address is unguessable.
        //
        // Hint sanitization: lowercase, alphanum + dashes only, max 24
        // chars. Hebrew/Unicode form names get reduced to dashes; if
        // nothing usable remains we use a fixed prefix.
        $hint = strtolower( $form->slug );
        $hint = preg_replace( '/[^a-z0-9]+/', '-', $hint );
        $hint = trim( $hint, '-' );
        $hint = substr( $hint, 0, 24 );
        if ( $hint === '' ) {
            $hint = 'form';
        }

        // 6 random alphanumeric chars (~36 bits of entropy). Combined
        // with the hint, the full local-part is unguessable enough to
        // survive directory-scanning attacks.
        $random = strtolower( wp_generate_password( 6, false, false ) );
        $slug   = $hint . '-' . $random;

        // Collision check: extremely unlikely but cheap to verify.
        $table = CMMS_DB::table( 'email_routes' );
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE email_slug = %s",
            $slug
        ) );
        if ( $exists ) {
            // Try once more with a longer random tail.
            $random = strtolower( wp_generate_password( 10, false, false ) );
            $slug   = $hint . '-' . $random;
        }

        // Upsert: rotate if a route already exists for this form.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE form_id = %d",
            $form_id
        ) );

        if ( $existing ) {
            $wpdb->update( $table, array(
                'email_slug'    => $slug,
                'enabled'       => 1,
                'last_used_at'  => null,
                'request_count' => 0,
                'created_at'    => current_time( 'mysql' ),
            ), array( 'id' => (int) $existing ),
               array( '%s', '%d', '%s', '%d', '%s' ),
               array( '%d' ) );
        } else {
            $wpdb->insert( $table, array(
                'form_id'       => $form_id,
                'account_id'    => $account_id,
                'email_slug'    => $slug,
                'enabled'       => 1,
                'request_count' => 0,
                'created_at'    => current_time( 'mysql' ),
            ), array( '%d', '%d', '%s', '%d', '%d', '%s' ) );
        }

        return $slug;
    }

    /**
     * Revoke (delete) the email route for a form. Idempotent.
     */
    public static function revoke_slug( $form_id, $account_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'email_routes' );
        $wpdb->delete( $table,
            array( 'form_id' => (int) $form_id, 'account_id' => (int) $account_id ),
            array( '%d', '%d' )
        );
    }

    /**
     * Get the route record for a form. Returns object with id,
     * email_slug, enabled, last_used_at, request_count, created_at —
     * or null if no route exists.
     */
    public static function get_route_info( $form_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'email_routes' );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, form_id, account_id, email_slug, enabled,
                    last_used_at, request_count, created_at
             FROM $table
             WHERE form_id = %d",
            (int) $form_id
        ) );
    }

    /**
     * Look up a route by its slug. Returns the row or null.
     */
    public static function get_route_by_slug( $slug ) {
        global $wpdb;
        $table = CMMS_DB::table( 'email_routes' );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, form_id, account_id, email_slug, enabled,
                    last_used_at, request_count, created_at
             FROM $table
             WHERE email_slug = %s",
            (string) $slug
        ) );
    }

    /**
     * Build the full email address for a form (tasks-slug@domain).
     *
     * Format: `tasks-<slug>@cmms.co.il` (1.15.1+)
     *   - The `tasks-` prefix is the routing key — CloudFlare's
     *     Email Routing rule matches `tasks-*@cmms.co.il` and sends
     *     matching emails to the Worker. Without this prefix the
     *     mail would fall through to the catch-all and be forwarded
     *     to Guy's mailbox instead of becoming a task.
     *   - Using the main domain (not a subdomain like tasks.cmms.co.il)
     *     means we don't need separate MX records — Email Routing on
     *     cmms.co.il is already live.
     *
     * Domain is filterable for white-label installs.
     */
    public static function email_address( $route ) {
        $domain = apply_filters( 'cmms_email_inbox_domain', 'cmms.co.il' );
        $prefix = apply_filters( 'cmms_email_inbox_prefix', 'tasks-' );
        return $prefix . $route->email_slug . '@' . $domain;
    }

    // ─────────────────────────────────────────────────────────────────
    // REQUEST HANDLING
    // ─────────────────────────────────────────────────────────────────

    /**
     * Main REST callback. Validates signature, transforms the email
     * payload into the post_data shape that process_submission expects,
     * and delegates.
     *
     * Expected JSON body:
     *   {
     *     "from":    "sender@example.com",
     *     "from_name": "Sender Name",     (optional)
     *     "subject": "Email subject line",
     *     "text":    "Plain-text body",
     *     "html":    "<p>HTML body</p>",  (optional, fallback to text)
     *     "attachments": [                 (optional)
     *       {
     *         "filename":     "report.pdf",
     *         "content_type": "application/pdf",
     *         "content_b64":  "<base64-encoded bytes>"
     *       }
     *     ],
     *     "_test": true                    (optional, returns preview)
     *   }
     */
    public static function handle_request( WP_REST_Request $request ) {
        $slug = $request->get_param( 'slug' );

        // 1. Resolve route. Slug is the public identifier.
        $route = self::get_route_by_slug( $slug );
        if ( ! $route ) {
            return self::error_response( 'route_not_found', __( 'Email route not found', 'cmms-light' ), 404 );
        }
        if ( ! $route->enabled ) {
            return self::error_response( 'route_disabled', __( 'Email route is disabled', 'cmms-light' ), 403 );
        }

        // 2. Resolve form. Same checks as webhook handler.
        $form = CMMS_Forms::get( $route->form_id );
        if ( ! $form ) {
            return self::error_response( 'form_not_found', __( 'Form not found', 'cmms-light' ), 404 );
        }
        if ( ! $form->enabled ) {
            return self::error_response( 'form_disabled', __( 'Form is disabled', 'cmms-light' ), 403 );
        }

        // 3. Authentication. HMAC-SHA256 of the raw request body, signed
        //    with the per-account shared secret. The Worker sets the
        //    'X-CMMS-Signature' header. We allow unsigned requests in
        //    development mode (no secret configured for the account)
        //    so cURL testing works out of the box.
        $secret = self::get_account_secret( $route->account_id );
        if ( $secret ) {
            $provided_sig = (string) $request->get_header( 'x-cmms-signature' );
            $raw_body     = $request->get_body();
            $expected_sig = hash_hmac( 'sha256', $raw_body, $secret );
            if ( ! $provided_sig || ! hash_equals( $expected_sig, $provided_sig ) ) {
                return self::error_response( 'invalid_signature', __( 'Invalid or missing signature', 'cmms-light' ), 401 );
            }
        }
        // If no secret configured, we accept the request as-is.
        // This is intentional for the development/testing phase.
        // Filter 'cmms_email_inbox_require_signature' lets ops require
        // signatures even without a secret (returns 401 in that case).
        if ( ! $secret && apply_filters( 'cmms_email_inbox_require_signature', false ) ) {
            return self::error_response( 'no_secret_configured', __( 'Signature required but no secret configured', 'cmms-light' ), 401 );
        }

        // 4. Parse payload.
        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            return self::error_response( 'invalid_json', __( 'Body must be valid JSON', 'cmms-light' ), 400 );
        }

        // 5. Test mode — return what would happen, no task created.
        $is_test = ! empty( $payload['_test'] );

        // 6. Transform email payload into task-shaped data.
        $transformed = self::transform_payload( $form, $payload );

        if ( $is_test ) {
            return self::success_response( array(
                'test_mode' => true,
                'would_create_task' => array(
                    'title'         => $transformed['post_data']['__webhook_title'] ?? '',
                    'description'   => $transformed['post_data']['__webhook_description'] ?? '',
                    'priority'      => $transformed['post_data']['__webhook_priority'] ?? $form->default_priority,
                    'status'        => $transformed['post_data']['__webhook_status'] ?? $form->default_status,
                    'form_id'       => (int) $form->id,
                    'form_name'     => $form->name,
                    'attachment_count' => count( $transformed['files'] ),
                ),
            ) );
        }

        // 7. Materialize base64 attachments into temp files on disk.
        //    NOTE: we do NOT pass these to process_submission — that
        //    path only handles HTTP $_FILES uploads (move_uploaded_file)
        //    and filters by 'field_' keys. Instead we attach them
        //    directly after the task is created (step 9b) via
        //    handle_upload_from_path, which works on disk paths.
        $materialized = array();
        if ( ! empty( $transformed['files'] ) ) {
            $materialized = self::materialize_attachments( $transformed['files'] );
        }

        // 8. Delegate to the shared submission processor for the TASK
        //    itself (title, description, fields). We pass an empty
        //    files array — attachments are handled separately below.
        $result = CMMS_Forms::process_submission(
            $form->slug,
            $transformed['post_data'],
            array(),        // attachments handled post-creation, not here
            true,           // skip required-field validation
            'email_inbox'   // source_type for reporting
        );

        // Extract sender/subject for the inbound log (both success
        // and failure paths use these).
        $log_from    = isset( $payload['from'] ) ? sanitize_email( $payload['from'] ) : '';
        $log_name    = isset( $payload['from_name'] ) ? sanitize_text_field( $payload['from_name'] ) : '';
        $log_subject = isset( $payload['subject'] ) ? sanitize_text_field( $payload['subject'] ) : '';

        if ( is_wp_error( $result ) ) {
            // Clean up materialized temp files since the task failed.
            self::cleanup_temp_files( $materialized );
            // Log the failure so it's visible/debuggable in the inbox log.
            self::log_inbound( array(
                'account_id'    => (int) $form->account_id,
                'form_id'       => (int) $form->id,
                'email_slug'    => $route->email_slug,
                'from_email'    => $log_from,
                'from_name'     => $log_name,
                'subject'       => $log_subject,
                'status'        => 'failed',
                'task_id'       => null,
                'error_message' => $result->get_error_message(),
            ) );
            return self::error_response(
                $result->get_error_code(),
                $result->get_error_message(),
                400
            );
        }

        // 9. Update usage stats on the route record.
        self::touch_route( $route->id );

        // process_submission returns the task id directly (int), or a
        // WP_Error (handled above). Earlier code treated it as an array
        // which always yielded null — fixed here.
        $new_task_id = is_numeric( $result ) ? (int) $result : null;

        // 9b. Attach materialized files directly to the new task.
        //     We bypass process_submission's file handling because it
        //     only accepts HTTP uploads; ours are on-disk temp files.
        if ( $new_task_id && ! empty( $materialized ) ) {
            foreach ( $materialized as $mf ) {
                if ( empty( $mf['tmp_name'] ) || ! file_exists( $mf['tmp_name'] ) ) {
                    continue;
                }
                CMMS_Attachments::handle_upload_from_path(
                    (int) $form->account_id,
                    'task',
                    $new_task_id,
                    $mf['tmp_name'],
                    $mf['name'],
                    null
                );
            }
            // Clean up any temp files that weren't moved (e.g. rejected
            // by mime check inside handle_upload_from_path).
            self::cleanup_temp_files( $materialized );
        }

        // 10. Log the successful inbound email.
        self::log_inbound( array(
            'account_id'    => (int) $form->account_id,
            'form_id'       => (int) $form->id,
            'email_slug'    => $route->email_slug,
            'from_email'    => $log_from,
            'from_name'     => $log_name,
            'subject'       => $log_subject,
            'status'        => 'created',
            'task_id'       => $new_task_id,
            'error_message' => null,
        ) );

        return self::success_response( array(
            'success' => true,
            'task_id' => $new_task_id,
        ) );
    }

    /**
     * Remove leftover temp files from the email-tmp dir. Safe to call
     * with files that were already moved (file_exists guards).
     */
    private static function cleanup_temp_files( $materialized ) {
        if ( empty( $materialized ) || ! is_array( $materialized ) ) return;
        foreach ( $materialized as $mf ) {
            if ( ! empty( $mf['tmp_name'] ) && file_exists( $mf['tmp_name'] ) ) {
                @unlink( $mf['tmp_name'] );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // INBOUND LOG (1.15.5)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Record one inbound email in the log. Best-effort — a logging
     * failure must never break task creation, so we swallow errors.
     */
    private static function log_inbound( $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'email_inbox_log' );

        // Subject can be long; the column is 255. Truncate safely
        // (mb-aware so we don't cut a Hebrew char in half).
        $subject = (string) ( $data['subject'] ?? '' );
        if ( function_exists( 'mb_substr' ) ) {
            $subject = mb_substr( $subject, 0, 250 );
        } else {
            $subject = substr( $subject, 0, 250 );
        }

        $err = $data['error_message'] ?? null;
        if ( $err !== null ) {
            $err = function_exists( 'mb_substr' ) ? mb_substr( (string) $err, 0, 250 ) : substr( (string) $err, 0, 250 );
        }

        // @phpcs:ignore — best-effort insert, errors intentionally ignored.
        @$wpdb->insert( $table, array(
            'account_id'    => (int) $data['account_id'],
            'form_id'       => isset( $data['form_id'] ) ? (int) $data['form_id'] : null,
            'email_slug'    => $data['email_slug'] ?? null,
            'from_email'    => $data['from_email'] ?? null,
            'from_name'     => $data['from_name'] ?? null,
            'subject'       => $subject,
            'status'        => $data['status'] ?? 'created',
            'task_id'       => isset( $data['task_id'] ) ? (int) $data['task_id'] : null,
            'error_message' => $err,
            'received_at'   => current_time( 'mysql' ),
        ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
    }

    /**
     * Get inbound log rows for an account, newest first.
     *
     * @param int $account_id
     * @param int $limit       Max rows (default 50)
     * @param int $form_id     Optional — filter to one form
     */
    public static function get_inbound_log( $account_id, $limit = 50, $form_id = 0 ) {
        global $wpdb;
        $table = CMMS_DB::table( 'email_inbox_log' );

        $limit   = max( 1, min( 200, (int) $limit ) );
        $form_id = (int) $form_id;

        if ( $form_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $table
                 WHERE account_id = %d AND form_id = %d
                 ORDER BY received_at DESC, id DESC
                 LIMIT %d",
                (int) $account_id, $form_id, $limit
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table
             WHERE account_id = %d
             ORDER BY received_at DESC, id DESC
             LIMIT %d",
            (int) $account_id, $limit
        ) );
    }

    /**
     * Count inbound emails for a form (used for the small counter
     * shown on the form-edit page).
     */
    public static function count_inbound_for_form( $form_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'email_inbox_log' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE form_id = %d AND status = 'created'",
            (int) $form_id
        ) );
    }

    /**
     * Delete log rows older than 30 days. Called from a scheduled
     * event (see CMMS_Plugin cron registration) to keep the table
     * from growing unbounded.
     */
    public static function prune_inbound_log() {
        global $wpdb;
        $table = CMMS_DB::table( 'email_inbox_log' );
        $wpdb->query(
            "DELETE FROM $table WHERE received_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // PAYLOAD TRANSFORMATION
    // ─────────────────────────────────────────────────────────────────

    /**
     * Convert an email payload (subject/body/from/attachments) into the
     * post_data + files shape that process_submission expects.
     *
     * Mapping:
     *   - subject  → __webhook_title
     *   - text     → __webhook_description (with "From: ..." appended)
     *   - html     → fallback if text missing, stripped to plain
     *   - from     → appended to description for traceability
     *   - attachments → files array (base64 → raw bytes later)
     */
    private static function transform_payload( $form, $payload ) {
        $post_data = array();

        // ── Title (from subject) ──
        $subject = isset( $payload['subject'] ) ? (string) $payload['subject'] : '';
        $subject = trim( wp_strip_all_tags( $subject ) );
        if ( $subject === '' ) {
            $subject = __( '(No subject)', 'cmms-light' );
        }
        $post_data['__webhook_title'] = $subject;

        // ── Description (from text body, fallback to HTML) ──
        $body_text = '';
        if ( isset( $payload['text'] ) && trim( (string) $payload['text'] ) !== '' ) {
            $body_text = (string) $payload['text'];
        } elseif ( isset( $payload['html'] ) ) {
            // Convert HTML to plain text. We use wp_strip_all_tags
            // rather than wp_kses_post because we want plain text in
            // the description, not formatted HTML (the public form
            // path also stores plain text).
            $body_text = wp_strip_all_tags( (string) $payload['html'] );
        }
        $body_text = trim( $body_text );

        // Append "From:" line for traceability. Email-to-task lacks
        // structured fields, so the only way to track who reported it
        // is to embed it in the description.
        $from       = isset( $payload['from'] ) ? sanitize_email( $payload['from'] ) : '';
        $from_name  = isset( $payload['from_name'] ) ? sanitize_text_field( $payload['from_name'] ) : '';
        $from_label = $from_name ? "$from_name <$from>" : $from;

        if ( $from_label ) {
            $body_text = trim( $body_text . "\n\n---\n" . sprintf(
                /* translators: %s: sender email (possibly with display name) */
                __( 'From: %s', 'cmms-light' ),
                $from_label
            ) );
        }

        $post_data['__webhook_description'] = $body_text;

        // ── Priority / Status (form defaults) ──
        // Email-to-task can't determine priority from free-form text.
        // We rely on the form's default. Dynamic field values stay
        // empty for the same reason.

        // ── Attachments ──
        // We keep them in a custom shape here ($payload-style with
        // base64 still). materialize_attachments converts to $_FILES-
        // shape just before delegating to process_submission.
        $files = array();
        if ( ! empty( $payload['attachments'] ) && is_array( $payload['attachments'] ) ) {
            foreach ( $payload['attachments'] as $att ) {
                if ( ! is_array( $att ) ) continue;
                if ( empty( $att['filename'] ) ) continue;
                if ( empty( $att['content_b64'] ) ) continue;
                $files[] = array(
                    'filename'     => sanitize_file_name( $att['filename'] ),
                    'content_type' => isset( $att['content_type'] ) ? sanitize_text_field( $att['content_type'] ) : 'application/octet-stream',
                    'content_b64'  => (string) $att['content_b64'],
                );
            }
        }

        return array(
            'post_data' => $post_data,
            'files'     => $files,
        );
    }

    /**
     * Decode base64 attachments into temp files and produce a $_FILES-
     * shaped array that process_submission can consume.
     *
     * We write to wp_upload_dir()/cmms-email-tmp/ and rely on
     * process_submission to move them to the final attachments dir.
     *
     * Security hardening (1.15.6):
     *   - Filenames are sanitized (sanitize_file_name) to block path
     *     traversal and unsafe characters.
     *   - Dangerous extensions (php, exe, sh, etc) are blocked even
     *     though we allow "everything" — executable code uploaded to
     *     the server is never acceptable. We allow all *document* and
     *     *media* types but not server-executable scripts.
     *   - Per-file size cap (15MB) and total cap (40MB) prevent abuse.
     */
    private static function materialize_attachments( $attachments ) {
        $upload_dir = wp_upload_dir();
        $tmp_dir    = $upload_dir['basedir'] . '/cmms-email-tmp';
        if ( ! is_dir( $tmp_dir ) ) {
            wp_mkdir_p( $tmp_dir );
        }

        // Extensions we refuse regardless of the "allow everything"
        // setting — these can execute on the server or are common
        // malware vectors. Everything else (images, PDF, Office, zip,
        // text, etc) is allowed.
        $blocked_ext = array(
            'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'pht',
            'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl',
            'jsp', 'asp', 'aspx', 'js', 'jar', 'msi', 'scr', 'vbs',
            'dll', 'so', 'htaccess',
        );

        $per_file_cap = 15 * 1024 * 1024; // 15MB per attachment
        $total_cap    = 40 * 1024 * 1024; // 40MB total per email
        $total_bytes  = 0;

        $files = array();
        foreach ( $attachments as $i => $att ) {
            $bytes = base64_decode( $att['content_b64'], true );
            if ( $bytes === false ) {
                // Malformed base64 — skip this attachment, don't fail
                // the whole email. The task will still be created.
                continue;
            }

            $size = strlen( $bytes );
            if ( $size === 0 || $size > $per_file_cap ) {
                continue; // empty or oversized — skip
            }
            if ( $total_bytes + $size > $total_cap ) {
                break; // hit the total cap — stop adding more
            }

            // Sanitize the filename: strips path components, unsafe
            // chars, leaving a clean basename. Fall back to a generic
            // name if sanitization leaves nothing.
            $raw_name  = isset( $att['filename'] ) ? (string) $att['filename'] : '';
            $safe_name = sanitize_file_name( $raw_name );
            if ( $safe_name === '' || $safe_name === '.' ) {
                $safe_name = 'attachment-' . ( $i + 1 );
            }

            // Block dangerous extensions.
            $ext = strtolower( pathinfo( $safe_name, PATHINFO_EXTENSION ) );
            if ( in_array( $ext, $blocked_ext, true ) ) {
                continue; // refuse executable/script files
            }

            $tmp_path = $tmp_dir . '/' . uniqid( 'email-att-', true ) . '-' . $safe_name;

            $written = file_put_contents( $tmp_path, $bytes );
            if ( $written === false ) {
                continue;
            }

            $total_bytes += $size;

            // Build $_FILES-shaped entry. Field name pattern matches
            // what process_submission expects from multipart uploads:
            // 'file_<n>' is treated as a generic attachment slot.
            $files[ 'email_attachment_' . $i ] = array(
                'name'     => $safe_name,
                'type'     => isset( $att['content_type'] ) ? (string) $att['content_type'] : 'application/octet-stream',
                'tmp_name' => $tmp_path,
                'error'    => UPLOAD_ERR_OK,
                'size'     => $size,
            );
        }

        return $files;
    }

    // ─────────────────────────────────────────────────────────────────
    // ACCOUNT SECRET (for HMAC signing)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Get the HMAC secret for an account. Returns null if no secret
     * configured (development mode — unsigned requests accepted).
     */
    public static function get_account_secret( $account_id ) {
        $secret = get_option( 'cmms_email_inbox_secret_' . (int) $account_id, '' );
        return $secret ? $secret : null;
    }

    /**
     * Generate and store a new HMAC secret for an account. Returns the
     * plaintext secret (caller must show it to the user once).
     */
    public static function rotate_account_secret( $account_id ) {
        $secret = bin2hex( random_bytes( 32 ) );
        update_option( 'cmms_email_inbox_secret_' . (int) $account_id, $secret, false );
        return $secret;
    }

    // ─────────────────────────────────────────────────────────────────
    // BOOKKEEPING
    // ─────────────────────────────────────────────────────────────────

    /**
     * Update last_used_at and increment request_count for a route.
     */
    private static function touch_route( $route_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'email_routes' );
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table
             SET last_used_at = %s, request_count = request_count + 1
             WHERE id = %d",
            current_time( 'mysql' ),
            (int) $route_id
        ) );
    }

    // ─────────────────────────────────────────────────────────────────
    // RESPONSE HELPERS
    // ─────────────────────────────────────────────────────────────────

    private static function success_response( $data ) {
        return new WP_REST_Response( $data, 200 );
    }

    private static function error_response( $code, $message, $status ) {
        return new WP_REST_Response( array(
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ), $status );
    }
}
