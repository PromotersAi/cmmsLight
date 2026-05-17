<?php
/**
 * CMMS Light Help Center (1.14.22)
 *
 * In-app contextual guidance system. The goal is operational clarity —
 * users should learn the workflow ("what to do first, why this order"),
 * not button-by-button instructions.
 *
 * Architecture:
 *   - Content lives in PHP arrays here as the single source of truth.
 *   - Each article has a stable id (used as anchor/route) and is keyed
 *     to one or more pages via the `pages` array. The same article can
 *     surface from multiple pages.
 *   - Future-ready: a DB override layer (cmms_help_articles table) is
 *     planned for 1.14.23. The get_article() method is structured so
 *     that adding the override is a one-line change with no call-site
 *     impact.
 *
 * Why content-in-PHP for v1:
 *   - Ships in Git; new installs are useful immediately.
 *   - No DB write needed during onboarding.
 *   - Translatable through the existing CMMS_I18n pipeline if/when
 *     other languages are needed (currently Hebrew-only by design).
 *
 * IMPORTANT: this file is *just data plus a small renderer*. Do not put
 * business logic here. Help text is reference content; if it goes stale,
 * it should be updated in one place (this file) without touching code.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Help {

    /** Cache for the article catalog — built once per request. */
    private static $catalog = null;

    /**
     * Returns the full article catalog: id => article assoc array.
     *
     * Article shape:
     *   id          string  stable, kebab-case (e.g. "tasks-overview")
     *   title       string  short heading shown in the sidebar list
     *   summary     string  one-line elevator pitch for the article
     *   pages       string[] page identifiers this article is contextual for
     *                       (matches data-cmms-page attribute on screens)
     *   sections    array of { heading, body }, body is HTML-allowed
     *   tips        string[] optional bullet points for "Pro tips"
     *   common_mistakes string[] optional "watch out" items
     *
     * To add a new article: append a row to articles_definition() below.
     * Order in that method controls order in the help center sidebar.
     */
    public static function catalog() {
        if ( self::$catalog !== null ) return self::$catalog;
        $rows = self::articles_definition();
        // Index by id for O(1) lookup. Filter hook lets future code add or
        // override articles without editing this file.
        $by_id = array();
        foreach ( $rows as $row ) {
            if ( ! empty( $row['id'] ) ) $by_id[ $row['id'] ] = $row;
        }
        self::$catalog = (array) apply_filters( 'cmms_help_articles', $by_id );
        return self::$catalog;
    }

    /**
     * Get one article by id, or null if missing.
     *
     * (1.14.23 will add a DB-override pass here. The site admin will be
     * able to edit any article from WP Admin; if a row exists in the
     * cmms_help_articles table, it overrides the default below.)
     */
    public static function get_article( $id ) {
        $cat = self::catalog();
        return isset( $cat[ $id ] ) ? $cat[ $id ] : null;
    }

    /**
     * Get all articles tagged for a given page id (e.g. "tasks").
     * Order preserved from the definition list. Used by contextual help
     * buttons on the page.
     */
    public static function articles_for_page( $page_id ) {
        $page_id = (string) $page_id;
        $out = array();
        foreach ( self::catalog() as $art ) {
            if ( ! empty( $art['pages'] ) && in_array( $page_id, (array) $art['pages'], true ) ) {
                $out[] = $art;
            }
        }
        return $out;
    }

    /* ============================================================
       Article definitions — the actual help content.

       NOTE: text is intentionally Hebrew-only for v1. The plugin's
       primary deployment is Hebrew, and writing thin translations of
       guidance text in 3 languages produces 3 mediocre articles
       instead of 1 useful one. When real demand for English/Arabic
       help comes, we'll switch to the i18n key system.
    ============================================================ */
    private static function articles_definition() {
        return array(

            /* ---------- Setup checklist (always relevant for new accounts) ---------- */
            array(
                'id'      => 'getting-started',
                'title'   => 'איך מתחילים — סדר עבודה מומלץ',
                'summary' => 'מה לעשות קודם בחשבון חדש, ולמה.',
                'pages'   => array( 'home', 'help-center' ),
                'sections' => array(
                    array(
                        'heading' => 'הסדר מסביר את עצמו',
                        'body'    => 'המערכת מנהלת קשרים בין משימות, נכסים, ומשתמשים. אם תיצור משימה לפני שיש לך נכסים — לא תוכל לקשר אותה לנכס. אם תיצור משתמש בלי לתת לו תפקיד מתאים — הוא לא יראה כלום. לכן יש סדר נכון להקים את החשבון:',
                    ),
                    array(
                        'heading' => '1. הגדר נכסים',
                        'body'    => 'נכס = כל דבר שדורש תחזוקה: רכב, ציוד, חדר, מערכת מיזוג, מעלית. אם יש לך הרבה — היכנס ל"ייבוא נכסים" והעלה מאקסל. אחרת, בנה אחד אחד דרך "נכסים → נכס חדש".',
                    ),
                    array(
                        'heading' => '2. הוסף משתמשים',
                        'body'    => 'תפקידים: <strong>בעלים</strong> (גישה מלאה), <strong>מנהל</strong> (רואה הכל בחשבון, לא רואה הגדרות חיוב), <strong>טכנאי</strong> (רואה רק משימות שהוקצו לו), <strong>מדווח</strong> (רואה רק משימות שיצר). כל משתמש מקבל מייל הזמנה עם סיסמה זמנית.',
                    ),
                    array(
                        'heading' => '3. הפעל QR codes לנכסים',
                        'body'    => 'אם הצוות עובד בשטח — QR מאפשר להם לסרוק נכס ולראות מיד את ההיסטוריה שלו ואת המשימות הפתוחות עליו. אופציונלי. אם החשבון שלך תפעולי בעיקר מאחורי שולחן — דלג על זה.',
                    ),
                    array(
                        'heading' => '4. צור את המשימה הראשונה',
                        'body'    => 'עכשיו, כשיש נכסים ומשתמשים, אתה יכול לקשר משימות ולהקצות אותן. התחל ממשימה אחת קלה כדי לראות איך הזרימה עובדת — מי מקבל התראה, מה היא נראית, איך מסמנים שהושלם.',
                    ),
                ),
                'tips' => array(
                    'אל תייבא 5,000 נכסים מיד — נסה 10 ראשונים לוודא שהפורמט תקין.',
                    'משתמשים שלא יחוברו ל-Wordpress אצלך — נוצרים בכל מקרה. הם רק לא יוכלו להתחבר עד שתשלח להם הזמנה.',
                    'אם החשבון שלך משרת מספר אתרים פיזיים, שקול ליצור קטגוריות נכסים לפי אתר.',
                ),
                'common_mistakes' => array(
                    'יצירת משימה ללא assignee — לא תופיע לאף אחד ב"המשימות שלי".',
                    'מתן תפקיד "טכנאי" למישהו שאמור לראות הכל — הוא לא יראה משימות של אחרים.',
                ),
            ),

            /* ---------- Tasks ---------- */
            array(
                'id'      => 'tasks-overview',
                'title'   => 'משימות — ההיגיון התפעולי',
                'summary' => 'מבנה המשימה, מי רואה מה, ואיך זרימת הסטטוסים עובדת.',
                'pages'   => array( 'tasks', 'task' ),
                'sections' => array(
                    array(
                        'heading' => 'המבנה הבסיסי של משימה',
                        'body'    => 'כל משימה מקושרת ל-3 משתמשים: <strong>יוצר</strong> (מי שפתח אותה), <strong>מבצע / assignee</strong> (מי שאחראי לבצע), ו<strong>מנהל</strong> (מי שמפקח). כל אחד יכול להיות אותו אדם או אנשים שונים. למשימה יש גם נכס משויך (אופציונלי), קטגוריה, עדיפות, ותאריך יעד.',
                    ),
                    array(
                        'heading' => 'מי רואה את המשימה?',
                        'body'    => 'בעלים ומנהלים רואים את כל המשימות בחשבון. טכנאים ומדווחים רואים רק משימות שהם קשורים אליהן (assignee, manager, או creator). זה כדי שטכנאי בשטח לא יוצף בעבודה של אחרים.',
                    ),
                    array(
                        'heading' => 'זרימת הסטטוסים',
                        'body'    => '<strong>פתוח</strong> → <strong>בעבודה</strong> → <strong>הושלם</strong> או <strong>סגור</strong>. אפשר גם <strong>בהמתנה</strong> כשצריך להמתין לחומרים/אישור. הסטטוס לא רק לתצוגה — סטטוס "הושלם" מבטל אוטומטית תזכורות ובמקרה של משימות חוזרות יוצר את המופע הבא.',
                    ),
                    array(
                        'heading' => 'מי מקבל התראות?',
                        'body'    => 'בכל אירוע (יצירה, עדכון סטטוס, תגובה, איחור) מקבלים התראה: ה-assignee, המנהל, והיוצר. ההתראה מגיעה <strong>גם לפעמון בתוך האפליקציה, גם למייל, וגם כ-push</strong> אם הופעל. מי שביצע את הפעולה לא מקבל התראה על עצמו — אלא אם הוא היחיד שקשור למשימה.',
                    ),
                ),
                'tips' => array(
                    'תאריך יעד = פעיל. אם תאחר אותו, אוטומטית תיווצר התראת איחור.',
                    'תגובות הן הדרך הנכונה לתעד התקדמות. לא לערוך את הטקסט המקורי של המשימה.',
                ),
                'common_mistakes' => array(
                    'יצירת משימה ללא נכס משויך כשיש נכס רלוונטי — מאבדים את ההיסטוריה של הנכס.',
                    'מילוי "תיאור" עם פירוט מבצעי — עדיף בתגובות, כך התיאור נשאר נקי.',
                ),
            ),

            /* ---------- Bulk Task Creation ---------- */
            array(
                'id'      => 'bulk-tasks',
                'title'   => 'יצירת משימות מרובות',
                'summary' => 'איך להעלות הרבה משימות במכה אחת מאקסל או הדבקה.',
                'pages'   => array( 'tasks', 'bulk-tasks' ),
                'sections' => array(
                    array(
                        'heading' => 'מתי להשתמש',
                        'body'    => 'כשיש לך טבלה (אקסל / Sheets) של משימות שצריך להזין במכה. לדוגמה: אחרי סיור איכות שיצר רשימה של 30 ליקויים, או רשימת תחזוקה שנתית.',
                    ),
                    array(
                        'heading' => 'הסדר הנכון',
                        'body'    => 'לפני שתיצור משימות מרובות, <strong>וודא שהנכסים קיימים במערכת</strong>. המערכת תקשר משימה לנכס לפי שם, אז אם הנכס לא קיים — המשימה תיווצר בלי קישור והיא תהיה יתומה.',
                    ),
                    array(
                        'heading' => 'איך זה עובד',
                        'body'    => 'תוכל להעתיק טבלה ישירות מאקסל או Google Sheets ולהדביק. המערכת תזהה את העמודות אוטומטית. תוכל גם להעלות קובץ. אחרי הזיהוי תוצג טבלת תצוגה מקדימה — תוכל לערוך תאים בטבלה לפני שמירה.',
                    ),
                ),
                'tips' => array(
                    'תייצר תחילה 2-3 משימות בלבד כדי לוודא שהפורמט נקלט נכון, ואז את כולם.',
                    'אם המערכת לא מזהה עמודה — זה כי שם העמודה לא תואם. תייצא תבנית ריקה לראות את שמות העמודות הצפויים.',
                ),
                'common_mistakes' => array(
                    'יצירת אלפי משימות עם אותו assignee — הוא יקבל מאות התראות. שקול לחלק.',
                    'הדבקה כשעדיין לא הוחלט מה התפקיד של מי במשימות — אז תיצור 50 משימות "יתומות".',
                ),
            ),

            /* ---------- Assets ---------- */
            array(
                'id'      => 'assets-overview',
                'title'   => 'נכסים — מבנה והיסטוריה',
                'summary' => 'איך לארגן נכסים, מה זה שדות מותאמים, ולמה זה חשוב.',
                'pages'   => array( 'assets', 'asset' ),
                'sections' => array(
                    array(
                        'heading' => 'מהו נכס',
                        'body'    => 'כל פריט שדורש תחזוקה לאורך זמן: רכב, מכונה, מיזוג, מעלית, חדר. נכס מאחד תחתיו את כל המשימות שבוצעו עליו, את הקבצים, ואת ההיסטוריה — כך שאתה תמיד יכול לראות "מה עשינו עליו השנה" ב-2 לחיצות.',
                    ),
                    array(
                        'heading' => 'שדות מותאמים',
                        'body'    => 'מעבר לשדות הסטנדרטיים (שם, סוג, מיקום), אתה יכול להוסיף שדות מותאמים — מספר רישוי, תאריך אחריות, יצרן, וכו\'. אלה מופיעים בכרטיס הנכס ומחפשים בחיפוש הגלובלי.',
                    ),
                    array(
                        'heading' => 'נכס במחיקה רכה',
                        'body'    => 'מחיקת נכס לא מוחקת אותו פיזית — היא מסמנת אותו כלא פעיל. ההיסטוריה נשמרת. כך אם משימה ישנה התייחסה לנכס שעכשיו פרש, התיעוד עדיין נגיש.',
                    ),
                ),
                'tips' => array(
                    'אם יש לך הרבה נכסים מאותו סוג — תשתמש בקטגוריות נכסים, ככה תוכל לסנן ב-2 לחיצות.',
                    'מיקום פיזי נכון בנכס מקצר זמן תגובה לטכנאי בשטח.',
                ),
            ),

            /* ---------- QR codes ---------- */
            array(
                'id'      => 'qr-codes',
                'title'   => 'QR codes לנכסים — מתי וכמה',
                'summary' => 'איך לייצר ולהדביק קודי QR לסריקה בשטח.',
                'pages'   => array( 'assets', 'asset' ),
                'sections' => array(
                    array(
                        'heading' => 'למה QR',
                        'body'    => 'במקום שטכנאי יחפש את הנכס באפליקציה לפי שם, הוא סורק את ה-QR שמודבק על הנכס עצמו ומגיע ישר לעמוד שלו — רואה משימות פתוחות, מוסיף תגובה, מצרף תמונה. זה מקצר משמעותית את זמן העבודה בשטח.',
                    ),
                    array(
                        'heading' => 'מתי להפעיל גישה ציבורית',
                        'body'    => 'יש שתי אופציות. <strong>סריקה רק לעובדים שלך</strong> — מי שסורק חייב להיות מחובר. <strong>סריקה לציבור</strong> — לדוגמה לקוח שיכול לפתוח דיווח על תקלה ישירות מסריקת ה-QR. סריקה ציבורית פותחת רק טופס דיווח, לא את כל ההיסטוריה של הנכס.',
                    ),
                    array(
                        'heading' => 'הדפסה',
                        'body'    => 'כל נכס שיוצר לו QR מאפשר הורדה של תמונת PNG בגודל מודפס. אפשר להדפיס מדבקה ולהדביק על הנכס. גם להדפיס בכמות עם מדפסת תוויות סטנדרטית.',
                    ),
                ),
                'tips' => array(
                    'הדבק QR במקום שתמיד נגיש — לא מאחורי הציוד או באזור שמתלכלך.',
                    'אם הנכס בחוץ — תשתמש במדבקה עמידת מים.',
                ),
            ),

            /* ---------- Users ---------- */
            array(
                'id'      => 'users-roles',
                'title'   => 'משתמשים ותפקידים',
                'summary' => 'מה כל תפקיד יכול לעשות, ואיך לבחור נכון.',
                'pages'   => array( 'users' ),
                'sections' => array(
                    array(
                        'heading' => 'ארבעה תפקידים',
                        'body'    => '<strong>בעלים</strong> — גישה מלאה לחשבון כולל הגדרות וחיוב. <strong>מנהל</strong> — רואה הכל בחשבון, יכול לערוך משימות ונכסים, אבל לא משנה הגדרות חיוב או מוסיף משתמשים. <strong>טכנאי</strong> — רואה רק משימות שהוא assignee, יכול לעדכן סטטוס ולהוסיף תגובות וקבצים. <strong>מדווח</strong> — רואה רק משימות שהוא יצר.',
                    ),
                    array(
                        'heading' => 'איך לבחור תפקיד',
                        'body'    => 'מנהל = בעל סמכות תפעולית בחברה. טכנאי = עובד שטח שצריך לבצע משימות. מדווח = משתמש קצה (לקוח, דייר, נציג שירות) שיכול לפתוח קריאות אבל לא לראות עבודה של אחרים.',
                    ),
                    array(
                        'heading' => 'הזמנה למערכת',
                        'body'    => 'כשאתה מוסיף משתמש, נשלח לו מייל עם סיסמה זמנית. מומלץ שיחליף אותה בכניסה הראשונה. אם הכתובת כבר קיימת ב-WordPress של האתר — המערכת תקשר אותו לחשבון שלך בלי ליצור משתמש חדש.',
                    ),
                ),
                'tips' => array(
                    'אל תיתן תפקיד "בעלים" סתם — תפקיד זה רואה גם נתוני חיוב.',
                    'אם משתמש לא מקבל את מייל ההזמנה, בדוק את תיקיית הספאם.',
                ),
                'common_mistakes' => array(
                    'מתן תפקיד "מנהל" לטכנאי — הוא יראה משימות של כל החברים, וזה לרוב לא רצוי.',
                ),
            ),

            /* ---------- Notifications ---------- */
            array(
                'id'      => 'notifications-overview',
                'title'   => 'התראות — איך זה עובד',
                'summary' => 'שלושה ערוצי התראה: פעמון, מייל, push. מתי כל אחד.',
                'pages'   => array( 'settings-notify', 'home' ),
                'sections' => array(
                    array(
                        'heading' => 'שלושה ערוצים מקבילים',
                        'body'    => '<strong>פעמון בתוך האפליקציה</strong> — רואים כשמשתמשים. תמיד פעיל. <strong>מייל</strong> — מגיע לתיבה, יכולים לכבות לסוגי הודעות מסוימים. <strong>Push</strong> — התראת מערכת על המכשיר, גם כשהאפליקציה סגורה. דורש הפעלה אקטיבית בכל מכשיר.',
                    ),
                    array(
                        'heading' => 'מתי תקבל התראה',
                        'body'    => 'כשאתה assignee/manager/creator של משימה, ומישהו <em>אחר</em> מבצע פעולה: יצירה, שינוי סטטוס, תגובה, או שיוך מחדש. גם תזכורות אוטומטיות לפני יעד ולאחר איחור.',
                    ),
                    array(
                        'heading' => 'איך מפעילים push',
                        'body'    => 'בכל מכשיר בנפרד: הגדרות → התראות → כפתור "הפעל התראות". המכשיר ישאל אישור — תאשר. אחר כך תוכל ללחוץ "שלח בדיקה" כדי לוודא שעובד. <strong>iPhone</strong> דורש להוסיף את האפליקציה ל-Home Screen תחילה.',
                    ),
                ),
                'common_mistakes' => array(
                    'חיכוי שpush יעבוד בלי להפעיל אותו — push לא קופץ אוטומטית, צריך לתת הרשאה ידנית.',
                    'שכחה שיש מספר מכשירים — push פעיל רק במכשיר שאישרת בו.',
                ),
            ),

            /* ---------- Reports ---------- */
            array(
                'id'      => 'reports-overview',
                'title'   => 'דוחות — מה אפשר ללמוד',
                'summary' => 'דוחות תפעוליים שעוזרים להבין מגמות, עומסים, וצווארי בקבוק.',
                'pages'   => array( 'reports' ),
                'sections' => array(
                    array(
                        'heading' => 'מה הדוחות מראים',
                        'body'    => 'דוח <strong>סטטוסים</strong> — חתך משימות לפי מצב (פתוח/עבודה/הושלם). דוח <strong>זמני תגובה</strong> — כמה זמן עובר בין יצירה לסיום. דוח <strong>נכסים בעייתיים</strong> — איזה נכסים מייצרים הכי הרבה משימות.',
                    ),
                    array(
                        'heading' => 'איך משתמשים בדוחות',
                        'body'    => 'דוחות הם כלי תכנון, לא רק תיעוד. אם דוח זמני תגובה מראה שזמן הסגירה הממוצע גדל — זה איתות שצריך עוד טכנאי או צמצום במשימות. נכס שמופיע בראש "נכסים בעייתיים" באופן עקבי — אולי הזמן להחליף אותו.',
                    ),
                ),
                'tips' => array(
                    'בדוק דוחות פעם בחודש לפחות, לא רק כשמשהו דחוף.',
                    'תייצא לאקסל אם אתה רוצה לחתוך לפי קריטריונים שלא בנינו ב-UI.',
                ),
            ),

        );
    }

    /* ============================================================
       Rendering helpers
    ============================================================ */

    /**
     * Render a single article into HTML. Used by the dedicated help
     * center page and by the contextual help modal.
     */
    public static function render_article_html( $article ) {
        if ( ! is_array( $article ) ) return '';
        ob_start();
        ?>
        <article class="cmms-help-article" data-help-article="<?php echo esc_attr( $article['id'] ); ?>">
            <header>
                <h2><?php echo esc_html( $article['title'] ); ?></h2>
                <?php if ( ! empty( $article['summary'] ) ) : ?>
                    <p class="cmms-help-summary"><?php echo esc_html( $article['summary'] ); ?></p>
                <?php endif; ?>
            </header>

            <?php foreach ( (array) ( $article['sections'] ?? array() ) as $sec ) : ?>
                <section class="cmms-help-section">
                    <?php if ( ! empty( $sec['heading'] ) ) : ?>
                        <h3><?php echo esc_html( $sec['heading'] ); ?></h3>
                    <?php endif; ?>
                    <?php if ( ! empty( $sec['body'] ) ) : ?>
                        <?php // Body uses a small allowlist — bold/em/links/lists. ?>
                        <div class="cmms-help-body"><?php
                            echo wp_kses_post( $sec['body'] );
                        ?></div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php if ( ! empty( $article['tips'] ) ) : ?>
                <section class="cmms-help-tips">
                    <h3>טיפים</h3>
                    <ul>
                        <?php foreach ( (array) $article['tips'] as $tip ) : ?>
                            <li><?php echo esc_html( $tip ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ( ! empty( $article['common_mistakes'] ) ) : ?>
                <section class="cmms-help-mistakes">
                    <h3>טעויות נפוצות</h3>
                    <ul>
                        <?php foreach ( (array) $article['common_mistakes'] as $m ) : ?>
                            <li><?php echo esc_html( $m ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </article>
        <?php
        return ob_get_clean();
    }

    /**
     * The help button injected on every page that has contextual help.
     * Clicking opens a modal/panel that loads the relevant article(s).
     *
     * @param string $page_id  e.g. "tasks" — must match data-cmms-page on the screen
     */
    public static function render_help_button( $page_id ) {
        $articles = self::articles_for_page( $page_id );
        if ( empty( $articles ) ) return;
        ?>
        <button type="button"
                class="cmms-help-trigger"
                data-cmms-help-trigger
                data-help-page="<?php echo esc_attr( $page_id ); ?>"
                aria-label="עזרה לעמוד זה">
            <span class="cmms-help-icon" aria-hidden="true">?</span>
        </button>
        <?php
    }
}
