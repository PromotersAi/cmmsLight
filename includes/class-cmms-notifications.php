<?php
/**
 * Notifications subsystem.
 *
 * Two channels per event, both gated by the user's preferences:
 *   1. In-app feed (always written to DB, shown in the bell dropdown)
 *   2. Email via wp_mail() (sent immediately or queued depending on context)
 *
 * The DB row is created first, then we attempt to email. Email failure does NOT
 * lose the notification - the user still sees it in the bell.
 *
 * Event types we emit (use these constants - do not hand-write strings):
 *   - task_assigned     : a task was assigned to this user
 *   - task_managed      : this user is the manager of a task that was created
 *   - task_due_before   : reminder fired before due_at
 *   - task_overdue      : reminder fired after due_at and task is still open
 *   - task_commented    : a comment was added on a task this user owns/manages
 *   - task_status       : status changed on a task this user owns/manages
 *   - user_invited      : welcome email for a new user (no in-app row needed)
 *   - public_submitted  : a public form submission created a task in your queue
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Notifications {

    const TYPE_TASK_ASSIGNED    = 'task_assigned';
    const TYPE_TASK_MANAGED     = 'task_managed';
    const TYPE_TASK_DUE_BEFORE  = 'task_due_before';
    const TYPE_TASK_OVERDUE     = 'task_overdue';
    const TYPE_TASK_COMMENTED   = 'task_commented';
    const TYPE_TASK_STATUS      = 'task_status';
    const TYPE_USER_INVITED     = 'user_invited';
    const TYPE_PUBLIC_SUBMITTED = 'public_submitted';

    /* ============================================================
     * Centralized recipient resolver (1.14.20)
     *
     * Single source of truth for "who gets notified about event X on
     * task Y, given that user Z performed it". All cron handlers use
     * this — never compute recipients inline anymore.
     *
     * Rules per event type:
     *
     *   task_created      assignee + manager + creator
     *                     (the creator is included so a user that filed
     *                      a task gets a confirmation in their bell —
     *                      even if they assigned it to themselves, the
     *                      dedupe step prevents duplicates)
     *
     *   task_assigned     new assignee + manager + creator
     *                     (the OLD assignee is intentionally not on the
     *                      list; they no longer own the work)
     *
     *   task_status_changed
     *                     assignee + manager + creator
     *
     *   task_commented    assignee + manager + creator
     *
     *   task_due_before / task_overdue
     *                     assignee + manager + creator
     *                     (everyone on the work needs to know)
     *
     *   public_submitted  assignee + manager
     *                     (creator is the public form, not a cmms user)
     *
     * Global rules:
     *   - All ids are deduplicated.
     *   - Zeros / nulls / non-positive ids are filtered out.
     *   - The actor (the user who performed the action) is removed —
     *     except if they are the only recipient. We do NOT want a user
     *     who creates a task and assigns it only to themselves to walk
     *     away with zero feedback that anything happened.
     *   - The 'cmms_task_related_users' filter (introduced 1.14.19) is
     *     applied via CMMS_Auth::task_related_user_ids, so future
     *     watchers extension hooks here automatically.
     *
     * @param string      $event   one of self::TYPE_* constants
     * @param object|int  $task    task row or id
     * @param int         $actor   cmms user id who triggered the event
     * @return int[]               cmms user ids to notify
     * ============================================================ */
    public static function resolve_recipients( $event, $task, $actor = 0 ) {
        // Resolve task. If the row is missing we can't notify anyone.
        if ( is_numeric( $task ) ) {
            $task = CMMS_Tasks::get( (int) $task );
        }
        if ( ! is_object( $task ) ) {
            return array();
        }
        $actor = (int) $actor;

        // Use the central CMMS_Auth helper so that the "watchers" filter
        // hook is honored and the related-users definition stays in one
        // place. For most events the related set is exactly what we want.
        $related = class_exists( 'CMMS_Auth' )
            ? CMMS_Auth::task_related_user_ids( $task )
            : array_filter( array(
                  (int) ( $task->assigned_to ?? 0 ),
                  (int) ( $task->manager_id  ?? 0 ),
                  (int) ( $task->created_by  ?? 0 ),
              ) );

        // public_submitted: a public form, not a logged-in user, made
        // this. Notify only people who can act on it — assignee/manager.
        if ( $event === self::TYPE_PUBLIC_SUBMITTED ) {
            $related = array_filter( $related, function( $id ) use ( $task ) {
                return (int) $id === (int) ( $task->assigned_to ?? 0 )
                    || (int) $id === (int) ( $task->manager_id  ?? 0 );
            } );
        }

        // Allow integrations to add or remove recipients per-event. The
        // task object and event are passed for context.
        $recipients = (array) apply_filters(
            'cmms_notification_recipients', $related, $event, $task, $actor
        );

        // Normalize: positive ints, deduped, reindexed.
        $recipients = array_filter( array_map( 'intval', $recipients ),
            function( $id ) { return $id > 0; } );
        $recipients = array_values( array_unique( $recipients ) );

        // Remove the actor — unless they would otherwise be the only
        // recipient. The "self-assign with no one else" case is the
        // important one: the user did just take ownership; the bell
        // should reflect that.
        if ( $actor > 0 && count( $recipients ) > 1 ) {
            $recipients = array_values( array_filter(
                $recipients,
                function( $id ) use ( $actor ) { return (int) $id !== $actor; }
            ) );
        }

        return $recipients;
    }

    /**
     * Structured log line. Read by tail -f on debug.log; the prefix
     * 'CMMS_NOTIFY' makes it easy to grep for. Best-effort — never
     * throws, never breaks the caller, never logs sensitive content.
     *
     * Usage:
     *   self::log_event( 'task_created', $task_id, array(
     *       'recipients' => array(3, 7), 'actor' => 9
     *   ) );
     */
    public static function log_event( $event, $task_id, $context = array() ) {
        try {
            $parts = array(
                'event=' . $event,
                'task_id=' . (int) $task_id,
            );
            foreach ( $context as $k => $v ) {
                if ( is_array( $v ) ) $v = '[' . implode( ',', array_map( 'intval', $v ) ) . ']';
                if ( is_bool( $v ) ) $v = $v ? 'true' : 'false';
                if ( is_null( $v ) ) $v = 'null';
                $parts[] = $k . '=' . $v;
            }
            error_log( 'CMMS_NOTIFY ' . implode( ' ', $parts ) );
        } catch ( \Throwable $e ) {
            // Logging itself must never propagate.
        }
    }

    /**
     * Create a notification: always writes the in-app row, optionally emails.
     *
     * @param array $args {
     *     @type int    $account_id  Required.
     *     @type int    $user_id     Required (CMMS user id, not WP id).
     *     @type string $type        One of the TYPE_* constants.
     *     @type int    $task_id     Optional task id.
     *     @type string $title       Short headline (shown in bell).
     *     @type string $body        Longer body (shown in dropdown + email).
     *     @type string $url         Deep-link to the relevant view.
     *     @type bool   $email       Whether to attempt email (default true).
     *     @type bool   $in_app      Whether to write in-app row (default true).
     * }
     * @return int|false  Notification id, or false on failure.
     */
    public static function notify( $args ) {
        global $wpdb;
        $defaults = array(
            'account_id' => 0,
            'user_id'    => 0,
            'type'       => '',
            'task_id'    => null,
            'title'      => '',
            'body'       => '',
            'url'        => '',
            'lang'       => '',
            'email'      => true,
            'in_app'     => true,
        );
        $args = wp_parse_args( $args, $defaults );

        if ( ! $args['account_id'] || ! $args['user_id'] || ! $args['type'] || ! $args['title'] ) {
            self::log_event( 'notify_skipped', (int) ( $args['task_id'] ?: 0 ), array(
                'reason' => 'missing_required',
                'user'   => (int) $args['user_id'],
                'type'   => (string) $args['type'],
            ) );
            return false;
        }

        // PRIORITY 1: in-app row. Must succeed if requested. Wrapped in
        // try/catch so a DB hiccup on this insert can't break the caller
        // (task creation, status change, etc.).
        $notif_id = 0;
        $inapp_status = 'skipped';
        if ( $args['in_app'] ) {
            try {
                $ok = $wpdb->insert(
                    CMMS_DB::table( 'notifications' ),
                    array(
                        'account_id' => (int) $args['account_id'],
                        'user_id'    => (int) $args['user_id'],
                        'type'       => sanitize_key( $args['type'] ),
                        'task_id'    => $args['task_id'] ? (int) $args['task_id'] : null,
                        'title'      => substr( wp_strip_all_tags( $args['title'] ), 0, 255 ),
                        'body'       => wp_strip_all_tags( $args['body'] ),
                        'url'        => esc_url_raw( $args['url'] ),
                        'created_at' => current_time( 'mysql' ),
                    ),
                    array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
                );
                if ( $ok !== false ) {
                    $notif_id = (int) $wpdb->insert_id;
                    $inapp_status = 'ok';
                } else {
                    $inapp_status = 'db_error';
                }
            } catch ( \Throwable $e ) {
                error_log( 'CMMS_NOTIFY notify(): in-app insert threw: ' . $e->getMessage() );
                $inapp_status = 'exception';
            }
        }

        // PRIORITY 2: email. Independent of in-app — failure here must
        // not undo or block the in-app row.
        $email_status = 'skipped';
        if ( $args['email'] ) {
            try {
                if ( ! self::user_email_enabled( (int) $args['user_id'], $args['type'] ) ) {
                    $email_status = 'muted_by_user';
                } else {
                    $sent = self::send_email_to_user(
                        (int) $args['user_id'],
                        $args['title'],
                        $args['body'],
                        $args['url'],
                        $args['lang']
                    );
                    $email_status = $sent ? 'ok' : 'wp_mail_false';
                    if ( $sent && $notif_id ) {
                        $wpdb->update(
                            CMMS_DB::table( 'notifications' ),
                            array( 'email_sent' => 1, 'email_sent_at' => current_time( 'mysql' ) ),
                            array( 'id' => $notif_id ),
                            array( '%d', '%s' ),
                            array( '%d' )
                        );
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( 'CMMS_NOTIFY notify(): email send threw: ' . $e->getMessage() );
                $email_status = 'exception';
            }
        }

        // PRIORITY 3: web push. Failure must not affect anything above.
        $push_status = 'skipped';
        if ( class_exists( 'CMMS_Push' ) ) {
            try {
                CMMS_Push::send_to_user(
                    (int) $args['user_id'],
                    $args['title'],
                    $args['body'],
                    $args['url']
                );
                $push_status = 'attempted';
            } catch ( \Throwable $e ) {
                error_log( 'CMMS_NOTIFY notify(): push send threw: ' . $e->getMessage() );
                $push_status = 'exception';
            }
        }

        // Single structured log line per recipient — easy to grep.
        self::log_event( 'notify_sent', (int) ( $args['task_id'] ?: 0 ), array(
            'user'    => (int) $args['user_id'],
            'type'    => (string) $args['type'],
            'inapp'   => $inapp_status,
            'email'   => $email_status,
            'push'    => $push_status,
            'notif_id'=> $notif_id,
        ) );

        return $notif_id ?: ( $inapp_status === 'skipped' ? true : false );
    }

    /**
     * Send an HTML+plaintext email to a CMMS user (looks up WP email).
     */
    public static function send_email_to_user( $cmms_user_id, $subject, $body, $url = '', $lang = '' ) {
        $user = self::get_wp_user_for_cmms_user( (int) $cmms_user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return false;
        }
        if ( ! $lang || ! class_exists( 'CMMS_I18n' ) ) {
            $lang = class_exists( 'CMMS_I18n' ) ? CMMS_I18n::default_lang() : 'en';
        }

        $site_name = self::brand_name();
        $subject = sprintf( '[%s] %s', $site_name, wp_strip_all_tags( $subject ) );

        $cta_html = '';
        $cta_text = '';
        if ( $url ) {
            $cta_label = class_exists( 'CMMS_I18n' )
                ? CMMS_I18n::t_in( 'cron.cta_open', $lang )
                : 'Open in CMMS';
            $cta_html = '<p style="margin:24px 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 24px;background:#ff6a00;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">' . esc_html( $cta_label ) . '</a></p>';
            $cta_text = "\n\n" . $cta_label . ': ' . $url;
        }

        $html = self::render_email_html( $subject, $body, $cta_html, $lang );
        $text = wp_strip_all_tags( $body ) . $cta_text;

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );
        $from = self::email_from();
        if ( $from ) {
            $headers[] = 'From: ' . $from;
        }

        // Send HTML by default; wp_mail will use the Content-Type header.
        return wp_mail( $user->user_email, $subject, $html, $headers );
    }

    private static function render_email_html( $subject, $body, $cta_html = '', $lang = '' ) {
        $brand     = self::brand_name();
        $body_html = wpautop( esc_html( $body ) );
        $year      = gmdate( 'Y' );
        $logo_url  = home_url( '/' );

        // Direction based on the recipient's language so RTL languages
        // (Hebrew, Arabic) render correctly in the email client.
        if ( ! $lang || ! class_exists( 'CMMS_I18n' ) ) {
            $lang = class_exists( 'CMMS_I18n' ) ? CMMS_I18n::default_lang() : 'en';
        }
        $langs = class_exists( 'CMMS_I18n' ) ? CMMS_I18n::langs() : array();
        $dir   = isset( $langs[ $lang ]['dir'] ) ? $langs[ $lang ]['dir'] : 'ltr';
        $align = $dir === 'rtl' ? 'right' : 'left';
        $brand_margin = $dir === 'rtl' ? 'margin-right:12px' : 'margin-left:12px';

        return '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $dir ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>
        <body style="margin:0;padding:0;background:#f3f6fb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;direction:' . esc_attr( $dir ) . ';text-align:' . esc_attr( $align ) . ';">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f6fb;padding:32px 16px;direction:' . esc_attr( $dir ) . ';">
                <tr><td align="center">
                    <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" dir="' . esc_attr( $dir ) . '" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);direction:' . esc_attr( $dir ) . ';text-align:' . esc_attr( $align ) . ';">
                        <tr><td style="background:linear-gradient(135deg,#0b1c33 0%,#14304f 100%);padding:24px 32px;color:#ffffff;text-align:' . esc_attr( $align ) . ';">
                            <div style="display:inline-block;width:40px;height:40px;background:#ff6a00;border-radius:8px;text-align:center;line-height:40px;vertical-align:middle;">
                                <span style="display:inline-block;color:#ffffff;font-weight:700;font-size:18px;">⚙</span>
                            </div>
                            <span style="display:inline-block;' . $brand_margin . ';font-size:18px;font-weight:700;letter-spacing:-0.01em;vertical-align:middle;">' . esc_html( $brand ) . '</span>
                        </td></tr>
                        <tr><td style="padding:32px;font-size:15px;line-height:1.6;color:#1e293b;text-align:' . esc_attr( $align ) . ';">' . $body_html . $cta_html . '</td></tr>
                        <tr><td style="padding:16px 32px;border-top:1px solid #e2e8f0;background:#f8fafc;font-size:12px;color:#64748b;text-align:center;">
                            ' . sprintf( esc_html__( 'You are receiving this because you have an active account at %s.', 'cmms-light' ), '<a href="' . esc_url( $logo_url ) . '" style="color:#14304f;">' . esc_html( $brand ) . '</a>' ) . '<br>
                            &copy; ' . esc_html( $year ) . ' ' . esc_html( $brand ) . '
                        </td></tr>
                    </table>
                </td></tr>
            </table>
        </body></html>';
    }

    private static function brand_name() {
        $brand = get_option( 'cmms_brand_name' );
        if ( $brand ) return $brand;
        return get_bloginfo( 'name' ) ?: 'CMMS';
    }

    private static function email_from() {
        $name  = self::brand_name();
        $email = get_option( 'cmms_from_email' );
        if ( ! $email ) {
            // Fallback to admin email
            $email = get_option( 'admin_email' );
        }
        if ( ! is_email( $email ) ) return null;
        return sprintf( '%s <%s>', $name, $email );
    }

    private static function get_wp_user_for_cmms_user( $cmms_user_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT wp_user_id FROM " . CMMS_DB::table( 'users' ) . " WHERE id = %d",
            $cmms_user_id
        ) );
        if ( ! $row ) return null;
        return get_user_by( 'id', (int) $row->wp_user_id );
    }

    /**
     * Per-user opt-out of an event type. Stored in WP user meta as a JSON array
     * of types the user has muted. By default everything is on.
     */
    public static function user_email_enabled( $cmms_user_id, $type ) {
        $user = self::get_wp_user_for_cmms_user( $cmms_user_id );
        if ( ! $user ) return false;
        $muted = get_user_meta( $user->ID, 'cmms_email_muted_types', true );
        if ( ! is_array( $muted ) ) return true;
        return ! in_array( $type, $muted, true );
    }

    /* ============================================================
       Querying for the bell dropdown
    ============================================================ */

    public static function unread_count_for_user( $cmms_user_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . CMMS_DB::table( 'notifications' ) . " WHERE user_id = %d AND read_at IS NULL",
            $cmms_user_id
        ) );
    }

    public static function recent_for_user( $cmms_user_id, $limit = 20 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . CMMS_DB::table( 'notifications' ) . " WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $cmms_user_id, (int) $limit
        ) );
    }

    public static function mark_all_read( $cmms_user_id ) {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare(
            "UPDATE " . CMMS_DB::table( 'notifications' ) . " SET read_at = %s WHERE user_id = %d AND read_at IS NULL",
            current_time( 'mysql' ), $cmms_user_id
        ) );
    }

    public static function mark_one_read( $cmms_user_id, $notif_id ) {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare(
            "UPDATE " . CMMS_DB::table( 'notifications' ) . " SET read_at = %s WHERE id = %d AND user_id = %d AND read_at IS NULL",
            current_time( 'mysql' ), (int) $notif_id, (int) $cmms_user_id
        ) );
    }

    /* ============================================================
       Settings: account-level reminder configuration
    ============================================================ */

    public static function default_account_settings() {
        return array(
            'remind_before_enabled' => 1,
            'remind_before_hours'   => 24,    // reminder N hours before due_at
            'remind_after_enabled'  => 1,
            'remind_after_hours'    => 24,    // reminder N hours after due_at if not done
            'remind_after_repeat'   => 0,     // 0 = single, otherwise repeat every N hours up to a max
            'remind_after_max'      => 3,     // max repeated reminders
        );
    }

    public static function get_account_settings( $account_id ) {
        $key = 'cmms_notify_settings_' . (int) $account_id;
        $stored = get_option( $key );
        if ( ! is_array( $stored ) ) $stored = array();
        return wp_parse_args( $stored, self::default_account_settings() );
    }

    public static function save_account_settings( $account_id, $settings ) {
        $defaults = self::default_account_settings();
        $clean = array();
        foreach ( $defaults as $k => $default ) {
            if ( ! isset( $settings[ $k ] ) ) { $clean[ $k ] = $default; continue; }
            if ( in_array( $k, array( 'remind_before_enabled', 'remind_after_enabled' ), true ) ) {
                $clean[ $k ] = $settings[ $k ] ? 1 : 0;
            } else {
                $clean[ $k ] = max( 0, (int) $settings[ $k ] );
            }
        }
        update_option( 'cmms_notify_settings_' . (int) $account_id, $clean, false );
        return $clean;
    }

    /* ============================================================
       SMTP detection - shows admin warning when wp_mail goes through PHP mail()
    ============================================================ */

    public static function smtp_likely_configured() {
        // Heuristic: if PHPMailer has been hooked (phpmailer_init) by an SMTP plugin, assume it's set up.
        $known_plugins = array(
            'wp-mail-smtp/wp_mail_smtp.php',
            'fluent-smtp/fluent-smtp.php',
            'easy-wp-smtp/easy-wp-smtp.php',
            'post-smtp/postman-smtp.php',
            'wp-smtp/wp-smtp.php',
            'gmail-smtp/main.php',
        );
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ( $known_plugins as $plugin ) {
            if ( is_plugin_active( $plugin ) ) return true;
        }
        // If the user explicitly dismissed the warning, treat as configured.
        if ( get_option( 'cmms_smtp_warning_dismissed' ) ) return true;
        return false;
    }
}
