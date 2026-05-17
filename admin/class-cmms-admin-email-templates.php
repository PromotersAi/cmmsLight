<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CMMS_Admin_EmailTemplates (1.14.59)
 *
 * Super-admin UI for managing email templates. Two views:
 *
 *   1. LIST     — shows all known template keys with their status
 *                 (default / customized) and a "Edit" button each.
 *   2. EDIT     — subject input, custom rich-text body editor,
 *                 variable chips, preview pane, send-test button,
 *                 reset-to-default button.
 *
 * The editor is a custom contenteditable-based component:
 *   - No external libraries (no TinyMCE, no CKEditor)
 *   - Toolbar: bold, italic, underline, link, H2, paragraph, color,
 *     CTA button insert, variable insert
 *   - Renders inline styles only (email-safe)
 *   - RTL by default
 *
 * All actions are POST + nonce-protected. Capability gate: manage_options.
 */
class CMMS_Admin_EmailTemplates {

    const SLUG = 'cmms-email-templates';

    /** Dispatcher — called by add_submenu_page. */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        // Handle POST (save / reset / send test) before rendering.
        self::handle_post();

        $action = isset( $_GET['cmms_action'] ) ? sanitize_key( wp_unslash( $_GET['cmms_action'] ) ) : '';
        $key    = isset( $_GET['key'] )         ? sanitize_key( wp_unslash( $_GET['key'] ) )         : '';

        if ( $action === 'edit' && $key ) {
            self::render_edit( $key );
        } else {
            self::render_list();
        }
    }

    /** Handle save/reset/test from forms. */
    private static function handle_post() {
        if ( empty( $_POST['cmms_email_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $action = sanitize_key( wp_unslash( $_POST['cmms_email_action'] ) );
        $key    = isset( $_POST['template_key'] ) ? sanitize_key( wp_unslash( $_POST['template_key'] ) ) : '';
        $nonce  = $_POST['_wpnonce'] ?? '';

        if ( ! wp_verify_nonce( $nonce, 'cmms_email_template_' . $key ) ) {
            wp_die( 'Bad nonce.' );
        }

        // Save: validate subject + body, then persist.
        if ( $action === 'save' ) {
            $subject = isset( $_POST['subject'] )   ? wp_unslash( $_POST['subject'] )   : '';
            $body    = isset( $_POST['body_html'] ) ? wp_unslash( $_POST['body_html'] ) : '';

            $subject = trim( wp_strip_all_tags( $subject ) );
            // Allow only a safe subset of HTML in body. Permitted tags
            // include layout (p/h2/h3/strong/em/a/br/ul/li/img/span/div)
            // and common inline attributes for email-safe styling.
            $allowed = wp_kses_allowed_html( 'post' );
            // Tighten: allow style attr (essential for email HTML).
            foreach ( $allowed as $tag => &$attrs ) {
                $attrs['style'] = true;
            }
            $allowed['a']['href']   = true;
            $allowed['a']['target'] = true;
            $allowed['a']['rel']    = true;
            $body = wp_kses( $body, $allowed );

            if ( $subject === '' || $body === '' ) {
                wp_safe_redirect( add_query_arg(
                    array( 'page' => self::SLUG, 'cmms_action' => 'edit', 'key' => $key, 'cmms_err' => 'empty' ),
                    admin_url( 'admin.php' )
                ) );
                exit;
            }

            CMMS_EmailTemplates::save( $key, $subject, $body, get_current_user_id() );
            wp_safe_redirect( add_query_arg(
                array( 'page' => self::SLUG, 'cmms_action' => 'edit', 'key' => $key, 'cmms_msg' => 'saved' ),
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        // Reset: delete the customization row so default kicks back in.
        if ( $action === 'reset' ) {
            CMMS_EmailTemplates::reset( $key );
            wp_safe_redirect( add_query_arg(
                array( 'page' => self::SLUG, 'cmms_action' => 'edit', 'key' => $key, 'cmms_msg' => 'reset' ),
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        // Test send: substitute sample data and send to test email.
        if ( $action === 'test_send' ) {
            $test_email = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
            if ( ! is_email( $test_email ) ) {
                wp_safe_redirect( add_query_arg(
                    array( 'page' => self::SLUG, 'cmms_action' => 'edit', 'key' => $key, 'cmms_err' => 'bad_email' ),
                    admin_url( 'admin.php' )
                ) );
                exit;
            }
            $sample = self::sample_vars();
            $sent = CMMS_Mailer::send_templated( $key, $test_email, $sample );
            wp_safe_redirect( add_query_arg(
                array(
                    'page'        => self::SLUG,
                    'cmms_action' => 'edit',
                    'key'         => $key,
                    'cmms_msg'    => $sent ? 'test_sent' : 'test_failed',
                ),
                admin_url( 'admin.php' )
            ) );
            exit;
        }
    }

    /** Sample variable values for preview / test sending. */
    private static function sample_vars() {
        return array(
            'FULL_NAME'         => 'ישראל ישראלי',
            'COMPANY_NAME'      => 'חברה לדוגמה בעמ',
            'PACKAGE_NAME'      => 'Business',
            'BILLING_CYCLE'     => 'חודשי',
            'NEXT_PAYMENT_DATE' => date( 'd/m/Y', strtotime( '+1 month' ) ),
            'USER_EMAIL'        => 'demo@example.com',
            'DASHBOARD_URL'     => home_url( '/cmms-dashboard/' ),
            'SUPPORT_EMAIL'     => 'guy@promoters.co.il',
            'SITE_URL'          => home_url( '/' ),
        );
    }

    /** List view: all templates with status. */
    private static function render_list() {
        $known = CMMS_EmailTemplates::known_templates();
        global $wpdb;
        $t = CMMS_DB::table( 'email_templates' );
        $customized_keys = $t
            ? array_flip( (array) $wpdb->get_col( "SELECT template_key FROM $t" ) )
            : array();
        ?>
        <div class="wrap" dir="rtl">
            <h1 style="margin-bottom: 8px;">תבניות אימייל</h1>
            <p style="color:#64748b;margin:0 0 24px;">ערוך את התוכן והעיצוב של המיילים האוטומטיים שהמערכת שולחת ללקוחות. אם תבנית לא נערכה — נשלחת ברירת המחדל המובנית.</p>

            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th>שם התבנית</th>
                        <th>תיאור</th>
                        <th>סטטוס</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $known as $key => $meta ) :
                        $is_custom = isset( $customized_keys[ $key ] );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $meta['label'] ); ?></strong><br><code style="font-size:11px;color:#94a3b8;"><?php echo esc_html( $key ); ?></code></td>
                        <td><?php echo esc_html( $meta['description'] ); ?></td>
                        <td>
                            <?php if ( $is_custom ) : ?>
                                <span style="display:inline-block;padding:3px 10px;background:#dbeafe;color:#1d4ed8;border-radius:12px;font-size:12px;">מותאם אישית</span>
                            <?php else : ?>
                                <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;color:#64748b;border-radius:12px;font-size:12px;">ברירת מחדל</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'cmms_action' => 'edit', 'key' => $key ), admin_url( 'admin.php' ) ) ); ?>"
                               class="button button-primary">ערוך</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** Edit view: subject + body editor + variables + preview + test. */
    private static function render_edit( $key ) {
        $known = CMMS_EmailTemplates::known_templates();
        if ( ! isset( $known[ $key ] ) ) {
            wp_die( 'Unknown template key.' );
        }
        $meta = $known[ $key ];
        $tpl  = CMMS_EmailTemplates::get( $key );
        $vars = CMMS_EmailTemplates::available_variables();
        $nonce = wp_create_nonce( 'cmms_email_template_' . $key );

        $msg = isset( $_GET['cmms_msg'] ) ? sanitize_key( wp_unslash( $_GET['cmms_msg'] ) ) : '';
        $err = isset( $_GET['cmms_err'] ) ? sanitize_key( wp_unslash( $_GET['cmms_err'] ) ) : '';
        $list_url = admin_url( 'admin.php?page=' . self::SLUG );
        ?>
        <div class="wrap" dir="rtl">
            <p style="margin:0 0 8px;"><a href="<?php echo esc_url( $list_url ); ?>" style="text-decoration:none;">← חזרה לרשימה</a></p>
            <h1 style="margin-bottom:4px;"><?php echo esc_html( $meta['label'] ); ?></h1>
            <p style="color:#64748b;margin:0 0 18px;"><?php echo esc_html( $meta['description'] ); ?></p>

            <?php if ( $msg === 'saved' ) : ?>
                <div class="notice notice-success"><p>התבנית נשמרה.</p></div>
            <?php elseif ( $msg === 'reset' ) : ?>
                <div class="notice notice-success"><p>התבנית אופסה לברירת המחדל.</p></div>
            <?php elseif ( $msg === 'test_sent' ) : ?>
                <div class="notice notice-success"><p>נשלח אימייל לבדיקה.</p></div>
            <?php elseif ( $msg === 'test_failed' ) : ?>
                <div class="notice notice-error"><p>שליחת אימייל הבדיקה נכשלה. בדוק את הגדרות SMTP.</p></div>
            <?php endif; ?>
            <?php if ( $err === 'empty' ) : ?>
                <div class="notice notice-error"><p>הנושא והגוף לא יכולים להיות ריקים.</p></div>
            <?php elseif ( $err === 'bad_email' ) : ?>
                <div class="notice notice-error"><p>כתובת האימייל לבדיקה אינה תקינה.</p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 280px;gap:24px;align-items:start;">
                <!-- LEFT COLUMN: editor -->
                <div>
                    <form method="post" id="cmms-tpl-form">
                        <input type="hidden" name="cmms_email_action" value="save">
                        <input type="hidden" name="template_key" value="<?php echo esc_attr( $key ); ?>">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">

                        <p>
                            <label for="cmms-tpl-subject"><strong>נושא האימייל</strong></label>
                            <input type="text" id="cmms-tpl-subject" name="subject" class="regular-text"
                                   style="width:100%;font-family:Arial,sans-serif;direction:rtl;"
                                   value="<?php echo esc_attr( $tpl['subject'] ); ?>">
                        </p>

                        <p style="margin-top:18px;"><strong>גוף האימייל</strong></p>
                        <?php self::render_editor_toolbar(); ?>
                        <div id="cmms-tpl-editor"
                             contenteditable="true"
                             dir="rtl"
                             style="min-height:360px;background:#fff;border:1px solid #d4d4d8;border-top:0;border-radius:0 0 6px 6px;padding:20px;font-family:Arial,sans-serif;font-size:15px;line-height:1.7;color:#0f172a;outline:none;"
                        ><?php echo $tpl['body_html']; // intentionally not escaped — this is the rendered HTML editor content ?></div>
                        <input type="hidden" name="body_html" id="cmms-tpl-body-hidden">

                        <p style="margin-top:24px;">
                            <button type="submit" class="button button-primary button-large" id="cmms-tpl-save">
                                שמור שינויים
                            </button>
                            <?php if ( ! empty( $tpl['customized'] ) ) : ?>
                                <button type="button" class="button" id="cmms-tpl-reset-btn"
                                        style="margin-right:8px;color:#dc2626;">
                                    אפס לברירת מחדל
                                </button>
                            <?php endif; ?>
                            <button type="button" class="button" id="cmms-tpl-preview-btn"
                                    style="margin-right:8px;">
                                תצוגה מקדימה
                            </button>
                        </p>
                    </form>

                    <?php if ( ! empty( $tpl['customized'] ) ) : ?>
                    <!-- Hidden reset form -->
                    <form method="post" id="cmms-tpl-reset-form" style="display:none;">
                        <input type="hidden" name="cmms_email_action" value="reset">
                        <input type="hidden" name="template_key" value="<?php echo esc_attr( $key ); ?>">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                    </form>
                    <?php endif; ?>

                    <hr style="margin:32px 0 24px;">

                    <h3 style="margin:0 0 8px;">שלח אימייל לבדיקה</h3>
                    <p style="color:#64748b;margin:0 0 14px;font-size:13px;">השליחה תשתמש בערכי דוגמה במשתנים. למשל [FULL_NAME] יוחלף ל"ישראל ישראלי".</p>
                    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;max-width:500px;">
                        <input type="hidden" name="cmms_email_action" value="test_send">
                        <input type="hidden" name="template_key" value="<?php echo esc_attr( $key ); ?>">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                        <input type="email" name="test_email" placeholder="your@email.com"
                               value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
                               required style="flex:1;min-width:220px;">
                        <button type="submit" class="button">שלח</button>
                    </form>
                </div>

                <!-- RIGHT COLUMN: variables & info -->
                <div>
                    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
                        <h3 style="margin:0 0 4px;font-size:14px;">משתנים זמינים</h3>
                        <p style="margin:0 0 12px;font-size:12px;color:#64748b;">לחץ על משתנה כדי להעתיק אותו, או הקלד אותו ידנית עם סוגריים מסולסלים: <code>{{TOKEN}}</code></p>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <?php foreach ( $vars as $token => $desc ) : ?>
                                <button type="button" class="cmms-tpl-var-chip"
                                        data-token="{{<?php echo esc_attr( $token ); ?>}}"
                                        title="<?php echo esc_attr( $desc ); ?>"
                                        style="text-align:right;cursor:pointer;border:1px solid #e2e8f0;background:#f8fafc;border-radius:6px;padding:8px 10px;font-family:monospace;font-size:12px;direction:ltr;">
                                    <strong style="color:#ff6a00;">[<?php echo esc_html( $token ); ?>]</strong>
                                    <span style="display:block;color:#64748b;font-family:Arial;font-size:11px;direction:rtl;margin-top:2px;"><?php echo esc_html( $desc ); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview modal -->
        <div id="cmms-tpl-preview-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;align-items:center;justify-content:center;padding:40px;">
            <div style="background:#f8fafc;border-radius:12px;max-width:720px;width:100%;max-height:90vh;overflow:auto;position:relative;">
                <button type="button" id="cmms-tpl-preview-close" style="position:absolute;top:8px;left:8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;width:32px;height:32px;cursor:pointer;font-size:18px;">×</button>
                <iframe id="cmms-tpl-preview-frame" style="width:100%;height:80vh;border:0;border-radius:12px;background:#f8fafc;"></iframe>
            </div>
        </div>

        <?php self::render_editor_js( $key ); ?>
        <?php
    }

    /** Toolbar HTML — small custom strip above the contenteditable div. */
    private static function render_editor_toolbar() {
        ?>
        <div style="background:#f1f5f9;border:1px solid #d4d4d8;border-radius:6px 6px 0 0;padding:8px;display:flex;gap:4px;flex-wrap:wrap;direction:ltr;">
            <button type="button" data-cmd="bold" class="cmms-tpl-btn" title="Bold (Ctrl+B)" style="width:34px;height:30px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;font-weight:700;cursor:pointer;"><b>B</b></button>
            <button type="button" data-cmd="italic" class="cmms-tpl-btn" title="Italic (Ctrl+I)" style="width:34px;height:30px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;font-style:italic;cursor:pointer;"><i>I</i></button>
            <button type="button" data-cmd="underline" class="cmms-tpl-btn" title="Underline" style="width:34px;height:30px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;text-decoration:underline;cursor:pointer;">U</button>
            <span style="width:1px;background:#cbd5e1;margin:0 4px;"></span>
            <button type="button" data-cmd="formatBlock" data-arg="h2" class="cmms-tpl-btn" title="Heading" style="height:30px;padding:0 8px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;cursor:pointer;font-weight:700;">H2</button>
            <button type="button" data-cmd="formatBlock" data-arg="p" class="cmms-tpl-btn" title="Paragraph" style="height:30px;padding:0 8px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;cursor:pointer;">P</button>
            <span style="width:1px;background:#cbd5e1;margin:0 4px;"></span>
            <button type="button" data-cmd="insertUnorderedList" class="cmms-tpl-btn" title="Bullet list" style="height:30px;padding:0 8px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;cursor:pointer;">• List</button>
            <button type="button" data-cmd="cmms-link" class="cmms-tpl-btn" title="Link" style="height:30px;padding:0 8px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;cursor:pointer;">🔗 Link</button>
            <button type="button" data-cmd="cmms-cta" class="cmms-tpl-btn" title="Insert CTA button" style="height:30px;padding:0 10px;border:1px solid #cbd5e1;background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);color:#fff;border-radius:4px;cursor:pointer;font-weight:600;">⬛ CTA Button</button>
            <span style="width:1px;background:#cbd5e1;margin:0 4px;"></span>
            <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#475569;">
                Color:
                <input type="color" data-cmd="foreColor" class="cmms-tpl-color" value="#0f172a" style="width:34px;height:30px;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;background:#fff;padding:0;">
            </label>
        </div>
        <?php
    }

    /** Editor JS — contenteditable wiring + commands. */
    private static function render_editor_js( $key ) {
        ?>
        <script>
        (function () {
            var editor = document.getElementById('cmms-tpl-editor');
            var hidden = document.getElementById('cmms-tpl-body-hidden');
            var form   = document.getElementById('cmms-tpl-form');
            if (!editor || !hidden || !form) return;

            // Sync editor → hidden field on submit.
            form.addEventListener('submit', function () {
                hidden.value = editor.innerHTML;
            });

            // Toolbar buttons.
            document.querySelectorAll('.cmms-tpl-btn').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var cmd = btn.dataset.cmd;
                    var arg = btn.dataset.arg || null;
                    editor.focus();

                    if (cmd === 'cmms-link') {
                        var url = prompt('הזן כתובת URL:', 'https://');
                        if (url) document.execCommand('createLink', false, url);
                        return;
                    }
                    if (cmd === 'cmms-cta') {
                        var ctaUrl  = prompt('כתובת הקישור של הכפתור:', '{{DASHBOARD_URL}}');
                        if (ctaUrl === null) return;
                        var ctaText = prompt('טקסט הכפתור:', 'כניסה למערכת');
                        if (ctaText === null) return;
                        var ctaHtml = '<p style="margin:18px 0;"><a href="' + ctaUrl + '" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:600;">' + ctaText + '</a></p>';
                        document.execCommand('insertHTML', false, ctaHtml);
                        return;
                    }
                    document.execCommand(cmd, false, arg);
                });
            });

            // Color input.
            var colorInput = document.querySelector('.cmms-tpl-color');
            if (colorInput) {
                colorInput.addEventListener('input', function () {
                    editor.focus();
                    document.execCommand('foreColor', false, colorInput.value);
                });
            }

            // Variable chips — insert {{TOKEN}} at cursor.
            document.querySelectorAll('.cmms-tpl-var-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    var token = chip.dataset.token;
                    editor.focus();
                    document.execCommand('insertText', false, token);
                });
            });

            // Reset confirm.
            var resetBtn = document.getElementById('cmms-tpl-reset-btn');
            if (resetBtn) {
                resetBtn.addEventListener('click', function () {
                    if (confirm('האם לאפס את התבנית לברירת המחדל? כל השינויים שלך יאבדו.')) {
                        document.getElementById('cmms-tpl-reset-form').submit();
                    }
                });
            }

            // Preview.
            var previewBtn = document.getElementById('cmms-tpl-preview-btn');
            var modal      = document.getElementById('cmms-tpl-preview-modal');
            var frame      = document.getElementById('cmms-tpl-preview-frame');
            var closeBtn   = document.getElementById('cmms-tpl-preview-close');
            if (previewBtn && modal && frame) {
                previewBtn.addEventListener('click', function () {
                    // Build preview by sending the current editor HTML to a
                    // server-side preview endpoint that wraps it in the
                    // branded shell and substitutes sample vars. We can
                    // also just render inline for simplicity:
                    var html = editor.innerHTML;
                    // Quick client-side variable substitution for preview.
                    var sample = <?php echo wp_json_encode( self::sample_vars() ); ?>;
                    Object.keys(sample).forEach(function (k) {
                        var re = new RegExp('\\{\\{' + k + '\\}\\}', 'g');
                        html = html.replace(re, sample[k]);
                    });
                    // Wrap with a minimal shell similar to send-time.
                    var shell =
                        '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8">' +
                        '<style>body{margin:0;background:#f8fafc;font-family:Arial,sans-serif;padding:24px;}' +
                        '.card{max-width:600px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,0.06);}' +
                        '.stripe{background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);height:6px;}' +
                        '.brand{padding:24px 32px 8px;text-align:right;color:#ff6a00;font-weight:700;font-size:22px;}' +
                        '.body{padding:18px 32px 28px;font-size:15px;line-height:1.7;color:#0f172a;}' +
                        '.footer{padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;text-align:center;}' +
                        '</style></head><body>' +
                        '<div class="card"><div class="stripe"></div>' +
                        '<div class="brand">CMMS</div>' +
                        '<div class="body">' + html + '</div>' +
                        '<div class="footer">תצוגה מקדימה - כך ייראה האימייל אצל הלקוח</div>' +
                        '</div></body></html>';
                    frame.srcdoc = shell;
                    modal.style.display = 'flex';
                });
            }
            if (closeBtn) closeBtn.addEventListener('click', function () { modal.style.display = 'none'; });
            if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) modal.style.display = 'none'; });
        })();
        </script>
        <?php
    }
}
