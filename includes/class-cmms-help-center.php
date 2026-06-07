<?php
/**
 * 1.14.88 — Help Center / FAQ chatbot content.
 *
 * Centralized FAQ content for the floating help widget. Each topic
 * has:
 *   - id: unique slug
 *   - icon: emoji shown in the button
 *   - title: short label
 *   - category: 'tasks' | 'users' | 'settings' | 'telegram' | 'reports'
 *   - required_role: optional role gate. If user lacks this, the
 *     topic still appears but the "take me there" link triggers a
 *     "no permission" message instead of navigation.
 *   - steps: array of HTML-safe strings (numbered in UI)
 *   - link: target URL inside the dashboard (e.g., '?view=tasks')
 *   - link_label: button label (default "קח אותי לשם")
 *
 * Pattern: keep all content here, not scattered. When the UI changes,
 * we update ONE file, not 20.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Help_Center {

    /**
     * Returns all FAQ topics, ordered for display.
     * Each topic is shown in the chat menu as a button.
     */
    public static function topics() {
        return array(

            // ─── TASKS (כולם) ─────────────────────────────────────
            array(
                'id'       => 'create_task',
                'icon'     => '📝',
                'title'    => 'איך יוצרים משימה?',
                'category' => 'tasks',
                'steps'    => array(
                    'לחץ על <strong>"משימות"</strong> בתפריט הראשי',
                    'לחץ על הכפתור <strong>"+ משימה חדשה"</strong> בעליון הדף',
                    'מלא את הכותרת (חובה) ותיאור (אופציונלי)',
                    'בחר עדיפות, נכס, וקטגוריה',
                    'הקצה למשתמש (טכנאי שיבצע) ומנהל אחראי',
                    'קבע תאריך יעד אם רלוונטי',
                    'לחץ <strong>"שמור"</strong>',
                ),
                'link'     => '?view=tasks',
                'tip'      => '💡 תוכל להגדיר גם משימות חוזרות (יומיות, שבועיות, חודשיות) - בחר "סוג חזרה" בעת היצירה.',
            ),

            array(
                'id'       => 'change_status',
                'icon'     => '🔄',
                'title'    => 'איך משנים סטטוס משימה?',
                'category' => 'tasks',
                'steps'    => array(
                    'פתח את המשימה',
                    'בכרטיסיית "שינוי סטטוס מהיר" - לחץ על הסטטוס הרצוי',
                    'או, לחץ על תווית הסטטוס (pill) בעליון, ובחר מתפריט',
                    'הסטטוס יתעדכן מיידית והפעולה תירשם בהיסטוריה',
                ),
                'link'     => '?view=tasks',
                'tip'      => '💡 אם אתה מקבל התראות בטלגרם - תוכל לשנות סטטוס ישירות מהבוט בלחיצה על הכפתורים.',
            ),

            array(
                'id'       => 'add_photo',
                'icon'     => '📷',
                'title'    => 'איך מוסיפים תמונה למשימה?',
                'category' => 'tasks',
                'steps'    => array(
                    '<strong>במערכת:</strong> פתח את המשימה, גלול לסקציה "קבצים מצורפים", ולחץ "העלה"',
                    '<strong>בטלגרם:</strong> לחץ ארוך על הודעת המשימה, בחר "השב" (Reply), ושלח את התמונה',
                    'התמונה תופיע בעמוד המשימה תוך שניות',
                ),
                'link'     => '?view=tasks',
                'tip'      => '💡 ניתן להעלות עד 10MB לקובץ. סוגים נתמכים: JPG, PNG, PDF, DOC, XLS.',
            ),

            array(
                'id'       => 'add_comment',
                'icon'     => '💬',
                'title'    => 'איך מוסיפים הערה למשימה?',
                'category' => 'tasks',
                'steps'    => array(
                    '<strong>במערכת:</strong> פתח את המשימה, גלול לסקציה "פעילות", הקלד הערה ולחץ "הוסף"',
                    '<strong>בטלגרם:</strong> לחץ ארוך על הודעת המשימה, בחר "השב" (Reply), והקלד את ההערה',
                    'ההערה תופיע ביומן המשימה',
                ),
                'link'     => '?view=tasks',
            ),

            array(
                'id'       => 'recurring_tasks',
                'icon'     => '🔁',
                'title'    => 'איך יוצרים משימות חוזרות?',
                'category' => 'tasks',
                'steps'    => array(
                    'בעת יצירת משימה חדשה, בחר "סוג חזרה" (יומי / שבועי / חודשי / שנתי)',
                    'הגדר את <strong>תאריך היעד</strong> - זה יהיה גם תאריך החזרה הראשונה',
                    'הגדר <strong>"חזרה עד"</strong> - תאריך סיום החזרות',
                    'המערכת תיצור אוטומטית עותקים חדשים של המשימה',
                ),
                'link'     => '?view=tasks',
                'tip'      => '💡 משימה חוזרת חייבת תאריך יעד ותאריך סיום - אחרת היא תיווצר לאינסוף.',
            ),

            array(
                'id'       => 'delete_task',
                'icon'     => '🗑',
                'title'    => 'איך מוחקים משימה?',
                'category' => 'tasks',
                'steps'    => array(
                    'פתח את המשימה שברצונך למחוק',
                    'לחץ על כפתור "מחק" בעליון העמוד',
                    'אשר את המחיקה',
                    'המשימה תועבר לארכיון - היא לא תופיע ברשימות אך תישמר להיסטוריה',
                ),
                'link'     => '?view=tasks',
                'tip'      => '💡 רק בעלים ומנהלים יכולים למחוק כל משימה. טכנאים יכולים למחוק רק משימות שהם יצרו.',
            ),

            // ─── ASSETS (נכסים) - לכולם ────────────────────────────
            array(
                'id'       => 'view_assets',
                'icon'     => '📦',
                'title'    => 'מה זה נכסים ולמה צריך אותם?',
                'category' => 'assets',
                'steps'    => array(
                    '<strong>נכס</strong> = כל פריט במערכת שצריך תחזוקה (מזגן, מכונה, רכב, מבנה...)',
                    'כל משימה יכולה להיות מקושרת לנכס',
                    'תוכל לראות את כל המשימות שבוצעו על נכס מסוים',
                    'זה עוזר לנהל היסטוריית תחזוקה ולתכנן עבודה',
                ),
                'link'     => '?view=assets',
                'tip'      => '💡 כדאי להקצות לכל נכס QR code - תוכל להדפיס ולהדביק על הציוד, וטכנאי יכול לסרוק כדי לראות היסטוריה.',
            ),

            array(
                'id'       => 'add_asset',
                'icon'     => '➕',
                'title'    => 'איך מוסיפים נכס חדש?',
                'category' => 'assets',
                'required_role' => array( 'owner', 'manager' ),
                'steps'    => array(
                    'לחץ על <strong>"נכסים"</strong> בתפריט',
                    'לחץ על <strong>"+ נכס חדש"</strong>',
                    'מלא שם, מיקום, ופרטים נוספים',
                    'תוכל להעלות תמונה של הנכס',
                    'שמור - הנכס יהיה זמין לבחירה במשימות',
                ),
                'link'     => '?view=assets',
            ),

            array(
                'id'       => 'asset_qr',
                'icon'     => '🔲',
                'title'    => 'איך מדפיסים QR לנכס?',
                'category' => 'assets',
                'steps'    => array(
                    'כנס לעמוד הנכס',
                    'לחץ על "הדפס QR" / "QR Code"',
                    'יפתח חלון הדפסה עם ה-QR מוכן',
                    'הדפס והדבק על הציוד',
                    'סריקה תכוון ישירות לעמוד הנכס',
                ),
                'link'     => '?view=assets',
                'tip'      => '💡 שימושי במיוחד בחברות עם הרבה ציוד - הטכנאי סורק QR ורואה היסטוריית תחזוקה.',
            ),

            // ─── FORMS (טפסים) ────────────────────────────────────
            array(
                'id'       => 'forms_overview',
                'icon'     => '📋',
                'title'    => 'מה זה טפסים ואיך משתמשים?',
                'category' => 'forms',
                'steps'    => array(
                    '<strong>טופס</strong> = רשימת שדות שטכנאי ממלא בעת סיום משימה',
                    'דוגמה: "טופס בדיקה חודשית" עם שדות כמו לחץ, טמפ׳, מצב כללי...',
                    'יוצרים טופס פעם אחת, ומקשרים אותו למשימות חוזרות',
                    'הטכנאי יקבל את הטופס למילוי, והנתונים נשמרים במערכת',
                ),
                'link'     => '?view=forms',
                'tip'      => '💡 שימושי לבדיקות תקופתיות, רשימות תיוג, וכל פעולה שדורשת מילוי קבוע.',
            ),

            array(
                'id'       => 'create_form',
                'icon'     => '✏️',
                'title'    => 'איך יוצרים טופס חדש?',
                'category' => 'forms',
                'required_role' => array( 'owner', 'manager' ),
                'steps'    => array(
                    'לחץ על <strong>"טפסים"</strong> בתפריט',
                    'לחץ על <strong>"+ טופס חדש"</strong>',
                    'הכנס שם לטופס (לדוגמה: "בדיקה חודשית למזגן")',
                    'הוסף שדות: טקסט, מספר, בחירה, כן/לא...',
                    'שמור - הטופס מוכן לקישור למשימות',
                ),
                'link'     => '?view=forms',
            ),

            // ─── CATEGORIES & SETTINGS ─────────────────────────
            array(
                'id'       => 'manage_categories',
                'icon'     => '🏷️',
                'title'    => 'איך מנהלים קטגוריות?',
                'category' => 'settings',
                'required_role' => array( 'owner', 'manager' ),
                'steps'    => array(
                    'לחץ על <strong>"הגדרות"</strong> בתפריט',
                    'בחר טאב <strong>"קטגוריות"</strong>',
                    'תוכל לערוך שם של קטגוריה, להסתיר/להציג, או למחוק',
                    'להוספת חדשה - לחץ "+ קטגוריה חדשה"',
                ),
                'link'     => '?view=settings&tab=categories',
                'tip'      => '💡 קטגוריות מוסתרות לא יופיעו בעת יצירת משימה חדשה, אבל הן עדיין יישמרו במשימות הקיימות.',
            ),

            // ─── USERS (מנהלים + בעלים) ────────────────────────────
            array(
                'id'       => 'add_user',
                'icon'     => '👥',
                'title'    => 'איך מוסיפים משתמש חדש?',
                'category' => 'users',
                'required_role' => array( 'owner', 'manager' ),
                'steps'    => array(
                    'לחץ על <strong>"משתמשים"</strong> בתפריט',
                    'לחץ על <strong>"+ הוסף משתמש"</strong>',
                    'מלא שם, אימייל, וטלפון',
                    'בחר תפקיד: בעלים / מנהל / טכנאי / מדווח',
                    'לחץ "שמור" - המשתמש יקבל אימייל הזמנה',
                ),
                'link'     => '?view=users',
                'tip'      => '💡 שים לב: כל משתמש חדש סופר במכסת המנוי שלך. תוכל לראות את המכסה הנוכחית בעמוד "תשלום".',
            ),

            array(
                'id'       => 'user_roles',
                'icon'     => '🔑',
                'title'    => 'מה ההבדל בין התפקידים?',
                'category' => 'users',
                'steps'    => array(
                    '<strong>בעלים:</strong> שליטה מלאה - יכול לנהל מנויים, משתמשים, והגדרות תשלום',
                    '<strong>מנהל:</strong> ניהול משתמשים, קטגוריות, הגדרות, וכל המשימות',
                    '<strong>טכנאי:</strong> רואה ומבצע משימות שמוקצות לו, יכול לסמן סטטוס, להעלות תמונות',
                    '<strong>מדווח:</strong> יכול לפתוח משימות חדשות אבל לא לבצע',
                ),
                'tip'      => '💡 בכל חשבון חייב להיות בעלים אחד לפחות.',
            ),

            // ─── BULK / IMPORT (ייבוא ויצירה מרובה) ─────────────────
            array(
                'id'       => 'bulk_tasks',
                'icon'     => '📥',
                'title'    => 'איך יוצרים משימות במכה אחת?',
                'category' => 'bulk',
                'required_role' => array( 'owner', 'manager' ),
                'steps'    => array(
                    'לחץ על <strong>"משימות"</strong> בתפריט',
                    'לחץ על <strong>"יצירה מרובה"</strong>',
                    'תוכל ליצור עד 50 משימות בבת אחת',
                    'מלא את הפרטים בטבלה',
                    'לחץ "שמור הכל" - כל המשימות ייווצרו יחד',
                ),
                'link'     => '?view=bulk_tasks',
                'tip'      => '💡 חוסך שעות של עבודה כשצריך להגדיר תחזוקה תקופתית להמון פריטי ציוד.',
            ),

            array(
                'id'       => 'import_assets',
                'icon'     => '📊',
                'title'    => 'איך מייבאים נכסים מקובץ Excel?',
                'category' => 'bulk',
                'required_role' => array( 'owner', 'manager' ),
                'steps'    => array(
                    'לחץ על <strong>"ייבוא"</strong> בתפריט',
                    'הורד את <strong>תבנית הקובץ</strong> (Excel)',
                    'פתח את הקובץ ומלא את הנכסים שלך',
                    'עמודות נדרשות: שם, מיקום, סוג, תיאור',
                    'שמור את הקובץ והעלה למערכת',
                    'המערכת תעבד את הקובץ ותציג סיכום',
                ),
                'link'     => '?view=import',
                'tip'      => '💡 דרך הכי מהירה להוסיף 50, 100, או אלפי נכסים בבת אחת. אידיאלי לחברות שמעבירות נתונים ממערכת אחרת.',
            ),

            array(
                'id'       => 'import_tasks',
                'icon'     => '📋',
                'title'    => 'איך מייבאים משימות מקובץ Excel?',
                'category' => 'bulk',
                'required_role' => array( 'owner', 'manager' ),
                'steps'    => array(
                    'לחץ על <strong>"ייבוא"</strong> בתפריט',
                    'בחר טאב <strong>"משימות"</strong>',
                    'הורד את תבנית קובץ המשימות',
                    'מלא את הקובץ עם המשימות שלך',
                    'עמודות נדרשות: כותרת, תיאור, נכס, עדיפות, יעד',
                    'העלה את הקובץ - המערכת תעבד',
                ),
                'link'     => '?view=import',
                'tip'      => '💡 אם הקובץ מכיל שגיאות, המערכת תציג רשימה של מה לתקן לפני יצירה.',
            ),

            // ─── TELEGRAM ─────────────────────────────────────────
            array(
                'id'       => 'connect_telegram',
                'icon'     => '📱',
                'title'    => 'איך מחברים את חשבוני לטלגרם?',
                'category' => 'telegram',
                'steps'    => array(
                    'לחץ על <strong>"הגדרות"</strong> בתפריט',
                    'בחר טאב <strong>"טלגרם"</strong>',
                    'לחץ "צור קוד חיבור"',
                    'תקבל קישור - לחץ עליו ויפתח את הבוט',
                    'בטלגרם, לחץ "Start" - והחשבון יחובר אוטומטית',
                ),
                'link'     => '?view=settings&tab=telegram',
                'tip'      => '💡 לאחר חיבור: תקבל התראות על משימות שמוקצות לך, ותוכל לעדכן סטטוס, להעלות תמונות, ולהוסיף הערות - הכל מטלגרם.',
            ),

            array(
                'id'       => 'telegram_actions',
                'icon'     => '⚙️',
                'title'    => 'מה אפשר לעשות מטלגרם?',
                'category' => 'telegram',
                'steps'    => array(
                    '<strong>קבלת התראות:</strong> על משימות חדשות שמוקצות אליך',
                    '<strong>שינוי סטטוס:</strong> כפתורים בכל הודעת משימה',
                    '<strong>העלאת תמונות:</strong> Reply להודעת משימה + שלח תמונה',
                    '<strong>הוספת הערות:</strong> Reply להודעת משימה + הקלד טקסט',
                    '<strong>רשימת משימות:</strong> שלח /mytasks',
                    '<strong>שיתוף מיקום:</strong> אם הופעל בהגדרות, מיקום ייתבקש בסגירת משימה',
                ),
                'tip'      => '💡 הטכנאים שלך לא חייבים להיכנס למערכת בכלל - הכל עובד מטלגרם!',
            ),

            // ─── SETTINGS ─────────────────────────────────────────
            array(
                'id'       => 'gps_settings',
                'icon'     => '📍',
                'title'    => 'איך מפעילים מיקום (GPS)?',
                'category' => 'settings',
                'required_role' => array( 'owner', 'manager' ),
                'steps'    => array(
                    'לחץ על <strong>"הגדרות"</strong> בתפריט',
                    'בחר טאב <strong>"אירגון"</strong>',
                    'גלול ל-"הגדרות משימות"',
                    'סמן ☑ <strong>"בקש מיקום (GPS) בסגירת משימה"</strong>',
                    'שמור',
                ),
                'link'     => '?view=settings&tab=org',
                'tip'      => '💡 מומלץ לחברות שירות שטח שצריכות הוכחה שהטכנאי באמת היה באתר. עובד גם בדפדפן וגם בטלגרם.',
            ),

            array(
                'id'       => 'notifications',
                'icon'     => '🔔',
                'title'    => 'איך מפעילים התראות בטלפון?',
                'category' => 'settings',
                'steps'    => array(
                    'במערכת, לחץ על <strong>"הגדרות"</strong>',
                    'בחר טאב <strong>"התראות ותזכורות"</strong>',
                    'גלול לסקציה "התראות דחיפה"',
                    'לחץ <strong>"הפעל התראות"</strong>',
                    'הדפדפן יבקש הרשאה - אשר',
                    'תוכל ללחוץ "שלח בדיקה" כדי לוודא',
                ),
                'link'     => '?view=settings&tab=notifications',
                'tip'      => '💡 לחוויה הכי טובה - התקן את האפליקציה (PWA) על המסך הראשי של הטלפון שלך.',
            ),

            // ─── REPORTS ──────────────────────────────────────────
            array(
                'id'       => 'view_calendar',
                'icon'     => '📅',
                'title'    => 'איך משתמשים ביומן?',
                'category' => 'reports',
                'steps'    => array(
                    'לחץ על <strong>"יומן"</strong> בתפריט',
                    'תראה תצוגה חודשית של כל המשימות לפי תאריך יעד',
                    'נווט בין חודשים עם החיצים בראש',
                    'לחץ על משימה - תועבר לעמוד הפרטים',
                    'לחץ על יום ריק - תפתח טופס יצירת משימה לאותו יום',
                ),
                'link'     => '?view=calendar',
                'tip'      => '💡 היומן בעיצוב כמו Apple Calendar - נקי וקל לקריאה במבט אחד.',
            ),

            array(
                'id'       => 'view_kanban',
                'icon'     => '📊',
                'title'    => 'איפה רואים את לוח Kanban?',
                'category' => 'reports',
                'steps'    => array(
                    'לחץ על <strong>"משימות"</strong> בתפריט',
                    'בעליון הדף, לחץ על אייקון <strong>Kanban</strong>',
                    'תראה משימות מסודרות לפי סטטוס',
                    'גרור משימה בין עמודות לשינוי סטטוס',
                ),
                'link'     => '?view=tasks&display=kanban',
            ),

            // ─── BILLING ─────────────────────────────────────────
            array(
                'id'       => 'billing',
                'icon'     => '💳',
                'title'    => 'איך אני מנהל את התשלום?',
                'category' => 'billing',
                'required_role' => array( 'owner' ),
                'steps'    => array(
                    'לחץ על <strong>"תשלום"</strong> בתפריט',
                    'תראה את המנוי הנוכחי, מספר משתמשים, וסטטוס',
                    'תוכל לשדרג חבילה, להוסיף משתמשים, או לבטל',
                ),
                'link'     => '?view=billing',
                'tip'      => '💡 רק בעלים יכול לראות ולשנות את הגדרות התשלום.',
            ),
        );
    }

    /**
     * Returns categories for the chat menu, in display order.
     */
    public static function categories() {
        return array(
            'tasks'    => array( 'icon' => '📋', 'label' => 'משימות' ),
            'assets'   => array( 'icon' => '📦', 'label' => 'נכסים' ),
            'forms'    => array( 'icon' => '✏️', 'label' => 'טפסים' ),
            'telegram' => array( 'icon' => '📱', 'label' => 'טלגרם' ),
            'users'    => array( 'icon' => '👥', 'label' => 'משתמשים' ),
            'bulk'     => array( 'icon' => '📥', 'label' => 'ייבוא ויצירה מרובה' ),
            'settings' => array( 'icon' => '⚙️', 'label' => 'הגדרות' ),
            'reports'  => array( 'icon' => '📊', 'label' => 'דוחות ותצוגות' ),
            'billing'  => array( 'icon' => '💳', 'label' => 'תשלום' ),
        );
    }

    /**
     * Look up a topic by id. Returns null if not found.
     */
    public static function get_topic( $id ) {
        foreach ( self::topics() as $t ) {
            if ( $t['id'] === $id ) return $t;
        }
        return null;
    }

    /**
     * Check whether a user can access the action described by the
     * topic. If the topic has no required_role, anyone can navigate
     * to it. Otherwise the user's role must be in the list.
     */
    public static function user_can_access( $topic, $user_role ) {
        if ( empty( $topic['required_role'] ) ) return true;
        return in_array( $user_role, $topic['required_role'], true );
    }
}
