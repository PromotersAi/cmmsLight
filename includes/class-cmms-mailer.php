<?php
/**
 * CMMS Mailer (1.14.43).
 *
 * Sends transactional emails using wp_mail() under the hood. WordPress
 * routes wp_mail through any configured SMTP plugin (FluentSMTP, WP Mail
 * SMTP), so we don't bind to a specific provider here.
 *
 * Currently supports:
 *   - Welcome email (sent once per account, after first successful
 *     payment / activation)
 *
 * Templates are inline HTML — keeps everything in one file, easier
 * to tweak. RTL, mobile-first, no external CSS or images required.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Mailer {

    /**
     * 1.14.70: The init() method and From-override filters added in
     * 1.14.69 have been REMOVED. They forced WordPress to send mail
     * with a From of noreply@cmms.co.il, but the underlying SMTP was
     * still Upress — causing SPF softfail at Gmail/Outlook AND outright
     * rejection at Cloudflare Email Routing. Until a proper SMTP
     * provider is configured (Brevo / Mailgun / SES), we leave WP's
     * default From behavior alone: the From will follow the WordPress
     * admin email, which the customer can change in Settings → General
     * to any address they prefer (e.g. their own Gmail/Workspace).
     */

    /**
     * Send the Welcome email for an account.
     *
     * Idempotent — checks accounts.welcome_email_sent_at first; if
     * already set, returns true without sending. Marks the timestamp
     * after a successful send so a re-trigger (e.g. an IPN retry)
     * doesn't double-send.
     *
     * @param int $account_id
     * @return bool true on success or already-sent; false on failure
     */
    public static function send_welcome( $account_id ) {
        $account_id = (int) $account_id;
        if ( ! $account_id ) return false;

        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $users_t    = CMMS_DB::table( 'users' );

        // Pull the account row.
        $account = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $accounts_t WHERE id = %d",
            $account_id
        ), ARRAY_A );
        if ( ! $account ) return false;

        // Idempotency guard — already sent? Return success.
        if ( ! empty( $account['welcome_email_sent_at'] ) ) {
            return true;
        }

        // Owner user details. The email lives on the WordPress users
        // table (wp_users), not on cmms_users — cmms_users only has
        // wp_user_id pointing there. Join the two so we get the right
        // address. 1.14.48 fix.
        $owner = $wpdb->get_row( $wpdb->prepare(
            "SELECT cu.*, wpu.user_email AS email, wpu.display_name AS wp_display_name
             FROM $users_t cu
             LEFT JOIN {$wpdb->users} wpu ON wpu.ID = cu.wp_user_id
             WHERE cu.id = %d",
            (int) $account['owner_user_id']
        ), ARRAY_A );
        if ( ! $owner || empty( $owner['email'] ) ) return false;

        // Resolve package details for the body.
        $package = null;
        if ( ! empty( $account['plan_type'] ) && ! empty( $account['billing_cycle'] ) ) {
            $package = CMMS_Plans::get_package( $account['plan_type'], $account['billing_cycle'] );
        }
        $package_name  = $package ? $package['display_name'] : ( ucfirst( $account['plan_type'] ?? '' ) ?: '—' );
        $cycle_label   = ( $account['billing_cycle'] === 'yearly' ) ? 'שנתי' : 'חודשי';

        // Display name: prefer cmms_users.display_name, fall back to wp_users.

        // Display name: prefer cmms_users.display_name, fall back to wp_users.
        $display_name = trim( (string) ( $owner['display_name'] ?? '' ) );
        if ( $display_name === '' ) $display_name = trim( (string) ( $owner['wp_display_name'] ?? '' ) );
        if ( $display_name === '' ) $display_name = 'לקוח/ה';

        // 1.14.59: Subject and body now come from the editable
        // template system. If the admin customized "welcome" via
        // Admin → Email Templates, those values are used; otherwise
        // the hardcoded defaults from CMMS_EmailTemplates::defaults().
        $next_payment_date = '';
        if ( class_exists( 'CMMS_Subscriptions' ) ) {
            $sub = CMMS_Subscriptions::for_account( $account_id );
            if ( $sub && ! empty( $sub['next_charge_at'] ) ) {
                $next_payment_date = mysql2date( 'd/m/Y', $sub['next_charge_at'] );
            }
        }

        $vars = array(
            'FULL_NAME'         => $display_name,
            'COMPANY_NAME'      => $account['name'] ?? '—',
            'PACKAGE_NAME'      => $package_name,
            'BILLING_CYCLE'     => $cycle_label,
            'NEXT_PAYMENT_DATE' => $next_payment_date,
            'USER_EMAIL'        => $owner['email'],
            'DASHBOARD_URL'     => home_url( '/cmms-dashboard/' ),
            'SUPPORT_EMAIL'     => 'guy@promoters.co.il',
            'SITE_URL'          => home_url( '/' ),
        );

        $sent = self::send_templated( 'welcome', $owner['email'], $vars );

        if ( $sent ) {
            // Mark sent so we never duplicate.
            $wpdb->update(
                $accounts_t,
                array( 'welcome_email_sent_at' => current_time( 'mysql' ) ),
                array( 'id' => $account_id ),
                array( '%s' ),
                array( '%d' )
            );
            return true;
        }
        // Log failure for debugging but don't crash the IPN flow.
        error_log( 'CMMS_Mailer: wp_mail failed for account ' . $account_id );
        return false;
    }

    /**
     * 1.14.59: Generic templated send.
     *
     * Looks up the template by key (admin-customized or default),
     * substitutes variables, wraps in the branded shell, and sends.
     *
     * Returns true on success, false on failure. Failures are logged
     * but never thrown — callers (IPN, cron) need to keep going.
     *
     * @param string $key     Template key (welcome, past_due, frozen, subscription_canceled)
     * @param string $to      Recipient email
     * @param array  $vars    Associative array of TOKEN => value (no braces; we add them)
     * @return bool
     */
    public static function send_templated( $key, $to, $vars = array() ) {
        if ( ! is_email( $to ) ) return false;

        $tpl = CMMS_EmailTemplates::get( $key );
        if ( ! $tpl ) {
            error_log( 'CMMS_Mailer: unknown template key "' . $key . '"' );
            return false;
        }

        $subject  = CMMS_EmailTemplates::render( $tpl['subject'],   $vars );
        $body_in  = CMMS_EmailTemplates::render( $tpl['body_html'], $vars );
        $body_out = CMMS_EmailTemplates::wrap_in_shell( $body_in );

        $headers = self::default_headers();
        return wp_mail( $to, $subject, $body_out, $headers );
    }

    /* ============================================================
       HEADERS
    ============================================================ */

    /**
     * Default email headers — HTML + branded From address.
     * From is taken from cmms_brand_email option (Brand Settings),
     * falls back to noreply@cmms.co.il.
     */
    private static function default_headers() {
        $from_email = get_option( 'cmms_brand_email', '' );
        if ( ! $from_email || ! is_email( $from_email ) ) {
            $from_email = 'noreply@cmms.co.il';
        }
        $from_name = 'CMMS Light';
        return array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
        );
    }

    /* ============================================================
       WELCOME EMAIL TEMPLATE
    ============================================================ */

    /**
     * Render the Welcome email body.
     * Inline CSS only — most email clients strip <style> tags.
     * Tables for layout — required for Outlook compatibility.
     */
    private static function render_welcome_html( $vars ) {
        $orange = '#ff6a00';
        $orange_dark = '#e85d00';
        $text_dark = '#0f172a';
        $text_muted = '#64748b';
        $bg_light = '#f8fafc';
        $border = '#e2e8f0';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ברוכים הבאים ל־CMMS Light</title>
</head>
<body style="margin:0; padding:0; background:<?php echo $bg_light; ?>; font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; color:<?php echo $text_dark; ?>;">

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:<?php echo $bg_light; ?>; padding: 24px 12px;" dir="rtl">
    <tr>
        <td align="center">

            <!-- Main email container -->
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 14px rgba(15,23,42,0.06);" dir="rtl">

                <!-- Header -->
                <tr>
                    <td style="background: linear-gradient(135deg, <?php echo $orange; ?> 0%, <?php echo $orange_dark; ?> 100%); padding: 32px 28px; text-align: center;">
                        <h1 style="color:#ffffff; font-size:26px; font-weight:700; margin:0 0 8px; font-family: Arial, sans-serif;">CMMS Light</h1>
                        <p style="color:#ffffff; opacity:0.92; font-size:15px; margin:0; font-family: Arial, sans-serif;">ה־CMMS הכי פשוט ומהיר להטמעה בישראל</p>
                    </td>
                </tr>

                <!-- Greeting -->
                <tr>
                    <td style="padding: 36px 32px 12px;">
                        <h2 style="color:<?php echo $text_dark; ?>; font-size:22px; font-weight:700; margin:0 0 16px; font-family: Arial, sans-serif;">
                            שלום <?php echo esc_html( $vars['{{FULL_NAME}}'] ); ?>,
                        </h2>
                        <p style="color:<?php echo $text_dark; ?>; font-size:15px; line-height:1.7; margin:0 0 12px; font-family: Arial, sans-serif;">
                            ברוכים הבאים ל־<strong>CMMS Light</strong> — ה־CMMS הכי פשוט ומהיר להטמעה בישראל.
                        </p>
                        <p style="color:<?php echo $text_muted; ?>; font-size:15px; line-height:1.7; margin:0 0 28px; font-family: Arial, sans-serif;">
                            החשבון שלך נוצר בהצלחה והמערכת מוכנה להתחלת עבודה.
                        </p>
                    </td>
                </tr>

                <!-- Account details card -->
                <tr>
                    <td style="padding: 0 32px 24px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:<?php echo $bg_light; ?>; border:1px solid <?php echo $border; ?>; border-radius:12px;" dir="rtl">
                            <tr>
                                <td style="padding: 20px 22px;">
                                    <p style="color:<?php echo $text_dark; ?>; font-size:14px; font-weight:700; margin:0 0 14px; font-family: Arial, sans-serif;">פרטי החשבון</p>

                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" dir="rtl">
                                        <tr>
                                            <td style="color:<?php echo $text_muted; ?>; font-size:13px; padding:6px 0; font-family: Arial, sans-serif; width:40%;">חברה:</td>
                                            <td style="color:<?php echo $text_dark; ?>; font-size:14px; padding:6px 0; font-family: Arial, sans-serif; font-weight:600;"><?php echo esc_html( $vars['{{COMPANY_NAME}}'] ); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:<?php echo $text_muted; ?>; font-size:13px; padding:6px 0; font-family: Arial, sans-serif; border-top:1px dashed <?php echo $border; ?>;">חבילה:</td>
                                            <td style="color:<?php echo $text_dark; ?>; font-size:14px; padding:6px 0; font-family: Arial, sans-serif; font-weight:600; border-top:1px dashed <?php echo $border; ?>;"><?php echo esc_html( $vars['{{PACKAGE_NAME}}'] ); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:<?php echo $text_muted; ?>; font-size:13px; padding:6px 0; font-family: Arial, sans-serif; border-top:1px dashed <?php echo $border; ?>;">מחזור חיוב:</td>
                                            <td style="color:<?php echo $text_dark; ?>; font-size:14px; padding:6px 0; font-family: Arial, sans-serif; font-weight:600; border-top:1px dashed <?php echo $border; ?>;"><?php echo esc_html( $vars['{{BILLING_CYCLE}}'] ); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:<?php echo $text_muted; ?>; font-size:13px; padding:6px 0; font-family: Arial, sans-serif; border-top:1px dashed <?php echo $border; ?>;">משתמש ראשי:</td>
                                            <td style="color:<?php echo $text_dark; ?>; font-size:14px; padding:6px 0; font-family: Arial, sans-serif; font-weight:600; border-top:1px dashed <?php echo $border; ?>; word-break:break-all;"><?php echo esc_html( $vars['{{USER_EMAIL}}'] ); ?></td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Primary CTA -->
                <tr>
                    <td style="padding: 8px 32px 32px; text-align:center;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;">
                            <tr>
                                <td style="background: linear-gradient(135deg, <?php echo $orange; ?> 0%, <?php echo $orange_dark; ?> 100%); border-radius:10px;">
                                    <a href="<?php echo esc_url( $vars['{{DASHBOARD_URL}}'] ); ?>"
                                       style="display:inline-block; padding:14px 36px; color:#ffffff; text-decoration:none; font-weight:700; font-size:16px; font-family: Arial, sans-serif; letter-spacing:0.01em;">
                                        כניסה למערכת
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Recommended next steps -->
                <tr>
                    <td style="padding: 0 32px 8px;">
                        <h3 style="color:<?php echo $text_dark; ?>; font-size:17px; font-weight:700; margin:0 0 14px; font-family: Arial, sans-serif;">צעדים ראשונים מומלצים:</h3>
                        <ol style="color:<?php echo $text_dark; ?>; font-size:14px; line-height:1.9; margin:0 0 20px; padding-right:20px; font-family: Arial, sans-serif;">
                            <li>יצירת משימת אחזקה ראשונה</li>
                            <li>הוספת נכס או מכונה</li>
                            <li>הזמנת משתמשים נוספים</li>
                            <li>יצירת QR לפתיחת תקלות</li>
                            <li>התאמת קטגוריות אחזקה לארגון</li>
                        </ol>
                    </td>
                </tr>

                <!-- Feature highlights -->
                <tr>
                    <td style="padding: 16px 32px 28px;">
                        <h3 style="color:<?php echo $text_dark; ?>; font-size:17px; font-weight:700; margin:0 0 14px; font-family: Arial, sans-serif;">מה מחכה לך במערכת:</h3>
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" dir="rtl">
                            <?php
                            $features = array(
                                'ניהול אחזקת שבר',
                                'אחזקה מונעת ומתוכננת',
                                'ניהול נכסים ומכונות',
                                'פתיחת תקלות באמצעות QR',
                                'עבודה מלאה מהנייד',
                                'דוחות KPI ובקרה',
                                'הרשאות משתמשים וצוותים',
                            );
                            foreach ( $features as $f ) :
                            ?>
                            <tr>
                                <td style="color:<?php echo $text_dark; ?>; font-size:14px; padding:5px 0; font-family: Arial, sans-serif;">
                                    <span style="color:#10b981; font-weight:bold; margin-left:8px;">✓</span>
                                    <?php echo esc_html( $f ); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                </tr>

                <!-- Support box -->
                <tr>
                    <td style="padding: 0 32px 32px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fff7ed; border:1px solid #fed7aa; border-radius:12px;" dir="rtl">
                            <tr>
                                <td style="padding: 18px 22px;">
                                    <p style="color:<?php echo $text_dark; ?>; font-size:14px; font-weight:700; margin:0 0 6px; font-family: Arial, sans-serif;">זקוקים לעזרה?</p>
                                    <p style="color:<?php echo $text_muted; ?>; font-size:14px; line-height:1.6; margin:0 0 8px; font-family: Arial, sans-serif;">צוות CMMS Light כאן בשבילכם.</p>
                                    <p style="margin:0; font-family: Arial, sans-serif;">
                                        <a href="mailto:<?php echo esc_attr( $vars['{{SUPPORT_EMAIL}}'] ); ?>" style="color:<?php echo $orange; ?>; text-decoration:none; font-size:14px; font-weight:600;"><?php echo esc_html( $vars['{{SUPPORT_EMAIL}}'] ); ?></a>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="background:<?php echo $bg_light; ?>; padding: 20px 32px; text-align:center; border-top:1px solid <?php echo $border; ?>;">
                        <p style="color:<?php echo $text_muted; ?>; font-size:12px; margin:0 0 4px; font-family: Arial, sans-serif;">
                            <a href="<?php echo esc_url( $vars['{{SITE_URL}}'] ); ?>" style="color:<?php echo $text_muted; ?>; text-decoration:none;"><?php echo esc_html( $vars['{{SITE_URL}}'] ); ?></a>
                        </p>
                        <p style="color:<?php echo $text_muted; ?>; font-size:11px; margin:0; font-family: Arial, sans-serif;">
                            © <?php echo gmdate( 'Y' ); ?> CMMS Light. כל הזכויות שמורות.
                        </p>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
        <?php
        return ob_get_clean();
    }
}
