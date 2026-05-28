<?php
/**
 * Admin iCredit Settings page (1.14.36).
 *
 * Single screen for managing the GroupPrivateToken and mode.
 * The token is stored in wp_options as `cmms_icredit_settings`.
 *
 * We keep production and test tokens in separate fields so admins
 * can switch between modes without copy-pasting back and forth. 
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Admin_Icredit { 

    const CAP  = 'manage_options';
    const SLUG = 'cmms-light-icredit';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_handle_save' ) );
    }

    public static function render() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'cmms-light' ) );
        }

        $s = CMMS_Icredit::settings();
        $saved = ! empty( $_GET['saved'] );

        // Computed webhook and return URLs for the admin's reference —
        // these are what iCredit will use unless explicitly overridden
        // in the settings.
        $auto_ipn    = add_query_arg( 'action', 'cmms_icredit_ipn',    admin_url( 'admin-post.php' ) );
        $auto_return = add_query_arg( 'action', 'cmms_icredit_return', admin_url( 'admin-post.php' ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'iCredit Settings', 'cmms-light' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'cmms-light' ); ?></p>
                </div>
            <?php endif; ?>

            <p style="max-width:720px;color:#50575e;">
                <?php esc_html_e( 'Connect iCredit (Rivhit) to accept payments during onboarding. Use the test mode while configuring; switch to live only when you have a production GroupPrivateToken.', 'cmms-light' ); ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field( 'cmms_icredit_save', 'cmms_icredit_nonce' ); ?>
                <input type="hidden" name="cmms_icredit_save" value="1">

                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row"><label for="mode"><?php esc_html_e( 'Mode', 'cmms-light' ); ?></label></th>
                        <td>
                            <select name="mode" id="mode">
                                <option value="test" <?php selected( $s['mode'], 'test' ); ?>><?php esc_html_e( 'Test (sandbox)', 'cmms-light' ); ?></option>
                                <option value="live" <?php selected( $s['mode'], 'live' ); ?>><?php esc_html_e( 'Live (production)', 'cmms-light' ); ?></option>
                            </select>
                            <p class="description">
                                <?php echo $s['mode'] === 'live'
                                    ? '<strong style="color:#d63638">LIVE — real charges.</strong>'
                                    : 'Test mode — no real charges. Card 4580000000000001 succeeds.'; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="test_token"><?php esc_html_e( 'Test GroupPrivateToken', 'cmms-light' ); ?></label></th>
                        <td>
                            <input type="text" name="test_token" id="test_token" class="regular-text"
                                   value="<?php echo esc_attr( $s['test_token'] ); ?>" autocomplete="off">
                            <p class="description"><?php esc_html_e( 'Sandbox token. The public token a1408bfc-18da-49dc-aa77-d65870f7943e works for any test integration.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="live_token"><?php esc_html_e( 'Live GroupPrivateToken', 'cmms-light' ); ?></label></th>
                        <td>
                            <input type="text" name="live_token" id="live_token" class="regular-text"
                                   value="<?php echo esc_attr( $s['live_token'] ); ?>" autocomplete="off">
                            <p class="description"><?php esc_html_e( 'Your production token. Required before switching to Live mode.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Webhook (IPN) URL', 'cmms-light' ); ?></th>
                        <td>
                            <input type="text" readonly class="large-text code" value="<?php echo esc_attr( $auto_ipn ); ?>">
                            <p class="description"><?php esc_html_e( 'Configure this URL as the IPNURL in your iCredit account if needed. Sent automatically with each sale.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Return URL', 'cmms-light' ); ?></th>
                        <td>
                            <input type="text" readonly class="large-text code" value="<?php echo esc_attr( $auto_return ); ?>">
                            <p class="description"><?php esc_html_e( 'Where users return after paying. Sent automatically with each sale.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'cmms-light' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    public static function maybe_handle_save() {
        if ( empty( $_POST['cmms_icredit_save'] ) ) return;
        if ( ! current_user_can( self::CAP ) ) return;
        check_admin_referer( 'cmms_icredit_save', 'cmms_icredit_nonce' );

        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'test';
        if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) $mode = 'test';

        update_option( 'cmms_icredit_settings', array(
            'mode'        => $mode,
            'test_token'  => isset( $_POST['test_token'] ) ? sanitize_text_field( wp_unslash( $_POST['test_token'] ) ) : '',
            'live_token'  => isset( $_POST['live_token'] ) ? sanitize_text_field( wp_unslash( $_POST['live_token'] ) ) : '',
            'ipn_url'     => '',
            'return_url'  => '',
        ) );

        wp_safe_redirect( add_query_arg( array(
            'page'  => self::SLUG,
            'saved' => 1,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }
}

CMMS_Admin_Icredit::init();
