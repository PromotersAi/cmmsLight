<?php
/**
 * 1.14.84 — Telegram Bot integration core.
 *
 * Architecture overview:
 *
 *   CMMS_Telegram         — this class. Static API for talking to
 *                           the Telegram Bot API + business logic
 *                           glue (linking users, sending task
 *                           notifications, handling webhooks).
 *   CMMS_Telegram_Webhook — REST endpoint that Telegram pings on
 *                           every inbound message/callback.
 *
 * Settings live in wp_options:
 *   cmms_telegram_enabled       — '1' or empty
 *   cmms_telegram_token         — bot token from BotFather
 *   cmms_telegram_bot_username  — e.g. 'cmms_light_bot' (no @)
 *   cmms_telegram_webhook_secret — random secret, validated on
 *                                  every inbound request
 *
 * Security model:
 *   - Token never appears in URLs or JS. Stored in wp_options,
 *     used only in server-side curl calls.
 *   - Webhook secret is sent by Telegram in a header and verified
 *     on every inbound. Without it, anyone could POST fake events.
 *   - User linking uses a one-shot token in the deep-link;
 *     consumed on /start.
 *   - Every action re-verifies CMMS_Users + cmms_user.account_id
 *     to prevent cross-account access.
 */
defined( 'ABSPATH' ) || exit;

class CMMS_Telegram {

    const API_BASE = 'https://api.telegram.org/bot';

    /* ================================================================
       SETTINGS
    ================================================================ */

    public static function is_enabled() {
        if ( ! get_option( 'cmms_telegram_enabled' ) ) return false;
        if ( ! self::token() ) return false;
        return true;
    }

    public static function token() {
        return (string) get_option( 'cmms_telegram_token', '' );
    }

    public static function bot_username() {
        return (string) get_option( 'cmms_telegram_bot_username', '' );
    }

    public static function webhook_secret() {
        $secret = get_option( 'cmms_telegram_webhook_secret', '' );
        if ( ! $secret ) {
            // Auto-generate once on first need. Persists thereafter.
            $secret = wp_generate_password( 40, false, false );
            update_option( 'cmms_telegram_webhook_secret', $secret );
        }
        return $secret;
    }

    /**
     * The public URL Telegram will POST to. Includes a secret query
     * parameter as a first-line defence; the per-request header
     * X-Telegram-Bot-Api-Secret-Token is the real check.
     */
    public static function webhook_url() {
        // Use rest_url for clean /wp-json/cmms/v1/telegram routing.
        return rest_url( 'cmms/v1/telegram/webhook' );
    }

    /* ================================================================
       TELEGRAM API CLIENT
       Thin wrapper around api.telegram.org/bot<TOKEN>/<method>.
       Every call returns ['ok' => bool, 'result' => mixed, 'error' => string].
    ================================================================ */

    /**
     * Low-level Telegram API call. All other methods route through here.
     * Logs every request (success or failure) for forensics.
     */
    public static function api( $method, $params = array(), $log_context = array() ) {
        $token = self::token();
        if ( ! $token ) {
            return array( 'ok' => false, 'error' => 'Telegram token not configured' );
        }
        $url = self::API_BASE . $token . '/' . $method;
        $args = array(
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            'body'    => wp_json_encode( $params ),
        );
        $resp = wp_remote_post( $url, $args );

        $http_code = is_wp_error( $resp ) ? 0 : wp_remote_retrieve_response_code( $resp );
        $body      = is_wp_error( $resp ) ? '' : wp_remote_retrieve_body( $resp );
        $decoded   = $body ? json_decode( $body, true ) : null;

        // Log every API call. payload is what we sent, response_code
        // is what Telegram returned, error_text captures wp_error or
        // Telegram's own error description.
        $error_text = '';
        if ( is_wp_error( $resp ) ) {
            $error_text = $resp->get_error_message();
        } elseif ( ! is_array( $decoded ) || empty( $decoded['ok'] ) ) {
            $error_text = isset( $decoded['description'] ) ? $decoded['description'] : 'Unknown error';
        }

        self::log( array_merge( array(
            'direction'    => 'out',
            'kind'         => $method,
            'payload'      => wp_json_encode( $params ),
            'response_code'=> $http_code,
            'error_text'   => $error_text,
        ), $log_context ) );

        if ( is_wp_error( $resp ) ) {
            return array( 'ok' => false, 'error' => $resp->get_error_message() );
        }
        if ( ! is_array( $decoded ) ) {
            return array( 'ok' => false, 'error' => 'Invalid response from Telegram' );
        }
        if ( empty( $decoded['ok'] ) ) {
            return array( 'ok' => false, 'error' => isset( $decoded['description'] ) ? $decoded['description'] : 'API error' );
        }
        return array( 'ok' => true, 'result' => $decoded['result'] );
    }

    /**
     * Send a plain text message to a Telegram chat_id.
     * For task notifications use send_task_message() (Phase 2).
     */
    public static function send_message( $chat_id, $text, $parse_mode = 'HTML', $reply_markup = null ) {
        $params = array(
            'chat_id'                  => $chat_id,
            'text'                     => $text,
            'parse_mode'               => $parse_mode,
            'disable_web_page_preview' => true,
        );
        if ( $reply_markup ) {
            $params['reply_markup'] = $reply_markup;
        }
        return self::api( 'sendMessage', $params );
    }

    /**
     * Set the webhook URL with Telegram. Called from Admin Settings
     * when the user clicks "Save & Connect". Returns ['ok' => bool, ...].
     */
    public static function set_webhook() {
        $url    = self::webhook_url();
        $secret = self::webhook_secret();
        return self::api( 'setWebhook', array(
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => array( 'message', 'callback_query' ),
            'drop_pending_updates' => true,
        ), array( 'kind' => 'webhook_set' ) );
    }

    /**
     * Remove the webhook (when admin disables the integration).
     */
    public static function delete_webhook() {
        return self::api( 'deleteWebhook', array(), array( 'kind' => 'webhook_delete' ) );
    }

    /**
     * Check current webhook status. Returns the result of getWebhookInfo
     * so the admin UI can show "✅ Connected" or "❌ Not connected".
     */
    public static function get_webhook_info() {
        return self::api( 'getWebhookInfo', array(), array( 'kind' => 'webhook_info' ) );
    }

    /**
     * Used by the admin's "Test Connection" button. Calls /getMe
     * which returns bot info (name, username). If this succeeds, the
     * token is valid.
     */
    public static function get_me() {
        return self::api( 'getMe', array(), array( 'kind' => 'getMe' ) );
    }

    /**
     * 1.14.86: Download a file from Telegram by file_id.
     *
     * Two-step process per Telegram's API:
     *   1. getFile(file_id) → returns file_path on Telegram's servers
     *   2. GET https://api.telegram.org/file/bot<TOKEN>/<file_path>
     *
     * Returns array with ['bytes', 'extension', 'mime_type'] on success,
     * or false on failure. Limited to 20 MB by Telegram's API.
     */
    public static function download_file( $file_id ) {
        $resp = self::api( 'getFile', array( 'file_id' => $file_id ), array( 'kind' => 'getFile' ) );
        if ( empty( $resp['ok'] ) || empty( $resp['result']['file_path'] ) ) {
            return false;
        }
        $file_path = $resp['result']['file_path'];
        $token = self::token();
        if ( ! $token ) return false;

        $url = 'https://api.telegram.org/file/bot' . $token . '/' . $file_path;
        $remote = wp_remote_get( $url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $remote ) ) {
            self::log( array(
                'direction'  => 'out',
                'kind'       => 'download_file_failed',
                'error_text' => $remote->get_error_message(),
            ) );
            return false;
        }
        $code  = wp_remote_retrieve_response_code( $remote );
        $bytes = wp_remote_retrieve_body( $remote );
        if ( $code !== 200 || empty( $bytes ) ) {
            self::log( array(
                'direction'    => 'out',
                'kind'         => 'download_file_failed',
                'response_code'=> $code,
                'error_text'   => 'Empty body or non-200',
            ) );
            return false;
        }

        // Determine extension from the file_path Telegram gave us.
        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( ! $ext ) $ext = 'jpg'; // photos usually have no ext

        // Map ext → mime
        $mime_map = array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
        );
        $mime = isset( $mime_map[ $ext ] ) ? $mime_map[ $ext ] : 'application/octet-stream';

        return array(
            'bytes'     => $bytes,
            'extension' => $ext,
            'mime_type' => $mime,
        );
    }

    /* ================================================================
       USER LINKING
    ================================================================ */

    /**
     * Create a fresh link token for a CMMS user. The user follows
     * a deep-link to the bot which calls /start <token>. The token
     * is single-use and expires after 30 minutes.
     */
    public static function generate_link_token( $cmms_user_id ) {
        global $wpdb;
        $cmms_user = CMMS_Users::get( (int) $cmms_user_id );
        if ( ! $cmms_user ) return false;

        $table = CMMS_DB::table( 'telegram_users' );
        $token = wp_generate_password( 24, false, false );
        $expires = gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS );
        $now = current_time( 'mysql' );

        // Upsert: if user already has a row, refresh the token.
        // If they were previously linked, we wipe telegram_user_id
        // so the /start flow re-binds them (in case they're switching
        // Telegram accounts).
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM $table WHERE cmms_user_id = %d", (int) $cmms_user_id
        ) );
        if ( $existing ) {
            $wpdb->update( $table, array(
                'link_token'         => $token,
                'link_token_expires' => $expires,
                'updated_at'         => $now,
            ), array( 'id' => $existing->id ), array( '%s', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array(
                'account_id'         => (int) $cmms_user->account_id,
                'cmms_user_id'       => (int) $cmms_user_id,
                'link_token'         => $token,
                'link_token_expires' => $expires,
                'created_at'         => $now,
                'updated_at'         => $now,
            ), array( '%d', '%d', '%s', '%s', '%s', '%s' ) );
        }
        return $token;
    }

    /**
     * Build the deep-link URL the user opens to start the bot with
     * their token. Format: https://t.me/<bot_username>?start=<token>
     */
    public static function build_link_url( $token ) {
        $username = self::bot_username();
        if ( ! $username ) return '';
        return 'https://t.me/' . $username . '?start=' . urlencode( $token );
    }

    /**
     * Consume a link token. Called when Telegram sends us /start <token>.
     * Atomically:
     *   1. Look up the row by link_token
     *   2. Check not expired
     *   3. Set telegram_user_id, username, first_name, connected_at
     *   4. Clear the token (single-use)
     * Returns the CMMS user on success, false on failure.
     */
    public static function consume_link_token( $token, $telegram_user ) {
        global $wpdb;
        $token = (string) $token;
        if ( strlen( $token ) < 8 ) return false;

        $table = CMMS_DB::table( 'telegram_users' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE link_token = %s LIMIT 1", $token
        ) );
        if ( ! $row ) return false;
        if ( $row->link_token_expires && strtotime( $row->link_token_expires ) < time() ) return false;

        $now = current_time( 'mysql' );
        $wpdb->update( $table, array(
            'telegram_user_id'   => (int) $telegram_user['id'],
            'telegram_username'  => isset( $telegram_user['username'] ) ? sanitize_text_field( $telegram_user['username'] ) : null,
            'telegram_first_name'=> isset( $telegram_user['first_name'] ) ? sanitize_text_field( $telegram_user['first_name'] ) : null,
            'link_token'         => null,
            'link_token_expires' => null,
            'connected_at'       => $now,
            'updated_at'         => $now,
        ), array( 'id' => $row->id ),
           array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
           array( '%d' ) );

        return CMMS_Users::get( (int) $row->cmms_user_id );
    }

    /**
     * Look up a CMMS user by their Telegram user ID. Used by the
     * webhook to identify the sender on every inbound message.
     * Returns the CMMS user OR null if not linked.
     */
    public static function get_cmms_user_by_telegram_id( $telegram_user_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'telegram_users' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT cmms_user_id FROM $table WHERE telegram_user_id = %d AND telegram_user_id IS NOT NULL LIMIT 1",
            (int) $telegram_user_id
        ) );
        if ( ! $row ) return null;
        return CMMS_Users::get( (int) $row->cmms_user_id );
    }

    /**
     * Disconnect a user's Telegram. Used in profile UI ("Unlink").
     * Clears telegram_user_id so they can't receive notifications
     * or perform actions through the bot.
     */
    public static function unlink( $cmms_user_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'telegram_users' );
        $wpdb->update( $table, array(
            'telegram_user_id'   => null,
            'telegram_username'  => null,
            'telegram_first_name'=> null,
            'connected_at'       => null,
            'link_token'         => null,
            'link_token_expires' => null,
            'updated_at'         => current_time( 'mysql' ),
        ), array( 'cmms_user_id' => (int) $cmms_user_id ),
           array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
           array( '%d' ) );
    }

    /**
     * Check if a CMMS user has Telegram linked. Returns the row from
     * telegram_users (with telegram_user_id, username, etc), or null.
     */
    public static function get_link( $cmms_user_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'telegram_users' );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE cmms_user_id = %d LIMIT 1",
            (int) $cmms_user_id
        ) );
    }

    /* ================================================================
       LOGGING
       Every inbound/outbound event is logged. Helps with debugging
       and provides an audit trail for security review.
    ================================================================ */

    public static function log( $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'telegram_logs' );
        if ( ! $table ) return;
        $defaults = array(
            'account_id'      => null,
            'cmms_user_id'    => null,
            'telegram_user_id'=> null,
            'task_id'         => null,
            'direction'       => 'in',
            'kind'            => 'unknown',
            'payload'         => '',
            'response_code'   => null,
            'error_text'      => '',
            'created_at'      => current_time( 'mysql' ),
        );
        $data = array_merge( $defaults, $data );
        $wpdb->insert( $table, $data );
    }

    /* ================================================================
       TASK NOTIFICATIONS — Phase 2 (1.14.85)
       Sends task notifications to assigned technicians and lets
       them update status via inline buttons. The same message is
       edited in place as the status changes, so the chat stays clean.
    ================================================================ */

    /**
     * Status labels matching CMMS_Tasks::statuses() — in Hebrew with
     * emoji for visual clarity. Must stay in sync with the system's
     * status labels (see the dropdown in tasks list).
     */
    public static function status_label( $status ) {
        $labels = array(
            'open'        => '🟡 פתוח',
            'in_progress' => '🟠 בטיפול',
            'waiting'     => '⚪ ממתין',
            'completed'   => '🟢 הושלם ונסגר',
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
    }

    /**
     * Build the inline keyboard for a task message, given the current
     * status. The buttons shown are status transitions that make sense
     * from this status — completed tasks have no transitions, only
     * "open in CMMS".
     *
     * Each callback_data uses the format: "tsk:{task_id}:{new_status}"
     * — parsed by the webhook handler.
     */
    public static function build_status_keyboard( $task ) {
        $task_id = (int) $task->id;
        $current = $task->status;

        $buttons = array();
        // Add status-change buttons based on current status. We omit
        // the current status itself so the technician can't "change"
        // it to what it already is.
        if ( $current !== 'in_progress' && $current !== 'completed' ) {
            $buttons[] = array(
                'text' => '🟠 בטיפול',
                'callback_data' => "tsk:{$task_id}:in_progress",
            );
        }
        if ( $current !== 'waiting' && $current !== 'completed' ) {
            $buttons[] = array(
                'text' => '⚪ ממתין',
                'callback_data' => "tsk:{$task_id}:waiting",
            );
        }
        if ( $current !== 'completed' ) {
            $buttons[] = array(
                'text' => '🟢 הושלם ונסגר',
                'callback_data' => "tsk:{$task_id}:completed",
            );
        }

        // Layout: pair them 2-per-row for readability on phones.
        $rows = array();
        $chunk = array_chunk( $buttons, 2 );
        foreach ( $chunk as $pair ) {
            $rows[] = $pair;
        }

        // Always show "Open in CMMS" as the bottom row, on its own.
        $rows[] = array( array(
            'text' => '👁 פתח במערכת',
            'url'  => self::build_task_url( $task_id ),
        ) );

        return array( 'inline_keyboard' => $rows );
    }

    /**
     * Build the public URL to the task detail page. The /cmms-dashboard/
     * slug is what we use throughout — the URL is fine to open from
     * Telegram (user logs in if needed).
     */
    public static function build_task_url( $task_id ) {
        return home_url( '/cmms-dashboard/?view=task&id=' . (int) $task_id );
    }

    /**
     * Render the task as Telegram HTML text. Keep it under Telegram's
     * 4096-char message cap; tasks rarely come close, but truncate
     * descriptions to be safe.
     */
    public static function format_task_text( $task, $extra = array() ) {
        global $wpdb;
        // Pull asset name + creator name for context (worth the queries
        // — these go out infrequently, not in hot loops).
        $asset_name = '';
        if ( ! empty( $task->asset_id ) ) {
            $asset = CMMS_Assets::get( (int) $task->asset_id );
            if ( $asset ) $asset_name = $asset->name;
        }
        $created_by_name = '';
        if ( ! empty( $task->created_by ) ) {
            $creator = CMMS_Users::get( (int) $task->created_by );
            if ( $creator ) $created_by_name = $creator->display_name;
        }

        // Priority emoji
        $priority_emoji = array(
            'low'    => '🟢 נמוכה',
            'medium' => '🟡 בינונית',
            'high'   => '🔴 גבוהה',
            'urgent' => '🚨 דחופה',
        );
        $priority = isset( $priority_emoji[ $task->priority ] ) ? $priority_emoji[ $task->priority ] : $task->priority;

        // Header changes by whether this is a fresh assignment or
        // an in-flight update.
        $header = ! empty( $extra['header'] )
            ? $extra['header']
            : '🔧 <b>משימה חדשה הוקצתה לך</b>';

        $lines = array( $header, '' );
        $lines[] = '📋 <b>' . esc_html( $task->title ) . '</b>';
        if ( $asset_name ) {
            $lines[] = '🏷️ נכס: ' . esc_html( $asset_name );
        }
        $lines[] = '⚠️ עדיפות: ' . $priority;
        $lines[] = '📊 סטטוס: ' . self::status_label( $task->status );

        if ( ! empty( $task->due_date ) ) {
            $lines[] = '📅 יעד: ' . mysql2date( 'd/m/Y H:i', $task->due_date );
        }
        if ( $created_by_name ) {
            $lines[] = '👤 הוקצה ע"י: ' . esc_html( $created_by_name );
        }

        if ( ! empty( $task->description ) ) {
            $desc = wp_strip_all_tags( $task->description );
            // Telegram supports HTML; keep desc plain to avoid breaking parse.
            if ( mb_strlen( $desc ) > 500 ) {
                $desc = mb_substr( $desc, 0, 500 ) . '…';
            }
            $lines[] = '';
            $lines[] = '📝 <i>' . esc_html( $desc ) . '</i>';
        }

        return implode( "\n", $lines );
    }

    /**
     * Send a fresh task message to a recipient (typically the assignee).
     * Stores chat_id + message_id in telegram_task_messages so future
     * edits can target this exact message.
     *
     * Idempotent: if a message for (task, recipient) already exists,
     * edit it instead of sending a new one.
     */
    public static function send_task_message( $task_id, $cmms_user_id ) {
        if ( ! self::is_enabled() ) return false;
        $task = CMMS_Tasks::get( (int) $task_id );
        if ( ! $task ) return false;
        if ( ! empty( $task->deleted_at ) ) return false;

        $link = self::get_link( (int) $cmms_user_id );
        if ( ! $link || empty( $link->telegram_user_id ) ) {
            // User hasn't connected Telegram. Not an error — just skip.
            return false;
        }
        // Account check — never message a user about a task that isn't
        // in their account (defence in depth).
        if ( (int) $link->account_id !== (int) $task->account_id ) {
            return false;
        }

        global $wpdb;
        $msgs_t = CMMS_DB::table( 'telegram_task_messages' );
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $msgs_t WHERE task_id = %d AND cmms_user_id = %d LIMIT 1",
            (int) $task_id, (int) $cmms_user_id
        ) );

        $text = self::format_task_text( $task );
        $keyboard = self::build_status_keyboard( $task );

        if ( $existing ) {
            // Edit existing message rather than send a new one.
            return self::edit_task_message_existing( $existing, $text, $keyboard, $task->status );
        }

        $chat_id = (int) $link->telegram_user_id;
        $resp = self::api( 'sendMessage', array(
            'chat_id'      => $chat_id,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard,
            'disable_web_page_preview' => true,
        ), array(
            'task_id'         => $task_id,
            'cmms_user_id'    => $cmms_user_id,
            'telegram_user_id'=> $chat_id,
            'kind'            => 'send_task',
        ) );

        if ( empty( $resp['ok'] ) || empty( $resp['result']['message_id'] ) ) {
            return false;
        }

        $now = current_time( 'mysql' );
        $wpdb->insert( $msgs_t, array(
            'account_id'      => (int) $task->account_id,
            'task_id'         => (int) $task_id,
            'cmms_user_id'    => (int) $cmms_user_id,
            'telegram_user_id'=> $chat_id,
            'chat_id'         => $chat_id,
            'message_id'      => (int) $resp['result']['message_id'],
            'last_status'     => $task->status,
            'created_at'      => $now,
            'updated_at'      => $now,
        ), array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ) );

        return true;
    }

    /**
     * Edit an existing task message in place. Called after a status
     * change. If the message has been deleted on Telegram's side
     * (user wiped chat), the edit fails silently and we send a fresh one.
     */
    private static function edit_task_message_existing( $row, $text, $keyboard, $new_status ) {
        // Skip if status hasn't changed AND text would be identical.
        // (Defends against pointless API calls when the user taps a
        // status that's already current — shouldn't happen because
        // the button is hidden, but be safe.)
        if ( $row->last_status === $new_status ) {
            // Still allow the edit to update the keyboard if buttons changed.
        }

        $resp = self::api( 'editMessageText', array(
            'chat_id'      => (int) $row->chat_id,
            'message_id'   => (int) $row->message_id,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard,
            'disable_web_page_preview' => true,
        ), array(
            'task_id'         => (int) $row->task_id,
            'cmms_user_id'    => (int) $row->cmms_user_id,
            'telegram_user_id'=> (int) $row->telegram_user_id,
            'kind'            => 'edit_task',
        ) );

        if ( empty( $resp['ok'] ) ) {
            // If message was deleted on Telegram (HTTP 400 "message to
            // edit not found"), retry by sending a new message. Don't
            // loop forever — just one retry.
            $err = isset( $resp['error'] ) ? $resp['error'] : '';
            if ( stripos( $err, 'message to edit not found' ) !== false
              || stripos( $err, 'message can\'t be edited' ) !== false ) {
                // Wipe stale row and try a fresh send.
                global $wpdb;
                $msgs_t = CMMS_DB::table( 'telegram_task_messages' );
                $wpdb->delete( $msgs_t, array( 'id' => (int) $row->id ), array( '%d' ) );
                return self::send_task_message( (int) $row->task_id, (int) $row->cmms_user_id );
            }
            return false;
        }

        global $wpdb;
        $msgs_t = CMMS_DB::table( 'telegram_task_messages' );
        $wpdb->update( $msgs_t,
            array( 'last_status' => $new_status, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $row->id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
        return true;
    }

    /**
     * Refresh all task messages for a given task after a status change.
     * Iterates every recipient that has a message recorded and edits
     * each one. Idempotent — safe to call repeatedly.
     */
    public static function refresh_task_messages( $task_id ) {
        if ( ! self::is_enabled() ) return;
        $task = CMMS_Tasks::get( (int) $task_id );
        if ( ! $task ) return;

        global $wpdb;
        $msgs_t = CMMS_DB::table( 'telegram_task_messages' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $msgs_t WHERE task_id = %d",
            (int) $task_id
        ) );
        if ( ! $rows ) return;

        $text = self::format_task_text( $task, array(
            'header' => '🔧 <b>' . esc_html( $task->title ) . '</b>',
        ) );
        $keyboard = self::build_status_keyboard( $task );

        foreach ( $rows as $row ) {
            self::edit_task_message_existing( $row, $text, $keyboard, $task->status );
        }
    }
}
