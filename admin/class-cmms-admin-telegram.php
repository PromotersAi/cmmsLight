<?php
/**
 * 1.14.84 — Telegram Admin Settings page.
 *
 * Single screen for managing the Telegram bot integration:
 *   - Enable/disable
 *   - Bot token (BotFather)
 *   - Bot username
 *   - Webhook status (live check via getWebhookInfo)
 *   - Test connection (calls getMe)
 *   - Connect/Disconnect (calls setWebhook / deleteWebhook)
 *
 * The actual user linking (CMMS user ↔ Telegram account) happens
 * from the customer's profile page in the dashboard, NOT here.
 * This screen is for the platform admin only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Admin_Telegram {

    const CAP  = 'manage_options';
    const SLUG = 'cmms-light-telegram';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_handle_save' ) );
    }

    public static function render() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'cmms-light' ) );
        }

        $enabled       = (bool) get_option( 'cmms_telegram_enabled', false );
        $token         = (string) get_option( 'cmms_telegram_token', '' );
        $bot_username  = (string) get_option( 'cmms_telegram_bot_username', '' );
        $saved         = ! empty( $_GET['saved'] );
        $action_done   = isset( $_GET['action_done'] ) ? sanitize_text_field( wp_unslash( $_GET['action_done'] ) ) : '';
        $action_error  = isset( $_GET['action_error'] ) ? sanitize_text_field( wp_unslash( $_GET['action_error'] ) ) : '';

        // Check webhook status if enabled
        $webhook_info = null;
        $bot_info = null;
        if ( $enabled && $token ) {
            $info_resp = CMMS_Telegram::get_webhook_info();
            if ( ! empty( $info_resp['ok'] ) ) {
                $webhook_info = $info_resp['result'];
            }
            $me_resp = CMMS_Telegram::get_me();
            if ( ! empty( $me_resp['ok'] ) ) {
                $bot_info = $me_resp['result'];
            }
        }

        $webhook_url = CMMS_Telegram::webhook_url();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Telegram Bot Integration', 'cmms-light' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'cmms-light' ); ?></p>
                </div>
            <?php endif; ?>
            <?php if ( $action_done ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $action_done ); ?></p>
                </div>
            <?php endif; ?>
            <?php if ( $action_error ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html( $action_error ); ?></p>
                </div>
            <?php endif; ?>

            <p style="max-width:720px;color:#50575e;">
                <?php esc_html_e( 'Connect a Telegram bot to send task notifications to technicians and let them update task status from Telegram. Create the bot with @BotFather first, then paste the token below.', 'cmms-light' ); ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field( 'cmms_telegram_save', 'cmms_telegram_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="cmms_telegram_enabled"><?php esc_html_e( 'Enabled', 'cmms-light' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="cmms_telegram_enabled" name="cmms_telegram_enabled" value="1" <?php checked( $enabled ); ?>>
                                <?php esc_html_e( 'Enable Telegram integration', 'cmms-light' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'When disabled, no Telegram messages are sent and the webhook rejects all incoming requests.', 'cmms-light' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="cmms_telegram_token"><?php esc_html_e( 'Bot Token', 'cmms-light' ); ?></label></th>
                        <td>
                            <input type="password" id="cmms_telegram_token" name="cmms_telegram_token"
                                   value="<?php echo esc_attr( $token ); ?>" class="regular-text" autocomplete="off">
                            <p class="description">
                                <?php esc_html_e( 'The bot token from @BotFather (looks like 1234567890:ABC...). Keep this secret.', 'cmms-light' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="cmms_telegram_bot_username"><?php esc_html_e( 'Bot Username', 'cmms-light' ); ?></label></th>
                        <td>
                            <input type="text" id="cmms_telegram_bot_username" name="cmms_telegram_bot_username"
                                   value="<?php echo esc_attr( $bot_username ); ?>" class="regular-text" placeholder="cmms_light_bot">
                            <p class="description">
                                <?php esc_html_e( 'The username from BotFather (without the @). Used to build user-linking deep links.', 'cmms-light' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Settings', 'cmms-light' ) ); ?>
            </form>

            <?php if ( $enabled && $token ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Connection Status', 'cmms-light' ); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Bot identity', 'cmms-light' ); ?></th>
                        <td>
                            <?php if ( $bot_info ) : ?>
                                <span style="color:#1e8a4f;font-weight:600;">✅
                                    <?php echo esc_html( $bot_info['first_name'] ); ?>
                                    (@<?php echo esc_html( $bot_info['username'] ); ?>)
                                </span>
                            <?php else : ?>
                                <span style="color:#c2261a;font-weight:600;">❌ <?php esc_html_e( 'Token invalid or Telegram unreachable.', 'cmms-light' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Webhook URL', 'cmms-light' ); ?></th>
                        <td>
                            <code style="background:#f6f7f7;padding:4px 8px;display:inline-block;"><?php echo esc_html( $webhook_url ); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Webhook status', 'cmms-light' ); ?></th>
                        <td>
                            <?php if ( $webhook_info && ! empty( $webhook_info['url'] ) && $webhook_info['url'] === $webhook_url ) : ?>
                                <span style="color:#1e8a4f;font-weight:600;">✅ <?php esc_html_e( 'Connected', 'cmms-light' ); ?></span>
                                <?php if ( ! empty( $webhook_info['pending_update_count'] ) ) : ?>
                                    <span style="color:#c2261a;margin-inline-start:10px;">
                                        <?php
                                        printf(
                                            esc_html__( 'Pending updates: %d', 'cmms-light' ),
                                            (int) $webhook_info['pending_update_count']
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( ! empty( $webhook_info['last_error_message'] ) ) : ?>
                                    <p style="color:#c2261a;margin-top:6px;">
                                        <?php esc_html_e( 'Last error from Telegram:', 'cmms-light' ); ?>
                                        <code><?php echo esc_html( $webhook_info['last_error_message'] ); ?></code>
                                    </p>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color:#c2261a;font-weight:600;">❌ <?php esc_html_e( 'Not connected', 'cmms-light' ); ?></span>
                                <?php if ( $webhook_info && ! empty( $webhook_info['url'] ) ) : ?>
                                    <p style="color:#50575e;margin-top:6px;">
                                        <?php esc_html_e( 'Webhook is currently set to a different URL:', 'cmms-light' ); ?>
                                        <code><?php echo esc_html( $webhook_info['url'] ); ?></code>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <p>
                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field( 'cmms_telegram_action', 'cmms_telegram_action_nonce' ); ?>
                        <input type="hidden" name="cmms_telegram_action" value="connect">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Set Webhook (Connect)', 'cmms-light' ); ?>
                        </button>
                    </form>

                    <form method="post" style="display:inline-block;margin-inline-start:6px;">
                        <?php wp_nonce_field( 'cmms_telegram_action', 'cmms_telegram_action_nonce' ); ?>
                        <input type="hidden" name="cmms_telegram_action" value="disconnect">
                        <button type="submit" class="button">
                            <?php esc_html_e( 'Remove Webhook (Disconnect)', 'cmms-light' ); ?>
                        </button>
                    </form>

                    <form method="post" style="display:inline-block;margin-inline-start:6px;">
                        <?php wp_nonce_field( 'cmms_telegram_action', 'cmms_telegram_action_nonce' ); ?>
                        <input type="hidden" name="cmms_telegram_action" value="test">
                        <button type="submit" class="button">
                            <?php esc_html_e( 'Test Connection', 'cmms-light' ); ?>
                        </button>
                    </form>
                </p>

                <hr>
                <h2><?php esc_html_e( 'How users connect', 'cmms-light' ); ?></h2>
                <p style="max-width:720px;color:#50575e;">
                    <?php esc_html_e( 'After you click "Set Webhook", customer users can connect their Telegram from their profile page in the dashboard. They click "Connect Telegram", which generates a one-time deep link. Opening the link in Telegram triggers /start with a token, and the bot binds their Telegram account to their CMMS user.', 'cmms-light' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle form submissions. Two paths:
     *   - cmms_telegram_save  → save settings
     *   - cmms_telegram_action → connect/disconnect/test
     */
    public static function maybe_handle_save() {
        if ( ! isset( $_POST['cmms_telegram_nonce'] ) && ! isset( $_POST['cmms_telegram_action_nonce'] ) ) return;
        if ( ! current_user_can( self::CAP ) ) return;

        // Save settings path
        if ( isset( $_POST['cmms_telegram_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['cmms_telegram_nonce'], 'cmms_telegram_save' ) ) return;

            $enabled      = ! empty( $_POST['cmms_telegram_enabled'] );
            $token        = isset( $_POST['cmms_telegram_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['cmms_telegram_token'] ) ) ) : '';
            $bot_username = isset( $_POST['cmms_telegram_bot_username'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['cmms_telegram_bot_username'] ) ) ) : '';
            // Strip leading @ if user pasted with it
            $bot_username = ltrim( $bot_username, '@' );

            update_option( 'cmms_telegram_enabled', $enabled ? 1 : 0 );
            update_option( 'cmms_telegram_token', $token );
            update_option( 'cmms_telegram_bot_username', $bot_username );

            wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // Action path: connect/disconnect/test
        if ( isset( $_POST['cmms_telegram_action_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['cmms_telegram_action_nonce'], 'cmms_telegram_action' ) ) return;

            $action = isset( $_POST['cmms_telegram_action'] ) ? sanitize_text_field( wp_unslash( $_POST['cmms_telegram_action'] ) ) : '';
            $msg = '';
            $err = '';

            if ( $action === 'connect' ) {
                $resp = CMMS_Telegram::set_webhook();
                if ( ! empty( $resp['ok'] ) ) {
                    $msg = __( 'Webhook connected successfully. The bot is now ready to receive messages.', 'cmms-light' );
                } else {
                    $err = sprintf( __( 'Failed to set webhook: %s', 'cmms-light' ), $resp['error'] ?? 'Unknown error' );
                }
            } elseif ( $action === 'disconnect' ) {
                $resp = CMMS_Telegram::delete_webhook();
                if ( ! empty( $resp['ok'] ) ) {
                    $msg = __( 'Webhook removed. Telegram will stop sending updates.', 'cmms-light' );
                } else {
                    $err = sprintf( __( 'Failed to remove webhook: %s', 'cmms-light' ), $resp['error'] ?? 'Unknown error' );
                }
            } elseif ( $action === 'test' ) {
                $resp = CMMS_Telegram::get_me();
                if ( ! empty( $resp['ok'] ) ) {
                    $msg = sprintf(
                        __( 'Test successful! Bot: %s (@%s)', 'cmms-light' ),
                        $resp['result']['first_name'] ?? '?',
                        $resp['result']['username'] ?? '?'
                    );
                } else {
                    $err = sprintf( __( 'Test failed: %s', 'cmms-light' ), $resp['error'] ?? 'Unknown error' );
                }
            }

            $args = array( 'page' => self::SLUG );
            if ( $msg ) $args['action_done']  = $msg;
            if ( $err ) $args['action_error'] = $err;
            wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
            exit;
        }
    }
}

CMMS_Admin_Telegram::init();
