<?php
/**
 * 1.14.84 — Telegram webhook endpoint.
 *
 * Registers a REST route at /wp-json/cmms/v1/telegram/webhook that
 * Telegram POSTs to on every inbound message and callback query.
 *
 * Security:
 *   1. Telegram sends X-Telegram-Bot-Api-Secret-Token header with
 *      every request. We verify it matches our stored secret BEFORE
 *      doing anything else. If it doesn't match, return 401 silently.
 *      This protects against random POSTs to our public URL.
 *   2. Even with a valid secret, we never trust the data blindly:
 *      every action re-verifies the CMMS user mapping and account
 *      isolation.
 *
 * Handling:
 *   For Phase 1 (this version) we only handle:
 *     - /start <token>  → link the user
 *     - /help           → show a help message
 *     - any other text  → "not linked" prompt or generic ack
 *   Phase 2 will add callback_query handlers (status buttons) and
 *   Phase 3 will add photo/reply handlers.
 *
 * Always return 200 OK to Telegram. If we return non-200, Telegram
 * will retry indefinitely. Even error cases log and return 200.
 */
defined( 'ABSPATH' ) || exit;

class CMMS_Telegram_Webhook {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );

        // 1.14.85: Phase 2 — react to task events by sending or
        // editing Telegram messages for assigned users.
        //
        //   cmms_task_created       → if the new task already has an
        //                             assignee, send them the message.
        //   cmms_task_assigned      → assignee changed; send to the new
        //                             one. (The old recipient's existing
        //                             message will be left as-is — it
        //                             remains accurate for what they
        //                             were told at the time.)
        //   cmms_task_status_changed→ refresh all existing recipients'
        //                             messages in place.
        add_action( 'cmms_task_created',         array( __CLASS__, 'on_task_created' ),         10, 2 );
        add_action( 'cmms_task_assigned',        array( __CLASS__, 'on_task_assigned' ),        10, 3 );
        add_action( 'cmms_task_status_changed',  array( __CLASS__, 'on_task_status_changed' ), 10, 4 );
    }

    /**
     * Listener: task just created. If it already has an assigned_to,
     * send the message right away. (Most tasks are created via the
     * UI with an assignee already picked.)
     */
    public static function on_task_created( $task_id, $context ) {
        if ( ! CMMS_Telegram::is_enabled() ) return;
        $assigned_to = isset( $context['assigned_to'] ) ? (int) $context['assigned_to'] : 0;
        if ( $assigned_to > 0 ) {
            CMMS_Telegram::send_task_message( (int) $task_id, $assigned_to );
        }
    }

    /**
     * Listener: assignee changed. Send to the NEW assignee. We don't
     * touch the previous assignee's message — they were notified of
     * a task that was theirs at that time; leaving the message helps
     * them remember context.
     */
    public static function on_task_assigned( $task_id, $old_user_id, $new_user_id ) {
        if ( ! CMMS_Telegram::is_enabled() ) return;
        $new_user_id = (int) $new_user_id;
        if ( $new_user_id > 0 ) {
            CMMS_Telegram::send_task_message( (int) $task_id, $new_user_id );
        }
    }

    /**
     * Listener: status changed. Refresh all in-flight messages for
     * this task so the buttons + status line stay accurate.
     */
    public static function on_task_status_changed( $task_id, $old_status, $new_status, $actor_user_id ) {
        if ( ! CMMS_Telegram::is_enabled() ) return;
        CMMS_Telegram::refresh_task_messages( (int) $task_id );
    }

    public static function register_route() {
        register_rest_route( 'cmms/v1', '/telegram/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle' ),
            // permission_callback returns true; we do our own auth via
            // the X-Telegram-Bot-Api-Secret-Token header inside handle().
            'permission_callback' => '__return_true',
        ) );
    }

    public static function handle( WP_REST_Request $request ) {
        // Verify the secret token from the header. Telegram includes
        // X-Telegram-Bot-Api-Secret-Token in every webhook request
        // that was registered with secret_token. If missing or wrong,
        // someone is poking at our endpoint — log and reject.
        $expected = CMMS_Telegram::webhook_secret();
        $received = $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );
        if ( ! $expected || ! $received || ! hash_equals( $expected, $received ) ) {
            CMMS_Telegram::log( array(
                'direction' => 'in',
                'kind'      => 'rejected_invalid_secret',
                'payload'   => wp_json_encode( $request->get_params() ),
                'error_text'=> 'Invalid or missing X-Telegram-Bot-Api-Secret-Token header',
            ) );
            // Return 401 — only the legitimate Telegram bot has the
            // header, so this is the right signal.
            return new WP_REST_Response( array( 'ok' => false ), 401 );
        }

        $update = $request->get_json_params();
        if ( ! is_array( $update ) ) {
            // Malformed JSON — log and ack so Telegram doesn't retry.
            CMMS_Telegram::log( array(
                'direction' => 'in',
                'kind'      => 'malformed',
                'payload'   => $request->get_body(),
            ) );
            return new WP_REST_Response( array( 'ok' => true ), 200 );
        }

        // Log every inbound update for forensics.
        CMMS_Telegram::log( array(
            'direction' => 'in',
            'kind'      => isset( $update['callback_query'] ) ? 'callback' : 'message',
            'payload'   => wp_json_encode( $update ),
        ) );

        try {
            // Message updates (most common in Phase 1).
            if ( isset( $update['message'] ) ) {
                self::handle_message( $update['message'] );
            }
            // Callback queries (button presses) — wired up but
            // routes are added in Phase 2.
            elseif ( isset( $update['callback_query'] ) ) {
                self::handle_callback( $update['callback_query'] );
            }
        } catch ( Exception $e ) {
            CMMS_Telegram::log( array(
                'direction' => 'in',
                'kind'      => 'exception',
                'error_text'=> $e->getMessage(),
            ) );
            // Always return OK so Telegram doesn't retry into an
            // infinite loop. The exception is logged above for us
            // to review.
        }

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    /**
     * Handle a message update. Phase 1 dispatch:
     *   /start <token>  → link the user
     *   /start          → help message
     *   /help           → help message
     *   /mytasks        → list open tasks (Phase 2)
     *   /connect        → "use the website to connect"
     * Phase 3 (1.14.86):
     *   Reply to a task message + photo → attach to task
     *   Reply to a task message + text  → add as comment
     *   anything else   → "not linked" prompt or generic ack
     */
    private static function handle_message( $message ) {
        if ( ! isset( $message['from'] ) || ! isset( $message['chat'] ) ) return;
        $from    = $message['from'];
        $chat_id = (int) $message['chat']['id'];
        $text    = isset( $message['text'] ) ? trim( $message['text'] ) : '';

        // 1.14.87.3: Location share check FIRST (before reply check).
        // When a user taps "Share Location" via the request_location
        // keyboard button, Telegram sometimes includes a reply_to_message
        // referring to our prompt. We must not route that to handle_reply
        // — it's a location response, not a task reply.
        if ( isset( $message['location'] ) && is_array( $message['location'] ) ) {
            self::handle_location( $message, $from, $chat_id );
            return;
        }

        // 1.14.87: User canceled the location request via the
        // "❌ ביטול" reply button. Same priority as location — must
        // win over the generic reply handler.
        if ( $text === '❌ ביטול' ) {
            $cmms_user = CMMS_Telegram::get_cmms_user_by_telegram_id( (int) $from['id'] );
            if ( $cmms_user ) {
                delete_transient( 'cmms_tg_pending_complete_' . (int) $cmms_user->id );
            }
            CMMS_Telegram::api( 'sendMessage', array(
                'chat_id' => $chat_id,
                'text'    => '❌ הסגירה בוטלה. המשימה נשארה במצב הנוכחי שלה.',
                'reply_markup' => array( 'remove_keyboard' => true ),
            ), array( 'kind' => 'location_canceled' ) );
            return;
        }

        // 1.14.86: Reply to a task message (photo / comment).
        if ( isset( $message['reply_to_message'] ) ) {
            self::handle_reply( $message, $from, $chat_id );
            return;
        }

        // Photo without Reply — guide the user.
        if ( isset( $message['photo'] ) && empty( $text ) ) {
            $cmms_user = CMMS_Telegram::get_cmms_user_by_telegram_id( (int) $from['id'] );
            if ( ! $cmms_user ) {
                CMMS_Telegram::send_message( $chat_id,
                    "👋 שלום!\n\n" .
                    "כדי להשתמש בבוט, ראשית עליך לחבר את חשבונך במערכת CMMS Light.\n\n/connect" );
                return;
            }
            CMMS_Telegram::send_message( $chat_id,
                "📸 כדי לצרף תמונה למשימה, ענה (Reply) על הודעת המשימה ושלח את התמונה.\n\n" .
                "💡 לחץ ארוך על הודעת המשימה → בחר \"Reply\" → שלח את התמונה." );
            return;
        }

        // Phase 1 only handles slash-commands and plain text. Photos
        // and replies will be routed in Phase 3.
        if ( $text === '' ) {
            return;
        }

        // /start <optional token>
        if ( preg_match( '#^/start(?:\s+(\S+))?#', $text, $m ) ) {
            $token = isset( $m[1] ) ? $m[1] : '';
            if ( $token ) {
                self::handle_start_with_token( $chat_id, $from, $token );
            } else {
                self::send_welcome( $chat_id );
            }
            return;
        }

        // /help
        if ( strpos( $text, '/help' ) === 0 ) {
            self::send_help( $chat_id );
            return;
        }

        // /connect — explain that connection starts on the website
        if ( strpos( $text, '/connect' ) === 0 ) {
            $home = home_url( '/cmms-dashboard/?view=settings&tab=telegram' );
            CMMS_Telegram::send_message( $chat_id,
                "כדי לחבר את חשבונך, הכנס לפרופיל שלך במערכת ולחץ על \"חבר טלגרם\":\n\n" . $home );
            return;
        }

        // /mytasks — show the user's open tasks
        if ( strpos( $text, '/mytasks' ) === 0 ) {
            self::handle_mytasks( $chat_id, $from );
            return;
        }

        // Anything else — check if user is linked and respond accordingly.
        $cmms_user = CMMS_Telegram::get_cmms_user_by_telegram_id( $from['id'] );
        if ( ! $cmms_user ) {
            CMMS_Telegram::send_message( $chat_id,
                "👋 שלום!\n\n" .
                "כדי להשתמש בבוט, ראשית עליך לחבר את חשבונך במערכת CMMS Light.\n\n" .
                "1. הכנס למערכת ב-cmms.co.il\n" .
                "2. לחץ על הפרופיל שלך\n" .
                "3. לחץ על \"חבר טלגרם\"" );
            return;
        }

        // Linked user sent free text (without Reply). Guide them to use
        // Reply for attaching comments to a specific task.
        CMMS_Telegram::send_message( $chat_id,
            "💡 כדי להוסיף הערה או תמונה למשימה, ענה (Reply) על הודעת המשימה.\n\n" .
            "לרשימת המשימות הפתוחות שלך: /mytasks" );
    }

    /**
     * 1.14.87: Handle a Location message. Only meaningful if the user
     * has a "pending completion" — i.e., they tapped "Completed" on
     * a task, the account requires GPS, and we asked them to share
     * their location.
     */
    private static function handle_location( $message, $from, $chat_id ) {
        $cmms_user = CMMS_Telegram::get_cmms_user_by_telegram_id( (int) $from['id'] );
        if ( ! $cmms_user ) {
            CMMS_Telegram::send_message( $chat_id, "👋 כדי להשתמש בבוט, חבר את חשבונך תחילה.\n\n/connect" );
            return;
        }

        $transient_key = 'cmms_tg_pending_complete_' . (int) $cmms_user->id;
        $pending_task_id = get_transient( $transient_key );
        if ( ! $pending_task_id ) {
            // No pending completion — just acknowledge.
            CMMS_Telegram::api( 'sendMessage', array(
                'chat_id' => $chat_id,
                'text'    => '📍 קיבלתי את המיקום שלך, אבל אין משימה פתוחה שמחכה לסגירה. אם רצית לסגור משימה, לחץ על "🟢 הושלם ונסגר" בהודעת המשימה.',
                'reply_markup' => array( 'remove_keyboard' => true ),
            ), array( 'kind' => 'location_no_pending' ) );
            return;
        }

        $task = CMMS_Tasks::get( (int) $pending_task_id );
        if ( ! $task || (int) $task->account_id !== (int) $cmms_user->account_id ) {
            delete_transient( $transient_key );
            CMMS_Telegram::api( 'sendMessage', array(
                'chat_id' => $chat_id,
                'text'    => '⚠️ המשימה לא נמצאה.',
                'reply_markup' => array( 'remove_keyboard' => true ),
            ), array( 'kind' => 'location_task_missing' ) );
            return;
        }

        // Extract coords from the Telegram location object.
        $lat = isset( $message['location']['latitude'] )  ? (float) $message['location']['latitude']  : null;
        $lng = isset( $message['location']['longitude'] ) ? (float) $message['location']['longitude'] : null;
        if ( $lat === null || $lng === null ) {
            CMMS_Telegram::api( 'sendMessage', array(
                'chat_id' => $chat_id,
                'text'    => '⚠️ שגיאה בקבלת המיקום. נסה שוב.',
                'reply_markup' => array( 'remove_keyboard' => true ),
            ), array( 'kind' => 'location_invalid' ) );
            return;
        }

        // Apply the completion with location.
        $ok = CMMS_Tasks::update( (int) $task->id, array(
            'status'                     => 'completed',
            'completion_lat'             => $lat,
            'completion_lng'             => $lng,
            'completion_address'         => '', // Telegram doesn't give us an address; we leave it for the UI to show coords
            'completion_location_source' => 'telegram',
        ), (int) $cmms_user->id );

        delete_transient( $transient_key );

        if ( ! $ok ) {
            CMMS_Telegram::api( 'sendMessage', array(
                'chat_id' => $chat_id,
                'text'    => '⚠️ שגיאה בסגירת המשימה. נסה שוב.',
                'reply_markup' => array( 'remove_keyboard' => true ),
            ), array( 'kind' => 'location_update_failed' ) );
            return;
        }

        CMMS_Telegram::api( 'sendMessage', array(
            'chat_id'      => $chat_id,
            'text'         => "✅ <b>המשימה נסגרה</b>\n\n" .
                              "📋 " . esc_html( $task->title ) . "\n" .
                              "📍 המיקום נשמר",
            'parse_mode'   => 'HTML',
            'reply_markup' => array( 'remove_keyboard' => true ),
        ), array( 'kind' => 'location_completed' ) );
    }

    /**
     * Phase 3 (1.14.86): Handle a Reply to a task message.
     *
     * The reply could be a photo (→ attach to task) or text (→ comment
     * on task). We:
     *   1. Identify the user
     *   2. Find which task the reply is targeting (lookup by message_id)
     *   3. Verify permission
     *   4. Save the attachment or comment
     *   5. Send confirmation
     */
    private static function handle_reply( $message, $from, $chat_id ) {
        $reply_to = $message['reply_to_message'];

        // Identify the user.
        $cmms_user = CMMS_Telegram::get_cmms_user_by_telegram_id( (int) $from['id'] );
        if ( ! $cmms_user ) {
            CMMS_Telegram::send_message( $chat_id,
                "👋 כדי להשתמש בבוט, חבר את חשבונך תחילה.\n\n/connect" );
            return;
        }

        // Look up which task this Reply belongs to. We match by
        // (chat_id + message_id) against telegram_task_messages.
        global $wpdb;
        $msgs_t = CMMS_DB::table( 'telegram_task_messages' );
        $msg_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $msgs_t WHERE chat_id = %d AND message_id = %d LIMIT 1",
            $chat_id, (int) $reply_to['message_id']
        ) );

        if ( ! $msg_row ) {
            CMMS_Telegram::send_message( $chat_id,
                "⚠️ לא הצלחתי לזהות לאיזו משימה הכוונה.\n\n" .
                "ענה (Reply) ישירות על הודעת המשימה - לא על הודעות אחרות." );
            return;
        }

        $task_id = (int) $msg_row->task_id;
        $task = CMMS_Tasks::get( $task_id );

        // Defensive checks.
        if ( ! $task || (int) $task->account_id !== (int) $cmms_user->account_id ) {
            CMMS_Telegram::send_message( $chat_id, "⚠️ המשימה לא נמצאה." );
            return;
        }
        if ( ! empty( $task->deleted_at ) ) {
            CMMS_Telegram::send_message( $chat_id, "⚠️ המשימה נמחקה." );
            return;
        }

        // Permission check — same model as status change.
        if ( ! self::can_user_change_task_status( $cmms_user, $task ) ) {
            CMMS_Telegram::send_message( $chat_id, "⚠️ אין לך הרשאה לעדכן את המשימה הזו." );
            return;
        }

        // ─── PHOTO REPLY ───────────────────────────────────────────
        if ( isset( $message['photo'] ) && is_array( $message['photo'] ) && ! empty( $message['photo'] ) ) {
            self::handle_reply_photo( $message, $cmms_user, $task, $chat_id );
            return;
        }

        // ─── DOCUMENT REPLY (image as file) ────────────────────────
        if ( isset( $message['document'] ) ) {
            self::handle_reply_document( $message, $cmms_user, $task, $chat_id );
            return;
        }

        // ─── TEXT REPLY (comment) ──────────────────────────────────
        $text = isset( $message['text'] ) ? trim( $message['text'] ) : '';
        if ( $text !== '' ) {
            self::handle_reply_text( $text, $cmms_user, $task, $chat_id );
            return;
        }

        // Reply with no content we can handle (voice note, sticker, etc).
        CMMS_Telegram::send_message( $chat_id,
            "⚠️ סוג ההודעה לא נתמך. שלח תמונה או טקסט (כ-Reply להודעת המשימה)." );
    }

    /**
     * Handle photo Reply: download from Telegram, save as attachment.
     */
    private static function handle_reply_photo( $message, $cmms_user, $task, $chat_id ) {
        // Telegram sends multiple sizes; pick the LARGEST (last in array).
        $photos = $message['photo'];
        $largest = end( $photos );
        if ( empty( $largest['file_id'] ) ) {
            CMMS_Telegram::send_message( $chat_id, "⚠️ שגיאה בקבלת התמונה." );
            return;
        }

        $file = CMMS_Telegram::download_file( $largest['file_id'] );
        if ( ! $file ) {
            CMMS_Telegram::send_message( $chat_id, "⚠️ שגיאה בהורדת התמונה. נסה שוב." );
            return;
        }

        // Build a friendly filename including task title.
        $task_title_slug = sanitize_title( substr( $task->title, 0, 30 ) );
        $original_name = sprintf( 'telegram-task-%d-%s.%s', (int) $task->id, gmdate( 'Ymd-His' ), $file['extension'] );

        $attachment_id = CMMS_Attachments::handle_remote_file(
            (int) $cmms_user->account_id,
            'task',
            (int) $task->id,
            $file['bytes'],
            $file['extension'],
            $original_name,
            $file['mime_type'],
            (int) $cmms_user->id
        );

        if ( is_wp_error( $attachment_id ) ) {
            CMMS_Telegram::send_message( $chat_id,
                "⚠️ שגיאה בשמירת התמונה: " . $attachment_id->get_error_message() );
            return;
        }

        // Log the action to task_logs (audit trail).
        CMMS_Tasks::log(
            (int) $task->id,
            (int) $task->account_id,
            (int) $cmms_user->id,
            'attachment_added',
            'תמונה הועלתה דרך טלגרם'
        );

        CMMS_Telegram::send_message( $chat_id,
            "✅ <b>התמונה צורפה למשימה</b>\n\n" .
            "📋 " . esc_html( $task->title ) );
    }

    /**
     * Handle document Reply: download from Telegram, save as attachment.
     * Some Telegram clients send images as documents when "compress"
     * is disabled. We handle them like photos.
     */
    private static function handle_reply_document( $message, $cmms_user, $task, $chat_id ) {
        $doc = $message['document'];
        if ( empty( $doc['file_id'] ) ) {
            CMMS_Telegram::send_message( $chat_id, "⚠️ שגיאה בקבלת הקובץ." );
            return;
        }

        $file = CMMS_Telegram::download_file( $doc['file_id'] );
        if ( ! $file ) {
            CMMS_Telegram::send_message( $chat_id, "⚠️ שגיאה בהורדת הקובץ. נסה שוב." );
            return;
        }

        // Use the original filename if Telegram gave us one, else build one.
        $original_name = ! empty( $doc['file_name'] )
            ? sanitize_file_name( $doc['file_name'] )
            : sprintf( 'telegram-task-%d-%s.%s', (int) $task->id, gmdate( 'Ymd-His' ), $file['extension'] );

        // If Telegram told us the mime type explicitly, prefer it.
        $mime = ! empty( $doc['mime_type'] ) ? $doc['mime_type'] : $file['mime_type'];

        $attachment_id = CMMS_Attachments::handle_remote_file(
            (int) $cmms_user->account_id,
            'task',
            (int) $task->id,
            $file['bytes'],
            $file['extension'],
            $original_name,
            $mime,
            (int) $cmms_user->id
        );

        if ( is_wp_error( $attachment_id ) ) {
            CMMS_Telegram::send_message( $chat_id,
                "⚠️ שגיאה בשמירת הקובץ: " . $attachment_id->get_error_message() );
            return;
        }

        CMMS_Tasks::log(
            (int) $task->id,
            (int) $task->account_id,
            (int) $cmms_user->id,
            'attachment_added',
            'קובץ הועלה דרך טלגרם'
        );

        CMMS_Telegram::send_message( $chat_id,
            "✅ <b>הקובץ צורף למשימה</b>\n\n" .
            "📋 " . esc_html( $task->title ) );
    }

    /**
     * Handle text Reply: save as a comment in task_logs.
     */
    private static function handle_reply_text( $text, $cmms_user, $task, $chat_id ) {
        // Cap at 2000 chars for sanity. Anything longer and the user
        // should be using the web UI.
        if ( mb_strlen( $text ) > 2000 ) {
            $text = mb_substr( $text, 0, 2000 ) . '…';
        }

        // Log as a 'comment' action. task_logs.note holds the text.
        CMMS_Tasks::log(
            (int) $task->id,
            (int) $task->account_id,
            (int) $cmms_user->id,
            'comment',
            $text
        );

        CMMS_Telegram::send_message( $chat_id,
            "✅ <b>ההערה נוספה למשימה</b>\n\n" .
            "📋 " . esc_html( $task->title ) );
    }

    /**
     * /start <token> handler. Verifies the token, links the user.
     */
    private static function handle_start_with_token( $chat_id, $from, $token ) {
        $cmms_user = CMMS_Telegram::consume_link_token( $token, $from );
        if ( ! $cmms_user ) {
            CMMS_Telegram::send_message( $chat_id,
                "❌ הקישור לא תקף או שפג תוקפו.\n\n" .
                "בקש קישור חדש מהפרופיל שלך במערכת." );
            return;
        }
        CMMS_Telegram::send_message( $chat_id,
            "✅ <b>החשבון חובר בהצלחה!</b>\n\n" .
            "שלום " . esc_html( $cmms_user->display_name ) . "!\n\n" .
            "מעתה תקבל התראות על משימות שמוקצות לך, ותוכל לעדכן את הסטטוס שלהן ישירות מכאן.\n\n" .
            "💡 שלח /mytasks כדי לראות את המשימות הפתוחות שלך." );
    }

    /**
     * /mytasks command. Send the user a fresh task message for
     * each of their open tasks (or edit if already sent). This is
     * useful as a "refresh" or after first connecting.
     *
     * Capped at 10 tasks to avoid chat spam. If they have more,
     * they should use the web app.
     */
    private static function handle_mytasks( $chat_id, $from ) {
        $cmms_user = CMMS_Telegram::get_cmms_user_by_telegram_id( (int) $from['id'] );
        if ( ! $cmms_user ) {
            CMMS_Telegram::send_message( $chat_id,
                "👋 שלום!\n\n" .
                "כדי להשתמש בבוט, ראשית עליך לחבר את חשבונך במערכת CMMS Light.\n\n" .
                "/connect" );
            return;
        }

        // Fetch open tasks assigned to this user.
        global $wpdb;
        $tasks_t = CMMS_DB::table( 'tasks' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $tasks_t
              WHERE account_id = %d
                AND assigned_to = %d
                AND status IN ('open','in_progress','waiting')
                AND deleted_at IS NULL
              ORDER BY
                CASE priority
                  WHEN 'urgent' THEN 0
                  WHEN 'high'   THEN 1
                  WHEN 'medium' THEN 2
                  WHEN 'normal' THEN 3
                  ELSE 4
                END,
                due_date IS NULL,
                due_date ASC,
                created_at DESC
              LIMIT 10",
            (int) $cmms_user->account_id, (int) $cmms_user->id
        ) );

        if ( empty( $rows ) ) {
            CMMS_Telegram::send_message( $chat_id,
                "🎉 <b>אין לך משימות פתוחות!</b>\n\n" .
                "כל המשימות שלך הושלמו. מעולה!" );
            return;
        }

        // Send a summary header.
        CMMS_Telegram::send_message( $chat_id, sprintf(
            "📋 <b>המשימות הפתוחות שלך</b> (%d)",
            count( $rows )
        ) );

        // For each task, send (or refresh) its message.
        foreach ( $rows as $task ) {
            CMMS_Telegram::send_task_message( (int) $task->id, (int) $cmms_user->id );
        }
    }

    private static function send_welcome( $chat_id ) {
        CMMS_Telegram::send_message( $chat_id,
            "👋 ברוך הבא לבוט של CMMS Light!\n\n" .
            "כדי להתחיל, חבר את חשבונך:\n" .
            "1. הכנס למערכת ב-cmms.co.il\n" .
            "2. לחץ על הפרופיל שלך\n" .
            "3. לחץ על \"חבר טלגרם\"\n\n" .
            "צריך עזרה? /help" );
    }

    private static function send_help( $chat_id ) {
        CMMS_Telegram::send_message( $chat_id,
            "📖 <b>עזרה</b>\n\n" .
            "הבוט מאפשר לך לקבל ולנהל משימות תחזוקה ישירות מטלגרם.\n\n" .
            "<b>פקודות:</b>\n" .
            "/start - התחל את הבוט\n" .
            "/mytasks - המשימות שלי\n" .
            "/connect - חבר את החשבון\n" .
            "/help - מסך זה\n\n" .
            "למידע נוסף: cmms.co.il" );
    }

    /**
     * Callback query handler — buttons in the inline keyboard.
     * Phase 2 wires up the status-change buttons. The callback_data
     * format is "tsk:{task_id}:{new_status}", parsed below.
     *
     * Security flow on every callback:
     *   1. Resolve Telegram user → CMMS user (via telegram_users)
     *   2. Verify the task belongs to the user's account
     *   3. Verify the user has permission to update that task
     *   4. Apply update via CMMS_Tasks::update — same code path the
     *      web UI uses, so audit log, lifecycle timestamps, emails
     *      all fire automatically
     *   5. The hook 'cmms_task_status_changed' fires and our listener
     *      refreshes the Telegram message
     *   6. Send an ephemeral toast confirmation
     */
    private static function handle_callback( $callback ) {
        if ( ! isset( $callback['id'] ) ) return;
        $callback_id = $callback['id'];
        $from        = isset( $callback['from'] ) ? $callback['from'] : array();
        $data        = isset( $callback['data'] ) ? $callback['data'] : '';

        // Quick ack — clears the "loading" spinner. We'll send a
        // proper toast at the end with the result.
        $ack = function( $text, $show_alert = false ) use ( $callback_id ) {
            CMMS_Telegram::api( 'answerCallbackQuery', array(
                'callback_query_id' => $callback_id,
                'text'              => $text,
                'show_alert'        => $show_alert,
            ), array( 'kind' => 'callback_answer' ) );
        };

        // Parse callback data. Format: "tsk:{task_id}:{new_status}".
        if ( ! preg_match( '#^tsk:(\d+):([a-z_]+)$#', $data, $m ) ) {
            $ack( 'פעולה לא מוכרת' );
            return;
        }
        $task_id    = (int) $m[1];
        $new_status = $m[2];

        // Whitelist: only the system's statuses are allowed.
        $allowed = array_keys( CMMS_Tasks::statuses() );
        if ( ! in_array( $new_status, $allowed, true ) ) {
            $ack( 'סטטוס לא תקין' );
            return;
        }

        // Look up the CMMS user from the Telegram user_id.
        $cmms_user = CMMS_Telegram::get_cmms_user_by_telegram_id( (int) $from['id'] );
        if ( ! $cmms_user ) {
            $ack( 'החשבון שלך לא מקושר', true );
            return;
        }

        // Look up the task — and verify it's in the user's account.
        $task = CMMS_Tasks::get( $task_id );
        if ( ! $task || (int) $task->account_id !== (int) $cmms_user->account_id ) {
            $ack( 'המשימה לא נמצאה', true );
            return;
        }
        if ( ! empty( $task->deleted_at ) ) {
            $ack( 'המשימה נמחקה', true );
            return;
        }

        // No-op check — already in the requested status.
        if ( $task->status === $new_status ) {
            $ack( 'הסטטוס כבר ' . CMMS_Telegram::status_label( $new_status ) );
            return;
        }

        // Permission check. Mirrors the web UI: a user can change
        // status if they're the assignee, manager, creator, or
        // owner/manager role on the account.
        $can_update = self::can_user_change_task_status( $cmms_user, $task );
        if ( ! $can_update ) {
            $ack( 'אין לך הרשאה לעדכן את המשימה הזו', true );
            return;
        }

        // 1.14.87: If the account requires GPS-on-complete AND the
        // user is moving to 'completed', send a location-request
        // BEFORE applying the change. We stash the pending status in
        // a transient so the next location message can complete it.
        if ( $new_status === 'completed'
          && CMMS_Task_Settings::require_location_on_complete( (int) $cmms_user->account_id ) ) {
            self::request_location_for_completion( $cmms_user, $task, $callback['message'] ?? array() );
            $ack( 'נדרש לשלוח את המיקום שלך כדי לסגור את המשימה' );
            return;
        }

        // Apply via the standard update method. This fires the
        // cmms_task_status_changed hook → our listener refreshes
        // the Telegram message → buttons are updated in place.
        $ok = CMMS_Tasks::update( $task_id, array( 'status' => $new_status ), (int) $cmms_user->id );
        if ( ! $ok ) {
            $ack( 'שגיאה בעדכון', true );
            return;
        }

        $ack( '✅ הסטטוס עודכן: ' . CMMS_Telegram::status_label( $new_status ) );
    }

    /**
     * 1.14.87: When the account requires GPS on completion, the
     * "Completed" button doesn't immediately close the task. Instead
     * we ask the user to share their Telegram location. We stash a
     * "pending completion" record (transient) tied to the user, and
     * the next location message they send will complete the task.
     */
    private static function request_location_for_completion( $cmms_user, $task, $message ) {
        // Stash the pending completion for 5 minutes. Keyed by the
        // CMMS user — if they have multiple in flight, the newest wins.
        $transient_key = 'cmms_tg_pending_complete_' . (int) $cmms_user->id;
        set_transient( $transient_key, (int) $task->id, 5 * MINUTE_IN_SECONDS );

        // Send a message with a "share location" keyboard button.
        // Telegram's KeyboardButton with request_location=true asks
        // the user once for their current GPS coords.
        $chat_id = isset( $message['chat']['id'] ) ? (int) $message['chat']['id'] : 0;
        if ( ! $chat_id ) {
            // Fallback — look up from telegram_users
            $link = CMMS_Telegram::get_link( (int) $cmms_user->id );
            if ( ! $link ) return;
            $chat_id = (int) $link->telegram_user_id;
        }

        CMMS_Telegram::api( 'sendMessage', array(
            'chat_id'    => $chat_id,
            'text'       => "📍 <b>נדרש מיקום לסגירת המשימה</b>\n\n" .
                            "כדי לסגור את המשימה \"" . esc_html( $task->title ) . "\", שלח לי את המיקום הנוכחי שלך.\n\n" .
                            "💡 לחץ על אייקון המצורף (📎) → בחר \"מיקום\" → \"שלח את המיקום הנוכחי שלי\".",
            'parse_mode' => 'HTML',
            'reply_markup' => array(
                'keyboard' => array(
                    array(
                        array(
                            'text' => '📍 שתף את המיקום שלי',
                            'request_location' => true,
                        ),
                    ),
                    array(
                        array( 'text' => '❌ ביטול' ),
                    ),
                ),
                'resize_keyboard'   => true,
                'one_time_keyboard' => true,
            ),
        ), array(
            'task_id'         => (int) $task->id,
            'cmms_user_id'    => (int) $cmms_user->id,
            'kind'            => 'location_request',
        ) );
    }

    /**
     * Permission check for status changes via Telegram. Mirrors the
     * permission model used by the web UI: privileged roles can change
     * any task in their account; non-privileged users can change tasks
     * they're directly involved with (assignee/manager/creator).
     */
    private static function can_user_change_task_status( $cmms_user, $task ) {
        // Privileged roles (owner / manager) — anything in their account.
        $privileged = array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER );
        if ( in_array( $cmms_user->role, $privileged, true ) ) {
            return true;
        }
        // Direct involvement.
        $uid = (int) $cmms_user->id;
        if ( (int) $task->assigned_to === $uid ) return true;
        if ( (int) $task->manager_id  === $uid ) return true;
        if ( (int) $task->created_by  === $uid ) return true;
        return false;
    }
}
