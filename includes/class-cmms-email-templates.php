<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CMMS_EmailTemplates (1.14.59)
 *
 * Manages the editable email templates used by the system mailer.
 *
 * Storage model:
 *   - One row per template_key in the cmms_email_templates table
 *   - Subject and body_html columns hold the editable content
 *   - If a key has no row (admin never customized it), get() returns
 *     the hardcoded default — so the system is always functional
 *     even on fresh installs.
 *
 * Templates supported (defaults are seeded by activator):
 *   - welcome                : Sent after a successful initial payment
 *   - past_due               : Sent when iCredit recurring charge fails
 *   - frozen                 : Sent when grace period expires
 *   - subscription_canceled  : Sent after user clicks Cancel in dashboard
 *
 * Variable substitution: caller provides an associative array of
 * {{TOKEN}} => value pairs. We replace using str_replace — simple
 * and predictable, no expression evaluation, no security pitfalls.
 *
 * Note: We use square-bracket-looking tokens in the UI ([FULL_NAME])
 * for friendliness, but the actual placeholder in the body is
 * {{FULL_NAME}} (mustache-style) so it's distinguishable from any
 * stray text the admin might paste. The UI renders [TOKEN] as a
 * clickable chip that inserts {{TOKEN}} at the cursor.
 */
class CMMS_EmailTemplates {

    /** All known template keys with their display labels (Hebrew). */
    public static function known_templates() {
        return array(
            'welcome' => array(
                'label'       => 'ברוכים הבאים',
                'description' => 'נשלח אחרי תשלום מוצלח של הרשמה ראשונה.',
            ),
            'past_due' => array(
                'label'       => 'חיוב נכשל',
                'description' => 'נשלח כאשר חיוב חוזר ב-iCredit נכשל.',
            ),
            'frozen' => array(
                'label'       => 'חשבון מוקפא',
                'description' => 'נשלח כאשר תקופת החסד הסתיימה ללא חיוב מוצלח.',
            ),
            'subscription_canceled' => array(
                'label'       => 'אישור ביטול חברות',
                'description' => 'נשלח אחרי שהמשתמש ביטל את הוראת הקבע מהדאשבורד.',
            ),
            'password_reset' => array(
                'label'       => 'איפוס סיסמה',
                'description' => 'נשלח כאשר משתמש מבקש איפוס סיסמה דרך "שכחתי סיסמה".',
            ),
            // 1.14.75 — Phase 5 self-service seat purchase emails.
            'seats_purchased' => array(
                'label'       => 'אישור רכישת משתמשים',
                'description' => 'נשלח ללקוח אחרי שהוא רכש משתמשים נוספים מהדאשבורד.',
            ),
            'admin_seats_alert' => array(
                'label'       => 'התראה למנהל — רכישת משתמשים',
                'description' => 'נשלח לכתובת האימייל של מנהל האתר כאשר לקוח רוכש משתמשים נוספים. כולל פרטים על העדכון הידני הנדרש ב-iCredit.',
            ),
        );
    }

    /** Variables available to all templates, in Hebrew display order. */
    public static function available_variables() {
        return array(
            'FULL_NAME'         => 'שם מלא של הלקוח',
            'COMPANY_NAME'      => 'שם החברה',
            'PACKAGE_NAME'      => 'שם החבילה (Starter / Business / Enterprise)',
            'BILLING_CYCLE'     => 'מחזור חיוב (חודשי / שנתי)',
            'NEXT_PAYMENT_DATE' => 'תאריך החיוב הבא',
            'DASHBOARD_URL'     => 'קישור לדאשבורד',
            'SUPPORT_EMAIL'     => 'כתובת אימייל לתמיכה',
            'SITE_URL'          => 'כתובת האתר הראשית',
            'USER_EMAIL'        => 'אימייל הלקוח',
            'RESET_URL'         => 'קישור לאיפוס סיסמה (במייל איפוס בלבד)',
            // 1.14.75 — additional vars for seat-related emails.
            'SEATS_ADDED'       => 'כמות המשתמשים שנוספו (במיילי רכישת משתמשים)',
            'AMOUNT_PAID'       => 'הסכום ששולם (במיילי רכישת משתמשים)',
            'NEW_TOTAL_USERS'   => 'כמות המשתמשים החדשה הכוללת (במייל אישור ללקוח)',
            'NEW_RECURRING'     => 'סכום החיוב המחזורי החדש (במייל התראה למנהל)',
            'RECURRING_ID'      => 'מזהה ה-Recurring ב-iCredit (במייל התראה למנהל)',
            'ADMIN_PANEL_URL'   => 'קישור לפאנל הניהול (במייל התראה למנהל)',
        );
    }

    /**
     * Returns an associative array with 'subject' and 'body_html' for
     * the given template key. If the admin has customized the template,
     * those values are returned. Otherwise the hardcoded default is.
     */
    public static function get( $key ) {
        $defaults = self::defaults();
        if ( ! isset( $defaults[ $key ] ) ) return null;

        global $wpdb;
        $t = CMMS_DB::table( 'email_templates' );
        if ( $t ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT subject, body_html FROM $t WHERE template_key = %s",
                $key
            ), ARRAY_A );
            if ( $row && ! empty( $row['subject'] ) && ! empty( $row['body_html'] ) ) {
                return array(
                    'subject'   => (string) $row['subject'],
                    'body_html' => (string) $row['body_html'],
                    'customized' => true,
                );
            }
        }

        // Fall back to hardcoded defaults.
        return array(
            'subject'   => $defaults[ $key ]['subject'],
            'body_html' => $defaults[ $key ]['body_html'],
            'customized' => false,
        );
    }

    /**
     * Save (insert or update) a template for the given key.
     */
    public static function save( $key, $subject, $body_html, $user_id = null ) {
        $defaults = self::defaults();
        if ( ! isset( $defaults[ $key ] ) ) return false;

        global $wpdb;
        $t = CMMS_DB::table( 'email_templates' );
        if ( ! $t ) return false;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $t WHERE template_key = %s", $key
        ) );
        $now = current_time( 'mysql' );

        if ( $existing ) {
            $wpdb->update( $t, array(
                'subject'   => $subject,
                'body_html' => $body_html,
                'updated_by' => $user_id,
                'updated_at' => $now,
            ), array( 'id' => (int) $existing ) );
        } else {
            $wpdb->insert( $t, array(
                'template_key' => $key,
                'subject'      => $subject,
                'body_html'    => $body_html,
                'updated_by'   => $user_id,
                'updated_at'   => $now,
            ) );
        }
        return true;
    }

    /**
     * Reset a template back to the hardcoded default (deletes the
     * customization row). The mailer will then use the built-in copy.
     */
    public static function reset( $key ) {
        global $wpdb;
        $t = CMMS_DB::table( 'email_templates' );
        if ( ! $t ) return false;
        return $wpdb->delete( $t, array( 'template_key' => $key ) );
    }

    /**
     * Apply variable substitutions to a string. Variables use
     * mustache-style {{TOKEN}} delimiters. Unknown tokens are left
     * untouched (so a typo doesn't silently delete content).
     */
    public static function render( $template, $vars ) {
        if ( ! is_array( $vars ) ) $vars = array();
        $search  = array();
        $replace = array();
        foreach ( $vars as $key => $val ) {
            $search[]  = '{{' . $key . '}}';
            $replace[] = (string) $val;
        }
        return str_replace( $search, $replace, $template );
    }

    /**
     * Wraps a body in a branded HTML email shell.
     *
     * Design language (1.14.59):
     *   - White card on light gray background
     *   - Orange brand stripe at top
     *   - Arial font (universally supported in email clients)
     *   - 600px max width, RTL
     *   - Inline CSS only (no external stylesheet — email clients
     *     strip <style> tags inconsistently)
     */
    public static function wrap_in_shell( $inner_html ) {
        $brand = get_option( 'cmms_brand_name' ) ?: 'CMMS';
        $logo_url = get_option( 'cmms_brand_logo' ) ?: '';
        $year = date( 'Y' );
        $shell = '<!doctype html><html lang="he" dir="rtl"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . esc_html( $brand ) . '</title>'
            . '</head><body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;direction:rtl;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,0.06);">'
            // Orange top stripe
            . '<tr><td style="background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);height:6px;"></td></tr>'
            // Logo + brand
            . '<tr><td style="padding:28px 32px 8px 32px;text-align:right;">'
            . ( $logo_url
                ? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $brand ) . '" style="max-height:44px;display:inline-block;">'
                : '<span style="font-size:22px;font-weight:700;color:#ff6a00;">' . esc_html( $brand ) . '</span>'
              )
            . '</td></tr>'
            // Body
            . '<tr><td style="padding:18px 32px 28px 32px;font-size:15px;line-height:1.7;color:#0f172a;">'
            . $inner_html
            . '</td></tr>'
            // Footer
            . '<tr><td style="padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;text-align:center;">'
            . '&copy; ' . esc_html( $year ) . ' ' . esc_html( $brand ) . ' &middot; כל הזכויות שמורות'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';
        return $shell;
    }

    /**
     * Hardcoded default templates. These are the templates used when
     * the admin hasn't customized anything. They're also the seeds
     * for the editor when admin opens an unedited template for the
     * first time.
     *
     * Body is INNER content only (no <html>/<body> wrapping). The
     * wrap_in_shell() method adds the branded shell at send time.
     */
    public static function defaults() {
        return array(
            'welcome' => array(
                'subject'   => 'ברוכים הבאים ל-{{COMPANY_NAME}}',
                'body_html' =>
                    '<h2 style="color:#0f172a;margin:0 0 14px;font-size:22px;">היי {{FULL_NAME}}, ברוך/ה הבא/ה!</h2>'
                    . '<p style="margin:0 0 14px;">החשבון שלך הופעל בהצלחה ואנחנו שמחים שבחרת בנו.</p>'
                    . '<p style="margin:0 0 14px;"><strong>חבילה:</strong> {{PACKAGE_NAME}} ({{BILLING_CYCLE}})</p>'
                    . '<p style="margin:0 0 22px;">תוכל/י להיכנס לדאשבורד בכל עת ולהתחיל לעבוד מיד.</p>'
                    . '<p style="margin:0 0 22px;"><a href="{{DASHBOARD_URL}}" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:600;">כניסה לדאשבורד</a></p>'
                    . '<p style="margin:0 0 8px;font-size:13px;color:#64748b;">שאלות? נשמח לעזור — פנה/י אלינו ב-{{SUPPORT_EMAIL}}.</p>',
            ),

            'past_due' => array(
                'subject'   => 'חיוב נכשל - {{COMPANY_NAME}}',
                'body_html' =>
                    '<h2 style="color:#0f172a;margin:0 0 14px;font-size:22px;">{{FULL_NAME}}, היה כשל בחיוב</h2>'
                    . '<p style="margin:0 0 14px;">לא הצלחנו לחייב את הכרטיס שלך עבור החבילה <strong>{{PACKAGE_NAME}}</strong>.</p>'
                    . '<p style="margin:0 0 14px;">אנחנו ננסה לחייב שוב במהלך הימים הקרובים. בינתיים, המערכת ממשיכה לפעול כרגיל.</p>'
                    . '<p style="margin:0 0 22px;">אם הכרטיס פג תוקף או נחסם - מומלץ לעדכן את אמצעי התשלום ב-iCredit.</p>'
                    . '<p style="margin:0 0 22px;"><a href="{{DASHBOARD_URL}}" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:600;">כניסה לדאשבורד</a></p>'
                    . '<p style="margin:0 0 8px;font-size:13px;color:#64748b;">לתמיכה: {{SUPPORT_EMAIL}}</p>',
            ),

            'frozen' => array(
                'subject'   => 'חשבון מוקפא - {{COMPANY_NAME}}',
                'body_html' =>
                    '<h2 style="color:#0f172a;margin:0 0 14px;font-size:22px;">{{FULL_NAME}}, החשבון הוקפא</h2>'
                    . '<p style="margin:0 0 14px;">לאחר כמה ניסיונות חיוב לא מוצלחים, החשבון של <strong>{{COMPANY_NAME}}</strong> הוקפא זמנית.</p>'
                    . '<p style="margin:0 0 14px;">הנתונים שלך שמורים אצלנו ולא ימחקו. כדי להפעיל מחדש את החשבון - יש לעדכן את אמצעי התשלום.</p>'
                    . '<p style="margin:0 0 22px;"><a href="{{DASHBOARD_URL}}" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:600;">עדכן אמצעי תשלום</a></p>'
                    . '<p style="margin:0 0 8px;font-size:13px;color:#64748b;">לעזרה: {{SUPPORT_EMAIL}}</p>',
            ),

            'subscription_canceled' => array(
                'subject'   => 'אישור ביטול חברות - {{COMPANY_NAME}}',
                'body_html' =>
                    '<h2 style="color:#0f172a;margin:0 0 14px;font-size:22px;">{{FULL_NAME}}, החברות בוטלה</h2>'
                    . '<p style="margin:0 0 14px;">קיבלנו את בקשתך לבטל את הוראת הקבע על חבילת <strong>{{PACKAGE_NAME}}</strong>. הביטול בוצע בהצלחה.</p>'
                    . '<p style="margin:0 0 14px;"><strong>חשוב לדעת:</strong> תוכל/י להמשיך להשתמש במערכת באופן רגיל עד <strong>{{NEXT_PAYMENT_DATE}}</strong> - תום תקופת החיוב הנוכחית.</p>'
                    . '<p style="margin:0 0 22px;">אחרי תאריך זה, הגישה לחשבון תיחסם והנתונים יישמרו אצלנו למקרה שתרצה/י לחזור אלינו.</p>'
                    . '<p style="margin:0 0 22px;"><a href="{{DASHBOARD_URL}}" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:600;">כניסה לדאשבורד</a></p>'
                    . '<p style="margin:0 0 8px;font-size:13px;color:#64748b;">משוב? נשמח לשמוע למה בחרת לעזוב: {{SUPPORT_EMAIL}}</p>',
            ),

            'password_reset' => array(
                'subject'   => 'איפוס סיסמה - CMMS',
                'body_html' =>
                    '<h2 style="color:#0f172a;margin:0 0 14px;font-size:22px;">היי {{FULL_NAME}}, ביקשת איפוס סיסמה</h2>'
                    . '<p style="margin:0 0 14px;">קיבלנו בקשה לאיפוס הסיסמה לחשבון שלך ב-CMMS. לחיצה על הכפתור הבא תוביל אותך לדף קביעת סיסמה חדשה:</p>'
                    . '<p style="margin:0 0 22px;"><a href="{{RESET_URL}}" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:600;">איפוס סיסמה</a></p>'
                    . '<p style="margin:0 0 14px;font-size:13px;color:#64748b;">הקישור תקף ל-24 שעות. לאחר מכן יהיה צורך לבקש איפוס מחדש.</p>'
                    . '<p style="margin:0 0 14px;font-size:13px;color:#64748b;">אם לא ביקשת איפוס סיסמה — אפשר להתעלם מהמייל הזה והסיסמה הנוכחית תישאר ללא שינוי.</p>'
                    . '<p style="margin:0 0 8px;font-size:13px;color:#64748b;">לעזרה: {{SUPPORT_EMAIL}}</p>',
            ),

            // 1.14.75 — Customer confirmation after self-service seat purchase.
            'seats_purchased' => array(
                'subject'   => 'אישור רכישת משתמשים נוספים - CMMS',
                'body_html' =>
                    '<h2 style="color:#0f172a;margin:0 0 14px;font-size:22px;">{{FULL_NAME}}, הוספת המשתמשים הצליחה 🎉</h2>'
                    . '<p style="margin:0 0 14px;">תודה על הרכישה. {{SEATS_ADDED}} משתמשים נוספים נפתחו בחשבונך ב-CMMS ופעילים מעכשיו.</p>'
                    . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin:0 0 22px;">'
                    . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                    . '<tr><td style="padding:6px 0;color:#64748b;">חבילה</td><td style="padding:6px 0;text-align:end;font-weight:600;">{{PACKAGE_NAME}} / {{BILLING_CYCLE}}</td></tr>'
                    . '<tr><td style="padding:6px 0;color:#64748b;">משתמשים נוספים</td><td style="padding:6px 0;text-align:end;font-weight:600;">{{SEATS_ADDED}}</td></tr>'
                    . '<tr><td style="padding:6px 0;color:#64748b;">סה"כ משתמשים מותרים</td><td style="padding:6px 0;text-align:end;font-weight:600;">{{NEW_TOTAL_USERS}}</td></tr>'
                    . '<tr><td style="padding:6px 0;color:#64748b;">חיוב יחסי ששולם</td><td style="padding:6px 0;text-align:end;font-weight:700;color:#4f46e5;">{{AMOUNT_PAID}}</td></tr>'
                    . '</table>'
                    . '</div>'
                    . '<p style="margin:0 0 14px;font-size:13px;color:#64748b;">החיוב המחזורי החודש הבא יעודכן בהתאם לסכום החדש. אישור החיוב נשלח גם דרך iCredit.</p>'
                    . '<p style="margin:0 0 22px;"><a href="{{DASHBOARD_URL}}" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:600;">חזרה לדאשבורד</a></p>'
                    . '<p style="margin:0 0 8px;font-size:13px;color:#64748b;">לכל שאלה ניתן לפנות אלינו ב-{{SUPPORT_EMAIL}}</p>',
            ),

            // 1.14.75 — Admin alert when a customer self-purchases seats.
            // Sent to wp_options.admin_email so the admin knows to update
            // the iCredit recurring manually.
            'admin_seats_alert' => array(
                'subject'   => '⚠️ עדכון נדרש ב-iCredit — לקוח רכש משתמשים נוספים',
                'body_html' =>
                    '<h2 style="color:#0f172a;margin:0 0 14px;font-size:22px;">לקוח רכש משתמשים נוספים</h2>'
                    . '<p style="margin:0 0 14px;font-size:14px;">לקוח השלים רכישת משתמשים נוספים דרך הדאשבורד. נדרש עדכון ידני של ה-Recurring ב-iCredit.</p>'
                    . '<div style="background:#fffbeb;border:1.5px solid #fcd34d;border-radius:10px;padding:16px 20px;margin:0 0 18px;">'
                    . '<h3 style="margin:0 0 10px;font-size:15px;color:#78350f;">פרטי הלקוח</h3>'
                    . '<table style="width:100%;border-collapse:collapse;font-size:13.5px;">'
                    . '<tr><td style="padding:5px 0;color:#78350f;">חברה</td><td style="padding:5px 0;text-align:end;font-weight:600;">{{COMPANY_NAME}}</td></tr>'
                    . '<tr><td style="padding:5px 0;color:#78350f;">שם הלקוח</td><td style="padding:5px 0;text-align:end;">{{FULL_NAME}}</td></tr>'
                    . '<tr><td style="padding:5px 0;color:#78350f;">אימייל</td><td style="padding:5px 0;text-align:end;">{{USER_EMAIL}}</td></tr>'
                    . '<tr><td style="padding:5px 0;color:#78350f;">חבילה</td><td style="padding:5px 0;text-align:end;">{{PACKAGE_NAME}} / {{BILLING_CYCLE}}</td></tr>'
                    . '</table>'
                    . '</div>'
                    . '<div style="background:#eef2ff;border:1.5px solid #c7d2fe;border-radius:10px;padding:16px 20px;margin:0 0 18px;">'
                    . '<h3 style="margin:0 0 10px;font-size:15px;color:#3730a3;">פרטי הרכישה</h3>'
                    . '<table style="width:100%;border-collapse:collapse;font-size:13.5px;">'
                    . '<tr><td style="padding:5px 0;color:#3730a3;">משתמשים שנוספו</td><td style="padding:5px 0;text-align:end;font-weight:700;">+{{SEATS_ADDED}}</td></tr>'
                    . '<tr><td style="padding:5px 0;color:#3730a3;">חיוב יחסי שנגבה</td><td style="padding:5px 0;text-align:end;font-weight:600;">{{AMOUNT_PAID}}</td></tr>'
                    . '<tr><td style="padding:5px 0;color:#3730a3;">Recurring חדש (חודשי)</td><td style="padding:5px 0;text-align:end;font-weight:700;color:#4f46e5;">{{NEW_RECURRING}}</td></tr>'
                    . '<tr><td style="padding:5px 0;color:#3730a3;">iCredit RecurringId</td><td style="padding:5px 0;text-align:end;font-family:monospace;font-size:12px;">{{RECURRING_ID}}</td></tr>'
                    . '</table>'
                    . '</div>'
                    . '<h3 style="margin:0 0 10px;font-size:15px;color:#0f172a;">פעולות נדרשות</h3>'
                    . '<ol style="margin:0 0 18px;padding-inline-start:22px;font-size:14px;line-height:1.7;">'
                    . '<li>היכנס ל-iCredit עם ה-RecurringId שמופיע למעלה.</li>'
                    . '<li>עדכן את הסכום החודשי ל-{{NEW_RECURRING}}.</li>'
                    . '<li>שמור.</li>'
                    . '<li>חזור לפאנל הניהול ולחץ "Mark as updated" כדי לסמן שהעדכון בוצע.</li>'
                    . '</ol>'
                    . '<p style="margin:0 0 22px;"><a href="{{ADMIN_PANEL_URL}}" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:600;">פתח את פאנל הניהול</a></p>'
                    . '<p style="margin:0;font-size:12px;color:#94a3b8;">החיוב היחסי החד-פעמי כבר נגבה. הלקוח קיבל את המשתמשים שלו. נשאר רק לעדכן את ה-recurring העתידי ב-iCredit.</p>',
            ),
        );
    }
}
