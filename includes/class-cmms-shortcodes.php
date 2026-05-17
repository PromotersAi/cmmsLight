<?php
/**
 * CMMS Shortcodes - the entire user-facing UI.
 *
 * Four shortcodes: [cmms_signup], [cmms_login], [cmms_dashboard], [cmms_public_form].
 * The dashboard contains all internal app views routed by ?view=...
 *
 * UI design language: industrial SaaS (UpKeep / Fiix). Deep navy + safety orange.
 * All copy goes through CMMS_I18n::t() so the user can switch language live.
 * All icons come from CMMS_Icons (inline SVG, no fonts, no emojis).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Shortcodes {

    public function __construct() {
        add_shortcode( 'cmms_signup',      array( $this, 'render_signup' ) );
        add_shortcode( 'cmms_login',       array( $this, 'render_login' ) );
        add_shortcode( 'cmms_dashboard',   array( $this, 'render_dashboard' ) );
        add_shortcode( 'cmms_public_form', array( $this, 'render_public_form' ) );
        add_shortcode( 'cmms_public_asset', array( $this, 'render_public_asset' ) );
        // 1.14.61: Forgot/Reset password — standalone pages that
        // wrap our branded login-style card. Both render full HTML
        // via maybe_serve_standalone path (same as login/signup).
        add_shortcode( 'cmms_forgot',      array( $this, 'render_forgot' ) );
        add_shortcode( 'cmms_reset',       array( $this, 'render_reset' ) );
        // 1.14.25: modern SaaS onboarding wizard (entirely separate from the
        // legacy cmms_signup shortcode; that one continues to function).
        add_shortcode( 'cmms_onboarding',  array( $this, 'render_onboarding' ) );

        // 1.14.29: intercept /start at template_redirect — BEFORE the
        // theme starts rendering. Without this, the theme wraps our
        // standalone HTML doc inside its own header/footer, which both
        // breaks the SaaS look AND blocks our inline JS from running
        // properly (theme scripts often re-set globals or interfere
        // with event listeners on the document body).
        //
        // The shortcode handler stays registered as a fallback in case
        // someone embeds [cmms_onboarding] elsewhere, but the primary
        // path is this hook.
        add_action( 'template_redirect', array( $this, 'maybe_serve_onboarding_standalone' ), 1 );

        // 1.14.46: same standalone treatment for the public asset page
        // (QR scan target). A worker scanning a QR code in the field
        // doesn't need the WordPress site's header/footer/WhatsApp
        // bubble — they need a clean, app-like report screen. We
        // detect by URL path (/cmms-asset/) OR by [cmms_public_asset]
        // shortcode anywhere in the post content.
        add_action( 'template_redirect', array( $this, 'maybe_serve_public_asset_standalone' ), 1 );

        // 1.14.64: standalone treatment for auth pages too. The login,
        // signup, forgot, and reset pages should look like SaaS auth
        // (Stripe / Slack / Linear style) not a WordPress page with a
        // theme wrap around them. Detection: any of the four auth
        // shortcodes in the post content.
        add_action( 'template_redirect', array( $this, 'maybe_serve_auth_standalone' ), 1 );
    }

    /**
     * Serve /start as a fully standalone page (no theme wrap) before
     * WordPress starts streaming the theme's header. Detection: any
     * page using the [cmms_onboarding] shortcode, regardless of slug.
     */
    public function maybe_serve_onboarding_standalone() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post ) return;
        // Match by slug OR by shortcode presence — both paths cover the
        // "Start" page WordPress auto-creates AND any custom page that
        // editors might embed the onboarding into.
        $is_start_slug = ( isset( $post->post_name ) && $post->post_name === 'start' );
        $has_shortcode = has_shortcode( (string) $post->post_content, 'cmms_onboarding' );
        if ( ! $is_start_slug && ! $has_shortcode ) return;

        // Hand off to the renderer, which echoes the full HTML doc and
        // calls exit(). Theme rendering never gets a turn.
        $this->render_onboarding();
    }

    /**
     * 1.14.46: Serve the public asset / QR report page as a fully
     * standalone document — no theme header, footer, admin bar, or
     * marketing chrome. A worker in the field who just scanned a QR
     * needs an app-like experience: clean, focused, mobile-first.
     *
     * Detection mirrors the onboarding pattern:
     *   - slug 'cmms-asset' (the page WordPress auto-creates), OR
     *   - any page containing the [cmms_public_asset] shortcode
     *
     * Logic note: render_public_asset() may internally redirect
     * logged-in CMMS users to the full asset view in the dashboard.
     * That redirect uses a <script>location.replace</script> which
     * works regardless of standalone wrap, so we route through the
     * standalone wrapper either way.
     */
    public function maybe_serve_public_asset_standalone() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post ) return;

        $is_asset_slug = ( isset( $post->post_name ) && $post->post_name === 'cmms-asset' );
        $has_shortcode = has_shortcode( (string) $post->post_content, 'cmms_public_asset' );
        if ( ! $is_asset_slug && ! $has_shortcode ) return;

        $this->render_public_asset_standalone();
    }

    /**
     * 1.14.64: Standalone serve for auth pages (login/signup/forgot/reset).
     *
     * Why: WordPress themes by default wrap the page in header/footer
     * with marketing chrome (menus, WhatsApp widgets, brand logos
     * repeated). For auth screens — which should feel like Stripe,
     * Slack, Linear, etc — we want a fully isolated full-screen layout.
     *
     * Detection: any of the four auth shortcodes present in the
     * current page's content. We route to a single shared renderer
     * that dispatches to the right inner shortcode handler based on
     * which one matched.
     */
    public function maybe_serve_auth_standalone() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post ) return;

        $content = (string) $post->post_content;
        $shortcode = null;
        $renderer  = null;
        if ( has_shortcode( $content, 'cmms_login' ) ) {
            $shortcode = 'cmms_login';
            $renderer  = array( $this, 'render_login' );
        } elseif ( has_shortcode( $content, 'cmms_signup' ) ) {
            $shortcode = 'cmms_signup';
            $renderer  = array( $this, 'render_signup' );
        } elseif ( has_shortcode( $content, 'cmms_forgot' ) ) {
            $shortcode = 'cmms_forgot';
            $renderer  = array( $this, 'render_forgot' );
        } elseif ( has_shortcode( $content, 'cmms_reset' ) ) {
            $shortcode = 'cmms_reset';
            $renderer  = array( $this, 'render_reset' );
        } else {
            return;
        }

        $this->render_auth_standalone( $renderer );
    }

    /**
     * Emit a full standalone HTML doc for an auth page. Same idea as
     * render_public_asset_standalone() but for auth: wp_head/wp_footer
     * still run (so cmms-light-public.css ships), but theme header
     * and marketing widgets do not.
     *
     * @param callable $renderer  the shortcode renderer that produces
     *                            the page-body HTML
     */
    private function render_auth_standalone( $renderer ) {
        $inner = call_user_func( $renderer );

        $brand_name = get_option( 'cmms_brand_name' ) ?: 'CMMS';
        $dir  = $this->dir();
        $lang = $this->lang();

        nocache_headers();
        show_admin_bar( false );

        ?><!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $brand_name ); ?></title>
<link rel="icon" href="<?php echo esc_url( CMMS_LIGHT_URL . 'assets/images/icon-192.png' ); ?>">
<style>
/* Standalone shell (1.14.64). Resets + hides theme chrome that some
   themes inject via wp_footer (chat bubbles, floating CTAs, etc.).
   The actual auth layout styles come from cmms-light-public.css,
   which wp_head() will print below. */
html, body {
    margin: 0;
    padding: 0;
    background: #f8fafc;
    min-height: 100vh;
    min-height: 100dvh;
    font-family: Arial, "Heebo", "Segoe UI", sans-serif;
    -webkit-font-smoothing: antialiased;
}
/* Hide theme chrome and third-party widgets. */
body > #wpadminbar,
body > .wp-site-blocks > header,
body > .wp-site-blocks > footer,
body > header,
body > footer,
.elementor-location-header,
.elementor-location-footer,
.b24-widget-button-wrapper,
.bx24-form,
.crisp-client,
.intercom-lightweight-app,
.intercom-app,
[id^="hubspot-messages"],
.facebook-messenger,
[class*="whatsapp"][class*="float"],
[class*="whatsapp"][class*="widget"],
[id*="whatsapp"][id*="chat"],
.fb_dialog,
#fb-root { display: none !important; }
</style>
<?php
    wp_head();
?>
</head>
<body class="cmms-auth-standalone">
<?php
    echo $inner;
?>
<?php
    wp_footer();
?>
</body>
</html>
        <?php
        exit;
    }

    /**
     * 1.14.46: Echo a full standalone HTML document for the public
     * asset / QR scan page. We deliberately call wp_head() and
     * wp_footer() so that:
     *   - The plugin's own CSS/JS (cmms-light-public) gets enqueued
     *     and printed
     *   - WP system scripts (jQuery if needed) still load
     *   - Service Worker registration still happens
     * But we do NOT include the theme's header.php / footer.php, the
     * admin bar, marketing widgets, or any third-party chrome.
     *
     * The actual page content is produced by the existing
     * render_public_asset() — we don't reimplement business logic,
     * we just give it a clean shell.
     */
    private function render_public_asset_standalone() {
        // Inner content from the existing shortcode handler. If it
        // returns a <script>location.replace</script> redirect (the
        // logged-in CMMS user case), we still want to wrap it in a
        // minimal doc so the script actually executes before any theme
        // bytes are streamed.
        $inner = $this->render_public_asset();

        // Brand bits for the standalone shell.
        $brand_name = get_option( 'cmms_brand_name' ) ?: 'CMMS';
        $brand_logo = get_option( 'cmms_brand_logo' ) ?: '';
        $dir  = $this->dir();
        $lang = $this->lang();

        // Prevent any theme output that might already be queued.
        nocache_headers();

        // Hide the WP admin bar for logged-in admins on this view —
        // they're not "managing" here, they're previewing the field UX.
        show_admin_bar( false );

        ?><!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $brand_name ); ?></title>
<link rel="icon" href="<?php echo esc_url( CMMS_LIGHT_URL . 'assets/images/icon-192.png' ); ?>">
<style>
/* ================================================================
   Standalone shell (1.14.46). Minimal — just the body reset so the
   plugin's own cmms-light-public.css can drive the visual design.
   We do NOT redeclare component styles here; those live in the
   public CSS that wp_head() will print below.
================================================================ */
html, body {
    margin: 0;
    padding: 0;
    background: #f8fafc;
    min-height: 100vh;
    min-height: 100dvh;
    font-family: Arial, "Heebo", "Segoe UI", sans-serif;
    -webkit-font-smoothing: antialiased;
}
/* Belt-and-braces: even though we don't render the theme header,
   some themes inject overlay widgets (chat bubbles, floating CTAs)
   via wp_footer. Hide any such elements that might appear here.
   This list is conservative — only well-known marketing chrome
   that has no business on a QR-scan report page. */
body > #wpadminbar,
body > .wp-site-blocks > header,
body > .wp-site-blocks > footer,
body > header,
body > footer,
.elementor-location-header,
.elementor-location-footer,
.wpcf7-not-valid-tip,
.b24-widget-button-wrapper,
.bx24-form,
.crisp-client,
.intercom-lightweight-app,
.intercom-app,
[id^="hubspot-messages"],
.facebook-messenger,
[class*="whatsapp"][class*="float"],
[class*="whatsapp"][class*="widget"],
[id*="whatsapp"][id*="chat"],
.fb_dialog,
#fb-root { display: none !important; }
</style>
<?php
    // Let WordPress (and the public class) enqueue cmms-light-public.css/.js
    // plus any other system scripts via the standard pipeline.
    wp_head();
?>
</head>
<body class="cmms-public-standalone">
<?php
    // The inner content from render_public_asset(). This already
    // includes the .cmms-public-form-page wrapper and all per-asset
    // markup.
    echo $inner; // already escaped inside the renderer
?>
<?php
    // Footer scripts (SW registration, public JS bundle).
    wp_footer();
?>
</body>
</html>
        <?php
        exit;
    }

    /* ============================================================
       Helpers
    ============================================================ */
    private function t( $key ) { return CMMS_I18n::t( $key ); }
    private function e( $key ) { CMMS_I18n::e( $key ); }
    private function ico( $name, $size = 18 ) { return CMMS_Icons::get( $name, $size ); }

    private function dir() { return CMMS_I18n::dir(); }
    private function lang() { return CMMS_I18n::current(); }

    /** Renders the language switcher pill (top-right corner). */
    private function lang_switcher() {
        $current = CMMS_I18n::current();
        $url = remove_query_arg( 'cmms_lang' );
        ?>
        <div class="cmms-lang-switcher" role="group" aria-label="Language">
            <?php foreach ( CMMS_I18n::langs() as $code => $info ) :
                $href = add_query_arg( 'cmms_lang', $code, $url );
            ?>
                <a href="<?php echo esc_url( $href ); ?>"
                   class="<?php echo $current === $code ? 'active' : ''; ?>"
                   hreflang="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $info['name'] ); ?></a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Notification bell with dropdown.
     * $variant: 'mobile' (default, used in topbar) or 'desktop' (used in main header).
     */
    private function bell( $u, $unread_count, $recent_notifs, $variant = 'mobile' ) {
        $mark_all_url = wp_nonce_url(
            add_query_arg( array( 'action' => 'cmms_notify_mark_all_read' ), $this->admin_post_url() ),
            'cmms_nonce_cmms_notify_mark_all_read', '_wpnonce'
        );
        ?>
        <div class="cmms-bell cmms-bell-<?php echo esc_attr( $variant ); ?>" data-cmms-bell>
            <button type="button" class="cmms-bell-btn" aria-label="<?php esc_attr_e( 'Notifications', 'cmms-light' ); ?>" aria-haspopup="true" aria-expanded="false">
                <?php CMMS_Icons::e( 'bell', 20 ); ?>
                <?php if ( $unread_count > 0 ) : ?>
                    <span class="cmms-bell-badge"><?php echo esc_html( $unread_count > 99 ? '99+' : $unread_count ); ?></span>
                <?php endif; ?>
            </button>
            <div class="cmms-bell-dropdown" role="menu" aria-hidden="true">
                <div class="cmms-bell-head">
                    <span class="cmms-bell-title"><?php $this->e( 'notify.title' ); ?></span>
                    <?php if ( $unread_count > 0 ) : ?>
                        <a href="<?php echo esc_url( $mark_all_url ); ?>" class="cmms-bell-mark-all"><?php $this->e( 'notify.mark_all_read' ); ?></a>
                    <?php endif; ?>
                </div>
                <div class="cmms-bell-list">
                    <?php if ( empty( $recent_notifs ) ) : ?>
                        <div class="cmms-bell-empty">
                            <?php CMMS_Icons::e( 'inbox', 32 ); ?>
                            <p><?php $this->e( 'notify.empty' ); ?></p>
                        </div>
                    <?php else :
                        foreach ( $recent_notifs as $n ) :
                            $is_unread = empty( $n->read_at );
                            $url = $n->url ?: $this->url( array( 'view' => 'home' ) );
                            // POST-mark-read via GET-then-redirect by appending the notif id to a special URL
                            $click_url = wp_nonce_url(
                                add_query_arg( array( 'action' => 'cmms_notify_mark_read', 'id' => (int) $n->id, 'redirect' => urlencode( $url ) ), $this->admin_post_url() ),
                                'cmms_nonce_cmms_notify_mark_read', '_wpnonce'
                            );
                            $when = $this->humanize_time( $n->created_at );
                            $icon = $this->icon_for_notif_type( $n->type );
                    ?>
                        <a href="<?php echo esc_url( $click_url ); ?>" class="cmms-bell-item <?php echo $is_unread ? 'unread' : ''; ?>" data-cmms-notif="<?php echo esc_attr( $n->id ); ?>">
                            <span class="cmms-bell-item-icon"><?php CMMS_Icons::e( $icon, 16 ); ?></span>
                            <span class="cmms-bell-item-body">
                                <span class="cmms-bell-item-title"><?php echo esc_html( $n->title ); ?></span>
                                <?php if ( ! empty( $n->body ) ) : ?>
                                    <span class="cmms-bell-item-snippet"><?php echo esc_html( wp_trim_words( $n->body, 14, '…' ) ); ?></span>
                                <?php endif; ?>
                                <span class="cmms-bell-item-time"><?php echo esc_html( $when ); ?></span>
                            </span>
                            <?php if ( $is_unread ) : ?><span class="cmms-bell-item-dot" aria-hidden="true"></span><?php endif; ?>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /** Map a notification type to an icon name. */
    private function icon_for_notif_type( $type ) {
        switch ( $type ) {
            case 'task_assigned':    return 'user-plus';
            case 'task_managed':     return 'briefcase';
            case 'task_due_before':  return 'clock';
            case 'task_overdue':     return 'alert-triangle';
            case 'task_commented':   return 'message-square';
            case 'task_status':      return 'activity';
            case 'user_invited':     return 'user-plus';
            case 'public_submitted': return 'inbox';
            default:                 return 'info';
        }
    }

    /**
     * Renders a "Install as app" banner + a hidden install dialog with
     * platform-specific instructions. The banner auto-hides when:
     *   - The page is already running in standalone mode (PWA installed)
     *   - The user dismissed the banner before (localStorage flag)
     * The button opens a dialog. On Android Chrome, if the beforeinstallprompt
     * event was captured, it triggers the native prompt directly. On iOS,
     * shows step-by-step instructions (Share -> Add to Home Screen).
     */
    private function install_banner() {
        ?>
        <div class="cmms-install-banner" data-cmms-install-banner hidden>
            <span class="cmms-install-banner-icon"><?php CMMS_Icons::e( 'download', 18 ); ?></span>
            <div class="cmms-install-banner-text">
                <strong><?php $this->e( 'install.banner_title' ); ?></strong>
                <span><?php $this->e( 'install.banner_sub' ); ?></span>
            </div>
            <button type="button" class="cmms-btn cmms-btn-sm cmms-btn-primary" data-cmms-install-open>
                <?php $this->e( 'install.banner_cta' ); ?>
            </button>
            <button type="button" class="cmms-install-banner-dismiss" data-cmms-install-dismiss aria-label="<?php echo esc_attr( $this->t( 'install.dismiss' ) ); ?>">
                <?php CMMS_Icons::e( 'x', 16 ); ?>
            </button>
        </div>

        <!-- Install instructions dialog -->
        <div class="cmms-install-dialog" data-cmms-install-dialog hidden role="dialog" aria-modal="true" aria-labelledby="cmms-install-title">
            <div class="cmms-install-dialog-backdrop" data-cmms-install-close></div>
            <div class="cmms-install-dialog-panel">
                <button type="button" class="cmms-install-dialog-close" data-cmms-install-close aria-label="<?php echo esc_attr( $this->t( 'install.close' ) ); ?>">
                    <?php CMMS_Icons::e( 'x', 18 ); ?>
                </button>
                <div class="cmms-install-dialog-head">
                    <div class="cmms-install-dialog-icon">
                        <img src="<?php echo esc_url( CMMS_LIGHT_URL . 'assets/images/icon-192.png' ); ?>" alt="" width="64" height="64">
                    </div>
                    <h2 id="cmms-install-title"><?php $this->e( 'install.title' ); ?></h2>
                    <p><?php $this->e( 'install.subtitle' ); ?></p>
                </div>

                <!-- Tabs for platforms -->
                <div class="cmms-install-tabs" role="tablist">
                    <button type="button" class="cmms-install-tab active" data-cmms-install-tab="android" role="tab">
                        <?php CMMS_Icons::e( 'smartphone', 16 ); ?> <?php $this->e( 'install.tab_android' ); ?>
                    </button>
                    <button type="button" class="cmms-install-tab" data-cmms-install-tab="ios" role="tab">
                        <?php CMMS_Icons::e( 'smartphone', 16 ); ?> <?php $this->e( 'install.tab_ios' ); ?>
                    </button>
                    <button type="button" class="cmms-install-tab" data-cmms-install-tab="desktop" role="tab">
                        <?php CMMS_Icons::e( 'monitor', 16 ); ?> <?php $this->e( 'install.tab_desktop' ); ?>
                    </button>
                </div>

                <!-- Android panel -->
                <div class="cmms-install-panel active" data-cmms-install-panel="android">
                    <button type="button" class="cmms-btn cmms-btn-primary cmms-btn-block" data-cmms-install-trigger hidden>
                        <?php CMMS_Icons::e( 'download', 16 ); ?> <?php $this->e( 'install.android_button' ); ?>
                    </button>
                    <ol class="cmms-install-steps">
                        <li><?php $this->e( 'install.android_1' ); ?></li>
                        <li><?php $this->e( 'install.android_2' ); ?></li>
                        <li><?php $this->e( 'install.android_3' ); ?></li>
                    </ol>
                    <p class="cmms-install-note"><?php $this->e( 'install.android_note' ); ?></p>
                </div>

                <!-- iOS panel -->
                <div class="cmms-install-panel" data-cmms-install-panel="ios" hidden>
                    <ol class="cmms-install-steps">
                        <li><?php $this->e( 'install.ios_1' ); ?></li>
                        <li><?php $this->e( 'install.ios_2' ); ?> <span class="cmms-ios-share-icon" aria-hidden="true">⬆️</span></li>
                        <li><?php $this->e( 'install.ios_3' ); ?></li>
                        <li><?php $this->e( 'install.ios_4' ); ?></li>
                    </ol>
                    <p class="cmms-install-note"><?php $this->e( 'install.ios_note' ); ?></p>
                </div>

                <!-- Desktop panel -->
                <div class="cmms-install-panel" data-cmms-install-panel="desktop" hidden>
                    <button type="button" class="cmms-btn cmms-btn-primary cmms-btn-block" data-cmms-install-trigger hidden>
                        <?php CMMS_Icons::e( 'download', 16 ); ?> <?php $this->e( 'install.desktop_button' ); ?>
                    </button>
                    <ol class="cmms-install-steps">
                        <li><?php $this->e( 'install.desktop_1' ); ?></li>
                        <li><?php $this->e( 'install.desktop_2' ); ?></li>
                    </ol>
                    <p class="cmms-install-note"><?php $this->e( 'install.desktop_note' ); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Time range picker. Renders a small dropdown that scopes data on the
     * current page to a chosen window. State is in URL (?range=...).
     */
    private function range_picker( $current_view, $extra_args = array() ) {
        $range = CMMS_TimeRange::from_request();
        $base_args = array_merge( array( 'view' => $current_view ), $extra_args );
        ?>
        <div class="cmms-range-picker" data-cmms-range>
            <button type="button" class="cmms-range-trigger" aria-haspopup="true" aria-expanded="false">
                <?php CMMS_Icons::e( 'calendar', 16 ); ?>
                <span class="cmms-range-trigger-label"><?php echo esc_html( $range['label'] ); ?></span>
                <?php CMMS_Icons::e( 'chevron-down', 14 ); ?>
            </button>
            <div class="cmms-range-menu" role="menu">
                <?php foreach ( CMMS_TimeRange::presets() as $preset ) :
                    if ( $preset === 'custom' ) continue;
                    $href_args = array_merge( $base_args, array( 'range' => $preset ) );
                    $is_active = ( $range['key'] === $preset );
                ?>
                    <a class="cmms-range-item <?php echo $is_active ? 'active' : ''; ?>" href="<?php echo esc_url( $this->url( $href_args ) ); ?>">
                        <?php echo esc_html( CMMS_TimeRange::label_for( $preset ) ); ?>
                    </a>
                <?php endforeach; ?>
                <div class="cmms-range-custom">
                    <form method="get">
                        <?php foreach ( $base_args as $k => $v ) : ?>
                            <input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
                        <?php endforeach; ?>
                        <?php
                        // preserve page_id if set (WP page rewrites)
                        if ( isset( $_GET['page_id'] ) ) {
                            echo '<input type="hidden" name="page_id" value="' . esc_attr( (int) $_GET['page_id'] ) . '">';
                        }
                        ?>
                        <input type="hidden" name="range" value="custom">
                        <label class="cmms-range-custom-label"><?php $this->e( 'range.from' ); ?></label>
                        <input type="date" name="from" value="<?php echo esc_attr( $range['key'] === 'custom' ? $range['from_date'] : '' ); ?>" class="cmms-input">
                        <label class="cmms-range-custom-label"><?php $this->e( 'range.to' ); ?></label>
                        <input type="date" name="to" value="<?php echo esc_attr( $range['key'] === 'custom' ? $range['to_date'] : '' ); ?>" class="cmms-input">
                        <button type="submit" class="cmms-btn cmms-btn-sm cmms-btn-primary"><?php $this->e( 'common.apply' ); ?></button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /** Human-readable relative time (i18n-aware). */
    private function humanize_time( $datetime ) {
        if ( ! $datetime ) return '';
        $ts = strtotime( $datetime );
        if ( ! $ts ) return $datetime;
        $now = current_time( 'timestamp' );
        $diff = $now - $ts;
        if ( $diff < 60 ) return $this->t( 'time.now' );
        if ( $diff < 3600 ) return sprintf( $this->t( 'time.min_ago' ), (int) ( $diff / 60 ) );
        if ( $diff < 86400 ) return sprintf( $this->t( 'time.hour_ago' ), (int) ( $diff / 3600 ) );
        if ( $diff < 7 * 86400 ) return sprintf( $this->t( 'time.day_ago' ), (int) ( $diff / 86400 ) );
        return wp_date( 'M j', $ts );
    }

    private function brand_mark( $size = 36 ) {
        $px = (int) $size;
        ?>
        <span class="cmms-brand-mark" style="width:<?php echo $px; ?>px;height:<?php echo $px; ?>px;">
            <?php CMMS_Icons::e( 'wrench', max( 16, $px - 16 ) ); ?>
        </span>
        <?php
    }

    private function url( $args = array() ) {
        return add_query_arg( $args, home_url( '/cmms-dashboard/' ) );
    }

    private function admin_post_url() {
        return admin_url( 'admin-post.php' );
    }

    /* ============================================================
       SIGNUP
    ============================================================ */
    /* ============================================================
       ONBOARDING WIZARD (1.14.25+)

       Modern SaaS wizard accessible at /start. Entirely separate from
       the legacy [cmms_signup] above — that one keeps working for the
       /cmms-signup/ page and existing flows. This one is the new front
       door for cmms.co.il.

       Build state (1.14.25): step 1 implemented (account creation).
       Steps 2–6 will be added in 1.14.26 → 1.14.29. The wizard scaffold
       below already shows the 6-step progress bar so users see where
       they are; later steps just slot into the same shell.

       Design rules:
         - Mobile first, full-bleed on phones, centered card on desktop.
         - Arial-stack font; no WordPress chrome (no theme header, no
           Elementor, no wp-login look).
         - RTL by default. The standalone stylesheet at the bottom of
           this method scopes everything to .cmms-onb-* so it can't
           collide with anything else on the site.
         - All assets inlined here (CSS in <style>, JS in <script>) so
           the page has zero plugin/theme dependencies and stays fast.
           Trade-off: the file is large. Acceptable because this lives
           on one page and ships uncached the first time anyway.
    ============================================================ */
    public function render_onboarding( $atts = array() ) {
        $step = isset( $_GET['step'] ) ? (int) $_GET['step'] : 1;
        if ( $step < 1 || $step > 6 ) $step = 1;

        // 1.14.36 fix: only bounce logged-in users to the dashboard on
        // the EARLY steps (pricing + account creation). Steps 3+ are
        // post-signup steps — Payment, Workspace setup, etc. — and the
        // user MUST already be logged in by then (auto_login=true after
        // signup). Bouncing them to dashboard would skip Payment.
        //
        // Specifically: a user reaching /start?step=3 has just been
        // auto-logged-in by the signup pipeline and needs to land on
        // the payment screen, not the dashboard.
        if ( $step <= 2 && is_user_logged_in() && CMMS_Auth::current_cmms_user() ) {
            wp_safe_redirect( home_url( '/cmms-dashboard/' ) );
            exit;
        }

        // 1.14.47: Step 4 is the Workspace Animation — a dedicated full-
        // screen experience that runs after a successful payment and
        // before the dashboard. It has its own renderer and DOES NOT go
        // through the regular onboarding shell (no progress dots, no
        // step label, no "back" link). The renderer echoes a complete
        // document and exit()s.
        if ( $step === 4 ) {
            $this->render_workspace_animation();
            exit;
        }

        // 1.14.27: read plan + cycle from URL. These are the source of
        // truth for which plan the user selected on Step 1 before they
        // arrive at Step 2 (account creation). Defaults are safe even
        // if missing: Business + monthly is the recommended path.
        //
        // Validation is loose here — invalid values just fall back to
        // defaults. The AJAX endpoint does strict validation again
        // before persisting to the DB.
        $selected_plan  = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : '';
        $selected_cycle = isset( $_GET['cycle'] ) ? sanitize_key( wp_unslash( $_GET['cycle'] ) ) : '';
        if ( ! in_array( $selected_plan, CMMS_Plans::valid_ids(), true ) ) {
            $selected_plan = CMMS_Plans::default_id();
        }
        if ( ! in_array( $selected_cycle, CMMS_Plans::valid_cycles(), true ) ) {
            $selected_cycle = CMMS_Plans::default_cycle();
        }

        // Guardrail: if someone lands on step=2 without a plan in the URL
        // and without sessionStorage backup, we still let them through —
        // the JS on step 2 will restore from sessionStorage if available,
        // otherwise the defaults above apply. We never force-redirect them
        // back to step 1; that would break the back-button experience.

        // Logo URL — use the brand logo option if set, otherwise show a
        // wordmark made from the brand initial.
        $brand_logo = get_option( 'cmms_brand_logo' ) ?: '';
        $brand_name = get_option( 'cmms_brand_name' ) ?: 'CMMS';

        $login_url   = home_url( '/cmms-login/' );
        $admin_post  = esc_url( admin_url( 'admin-post.php' ) );
        $nonce       = wp_create_nonce( 'cmms_onboarding' );

        // Pre-fetch plan data for templates (avoids repeated CMMS_Plans
        // calls inside markup).
        $plans       = CMMS_Plans::all();
        $current_plan = CMMS_Plans::get( $selected_plan );

        ob_start();
        ?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $brand_name ); ?> — פתיחת חשבון</title>
<link rel="icon" href="<?php echo esc_url( CMMS_LIGHT_URL . 'assets/images/icon-192.png' ); ?>">
<style>
/* ================================================================
   Onboarding wizard styles. Scoped under .cmms-onb-root so nothing
   leaks into the rest of the site. Self-contained: no external CSS,
   no theme inheritance assumed.
   ================================================================ */
.cmms-onb-root, .cmms-onb-root * { box-sizing: border-box; }
.cmms-onb-root {
    font-family: Arial, "Heebo", "Segoe UI", sans-serif;
    color: #0f172a;
    background: #f8fafc;
    min-height: 100vh;
    min-height: 100dvh;
    direction: rtl;
    -webkit-font-smoothing: antialiased;
    line-height: 1.55;
}
.cmms-onb-root a { color: #4f46e5; text-decoration: none; }
.cmms-onb-root a:hover { text-decoration: underline; }
.cmms-onb-root button { font-family: inherit; }

/* Topbar — brand + login link */
.cmms-onb-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: #fff;
    border-bottom: 1px solid #eef2f7;
}
.cmms-onb-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 17px;
    color: #0f172a;
}
.cmms-onb-brand img { width: 28px; height: 28px; border-radius: 7px; object-fit: contain; }
.cmms-onb-brand-mark {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 800; font-size: 15px;
}
.cmms-onb-login-link {
    font-size: 14px;
    color: #475569;
}

/* Page container.
   Width adapts to content: narrow on form steps (account creation,
   company setup) where the user is filling in fields, wider on the
   pricing step where 3 plan cards need breathing room. The wrap class
   gets a step modifier (.is-step-1, .is-step-2, etc.) from PHP so we
   can target without JS. */
.cmms-onb-wrap {
    max-width: 540px;
    margin: 0 auto;
    padding: 24px 20px 120px; /* bottom padding leaves room for sticky CTA on mobile */
}
/* Pricing step gets the full premium SaaS width. On large screens
   we go up to 1200px so the 3 cards spread out comfortably. */
.cmms-onb-wrap.is-step-1 {
    max-width: 1200px;
}

/* Progress bar — 6 stepper dots */
.cmms-onb-progress {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 28px;
}
.cmms-onb-progress-dot {
    flex: 1;
    height: 4px;
    border-radius: 2px;
    background: #e2e8f0;
    transition: background 0.3s ease;
}
.cmms-onb-progress-dot.is-done { background: #4f46e5; }
.cmms-onb-progress-dot.is-active { background: #4f46e5; }
.cmms-onb-step-label {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 18px;
}

/* Hero — positioning headline */
.cmms-onb-hero h1 {
    font-size: 26px;
    line-height: 1.25;
    margin: 0 0 8px;
    font-weight: 700;
    letter-spacing: -0.01em;
}
.cmms-onb-hero p {
    font-size: 15px;
    color: #475569;
    margin: 0 0 24px;
}

/* Form card */
.cmms-onb-card {
    background: #fff;
    border-radius: 16px;
    padding: 22px 20px;
    box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 4px 16px rgba(15,23,42,0.04);
}

/* Form field group */
.cmms-onb-field {
    margin-bottom: 16px;
}
.cmms-onb-field-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #334155;
    margin-bottom: 6px;
}
.cmms-onb-input-wrap {
    position: relative;
}
.cmms-onb-input {
    width: 100%;
    height: 50px;
    padding: 0 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 16px; /* 16px prevents iOS zoom-on-focus */
    background: #fff;
    color: #0f172a;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    font-family: inherit;
}
.cmms-onb-input:focus {
    outline: none;
    border-color: #4f46e5;
    box-shadow: 0 0 0 4px rgba(79,70,229,0.12);
}
.cmms-onb-input::placeholder { color: #94a3b8; }
.cmms-onb-input.has-error {
    border-color: #ef4444;
    background: #fef2f2;
}
.cmms-onb-input.has-error:focus {
    box-shadow: 0 0 0 4px rgba(239,68,68,0.12);
}
.cmms-onb-field.is-valid .cmms-onb-input {
    border-color: #10b981;
}

/* Password input with reveal toggle */
.cmms-onb-input--pw { padding-inline-end: 44px; }
.cmms-onb-pw-toggle {
    position: absolute;
    inset-inline-end: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: 0;
    color: #64748b;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    line-height: 0;
}
.cmms-onb-pw-toggle:hover { color: #0f172a; background: #f1f5f9; }
.cmms-onb-pw-toggle svg { width: 18px; height: 18px; display: block; }

/* Error message under field */
.cmms-onb-error {
    display: none;
    font-size: 13px;
    color: #dc2626;
    margin-top: 6px;
    line-height: 1.4;
}
.cmms-onb-field.has-error .cmms-onb-error { display: block; }

/* Help text under field (eg password rules) */
.cmms-onb-hint {
    font-size: 12px;
    color: #64748b;
    margin-top: 6px;
}

/* Password strength meter — 3 segments */
.cmms-onb-strength {
    display: flex;
    gap: 4px;
    margin-top: 8px;
}
.cmms-onb-strength-seg {
    flex: 1;
    height: 4px;
    border-radius: 2px;
    background: #e2e8f0;
    transition: background 0.2s ease;
}
.cmms-onb-strength.is-weak .cmms-onb-strength-seg:nth-child(1) { background: #ef4444; }
.cmms-onb-strength.is-medium .cmms-onb-strength-seg:nth-child(-n+2) { background: #f59e0b; }
.cmms-onb-strength.is-strong .cmms-onb-strength-seg { background: #10b981; }

/* Terms checkbox */
.cmms-onb-terms {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 18px 0 4px;
    font-size: 14px;
    color: #334155;
    cursor: pointer;
}
.cmms-onb-terms input {
    flex: none;
    width: 20px;
    height: 20px;
    margin: 1px 0 0;
    accent-color: #4f46e5;
    cursor: pointer;
}
.cmms-onb-terms.has-error { color: #dc2626; }
.cmms-onb-terms a {
    color: #4f46e5;
    font-weight: 600;
    text-decoration: underline;
}
.cmms-onb-terms a:hover { color: #4338ca; }

/* Primary CTA */
.cmms-onb-cta {
    width: 100%;
    height: 52px;
    border: 0;
    border-radius: 12px;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.1s ease, box-shadow 0.15s ease, opacity 0.2s ease;
    box-shadow: 0 4px 14px rgba(79,70,229,0.32);
    margin-top: 8px;
}
.cmms-onb-cta:hover { box-shadow: 0 6px 18px rgba(79,70,229,0.4); }
.cmms-onb-cta:active { transform: translateY(1px); }
.cmms-onb-cta:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    box-shadow: none;
}
.cmms-onb-cta-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.cmms-onb-spinner {
    width: 18px; height: 18px;
    border: 2px solid rgba(255,255,255,0.45);
    border-top-color: #fff;
    border-radius: 50%;
    animation: cmms-onb-spin 0.7s linear infinite;
    display: none;
}
.cmms-onb-cta.is-loading .cmms-onb-spinner { display: inline-block; }
.cmms-onb-cta.is-loading .cmms-onb-cta-label { opacity: 0.7; }
@keyframes cmms-onb-spin { to { transform: rotate(360deg); } }

/* Secondary link under CTA */
.cmms-onb-secondary {
    text-align: center;
    margin-top: 18px;
    font-size: 14px;
    color: #64748b;
}

/* Global error banner above the form */
.cmms-onb-banner {
    display: none;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 14px;
    margin-bottom: 16px;
}
.cmms-onb-banner.is-visible { display: block; }

/* Trust strip below the card */
.cmms-onb-trust {
    margin-top: 22px;
    text-align: center;
    font-size: 12px;
    color: #94a3b8;
    line-height: 1.6;
}
.cmms-onb-trust strong { color: #475569; font-weight: 600; }

/* Desktop refinements */
@media (min-width: 640px) {
    .cmms-onb-wrap { padding: 40px 24px 60px; }
    .cmms-onb-hero h1 { font-size: 30px; }
    .cmms-onb-card { padding: 28px; }
}

/* Mobile sticky CTA — when the form is long enough to scroll on small
   screens, the primary CTA sticks to the bottom of the viewport so the
   user always sees the next action. Active only on phone-sized viewports
   to avoid covering the desktop card layout. */
@media (max-width: 639px) {
    .cmms-onb-card { padding: 20px 16px 16px; }
}

/* ================================================================
   Pricing step (1.14.27)
   Cycle toggle, plan cards, and the summary chip on step 2.
   ================================================================ */

/* Cycle toggle (monthly/yearly) — segmented control style */
.cmms-onb-cycle-toggle {
    display: inline-flex;
    background: #eef2f7;
    border-radius: 12px;
    padding: 4px;
    margin: 0 auto 24px;
    width: fit-content;
    /* Center the toggle. Auto margins on a flex parent need this trick. */
    display: flex;
}
.cmms-onb-cycle-wrap {
    text-align: center;
    margin-bottom: 24px;
}
.cmms-onb-cycle-btn {
    border: 0;
    background: transparent;
    color: #475569;
    padding: 10px 18px;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}
.cmms-onb-cycle-btn:hover { color: #0f172a; }
.cmms-onb-cycle-btn.is-active {
    background: #fff;
    color: #0f172a;
    box-shadow: 0 1px 3px rgba(15,23,42,0.08);
}
.cmms-onb-cycle-save {
    font-size: 11px;
    background: #d1fae5;
    color: #047857;
    padding: 2px 8px;
    border-radius: 999px;
    font-weight: 700;
}

/* Center the toggle */
.cmms-onb-cycle-toggle {
    margin-left: auto;
    margin-right: auto;
}

/* Plans grid: 1 column mobile, 3 columns desktop */
.cmms-onb-plans {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    margin-bottom: 8px;
}
@media (min-width: 900px) {
    .cmms-onb-plans { grid-template-columns: repeat(3, 1fr); }
}

/* Plan card */
.cmms-onb-plan {
    position: relative;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 16px;
    padding: 24px 22px;
    display: flex;
    flex-direction: column;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
}
.cmms-onb-plan:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(15,23,42,0.06);
}
.cmms-onb-plan.is-recommended {
    border-color: #4f46e5;
    /* Slight extra emphasis: subtle gradient border via background +
       inner card. Keeps the card visually anchored as the default pick. */
    box-shadow: 0 8px 24px rgba(79,70,229,0.12);
}
.cmms-onb-plan-badge {
    position: absolute;
    top: -10px;
    inset-inline-start: 22px;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 999px;
    letter-spacing: 0.02em;
}

.cmms-onb-plan-head { margin-bottom: 16px; }
.cmms-onb-plan-name {
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 4px;
    color: #0f172a;
}
.cmms-onb-plan-tagline {
    font-size: 13px;
    color: #64748b;
    margin: 0;
    line-height: 1.5;
}

.cmms-onb-plan-price { margin-bottom: 20px; }
.cmms-onb-price-line {
    display: flex;
    align-items: baseline;
    gap: 6px;
    flex-wrap: wrap;
}
/* By default the yearly line is hidden. The JS shows whichever cycle
   is active and hides the other. This prevents a flash where both
   prices show before the JS runs. The .is-cycle-yearly class on the
   plan card flips which one is visible.
   1.14.30: hardened with !important because some themes' global
   reset CSS was overriding our display rules with higher specificity
   selectors, leaving both prices visible at all times. The JS also
   sets inline styles as belt-and-braces. */
.cmms-onb-root .cmms-onb-price-line[data-price-for="yearly"] { display: none !important; }
.cmms-onb-root .cmms-onb-price-line[data-price-for="monthly"] { display: flex !important; }
.cmms-onb-root .cmms-onb-plan.is-cycle-yearly .cmms-onb-price-line[data-price-for="monthly"] { display: none !important; }
.cmms-onb-root .cmms-onb-plan.is-cycle-yearly .cmms-onb-price-line[data-price-for="yearly"] { display: flex !important; }
.cmms-onb-price-prefix {
    font-size: 14px;
    color: #64748b;
    font-weight: 500;
}
.cmms-onb-price-amount {
    font-size: 32px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
}
.cmms-onb-price-suffix {
    font-size: 14px;
    color: #64748b;
}
.cmms-onb-price-per-month {
    font-size: 12px;
    color: #047857;
    background: #d1fae5;
    padding: 4px 10px;
    border-radius: 999px;
    display: inline-block;
    margin-top: 8px;
    font-weight: 600;
}

.cmms-onb-plan-features {
    list-style: none;
    margin: 0 0 24px;
    padding: 0;
    flex: 1 1 auto;
}
.cmms-onb-plan-features li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 6px 0;
    font-size: 14px;
    color: #334155;
    line-height: 1.5;
}
.cmms-onb-plan-features li svg {
    flex: none;
    color: #10b981;
    margin-top: 3px;
}

.cmms-onb-plan-cta {
    display: block;
    width: 100%;
    height: 48px;
    line-height: 48px;
    text-align: center;
    background: #fff;
    color: #4f46e5;
    border: 1.5px solid #c7d2fe;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    font-size: 15px;
    transition: background 0.18s ease, color 0.18s ease, transform 0.1s ease;
}
.cmms-onb-plan-cta:hover {
    background: #eff6ff;
    text-decoration: none;
}
.cmms-onb-plan-cta:active { transform: translateY(1px); }
.cmms-onb-plan-cta.is-primary {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 4px 14px rgba(79,70,229,0.32);
}
.cmms-onb-plan-cta.is-primary:hover {
    box-shadow: 0 6px 18px rgba(79,70,229,0.4);
    background: linear-gradient(135deg, #4338ca 0%, #6d28d9 100%);
}

/* Plan summary chip on step 2 — compact, reassuring, with a clear way
   back to step 1 to change choice. */
.cmms-onb-plan-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: #eef2ff;
    border: 1px solid #c7d2fe;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.cmms-onb-plan-summary-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #1e293b;
    flex-wrap: wrap;
}
.cmms-onb-plan-summary-name {
    font-weight: 700;
    color: #4338ca;
}
.cmms-onb-plan-summary-sep { color: #94a3b8; }
.cmms-onb-plan-summary-price { font-weight: 600; }
.cmms-onb-plan-summary-change {
    font-size: 13px;
    color: #4f46e5;
    text-decoration: underline;
    white-space: nowrap;
}
.cmms-onb-plan-summary-change:hover { color: #4338ca; }

/* ================================================================
   Payment step (1.14.36)
   Summary card + CTA. The card uses subtle borders and clear spacing
   to convey "this is the final commitment screen". The disclaimer
   below mentions PCI/security to build trust.
   ================================================================ */
.cmms-onb-payment-card {
    padding: 28px;
}
.cmms-onb-payment-summary {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px 20px;
    margin-bottom: 20px;
}
.cmms-onb-payment-summary-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    font-size: 14px;
    color: #475569;
}
.cmms-onb-payment-summary-line strong {
    color: #0f172a;
    font-weight: 600;
}
.cmms-onb-payment-summary-line + .cmms-onb-payment-summary-line {
    border-top: 1px dashed #e2e8f0;
}
.cmms-onb-payment-summary-total {
    margin-top: 4px;
    padding-top: 14px !important;
    border-top: 2px solid #cbd5e1 !important;
    font-size: 16px !important;
}
.cmms-onb-payment-summary-total strong {
    font-size: 20px;
    color: #4f46e5 !important;
}
.cmms-onb-payment-disclaimer {
    text-align: center;
    margin-top: 16px;
    font-size: 12px;
    color: #64748b;
}
.cmms-onb-payment-manual-note {
    text-align: center;
    color: #475569;
    font-size: 14px;
    margin: 20px 0 22px;
    padding: 16px;
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 10px;
    line-height: 1.6;
}

/* ================================================================
   Premium desktop refinements for the Pricing step (1.14.28).
   Goal: feel like a real SaaS pricing page on large screens — wide
   container, larger cards, more breathing room, bigger typography.
   Mobile is untouched; these all live inside min-width media queries.
   ================================================================ */

/* Hero gets significantly larger on desktop — true hero presence */
@media (min-width: 900px) {
    .cmms-onb-wrap.is-step-1 {
        padding: 56px 32px 80px;
    }
    .cmms-onb-wrap.is-step-1 .cmms-onb-hero {
        text-align: center;
        margin-bottom: 36px;
    }
    .cmms-onb-wrap.is-step-1 .cmms-onb-hero h1 {
        font-size: 44px;
        line-height: 1.15;
        max-width: 760px;
        margin: 0 auto 14px;
        letter-spacing: -0.02em;
    }
    .cmms-onb-wrap.is-step-1 .cmms-onb-hero p {
        font-size: 17px;
        max-width: 580px;
        margin: 0 auto;
    }
    /* Center the cycle toggle in the bigger container */
    .cmms-onb-wrap.is-step-1 .cmms-onb-cycle-toggle {
        margin-bottom: 40px;
    }

    /* Pricing grid: 3 columns with generous gaps */
    .cmms-onb-plans {
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        align-items: stretch;
    }
    .cmms-onb-plan {
        padding: 36px 32px;
        border-radius: 20px;
    }
    /* The recommended plan visually pops: slightly taller, stronger shadow,
       sits above the others. This is the "Most Popular" emphasis pattern
       used by Monday, Fireberry, ClickUp. */
    .cmms-onb-plan.is-recommended {
        transform: translateY(-12px);
        border-width: 2px;
        box-shadow: 0 16px 40px rgba(79,70,229,0.18);
    }
    .cmms-onb-plan.is-recommended:hover {
        transform: translateY(-14px);
    }
    .cmms-onb-plan-badge {
        top: -14px;
        font-size: 12px;
        padding: 6px 16px;
        inset-inline-start: 32px;
    }
    .cmms-onb-plan-name { font-size: 26px; margin-bottom: 6px; }
    .cmms-onb-plan-tagline { font-size: 14px; min-height: 42px; }
    .cmms-onb-plan-price { margin-bottom: 28px; }
    .cmms-onb-price-amount { font-size: 42px; }
    .cmms-onb-price-suffix { font-size: 15px; }
    .cmms-onb-plan-features { margin-bottom: 32px; }
    .cmms-onb-plan-features li { font-size: 14.5px; padding: 8px 0; }
    .cmms-onb-plan-cta {
        height: 52px;
        line-height: 52px;
        font-size: 16px;
        border-radius: 12px;
    }
}

/* Extra-wide screens (1280px+) get even more space */
@media (min-width: 1280px) {
    .cmms-onb-wrap.is-step-1 .cmms-onb-hero h1 {
        font-size: 48px;
    }
    .cmms-onb-plans { gap: 28px; }
}
</style>
</head>
<body>
<div class="cmms-onb-root">

    <!-- Topbar: brand mark + login link -->
    <header class="cmms-onb-top">
        <a class="cmms-onb-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
            <?php if ( $brand_logo ) : ?>
                <img src="<?php echo esc_url( $brand_logo ); ?>" alt="">
            <?php else : ?>
                <span class="cmms-onb-brand-mark"><?php echo esc_html( mb_substr( $brand_name, 0, 1 ) ); ?></span>
            <?php endif; ?>
            <span><?php echo esc_html( $brand_name ); ?></span>
        </a>
        <a class="cmms-onb-login-link" href="<?php echo esc_url( $login_url ); ?>">יש לי כבר חשבון</a>
    </header>

    <main class="cmms-onb-wrap is-step-<?php echo (int) $step; ?>">

        <!-- 6-step progress indicator. Step 1 active for now; the others
             still render greyed so users see the journey ahead.
             1.14.27: step semantics changed — step 1 = Pricing,
             step 2 = Create Account, steps 3-6 are placeholders for
             company setup / payment / workspace / dashboard. -->
        <?php
        // Compute dot states. Dots before $step = done, $step itself = active, rest = pending.
        $step_labels = array(
            1 => 'בחירת חבילה',
            2 => 'פרטי חשבון',
            3 => 'פרטי חברה',
            4 => 'תשלום',
            5 => 'הקמת סביבת עבודה',
            6 => 'התחלת עבודה',
        );
        ?>
        <div class="cmms-onb-progress" aria-label="התקדמות הרשמה">
            <?php for ( $i = 1; $i <= 6; $i++ ) :
                $cls = 'cmms-onb-progress-dot';
                if ( $i < $step ) $cls .= ' is-done';
                if ( $i === $step ) $cls .= ' is-active';
            ?>
                <span class="<?php echo esc_attr( $cls ); ?>"></span>
            <?php endfor; ?>
        </div>
        <div class="cmms-onb-step-label">שלב <?php echo (int) $step; ?> מתוך 6 — <?php echo esc_html( $step_labels[ $step ] ?? '' ); ?></div>

        <?php if ( $step === 1 ) : /* === STEP 1: PRICING === */ ?>

        <section class="cmms-onb-hero">
            <h1>בחר את התוכנית שמתאימה לעסק שלך</h1>
            <p>תוכל לשנות תוכנית בכל עת. ללא התחייבות לתקופה.</p>
        </section>

        <!-- Billing cycle toggle: monthly / yearly with savings hint -->
        <div class="cmms-onb-cycle-toggle" role="tablist" aria-label="מחזור חיוב">
            <button type="button" role="tab" class="cmms-onb-cycle-btn <?php echo $selected_cycle === 'monthly' ? 'is-active' : ''; ?>" data-cycle="monthly">חודשי</button>
            <button type="button" role="tab" class="cmms-onb-cycle-btn <?php echo $selected_cycle === 'yearly' ? 'is-active' : ''; ?>" data-cycle="yearly">
                שנתי
                <span class="cmms-onb-cycle-save">חסכון ~17%</span>
            </button>
        </div>

        <!-- Pricing cards -->
        <div class="cmms-onb-plans">
            <?php foreach ( $plans as $plan ) :
                $plan_id = $plan['id'];
                $is_recommended = ! empty( $plan['recommended'] );
                $is_selected = ( $plan_id === $selected_plan );
                $next_url = add_query_arg( array(
                    'step'  => 2,
                    'plan'  => $plan_id,
                    'cycle' => $selected_cycle,
                ), home_url( '/start/' ) );
            ?>
                <article class="cmms-onb-plan <?php echo $is_recommended ? 'is-recommended' : ''; ?> <?php echo $is_selected ? 'is-selected' : ''; ?> <?php echo $selected_cycle === 'yearly' ? 'is-cycle-yearly' : ''; ?>"
                         data-plan="<?php echo esc_attr( $plan_id ); ?>">
                    <?php if ( $is_recommended ) : ?>
                        <div class="cmms-onb-plan-badge">המומלץ</div>
                    <?php endif; ?>
                    <div class="cmms-onb-plan-head">
                        <h3 class="cmms-onb-plan-name"><?php echo esc_html( $plan['name'] ); ?></h3>
                        <p class="cmms-onb-plan-tagline"><?php echo esc_html( $plan['tagline'] ); ?></p>
                    </div>

                    <!-- Price block: two prices, only one visible at a time per cycle -->
                    <div class="cmms-onb-plan-price">
                        <?php if ( $plan['custom_price'] ) : ?>
                            <div class="cmms-onb-price-line" data-price-for="monthly">
                                <span class="cmms-onb-price-prefix">החל מ־</span>
                                <span class="cmms-onb-price-amount">₪<?php echo number_format( $plan['price_monthly'] ); ?></span>
                                <span class="cmms-onb-price-suffix">/ חודש</span>
                            </div>
                            <div class="cmms-onb-price-line" data-price-for="yearly">
                                <span class="cmms-onb-price-prefix">החל מ־</span>
                                <span class="cmms-onb-price-amount">₪<?php echo number_format( $plan['price_yearly'] ); ?></span>
                                <span class="cmms-onb-price-suffix">/ שנה</span>
                            </div>
                        <?php else : ?>
                            <div class="cmms-onb-price-line" data-price-for="monthly">
                                <span class="cmms-onb-price-amount">₪<?php echo number_format( $plan['price_monthly'] ); ?></span>
                                <span class="cmms-onb-price-suffix">/ חודש</span>
                            </div>
                            <div class="cmms-onb-price-line" data-price-for="yearly">
                                <span class="cmms-onb-price-amount">₪<?php echo number_format( $plan['price_yearly'] ); ?></span>
                                <span class="cmms-onb-price-suffix">/ שנה</span>
                                <div class="cmms-onb-price-per-month">≈ ₪<?php echo number_format( CMMS_Plans::yearly_per_month( $plan_id ) ); ?> לחודש</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <ul class="cmms-onb-plan-features">
                        <?php foreach ( $plan['features'] as $feature ) : ?>
                            <li>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                <span><?php echo esc_html( $feature ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <a href="<?php echo esc_url( $next_url ); ?>"
                       class="cmms-onb-plan-cta <?php echo $is_recommended ? 'is-primary' : ''; ?>"
                       data-plan-cta="<?php echo esc_attr( $plan_id ); ?>">
                        <?php echo $plan['custom_price'] ? 'צור קשר' : 'המשך עם ' . esc_html( $plan['name'] ); ?>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>

        <p class="cmms-onb-secondary" style="margin-top:28px;">
            כל המחירים בש"ח ולא כוללים מע"מ. תוכל לבטל בכל עת.
        </p>

        <?php elseif ( $step === 2 ) : /* === STEP 2: CREATE ACCOUNT === */ ?>

        <section class="cmms-onb-hero">
            <h1>פתח חשבון חדש</h1>
            <p>פרטי הבעלים — תוכל להוסיף משתמשים נוספים אחרי שתיכנס.</p>
        </section>

        <!-- 1.14.27: chosen-plan summary card. Shows the selected plan
             above the form so the user remembers what they're signing up
             for and has a clear way to change their mind. -->
        <?php if ( $current_plan ) :
            $cycle_label = $selected_cycle === 'yearly' ? 'שנתי' : 'חודשי';
            $price_display = $current_plan['custom_price']
                ? 'מחיר מותאם'
                : ( $selected_cycle === 'yearly'
                    ? '₪' . number_format( $current_plan['price_yearly'] ) . ' / שנה'
                    : '₪' . number_format( $current_plan['price_monthly'] ) . ' / חודש' );
            $change_url = add_query_arg( array(
                'step'  => 1,
                'plan'  => $selected_plan,
                'cycle' => $selected_cycle,
            ), home_url( '/start/' ) );
        ?>
        <div class="cmms-onb-plan-summary">
            <div class="cmms-onb-plan-summary-info">
                <span class="cmms-onb-plan-summary-name"><?php echo esc_html( $current_plan['name'] ); ?></span>
                <span class="cmms-onb-plan-summary-sep">·</span>
                <span class="cmms-onb-plan-summary-cycle"><?php echo esc_html( $cycle_label ); ?></span>
                <span class="cmms-onb-plan-summary-sep">·</span>
                <span class="cmms-onb-plan-summary-price"><?php echo esc_html( $price_display ); ?></span>
            </div>
            <a class="cmms-onb-plan-summary-change" href="<?php echo esc_url( $change_url ); ?>">שינוי תוכנית</a>
        </div>
        <?php endif; ?>

        <article class="cmms-onb-card">

            <!-- Top-level error banner for cases the field-level errors don't cover -->
            <div class="cmms-onb-banner" id="cmms-onb-banner" role="alert"></div>

            <form id="cmms-onb-form" novalidate autocomplete="on">
                <input type="hidden" name="cmms_onboarding_nonce" value="<?php echo esc_attr( $nonce ); ?>">
                <!-- 1.14.27: chosen plan + billing cycle carried into the
                     account-creation submission. These come from the URL
                     params on this page load and persist into the DB on
                     successful signup. -->
                <input type="hidden" name="plan" id="onb-plan" value="<?php echo esc_attr( $selected_plan ); ?>">
                <input type="hidden" name="cycle" id="onb-cycle" value="<?php echo esc_attr( $selected_cycle ); ?>">

                <!-- Company name -->
                <div class="cmms-onb-field" data-field="company">
                    <label class="cmms-onb-field-label" for="onb-company">שם החברה</label>
                    <div class="cmms-onb-input-wrap">
                        <input class="cmms-onb-input" type="text" id="onb-company" name="company"
                               placeholder="לדוגמה: פרומוטרס אינטראקטיב" autocomplete="organization" required>
                    </div>
                    <div class="cmms-onb-error" data-error="company"></div>
                </div>

                <!-- Full name -->
                <div class="cmms-onb-field" data-field="name">
                    <label class="cmms-onb-field-label" for="onb-name">שם מלא</label>
                    <div class="cmms-onb-input-wrap">
                        <input class="cmms-onb-input" type="text" id="onb-name" name="name"
                               placeholder="שם פרטי ומשפחה" autocomplete="name" required>
                    </div>
                    <div class="cmms-onb-error" data-error="name"></div>
                </div>

                <!-- Phone -->
                <div class="cmms-onb-field" data-field="phone">
                    <label class="cmms-onb-field-label" for="onb-phone">טלפון נייד</label>
                    <div class="cmms-onb-input-wrap">
                        <input class="cmms-onb-input" type="tel" id="onb-phone" name="phone"
                               placeholder="050-1234567" autocomplete="tel" inputmode="tel" required>
                    </div>
                    <div class="cmms-onb-error" data-error="phone"></div>
                </div>

                <!-- Email -->
                <div class="cmms-onb-field" data-field="email">
                    <label class="cmms-onb-field-label" for="onb-email">אימייל</label>
                    <div class="cmms-onb-input-wrap">
                        <input class="cmms-onb-input" type="email" id="onb-email" name="email"
                               placeholder="name@company.com" autocomplete="email"
                               inputmode="email" dir="ltr" style="text-align:start" required>
                    </div>
                    <div class="cmms-onb-error" data-error="email"></div>
                </div>

                <!-- Password with reveal -->
                <div class="cmms-onb-field" data-field="password">
                    <label class="cmms-onb-field-label" for="onb-password">סיסמה</label>
                    <div class="cmms-onb-input-wrap">
                        <input class="cmms-onb-input cmms-onb-input--pw" type="password" id="onb-password"
                               name="password" placeholder="לפחות 8 תווים, אנגלית וספרות"
                               autocomplete="new-password" dir="ltr" style="text-align:start"
                               required minlength="8">
                        <button type="button" class="cmms-onb-pw-toggle" aria-label="הצג/הסתר סיסמה" data-toggle-pw>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round" data-icon-eye>
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <div class="cmms-onb-strength" id="onb-strength" aria-hidden="true">
                        <span class="cmms-onb-strength-seg"></span>
                        <span class="cmms-onb-strength-seg"></span>
                        <span class="cmms-onb-strength-seg"></span>
                    </div>
                    <div class="cmms-onb-hint">8 תווים לפחות, אותיות באנגלית וספרות</div>
                    <div class="cmms-onb-error" data-error="password"></div>
                </div>

                <!-- Terms checkbox with real links to the legal pages. -->
                <label class="cmms-onb-terms" data-field="terms">
                    <input type="checkbox" id="onb-terms" name="terms">
                    <span>
                        אני מאשר את
                        <a href="<?php echo esc_url( home_url( '/terms-of-service/' ) ); ?>" target="_blank" rel="noopener">תנאי השירות</a>
                        ו<a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>" target="_blank" rel="noopener">מדיניות הפרטיות</a>
                    </span>
                </label>
                <div class="cmms-onb-error" data-error="terms"></div>

                <!-- Primary CTA -->
                <button type="submit" class="cmms-onb-cta" id="cmms-onb-submit">
                    <span class="cmms-onb-cta-row">
                        <span class="cmms-onb-cta-label">המשך</span>
                        <span class="cmms-onb-spinner" aria-hidden="true"></span>
                    </span>
                </button>

                <p class="cmms-onb-secondary">
                    יש לך כבר חשבון? <a href="<?php echo esc_url( $login_url ); ?>">התחבר</a>
                </p>
            </form>
        </article>

        <?php else : /* === STEP 3: PAYMENT === */
            // Resolve the specific package row so we know whether iCredit
            // is configured for this plan/cycle, plus the exact price.
            $payment_package = CMMS_Plans::get_package( $selected_plan, $selected_cycle );
            $payment_nonce   = wp_create_nonce( 'cmms_payment' );
            $payment_failed  = ! empty( $_GET['payment'] ) && $_GET['payment'] === 'failed';
            // 1.14.38: removed the icredit_page_id requirement. GetUrl
            // creates an ad-hoc sale and doesn't need a pre-configured
            // page id. The field stays in the schema for future use
            // (e.g. switching to subscription mode with pre-built pages).
            $payment_ready = (
                $payment_package
                && (float) $payment_package['price'] > 0
                && CMMS_Icredit::is_configured()
            );
            // If the package itself is custom-priced (Enterprise) we
            // show the "contact us" message regardless of icredit config.
            $is_custom_priced = $payment_package && ! empty( $payment_package['custom_price'] );

            $cycle_label = $selected_cycle === 'yearly' ? 'שנתי' : 'חודשי';
            $price_display = $payment_package
                ? ( $is_custom_priced ? 'מחיר מותאם' : '₪' . number_format( (float) $payment_package['price'] ) )
                : '';
        ?>

        <section class="cmms-onb-hero">
            <h1>השלמת תשלום</h1>
            <p>תשלום מאובטח דרך iCredit. תוכל לבטל את החיוב בכל עת.</p>
        </section>

        <?php if ( $payment_failed ) : ?>
        <div class="cmms-onb-banner is-visible" style="margin-bottom:20px;">
            התשלום לא הושלם. ניתן לנסות שוב או לפנות לתמיכה.
        </div>
        <?php endif; ?>

        <article class="cmms-onb-card cmms-onb-payment-card">
            <?php if ( ! $payment_package ) : ?>
                <p>החבילה הנבחרת אינה זמינה.</p>
                <a href="<?php echo esc_url( home_url( '/start/' ) ); ?>" class="cmms-onb-cta cmms-onb-cta-row" style="text-decoration:none;display:flex;align-items:center;justify-content:center;color:#fff;">חזרה לבחירת חבילה</a>

            <?php elseif ( $is_custom_priced || ! $payment_ready ) : ?>
                <!-- Custom-priced (Enterprise) OR iCredit not configured
                     for this package — show "contact us" path in pure
                     Hebrew. The exact wording is the one approved in the
                     1.14.36 brief: "התשלום לחבילה זו עדיין לא הוגדר..." -->
                <div class="cmms-onb-payment-summary">
                    <div class="cmms-onb-payment-summary-line">
                        <span>חבילה</span>
                        <strong><?php echo esc_html( $payment_package['display_name'] ); ?></strong>
                    </div>
                    <div class="cmms-onb-payment-summary-line">
                        <span>מחזור חיוב</span>
                        <strong><?php echo esc_html( $cycle_label ); ?></strong>
                    </div>
                    <div class="cmms-onb-payment-summary-line cmms-onb-payment-summary-total">
                        <span>סכום</span>
                        <strong><?php echo esc_html( $price_display ); ?></strong>
                    </div>
                </div>
                <p class="cmms-onb-payment-manual-note">
                    התשלום לחבילה זו עדיין לא הוגדר. ניתן לפנות אלינו להשלמת פתיחת החשבון.
                </p>
                <a href="<?php echo esc_url( home_url( '/cmms-dashboard/' ) ); ?>"
                   class="cmms-onb-cta"
                   style="text-decoration:none;display:flex;align-items:center;justify-content:center;">
                    <span class="cmms-onb-cta-row">
                        <span class="cmms-onb-cta-label">המשך לדאשבורד</span>
                    </span>
                </a>

            <?php else : ?>
                <!-- Standard payment flow: summary card + button that
                     calls cmms_payment_init via AJAX. On success the
                     browser is redirected to the iCredit hosted page. -->
                <div class="cmms-onb-payment-summary">
                    <div class="cmms-onb-payment-summary-line">
                        <span>חבילה</span>
                        <strong><?php echo esc_html( $payment_package['display_name'] ); ?></strong>
                    </div>
                    <div class="cmms-onb-payment-summary-line">
                        <span>מחזור חיוב</span>
                        <strong><?php echo esc_html( $cycle_label ); ?></strong>
                    </div>
                    <?php if ( ! empty( $payment_package['max_users'] ) ) : ?>
                    <div class="cmms-onb-payment-summary-line">
                        <span>משתמשים בחבילה</span>
                        <strong>עד <?php echo (int) $payment_package['max_users']; ?></strong>
                    </div>
                    <?php endif; ?>
                    <div class="cmms-onb-payment-summary-line cmms-onb-payment-summary-total">
                        <span>סכום לתשלום</span>
                        <strong><?php echo esc_html( $price_display ); ?></strong>
                    </div>
                </div>

                <form id="cmms-onb-payment-form" novalidate>
                    <input type="hidden" name="cmms_payment_nonce" value="<?php echo esc_attr( $payment_nonce ); ?>">
                    <input type="hidden" name="plan" value="<?php echo esc_attr( $selected_plan ); ?>">
                    <input type="hidden" name="cycle" value="<?php echo esc_attr( $selected_cycle ); ?>">

                    <div class="cmms-onb-banner" id="cmms-onb-payment-banner" role="alert" style="display:none;"></div>

                    <button type="submit" class="cmms-onb-cta" id="cmms-onb-payment-submit">
                        <span class="cmms-onb-cta-row">
                            <span class="cmms-onb-cta-label">המשך לתשלום מאובטח</span>
                            <span class="cmms-onb-spinner" aria-hidden="true"></span>
                        </span>
                    </button>
                </form>

                <p class="cmms-onb-payment-disclaimer">
                    🔒 תועבר לעמוד תשלום מאובטח של iCredit. פרטי האשראי שלך לא נשמרים אצלנו.
                </p>
            <?php endif; ?>
        </article>

        <?php endif; /* end step branching */ ?>

        <div class="cmms-onb-trust">
            🔒 <strong>הנתונים שלך מוצפנים</strong> · עומדת בתקני אבטחה · ללא התחייבות
        </div>

    </main>
</div>

<script>
/* ================================================================
   Onboarding wizard JS (vanilla, no deps). Lives inline so the page
   has zero external script requirements — important for time-to-
   interactive on slow mobile connections.

   Responsibilities:
     1. Field-by-field validation as the user types (debounced).
     2. Submit with fetch() to cmms_onboarding_step1 — no page reload.
     3. Map server-returned field errors back to the right inputs.
     4. Password reveal toggle + strength indicator.
     5. Disable CTA until basic shape is valid.
   ================================================================ */
(function () {
    'use strict';

    var form     = document.getElementById('cmms-onb-form');
    // 1.14.31 fix: if the form isn't on this page (we're on Step 1 /
    // Pricing where there is no account form), exit cleanly. Without
    // this guard, the next line crashes with "Cannot read properties
    // of null (reading 'querySelector')" and every script after this
    // IIFE — including the cycle toggle — never runs.
    if (!form) return;
    var submit   = document.getElementById('cmms-onb-submit');
    var banner   = document.getElementById('cmms-onb-banner');
    var pwInput  = document.getElementById('onb-password');
    var pwStr    = document.getElementById('onb-strength');
    var adminPost = '<?php echo $admin_post; // phpcs-safe: pre-escaped via esc_url() above ?>';

    /* --- field helpers --- */
    function field(name) { return form.querySelector('[data-field="' + name + '"]'); }
    function errorEl(name) { return form.querySelector('[data-error="' + name + '"]'); }
    function clearError(name) {
        var f = field(name);
        if (!f) return;
        f.classList.remove('has-error');
        var input = f.querySelector('input');
        if (input) input.classList.remove('has-error');
        var e = errorEl(name);
        if (e) e.textContent = '';
    }
    function showError(name, message) {
        var f = field(name);
        if (!f) return;
        f.classList.add('has-error');
        var input = f.querySelector('input');
        if (input) input.classList.add('has-error');
        var e = errorEl(name);
        if (e) e.textContent = message;
    }
    function clearAllErrors() {
        ['company','name','phone','email','password','terms'].forEach(clearError);
        banner.classList.remove('is-visible');
        banner.textContent = '';
    }

    /* --- password reveal --- */
    var pwToggle = form.querySelector('[data-toggle-pw]');
    if (pwToggle) {
        pwToggle.addEventListener('click', function () {
            var t = pwInput.getAttribute('type');
            pwInput.setAttribute('type', t === 'password' ? 'text' : 'password');
            pwInput.focus();
        });
    }

    /* --- password strength indicator --- */
    function scorePassword(p) {
        if (!p || p.length < 4) return 0;
        var score = 0;
        if (p.length >= 8) score++;
        if (/[A-Za-z]/.test(p) && /[0-9]/.test(p)) score++;
        if (p.length >= 12 || (/[^A-Za-z0-9]/.test(p) && score >= 2)) score++;
        return score;
    }
    pwInput.addEventListener('input', function () {
        var s = scorePassword(pwInput.value);
        pwStr.classList.remove('is-weak', 'is-medium', 'is-strong');
        if (s === 1) pwStr.classList.add('is-weak');
        else if (s === 2) pwStr.classList.add('is-medium');
        else if (s >= 3) pwStr.classList.add('is-strong');
        if (pwInput.value.length > 0) clearError('password');
    });

    /* --- light client-side validation: clear error on input --- */
    ['onb-company','onb-name','onb-phone','onb-email'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function () {
            var name = el.name;
            if (el.value.trim() !== '') clearError(name);
        });
    });
    document.getElementById('onb-terms').addEventListener('change', function () {
        if (this.checked) clearError('terms');
    });

    /* --- submit --- */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (submit.classList.contains('is-loading')) return;
        clearAllErrors();

        // Very light client checks — the server is authoritative. We
        // just catch the obvious empty fields so users don't hit a
        // network round-trip for a blank form.
        var data = new FormData(form);
        var missing = [];
        ['company','name','phone','email','password'].forEach(function (k) {
            if (!String(data.get(k) || '').trim()) missing.push(k);
        });
        if (missing.length) {
            missing.forEach(function (k) {
                var msg = {
                    company:  'יש להזין את שם החברה.',
                    name:     'יש להזין שם מלא.',
                    phone:    'יש להזין מספר טלפון.',
                    email:    'יש להזין אימייל.',
                    password: 'יש להזין סיסמה.'
                }[k];
                showError(k, msg);
            });
            return;
        }
        if (!data.get('terms')) {
            showError('terms', 'יש לאשר את תנאי השימוש.');
            return;
        }

        submit.classList.add('is-loading');
        submit.disabled = true;

        var body = new URLSearchParams();
        body.set('action', 'cmms_onboarding_step1');
        body.set('cmms_onboarding_nonce', data.get('cmms_onboarding_nonce'));
        body.set('company',  data.get('company'));
        body.set('name',     data.get('name'));
        body.set('phone',    data.get('phone'));
        body.set('email',    data.get('email'));
        body.set('password', data.get('password'));
        body.set('terms',    data.get('terms') ? '1' : '');
        // 1.14.27: carry the chosen plan + cycle into the signup so the
        // server can persist them onto the new account row.
        body.set('plan',  data.get('plan')  || '');
        body.set('cycle', data.get('cycle') || '');

        fetch(adminPost, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json().then(function (json) { return { ok: r.ok, json: json }; }); })
        .then(function (res) {
            if (res.ok && res.json && res.json.success) {
                // Success — redirect into the system.
                window.location.href = res.json.data.redirect || '/cmms-dashboard/';
                return;
            }
            var err = (res.json && res.json.data) || {};
            if (err.field) {
                showError(err.field, err.message || 'אירעה שגיאה.');
                var el = document.getElementById('onb-' + err.field);
                if (el && el.focus) el.focus();
            } else {
                banner.textContent = err.message || 'אירעה שגיאה. נסה שוב בעוד רגע.';
                banner.classList.add('is-visible');
            }
            submit.classList.remove('is-loading');
            submit.disabled = false;
        })
        .catch(function () {
            banner.textContent = 'אירעה שגיאת רשת. בדוק את החיבור ונסה שוב.';
            banner.classList.add('is-visible');
            submit.classList.remove('is-loading');
            submit.disabled = false;
        });
    });
})();

/* ============================================================
   Pricing step JS (1.14.27)
   Only attaches when we're on step 1 (Pricing). Handles:
     - Billing cycle toggle (monthly / yearly) — switches visible prices
       in every plan card without a server round-trip.
     - Persisting the selected cycle to sessionStorage so a refresh
       restores the user's choice.
     - Pre-populating the cycle from sessionStorage if the URL doesn't
       carry it.
     - Updating the CTAs so clicking "Continue with X" lands on
       step 2 with the right ?cycle= URL param.
   ============================================================ */
(function () {
    'use strict';
    var STORAGE_KEY = 'cmms_onb_state';

    // Only run on step 1 (pricing). Detected via presence of .cmms-onb-plans.
    var plansEl = document.querySelector('.cmms-onb-plans');
    if (!plansEl) return;

    var cycleButtons = document.querySelectorAll('.cmms-onb-cycle-btn');
    var planCTAs = document.querySelectorAll('[data-plan-cta]');

    function loadState() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) { return {}; }
    }
    function saveState(patch) {
        try {
            var st = loadState();
            Object.keys(patch).forEach(function (k) { st[k] = patch[k]; });
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(st));
        } catch (e) {}
    }

    function getActiveCycle() {
        for (var i = 0; i < cycleButtons.length; i++) {
            if (cycleButtons[i].classList.contains('is-active')) return cycleButtons[i].getAttribute('data-cycle');
        }
        return 'monthly';
    }

    var planCards = document.querySelectorAll('.cmms-onb-plan');
    // Also grab every individual price line — we'll force its inline
    // display style as a final override. This is belt-and-braces in case
    // a theme's CSS uses higher specificity than ours and wins.
    var priceLines = document.querySelectorAll('.cmms-onb-price-line');

    function applyCycle(cycle) {
        // Update toggle button state
        cycleButtons.forEach(function (btn) {
            if (btn.getAttribute('data-cycle') === cycle) btn.classList.add('is-active');
            else btn.classList.remove('is-active');
        });
        // Toggle the cycle class on each plan card. The CSS handles the
        // actual price-line visibility based on this class.
        planCards.forEach(function (card) {
            if (cycle === 'yearly') card.classList.add('is-cycle-yearly');
            else card.classList.remove('is-cycle-yearly');
        });
        // Belt-and-braces: also set inline display on each price line so
        // that even if external CSS overrides our rules, the right line
        // wins. setProperty with 'important' beats all stylesheets.
        priceLines.forEach(function (line) {
            var which = line.getAttribute('data-price-for');
            if (which === cycle) {
                line.style.setProperty('display', 'flex', 'important');
            } else {
                line.style.setProperty('display', 'none', 'important');
            }
        });
        // Update CTA hrefs to carry the chosen cycle to step 2
        planCTAs.forEach(function (cta) {
            var href = cta.getAttribute('href');
            if (!href) return;
            try {
                var url = new URL(href, window.location.origin);
                url.searchParams.set('cycle', cycle);
                cta.setAttribute('href', url.toString());
            } catch (e) {}
        });
        saveState({ cycle: cycle });
    }

    // Initial sync. If URL has no cycle but sessionStorage does, use it.
    var urlParams = new URLSearchParams(window.location.search);
    var initialCycle = urlParams.get('cycle');
    if (!initialCycle) {
        var st = loadState();
        if (st.cycle === 'monthly' || st.cycle === 'yearly') initialCycle = st.cycle;
    }
    if (initialCycle === 'monthly' || initialCycle === 'yearly') {
        applyCycle(initialCycle);
    } else {
        // Default to whatever the server rendered as active.
        applyCycle(getActiveCycle());
    }

    // Wire up cycle buttons
    cycleButtons.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var cycle = btn.getAttribute('data-cycle');
            try { console.log('[CMMS] Cycle toggled to:', cycle, '— price lines updated:', priceLines.length); } catch (_) {}
            applyCycle(cycle);
        });
    });

    // Save chosen plan when user clicks a CTA — backup for step 2 in
    // case URL params get lost.
    planCTAs.forEach(function (cta) {
        cta.addEventListener('click', function () {
            saveState({
                plan:  cta.getAttribute('data-plan-cta'),
                cycle: getActiveCycle(),
            });
        });
    });
})();

/* ============================================================
   Step 2 hydration from sessionStorage (1.14.27)
   If the user lands on step 2 without plan/cycle in URL but has them
   in sessionStorage, refresh the URL silently so all server-rendered
   summary/CTAs reflect the correct state. This handles the case where
   someone bookmarks /start?step=2 or copies a URL without the params.
   ============================================================ */
(function () {
    'use strict';
    var form = document.getElementById('cmms-onb-form');
    if (!form) return;

    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('step') !== '2') return; // only on step 2

    try {
        var st = JSON.parse(sessionStorage.getItem('cmms_onb_state') || '{}');
        var hasUrlPlan  = urlParams.get('plan');
        var hasUrlCycle = urlParams.get('cycle');
        if (!hasUrlPlan && st.plan) {
            // Reload with the proper params so server-side rendering
            // shows the right summary card. One-shot guarded by sessionStorage
            // flag to avoid loops.
            if (!sessionStorage.getItem('cmms_onb_hydrated')) {
                sessionStorage.setItem('cmms_onb_hydrated', '1');
                urlParams.set('plan', st.plan);
                if (st.cycle) urlParams.set('cycle', st.cycle);
                window.location.replace(window.location.pathname + '?' + urlParams.toString());
                return;
            }
        }
        // Got here — either URL has params or no useful sessionStorage.
        // Clear the hydration guard for future visits.
        sessionStorage.removeItem('cmms_onb_hydrated');
    } catch (e) {}
})();

/* ============================================================
   Step 3 — Payment submission (1.14.36)
   Sends the form to cmms_payment_init, then redirects the browser
   to the iCredit URL returned by the server.
   ============================================================ */
(function () {
    'use strict';
    var form = document.getElementById('cmms-onb-payment-form');
    if (!form) return; // not on payment step

    var submit = document.getElementById('cmms-onb-payment-submit');
    var banner = document.getElementById('cmms-onb-payment-banner');
    var adminPost = '<?php echo $admin_post; // already esc_url() ?>';

    function showError(msg) {
        if (!banner) return;
        banner.textContent = msg || 'אירעה שגיאה. נסה שוב.';
        banner.style.display = 'block';
        banner.classList.add('is-visible');
    }
    function clearError() {
        if (!banner) return;
        banner.textContent = '';
        banner.style.display = 'none';
        banner.classList.remove('is-visible');
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (submit.classList.contains('is-loading')) return;
        clearError();
        submit.classList.add('is-loading');
        submit.disabled = true;

        var data = new FormData(form);
        var body = new URLSearchParams();
        body.set('action', 'cmms_payment_init');
        body.set('cmms_payment_nonce', data.get('cmms_payment_nonce'));
        body.set('plan',  data.get('plan'));
        body.set('cycle', data.get('cycle'));

        fetch(adminPost, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
        .then(function (res) {
            if (res.ok && res.json && res.json.success && res.json.data && res.json.data.redirect) {
                // Hand off to iCredit's hosted payment page.
                window.location.href = res.json.data.redirect;
                return;
            }
            var err = (res.json && res.json.data) || {};
            // 401 → session expired, send to login
            if (err.redirect) {
                window.location.href = err.redirect;
                return;
            }
            showError(err.message || 'לא הצלחנו לפתוח את עמוד התשלום. נסה שוב.');
            submit.classList.remove('is-loading');
            submit.disabled = false;
        })
        .catch(function () {
            showError('שגיאת רשת. בדוק את החיבור ונסה שוב.');
            submit.classList.remove('is-loading');
            submit.disabled = false;
        });
    });
})();
</script>

</body>
</html>
        <?php
        $html = ob_get_clean();
        // We bypass the WP theme entirely — onboarding must feel like a
        // standalone SaaS page, not a WordPress post. Echo the full doc
        // and exit so theme header/footer never wraps it.
        echo $html;
        exit;
    }

    /**
     * 1.14.47: Workspace Animation screen.
     *
     * Shown after a successful payment return from iCredit. Full-screen,
     * branded, mobile-first. Stays visible for ~5 seconds, then auto-
     * redirects to /cmms-dashboard/?welcome=1. Tap-to-skip behavior is
     * built into the JS: any click/keypress short-circuits the timer.
     *
     * During the visible time we ALSO perform real setup work server-
     * side via an async fetch — currently a no-op placeholder for
     * future seeding (default categories, sample tasks, etc.). Even
     * without server work, the animation gives the user a visible
     * "system is being prepared" moment, which materially improves the
     * perceived value of the SaaS at the highest-stakes moment of the
     * funnel (right after they paid).
     */
    private function render_workspace_animation() {
        // Resolve a few personalization bits from the logged-in user
        // (auto-login already happened during signup).
        $first_name   = '';
        $package_name = '';
        $cycle_label  = '';
        if ( is_user_logged_in() ) {
            $cu = CMMS_Auth::current_cmms_user();
            if ( $cu ) {
                $first_name = trim( (string) $cu->display_name );
                // Pull plan/cycle from accounts for the package label.
                global $wpdb;
                $accounts_t = CMMS_DB::table( 'accounts' );
                $acc = $wpdb->get_row( $wpdb->prepare(
                    "SELECT plan_type, billing_cycle, name FROM $accounts_t WHERE id = %d",
                    (int) $cu->account_id
                ), ARRAY_A );
                if ( $acc ) {
                    $cycle_label = ( $acc['billing_cycle'] === 'yearly' ) ? 'שנתי' : 'חודשי';
                    $pkg = CMMS_Plans::get_package( $acc['plan_type'], $acc['billing_cycle'] );
                    if ( $pkg ) $package_name = $pkg['display_name'];
                    elseif ( ! empty( $acc['plan_type'] ) ) $package_name = ucfirst( $acc['plan_type'] );
                }
            }
        }
        if ( $first_name === '' ) $first_name = 'לקוח';
        if ( $package_name === '' ) $package_name = 'CMMS Light';

        $brand_name = get_option( 'cmms_brand_name' ) ?: 'CMMS';
        // 1.14.48: cmms_anim_done=1 prevents the dashboard from looping
        // back here when it sees welcome=1.
        $dashboard_url = home_url( '/cmms-dashboard/?welcome=1&cmms_anim_done=1' );

        // Was this routed here as "processing" (IPN hadn't caught up
        // when the browser returned)? Subtle wording change, no logic
        // change — both paths still redirect to dashboard at end.
        $is_processing = isset( $_GET['payment'] ) && $_GET['payment'] === 'processing';

        // Don't get cached by anything between us and the user — this
        // page must run fresh every time.
        nocache_headers();
        show_admin_bar( false );

        ?><!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $brand_name ); ?> — מקימים את סביבת העבודה</title>
<link rel="icon" href="<?php echo esc_url( CMMS_LIGHT_URL . 'assets/images/icon-192.png' ); ?>">
<style>
/* ================================================================
   Workspace Animation (1.14.47). Fully self-contained — no external
   CSS, no theme inheritance. The whole experience is one screen.
================================================================ */
* { box-sizing: border-box; }
html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
}
body {
    font-family: Arial, "Heebo", "Segoe UI", sans-serif;
    color: #0f172a;
    background: radial-gradient(circle at 50% 0%, #fff7ed 0%, #f8fafc 50%, #f8fafc 100%);
    -webkit-font-smoothing: antialiased;
    line-height: 1.5;
}
.cmms-ws {
    position: fixed;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 32px 20px;
    cursor: pointer; /* Hints tap-to-skip without a visible button */
}
.cmms-ws-card {
    width: 100%;
    max-width: 460px;
    text-align: center;
}

/* ---------- Success check (animates in first) ---------- */
.cmms-ws-check {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    background: linear-gradient(135deg, #ff6a00 0%, #ff8a3d 100%);
    margin: 0 auto 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    box-shadow: 0 14px 40px rgba(255, 106, 0, 0.32);
    animation: cmms-ws-pop 0.55s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
.cmms-ws-check::before {
    content: "";
    position: absolute;
    inset: -8px;
    border-radius: 50%;
    border: 2px solid rgba(255, 106, 0, 0.25);
    animation: cmms-ws-pulse 2.4s ease-in-out infinite;
}
.cmms-ws-check svg {
    width: 42px;
    height: 42px;
    stroke: #fff;
    stroke-width: 3.4;
    stroke-linecap: round;
    stroke-linejoin: round;
    fill: none;
    stroke-dasharray: 40;
    stroke-dashoffset: 40;
    animation: cmms-ws-draw 0.5s 0.35s ease-out forwards;
}
@keyframes cmms-ws-pop {
    0%   { transform: scale(0.2); opacity: 0; }
    60%  { transform: scale(1.08); opacity: 1; }
    100% { transform: scale(1); opacity: 1; }
}
@keyframes cmms-ws-draw {
    to { stroke-dashoffset: 0; }
}
@keyframes cmms-ws-pulse {
    0%, 100% { transform: scale(1);   opacity: 0.6; }
    50%      { transform: scale(1.18); opacity: 0;   }
}

/* ---------- Headings & sub-copy ---------- */
.cmms-ws-greet {
    font-size: 22px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 6px;
    animation: cmms-ws-fade 0.5s 0.5s ease-out both;
}
.cmms-ws-pkg {
    font-size: 14px;
    color: #475569;
    margin: 0 0 24px;
    animation: cmms-ws-fade 0.5s 0.65s ease-out both;
}
.cmms-ws-pkg strong { color: #ff6a00; }
.cmms-ws-prep {
    font-size: 14px;
    color: #64748b;
    margin: 0 0 22px;
    animation: cmms-ws-fade 0.5s 0.8s ease-out both;
}
@keyframes cmms-ws-fade {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ---------- Progress steps ---------- */
.cmms-ws-steps {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px;
    text-align: right;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
    animation: cmms-ws-fade 0.5s 0.95s ease-out both;
}
.cmms-ws-step {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 9px 4px;
    font-size: 13.5px;
    color: #94a3b8;
    transition: color 0.3s ease;
    border-bottom: 1px dashed #f1f5f9;
}
.cmms-ws-step:last-child { border-bottom: 0; }
.cmms-ws-step.is-active { color: #0f172a; }
.cmms-ws-step.is-done   { color: #475569; }
.cmms-ws-step-icon {
    flex: 0 0 22px;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
}
/* Spinner for active step */
.cmms-ws-step-icon .cmms-ws-spin {
    width: 16px;
    height: 16px;
    border: 2px solid #e2e8f0;
    border-top-color: #ff6a00;
    border-radius: 50%;
    animation: cmms-ws-spin 0.8s linear infinite;
}
@keyframes cmms-ws-spin {
    to { transform: rotate(360deg); }
}
/* Filled circle for pending */
.cmms-ws-step-icon .cmms-ws-pending {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #cbd5e1;
}
/* Check for done */
.cmms-ws-step-icon .cmms-ws-tick {
    width: 18px;
    height: 18px;
    stroke: #16a34a;
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
    fill: none;
}

.cmms-ws-tap {
    margin-top: 24px;
    font-size: 12px;
    color: #94a3b8;
    animation: cmms-ws-fade 0.6s 1.6s ease-out both;
}

/* Mobile tightenings */
@media (max-width: 480px) {
    .cmms-ws-check { width: 72px; height: 72px; margin-bottom: 22px; }
    .cmms-ws-check svg { width: 36px; height: 36px; }
    .cmms-ws-greet { font-size: 20px; }
    .cmms-ws-steps { padding: 14px; }
}
</style>
</head>
<body>
<div class="cmms-ws" id="cmms-ws-root" role="status" aria-live="polite">
    <div class="cmms-ws-card">

        <div class="cmms-ws-check" aria-hidden="true">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>

        <h1 class="cmms-ws-greet">ברוך הבא, <?php echo esc_html( $first_name ); ?></h1>

        <?php if ( $is_processing ) : ?>
            <p class="cmms-ws-pkg">חבילת <strong><?php echo esc_html( $package_name ); ?> <?php echo esc_html( $cycle_label ); ?></strong> בעיבוד אחרון</p>
        <?php else : ?>
            <p class="cmms-ws-pkg">חבילת <strong><?php echo esc_html( $package_name ); ?> <?php echo esc_html( $cycle_label ); ?></strong> הופעלה בהצלחה</p>
        <?php endif; ?>

        <p class="cmms-ws-prep">אנחנו מכינים עבורך את סביבת העבודה...</p>

        <ol class="cmms-ws-steps" id="cmms-ws-steps">
            <li class="cmms-ws-step" data-step="account">
                <span class="cmms-ws-step-icon"><span class="cmms-ws-pending"></span></span>
                <span class="cmms-ws-step-label">יצירת חשבון</span>
            </li>
            <li class="cmms-ws-step" data-step="package">
                <span class="cmms-ws-step-icon"><span class="cmms-ws-pending"></span></span>
                <span class="cmms-ws-step-label">הגדרת חבילה</span>
            </li>
            <li class="cmms-ws-step" data-step="workspace">
                <span class="cmms-ws-step-icon"><span class="cmms-ws-pending"></span></span>
                <span class="cmms-ws-step-label">יצירת סביבת עבודה</span>
            </li>
            <li class="cmms-ws-step" data-step="ready">
                <span class="cmms-ws-step-icon"><span class="cmms-ws-pending"></span></span>
                <span class="cmms-ws-step-label">טעינת המערכת</span>
            </li>
        </ol>

        <div class="cmms-ws-tap">לחיצה במסך מעבירה ישירות לדאשבורד</div>
    </div>
</div>

<script>
(function () {
    var root = document.getElementById('cmms-ws-root');
    var stepsEl = document.getElementById('cmms-ws-steps');
    var stepNodes = stepsEl.querySelectorAll('.cmms-ws-step');
    var dashboardUrl = <?php echo wp_json_encode( $dashboard_url ); ?>;
    var totalMs = 5000;
    var redirected = false;

    function go() {
        if (redirected) return;
        redirected = true;
        // Mark all remaining steps as done for the visual moment
        // before the redirect kicks in. Costs nothing and avoids the
        // "still spinning" frame the user sees if the new page loads slow.
        stepNodes.forEach(function (n) { setStepDone(n); });
        location.replace(dashboardUrl);
    }

    function setStepActive(node) {
        node.classList.remove('is-done');
        node.classList.add('is-active');
        var icon = node.querySelector('.cmms-ws-step-icon');
        icon.innerHTML = '<span class="cmms-ws-spin"></span>';
    }
    function setStepDone(node) {
        node.classList.remove('is-active');
        node.classList.add('is-done');
        var icon = node.querySelector('.cmms-ws-step-icon');
        icon.innerHTML = '<svg class="cmms-ws-tick" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>';
    }

    // Sequence steps across the 5-second window. Times are tuned so
    // the perceived rhythm feels real, not fake-progress-bar pacing.
    //   t=200ms   step 1 active
    //   t=1200ms  step 1 done, step 2 active
    //   t=2400ms  step 2 done, step 3 active
    //   t=3600ms  step 3 done, step 4 active
    //   t=4700ms  step 4 done
    //   t=5000ms  redirect
    var seq = [
        { at:  200, action: function () { setStepActive(stepNodes[0]); } },
        { at: 1200, action: function () { setStepDone(stepNodes[0]); setStepActive(stepNodes[1]); } },
        { at: 2400, action: function () { setStepDone(stepNodes[1]); setStepActive(stepNodes[2]); } },
        { at: 3600, action: function () { setStepDone(stepNodes[2]); setStepActive(stepNodes[3]); } },
        { at: 4700, action: function () { setStepDone(stepNodes[3]); } }
    ];
    var timers = [];
    seq.forEach(function (s) {
        timers.push(setTimeout(s.action, s.at));
    });
    var redirectTimer = setTimeout(go, totalMs);

    // Tap / key to skip — cancel timers and jump straight to dashboard.
    function skip() {
        timers.forEach(function (t) { clearTimeout(t); });
        clearTimeout(redirectTimer);
        go();
    }
    root.addEventListener('click', skip);
    document.addEventListener('keydown', skip);
    document.addEventListener('touchend', skip);
})();
</script>
</body>
</html>
        <?php
    }

    public function render_signup( $atts = array() ) {
        ob_start();
        $errors = array();
        if ( isset( $_GET['cmms_err'] ) ) {
            $code = sanitize_text_field( wp_unslash( $_GET['cmms_err'] ) );
            $map = array(
                'invalid_email' => 'auth.invalid_email',
                'weak_pass'     => 'auth.weak_pass',
                'exists'        => 'auth.exists',
                'missing'       => 'auth.missing',
                'failed'        => 'auth.failed',
            );
            $errors[] = $this->t( $map[ $code ] ?? 'auth.failed' );
        }
        if ( is_user_logged_in() && CMMS_Auth::current_cmms_user() ) {
            wp_safe_redirect( home_url( '/cmms-dashboard/' ) );
            exit;
        }
        $dir = $this->dir();
        ?>
        <div class="cmms-auth-page" dir="<?php echo esc_attr( $dir ); ?>" lang="<?php echo esc_attr( $this->lang() ); ?>">
            <?php $this->lang_switcher(); ?>

            <aside class="cmms-auth-hero">
                <div class="cmms-auth-hero-inner">
                    <a href="#" class="cmms-auth-brand-mark">
                        <?php $this->brand_mark( 44 ); ?>
                        <?php $this->e( 'brand' ); ?>
                    </a>
                    <h1><?php $this->e( 'auth.hero_title' ); ?></h1>
                    <p><?php $this->e( 'auth.hero_sub' ); ?></p>
                    <ul class="cmms-auth-hero-features">
                        <li><?php CMMS_Icons::e( 'check-circle', 20 ); ?><span><?php $this->e( 'auth.feature_1' ); ?></span></li>
                        <li><?php CMMS_Icons::e( 'check-circle', 20 ); ?><span><?php $this->e( 'auth.feature_2' ); ?></span></li>
                        <li><?php CMMS_Icons::e( 'check-circle', 20 ); ?><span><?php $this->e( 'auth.feature_3' ); ?></span></li>
                        <li><?php CMMS_Icons::e( 'check-circle', 20 ); ?><span><?php $this->e( 'auth.feature_4' ); ?></span></li>
                    </ul>
                </div>
                <div class="cmms-auth-hero-foot"><?php $this->e( 'auth.hero_foot' ); ?></div>
            </aside>

            <main class="cmms-auth-form-side">
                <div class="cmms-auth-card">
                    <div class="cmms-auth-mobile-brand"><?php $this->brand_mark( 32 ); ?> <?php $this->e( 'brand' ); ?></div>
                    <h2><?php $this->e( 'auth.signup_title' ); ?></h2>
                    <p class="cmms-auth-card-sub"><?php $this->e( 'auth.signup_sub' ); ?></p>

                    <?php if ( $errors ) : ?>
                        <div class="cmms-alert cmms-alert-error">
                            <?php CMMS_Icons::e( 'alert-circle', 18 ); ?>
                            <span><?php echo esc_html( implode( '. ', $errors ) ); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form" novalidate>
                        <input type="hidden" name="action" value="cmms_signup">
                        <input type="hidden" name="cmms_lang" value="<?php echo esc_attr( $this->lang() ); ?>">
                        <?php wp_nonce_field( 'cmms_signup', 'cmms_signup_nonce' ); ?>

                        <div class="cmms-field">
                            <label class="cmms-field-label" for="cmms-org"><?php $this->e( 'auth.org' ); ?> <span class="req">*</span></label>
                            <input class="cmms-input" id="cmms-org" name="organization" type="text" required autocomplete="organization">
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label" for="cmms-name"><?php $this->e( 'auth.your_name' ); ?> <span class="req">*</span></label>
                            <input class="cmms-input" id="cmms-name" name="name" type="text" required autocomplete="name">
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label" for="cmms-email"><?php $this->e( 'auth.email' ); ?> <span class="req">*</span></label>
                            <input class="cmms-input" id="cmms-email" name="email" type="email" required autocomplete="email">
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label" for="cmms-phone"><?php $this->e( 'auth.phone' ); ?></label>
                            <input class="cmms-input" id="cmms-phone" name="phone" type="tel" autocomplete="tel">
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label" for="cmms-pass"><?php $this->e( 'auth.password' ); ?> <span class="req">*</span></label>
                            <input class="cmms-input" id="cmms-pass" name="password" type="password" minlength="6" required autocomplete="new-password">
                            <span class="cmms-field-help"><?php $this->e( 'auth.password_help' ); ?></span>
                        </div>

                        <button type="submit" class="cmms-btn cmms-btn-primary cmms-btn-block cmms-btn-lg">
                            <?php $this->e( 'auth.signup_btn' ); ?>
                            <?php CMMS_Icons::e( $this->dir() === 'rtl' ? 'arrow-left' : 'arrow-right', 18 ); ?>
                        </button>

                        <p class="cmms-text-sm cmms-muted cmms-text-center cmms-mt-4">
                            <?php $this->e( 'auth.have_account' ); ?>
                            <a href="<?php echo esc_url( home_url( '/cmms-login/' ) ); ?>"><?php $this->e( 'auth.login_link' ); ?></a>
                        </p>
                    </form>
                </div>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       LOGIN
    ============================================================ */
    public function render_login( $atts = array() ) {
        ob_start();
        $error = '';
        if ( isset( $_GET['reason'] ) && $_GET['reason'] === 'inactive' ) {
            $error = $this->t( 'auth.inactive' );
            if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
                wp_logout();
            }
        }
        if ( isset( $_GET['cmms_err'] ) ) {
            $code = sanitize_text_field( wp_unslash( $_GET['cmms_err'] ) );
            if ( $code === 'invalid' ) $error = $this->t( 'auth.invalid' );
            elseif ( $code === 'inactive' ) $error = $this->t( 'auth.inactive' );
        }
        if ( is_user_logged_in() && CMMS_Auth::current_cmms_user() ) {
            wp_safe_redirect( home_url( '/cmms-dashboard/' ) );
            exit;
        }
        $dir = $this->dir();
        ?>
        <div class="cmms-auth-page" dir="<?php echo esc_attr( $dir ); ?>" lang="<?php echo esc_attr( $this->lang() ); ?>">
            <?php $this->lang_switcher(); ?>

            <aside class="cmms-auth-hero">
                <div class="cmms-auth-hero-inner">
                    <a href="#" class="cmms-auth-brand-mark">
                        <?php $this->brand_mark( 44 ); ?>
                        <?php $this->e( 'brand' ); ?>
                    </a>
                    <h1><?php $this->e( 'auth.hero_title_login' ); ?></h1>
                    <p><?php $this->e( 'auth.hero_sub_login' ); ?></p>
                </div>
                <div class="cmms-auth-hero-foot"><?php $this->e( 'auth.hero_foot' ); ?></div>
            </aside>

            <main class="cmms-auth-form-side">
                <div class="cmms-auth-card">
                    <div class="cmms-auth-mobile-brand"><?php $this->brand_mark( 32 ); ?> <?php $this->e( 'brand' ); ?></div>
                    <h2><?php $this->e( 'auth.login_title' ); ?></h2>
                    <p class="cmms-auth-card-sub"><?php $this->e( 'auth.login_sub' ); ?></p>

                    <?php if ( $error ) : ?>
                        <div class="cmms-alert cmms-alert-error">
                            <?php CMMS_Icons::e( 'alert-circle', 18 ); ?>
                            <span><?php echo esc_html( $error ); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form" novalidate>
                        <input type="hidden" name="action" value="cmms_login">
                        <input type="hidden" name="cmms_lang" value="<?php echo esc_attr( $this->lang() ); ?>">
                        <?php wp_nonce_field( 'cmms_login', 'cmms_login_nonce' ); ?>

                        <div class="cmms-field">
                            <label class="cmms-field-label" for="cmms-login-email"><?php $this->e( 'auth.email' ); ?></label>
                            <input class="cmms-input" id="cmms-login-email" name="email" type="email" required autocomplete="email" placeholder="name@example.com">
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label" for="cmms-login-pass"><?php $this->e( 'auth.password' ); ?></label>
                            <input class="cmms-input" id="cmms-login-pass" name="password" type="password" required autocomplete="current-password">
                        </div>

                        <button type="submit" class="cmms-btn cmms-btn-primary cmms-btn-block cmms-btn-lg">
                            <?php $this->e( 'auth.login_btn' ); ?>
                            <?php CMMS_Icons::e( $this->dir() === 'rtl' ? 'arrow-left' : 'arrow-right', 18 ); ?>
                        </button>

                        <p class="cmms-text-sm cmms-text-center cmms-mt-4" style="margin-top:14px;">
                            <a href="<?php echo esc_url( home_url( '/cmms-forgot/' ) ); ?>" style="color:#64748b;text-decoration:none;">שכחת סיסמה?</a>
                        </p>

                        <p class="cmms-text-sm cmms-muted cmms-text-center cmms-mt-4">
                            <?php $this->e( 'auth.no_account' ); ?>
                            <a href="<?php echo esc_url( home_url( '/cmms-signup/' ) ); ?>"><?php $this->e( 'auth.signup_link' ); ?></a>
                        </p>
                    </form>
                </div>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       1.14.61: FORGOT PASSWORD page
       Single field (email/username), one button. Always shows the
       same success message regardless of whether the user exists,
       to prevent enumeration.
    ============================================================ */
    public function render_forgot( $atts = array() ) {
        ob_start();
        if ( is_user_logged_in() && CMMS_Auth::current_cmms_user() ) {
            wp_safe_redirect( home_url( '/cmms-dashboard/' ) );
            exit;
        }
        $dir = $this->dir();
        $nonce = wp_create_nonce( 'cmms_forgot_password' );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>
        <div class="cmms-auth-page" dir="<?php echo esc_attr( $dir ); ?>" lang="<?php echo esc_attr( $this->lang() ); ?>">
            <?php $this->lang_switcher(); ?>

            <aside class="cmms-auth-hero">
                <div class="cmms-auth-hero-inner">
                    <a href="#" class="cmms-auth-brand-mark">
                        <?php $this->brand_mark( 44 ); ?>
                        <?php $this->e( 'brand' ); ?>
                    </a>
                    <h1>שכחת סיסמה?</h1>
                    <p>אין דאגה. נשלח לך אימייל עם קישור לאיפוס.</p>
                </div>
                <div class="cmms-auth-hero-foot"><?php $this->e( 'auth.hero_foot' ); ?></div>
            </aside>

            <main class="cmms-auth-form-side">
                <div class="cmms-auth-card">
                    <div class="cmms-auth-mobile-brand"><?php $this->brand_mark( 32 ); ?> <?php $this->e( 'brand' ); ?></div>
                    <h2>איפוס סיסמה</h2>
                    <p class="cmms-auth-card-sub">הזן את כתובת האימייל שלך ונשלח אליה קישור לאיפוס סיסמה.</p>

                    <div id="cmms-forgot-msg" style="display:none;margin-bottom:14px;"></div>

                    <form id="cmms-forgot-form" class="cmms-form" novalidate>
                        <div class="cmms-field">
                            <label class="cmms-field-label" for="cmms-forgot-id">אימייל</label>
                            <input class="cmms-input" id="cmms-forgot-id" name="identifier" type="email" required autocomplete="email" placeholder="name@example.com">
                        </div>

                        <button type="submit" class="cmms-btn cmms-btn-primary cmms-btn-block cmms-btn-lg" id="cmms-forgot-submit">
                            שלח קישור איפוס
                            <?php CMMS_Icons::e( $this->dir() === 'rtl' ? 'arrow-left' : 'arrow-right', 18 ); ?>
                        </button>

                        <p class="cmms-text-sm cmms-muted cmms-text-center cmms-mt-4" style="margin-top:16px;">
                            <a href="<?php echo esc_url( home_url( '/cmms-login/' ) ); ?>" style="color:#64748b;text-decoration:none;">← חזרה להתחברות</a>
                        </p>

                        <div style="margin-top:24px;padding-top:18px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center;line-height:1.6;">
                            שכחת באיזו כתובת אימייל נרשמת?<br>
                            פנה לתמיכה ב-<a href="mailto:guy@promoters.co.il" style="color:#94a3b8;">guy@promoters.co.il</a>
                        </div>
                    </form>
                </div>
            </main>
        </div>

        <script>
        (function () {
            var form  = document.getElementById('cmms-forgot-form');
            var btn   = document.getElementById('cmms-forgot-submit');
            var msg   = document.getElementById('cmms-forgot-msg');
            var input = document.getElementById('cmms-forgot-id');
            var nonce = <?php echo wp_json_encode( $nonce ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!input.value.trim()) return;
                btn.disabled = true;
                btn.textContent = 'שולח...';
                msg.style.display = 'none';

                var fd = new FormData();
                fd.append('action', 'cmms_forgot_password');
                fd.append('nonce', nonce);
                fd.append('identifier', input.value);

                fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        var text = (j && j.data && j.data.message) ? j.data.message : 'בוצע.';
                        var ok = !!(j && j.success);
                        msg.style.display = 'block';
                        msg.className = ok ? 'cmms-alert cmms-alert-success' : 'cmms-alert cmms-alert-error';
                        msg.textContent = text;
                        if (ok) form.reset();
                    })
                    .catch(function () {
                        msg.style.display = 'block';
                        msg.className = 'cmms-alert cmms-alert-error';
                        msg.textContent = 'שגיאת רשת. נסה שוב.';
                    })
                    .finally(function () {
                        btn.disabled = false;
                        btn.innerHTML = 'שלח קישור איפוס';
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       1.14.61: RESET PASSWORD page
       Two password fields. Submits to AJAX which validates the reset
       key (built-in WP one-time token) and applies the new password.
    ============================================================ */
    public function render_reset( $atts = array() ) {
        ob_start();
        if ( is_user_logged_in() && CMMS_Auth::current_cmms_user() ) {
            wp_safe_redirect( home_url( '/cmms-dashboard/' ) );
            exit;
        }
        $dir = $this->dir();
        $key   = isset( $_GET['key'] )   ? sanitize_text_field( wp_unslash( $_GET['key'] ) )   : '';
        $login = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ) )       : '';
        $nonce = wp_create_nonce( 'cmms_reset_password' );
        $ajax_url = admin_url( 'admin-ajax.php' );

        // Pre-validate the key. If bad, show a clear error and a
        // "request a new link" CTA instead of a useless form.
        $key_valid = false;
        $key_error = '';
        if ( $key === '' || $login === '' ) {
            $key_error = 'הקישור אינו תקין. ייתכן שהעתקת רק חלק מהכתובת.';
        } else {
            $check = check_password_reset_key( $key, $login );
            if ( is_wp_error( $check ) ) {
                $key_error = 'הקישור פג תוקף או שכבר נעשה בו שימוש. אנא בקש קישור חדש.';
            } else {
                $key_valid = true;
            }
        }
        ?>
        <div class="cmms-auth-page" dir="<?php echo esc_attr( $dir ); ?>" lang="<?php echo esc_attr( $this->lang() ); ?>">
            <?php $this->lang_switcher(); ?>

            <aside class="cmms-auth-hero">
                <div class="cmms-auth-hero-inner">
                    <a href="#" class="cmms-auth-brand-mark">
                        <?php $this->brand_mark( 44 ); ?>
                        <?php $this->e( 'brand' ); ?>
                    </a>
                    <h1>בחירת סיסמה חדשה</h1>
                    <p>הזן סיסמה חדשה לחשבון שלך.</p>
                </div>
                <div class="cmms-auth-hero-foot"><?php $this->e( 'auth.hero_foot' ); ?></div>
            </aside>

            <main class="cmms-auth-form-side">
                <div class="cmms-auth-card">
                    <div class="cmms-auth-mobile-brand"><?php $this->brand_mark( 32 ); ?> <?php $this->e( 'brand' ); ?></div>
                    <h2>קביעת סיסמה חדשה</h2>

                    <?php if ( ! $key_valid ) : ?>
                        <div class="cmms-alert cmms-alert-error" style="margin-bottom:16px;">
                            <?php CMMS_Icons::e( 'alert-circle', 18 ); ?>
                            <span><?php echo esc_html( $key_error ); ?></span>
                        </div>
                        <a href="<?php echo esc_url( home_url( '/cmms-forgot/' ) ); ?>" class="cmms-btn cmms-btn-primary cmms-btn-block cmms-btn-lg">
                            בקש קישור חדש
                        </a>
                    <?php else : ?>
                        <p class="cmms-auth-card-sub">בחר סיסמה חדשה (לפחות 6 תווים) ולחץ "שמור". לאחר מכן תוכל/י להתחבר.</p>

                        <div id="cmms-reset-msg" style="display:none;margin-bottom:14px;"></div>

                        <form id="cmms-reset-form" class="cmms-form" novalidate>
                            <input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
                            <input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">

                            <div class="cmms-field">
                                <label class="cmms-field-label" for="cmms-reset-p1">סיסמה חדשה</label>
                                <input class="cmms-input" id="cmms-reset-p1" name="password1" type="password" required minlength="6" autocomplete="new-password">
                            </div>
                            <div class="cmms-field">
                                <label class="cmms-field-label" for="cmms-reset-p2">אימות סיסמה</label>
                                <input class="cmms-input" id="cmms-reset-p2" name="password2" type="password" required minlength="6" autocomplete="new-password">
                            </div>

                            <button type="submit" class="cmms-btn cmms-btn-primary cmms-btn-block cmms-btn-lg" id="cmms-reset-submit">
                                שמור סיסמה חדשה
                                <?php CMMS_Icons::e( $this->dir() === 'rtl' ? 'arrow-left' : 'arrow-right', 18 ); ?>
                            </button>
                        </form>

                        <script>
                        (function () {
                            var form = document.getElementById('cmms-reset-form');
                            var btn  = document.getElementById('cmms-reset-submit');
                            var msg  = document.getElementById('cmms-reset-msg');
                            var nonce = <?php echo wp_json_encode( $nonce ); ?>;
                            var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;

                            form.addEventListener('submit', function (e) {
                                e.preventDefault();
                                var fd = new FormData(form);
                                fd.append('action', 'cmms_reset_password');
                                fd.append('nonce', nonce);

                                btn.disabled = true;
                                btn.textContent = 'שומר...';
                                msg.style.display = 'none';

                                fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                                    .then(function (r) { return r.json(); })
                                    .then(function (j) {
                                        if (j && j.success) {
                                            msg.style.display = 'block';
                                            msg.className = 'cmms-alert cmms-alert-success';
                                            msg.textContent = j.data.message || 'הסיסמה עודכנה.';
                                            setTimeout(function () {
                                                window.location.href = j.data.redirect_url || <?php echo wp_json_encode( home_url( '/cmms-login/' ) ); ?>;
                                            }, 1500);
                                        } else {
                                            msg.style.display = 'block';
                                            msg.className = 'cmms-alert cmms-alert-error';
                                            msg.textContent = (j && j.data && j.data.message) ? j.data.message : 'שגיאה.';
                                            btn.disabled = false;
                                            btn.innerHTML = 'שמור סיסמה חדשה';
                                        }
                                    })
                                    .catch(function () {
                                        msg.style.display = 'block';
                                        msg.className = 'cmms-alert cmms-alert-error';
                                        msg.textContent = 'שגיאת רשת.';
                                        btn.disabled = false;
                                        btn.innerHTML = 'שמור סיסמה חדשה';
                                    });
                            });
                        })();
                        </script>
                    <?php endif; ?>

                    <p class="cmms-text-sm cmms-muted cmms-text-center cmms-mt-4" style="margin-top:16px;">
                        <a href="<?php echo esc_url( home_url( '/cmms-login/' ) ); ?>" style="color:#64748b;text-decoration:none;">← חזרה להתחברות</a>
                    </p>
                </div>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       DASHBOARD - shell + view router
    ============================================================ */
    public function render_dashboard( $atts = array() ) {
        // 1.14.48: If a user lands here with ?welcome=1 directly (i.e.
        // iCredit redirected them to the dashboard URL instead of our
        // handle_return endpoint, because the page configuration in
        // iCredit has its own return URL hard-coded), bounce them
        // through the Workspace Animation step first. We mark the
        // animation step with ?from=welcome so the animation knows it
        // shouldn't loop back here without finishing.
        if ( isset( $_GET['welcome'] ) && ! isset( $_GET['cmms_anim_done'] ) ) {
            // Only do the bounce on a real page hit; AJAX/REST calls
            // shouldn't get caught here.
            if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
                // Use a JS redirect to avoid "headers already sent" if
                // the theme started streaming HTML. The dashboard shortcode
                // can be invoked late in the page lifecycle.
                $target = home_url( '/start/?step=4&payment=success' );
                return '<script>location.replace(' . wp_json_encode( $target ) . ');</script>'
                     . '<noscript><meta http-equiv="refresh" content="0; url=' . esc_attr( $target ) . '"></noscript>';
            }
        }

        // If not logged in, show a friendly redirect link instead of trying
        // to wp_safe_redirect() — that fails when shortcode runs after the
        // theme has already started outputting HTML, leaving a blank page.
        if ( ! is_user_logged_in() ) {
            $login_url = home_url( '/cmms-login/' );
            return '<div style="padding:60px 20px;text-align:center;font-family:system-ui,sans-serif;">'
                . '<h2 style="color:#0b1c33;margin-bottom:16px;">' . esc_html__( 'Please sign in', 'cmms-light' ) . '</h2>'
                . '<p style="color:#64748b;margin-bottom:24px;">' . esc_html__( 'You need to be logged in to view the dashboard.', 'cmms-light' ) . '</p>'
                . '<a href="' . esc_url( $login_url ) . '" style="display:inline-block;padding:12px 28px;background:#ff6a00;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">'
                . esc_html__( 'Go to login', 'cmms-light' )
                . '</a>'
                . '<script>setTimeout(function(){location.href=' . wp_json_encode( $login_url ) . '},1500);</script>'
                . '</div>';
        }

        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) {
            $login_url = home_url( '/cmms-login/?reason=inactive' );
            return '<div style="padding:60px 20px;text-align:center;font-family:system-ui,sans-serif;">'
                . '<h2 style="color:#0b1c33;margin-bottom:16px;">' . esc_html__( 'Account inactive', 'cmms-light' ) . '</h2>'
                . '<p style="color:#64748b;margin-bottom:24px;">' . esc_html__( 'Your account is not active. Please contact your administrator.', 'cmms-light' ) . '</p>'
                . '<a href="' . esc_url( $login_url ) . '" style="display:inline-block;padding:12px 28px;background:#ff6a00;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">'
                . esc_html__( 'Sign in as a different user', 'cmms-light' )
                . '</a>'
                . '</div>';
        }

        // 1.14.49: SECURITY GATE. Block dashboard access for accounts
        // that have not completed payment. Previously, a user who hit
        // the browser back button between signup and payment would get
        // straight to the dashboard — bypassing payment entirely.
        //
        // The gate logic:
        //   - active or past_due  → allow (subscription works, normal access)
        //   - frozen              → block with reactivation screen
        //   - trial / canceled    → check if ANY payment was approved;
        //                            if yes (e.g. status just lagging behind),
        //                            allow; otherwise force to payment step
        //
        // The IPN handler flips status to active immediately after a
        // successful payment, so this only kicks in for genuinely
        // unpaid sessions.
        $sub_status = $this->get_account_subscription_status( (int) $u->account_id );
        if ( $sub_status === 'frozen' ) {
            return $this->render_frozen_blocker();
        }
        // 1.14.56: canceled_pending = user clicked Cancel. Allow access
        // until next_charge_at has passed, then revoke. Check via
        // the subscriptions table.
        if ( $sub_status === 'canceled_pending' ) {
            global $wpdb;
            $subs_t = CMMS_DB::table( 'subscriptions' );
            $period_end = $wpdb->get_var( $wpdb->prepare(
                "SELECT next_charge_at FROM $subs_t
                  WHERE account_id = %d ORDER BY id DESC LIMIT 1",
                (int) $u->account_id
            ) );
            if ( $period_end && strtotime( $period_end ) < current_time( 'timestamp' ) ) {
                // Period ended — block.
                return $this->render_payment_required_blocker( $u );
            }
            // Period still active — allow, but the billing tab will
            // show the "canceled" banner.
        } elseif ( ! in_array( $sub_status, array( 'active', 'past_due' ), true ) ) {
            // Look up whether this account has ever had an approved
            // payment. If yes, IPN probably just hasn't synced the
            // status field yet — allow access. If no, the account is
            // unpaid and must complete payment.
            if ( ! $this->account_has_approved_payment( (int) $u->account_id ) ) {
                return $this->render_payment_required_blocker( $u );
            }
        }

        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'home';
        ob_start();
        try {
            $this->render_shell( $u, $view );
            return ob_get_clean();
        } catch ( \Throwable $e ) {
            ob_end_clean();
            // Log internally — never leak details to the user.
            error_log( 'CMMS render_dashboard error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
            return '<div style="padding:40px;text-align:center;">' . esc_html__( 'Error loading dashboard. Please contact support.', 'cmms-light' ) . '</div>';
        }
    }

    private function render_shell( $u, $view ) {
        $account = CMMS_Auth::current_account();
        $can_manage = CMMS_Auth::can( 'manage_account' );
        $logout_url = wp_logout_url( home_url( '/cmms-login/' ) );
        $dir = $this->dir();

        $nav_items_work = array(
            array( 'view' => 'home',     'icon' => 'home',  'label' => 'nav.home' ),
            array( 'view' => 'tasks',    'icon' => 'list',  'label' => 'nav.tasks' ),
            array( 'view' => 'task_new', 'icon' => 'plus-circle', 'label' => 'nav.new_task' ),
            array( 'view' => 'assets',   'icon' => 'package', 'label' => 'nav.assets' ),
        );
        $nav_items_manage = array(
            array( 'view' => 'reports',    'icon' => 'bar-chart', 'label' => 'nav.reports' ),
            array( 'view' => 'forms',      'icon' => 'clipboard', 'label' => 'nav.forms' ),
            array( 'view' => 'users',      'icon' => 'users',     'label' => 'nav.users' ),
            array( 'view' => 'import',     'icon' => 'upload',    'label' => 'nav.import_assets' ),
            array( 'view' => 'bulk_tasks', 'icon' => 'plus-square','label' => 'nav.bulk_tasks' ),
            array( 'view' => 'settings',   'icon' => 'settings',  'label' => 'nav.settings' ),
            array( 'view' => 'help',       'icon' => 'help-circle','label' => 'nav.help' ),
        );

        // Compute nav badges (open task count etc.).
        global $wpdb;
        $t_tasks = CMMS_DB::table( 'tasks' );
        $open_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t_tasks WHERE account_id = %d AND status IN ('open','in_progress','waiting')",
            $u->account_id
        ) );
        // Notification bell data
        $unread_count = CMMS_Notifications::unread_count_for_user( $u->id );
        $recent_notifs = CMMS_Notifications::recent_for_user( $u->id, 12 );

        // 1.14.40: subscription status — used to render a banner for
        // past_due accounts (grace period). 'frozen' was already blocked
        // earlier in render_dashboard.
        $sub_status = $this->get_account_subscription_status( (int) $u->account_id );
        ?>
        <div class="cmms-app" dir="<?php echo esc_attr( $dir ); ?>" lang="<?php echo esc_attr( $this->lang() ); ?>">

            <?php if ( $sub_status === 'past_due' ) :
                // Fetch grace_until to show a date in the banner.
                $sub = CMMS_Subscriptions::for_account( (int) $u->account_id );
                $grace_until_text = '';
                if ( $sub && ! empty( $sub['grace_until'] ) ) {
                    $ts = strtotime( $sub['grace_until'] );
                    if ( $ts ) $grace_until_text = date_i18n( 'd/m/Y', $ts );
                }
                $reactivate_url = home_url( '/start/?step=3' );
            ?>
            <div class="cmms-sub-banner cmms-sub-banner-warning" role="alert">
                <strong>⚠ החיוב האחרון נכשל.</strong>
                <?php if ( $grace_until_text ) : ?>
                    יש לעדכן את אמצעי התשלום עד <?php echo esc_html( $grace_until_text ); ?> כדי לא לאבד גישה למערכת.
                <?php else : ?>
                    יש לעדכן את אמצעי התשלום בהקדם כדי לא לאבד גישה.
                <?php endif; ?>
                <a href="<?php echo esc_url( $reactivate_url ); ?>" class="cmms-sub-banner-cta">עדכון תשלום</a>
            </div>
            <style>
                .cmms-sub-banner { padding: 12px 20px; font-size: 14px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
                .cmms-sub-banner-warning { background: #fef3c7; color: #92400e; border-bottom: 1px solid #fde68a; }
                .cmms-sub-banner-cta { background: #fff; color: #92400e; padding: 6px 14px; border-radius: 8px; text-decoration: none; font-weight: 600; border: 1px solid #fde68a; }
                .cmms-sub-banner-cta:hover { background: #fef9c3; color: #78350f; }
            </style>
            <?php endif; ?>

            <!-- Mobile top bar -->
            <div class="cmms-topbar">
                <a href="<?php echo esc_url( $this->url( array( 'view' => 'home' ) ) ); ?>" class="cmms-topbar-brand">
                    <?php $this->brand_mark( 32 ); ?> <?php $this->e( 'brand' ); ?>
                </a>
                <div class="cmms-topbar-actions">
                    <button type="button" class="cmms-icon-btn cmms-search-trigger-mobile"
                            data-cmms-search-mobile-open
                            aria-label="<?php echo esc_attr( $this->t( 'search.placeholder' ) ); ?>">
                        <?php CMMS_Icons::e( 'search', 22 ); ?>
                    </button>
                    <?php $this->bell( $u, $unread_count, $recent_notifs ); ?>
                    <button type="button" id="cmms-menu-toggle" class="cmms-icon-btn" aria-label="Menu">
                        <?php CMMS_Icons::e( 'menu', 22 ); ?>
                    </button>
                </div>
            </div>

            <div class="cmms-sidebar-overlay" id="cmms-sidebar-overlay"></div>

            <!-- Sidebar -->
            <aside class="cmms-sidebar" id="cmms-sidebar">
                <div class="cmms-sidebar-head">
                    <a href="<?php echo esc_url( $this->url( array( 'view' => 'home' ) ) ); ?>" class="cmms-sidebar-brand">
                        <?php $this->brand_mark( 36 ); ?> <?php $this->e( 'brand' ); ?>
                    </a>
                    <div class="cmms-sidebar-org">
                        <div class="cmms-sidebar-org-name"><?php echo esc_html( $account ? $account->name : '' ); ?></div>
                        <div class="cmms-sidebar-org-user">
                            <?php echo esc_html( $u->display_name ); ?>
                            · <?php echo esc_html( $this->t( 'role.' . $u->role ) ?: ucfirst( $u->role ) ); ?>
                        </div>
                    </div>
                </div>

                <nav class="cmms-sidebar-nav">
                    <div class="cmms-sidebar-section-label"><?php $this->e( 'nav.section.work' ); ?></div>
                    <?php foreach ( $nav_items_work as $item ) : ?>
                        <a href="<?php echo esc_url( $this->url( array( 'view' => $item['view'] ) ) ); ?>"
                           class="<?php echo $view === $item['view'] ? 'active' : ''; ?>">
                            <?php CMMS_Icons::e( $item['icon'], 18 ); ?>
                            <span><?php $this->e( $item['label'] ); ?></span>
                            <?php if ( $item['view'] === 'tasks' && $open_count > 0 ) : ?>
                                <span class="cmms-sidebar-nav-badge"><?php echo esc_html( $open_count ); ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>

                    <?php if ( $can_manage ) : ?>
                        <div class="cmms-sidebar-section-label"><?php $this->e( 'nav.section.manage' ); ?></div>
                        <?php foreach ( $nav_items_manage as $item ) : ?>
                            <a href="<?php echo esc_url( $this->url( array( 'view' => $item['view'] ) ) ); ?>"
                               class="<?php echo $view === $item['view'] ? 'active' : ''; ?>">
                                <?php CMMS_Icons::e( $item['icon'], 18 ); ?>
                                <span><?php $this->e( $item['label'] ); ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </nav>

                <div class="cmms-sidebar-foot">
                    <a href="<?php echo esc_url( $logout_url ); ?>" class="cmms-sidebar-nav cmms-sidebar-nav-logout"
                       style="display:flex;align-items:center;gap:12px;padding:10px 12px;color:rgba(255,255,255,0.65);text-decoration:none;border-radius:8px;font-size:15px;font-weight:500;">
                        <?php CMMS_Icons::e( 'log-out', 18 ); ?> <?php $this->e( 'nav.logout' ); ?>
                    </a>
                </div>
            </aside>

            <main class="cmms-main">
                <div class="cmms-main-header">
                    <?php
                    // Search trigger — a simple icon button on both desktop and
                    // mobile. Clicking it opens the same fullscreen overlay
                    // for searching tasks/assets/users. We unified the two
                    // because the inline desktop dropdown was hard to make
                    // look right next to the bell + RTL header.
                    ?>
                    <button type="button" class="cmms-icon-btn cmms-search-trigger-desktop"
                            data-cmms-search-mobile-open
                            aria-label="<?php echo esc_attr( $this->t( 'search.placeholder' ) ); ?>"
                            title="<?php echo esc_attr( $this->t( 'search.placeholder' ) ); ?>">
                        <?php CMMS_Icons::e( 'search', 22 ); ?>
                    </button>
                    <?php $this->bell( $u, $unread_count, $recent_notifs, 'desktop' ); ?>
                </div>

                <?php
                // Search overlay. On mobile (<768px) this fills the screen.
                // On desktop (>=768px) the inner panel becomes a compact
                // dropdown anchored under the topbar, with a click-through
                // backdrop to dismiss.
                ?>
                <div class="cmms-search-overlay" data-cmms-search-overlay hidden role="dialog" aria-modal="true">
                    <div class="cmms-search-overlay-backdrop" data-cmms-search-mobile-close aria-hidden="true"></div>
                    <div class="cmms-search-overlay-panel">
                        <div class="cmms-search-overlay-header">
                            <button type="button" class="cmms-icon-btn" data-cmms-search-mobile-close
                                    aria-label="Close">
                                <?php CMMS_Icons::e( 'x', 24 ); ?>
                            </button>
                            <input type="search"
                                   class="cmms-search-overlay-input"
                                   data-cmms-search-mobile-input
                                   placeholder="<?php echo esc_attr( $this->t( 'search.placeholder' ) ); ?>"
                                   autocomplete="off" spellcheck="false">
                        </div>
                        <div class="cmms-search-overlay-body" data-cmms-search-mobile-results>
                            <div class="cmms-search-empty" data-cmms-search-mobile-empty>
                                <?php $this->e( 'search.start_typing' ); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    // Pass endpoint + nonce + i18n to the search JS.
                    window.cmmsSearchConfig = {
                        endpoint: <?php echo wp_json_encode( admin_url( 'admin-post.php' ) . '?action=cmms_search' ); ?>,
                        i18n: {
                            tasks:        <?php echo wp_json_encode( $this->t( 'search.group_tasks' ) ); ?>,
                            assets:       <?php echo wp_json_encode( $this->t( 'search.group_assets' ) ); ?>,
                            users:        <?php echo wp_json_encode( $this->t( 'search.group_users' ) ); ?>,
                            no_results:   <?php echo wp_json_encode( $this->t( 'search.no_results' ) ); ?>,
                            start_typing: <?php echo wp_json_encode( $this->t( 'search.start_typing' ) ); ?>,
                            min_chars:    <?php echo wp_json_encode( $this->t( 'search.min_chars' ) ); ?>,
                            err_network:  <?php echo wp_json_encode( $this->t( 'search.err_network' ) ); ?>,
                        },
                        priority_labels: {
                            low:    <?php echo wp_json_encode( $this->t( 'priority.low' ) ); ?>,
                            normal: <?php echo wp_json_encode( $this->t( 'priority.normal' ) ); ?>,
                            high:   <?php echo wp_json_encode( $this->t( 'priority.high' ) ); ?>,
                            urgent: <?php echo wp_json_encode( $this->t( 'priority.urgent' ) ); ?>,
                        },
                        status_labels: {
                            open:        <?php echo wp_json_encode( $this->t( 'status.open' ) ); ?>,
                            in_progress: <?php echo wp_json_encode( $this->t( 'status.in_progress' ) ); ?>,
                            waiting:     <?php echo wp_json_encode( $this->t( 'status.waiting' ) ); ?>,
                            completed:   <?php echo wp_json_encode( $this->t( 'status.completed' ) ); ?>,
                            cancelled:   <?php echo wp_json_encode( $this->t( 'status.cancelled' ) ); ?>,
                        },
                        role_labels: {
                            owner:      <?php echo wp_json_encode( $this->t( 'role.owner' ) ); ?>,
                            manager:    <?php echo wp_json_encode( $this->t( 'role.manager' ) ); ?>,
                            technician: <?php echo wp_json_encode( $this->t( 'role.technician' ) ); ?>,
                            reporter:   <?php echo wp_json_encode( $this->t( 'role.reporter' ) ); ?>,
                        },
                    };
                </script>
                <?php
                switch ( $view ) {
                    case 'tasks':     $this->view_tasks( $u ); break;
                    case 'task_new':  $this->view_task_form( $u ); break;
                    case 'task':      $this->view_task_detail( $u ); break;
                    case 'task_edit': $this->view_task_form( $u, true ); break;
                    case 'assets':    $this->view_assets( $u ); break;
                    case 'asset_new': $this->view_asset_form( $u ); break;
                    case 'asset':     $this->view_asset_detail( $u ); break;
                    case 'asset_edit':$this->view_asset_form( $u, true ); break;
                    case 'asset_qr_print': $this->view_asset_qr_print( $u ); break;
                    case 'reports':   $this->view_reports( $u ); break;
                    case 'forms':     $this->view_forms( $u ); break;
                    case 'form_edit': $this->view_form_edit( $u ); break;
                    case 'users':     $this->view_users( $u ); break;
                    case 'import':    $this->view_import( $u ); break;
                    case 'bulk_tasks': $this->view_bulk_tasks( $u ); break;
                    case 'settings':  $this->view_settings( $u ); break;
                    case 'help':      $this->view_help_center( $u ); break;
                    default:          $this->view_home( $u );
                }
                ?>
            </main>
        </div>

        <?php // === HELP CENTER (1.14.22) ===
              // Floating help trigger and modal shell. The trigger looks at
              // the nearest [data-cmms-page] ancestor (set by each view) and
              // asks the server for that page's articles. If the user is
              // already on the help center page, the trigger hides itself —
              // there's no sensible "help on a help page". ?>
        <button type="button"
                class="cmms-help-fab"
                data-cmms-help-trigger
                aria-label="עזרה לעמוד זה">
            <span aria-hidden="true">?</span>
        </button>

        <div class="cmms-help-modal" data-cmms-help-modal hidden role="dialog" aria-modal="true" aria-labelledby="cmms-help-modal-title">
            <div class="cmms-help-modal-backdrop" data-cmms-help-close></div>
            <div class="cmms-help-modal-card" role="document">
                <header class="cmms-help-modal-head">
                    <h2 id="cmms-help-modal-title">עזרה</h2>
                    <button type="button" class="cmms-help-modal-close" data-cmms-help-close aria-label="סגור">×</button>
                </header>
                <div class="cmms-help-modal-body" data-cmms-help-modal-body>
                    <p>טוען&hellip;</p>
                </div>
                <footer class="cmms-help-modal-foot">
                    <a class="cmms-btn cmms-btn-ghost cmms-btn-sm"
                       href="<?php echo esc_url( $this->url( array( 'view' => 'help' ) ) ); ?>">
                        כל המאמרים במרכז ההדרכה
                    </a>
                </footer>
            </div>
        </div>
        <?php
    }

    /* ============================================================
       VIEW: HOME
    ============================================================ */
    private function view_home( $u ) {
        $range = CMMS_TimeRange::from_request();
        $counts = CMMS_Tasks::counts_for_account( $u->account_id, $range );
        global $wpdb;
        $t_tasks = CMMS_DB::table( 'tasks' );

        // Build my_open with range scope.
        $my_open_sql = "SELECT COUNT(*) FROM $t_tasks WHERE account_id = %d AND assigned_to = %d AND status IN ('open','in_progress','waiting')";
        $my_open_args = array( $u->account_id, $u->id );
        list( $extra_sql, $extra_args ) = CMMS_TimeRange::sql_clause(
            in_array( $range['key'], array( 'next_month', 'next_quarter' ), true ) ? 'due_date' : 'created_at',
            $range
        );
        if ( $extra_sql ) {
            $my_open_sql .= $extra_sql;
            $my_open_args = array_merge( $my_open_args, $extra_args );
        }
        $my_open = (int) $wpdb->get_var( $wpdb->prepare( $my_open_sql, $my_open_args ) );

        $hour = (int) current_time( 'G' );
        $greet_key = $hour < 12 ? 'home.greet_morning' : ( $hour < 18 ? 'home.greet_afternoon' : 'home.greet_evening' );
        ?>
        <div class="cmms-page-head" data-cmms-page="home">
            <div>
                <h1 class="cmms-page-title">
                    <?php echo esc_html( $this->t( $greet_key ) ); ?>, <?php echo esc_html( $u->display_name ); ?>
                </h1>
                <p class="cmms-page-sub"><?php $this->e( 'home.subtitle' ); ?></p>
            </div>
            <div class="cmms-page-actions">
                <?php $this->range_picker( 'home' ); ?>
                <a class="cmms-btn cmms-btn-primary" href="<?php echo esc_url( $this->url( array( 'view' => 'task_new' ) ) ); ?>">
                    <?php CMMS_Icons::e( 'plus', 16 ); ?> <?php $this->e( 'home.create_task' ); ?>
                </a>
            </div>
        </div>

        <?php
        // 1.14.45: Setup Checklist for new accounts.
        // Renders only when account has incomplete steps AND hasn't been
        // dismissed. Self-hides if all steps are done. Sits between the
        // greeting (above) and the install banner (below) so it's the
        // first thing a new user sees before metrics.
        if ( class_exists( 'CMMS_Checklist' ) && CMMS_Checklist::should_show( (int) $u->account_id ) ) {
            $this->render_setup_checklist( $u );
        }
        ?>

        <?php
        // Install banner: shown by default to all users on dashboard home,
        // hidden by JS when (a) the app is already running standalone or
        // (b) the user clicked the X to dismiss. The button itself routes
        // to a real Chrome install prompt when available, otherwise opens
        // a platform-specific instructions modal.
        // Hidden on initial render via inline style="display:none" so users
        // don't see a flash before JS evaluates and confirms it should show.
        ?>
        <div class="cmms-install-banner" data-cmms-install-banner hidden>
            <div class="cmms-install-banner-icon">
                <?php CMMS_Icons::e( 'download', 24 ); ?>
            </div>
            <div class="cmms-install-banner-text">
                <strong><?php $this->e( 'install.banner_title' ); ?></strong>
                <span><?php $this->e( 'install.banner_subtitle' ); ?></span>
            </div>
            <div class="cmms-install-banner-actions">
                <button type="button" class="cmms-btn cmms-btn-primary cmms-btn-sm" data-cmms-install-banner-btn>
                    <?php $this->e( 'install.banner_button' ); ?>
                </button>
                <button type="button" class="cmms-install-banner-close" data-cmms-install-banner-close
                        aria-label="<?php echo esc_attr( $this->t( 'install.banner_dismiss' ) ); ?>">
                    ×
                </button>
            </div>
        </div>
        <script>
            // i18n strings for the JS-rendered instructions modal.
            // We pass them once on page render so the JS doesn't need to
            // do its own AJAX or include translations.
            window.cmmsInstallI18n = {
                ios_title: <?php echo wp_json_encode( $this->t( 'install.ios_title' ) ); ?>,
                ios_steps: <?php echo wp_json_encode( array(
                    $this->t( 'install.ios_step1' ),
                    $this->t( 'install.ios_step2' ),
                    $this->t( 'install.ios_step3' ),
                ) ); ?>,
                android_title: <?php echo wp_json_encode( $this->t( 'install.android_title' ) ); ?>,
                android_steps: <?php echo wp_json_encode( array(
                    $this->t( 'install.android_step1' ),
                    $this->t( 'install.android_step2' ),
                    $this->t( 'install.android_step3' ),
                ) ); ?>,
                other_title: <?php echo wp_json_encode( $this->t( 'install.other_title' ) ); ?>,
                other_steps: <?php echo wp_json_encode( array(
                    $this->t( 'install.other_step1' ),
                ) ); ?>,
                got_it: <?php echo wp_json_encode( $this->t( 'install.got_it' ) ); ?>,
            };
        </script>

        <div class="cmms-stats-grid">
            <a class="cmms-stat-card warning" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'status' => 'open' ) ) ); ?>">
                <span class="cmms-stat-card-icon"><?php CMMS_Icons::e( 'clock', 18 ); ?></span>
                <div class="cmms-stat-card-value"><?php echo (int) $counts['open']; ?></div>
                <div class="cmms-stat-card-label"><?php $this->e( 'stat.open' ); ?></div>
            </a>
            <a class="cmms-stat-card danger" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'overdue' => 1 ) ) ); ?>">
                <span class="cmms-stat-card-icon"><?php CMMS_Icons::e( 'alert-triangle', 18 ); ?></span>
                <div class="cmms-stat-card-value"><?php echo (int) $counts['overdue']; ?></div>
                <div class="cmms-stat-card-label"><?php $this->e( 'stat.overdue' ); ?></div>
            </a>
            <a class="cmms-stat-card success" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'status' => 'completed' ) ) ); ?>">
                <span class="cmms-stat-card-icon"><?php CMMS_Icons::e( 'check-circle', 18 ); ?></span>
                <div class="cmms-stat-card-value"><?php echo (int) $counts['completed']; ?></div>
                <div class="cmms-stat-card-label"><?php $this->e( 'stat.completed' ); ?></div>
            </a>
            <a class="cmms-stat-card accent" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'as_assignee' => 1 ) ) ); ?>">
                <span class="cmms-stat-card-icon"><?php CMMS_Icons::e( 'user', 18 ); ?></span>
                <div class="cmms-stat-card-value"><?php echo (int) $my_open; ?></div>
                <div class="cmms-stat-card-label"><?php $this->e( 'stat.my_open' ); ?></div>
            </a>
        </div>

        <div class="cmms-quick-actions">
            <a class="cmms-action-tile" href="<?php echo esc_url( $this->url( array( 'view' => 'task_new' ) ) ); ?>">
                <span class="cmms-action-tile-icon"><?php CMMS_Icons::e( 'plus', 22 ); ?></span>
                <div class="cmms-action-tile-text">
                    <span class="cmms-action-tile-title"><?php $this->e( 'home.create_task' ); ?></span>
                    <span class="cmms-action-tile-sub"><?php $this->e( 'home.create_task_d' ); ?></span>
                </div>
            </a>
            <a class="cmms-action-tile" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'as_assignee' => 1 ) ) ); ?>">
                <span class="cmms-action-tile-icon navy"><?php CMMS_Icons::e( 'list', 22 ); ?></span>
                <div class="cmms-action-tile-text">
                    <span class="cmms-action-tile-title"><?php $this->e( 'home.my_tasks' ); ?></span>
                    <span class="cmms-action-tile-sub"><?php $this->e( 'home.my_tasks_d' ); ?></span>
                </div>
            </a>
            <a class="cmms-action-tile" href="<?php echo esc_url( $this->url( array( 'view' => 'assets' ) ) ); ?>">
                <span class="cmms-action-tile-icon gray"><?php CMMS_Icons::e( 'package', 22 ); ?></span>
                <div class="cmms-action-tile-text">
                    <span class="cmms-action-tile-title"><?php $this->e( 'home.assets' ); ?></span>
                    <span class="cmms-action-tile-sub"><?php $this->e( 'home.assets_d' ); ?></span>
                </div>
            </a>
        </div>

        <?php
        // Recent tasks
        $recent = CMMS_Tasks::list_for_user( $u, array( 'limit' => 5 ) );
        ?>
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'activity', 16 ); ?> <?php $this->e( 'home.recent_tasks' ); ?></h3>
                <a class="cmms-btn cmms-btn-sm cmms-btn-ghost" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks' ) ) ); ?>"><?php $this->e( 'home.view_all' ); ?> →</a>
            </div>
            <div class="cmms-section-body">
                <?php if ( empty( $recent ) ) : ?>
                    <?php $this->empty_state( 'inbox', 'task.no_tasks', 'task.no_tasks_d' ); ?>
                <?php else : ?>
                    <div class="cmms-list">
                        <?php foreach ( $recent as $task ) $this->task_card( $task, $u ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private function task_card( $task, $u = null ) {
        // ============================================================
        // DEFENSIVE RENDERING
        // A single malformed task row must NEVER break the dashboard.
        // We wrap the whole card in try/catch and validate every field
        // before we touch it. If anything is unrecoverable, we render a
        // tiny "fallback card" with just the id+title so the user can
        // still navigate to it and fix or delete it.
        // ============================================================
        try {
            // 1. Hard guards: object must exist and have an id.
            if ( ! is_object( $task ) || empty( $task->id ) ) {
                return;
            }
            $task_id = (int) $task->id;

            // 2. Title — never null in DB but defensive anyway.
            $title = isset( $task->title ) && $task->title !== ''
                ? (string) $task->title
                : sprintf( __( '(Untitled task #%d)', 'cmms-light' ), $task_id );

            // 3. Status / priority — fall back to safe defaults if a row
            //    has a value outside the known enum (can happen after a
            //    schema change or a botched manual DB edit).
            $valid_statuses   = array_keys( CMMS_Tasks::statuses() );
            $valid_priorities = array_keys( CMMS_Tasks::priorities() );
            $status   = ( isset( $task->status )   && in_array( $task->status,   $valid_statuses,   true ) ) ? $task->status   : 'open';
            $priority = ( isset( $task->priority ) && in_array( $task->priority, $valid_priorities, true ) ) ? $task->priority : 'normal';

            // 4. due_date may be NULL, '', '0000-00-00 00:00:00', or
            //    an unparseable string from a buggy import. Normalize.
            $due_raw = isset( $task->due_date ) ? (string) $task->due_date : '';
            $has_due = false;
            $is_overdue = false;
            $due_display = '';
            if ( $due_raw !== '' && $due_raw !== '0000-00-00 00:00:00' && substr( $due_raw, 0, 10 ) !== '0000-00-00' ) {
                $ts = strtotime( $due_raw );
                if ( $ts && $ts > 0 ) {
                    $has_due = true;
                    $is_overdue = ( $due_raw < current_time( 'Y-m-d' ) ) && ! in_array( $status, array( 'completed', 'closed' ), true );
                    // mysql2date can occasionally throw on edge cases; guard it.
                    try {
                        $due_display = (string) mysql2date( get_option( 'date_format' ), $due_raw );
                    } catch ( \Throwable $e ) {
                        $due_display = substr( $due_raw, 0, 10 );
                    }
                }
            }

            // 5. asset_name — defensive lookup that never throws.
            $asset_name = '';
            if ( ! empty( $task->asset_id ) ) {
                try {
                    $asset_name = (string) CMMS_Assets::asset_name( (int) $task->asset_id );
                } catch ( \Throwable $e ) {
                    $asset_name = '';
                }
            }

            // 6. Role badges — both fields can be null.
            $is_assignee = $u && isset( $task->assigned_to ) && (int) $task->assigned_to === (int) $u->id;
            $is_manager  = $u && isset( $task->manager_id )  && (int) $task->manager_id  === (int) $u->id;

            $url = $this->url( array( 'view' => 'task', 'id' => $task_id ) );
            ?>
            <a class="cmms-task-card priority-<?php echo esc_attr( $priority ); ?>" href="<?php echo esc_url( $url ); ?>">
                <div class="cmms-task-card-body">
                    <h4 class="cmms-task-card-title"><?php echo esc_html( $title ); ?></h4>
                    <div class="cmms-task-card-meta">
                        <span class="cmms-badge status-<?php echo esc_attr( $status ); ?>">
                            <span class="cmms-badge-dot"></span>
                            <?php echo esc_html( $this->t( 'status.' . $status ) ); ?>
                        </span>
                        <?php if ( $priority !== 'normal' ) : ?>
                            <span class="cmms-badge priority-<?php echo esc_attr( $priority ); ?>">
                                <?php echo esc_html( $this->t( 'priority.' . $priority ) ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $is_assignee ) : ?>
                            <span class="cmms-badge role-badge role-assignee" title="<?php echo esc_attr( $this->t( 'role_label.assignee' ) ); ?>">
                                <?php CMMS_Icons::e( 'wrench', 12 ); ?>
                                <?php $this->e( 'role_label.assignee' ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $is_manager ) : ?>
                            <span class="cmms-badge role-badge role-manager" title="<?php echo esc_attr( $this->t( 'role_label.manager' ) ); ?>">
                                <?php CMMS_Icons::e( 'briefcase', 12 ); ?>
                                <?php $this->e( 'role_label.manager' ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $asset_name !== '' ) : ?>
                            <span class="cmms-task-card-meta-item"><?php CMMS_Icons::e( 'package', 14 ); ?> <?php echo esc_html( $asset_name ); ?></span>
                        <?php endif; ?>
                        <?php if ( $has_due ) : ?>
                            <span class="cmms-task-card-meta-item <?php echo $is_overdue ? 'cmms-overdue' : ''; ?>" style="<?php echo $is_overdue ? 'color:var(--c-red-600);font-weight:600;' : ''; ?>">
                                <?php CMMS_Icons::e( 'calendar', 14 ); ?> <?php echo esc_html( $due_display ); ?>
                                <?php if ( $is_overdue ) : ?> · <?php $this->e( 'task.overdue' ); ?><?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php
        } catch ( \Throwable $e ) {
            // Last-resort fallback card. We log the offending task id so an
            // admin can find it; the user sees a minimal clickable card.
            $bad_id = ( is_object( $task ) && ! empty( $task->id ) ) ? (int) $task->id : 0;
            error_log( sprintf(
                'CMMS task_card render failed for task #%d: %s at %s:%d',
                $bad_id, $e->getMessage(), $e->getFile(), $e->getLine()
            ) );
            if ( $bad_id > 0 ) {
                $url = $this->url( array( 'view' => 'task', 'id' => $bad_id ) );
                ?>
                <a class="cmms-task-card" href="<?php echo esc_url( $url ); ?>" style="border:1px dashed #fbbf24;background:#fffbeb;">
                    <div class="cmms-task-card-body">
                        <h4 class="cmms-task-card-title" style="color:#92400e;">
                            <?php echo esc_html( sprintf( __( 'Task #%d (data needs review)', 'cmms-light' ), $bad_id ) ); ?>
                        </h4>
                        <div class="cmms-task-card-meta">
                            <span class="cmms-badge" style="background:#fef3c7;color:#92400e;">
                                <?php echo esc_html__( 'Click to open', 'cmms-light' ); ?>
                            </span>
                        </div>
                    </div>
                </a>
                <?php
            }
        }
    }

    private function empty_state( $icon, $title_key, $desc_key, $cta_html = '' ) {
        ?>
        <div class="cmms-empty">
            <div class="cmms-empty-icon"><?php CMMS_Icons::e( $icon, 28 ); ?></div>
            <h3 class="cmms-empty-title"><?php echo esc_html( $this->t( $title_key ) ); ?></h3>
            <p class="cmms-empty-desc"><?php echo esc_html( $this->t( $desc_key ) ); ?></p>
            <?php echo $cta_html; ?>
        </div>
        <?php
    }

    /* ============================================================
       VIEW: TASKS LIST
    ============================================================ */
    private function view_tasks( $u ) {
        $range = CMMS_TimeRange::from_request();
        $filters = array();
        if ( ! empty( $_GET['status'] ) ) $filters['status'] = sanitize_key( wp_unslash( $_GET['status'] ) );
        if ( ! empty( $_GET['as_assignee'] ) ) $filters['as_assignee'] = 1;
        if ( ! empty( $_GET['as_manager'] ) ) $filters['as_manager'] = 1;
        if ( ! empty( $_GET['overdue'] ) ) $filters['overdue'] = 1;
        if ( ! empty( $_GET['category_id'] ) ) $filters['category_id'] = (int) $_GET['category_id'];

        // Apply time range
        if ( ! empty( $range['from'] ) || ! empty( $range['to'] ) ) {
            $filters['date_from']  = $range['from'];
            $filters['date_to']    = $range['to'];
            $filters['date_field'] = in_array( $range['key'], array( 'next_month', 'next_quarter' ), true ) ? 'due_date' : 'created_at';
        }

        $tasks = CMMS_Tasks::list_for_user( $u, $filters );
        ?>
        <div class="cmms-page-head" data-cmms-page="tasks">
            <div>
                <h1 class="cmms-page-title"><?php $this->e( 'task.list_title' ); ?></h1>
                <p class="cmms-page-sub"><?php echo (int) count( $tasks ); ?> <?php $this->e( 'task.results' ); ?></p>
            </div>
            <div class="cmms-page-actions">
                <?php $this->range_picker( 'tasks' ); ?>
                <a class="cmms-btn cmms-btn-primary" href="<?php echo esc_url( $this->url( array( 'view' => 'task_new' ) ) ); ?>" data-help="create-task">
                    <?php CMMS_Icons::e( 'plus', 16 ); ?> <?php $this->e( 'task.create' ); ?>
                </a>
            </div>
        </div>

        <!-- Filter pills -->
        <div class="cmms-flex cmms-gap-2 cmms-mb-4" style="flex-wrap:wrap;">
            <a class="cmms-btn cmms-btn-sm <?php echo empty( $filters ) ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks' ) ) ); ?>"><?php $this->e( 'filter.all' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ( $filters['status'] ?? '' ) === 'open' ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'status' => 'open' ) ) ); ?>"><?php $this->e( 'status.open' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ( $filters['status'] ?? '' ) === 'in_progress' ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'status' => 'in_progress' ) ) ); ?>"><?php $this->e( 'status.in_progress' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ( $filters['status'] ?? '' ) === 'completed' ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'status' => 'completed' ) ) ); ?>"><?php $this->e( 'status.completed' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ! empty( $filters['as_assignee'] ) ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'as_assignee' => 1 ) ) ); ?>"><?php CMMS_Icons::e( 'wrench', 14 ); ?> <?php $this->e( 'filter.as_assignee' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ! empty( $filters['as_manager'] ) ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'as_manager' => 1 ) ) ); ?>"><?php CMMS_Icons::e( 'briefcase', 14 ); ?> <?php $this->e( 'filter.as_manager' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ! empty( $filters['overdue'] ) ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array( 'view' => 'tasks', 'overdue' => 1 ) ) ); ?>" style="<?php echo ! empty( $filters['overdue'] ) ? '' : 'color:var(--c-red-600);'; ?>"><?php CMMS_Icons::e( 'alert-triangle', 14 ); ?> <?php $this->e( 'task.overdue' ); ?></a>
        </div>

        <?php if ( empty( $tasks ) ) : ?>
            <div class="cmms-section">
                <div class="cmms-section-body">
                    <?php $this->empty_state( 'inbox', 'task.no_tasks', 'task.no_tasks_d',
                        '<a class="cmms-btn cmms-btn-primary" href="' . esc_url( $this->url( array( 'view' => 'task_new' ) ) ) . '">' . esc_html( $this->t( 'task.create' ) ) . '</a>'
                    ); ?>
                </div>
            </div>
        <?php else : ?>
            <div class="cmms-list">
                <?php foreach ( $tasks as $task ) $this->task_card( $task, $u ); ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /* ============================================================
       VIEW: TASK NEW / EDIT FORM
    ============================================================ */
    private function view_task_form( $u, $is_edit = false ) {
        if ( ! CMMS_Auth::can( 'create_task' ) ) {
            $this->forbidden(); return;
        }
        $task = null;
        if ( $is_edit ) {
            $id = (int) ( $_GET['id'] ?? 0 );
            $task = CMMS_Tasks::get( $id );
            if ( ! $task || (int) $task->account_id !== (int) $u->account_id ) {
                $this->not_found(); return;
            }
            // Editing the metadata of an existing task is gated by
            // can_edit_task — assignees alone don't get to rewrite it.
            if ( ! CMMS_Auth::can_edit_task( $u, $task ) ) {
                $this->forbidden(); return;
            }
        }
        $cats = CMMS_Categories::list_by_account( $u->account_id, true );
        $assets = CMMS_Assets::list_by_account( $u->account_id );
        $users = CMMS_Users::list_by_account( $u->account_id );
        ?>
        <div class="cmms-page-head">
            <div>
                <h1 class="cmms-page-title"><?php $this->e( $is_edit ? 'task.edit_title' : 'task.new_title' ); ?></h1>
                <p class="cmms-page-sub"><?php $this->e( 'task.form_sub' ); ?></p>
            </div>
        </div>

        <?php
        if ( isset( $_GET['cmms_err'] ) ) {
            $err_msg = '';
            if ( isset( $_GET['cmms_msg'] ) ) {
                $err_msg = sanitize_text_field( wp_unslash( $_GET['cmms_msg'] ) );
            } elseif ( $_GET['cmms_err'] === 'title' ) {
                $err_msg = $this->t( 'task.err_title' );
            } else {
                $err_msg = $this->t( 'task.err_generic' );
            }
            if ( $err_msg ) :
            ?>
            <div class="cmms-alert cmms-alert-error">
                <?php CMMS_Icons::e( 'alert-circle', 18 ); ?>
                <span><?php echo esc_html( $err_msg ); ?></span>
            </div>
            <?php endif;
        }
        ?>

        <div class="cmms-section">
            <div class="cmms-section-body">
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" enctype="multipart/form-data" class="cmms-form">
                    <input type="hidden" name="action" value="<?php echo $is_edit ? 'cmms_task_update' : 'cmms_task_create'; ?>">
                    <?php if ( $is_edit ) : ?><input type="hidden" name="task_id" value="<?php echo (int) $task->id; ?>"><?php endif; ?>
                    <?php wp_nonce_field( $is_edit ? 'cmms_task_update' : 'cmms_task_create', $is_edit ? 'cmms_task_update_nonce' : 'cmms_task_create_nonce' ); ?>

                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'task.title' ); ?> <span class="req">*</span></label>
                        <input class="cmms-input" name="title" type="text" required value="<?php echo $task ? esc_attr( $task->title ) : ''; ?>" placeholder="<?php echo esc_attr( $this->t( 'task.title_ph' ) ); ?>">
                    </div>
                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'task.description' ); ?></label>
                        <textarea class="cmms-textarea" name="description" rows="4" placeholder="<?php echo esc_attr( $this->t( 'task.description_ph' ) ); ?>"><?php echo $task ? esc_textarea( $task->description ) : ''; ?></textarea>
                    </div>

                    <div class="cmms-form-row cols-2">
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'task.category' ); ?></label>
                            <select class="cmms-select" name="category_id">
                                <option value=""><?php $this->e( 'common.none' ); ?></option>
                                <?php foreach ( $cats as $c ) : ?>
                                    <option value="<?php echo (int) $c->id; ?>" <?php selected( $task && $task->category_id == $c->id ); ?>><?php echo esc_html( $c->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'task.asset' ); ?></label>
                            <select class="cmms-select" name="asset_id">
                                <option value=""><?php $this->e( 'common.none' ); ?></option>
                                <?php foreach ( $assets as $a ) : ?>
                                    <option value="<?php echo (int) $a->id; ?>" <?php selected( $task && $task->asset_id == $a->id ); ?>><?php echo esc_html( $a->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="cmms-form-row cols-3">
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'task.priority' ); ?></label>
                            <select class="cmms-select" name="priority">
                                <?php foreach ( CMMS_Tasks::priorities() as $k => $v ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( ( $task ? $task->priority : 'normal' ) === $k ); ?>><?php echo esc_html( $this->t( 'priority.' . $k ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'task.status' ); ?></label>
                            <select class="cmms-select" name="status">
                                <?php foreach ( CMMS_Tasks::statuses() as $k => $v ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( ( $task ? $task->status : 'open' ) === $k ); ?>><?php echo esc_html( $this->t( 'status.' . $k ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cmms-field" data-cmms-due-field>
                            <label class="cmms-field-label"><?php $this->e( 'task.due' ); ?></label>
                            <input class="cmms-input" name="due_date" type="date" value="<?php echo $task && $task->due_date ? esc_attr( $task->due_date ) : ''; ?>">
                            <span class="cmms-field-help" data-cmms-due-help-recurring style="display:none;">
                                <?php $this->e( 'task.due_help_recurring' ); ?>
                            </span>
                        </div>
                    </div>

                    <div class="cmms-form-row cols-2">
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'task.manager' ); ?></label>
                            <select class="cmms-select" name="manager_id">
                                <option value=""><?php $this->e( 'common.none' ); ?></option>
                                <?php foreach ( $users as $usr ) : if ( ! in_array( $usr->role, array( 'owner', 'manager' ), true ) ) continue; ?>
                                    <option value="<?php echo (int) $usr->id; ?>" <?php selected( $task && $task->manager_id == $usr->id ); ?>><?php echo esc_html( $usr->display_name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'task.assigned_to' ); ?></label>
                            <select class="cmms-select" name="assigned_to">
                                <option value=""><?php $this->e( 'common.none' ); ?></option>
                                <?php foreach ( $users as $usr ) : if ( $usr->role === 'reporter' ) continue; ?>
                                    <option value="<?php echo (int) $usr->id; ?>" <?php selected( $task && $task->assigned_to == $usr->id ); ?>><?php echo esc_html( $usr->display_name ); ?> (<?php echo esc_html( $this->t( 'role.' . $usr->role ) ?: $usr->role ); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="cmms-form-row cols-2">
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'task.recurrence' ); ?></label>
                            <select class="cmms-select" name="recurrence_type" data-cmms-recurrence-type>
                                <?php foreach ( CMMS_Tasks::recurrence_types() as $k => $v ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( ( $task ? $task->recurrence_type : 'one_time' ) === $k ); ?>><?php echo esc_html( $this->t( 'rec.' . $k ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'task.recurrence_interval' ); ?></label>
                            <input class="cmms-input" name="recurrence_interval" type="number" min="0" value="<?php echo $task ? (int) $task->recurrence_interval : 0; ?>">
                        </div>
                    </div>

                    <?php
                    $current_rec = $task ? $task->recurrence_type : 'one_time';
                    $rec_until_val = ( $task && ! empty( $task->recurrence_until ) ) ? substr( $task->recurrence_until, 0, 10 ) : '';
                    ?>
                    <div class="cmms-field cmms-rec-until-wrap" data-cmms-rec-until-wrap style="<?php echo $current_rec === 'one_time' ? 'display:none;' : ''; ?>">
                        <label class="cmms-field-label">
                            <?php $this->e( 'task.recurrence_until' ); ?>
                            <span style="color:var(--c-red-600);">*</span>
                        </label>
                        <input class="cmms-input" name="recurrence_until" type="date" value="<?php echo esc_attr( $rec_until_val ); ?>">
                        <span class="cmms-field-help"><?php $this->e( 'task.recurrence_until_help' ); ?></span>
                    </div>

                    <?php if ( ! $is_edit ) : ?>
                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'task.attachments' ); ?></label>
                        <input class="cmms-input" name="attachments[]" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
                        <span class="cmms-field-help"><?php $this->e( 'task.attachments_help' ); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="cmms-form-actions">
                        <button type="submit" class="cmms-btn cmms-btn-primary">
                            <?php CMMS_Icons::e( 'save', 16 ); ?>
                            <?php $this->e( $is_edit ? 'task.update' : 'task.create' ); ?>
                        </button>
                        <a class="cmms-btn cmms-btn-ghost" href="<?php echo esc_url( $this->url( array( 'view' => $is_edit ? 'task' : 'tasks', 'id' => $task ? $task->id : null ) ) ); ?>">
                            <?php $this->e( 'common.cancel' ); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /* ============================================================
       VIEW: TASK DETAIL
    ============================================================ */
    private function view_task_detail( $u ) {
        $id = (int) ( $_GET['id'] ?? 0 );
        $task = CMMS_Tasks::get( $id );
        if ( ! $task || (int) $task->account_id !== (int) $u->account_id ) { $this->not_found(); return; }
        // Centralized visibility check (1.14.19): only related users and
        // owners/managers may load this view. Anyone else gets the same
        // not-found page so we don't leak that the task exists.
        if ( ! CMMS_Auth::can_view_task( $u, $task ) ) { $this->not_found(); return; }

        $logs = CMMS_Tasks::get_logs( $task->id );
        $atts = CMMS_Attachments::for_object( 'task', $task->id );
        // Render-time capability flags drive which buttons/forms appear.
        // Backend AJAX handlers re-check these — UI gating is convenience,
        // not security.
        $can_edit       = CMMS_Auth::can_edit_task( $u, $task );
        $can_status     = CMMS_Auth::can_participate_task( $u, $task );
        $can_attach     = CMMS_Auth::can_participate_task( $u, $task );
        $can_comment    = CMMS_Auth::can_participate_task( $u, $task );
        $asset_name = $task->asset_id ? CMMS_Assets::asset_name( $task->asset_id ) : '';
        $manager_name = $task->manager_id ? CMMS_Users::display_name( $task->manager_id ) : '';
        $assigned_name = $task->assigned_to ? CMMS_Users::display_name( $task->assigned_to ) : '';
        $creator_name = $task->created_by ? CMMS_Users::display_name( $task->created_by ) : '';
        $cat = $task->category_id ? CMMS_Categories::get( $task->category_id ) : null;
        ?>
        <div class="cmms-page-head">
            <div>
                <p class="cmms-page-sub">
                    <a href="<?php echo esc_url( $this->url( array( 'view' => 'tasks' ) ) ); ?>" style="text-decoration:none;color:inherit;">← <?php $this->e( 'task.back_to_list' ); ?></a>
                </p>
                <h1 class="cmms-page-title"><?php echo esc_html( $task->title ); ?></h1>
                <div class="cmms-flex cmms-gap-2 cmms-mt-3" style="flex-wrap:wrap;">
                    <span class="cmms-badge status-<?php echo esc_attr( $task->status ); ?>">
                        <span class="cmms-badge-dot"></span>
                        <?php echo esc_html( $this->t( 'status.' . $task->status ) ); ?>
                    </span>
                    <span class="cmms-badge priority-<?php echo esc_attr( $task->priority ); ?>">
                        <?php echo esc_html( $this->t( 'priority.' . $task->priority ) ); ?>
                    </span>
                </div>
            </div>
            <div class="cmms-page-actions">
                <?php if ( $can_edit ) : ?>
                    <a class="cmms-btn" href="<?php echo esc_url( $this->url( array( 'view' => 'task_edit', 'id' => $task->id ) ) ); ?>">
                        <?php CMMS_Icons::e( 'edit', 16 ); ?> <?php $this->e( 'common.edit' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="cmms-detail-grid">
            <div>
                <?php if ( $can_status ) : ?>
                <section class="cmms-section">
                    <div class="cmms-section-head">
                        <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'zap', 16 ); ?> <?php $this->e( 'task.quick_status' ); ?></h3>
                    </div>
                    <div class="cmms-section-body">
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-flex cmms-gap-2" style="flex-wrap:wrap;">
                            <input type="hidden" name="action" value="cmms_task_status">
                            <input type="hidden" name="task_id" value="<?php echo (int) $task->id; ?>">
                            <?php wp_nonce_field( 'cmms_task_status', 'cmms_task_status_nonce' ); ?>
                            <?php foreach ( CMMS_Tasks::statuses() as $k => $v ) : if ( $k === $task->status ) continue; ?>
                                <button type="submit" name="status" value="<?php echo esc_attr( $k ); ?>" class="cmms-btn cmms-btn-sm">
                                    <?php echo esc_html( $this->t( 'status.' . $k ) ); ?>
                                </button>
                            <?php endforeach; ?>
                        </form>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ( $task->description ) : ?>
                <section class="cmms-section">
                    <div class="cmms-section-head">
                        <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'file-text', 16 ); ?> <?php $this->e( 'task.description' ); ?></h3>
                    </div>
                    <div class="cmms-section-body">
                        <p style="white-space:pre-wrap;margin:0;color:var(--c-text);"><?php echo esc_html( $task->description ); ?></p>
                    </div>
                </section>
                <?php endif; ?>

                <section class="cmms-section">
                    <div class="cmms-section-head">
                        <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'paperclip', 16 ); ?> <?php $this->e( 'task.attachments' ); ?></h3>
                    </div>
                    <div class="cmms-section-body">
                        <?php if ( empty( $atts ) ) : ?>
                            <p class="cmms-muted cmms-text-sm" style="margin:0 0 16px 0;"><?php $this->e( 'task.no_attachments' ); ?></p>
                        <?php else : ?>
                            <div class="cmms-att-list cmms-mb-4">
                                <?php foreach ( $atts as $att ) :
                                    $name = ! empty( $att->original_name ) ? $att->original_name : $att->file_name;
                                ?>
                                    <a href="<?php echo esc_url( $att->file_url ); ?>" target="_blank" rel="noopener" class="cmms-att-item">
                                        <span class="cmms-att-icon"><?php CMMS_Icons::e( CMMS_Attachments::is_image( $att ) ? 'image' : 'file', 18 ); ?></span>
                                        <div>
                                            <div class="cmms-att-name"><?php echo esc_html( $name ); ?></div>
                                            <div class="cmms-att-meta"><?php echo esc_html( $att->mime_type ); ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" enctype="multipart/form-data" class="cmms-flex cmms-gap-2" style="align-items:flex-end;">
                            <input type="hidden" name="action" value="cmms_task_attach">
                            <input type="hidden" name="task_id" value="<?php echo (int) $task->id; ?>">
                            <?php wp_nonce_field( 'cmms_task_attach', 'cmms_task_attach_nonce' ); ?>
                            <input type="file" name="attachments[]" multiple class="cmms-input" style="flex:1;" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
                            <button type="submit" class="cmms-btn"><?php CMMS_Icons::e( 'upload', 16 ); ?> <?php $this->e( 'task.upload' ); ?></button>
                        </form>
                    </div>
                </section>

                <section class="cmms-section">
                    <div class="cmms-section-head">
                        <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'message-square', 16 ); ?> <?php $this->e( 'task.activity' ); ?></h3>
                    </div>
                    <div class="cmms-section-body">
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form cmms-mb-4">
                            <input type="hidden" name="action" value="cmms_task_comment">
                            <input type="hidden" name="task_id" value="<?php echo (int) $task->id; ?>">
                            <?php wp_nonce_field( 'cmms_task_comment', 'cmms_task_comment_nonce' ); ?>
                            <div class="cmms-field">
                                <textarea class="cmms-textarea" name="note" rows="2" placeholder="<?php echo esc_attr( $this->t( 'task.add_comment' ) ); ?>"></textarea>
                            </div>
                            <div>
                                <button type="submit" class="cmms-btn cmms-btn-secondary"><?php CMMS_Icons::e( 'send', 16 ); ?> <?php $this->e( 'task.post_comment' ); ?></button>
                            </div>
                        </form>

                        <?php if ( empty( $logs ) ) : ?>
                            <p class="cmms-muted cmms-text-sm" style="margin:0;"><?php $this->e( 'task.no_activity' ); ?></p>
                        <?php else : ?>
                            <ol class="cmms-log">
                                <?php foreach ( $logs as $log ) :
                                    $who = $log->user_id ? CMMS_Users::display_name( $log->user_id ) : $this->t( 'common.system' );
                                ?>
                                    <li class="cmms-log-item">
                                        <span class="cmms-log-dot"><?php CMMS_Icons::e( $log->action === 'comment' ? 'message-square' : 'activity', 14 ); ?></span>
                                        <div class="cmms-log-body">
                                            <div><span class="cmms-log-who"><?php echo esc_html( $who ); ?></span> <span class="cmms-log-action"><?php echo esc_html( $this->t( 'log.action.' . $log->action ) ?: $log->action ); ?></span></div>
                                            <div class="cmms-log-when"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $log->created_at ) ); ?></div>
                                            <?php if ( ! empty( $log->note ) ) : ?>
                                                <div class="cmms-log-note"><?php echo esc_html( $log->note ); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <aside>
                <section class="cmms-section">
                    <div class="cmms-section-head">
                        <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'info', 16 ); ?> <?php $this->e( 'task.details' ); ?></h3>
                    </div>
                    <div class="cmms-section-body">
                        <div class="cmms-detail-meta-list">
                            <?php if ( $cat ) : ?>
                                <div class="cmms-detail-meta-item">
                                    <span class="cmms-detail-meta-label"><?php $this->e( 'task.category' ); ?></span>
                                    <span class="cmms-detail-meta-value"><?php echo esc_html( $cat->name ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $asset_name ) : ?>
                                <div class="cmms-detail-meta-item">
                                    <span class="cmms-detail-meta-label"><?php $this->e( 'task.asset' ); ?></span>
                                    <span class="cmms-detail-meta-value"><?php echo esc_html( $asset_name ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $task->due_date ) : ?>
                                <div class="cmms-detail-meta-item">
                                    <span class="cmms-detail-meta-label"><?php $this->e( 'task.due' ); ?></span>
                                    <span class="cmms-detail-meta-value"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $task->due_date ) ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $manager_name ) : ?>
                                <div class="cmms-detail-meta-item">
                                    <span class="cmms-detail-meta-label"><?php $this->e( 'task.manager' ); ?></span>
                                    <span class="cmms-detail-meta-value"><?php echo esc_html( $manager_name ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $assigned_name ) : ?>
                                <div class="cmms-detail-meta-item">
                                    <span class="cmms-detail-meta-label"><?php $this->e( 'task.assigned_to' ); ?></span>
                                    <span class="cmms-detail-meta-value"><?php echo esc_html( $assigned_name ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $creator_name ) : ?>
                                <div class="cmms-detail-meta-item">
                                    <span class="cmms-detail-meta-label"><?php $this->e( 'task.created_by' ); ?></span>
                                    <span class="cmms-detail-meta-value"><?php echo esc_html( $creator_name ); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="cmms-detail-meta-item">
                                <span class="cmms-detail-meta-label"><?php $this->e( 'task.created_at' ); ?></span>
                                <span class="cmms-detail-meta-value"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $task->created_at ) ); ?></span>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
        <?php
    }

    /* ============================================================
       VIEW: ASSETS LIST
    ============================================================ */
    private function view_assets( $u ) {
        $assets = CMMS_Assets::list_by_account( $u->account_id );
        $can_manage = CMMS_Auth::can( 'manage_assets' );
        ?>
        <div class="cmms-page-head" data-cmms-page="assets">
            <div>
                <h1 class="cmms-page-title"><?php $this->e( 'asset.list_title' ); ?></h1>
                <p class="cmms-page-sub"><?php echo (int) count( $assets ); ?> <?php $this->e( 'asset.results' ); ?></p>
            </div>
            <?php if ( $can_manage ) : ?>
            <div class="cmms-page-actions">
                <a class="cmms-btn cmms-btn-primary" href="<?php echo esc_url( $this->url( array( 'view' => 'asset_new' ) ) ); ?>">
                    <?php CMMS_Icons::e( 'plus', 16 ); ?> <?php $this->e( 'asset.create' ); ?>
                </a>
                <!--
                    Deliberately a plain link to the centralized settings tab,
                    not a separate "asset settings" UI. The Asset Fields
                    definitions live in Settings as a single source of truth;
                    this button is just a shortcut.
                -->
                <a class="cmms-btn cmms-btn-ghost" href="<?php echo esc_url( $this->url( array( 'view' => 'settings', 'tab' => 'asset_fields' ) ) ); ?>">
                    <?php CMMS_Icons::e( 'settings', 16 ); ?> <?php $this->e( 'asset.settings_link' ); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ( empty( $assets ) ) : ?>
            <div class="cmms-section">
                <div class="cmms-section-body">
                    <?php $this->empty_state( 'package', 'asset.no_assets', 'asset.no_assets_d',
                        $can_manage ? '<a class="cmms-btn cmms-btn-primary" href="' . esc_url( $this->url( array( 'view' => 'asset_new' ) ) ) . '">' . esc_html( $this->t( 'asset.create' ) ) . '</a>' : ''
                    ); ?>
                </div>
            </div>
        <?php else : ?>
            <div class="cmms-asset-grid">
                <?php foreach ( $assets as $asset ) :
                    $icon = CMMS_Icons::for_asset_type( $asset->asset_type );
                ?>
                    <a class="cmms-asset-card" href="<?php echo esc_url( $this->url( array( 'view' => 'asset', 'id' => $asset->id ) ) ); ?>">
                        <span class="cmms-asset-card-icon"><?php CMMS_Icons::e( $icon, 22 ); ?></span>
                        <span class="cmms-asset-card-type"><?php echo esc_html( $this->t( 'asset.type.' . $asset->asset_type ) ?: $asset->asset_type ); ?></span>
                        <span class="cmms-asset-card-name"><?php echo esc_html( $asset->name ); ?></span>
                        <?php if ( $asset->location ) : ?>
                            <span class="cmms-asset-card-meta"><?php CMMS_Icons::e( 'map-pin', 14 ); ?> <?php echo esc_html( $asset->location ); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    private function view_asset_form( $u, $is_edit = false ) {
        if ( ! CMMS_Auth::can( 'manage_assets' ) ) { $this->forbidden(); return; }
        $asset = null;
        if ( $is_edit ) {
            $id = (int) ( $_GET['id'] ?? 0 );
            $asset = CMMS_Assets::get( $id );
            if ( ! $asset || (int) $asset->account_id !== (int) $u->account_id ) { $this->not_found(); return; }
        }

        // Per spec: all custom fields apply to every asset regardless of type.
        // We pass empty string for asset_type which now ignores the filter.
        $field_defs   = CMMS_Assets::list_field_defs( (int) $u->account_id );
        $stored       = $asset ? CMMS_Assets::decode_custom_fields( $asset ) : array();
        $stored_by_key = array();
        foreach ( $stored as $s ) $stored_by_key[ $s['field_key'] ] = $s;

        $public_actions_catalog = CMMS_Assets::public_actions_catalog();
        $current_public_actions = $asset ? CMMS_Assets::decode_public_actions( $asset ) : CMMS_Assets::default_public_actions();
        ?>
        <div class="cmms-page-head">
            <div>
                <h1 class="cmms-page-title"><?php $this->e( $is_edit ? 'asset.edit_title' : 'asset.new_title' ); ?></h1>
            </div>
        </div>

        <div class="cmms-section">
            <div class="cmms-section-body">
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form">
                    <input type="hidden" name="action" value="<?php echo $is_edit ? 'cmms_asset_update' : 'cmms_asset_create'; ?>">
                    <?php if ( $is_edit ) : ?><input type="hidden" name="asset_id" value="<?php echo (int) $asset->id; ?>"><?php endif; ?>
                    <?php wp_nonce_field( $is_edit ? 'cmms_asset_update' : 'cmms_asset_create', $is_edit ? 'cmms_asset_update_nonce' : 'cmms_asset_create_nonce' ); ?>

                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'asset.name' ); ?> <span class="req">*</span></label>
                        <input class="cmms-input" name="name" type="text" required value="<?php echo $asset ? esc_attr( $asset->name ) : ''; ?>">
                    </div>
                    <div class="cmms-form-row cols-2">
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'asset.type' ); ?></label>
                            <select class="cmms-select" name="asset_type">
                                <?php foreach ( CMMS_Assets::asset_types() as $k => $v ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $asset && $asset->asset_type === $k ); ?>><?php echo esc_html( $this->t( 'asset.type.' . $k ) ?: $v ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'asset.location' ); ?></label>
                            <input class="cmms-input" name="location" type="text" value="<?php echo $asset ? esc_attr( $asset->location ) : ''; ?>">
                        </div>
                    </div>
                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'task.description' ); ?></label>
                        <textarea class="cmms-textarea" name="description" rows="3"><?php echo $asset ? esc_textarea( $asset->description ) : ''; ?></textarea>
                    </div>

                    <?php if ( ! empty( $field_defs ) ) : ?>
                    <fieldset class="cmms-form-section" style="border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:16px 0;">
                        <legend style="padding:0 8px;font-weight:600;color:#0f172a;"><?php $this->e( 'asset.custom_fields' ); ?></legend>
                        <?php foreach ( $field_defs as $def ) :
                            $existing = isset( $stored_by_key[ $def->field_key ] ) ? $stored_by_key[ $def->field_key ] : null;
                            $val      = $existing ? $existing['value'] : '';
                            $name     = 'cf[' . esc_attr( $def->field_key ) . ']';
                        ?>
                            <div class="cmms-field">
                                <label class="cmms-field-label">
                                    <?php echo esc_html( $def->label ); ?>
                                    <?php if ( $def->required ) : ?><span class="req">*</span><?php endif; ?>
                                </label>
                                <input type="hidden" name="cf_meta[<?php echo esc_attr( $def->field_key ); ?>][label]" value="<?php echo esc_attr( $def->label ); ?>">
                                <input type="hidden" name="cf_meta[<?php echo esc_attr( $def->field_key ); ?>][type]" value="<?php echo esc_attr( $def->field_type ); ?>">
                                <?php if ( $def->field_type === 'textarea' ) : ?>
                                    <textarea class="cmms-textarea" name="<?php echo esc_attr( $name ); ?>" rows="2" <?php if ( $def->required ) echo 'required'; ?>><?php echo esc_textarea( (string) $val ); ?></textarea>
                                <?php elseif ( $def->field_type === 'number' ) : ?>
                                    <input class="cmms-input" type="number" step="any" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $val ); ?>" <?php if ( $def->required ) echo 'required'; ?>>
                                <?php elseif ( $def->field_type === 'date' ) : ?>
                                    <input class="cmms-input" type="date" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $val ); ?>" <?php if ( $def->required ) echo 'required'; ?>>
                                <?php elseif ( $def->field_type === 'select' ) :
                                    $opts = $def->options ? json_decode( $def->options, true ) : array();
                                    if ( ! is_array( $opts ) ) $opts = array();
                                ?>
                                    <select class="cmms-select" name="<?php echo esc_attr( $name ); ?>" <?php if ( $def->required ) echo 'required'; ?>>
                                        <option value="">—</option>
                                        <?php foreach ( $opts as $opt ) : ?>
                                            <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $val === $opt ); ?>><?php echo esc_html( $opt ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ( $def->field_type === 'checkbox' ) : ?>
                                    <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                        <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $val ) ); ?>>
                                        <?php echo esc_html( $def->label ); ?>
                                    </label>
                                <?php else : ?>
                                    <input class="cmms-input" type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $val ); ?>" <?php if ( $def->required ) echo 'required'; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                    <?php endif; ?>

                    <fieldset class="cmms-form-section" style="border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:16px 0;">
                        <legend style="padding:0 8px;font-weight:600;color:#0f172a;"><?php $this->e( 'asset.qr_settings' ); ?></legend>
                        <div class="cmms-field" style="margin-bottom:12px;">
                            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                <input type="checkbox" name="public_qr_enabled" value="1" <?php checked( ! $asset || ! empty( $asset->public_qr_enabled ) ); ?>>
                                <span><?php $this->e( 'asset.qr_enabled' ); ?></span>
                            </label>
                            <p class="cmms-muted cmms-text-sm" style="margin:4px 0 0 24px;"><?php $this->e( 'asset.qr_enabled_help' ); ?></p>
                        </div>
                        <div class="cmms-field">
                            <label class="cmms-field-label"><?php $this->e( 'asset.public_actions' ); ?></label>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <?php foreach ( $public_actions_catalog as $key => $label ) : ?>
                                    <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                        <input type="checkbox" name="public_actions[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $current_public_actions, true ) ); ?>>
                                        <span><?php echo esc_html( $label ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </fieldset>

                    <div class="cmms-form-actions">
                        <button type="submit" class="cmms-btn cmms-btn-primary"><?php CMMS_Icons::e( 'save', 16 ); ?> <?php $this->e( $is_edit ? 'asset.update' : 'asset.create' ); ?></button>
                        <a class="cmms-btn cmms-btn-ghost" href="<?php echo esc_url( $this->url( array( 'view' => 'assets' ) ) ); ?>"><?php $this->e( 'common.cancel' ); ?></a>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function view_asset_detail( $u ) {
        $id = (int) ( $_GET['id'] ?? 0 );
        $asset = CMMS_Assets::get( $id );
        if ( ! $asset || (int) $asset->account_id !== (int) $u->account_id ) { $this->not_found(); return; }
        $can_manage = CMMS_Auth::can( 'manage_assets' );
        $can_create_task = CMMS_Auth::can( 'create_task' );

        global $wpdb;
        $t_tasks = CMMS_DB::table( 'tasks' );
        // Visibility scoping (1.14.19): owners/managers see every task on
        // the asset; everyone else only the related ones (assignee/manager/
        // creator). Without this, an asset detail page would leak the
        // existence and titles of tasks that the viewer has no business
        // seeing through the regular task list.
        $is_priv = in_array( $u->role, array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER ), true );
        if ( $is_priv ) {
            $tasks = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $t_tasks WHERE asset_id = %d AND account_id = %d ORDER BY created_at DESC LIMIT 20",
                $asset->id, $u->account_id
            ) );
            $task_count_total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $t_tasks WHERE asset_id = %d AND account_id = %d",
                $asset->id, $u->account_id
            ) );
        } else {
            $tasks = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $t_tasks
                  WHERE asset_id = %d AND account_id = %d
                    AND ( assigned_to = %d OR manager_id = %d OR created_by = %d )
                  ORDER BY created_at DESC LIMIT 20",
                $asset->id, $u->account_id, $u->id, $u->id, $u->id
            ) );
            $task_count_total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $t_tasks
                  WHERE asset_id = %d AND account_id = %d
                    AND ( assigned_to = %d OR manager_id = %d OR created_by = %d )",
                $asset->id, $u->account_id, $u->id, $u->id, $u->id
            ) );
        }

        // Custom fields: stored values + account-level definitions for fields the user
        // hasn't filled in yet. Show the stored ones first, then any unfilled defs.
        // Per spec: all fields apply to all assets, no per-type filtering.
        $stored_fields = CMMS_Assets::decode_custom_fields( $asset );
        $stored_keys   = array_column( $stored_fields, 'field_key' );
        $field_defs    = CMMS_Assets::list_field_defs( (int) $u->account_id );

        $type_label = '';
        $types = CMMS_Assets::asset_types();
        if ( isset( $types[ $asset->asset_type ] ) ) $type_label = $types[ $asset->asset_type ];

        $public_url = CMMS_Assets::public_url( $asset );
        $qr_image   = CMMS_Assets::qr_image_url( $asset );
        ?>
        <div class="cmms-page-head">
            <div>
                <p class="cmms-page-sub"><a href="<?php echo esc_url( $this->url( array( 'view' => 'assets' ) ) ); ?>" style="text-decoration:none;color:inherit;">← <?php $this->e( 'asset.back_to_list' ); ?></a></p>
                <h1 class="cmms-page-title"><?php echo esc_html( $asset->name ); ?></h1>
                <p class="cmms-page-sub">
                    <?php echo esc_html( $type_label ); ?>
                    <?php if ( $asset->location ) : ?>
                        · <?php CMMS_Icons::e( 'map-pin', 12 ); ?> <?php echo esc_html( $asset->location ); ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php if ( $can_manage ) : ?>
            <div class="cmms-page-actions">
                <a class="cmms-btn" href="<?php echo esc_url( $this->url( array( 'view' => 'asset_edit', 'id' => $asset->id ) ) ); ?>">
                    <?php CMMS_Icons::e( 'edit', 16 ); ?> <?php $this->e( 'common.edit' ); ?>
                </a>
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="display:inline;" class="cmms-confirm-delete" data-confirm="<?php echo esc_attr( $this->t( 'common.confirm_delete' ) ); ?>">
                    <input type="hidden" name="action" value="cmms_asset_delete">
                    <input type="hidden" name="asset_id" value="<?php echo (int) $asset->id; ?>">
                    <?php wp_nonce_field( 'cmms_asset_delete', 'cmms_asset_delete_nonce' ); ?>
                    <button type="submit" class="cmms-btn cmms-btn-danger"><?php CMMS_Icons::e( 'trash', 16 ); ?> <?php $this->e( 'common.delete' ); ?></button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick action bar: the verbs a technician most often wants from this screen. -->
        <section class="cmms-asset-actions" aria-label="<?php echo esc_attr( $this->t( 'asset.actions' ) ); ?>">
            <?php if ( $can_create_task ) : ?>
            <a class="cmms-asset-action cmms-asset-action-breakdown"
               href="<?php echo esc_url( $this->url( array( 'view' => 'task_new', 'asset_id' => $asset->id, 'kind' => 'breakdown' ) ) ); ?>">
                <span class="cmms-asset-action-icon" style="background:#fef2f2;color:#ef4444;">
                    <?php CMMS_Icons::e( 'alert-triangle', 20 ); ?>
                </span>
                <span><?php $this->e( 'asset.action_breakdown' ); ?></span>
            </a>
            <a class="cmms-asset-action cmms-asset-action-planned"
               href="<?php echo esc_url( $this->url( array( 'view' => 'task_new', 'asset_id' => $asset->id, 'kind' => 'planned' ) ) ); ?>">
                <span class="cmms-asset-action-icon" style="background:#ecfdf5;color:#10b981;">
                    <?php CMMS_Icons::e( 'calendar', 20 ); ?>
                </span>
                <span><?php $this->e( 'asset.action_planned' ); ?></span>
            </a>
            <?php endif; ?>
            <a class="cmms-asset-action"
               href="#cmms-asset-tasks">
                <span class="cmms-asset-action-icon" style="background:#eff6ff;color:#3b82f6;">
                    <?php CMMS_Icons::e( 'list', 20 ); ?>
                </span>
                <span><?php $this->e( 'asset.action_history' ); ?> <small>(<?php echo (int) $task_count_total; ?>)</small></span>
            </a>
            <a class="cmms-asset-action"
               href="#cmms-asset-qr">
                <span class="cmms-asset-action-icon" style="background:#fef3c7;color:#d97706;">
                    <?php CMMS_Icons::e( 'qr-code', 20 ); ?>
                </span>
                <span><?php $this->e( 'asset.action_qr' ); ?></span>
            </a>
        </section>

        <?php if ( $asset->description ) : ?>
        <div class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title"><?php $this->e( 'asset.description' ); ?></h3>
            </div>
            <div class="cmms-section-body">
                <p style="margin:0;white-space:pre-wrap;"><?php echo esc_html( $asset->description ); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Custom fields (the customer's own structure) -->
        <?php if ( ! empty( $stored_fields ) || ! empty( $field_defs ) ) : ?>
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title"><?php $this->e( 'asset.details' ); ?></h3>
                <?php if ( $can_manage ) : ?>
                    <a class="cmms-btn cmms-btn-sm cmms-btn-secondary"
                       href="<?php echo esc_url( $this->url( array( 'view' => 'asset_edit', 'id' => $asset->id ) ) ); ?>">
                        <?php $this->e( 'common.edit' ); ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="cmms-section-body">
                <?php
                // Build map field_key => is_public so we can show a small badge
                // next to fields exposed on the public QR page (helps the admin
                // remember what's leaking publicly).
                $public_keys_map = array();
                foreach ( $field_defs as $d ) {
                    $public_keys_map[ $d->field_key ] = ! empty( $d->is_public );
                }
                ?>
                <dl class="cmms-asset-fields">
                <?php foreach ( $stored_fields as $field ) :
                    $val = isset( $field['value'] ) ? $field['value'] : '';
                    if ( $val === '' || $val === null ) continue;
                    $label = isset( $field['label'] ) ? $field['label'] : $field['field_key'];
                    $type  = isset( $field['type'] ) ? $field['type'] : 'text';
                    $is_pub = ! empty( $public_keys_map[ $field['field_key'] ] );
                    ?>
                    <div class="cmms-asset-field-row">
                        <dt>
                            <?php echo esc_html( $label ); ?>
                            <?php if ( $is_pub ) : ?>
                                <span class="cmms-asset-field-public-badge" title="<?php echo esc_attr( $this->t( 'asset.field_public_badge' ) ); ?>">
                                    <?php CMMS_Icons::e( 'eye', 11 ); ?>
                                </span>
                            <?php endif; ?>
                        </dt>
                        <dd>
                            <?php if ( $type === 'checkbox' ) : ?>
                                <?php echo $val ? '✓' : '—'; ?>
                            <?php else : ?>
                                <?php echo esc_html( (string) $val ); ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
                <?php
                // Show empty defs as "—" so users know which fields exist but are unfilled.
                foreach ( $field_defs as $def ) :
                    if ( in_array( $def->field_key, $stored_keys, true ) ) continue;
                ?>
                    <div class="cmms-asset-field-row cmms-asset-field-empty">
                        <dt>
                            <?php echo esc_html( $def->label ); ?>
                            <?php if ( ! empty( $def->is_public ) ) : ?>
                                <span class="cmms-asset-field-public-badge" title="<?php echo esc_attr( $this->t( 'asset.field_public_badge' ) ); ?>">
                                    <?php CMMS_Icons::e( 'eye', 11 ); ?>
                                </span>
                            <?php endif; ?>
                        </dt>
                        <dd>—</dd>
                    </div>
                <?php endforeach; ?>
                </dl>
            </div>
        </section>
        <?php endif; ?>

        <!-- QR section -->
        <section class="cmms-section" id="cmms-asset-qr">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'qr-code', 16 ); ?> <?php $this->e( 'asset.qr_title' ); ?></h3>
            </div>
            <div class="cmms-section-body">
                <div class="cmms-asset-qr-block">
                    <?php if ( $qr_image ) : ?>
                        <img src="<?php echo esc_url( $qr_image ); ?>" alt="QR" width="200" height="200" loading="lazy" style="border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
                    <?php endif; ?>
                    <div class="cmms-asset-qr-info">
                        <p style="margin:0 0 6px;color:#64748b;font-size:13px;"><?php $this->e( 'asset.qr_help' ); ?></p>
                        <?php if ( $public_url ) : ?>
                        <code class="cmms-asset-qr-url"><?php echo esc_html( $public_url ); ?></code>
                        <div class="cmms-asset-qr-actions">
                            <a class="cmms-btn cmms-btn-sm" href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener">
                                <?php $this->e( 'asset.qr_open' ); ?>
                            </a>
                            <a class="cmms-btn cmms-btn-sm" href="<?php echo esc_url( $this->url( array( 'view' => 'asset_qr_print', 'id' => $asset->id ) ) ); ?>" target="_blank" rel="noopener">
                                <?php CMMS_Icons::e( 'printer', 14 ); ?> <?php $this->e( 'asset.qr_print' ); ?>
                            </a>
                            <?php if ( $qr_image ) : ?>
                            <a class="cmms-btn cmms-btn-sm" href="<?php echo esc_url( $qr_image ); ?>" download="qr-<?php echo esc_attr( $asset->id ); ?>.png">
                                <?php CMMS_Icons::e( 'download', 14 ); ?> <?php $this->e( 'asset.qr_download' ); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <p style="margin:12px 0 0;color:#64748b;font-size:12px;">
                            <?php if ( $asset->public_qr_enabled ) : ?>
                                <span style="color:#10b981;">●</span> <?php $this->e( 'asset.qr_public_on' ); ?>
                            <?php else : ?>
                                <span style="color:#94a3b8;">●</span> <?php $this->e( 'asset.qr_public_off' ); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Task history -->
        <section class="cmms-section" id="cmms-asset-tasks">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'list', 16 ); ?> <?php $this->e( 'asset.tasks' ); ?></h3>
                <?php if ( $task_count_total > count( $tasks ) ) : ?>
                    <span class="cmms-muted cmms-text-sm"><?php echo esc_html( sprintf( $this->t( 'asset.tasks_showing' ), count( $tasks ), $task_count_total ) ); ?></span>
                <?php endif; ?>
            </div>
            <div class="cmms-section-body">
                <?php if ( empty( $tasks ) ) : ?>
                    <p class="cmms-muted cmms-text-sm" style="margin:0;"><?php $this->e( 'asset.no_tasks' ); ?></p>
                <?php else : ?>
                    <div class="cmms-list">
                        <?php foreach ( $tasks as $task ) $this->task_card( $task, $u ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Print-friendly QR label page. Opened in a new window when the user
     * clicks "Print QR". Strips the chrome and centers the QR code.
     */
    private function view_asset_qr_print( $u ) {
        $id = (int) ( $_GET['id'] ?? 0 );
        $asset = CMMS_Assets::get( $id );
        if ( ! $asset || (int) $asset->account_id !== (int) $u->account_id ) { $this->not_found(); return; }

        $qr_image = CMMS_Assets::qr_image_url( $asset );
        $type_label = '';
        $types = CMMS_Assets::asset_types();
        if ( isset( $types[ $asset->asset_type ] ) ) $type_label = $types[ $asset->asset_type ];
        ?>
        <style>
            @media print {
                body > header, body > footer,
                .cmms-topbar, .cmms-sidebar, .cmms-main-header,
                .cmms-page-head, .cmms-asset-qr-print-actions { display: none !important; }
                .cmms-asset-qr-print { padding: 0 !important; }
            }
            .cmms-asset-qr-print {
                max-width: 360px;
                margin: 40px auto;
                text-align: center;
                padding: 20px;
                border: 2px solid #0b1c33;
                border-radius: 12px;
                background: #fff;
            }
            .cmms-asset-qr-print h2 { margin: 0 0 4px; font-size: 18px; color: #0b1c33; }
            .cmms-asset-qr-print .cmms-asset-qr-print-type { color: #64748b; font-size: 13px; margin-bottom: 16px; }
            .cmms-asset-qr-print img { width: 280px; height: 280px; }
            .cmms-asset-qr-print-loc { margin-top: 12px; color: #475569; font-size: 13px; }
        </style>
        <div class="cmms-asset-qr-print-actions" style="text-align:center;margin:16px;">
            <button type="button" class="cmms-btn cmms-btn-primary" onclick="window.print();return false;">
                <?php $this->e( 'asset.qr_print_now' ); ?>
            </button>
        </div>
        <div class="cmms-asset-qr-print">
            <h2><?php echo esc_html( $asset->name ); ?></h2>
            <div class="cmms-asset-qr-print-type"><?php echo esc_html( $type_label ); ?></div>
            <?php if ( $qr_image ) : ?>
                <img src="<?php echo esc_url( $qr_image ); ?>" alt="QR">
            <?php endif; ?>
            <?php if ( $asset->location ) : ?>
                <div class="cmms-asset-qr-print-loc"><?php echo esc_html( $asset->location ); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ============================================================
       VIEW: REPORTS / FORMS / USERS / SETTINGS - simpler
    ============================================================ */
    private function view_reports( $u ) {
        if ( ! CMMS_Auth::can( 'view_reports' ) ) { $this->forbidden(); return; }
        $range = CMMS_TimeRange::from_request();
        $report = CMMS_Reports::dashboard_stats( $u->account_id, $range );
        $by_tech = $report['by_tech'] ?? array();
        $by_cat  = $report['by_cat'] ?? array();
        // 1.14.24: rich operational KPIs computed in one go.
        $kpi = CMMS_Reports::kpi_dashboard( $u->account_id, $range );
        ?>
        <div class="cmms-page-head" data-cmms-page="reports">
            <div>
                <h1 class="cmms-page-title"><?php $this->e( 'reports.title' ); ?></h1>
            </div>
            <div class="cmms-page-actions">
                <?php $this->range_picker( 'reports' ); ?>
            </div>
        </div>

        <?php
        // Format helpers — kept inline here so the view file is self-contained.
        // For minutes < 60 show "Xm"; under 24h show "Xh"; beyond, "Xd Yh".
        $fmt_response = function( $minutes ) {
            if ( $minutes === null ) return null;
            if ( $minutes < 60 ) return $minutes . ' ' . $this->t( 'reports.minutes' );
            $hours = round( $minutes / 60, 1 );
            if ( $hours < 24 ) return $hours . ' ' . $this->t( 'reports.hours' );
            $days = round( $hours / 24, 1 );
            return $days . ' ' . $this->t( 'reports.days' );
        };
        $fmt_closure = function( $hours ) {
            if ( $hours === null ) return null;
            if ( $hours < 24 ) return $hours . ' ' . $this->t( 'reports.hours' );
            $days = round( $hours / 24, 1 );
            return $days . ' ' . $this->t( 'reports.days' );
        };
        $no_data = $this->t( 'reports.kpi_no_data' );
        $not_measured = $this->t( 'reports.kpi_not_measured' );

        // SLA color: red < 70%, yellow < 90%, green otherwise. Helps the
        // eye spot risk without reading every number.
        $sla_class = 'neutral';
        if ( $kpi['sla_compliance_pct'] !== null ) {
            if ( $kpi['sla_compliance_pct'] < 70 )       $sla_class = 'danger';
            elseif ( $kpi['sla_compliance_pct'] < 90 )   $sla_class = 'warning';
            else                                          $sla_class = 'success';
        }
        ?>

        <!-- KPI grid: 8 operational indicators arranged on a responsive
             auto-fit. Each card is a small visual unit; color tone hints
             at status (warning for backlog, danger for overdue, etc.). -->
        <div class="cmms-kpi-grid">
            <!-- 1. Open tasks -->
            <div class="cmms-kpi-card warning">
                <div class="cmms-kpi-icon"><?php CMMS_Icons::e( 'clock', 20 ); ?></div>
                <div class="cmms-kpi-body">
                    <div class="cmms-kpi-value"><?php echo (int) $kpi['open_count']; ?></div>
                    <div class="cmms-kpi-label"><?php $this->e( 'reports.kpi_open' ); ?></div>
                </div>
            </div>

            <!-- 2. Overdue -->
            <div class="cmms-kpi-card danger">
                <div class="cmms-kpi-icon"><?php CMMS_Icons::e( 'alert-triangle', 20 ); ?></div>
                <div class="cmms-kpi-body">
                    <div class="cmms-kpi-value"><?php echo (int) $kpi['overdue_count']; ?></div>
                    <div class="cmms-kpi-label"><?php $this->e( 'reports.kpi_overdue' ); ?></div>
                </div>
            </div>

            <!-- 3. Completed this week — throughput indicator -->
            <div class="cmms-kpi-card success">
                <div class="cmms-kpi-icon"><?php CMMS_Icons::e( 'check-circle', 20 ); ?></div>
                <div class="cmms-kpi-body">
                    <div class="cmms-kpi-value"><?php echo (int) $kpi['completed_this_week']; ?></div>
                    <div class="cmms-kpi-label"><?php $this->e( 'reports.kpi_completed_week' ); ?></div>
                </div>
            </div>

            <!-- 4. Avg response time -->
            <div class="cmms-kpi-card neutral">
                <div class="cmms-kpi-icon"><?php CMMS_Icons::e( 'activity', 20 ); ?></div>
                <div class="cmms-kpi-body">
                    <div class="cmms-kpi-value">
                        <?php echo $kpi['avg_response_minutes'] !== null
                            ? esc_html( $fmt_response( $kpi['avg_response_minutes'] ) )
                            : '<span class="cmms-kpi-empty">' . esc_html( $no_data ) . '</span>'; ?>
                    </div>
                    <div class="cmms-kpi-label"><?php $this->e( 'reports.kpi_avg_response' ); ?></div>
                </div>
            </div>

            <!-- 5. Avg closure time -->
            <div class="cmms-kpi-card neutral">
                <div class="cmms-kpi-icon"><?php CMMS_Icons::e( 'check-circle', 20 ); ?></div>
                <div class="cmms-kpi-body">
                    <div class="cmms-kpi-value">
                        <?php echo $kpi['avg_closure_hours'] !== null
                            ? esc_html( $fmt_closure( $kpi['avg_closure_hours'] ) )
                            : '<span class="cmms-kpi-empty">' . esc_html( $no_data ) . '</span>'; ?>
                    </div>
                    <div class="cmms-kpi-label"><?php $this->e( 'reports.kpi_avg_closure' ); ?></div>
                </div>
            </div>

            <!-- 6. SLA compliance % -->
            <div class="cmms-kpi-card <?php echo esc_attr( $sla_class ); ?>">
                <div class="cmms-kpi-icon"><?php CMMS_Icons::e( 'target', 20 ); ?></div>
                <div class="cmms-kpi-body">
                    <div class="cmms-kpi-value">
                        <?php echo $kpi['sla_compliance_pct'] !== null
                            ? (int) $kpi['sla_compliance_pct'] . '%'
                            : '<span class="cmms-kpi-empty">' . esc_html( $not_measured ) . '</span>'; ?>
                    </div>
                    <div class="cmms-kpi-label"><?php $this->e( 'reports.kpi_sla' ); ?></div>
                </div>
            </div>

            <!-- 7. Most loaded technician -->
            <div class="cmms-kpi-card neutral">
                <div class="cmms-kpi-icon"><?php CMMS_Icons::e( 'user', 20 ); ?></div>
                <div class="cmms-kpi-body">
                    <?php if ( $kpi['top_tech'] && (int) $kpi['top_tech']->open_count > 0 ) : ?>
                        <div class="cmms-kpi-value cmms-kpi-name"><?php echo esc_html( $kpi['top_tech']->display_name ); ?></div>
                        <div class="cmms-kpi-label">
                            <?php $this->e( 'reports.kpi_top_tech' ); ?>
                            <span class="cmms-kpi-meta"><?php echo sprintf( esc_html( $this->t( 'reports.kpi_open_tasks_n' ) ), (int) $kpi['top_tech']->open_count ); ?></span>
                        </div>
                    <?php else : ?>
                        <div class="cmms-kpi-value"><span class="cmms-kpi-empty"><?php echo esc_html( $no_data ); ?></span></div>
                        <div class="cmms-kpi-label"><?php $this->e( 'reports.kpi_top_tech' ); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 8. Most problematic asset -->
            <div class="cmms-kpi-card neutral">
                <div class="cmms-kpi-icon"><?php CMMS_Icons::e( 'package', 20 ); ?></div>
                <div class="cmms-kpi-body">
                    <?php if ( $kpi['top_asset'] && (int) $kpi['top_asset']->task_count > 0 ) : ?>
                        <div class="cmms-kpi-value cmms-kpi-name"><?php echo esc_html( $kpi['top_asset']->name ); ?></div>
                        <div class="cmms-kpi-label">
                            <?php $this->e( 'reports.kpi_top_asset' ); ?>
                            <span class="cmms-kpi-meta"><?php echo sprintf( esc_html( $this->t( 'reports.kpi_total_tasks_n' ) ), (int) $kpi['top_asset']->task_count ); ?></span>
                        </div>
                    <?php else : ?>
                        <div class="cmms-kpi-value"><span class="cmms-kpi-empty"><?php echo esc_html( $no_data ); ?></span></div>
                        <div class="cmms-kpi-label"><?php $this->e( 'reports.kpi_top_asset' ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php
        // Legacy summary 4-card row removed — covered by KPI grid above.
        ?>

        <!-- Category breakdown — visual cards with progress bars -->
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'tag', 16 ); ?> <?php $this->e( 'reports.cat_breakdown' ); ?></h3>
                <p class="cmms-section-sub"><?php $this->e( 'reports.cat_breakdown_d' ); ?></p>
            </div>
            <div class="cmms-section-body">
                <?php if ( empty( $by_cat ) ) : ?>
                    <p class="cmms-muted cmms-text-sm"><?php $this->e( 'reports.no_data' ); ?></p>
                <?php else : ?>
                    <div class="cmms-cat-grid">
                        <?php foreach ( $by_cat as $cat ) :
                            $total_c = (int) $cat->total_count;
                            $open_c  = (int) $cat->open_count;
                            $done_c  = (int) $cat->completed_count;
                            $pct = $total_c > 0 ? round( ( $done_c / $total_c ) * 100 ) : 0;
                            $tasks_url = $this->url( array( 'view' => 'tasks', 'category_id' => $cat->id ) );
                        ?>
                            <a href="<?php echo esc_url( $tasks_url ); ?>" class="cmms-cat-card">
                                <div class="cmms-cat-card-head">
                                    <span class="cmms-cat-card-icon"><?php CMMS_Icons::e( 'tag', 16 ); ?></span>
                                    <span class="cmms-cat-card-name"><?php echo esc_html( $cat->name ); ?></span>
                                </div>
                                <div class="cmms-cat-card-stats">
                                    <div class="cmms-cat-card-stat">
                                        <span class="cmms-cat-card-stat-num"><?php echo (int) $open_c; ?></span>
                                        <span class="cmms-cat-card-stat-label"><?php $this->e( 'stat.open' ); ?></span>
                                    </div>
                                    <div class="cmms-cat-card-stat">
                                        <span class="cmms-cat-card-stat-num"><?php echo (int) $done_c; ?></span>
                                        <span class="cmms-cat-card-stat-label"><?php $this->e( 'stat.completed' ); ?></span>
                                    </div>
                                    <div class="cmms-cat-card-stat">
                                        <span class="cmms-cat-card-stat-num"><?php echo (int) $total_c; ?></span>
                                        <span class="cmms-cat-card-stat-label"><?php $this->e( 'stat.total' ); ?></span>
                                    </div>
                                </div>
                                <div class="cmms-cat-card-bar">
                                    <div class="cmms-cat-card-bar-fill" style="width: <?php echo (int) $pct; ?>%;"></div>
                                </div>
                                <div class="cmms-cat-card-pct"><?php echo (int) $pct; ?>% <?php $this->e( 'reports.completed_pct' ); ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="cmms-form-row cols-2">
            <section class="cmms-section">
                <div class="cmms-section-head"><h3 class="cmms-section-title"><?php CMMS_Icons::e( 'users', 16 ); ?> <?php $this->e( 'reports.by_tech' ); ?></h3></div>
                <div class="cmms-section-body flush">
                    <?php if ( empty( $by_tech ) ) : ?>
                        <p class="cmms-muted cmms-text-sm" style="padding:20px;margin:0;"><?php $this->e( 'reports.no_data' ); ?></p>
                    <?php else : ?>
                        <div class="cmms-table-wrap" style="border:none;">
                            <table class="cmms-table">
                                <thead><tr>
                                    <th><?php $this->e( 'reports.technician' ); ?></th>
                                    <th style="text-align:end;"><?php $this->e( 'stat.open' ); ?></th>
                                    <th style="text-align:end;"><?php $this->e( 'stat.completed' ); ?></th>
                                    <th style="text-align:end;"><?php $this->e( 'stat.total' ); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ( $by_tech as $row ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $row->display_name ); ?></td>
                                        <td style="text-align:end;color:var(--c-amber-600);font-weight:600;"><?php echo (int) $row->open_count; ?></td>
                                        <td style="text-align:end;color:var(--c-green-600);"><?php echo (int) $row->completed_count; ?></td>
                                        <td style="text-align:end;"><strong><?php echo (int) $row->total_count; ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="cmms-section">
                <div class="cmms-section-head"><h3 class="cmms-section-title"><?php CMMS_Icons::e( 'tag', 16 ); ?> <?php $this->e( 'reports.by_cat' ); ?></h3></div>
                <div class="cmms-section-body flush">
                    <?php if ( empty( $by_cat ) ) : ?>
                        <p class="cmms-muted cmms-text-sm" style="padding:20px;margin:0;"><?php $this->e( 'reports.no_data' ); ?></p>
                    <?php else : ?>
                        <div class="cmms-table-wrap" style="border:none;">
                            <table class="cmms-table">
                                <thead><tr>
                                    <th><?php $this->e( 'reports.category' ); ?></th>
                                    <th style="text-align:end;"><?php $this->e( 'stat.open' ); ?></th>
                                    <th style="text-align:end;"><?php $this->e( 'stat.total' ); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ( $by_cat as $row ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $row->name ); ?></td>
                                        <td style="text-align:end;color:var(--c-amber-600);font-weight:600;"><?php echo (int) $row->open_count; ?></td>
                                        <td style="text-align:end;"><strong><?php echo (int) $row->total_count; ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <?php
    }

    private function view_forms( $u ) {
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) { $this->forbidden(); return; }
        $forms = CMMS_Forms::list_by_account( $u->account_id );
        ?>
        <div class="cmms-page-head">
            <div>
                <h1 class="cmms-page-title"><?php $this->e( 'forms.title' ); ?></h1>
                <p class="cmms-page-sub"><?php $this->e( 'forms.sub' ); ?></p>
            </div>
            <div class="cmms-page-actions">
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="cmms_form_create">
                    <input type="hidden" name="name" value="<?php echo esc_attr( $this->t( 'forms.default_name' ) ); ?>">
                    <?php wp_nonce_field( 'cmms_form_create', 'cmms_form_create_nonce' ); ?>
                    <button type="submit" class="cmms-btn cmms-btn-primary"><?php CMMS_Icons::e( 'plus', 16 ); ?> <?php $this->e( 'forms.create' ); ?></button>
                </form>
            </div>
        </div>

        <?php if ( empty( $forms ) ) : ?>
            <div class="cmms-section"><div class="cmms-section-body"><?php $this->empty_state( 'clipboard', 'forms.no_forms', 'forms.no_forms_d' ); ?></div></div>
        <?php else : ?>
            <div class="cmms-section">
                <div class="cmms-section-body flush">
                    <div class="cmms-table-wrap" style="border:none;">
                        <table class="cmms-table">
                            <thead><tr><th><?php $this->e( 'forms.name' ); ?></th><th><?php $this->e( 'forms.public_url' ); ?></th><th><?php $this->e( 'forms.enabled' ); ?></th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ( $forms as $f ) :
                                    $url = CMMS_Forms::form_public_url( $f );
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $f->name ); ?></strong></td>
                                        <td><a href="<?php echo esc_url( $url ); ?>" target="_blank" style="font-size:13px;"><?php echo esc_html( $url ); ?></a></td>
                                        <td><?php echo $f->enabled ? '<span class="cmms-badge status-completed">' . esc_html( $this->t( 'common.yes' ) ) . '</span>' : '<span class="cmms-badge status-closed">' . esc_html( $this->t( 'common.no' ) ) . '</span>'; ?></td>
                                        <td class="row-actions">
                                            <a class="cmms-btn cmms-btn-sm" href="<?php echo esc_url( $this->url( array( 'view' => 'form_edit', 'id' => $f->id ) ) ); ?>"><?php $this->e( 'common.edit' ); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    private function view_form_edit( $u ) {
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) { $this->forbidden(); return; }
        $id = (int) ( $_GET['id'] ?? 0 );
        $form = CMMS_Forms::get( $id );
        if ( ! $form || (int) $form->account_id !== (int) $u->account_id ) { $this->not_found(); return; }
        $fields = CMMS_Forms::get_fields( $form->id );
        $cats = CMMS_Categories::list_by_account( $u->account_id, true );
        $users = CMMS_Users::list_by_account( $u->account_id );
        $url = CMMS_Forms::form_public_url( $form );
        $qr = CMMS_Forms::qr_url( $form );
        ?>
        <div class="cmms-page-head">
            <div>
                <p class="cmms-page-sub"><a href="<?php echo esc_url( $this->url( array( 'view' => 'forms' ) ) ); ?>" style="text-decoration:none;color:inherit;">← <?php $this->e( 'forms.back' ); ?></a></p>
                <h1 class="cmms-page-title"><?php echo esc_html( $form->name ); ?></h1>
            </div>
        </div>

        <div class="cmms-detail-grid">
            <div>
                <section class="cmms-section">
                    <div class="cmms-section-head"><h3 class="cmms-section-title"><?php CMMS_Icons::e( 'settings', 16 ); ?> <?php $this->e( 'forms.settings' ); ?></h3></div>
                    <div class="cmms-section-body">
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form">
                            <input type="hidden" name="action" value="cmms_form_update">
                            <input type="hidden" name="form_id" value="<?php echo (int) $form->id; ?>">
                            <?php wp_nonce_field( 'cmms_form_update', 'cmms_form_update_nonce' ); ?>
                            <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'forms.name' ); ?></label><input class="cmms-input" name="name" type="text" required value="<?php echo esc_attr( $form->name ); ?>"></div>
                            <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'forms.description' ); ?></label><textarea class="cmms-textarea" name="description" rows="2"><?php echo esc_textarea( $form->description ); ?></textarea></div>
                            <div class="cmms-form-row cols-2">
                                <div class="cmms-field">
                                    <label class="cmms-field-label"><?php $this->e( 'forms.default_category' ); ?></label>
                                    <select class="cmms-select" name="default_category_id">
                                        <option value=""><?php $this->e( 'common.none' ); ?></option>
                                        <?php foreach ( $cats as $c ) : ?>
                                            <option value="<?php echo (int) $c->id; ?>" <?php selected( $form->default_category_id == $c->id ); ?>><?php echo esc_html( $c->name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="cmms-field">
                                    <label class="cmms-field-label"><?php $this->e( 'forms.manager' ); ?></label>
                                    <select class="cmms-select" name="manager_id">
                                        <option value=""><?php $this->e( 'common.none' ); ?></option>
                                        <?php foreach ( $users as $usr ) : if ( ! in_array( $usr->role, array( 'owner', 'manager' ), true ) ) continue; ?>
                                            <option value="<?php echo (int) $usr->id; ?>" <?php selected( $form->manager_id == $usr->id ); ?>><?php echo esc_html( $usr->display_name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="cmms-form-row cols-2">
                                <div class="cmms-field">
                                    <label class="cmms-field-label"><?php $this->e( 'forms.default_priority' ); ?></label>
                                    <select class="cmms-select" name="default_priority">
                                        <?php foreach ( CMMS_Tasks::priorities() as $k => $v ) : ?>
                                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $form->default_priority === $k ); ?>><?php echo esc_html( $this->t( 'priority.' . $k ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="cmms-field">
                                    <label class="cmms-field-label"><?php $this->e( 'forms.default_status' ); ?></label>
                                    <select class="cmms-select" name="default_status">
                                        <?php foreach ( CMMS_Tasks::statuses() as $k => $v ) : ?>
                                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $form->default_status === $k ); ?>><?php echo esc_html( $this->t( 'status.' . $k ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <label class="cmms-checkbox"><input type="checkbox" name="enabled" value="1" <?php checked( $form->enabled ); ?>> <?php $this->e( 'forms.enabled' ); ?></label>
                            <div class="cmms-form-actions">
                                <button type="submit" class="cmms-btn cmms-btn-primary"><?php CMMS_Icons::e( 'save', 16 ); ?> <?php $this->e( 'common.save' ); ?></button>
                            </div>
                        </form>
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="margin-top:12px;" class="cmms-confirm-delete" data-confirm="<?php echo esc_attr( $this->t( 'common.confirm_delete' ) ); ?>">
                            <input type="hidden" name="action" value="cmms_form_delete">
                            <input type="hidden" name="form_id" value="<?php echo (int) $form->id; ?>">
                            <?php wp_nonce_field( 'cmms_form_delete', 'cmms_form_delete_nonce' ); ?>
                            <button type="submit" class="cmms-btn cmms-btn-danger"><?php CMMS_Icons::e( 'trash', 14 ); ?> <?php $this->e( 'common.delete' ); ?></button>
                        </form>
                    </div>
                </section>

                <section class="cmms-section">
                    <div class="cmms-section-head"><h3 class="cmms-section-title"><?php CMMS_Icons::e( 'list', 16 ); ?> <?php $this->e( 'forms.fields' ); ?></h3></div>
                    <div class="cmms-section-body">
                        <?php if ( ! empty( $fields ) ) : ?>
                            <p class="cmms-muted cmms-text-sm" style="margin:0 0 12px;"><?php $this->e( 'forms.reorder_hint' ); ?></p>
                            <div class="cmms-form-fields-list" data-cmms-form-fields data-form-id="<?php echo (int) $form->id; ?>">
                                <?php foreach ( $fields as $field ) :
                                    // Each field is rendered in two states: a read-only "summary"
                                    // row (default) and a hidden inline-edit form. Clicking the
                                    // edit button toggles them. This keeps the page server-rendered
                                    // (no AJAX needed for editing) — the form posts to the
                                    // existing cmms_field_update handler.
                                    $field_options_text = $field->options ? $field->options : '';
                                ?>
                                    <div class="cmms-form-field-card" data-cmms-form-field data-field-id="<?php echo (int) $field->id; ?>">
                                        <!-- Read-only summary row -->
                                        <div class="cmms-form-field-summary" data-cmms-form-field-summary>
                                            <span class="cmms-form-field-handle" data-cmms-form-field-handle title="<?php echo esc_attr( $this->t( 'forms.drag_to_reorder' ) ); ?>" aria-label="<?php echo esc_attr( $this->t( 'forms.drag_to_reorder' ) ); ?>">
                                                <?php CMMS_Icons::e( 'menu', 16 ); ?>
                                            </span>
                                            <div class="cmms-form-field-summary-text">
                                                <div class="cmms-form-field-summary-label">
                                                    <?php echo esc_html( $field->label ); ?>
                                                    <?php if ( $field->required ) : ?>
                                                        <span style="color:var(--c-red-500);">*</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="cmms-form-field-summary-meta">
                                                    <span class="cmms-badge status-closed"><?php echo esc_html( $this->t( 'forms.field_type.' . $field->field_type ) ?: $field->field_type ); ?></span>
                                                    <?php if ( $field_options_text && in_array( $field->field_type, array( 'select', 'radio', 'checkbox' ), true ) ) : ?>
                                                        <span class="cmms-form-field-options-preview"><?php echo esc_html( $field_options_text ); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="cmms-form-field-actions">
                                                <button type="button" class="cmms-btn cmms-btn-sm cmms-btn-ghost" data-cmms-form-field-edit
                                                        aria-label="<?php echo esc_attr( $this->t( 'common.edit' ) ); ?>">
                                                    <?php CMMS_Icons::e( 'edit', 14 ); ?>
                                                </button>
                                                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-confirm-delete" data-confirm="<?php echo esc_attr( $this->t( 'common.confirm_delete' ) ); ?>" style="display:inline;">
                                                    <input type="hidden" name="action" value="cmms_field_delete">
                                                    <input type="hidden" name="field_id" value="<?php echo (int) $field->id; ?>">
                                                    <?php wp_nonce_field( 'cmms_field_delete', 'cmms_field_delete_nonce' ); ?>
                                                    <button type="submit" class="cmms-btn cmms-btn-sm cmms-btn-ghost"><?php CMMS_Icons::e( 'trash', 14 ); ?></button>
                                                </form>
                                            </div>
                                        </div>
                                        <!-- Inline edit form (hidden by default) -->
                                        <form class="cmms-form-field-edit" data-cmms-form-field-edit-form
                                              method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" hidden>
                                            <input type="hidden" name="action" value="cmms_field_update">
                                            <input type="hidden" name="field_id" value="<?php echo (int) $field->id; ?>">
                                            <?php wp_nonce_field( 'cmms_field_update', 'cmms_field_update_nonce' ); ?>
                                            <div class="cmms-form-row cols-2">
                                                <div class="cmms-field">
                                                    <label class="cmms-field-label"><?php $this->e( 'forms.field_label' ); ?></label>
                                                    <input class="cmms-input" name="label" type="text" required value="<?php echo esc_attr( $field->label ); ?>">
                                                </div>
                                                <div class="cmms-field">
                                                    <label class="cmms-field-label"><?php $this->e( 'forms.field_type_label' ); ?></label>
                                                    <select class="cmms-select" name="field_type">
                                                        <?php foreach ( CMMS_Forms::field_types() as $k => $v ) : ?>
                                                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $field->field_type === $k ); ?>><?php echo esc_html( $this->t( 'forms.field_type.' . $k ) ?: $k ); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="cmms-field">
                                                <label class="cmms-field-label"><?php $this->e( 'forms.field_options' ); ?></label>
                                                <input class="cmms-input" name="options" type="text" value="<?php echo esc_attr( $field_options_text ); ?>" placeholder="<?php echo esc_attr( $this->t( 'forms.field_options_ph' ) ); ?>">
                                                <span class="cmms-field-help"><?php $this->e( 'forms.field_options_help' ); ?></span>
                                            </div>
                                            <label class="cmms-checkbox">
                                                <input type="checkbox" name="required" value="1" <?php checked( $field->required ); ?>>
                                                <?php $this->e( 'forms.required' ); ?>
                                            </label>
                                            <div class="cmms-form-actions" style="display:flex;gap:8px;justify-content:flex-end;">
                                                <button type="button" class="cmms-btn cmms-btn-ghost" data-cmms-form-field-cancel><?php $this->e( 'common.cancel' ); ?></button>
                                                <button type="submit" class="cmms-btn cmms-btn-primary"><?php CMMS_Icons::e( 'save', 14 ); ?> <?php $this->e( 'common.save' ); ?></button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!--
                                Reorder save indicator. The drag-and-drop JS posts a new
                                ordering to admin-post.php (action=cmms_field_reorder) in the
                                background. The user sees this small status text confirm the
                                save without a full page reload.
                            -->
                            <div class="cmms-form-fields-reorder-status" data-cmms-form-fields-reorder-status hidden></div>
                        <?php endif; ?>

                        <h4 style="margin:20px 0 8px;font-size:14px;font-weight:600;color:#0f172a;">
                            <?php $this->e( 'forms.add_field' ); ?>
                        </h4>
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form">
                            <input type="hidden" name="action" value="cmms_field_add">
                            <input type="hidden" name="form_id" value="<?php echo (int) $form->id; ?>">
                            <?php wp_nonce_field( 'cmms_field_add', 'cmms_field_add_nonce' ); ?>
                            <div class="cmms-form-row cols-2">
                                <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'forms.field_label' ); ?></label><input class="cmms-input" name="label" type="text" required></div>
                                <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'forms.field_type_label' ); ?></label>
                                    <select class="cmms-select" name="field_type">
                                        <?php foreach ( CMMS_Forms::field_types() as $k => $v ) : ?>
                                            <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $this->t( 'forms.field_type.' . $k ) ?: $k ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'forms.field_options' ); ?></label><input class="cmms-input" name="options" type="text" placeholder="<?php echo esc_attr( $this->t( 'forms.field_options_ph' ) ); ?>"><span class="cmms-field-help"><?php $this->e( 'forms.field_options_help' ); ?></span></div>
                            <label class="cmms-checkbox"><input type="checkbox" name="required" value="1"> <?php $this->e( 'forms.required' ); ?></label>
                            <div><button type="submit" class="cmms-btn cmms-btn-secondary"><?php CMMS_Icons::e( 'plus', 16 ); ?> <?php $this->e( 'forms.add_field' ); ?></button></div>
                        </form>
                        <script>
                            // i18n + endpoint for the form-field reorder JS.
                            // The endpoint POSTs a JSON array of {id, sort_order}
                            // back to the server when the user finishes dragging.
                            window.cmmsFormFieldsConfig = window.cmmsFormFieldsConfig || {
                                reorder_endpoint: <?php echo wp_json_encode( admin_url( 'admin-post.php?action=cmms_field_reorder' ) ); ?>,
                                reorder_nonce:    <?php echo wp_json_encode( wp_create_nonce( 'cmms_field_reorder' ) ); ?>,
                                i18n: {
                                    saving:      <?php echo wp_json_encode( $this->t( 'forms.reorder_saving' ) ); ?>,
                                    saved:       <?php echo wp_json_encode( $this->t( 'forms.reorder_saved' ) ); ?>,
                                    err_network: <?php echo wp_json_encode( $this->t( 'forms.reorder_err' ) ); ?>,
                                },
                            };
                        </script>
                    </div>
                </section>
            </div>

            <aside>
                <section class="cmms-section">
                    <div class="cmms-section-head"><h3 class="cmms-section-title"><?php CMMS_Icons::e( 'share', 16 ); ?> <?php $this->e( 'forms.share' ); ?></h3></div>
                    <div class="cmms-section-body">
                        <p class="cmms-text-sm cmms-mb-3"><?php $this->e( 'forms.share_help' ); ?></p>
                        <div class="cmms-mb-4">
                            <input type="text" class="cmms-input" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();">
                        </div>
                        <div class="cmms-text-center">
                            <img src="<?php echo esc_url( $qr ); ?>" alt="QR" width="200" height="200" style="border:1px solid var(--c-border);border-radius:8px;">
                        </div>
                        <div class="cmms-mt-3 cmms-text-center">
                            <a href="<?php echo esc_url( $qr ); ?>" download class="cmms-btn cmms-btn-sm"><?php CMMS_Icons::e( 'download', 14 ); ?> <?php $this->e( 'forms.download_qr' ); ?></a>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
        <?php
    }

    private function view_users( $u ) {
        if ( ! CMMS_Auth::can( 'manage_users' ) ) { $this->forbidden(); return; }
        $users = CMMS_Users::list_by_account( $u->account_id );

        $editing_id = isset( $_GET['edit_user'] ) ? (int) $_GET['edit_user'] : 0;
        $err_msg = '';
        if ( isset( $_GET['cmms_err'] ) ) {
            $err_code = sanitize_key( wp_unslash( $_GET['cmms_err'] ) );
            // 1.14.50: map known error codes to clear Hebrew messages.
            $error_map = array(
                'email'      => 'כתובת אימייל לא תקינה.',
                'add'        => 'הוספת המשתמש נכשלה. ייתכן שהאימייל כבר קיים במערכת.',
                'user_limit' => 'הגעת למגבלת המשתמשים של החבילה. כדי להוסיף עוד משתמשים — שדרג את החבילה.',
            );
            if ( isset( $error_map[ $err_code ] ) ) {
                $err_msg = $error_map[ $err_code ];
            } elseif ( isset( $_GET['cmms_msg'] ) ) {
                $err_msg = sanitize_text_field( wp_unslash( $_GET['cmms_msg'] ) );
            }
        }
        $updated = isset( $_GET['cmms_msg'] ) && $_GET['cmms_msg'] === 'added';
        $just_added = isset( $_GET['cmms_msg'] ) && $_GET['cmms_msg'] === 'added';

        // 1.14.50: compute slot usage so we can show "X/Y users" hint
        // and disable the add form when full.
        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $users_t    = CMMS_DB::table( 'users' );
        $max_users = $wpdb->get_var( $wpdb->prepare(
            "SELECT max_users FROM $accounts_t WHERE id = %d", (int) $u->account_id
        ) );
        $max_users = ( $max_users === null || $max_users === '' ) ? null : (int) $max_users;
        $current_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $users_t WHERE account_id = %d AND status IN ('active','invited')",
            (int) $u->account_id
        ) );
        $is_full = ( $max_users !== null && $current_count >= $max_users );
        ?>
        <div class="cmms-page-head" data-cmms-page="users">
            <h1 class="cmms-page-title"><?php $this->e( 'users.title' ); ?></h1>
            <p class="cmms-page-sub"><?php $this->e( 'users.sub' ); ?></p>
        </div>

        <?php if ( $updated ) : ?>
            <div class="cmms-alert cmms-alert-success"><?php CMMS_Icons::e( 'check-circle', 18 ); ?> <span><?php $this->e( 'users.updated' ); ?></span></div>
        <?php endif; ?>
        <?php if ( $err_msg ) : ?>
            <div class="cmms-alert cmms-alert-error"><?php CMMS_Icons::e( 'alert-circle', 18 ); ?> <span><?php echo esc_html( $err_msg ); ?></span></div>
        <?php endif; ?>

        <div class="cmms-section">
            <div class="cmms-section-head" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'user-plus', 16 ); ?> <?php $this->e( 'users.add' ); ?></h3>
                <?php
                // 1.14.50: slot indicator. Shows "X / Y משתמשים" with a
                // warning tint when near the limit, error when at it.
                if ( $max_users !== null ) :
                    $is_warn = ( $current_count >= $max_users - 1 && ! $is_full );
                    $color = $is_full ? '#dc2626' : ( $is_warn ? '#d97706' : '#475569' );
                ?>
                <span style="font-size:13px; color:<?php echo $color; ?>; font-weight:600;">
                    <?php echo (int) $current_count; ?> / <?php echo (int) $max_users; ?> משתמשים
                </span>
                <?php endif; ?>
            </div>
            <div class="cmms-section-body">
                <?php if ( $is_full ) : ?>
                    <div class="cmms-alert cmms-alert-warning" style="margin-bottom:16px;">
                        <?php CMMS_Icons::e( 'alert-circle', 18 ); ?>
                        <span>הגעת למגבלת המשתמשים של החבילה (<?php echo (int) $max_users; ?>). כדי להוסיף עוד משתמשים — שדרג את החבילה.</span>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form"<?php echo $is_full ? ' style="opacity:0.5; pointer-events:none;"' : ''; ?>>
                    <input type="hidden" name="action" value="cmms_user_add">
                    <?php wp_nonce_field( 'cmms_user_add', 'cmms_user_add_nonce' ); ?>
                    <div class="cmms-form-row cols-2">
                        <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.email' ); ?> <span class="req">*</span></label><input class="cmms-input" name="email" type="email" required></div>
                        <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.name' ); ?></label><input class="cmms-input" name="display_name" type="text"></div>
                    </div>
                    <div class="cmms-form-row cols-3">
                        <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.phone' ); ?></label><input class="cmms-input" name="phone" type="tel"></div>
                        <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.role' ); ?></label>
                            <select class="cmms-select" name="role">
                                <option value="manager"><?php $this->e( 'role.manager' ); ?></option>
                                <option value="technician" selected><?php $this->e( 'role.technician' ); ?></option>
                                <option value="reporter"><?php $this->e( 'role.reporter' ); ?></option>
                            </select>
                        </div>
                        <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.password' ); ?></label><input class="cmms-input" name="password" type="text" placeholder="<?php echo esc_attr( $this->t( 'users.password_ph' ) ); ?>"></div>
                    </div>
                    <div><button type="submit" class="cmms-btn cmms-btn-primary"<?php echo $is_full ? ' disabled' : ''; ?>><?php CMMS_Icons::e( 'plus', 16 ); ?> <?php $this->e( 'users.add_btn' ); ?></button></div>
                </form>
            </div>
        </div>

        <div class="cmms-section">
            <div class="cmms-section-body flush">
                <div class="cmms-table-wrap" style="border:none;">
                    <table class="cmms-table">
                        <thead><tr>
                            <th><?php $this->e( 'users.name' ); ?></th>
                            <th><?php $this->e( 'users.role' ); ?></th>
                            <th><?php $this->e( 'users.phone' ); ?></th>
                            <th><?php $this->e( 'users.status_col' ); ?></th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ( $users as $usr ) :
                                $wpu = get_user_by( 'id', $usr->wp_user_id );
                                $is_editing = ( $editing_id === (int) $usr->id );
                                $is_self = ( (int) $usr->id === (int) $u->id );
                                $status_label_key = 'common.active';
                                $status_class = 'status-completed';
                                if ( $usr->status === 'suspended' ) { $status_label_key = 'users.status_suspended'; $status_class = 'status-waiting'; }
                                elseif ( $usr->status !== 'active' ) { $status_label_key = 'common.inactive'; $status_class = 'status-closed'; }
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $usr->display_name ); ?></strong>
                                        <br><span class="cmms-text-xs cmms-muted"><?php echo esc_html( $wpu ? $wpu->user_email : '' ); ?></span>
                                    </td>
                                    <td><span class="cmms-badge role-<?php echo esc_attr( $usr->role ); ?>"><?php echo esc_html( $this->t( 'role.' . $usr->role ) ); ?></span></td>
                                    <td><?php echo esc_html( $usr->phone ); ?></td>
                                    <td><span class="cmms-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $this->t( $status_label_key ) ); ?></span></td>
                                    <td class="row-actions">
                                        <a class="cmms-btn cmms-btn-sm cmms-btn-ghost" href="<?php echo esc_url( $this->url( array( 'view' => 'users', 'edit_user' => $usr->id ) ) ); ?>">
                                            <?php CMMS_Icons::e( 'edit', 14 ); ?> <?php $this->e( 'common.edit' ); ?>
                                        </a>
                                        <?php if ( ! $is_self ) : ?>
                                            <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="display:inline;" class="cmms-confirm-delete" data-confirm="<?php echo esc_attr( $this->t( 'users.confirm_delete' ) ); ?>">
                                                <input type="hidden" name="action" value="cmms_user_delete">
                                                <input type="hidden" name="user_id" value="<?php echo (int) $usr->id; ?>">
                                                <?php wp_nonce_field( 'cmms_user_delete', 'cmms_user_delete_nonce' ); ?>
                                                <button type="submit" class="cmms-btn cmms-btn-sm cmms-btn-ghost" title="<?php echo esc_attr( $this->t( 'common.delete' ) ); ?>"><?php CMMS_Icons::e( 'trash', 14 ); ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ( $is_editing ) : ?>
                                <tr class="cmms-user-edit-row">
                                    <td colspan="5" style="background:var(--c-gray-50);padding:24px;">
                                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form">
                                            <input type="hidden" name="action" value="cmms_user_update">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $usr->id; ?>">
                                            <?php wp_nonce_field( 'cmms_user_update', 'cmms_user_update_nonce' ); ?>
                                            <div class="cmms-form-row cols-2">
                                                <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.name' ); ?></label><input class="cmms-input" name="display_name" type="text" value="<?php echo esc_attr( $usr->display_name ); ?>"></div>
                                                <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.email' ); ?></label><input class="cmms-input" name="email" type="email" value="<?php echo esc_attr( $wpu ? $wpu->user_email : '' ); ?>"></div>
                                            </div>
                                            <div class="cmms-form-row cols-3">
                                                <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.phone' ); ?></label><input class="cmms-input" name="phone" type="tel" value="<?php echo esc_attr( $usr->phone ); ?>"></div>
                                                <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.role' ); ?></label>
                                                    <select class="cmms-select" name="role" <?php echo $is_self ? 'disabled' : ''; ?>>
                                                        <?php
                                                        $roles = array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER, CMMS_Auth::ROLE_TECHNICIAN, CMMS_Auth::ROLE_REPORTER );
                                                        foreach ( $roles as $r ) :
                                                        ?>
                                                            <option value="<?php echo esc_attr( $r ); ?>" <?php selected( $usr->role, $r ); ?>><?php echo esc_html( $this->t( 'role.' . $r ) ); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'users.status_col' ); ?></label>
                                                    <select class="cmms-select" name="status">
                                                        <option value="active" <?php selected( $usr->status, 'active' ); ?>><?php $this->e( 'common.active' ); ?></option>
                                                        <option value="suspended" <?php selected( $usr->status, 'suspended' ); ?>><?php $this->e( 'users.status_suspended' ); ?></option>
                                                        <option value="inactive" <?php selected( $usr->status, 'inactive' ); ?>><?php $this->e( 'common.inactive' ); ?></option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="cmms-flex cmms-gap-2">
                                                <button type="submit" class="cmms-btn cmms-btn-primary"><?php CMMS_Icons::e( 'save', 16 ); ?> <?php $this->e( 'common.save' ); ?></button>
                                                <a class="cmms-btn cmms-btn-ghost" href="<?php echo esc_url( $this->url( array( 'view' => 'users' ) ) ); ?>"><?php $this->e( 'common.cancel' ); ?></a>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /* ========================================================================
     * IMPORT view — bulk upload from .xlsx
     * ======================================================================*/

    private function view_import( $u ) {
        if ( ! CMMS_Auth::can( 'manage_account' ) ) { $this->forbidden(); return; }

        $template_url   = admin_url( 'admin-post.php?action=cmms_import_template' );
        $upload_action  = admin_url( 'admin-post.php' );
        $errors_url     = admin_url( 'admin-post.php?action=cmms_import_errors_csv' );

        // Result from a recent import (after redirect back from upload)
        $result = get_transient( 'cmms_import_result_' . (int) $u->id );
        $just_done = ! empty( $_GET['cmms_done'] );

        // Map error codes from query string to friendly Hebrew/translated messages
        $err_map = array(
            'failed'    => 'import.err.failed',
            'no_file'   => 'import.err.no_file',
            'upload'    => 'import.err.upload',
            'too_large' => 'import.err.too_large',
            'bad_ext'   => 'import.err.bad_ext',
            'corrupt'   => 'import.err.corrupt',
            'sandbox'   => 'import.err.sandbox',
        );
        $err_code = isset( $_GET['cmms_err'] ) ? sanitize_key( wp_unslash( $_GET['cmms_err'] ) ) : '';
        $err_message = ( $err_code && isset( $err_map[ $err_code ] ) ) ? $this->t( $err_map[ $err_code ] ) : '';
        ?>
        <div class="cmms-page-head">
            <h1 class="cmms-page-title"><?php $this->e( 'import.title' ); ?></h1>
            <p class="cmms-page-sub"><?php $this->e( 'import.intro' ); ?></p>
        </div>

        <?php if ( $err_message ) : ?>
            <div class="cmms-alert cmms-alert-error" role="alert" style="margin-bottom:16px;">
                <?php echo esc_html( $err_message ); ?>
            </div>
        <?php endif; ?>

        <?php if ( $just_done && is_array( $result ) ) :
            $skipped = isset( $result['skipped_rows'] ) ? $result['skipped_rows'] : array();
            $imp_a = (int) ( $result['imported_assets'] ?? 0 );
            ?>
            <div class="cmms-alert cmms-alert-success" style="margin-bottom:16px;">
                <strong><?php $this->e( 'import.done' ); ?></strong>
                <div style="margin-top:6px;">
                    <?php echo esc_html( sprintf( $this->t( 'import.done_summary' ), $imp_a, count( $skipped ) ) ); ?>
                </div>
                <?php if ( ! empty( $skipped ) ) : ?>
                    <div style="margin-top:10px;">
                        <a class="cmms-btn cmms-btn-secondary cmms-btn-sm" href="<?php echo esc_url( $errors_url ); ?>">
                            <?php $this->e( 'import.download_errors' ); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Download template -->
        <section class="cmms-section cmms-import-step">
            <div class="cmms-section-head">
                <span class="cmms-import-step-num">1</span>
                <h2 class="cmms-section-title"><?php $this->e( 'import.step1_title' ); ?></h2>
            </div>
            <p class="cmms-section-sub"><?php $this->e( 'import.step1_desc' ); ?></p>
            <a class="cmms-btn cmms-btn-secondary" href="<?php echo esc_url( $template_url ); ?>">
                <?php CMMS_Icons::e( 'download', 18 ); ?>
                <?php $this->e( 'import.download_template' ); ?>
            </a>
        </section>

        <!-- Step 2: Upload -->
        <section class="cmms-section cmms-import-step" style="margin-top:16px;">
            <div class="cmms-section-head">
                <span class="cmms-import-step-num">2</span>
                <h2 class="cmms-section-title"><?php $this->e( 'import.step2_title' ); ?></h2>
            </div>
            <p class="cmms-section-sub"><?php $this->e( 'import.step2_desc' ); ?></p>
            <form method="post"
                  action="<?php echo esc_url( $upload_action ); ?>"
                  enctype="multipart/form-data"
                  data-cmms-import-form>
                <input type="hidden" name="action" value="cmms_import_upload">
                <?php wp_nonce_field( 'cmms_import', 'cmms_import_nonce' ); ?>

                <label class="cmms-import-drop" data-cmms-import-drop>
                    <input type="file"
                           name="cmms_xlsx"
                           accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                           required
                           data-cmms-import-input
                           hidden>
                    <div class="cmms-import-drop-inner">
                        <?php CMMS_Icons::e( 'upload', 36 ); ?>
                        <div class="cmms-import-drop-title" data-cmms-import-droptitle>
                            <?php $this->e( 'import.drop_zone' ); ?>
                        </div>
                        <div class="cmms-import-drop-sub">
                            <?php $this->e( 'import.drop_zone_sub' ); ?>
                        </div>
                    </div>
                </label>

                <div style="margin-top:16px;">
                    <button type="submit" class="cmms-btn cmms-btn-primary" data-cmms-import-submit disabled>
                        <?php CMMS_Icons::e( 'upload', 18 ); ?>
                        <?php $this->e( 'import.upload_btn' ); ?>
                    </button>
                </div>
            </form>
        </section>

        <!-- Help -->
        <section class="cmms-section" style="margin-top:16px;">
            <h2 class="cmms-section-title" style="font-size:15px;"><?php $this->e( 'import.help_title' ); ?></h2>
            <ul style="margin:8px 0 0; padding-inline-start:18px; color:#475569; font-size:14px; line-height:1.8;">
                <li><?php $this->e( 'import.help_1' ); ?></li>
                <li><?php $this->e( 'import.help_2' ); ?></li>
                <li><?php $this->e( 'import.help_3' ); ?></li>
                <li><?php $this->e( 'import.help_4' ); ?></li>
            </ul>
        </section>
        <?php

        // Clear the result so refreshing the page doesn't show stale numbers,
        // but keep the error CSV alive in case the user clicks the download button.
        if ( $just_done ) {
            // Move the result into a "viewed" copy so subsequent refreshes don't re-show.
            // The errors download still uses the live transient until it expires.
            // (We deliberately do NOT delete the transient yet — the CSV button needs it.)
        }
    }

    /* ========================================================================
     * BULK TASKS view — in-app spreadsheet for fast multi-row task entry
     *
     * Why this exists alongside the Excel asset import:
     *  - Tasks need lookups (asset, assignee) that are dropdown-driven.
     *  - Excel doesn't enforce those, so users mistype and validation fails.
     *  - This grid pre-loads the assets and users for this account, so the
     *    user picks from real options. Missing data is visually obvious.
     *
     * The killer feature: paste from Excel (Ctrl+V) drops rows directly
     * into the grid. Most onboarding customers HAVE a spreadsheet with
     * tasks; we just want to spare them the alignment-by-asset-name dance.
     * ======================================================================*/

    private function view_bulk_tasks( $u ) {
        if ( ! CMMS_Auth::can( 'manage_account' ) ) { $this->forbidden(); return; }

        // Pre-load lookup data for the grid's dropdowns.
        $assets = CMMS_Assets::list_by_account( (int) $u->account_id );
        $users  = CMMS_Users::list_by_account( (int) $u->account_id );

        // Filter to only active users we'd want to assign tasks to
        $assignable = array();
        foreach ( (array) $users as $user_row ) {
            if ( isset( $user_row->status ) && $user_row->status !== 'active' ) continue;
            $assignable[] = $user_row;
        }

        $nonce = wp_create_nonce( 'cmms_bulk_tasks' );
        $endpoint = admin_url( 'admin-post.php?action=cmms_bulk_tasks_save' );

        // Pass everything to JS as a single config object.
        $config = array(
            'endpoint'  => $endpoint,
            'nonce'     => $nonce,
            'home'      => home_url( '/cmms-dashboard/?view=tasks' ),
            'assets'    => array_map( function( $a ) { return array( 'id' => (int) $a->id, 'name' => $a->name ); }, (array) $assets ),
            'users'     => array_map( function( $u ) { return array( 'id' => (int) $u->id, 'name' => $u->display_name ?: $u->user_login ); }, (array) $assignable ),
            'priorities'=> array(
                array( 'value' => 'low',    'label' => $this->t( 'priority.low' )    ?: 'Low' ),
                array( 'value' => 'normal', 'label' => $this->t( 'priority.normal' ) ?: 'Normal' ),
                array( 'value' => 'high',   'label' => $this->t( 'priority.high' )   ?: 'High' ),
                array( 'value' => 'urgent', 'label' => $this->t( 'priority.urgent' ) ?: 'Urgent' ),
            ),
            'i18n' => array(
                'col_title'        => $this->t( 'bulk.col_title' ),
                'col_description'  => $this->t( 'bulk.col_description' ),
                'col_asset'        => $this->t( 'bulk.col_asset' ),
                'col_priority'     => $this->t( 'bulk.col_priority' ),
                'col_assignee'     => $this->t( 'bulk.col_assignee' ),
                'col_due'          => $this->t( 'bulk.col_due' ),
                'asset_none'       => $this->t( 'bulk.asset_none' ),
                'assignee_none'    => $this->t( 'bulk.assignee_none' ),
                'add_rows'         => $this->t( 'bulk.add_rows' ),
                'save'             => $this->t( 'bulk.save' ),
                'saving'           => $this->t( 'bulk.saving' ),
                'saved_summary'    => $this->t( 'bulk.saved_summary' ),
                'failed_some'      => $this->t( 'bulk.failed_some' ),
                'err_title_required'=> $this->t( 'bulk.err_title_required' ),
                'err_network'      => $this->t( 'bulk.err_network' ),
                'no_assets_warn'   => $this->t( 'bulk.no_assets_warn' ),
                'go_to_import'     => $this->t( 'bulk.go_to_import' ),
                'paste_hint'       => $this->t( 'bulk.paste_hint' ),
                'view_tasks'       => $this->t( 'bulk.view_tasks' ),
            ),
        );
        ?>
        <div class="cmms-page-head" data-cmms-page="bulk-tasks">
            <h1 class="cmms-page-title"><?php $this->e( 'bulk.title' ); ?></h1>
            <p class="cmms-page-sub"><?php $this->e( 'bulk.intro' ); ?></p>
        </div>

        <?php if ( empty( $assets ) ) : ?>
            <div class="cmms-alert cmms-alert-warning" style="margin-bottom:16px;">
                <strong><?php $this->e( 'bulk.no_assets_warn' ); ?></strong>
                <a class="cmms-btn cmms-btn-secondary cmms-btn-sm" href="<?php echo esc_url( $this->url( array( 'view' => 'import' ) ) ); ?>" style="margin-inline-start:8px;">
                    <?php $this->e( 'bulk.go_to_import' ); ?>
                </a>
            </div>
        <?php endif; ?>

        <div class="cmms-section">
            <div class="cmms-bulk-toolbar">
                <p class="cmms-bulk-hint"><?php $this->e( 'bulk.paste_hint' ); ?></p>
            </div>

            <div class="cmms-bulk-grid-wrap" data-cmms-bulk-root>
                <table class="cmms-bulk-grid" data-cmms-bulk-grid>
                    <thead>
                        <tr>
                            <th class="cmms-bulk-th-num">#</th>
                            <th><?php $this->e( 'bulk.col_title' ); ?> <span class="cmms-required">*</span></th>
                            <th><?php $this->e( 'bulk.col_description' ); ?></th>
                            <th><?php $this->e( 'bulk.col_asset' ); ?></th>
                            <th><?php $this->e( 'bulk.col_priority' ); ?></th>
                            <th><?php $this->e( 'bulk.col_assignee' ); ?></th>
                            <th><?php $this->e( 'bulk.col_due' ); ?></th>
                        </tr>
                    </thead>
                    <tbody data-cmms-bulk-body></tbody>
                </table>
            </div>

            <div class="cmms-bulk-actions">
                <button type="button" class="cmms-btn cmms-btn-secondary" data-cmms-bulk-add>
                    <?php CMMS_Icons::e( 'plus', 16 ); ?>
                    <?php $this->e( 'bulk.add_rows' ); ?>
                </button>
                <button type="button" class="cmms-btn cmms-btn-primary" data-cmms-bulk-save>
                    <?php $this->e( 'bulk.save' ); ?>
                </button>
            </div>

            <div class="cmms-bulk-result" data-cmms-bulk-result hidden></div>
        </div>

        <script>
            window.cmmsBulkTasksConfig = <?php echo wp_json_encode( $config ); ?>;
        </script>
        <?php
    }

    /* ============================================================
       VIEW: HELP CENTER (1.14.22)

       Two-column layout: left/right list of articles, main pane shows
       the selected one. Articles are read-only here; admin editing
       comes in 1.14.23. The page-id `help-center` lets the contextual
       help-button render its own pinned article on this screen too,
       though we hide it (the whole page is help, no extra button).
    ============================================================ */
    private function view_help_center( $u ) {
        $articles = CMMS_Help::catalog();
        $selected_id = isset( $_GET['article'] ) ? sanitize_key( wp_unslash( $_GET['article'] ) ) : '';
        if ( ! $selected_id || ! isset( $articles[ $selected_id ] ) ) {
            // Default to first article so the page never opens empty.
            $selected_id = $articles ? array_key_first( $articles ) : '';
        }
        $current = $selected_id ? $articles[ $selected_id ] : null;
        ?>
        <div class="cmms-page-head" data-cmms-page="help-center">
            <h1 class="cmms-page-title">מרכז הדרכה</h1>
            <p class="cmms-page-sub">איך לעבוד עם המערכת — סדר עבודה, ההיגיון התפעולי, וטעויות נפוצות.</p>
        </div>

        <div class="cmms-help-layout">
            <!-- Article list (sidebar within the page) -->
            <nav class="cmms-help-nav" aria-label="רשימת מאמרים">
                <ul>
                    <?php foreach ( $articles as $a ) :
                        $is_active = ( $a['id'] === $selected_id );
                        $url = $this->url( array( 'view' => 'help', 'article' => $a['id'] ) );
                    ?>
                        <li>
                            <a href="<?php echo esc_url( $url ); ?>"
                               class="<?php echo $is_active ? 'is-active' : ''; ?>">
                                <strong><?php echo esc_html( $a['title'] ); ?></strong>
                                <?php if ( ! empty( $a['summary'] ) ) : ?>
                                    <span><?php echo esc_html( $a['summary'] ); ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <!-- Article content -->
            <div class="cmms-help-content">
                <?php if ( $current ) :
                    echo CMMS_Help::render_article_html( $current );  // already escaped inside
                else : ?>
                    <p>אין מאמרים זמינים כרגע.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }


    private function view_settings( $u ) {
        if ( ! CMMS_Auth::can( 'manage_account' ) ) { $this->forbidden(); return; }
        $account = CMMS_Auth::current_account();
        $cats = CMMS_Categories::list_by_account( $u->account_id, false );

        // 1.14.56: The 'billing' tab is owner-only — gated below at both
        // the tab-display and tab-content level. We compute the access
        // flag once so the same check covers both.
        $is_owner = ( $u->role === CMMS_Auth::ROLE_OWNER );

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'org';
        $valid_tabs = array( 'org', 'categories', 'asset_fields', 'notify' );
        if ( $is_owner ) $valid_tabs[] = 'billing';
        if ( ! in_array( $tab, $valid_tabs, true ) ) $tab = 'org';

        $saved = isset( $_GET['cmms_msg'] ) && $_GET['cmms_msg'] === 'saved';
        $field_done = isset( $_GET['cmms_done'] ) ? sanitize_key( wp_unslash( $_GET['cmms_done'] ) ) : '';
        $field_err  = isset( $_GET['cmms_err'] )  ? sanitize_key( wp_unslash( $_GET['cmms_err'] ) )  : '';
        ?>
        <div class="cmms-page-head">
            <h1 class="cmms-page-title"><?php $this->e( 'settings.title' ); ?></h1>
        </div>

        <?php
        // Always-visible install card. Lives above the tabs because it's
        // platform/account-level (not associated with any settings tab) and
        // because users should be able to find it without remembering which
        // tab. JS detects standalone mode and toggles the inner state.
        ?>
        <div class="cmms-section cmms-install-settings" data-cmms-install-settings>
            <div class="cmms-install-settings-row">
                <div class="cmms-install-settings-icon">
                    <?php CMMS_Icons::e( 'download', 22 ); ?>
                </div>
                <div class="cmms-install-settings-text">
                    <strong><?php $this->e( 'install.settings_title' ); ?></strong>
                    <span data-cmms-install-settings-help><?php $this->e( 'install.settings_help' ); ?></span>
                </div>
                <button type="button" class="cmms-btn cmms-btn-primary" data-cmms-install-settings-btn>
                    <?php $this->e( 'install.settings_button' ); ?>
                </button>
                <span class="cmms-install-settings-installed" data-cmms-install-settings-installed hidden>
                    <?php CMMS_Icons::e( 'check-circle', 18 ); ?>
                    <span><?php $this->e( 'install.already_installed' ); ?></span>
                </span>
            </div>
        </div>
        <script>
            // Reuse the same i18n stash as the dashboard banner.
            window.cmmsInstallI18n = window.cmmsInstallI18n || {
                ios_title: <?php echo wp_json_encode( $this->t( 'install.ios_title' ) ); ?>,
                ios_steps: <?php echo wp_json_encode( array(
                    $this->t( 'install.ios_step1' ),
                    $this->t( 'install.ios_step2' ),
                    $this->t( 'install.ios_step3' ),
                ) ); ?>,
                android_title: <?php echo wp_json_encode( $this->t( 'install.android_title' ) ); ?>,
                android_steps: <?php echo wp_json_encode( array(
                    $this->t( 'install.android_step1' ),
                    $this->t( 'install.android_step2' ),
                    $this->t( 'install.android_step3' ),
                ) ); ?>,
                other_title: <?php echo wp_json_encode( $this->t( 'install.other_title' ) ); ?>,
                other_steps: <?php echo wp_json_encode( array(
                    $this->t( 'install.other_step1' ),
                ) ); ?>,
                got_it: <?php echo wp_json_encode( $this->t( 'install.got_it' ) ); ?>,
            };
        </script>

        <div class="cmms-settings-tabs">
            <a href="<?php echo esc_url( $this->url( array( 'view' => 'settings', 'tab' => 'org' ) ) ); ?>"
               class="cmms-settings-tab <?php echo $tab === 'org' ? 'active' : ''; ?>">
                <?php $this->e( 'settings.org' ); ?>
            </a>
            <a href="<?php echo esc_url( $this->url( array( 'view' => 'settings', 'tab' => 'categories' ) ) ); ?>"
               class="cmms-settings-tab <?php echo $tab === 'categories' ? 'active' : ''; ?>">
                <?php $this->e( 'settings.categories' ); ?>
            </a>
            <a href="<?php echo esc_url( $this->url( array( 'view' => 'settings', 'tab' => 'asset_fields' ) ) ); ?>"
               class="cmms-settings-tab <?php echo $tab === 'asset_fields' ? 'active' : ''; ?>">
                <?php $this->e( 'settings.asset_fields' ); ?>
            </a>
            <a href="<?php echo esc_url( $this->url( array( 'view' => 'settings', 'tab' => 'notify' ) ) ); ?>"
               class="cmms-settings-tab <?php echo $tab === 'notify' ? 'active' : ''; ?>">
                <?php $this->e( 'notify.settings' ); ?>
            </a>
            <?php if ( $is_owner ) : ?>
            <a href="<?php echo esc_url( $this->url( array( 'view' => 'settings', 'tab' => 'billing' ) ) ); ?>"
               class="cmms-settings-tab <?php echo $tab === 'billing' ? 'active' : ''; ?>">
                חברות וחיוב
            </a>
            <?php endif; ?>
        </div>

        <?php if ( $saved ) : ?>
            <div class="cmms-alert cmms-alert-success"><?php CMMS_Icons::e( 'check-circle', 18 ); ?> <span><?php $this->e( 'notify.saved' ); ?></span></div>
        <?php endif; ?>
        <?php if ( $field_done === 'field_added' ) : ?>
            <div class="cmms-alert cmms-alert-success"><?php $this->e( 'settings.field_added' ); ?></div>
        <?php elseif ( $field_done === 'field_deleted' ) : ?>
            <div class="cmms-alert cmms-alert-success"><?php $this->e( 'settings.field_deleted' ); ?></div>
        <?php endif; ?>
        <?php if ( $field_err === 'dup' ) : ?>
            <div class="cmms-alert cmms-alert-error"><?php $this->e( 'settings.field_dup' ); ?></div>
        <?php elseif ( $field_err === 'invalid' ) : ?>
            <div class="cmms-alert cmms-alert-error"><?php $this->e( 'settings.field_invalid' ); ?></div>
        <?php endif; ?>

        <?php if ( $tab === 'org' ) : ?>
        <section class="cmms-section">
            <div class="cmms-section-head"><h3 class="cmms-section-title"><?php CMMS_Icons::e( 'briefcase', 16 ); ?> <?php $this->e( 'settings.org' ); ?></h3></div>
            <div class="cmms-section-body">
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form">
                    <input type="hidden" name="action" value="cmms_account_update">
                    <?php wp_nonce_field( 'cmms_account_update', 'cmms_account_update_nonce' ); ?>
                    <div class="cmms-field"><label class="cmms-field-label"><?php $this->e( 'auth.org' ); ?></label><input class="cmms-input" name="name" type="text" value="<?php echo esc_attr( $account->name ); ?>"></div>
                    <div><button type="submit" class="cmms-btn cmms-btn-primary"><?php CMMS_Icons::e( 'save', 16 ); ?> <?php $this->e( 'common.save' ); ?></button></div>
                </form>
            </div>
        </section>

        <?php elseif ( $tab === 'categories' ) : ?>
        <section class="cmms-section">
            <div class="cmms-section-head"><h3 class="cmms-section-title"><?php CMMS_Icons::e( 'tag', 16 ); ?> <?php $this->e( 'settings.categories' ); ?></h3></div>
            <div class="cmms-section-body">
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form">
                    <input type="hidden" name="action" value="cmms_categories_update">
                    <?php wp_nonce_field( 'cmms_categories_update', 'cmms_categories_update_nonce' ); ?>
                    <?php foreach ( $cats as $c ) : ?>
                        <input type="hidden" name="cat_ids[]" value="<?php echo (int) $c->id; ?>">
                        <label class="cmms-checkbox">
                            <input type="checkbox" name="enabled[]" value="<?php echo (int) $c->id; ?>" <?php checked( $c->enabled ); ?>>
                            <?php echo esc_html( $c->name ); ?>
                        </label>
                    <?php endforeach; ?>
                    <div><button type="submit" class="cmms-btn cmms-btn-secondary"><?php CMMS_Icons::e( 'save', 16 ); ?> <?php $this->e( 'common.save' ); ?></button></div>
                </form>
                <hr style="margin:20px 0;border:none;border-top:1px solid var(--c-border);">
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-flex cmms-gap-2" style="align-items:flex-end;">
                    <input type="hidden" name="action" value="cmms_category_add">
                    <?php wp_nonce_field( 'cmms_category_add', 'cmms_category_add_nonce' ); ?>
                    <div class="cmms-field" style="flex:1;"><label class="cmms-field-label"><?php $this->e( 'settings.add_category' ); ?></label><input class="cmms-input" name="name" type="text" required></div>
                    <button type="submit" class="cmms-btn"><?php CMMS_Icons::e( 'plus', 16 ); ?> <?php $this->e( 'common.add' ); ?></button>
                </form>
            </div>
        </section>

        <?php elseif ( $tab === 'asset_fields' ) : ?>
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'list', 16 ); ?> <?php $this->e( 'settings.asset_fields' ); ?></h3>
            </div>
            <div class="cmms-section-body">
                <p class="cmms-muted cmms-text-sm" style="margin:0 0 16px;"><?php $this->e( 'settings.asset_fields_help' ); ?></p>

                <?php
                $defs = CMMS_Assets::list_field_defs( (int) $u->account_id );
                if ( empty( $defs ) ) :
                ?>
                    <p class="cmms-muted" style="margin:0 0 16px;"><?php $this->e( 'settings.asset_fields_empty' ); ?></p>
                <?php else : ?>
                    <table class="cmms-table" style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                        <thead>
                            <tr style="text-align:start;border-bottom:2px solid #e2e8f0;">
                                <th style="padding:8px 10px;font-size:13px;color:#64748b;"><?php $this->e( 'settings.field_label' ); ?></th>
                                <th style="padding:8px 10px;font-size:13px;color:#64748b;"><?php $this->e( 'settings.field_key' ); ?></th>
                                <th style="padding:8px 10px;font-size:13px;color:#64748b;"><?php $this->e( 'settings.field_type' ); ?></th>
                                <th style="padding:8px 10px;font-size:13px;color:#64748b;"><?php $this->e( 'settings.field_visibility' ); ?></th>
                                <th style="padding:8px 10px;width:80px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $type_labels = CMMS_Assets::field_def_types();
                            foreach ( $defs as $def ) :
                                $type_label = isset( $type_labels[ $def->field_type ] ) ? $type_labels[ $def->field_type ] : $def->field_type;
                                $is_public  = ! empty( $def->is_public );
                            ?>
                                <tr style="border-bottom:1px solid #f1f5f9;">
                                    <td style="padding:10px;font-weight:500;">
                                        <?php echo esc_html( $def->label ); ?>
                                        <?php if ( $def->required ) : ?> <span style="color:#ef4444;font-size:12px;">*</span><?php endif; ?>
                                    </td>
                                    <td style="padding:10px;font-family:ui-monospace,Menlo,monospace;font-size:12px;color:#475569;">
                                        <?php echo esc_html( $def->field_key ); ?>
                                    </td>
                                    <td style="padding:10px;font-size:13px;color:#475569;">
                                        <?php echo esc_html( $type_label ); ?>
                                    </td>
                                    <td style="padding:10px;font-size:13px;">
                                        <?php if ( $is_public ) : ?>
                                            <span style="display:inline-flex;align-items:center;gap:4px;color:#10b981;font-weight:500;">
                                                <?php CMMS_Icons::e( 'eye', 14 ); ?>
                                                <?php $this->e( 'settings.field_visibility_public' ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span style="color:#94a3b8;"><?php $this->e( 'settings.field_visibility_internal' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:10px;text-align:end;">
                                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="display:inline;" class="cmms-confirm-delete" data-confirm="<?php echo esc_attr( $this->t( 'common.confirm_delete' ) ); ?>">
                                            <input type="hidden" name="action" value="cmms_field_def_delete">
                                            <input type="hidden" name="def_id" value="<?php echo (int) $def->id; ?>">
                                            <?php wp_nonce_field( 'cmms_field_def_del', 'cmms_field_def_del_nonce' ); ?>
                                            <button type="submit" class="cmms-btn cmms-btn-sm cmms-btn-danger">
                                                <?php CMMS_Icons::e( 'trash', 14 ); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h4 style="margin:20px 0 8px;font-size:14px;font-weight:600;color:#0f172a;">
                    <?php $this->e( 'settings.field_add' ); ?>
                </h4>
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form">
                    <input type="hidden" name="action" value="cmms_field_def_add">
                    <?php wp_nonce_field( 'cmms_field_def', 'cmms_field_def_nonce' ); ?>

                    <!--
                        Note: The internal "field_key" input was removed from this form
                        intentionally. Users were not filling it in (it's a developer
                        concept) but were running into "field already exists" errors
                        whenever the auto-generated key happened to collide. The key is
                        now ALWAYS auto-generated and made unique server-side via
                        CMMS_Assets::generate_unique_field_key(). The result is shown
                        in the table above so power users can still see it.
                    -->
                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'settings.field_label' ); ?> <span class="req">*</span></label>
                        <input class="cmms-input" name="label" type="text" required placeholder="<?php echo esc_attr( $this->t( 'settings.field_label_ph' ) ); ?>">
                    </div>
                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'settings.field_type' ); ?></label>
                        <select class="cmms-select" name="field_type" data-cmms-field-type-select>
                            <?php foreach ( CMMS_Assets::field_def_types() as $k => $v ) : ?>
                                <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cmms-field" data-cmms-field-options-row hidden>
                        <label class="cmms-field-label"><?php $this->e( 'settings.field_options' ); ?></label>
                        <textarea class="cmms-textarea" name="options" rows="3" placeholder="<?php echo esc_attr( $this->t( 'settings.field_options_ph' ) ); ?>"></textarea>
                        <small class="cmms-muted cmms-text-sm"><?php $this->e( 'settings.field_options_help' ); ?></small>
                    </div>
                    <div class="cmms-field">
                        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                            <input type="checkbox" name="required" value="1">
                            <span><?php $this->e( 'settings.field_required' ); ?></span>
                        </label>
                    </div>
                    <div class="cmms-field">
                        <label style="display:flex;align-items:start;gap:8px;font-weight:normal;">
                            <input type="checkbox" name="is_public" value="1" style="margin-top:2px;">
                            <span style="display:flex;flex-direction:column;gap:2px;">
                                <strong style="font-weight:500;"><?php $this->e( 'settings.field_is_public' ); ?></strong>
                                <small class="cmms-muted cmms-text-sm" style="line-height:1.4;"><?php $this->e( 'settings.field_is_public_help' ); ?></small>
                            </span>
                        </label>
                    </div>
                    <div>
                        <button type="submit" class="cmms-btn cmms-btn-primary">
                            <?php CMMS_Icons::e( 'plus', 16 ); ?> <?php $this->e( 'settings.field_add' ); ?>
                        </button>
                    </div>
                </form>
                <script>
                    // Toggle the "options" textarea visibility based on field_type select.
                    // Only the "select" type makes use of options.
                    (function () {
                        var sel = document.querySelector('[data-cmms-field-type-select]');
                        var optsRow = document.querySelector('[data-cmms-field-options-row]');
                        if (!sel || !optsRow) return;
                        function toggle() { optsRow.hidden = (sel.value !== 'select'); }
                        sel.addEventListener('change', toggle);
                        toggle();
                    })();
                </script>
            </div>
        </section>

        <?php elseif ( $tab === 'notify' ) : $this->view_settings_notify( $u ); endif; ?>
        <?php if ( $tab === 'billing' && $is_owner ) : $this->view_settings_billing( $u ); endif; ?>
        <?php
    }

    /**
     * 1.14.56: Billing & Subscription settings tab.
     *
     * Owner-only. Shows the current plan, billing cycle, next charge
     * date, and (most importantly) a "Cancel subscription" button that
     * cancels the recurring charge at iCredit and marks the account
     * as canceled_pending.
     *
     * Compliance note: under Israel's Consumer Protection Law (Tikun
     * 47, "Subscription with auto-renewal"), the cancellation must be
     * available with ONE click from the same place the user signed up.
     * We satisfy that here.
     */
    private function view_settings_billing( $u ) {
        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $subs_t     = CMMS_DB::table( 'subscriptions' );

        $account = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $accounts_t WHERE id = %d",
            (int) $u->account_id
        ), ARRAY_A );

        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $subs_t WHERE account_id = %d ORDER BY id DESC LIMIT 1",
            (int) $u->account_id
        ), ARRAY_A );

        // Package details from plans catalog.
        $package_name = '—';
        $cycle_label  = '—';
        $price_label  = '—';
        if ( $account && ! empty( $account['plan_type'] ) ) {
            $pkg = CMMS_Plans::get_package( $account['plan_type'], $account['billing_cycle'] ?: 'monthly' );
            if ( $pkg ) {
                $package_name = $pkg['display_name'];
                $cycle_label  = $account['billing_cycle'] === 'yearly' ? 'שנתי' : 'חודשי';
                $price_label  = '₪' . number_format( (float) $pkg['price'], 0 );
            }
        }

        $sub_status = $sub['status'] ?? '';
        $acct_status = $account['subscription_status'] ?? '';
        $next_charge = $sub['next_charge_at'] ?? '';
        $is_active = in_array( $sub_status, array( 'active', 'past_due' ), true );
        $is_canceled = ( $acct_status === 'canceled_pending' || $sub_status === 'canceled' );

        $nonce = wp_create_nonce( 'cmms_subscription_cancel' );
        ?>
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title">
                    <?php CMMS_Icons::e( 'credit-card', 18 ); ?>
                    פרטי החברות
                </h3>
            </div>
            <div class="cmms-section-body">
                <div class="cmms-billing-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:24px;">
                    <div class="cmms-billing-cell">
                        <div style="font-size:12px; color:#94a3b8; margin-bottom:4px;">חבילה</div>
                        <div style="font-size:18px; font-weight:600; color:#0f172a;"><?php echo esc_html( $package_name ); ?></div>
                    </div>
                    <div class="cmms-billing-cell">
                        <div style="font-size:12px; color:#94a3b8; margin-bottom:4px;">מחזור חיוב</div>
                        <div style="font-size:18px; font-weight:600; color:#0f172a;"><?php echo esc_html( $cycle_label ); ?></div>
                    </div>
                    <div class="cmms-billing-cell">
                        <div style="font-size:12px; color:#94a3b8; margin-bottom:4px;">סכום</div>
                        <div style="font-size:18px; font-weight:600; color:#0f172a;"><?php echo esc_html( $price_label ); ?></div>
                    </div>
                    <div class="cmms-billing-cell">
                        <div style="font-size:12px; color:#94a3b8; margin-bottom:4px;">סטטוס</div>
                        <div style="font-size:14px; font-weight:600;">
                            <?php if ( $is_canceled ) : ?>
                                <span style="color:#dc2626;">בוטלה — תוקף עד תאריך הסיום</span>
                            <?php elseif ( $sub_status === 'active' ) : ?>
                                <span style="color:#16a34a;">פעילה</span>
                            <?php elseif ( $sub_status === 'past_due' ) : ?>
                                <span style="color:#d97706;">חיוב נכשל — דחיית חיוב</span>
                            <?php elseif ( $sub_status === 'frozen' ) : ?>
                                <span style="color:#dc2626;">מוקפאת</span>
                            <?php else : ?>
                                <span style="color:#64748b;"><?php echo esc_html( $sub_status ?: 'ללא' ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ( $next_charge && $is_active && ! $is_canceled ) : ?>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; margin-bottom:20px;">
                    <strong>חיוב הבא:</strong> <?php echo esc_html( mysql2date( 'd/m/Y', $next_charge ) ); ?>
                </div>
                <?php endif; ?>

                <?php if ( $is_canceled && $next_charge ) : ?>
                <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:10px; padding:14px 16px; margin-bottom:20px;">
                    <strong>החברות בוטלה.</strong> תוכל להמשיך להשתמש במערכת עד <?php echo esc_html( mysql2date( 'd/m/Y', $next_charge ) ); ?>. אחר תאריך זה, החשבון ייחסם.
                </div>
                <?php endif; ?>
            </div>
        </section>

        <?php
        // 1.14.60: Plan change UI — only shown when subscription is
        // active AND not already canceled. We also fetch the current
        // pending state to know whether to show the "scheduled" banner.
        $pending_type    = $sub['pending_change_type'] ?? '';
        $pending_plan    = $sub['pending_plan_type'] ?? '';
        $pending_cycle   = $sub['pending_billing_cycle'] ?? '';
        $pending_at      = $sub['pending_change_effective_at'] ?? '';
        $has_pending_dg  = ( $pending_type === 'downgrade' && ! empty( $pending_plan ) );
        $has_pending_up  = ( $pending_type === 'upgrade_in_progress' );

        // Build catalog of switchable packages — only those of the
        // SAME billing cycle as the current subscription (Phase 1).
        $current_cycle = $sub['billing_cycle'] ?? 'monthly';
        $switchable = array();
        foreach ( array( 'starter', 'business', 'enterprise' ) as $pt ) {
            $pkg = CMMS_Plans::get_package( $pt, $current_cycle );
            if ( ! $pkg ) continue;
            if ( $pt === ( $sub['plan_type'] ?? '' ) ) continue; // skip current
            $switchable[] = $pkg;
        }
        $plan_change_nonce = wp_create_nonce( 'cmms_plan_change' );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>

        <?php if ( $is_active && ! $is_canceled ) : ?>
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title">
                    <?php CMMS_Icons::e( 'refresh-cw', 18 ); ?>
                    שינוי חבילה
                </h3>
            </div>
            <div class="cmms-section-body">
                <?php if ( $has_pending_up ) : ?>
                    <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                        <strong>שדרוג בעיבוד.</strong> תשלום ההפרש לא הושלם עדיין. השדרוג יחול אוטומטית עם אישור התשלום.
                    </div>
                <?php elseif ( $has_pending_dg ) : ?>
                    <?php
                    $pending_pkg = CMMS_Plans::get_package( $pending_plan, $pending_cycle );
                    $pending_label = $pending_pkg ? $pending_pkg['display_name'] : $pending_plan;
                    $pending_date  = $pending_at ? mysql2date( 'd/m/Y', $pending_at ) : '';
                    ?>
                    <div style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;border-radius:10px;padding:14px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                        <div>
                            <strong>הורדת חבילה מתוכננת.</strong>
                            השינוי ל-<?php echo esc_html( $pending_label ); ?>
                            ייכנס לתוקף ב-<?php echo esc_html( $pending_date ); ?>.
                            עד אז תמשיך/י לקבל את כל היתרונות של החבילה הנוכחית.
                        </div>
                        <button type="button" id="cmms-plan-cancel-pending"
                                data-nonce="<?php echo esc_attr( $plan_change_nonce ); ?>"
                                class="cmms-btn"
                                style="background:#fff;border:1px solid #93c5fd;color:#1e40af;">
                            ביטול השינוי
                        </button>
                    </div>
                <?php else : ?>
                    <p style="color:#475569;line-height:1.6;margin:0 0 16px;">
                        אפשר לעבור לחבילה אחרת בכל רגע. שדרוג נכנס לתוקף מיידית (חיוב יחסי על ההפרש). הורדה נכנסת לתוקף בתאריך החיוב הבא ללא חיוב נוסף.
                    </p>
                    <button type="button" id="cmms-plan-change-open" class="cmms-btn cmms-btn-primary">
                        בחר חבילה אחרת
                    </button>
                <?php endif; ?>
            </div>
        </section>

        <!-- Plan change modal -->
        <div id="cmms-plan-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto;">
            <div style="background:#fff;border-radius:16px;max-width:880px;width:100%;padding:28px;position:relative;font-family:Arial,sans-serif;direction:rtl;">
                <button type="button" id="cmms-plan-modal-close"
                        style="position:absolute;top:14px;left:14px;background:#f1f5f9;border:0;border-radius:8px;width:36px;height:36px;cursor:pointer;font-size:20px;line-height:1;color:#64748b;">&times;</button>
                <h2 style="margin:0 0 6px;font-size:22px;color:#0f172a;">בחר חבילה חדשה</h2>
                <p style="margin:0 0 22px;color:#64748b;font-size:14px;">המחיר המוצג הוא המחיר המלא של החבילה. החיוב היחסי יוצג בדף האישור.</p>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">
                    <?php foreach ( $switchable as $pkg ) :
                        $price = (float) $pkg['price'];
                        $current_price = (float) ( $sub['amount'] ?? 0 );
                        $is_up = $price > $current_price;
                        $features = is_array( $pkg['features'] ?? null ) ? $pkg['features'] : array();
                        $max_users = isset( $pkg['max_users'] ) && $pkg['max_users'] !== null ? (int) $pkg['max_users'] : null;
                    ?>
                    <div class="cmms-plan-card"
                         style="border:1.5px solid #e2e8f0;border-radius:14px;padding:18px;background:#fff;display:flex;flex-direction:column;gap:10px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <strong style="font-size:17px;color:#0f172a;"><?php echo esc_html( $pkg['display_name'] ); ?></strong>
                            <span style="font-size:11px;padding:2px 8px;border-radius:10px;<?php echo $is_up ? 'background:#dcfce7;color:#15803d;' : 'background:#dbeafe;color:#1e40af;'; ?>">
                                <?php echo $is_up ? 'שדרוג' : 'הורדה'; ?>
                            </span>
                        </div>
                        <div style="font-size:24px;font-weight:700;color:#0f172a;">
                            ₪<?php echo esc_html( number_format( $price, 0 ) ); ?>
                            <span style="font-size:13px;font-weight:400;color:#64748b;">/ <?php echo $current_cycle === 'yearly' ? 'שנה' : 'חודש'; ?></span>
                        </div>
                        <?php if ( $max_users !== null ) : ?>
                            <div style="font-size:13px;color:#475569;">עד <?php echo (int) $max_users; ?> משתמשים</div>
                        <?php else : ?>
                            <div style="font-size:13px;color:#475569;">משתמשים ללא הגבלה</div>
                        <?php endif; ?>
                        <?php if ( ! empty( $features ) ) : ?>
                            <ul style="list-style:none;padding:0;margin:6px 0 0;font-size:13px;color:#475569;line-height:1.7;">
                                <?php foreach ( array_slice( $features, 0, 4 ) as $feat ) : ?>
                                    <li>✓ <?php echo esc_html( $feat ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <button type="button"
                                class="cmms-btn cmms-plan-select"
                                style="margin-top:auto;background:<?php echo $is_up ? '#ff6a00' : '#0f172a'; ?>;color:#fff;border:0;border-radius:10px;padding:10px;font-weight:600;cursor:pointer;"
                                data-plan="<?php echo esc_attr( $pkg['plan_type'] ); ?>"
                                data-cycle="<?php echo esc_attr( $pkg['billing_cycle'] ); ?>">
                            <?php echo $is_up ? 'שדרג לחבילה זו' : 'הורד לחבילה זו'; ?>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Plan change confirmation modal -->
        <div id="cmms-plan-confirm" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:100000;align-items:center;justify-content:center;padding:40px 16px;">
            <div style="background:#fff;border-radius:16px;max-width:520px;width:100%;padding:28px;font-family:Arial,sans-serif;direction:rtl;">
                <h2 style="margin:0 0 12px;font-size:20px;color:#0f172a;" id="cmms-plan-confirm-title">אישור שינוי חבילה</h2>
                <div id="cmms-plan-confirm-body" style="margin:0 0 20px;color:#475569;line-height:1.7;font-size:14px;"></div>
                <div id="cmms-plan-confirm-loading" style="display:none;color:#64748b;font-size:13px;margin-bottom:14px;">בטעינה...</div>
                <div style="display:flex;gap:10px;justify-content:flex-start;">
                    <button type="button" id="cmms-plan-confirm-yes"
                            style="background:#ff6a00;color:#fff;border:0;border-radius:10px;padding:11px 22px;font-weight:600;cursor:pointer;font-size:14px;">
                        אישור
                    </button>
                    <button type="button" id="cmms-plan-confirm-no"
                            style="background:#f1f5f9;color:#475569;border:0;border-radius:10px;padding:11px 22px;cursor:pointer;font-size:14px;">
                        ביטול
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var modal     = document.getElementById('cmms-plan-modal');
            var modalOpen = document.getElementById('cmms-plan-change-open');
            var modalClose= document.getElementById('cmms-plan-modal-close');
            var confirmEl = document.getElementById('cmms-plan-confirm');
            var confirmBody = document.getElementById('cmms-plan-confirm-body');
            var confirmYes  = document.getElementById('cmms-plan-confirm-yes');
            var confirmNo   = document.getElementById('cmms-plan-confirm-no');
            var confirmLoad = document.getElementById('cmms-plan-confirm-loading');
            var nonce = <?php echo wp_json_encode( $plan_change_nonce ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
            var pendingChoice = null;

            function show(el) { el.style.display = 'flex'; }
            function hide(el) { el.style.display = 'none'; }

            if (modalOpen) modalOpen.addEventListener('click', function () { show(modal); });
            if (modalClose) modalClose.addEventListener('click', function () { hide(modal); });
            if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) hide(modal); });

            document.querySelectorAll('.cmms-plan-select').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var plan  = btn.dataset.plan;
                    var cycle = btn.dataset.cycle;
                    pendingChoice = { plan_type: plan, billing_cycle: cycle };

                    // Fetch preview before showing confirmation.
                    confirmBody.textContent = 'מחשבים את עלות השינוי...';
                    show(confirmEl);
                    hide(modal);

                    var fd = new FormData();
                    fd.append('action', 'cmms_plan_change_preview');
                    fd.append('nonce', nonce);
                    fd.append('plan_type', plan);
                    fd.append('billing_cycle', cycle);

                    fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            if (!j || !j.success) {
                                confirmBody.innerHTML = '<span style="color:#dc2626;">' + ((j && j.data && j.data.message) || 'שגיאה בחישוב.') + '</span>';
                                confirmYes.style.display = 'none';
                                return;
                            }
                            confirmYes.style.display = '';
                            var p = j.data.proration;
                            var np = j.data.new_package;
                            var lines = [];
                            if (p.is_upgrade) {
                                lines.push('<strong style="color:#0f172a;">שדרוג ל-' + np.display_name + '</strong>');
                                lines.push('זיכוי על יתרת החבילה הישנה: <strong>₪' + p.credit_old.toFixed(2) + '</strong>');
                                lines.push('עלות יחסית בחבילה החדשה: <strong>₪' + p.charge_new.toFixed(2) + '</strong>');
                                lines.push('<div style="margin-top:10px;padding:10px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;color:#9a3412;"><strong>חיוב מיידי: ₪' + p.net_charge.toFixed(2) + '</strong></div>');
                                lines.push('<div style="margin-top:10px;font-size:12px;color:#64748b;">לאחר אישור התשלום, החבילה תעבור מיידית. מחזורי החיוב הבאים יהיו במחיר המלא של ' + np.display_name + ' (₪' + np.price.toFixed(0) + ').</div>');
                            } else if (p.is_downgrade) {
                                var d = j.data.current_sub.next_charge_at ? new Date(j.data.current_sub.next_charge_at.replace(' ', 'T')) : null;
                                var dateStr = d ? (('0'+d.getDate()).slice(-2) + '/' + ('0'+(d.getMonth()+1)).slice(-2) + '/' + d.getFullYear()) : 'תאריך החיוב הבא';
                                lines.push('<strong style="color:#0f172a;">הורדה ל-' + np.display_name + '</strong>');
                                lines.push('השינוי ייכנס לתוקף ב-<strong>' + dateStr + '</strong>.');
                                lines.push('עד אז, תמשיכ/י לקבל את כל היתרונות של החבילה הנוכחית.');
                                lines.push('<strong>אין חיוב נוסף כעת.</strong>');
                                lines.push('<div style="margin-top:10px;font-size:12px;color:#64748b;">תוכל/י לבטל את השינוי בכל רגע לפני תאריך התוקף.</div>');
                            } else {
                                lines.push('לא נמצא שינוי תקף.');
                                confirmYes.style.display = 'none';
                            }
                            confirmBody.innerHTML = lines.join('<br>');
                        })
                        .catch(function () {
                            confirmBody.innerHTML = '<span style="color:#dc2626;">שגיאת רשת.</span>';
                            confirmYes.style.display = 'none';
                        });
                });
            });

            if (confirmNo) confirmNo.addEventListener('click', function () { hide(confirmEl); pendingChoice = null; });

            if (confirmYes) confirmYes.addEventListener('click', function () {
                if (!pendingChoice) return;
                confirmYes.disabled = true; confirmNo.disabled = true;
                confirmLoad.style.display = '';

                var fd = new FormData();
                fd.append('action', 'cmms_plan_change_apply');
                fd.append('nonce', nonce);
                fd.append('plan_type', pendingChoice.plan_type);
                fd.append('billing_cycle', pendingChoice.billing_cycle);

                fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (!j || !j.success) {
                            alert((j && j.data && j.data.message) || 'שגיאה.');
                            confirmYes.disabled = false; confirmNo.disabled = false;
                            confirmLoad.style.display = 'none';
                            return;
                        }
                        if (j.data.mode === 'redirect_to_payment') {
                            window.location.href = j.data.redirect_url;
                        } else {
                            alert(j.data.message || 'בוצע.');
                            location.reload();
                        }
                    })
                    .catch(function () {
                        alert('שגיאת רשת. נסה שוב.');
                        confirmYes.disabled = false; confirmNo.disabled = false;
                        confirmLoad.style.display = 'none';
                    });
            });

            // Cancel pending change (downgrade revert).
            var cancelPendingBtn = document.getElementById('cmms-plan-cancel-pending');
            if (cancelPendingBtn) cancelPendingBtn.addEventListener('click', function () {
                if (!confirm('האם לבטל את השינוי המתוכנן ולהישאר עם החבילה הנוכחית?')) return;
                var fd = new FormData();
                fd.append('action', 'cmms_plan_change_cancel_pending');
                fd.append('nonce', cancelPendingBtn.dataset.nonce);
                fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.success) {
                            alert(j.data.message);
                            location.reload();
                        } else {
                            alert((j && j.data && j.data.message) || 'שגיאה.');
                        }
                    });
            });
        })();
        </script>
        <?php endif; ?>

        <?php if ( $is_active && ! $is_canceled ) : ?>
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title" style="color:#dc2626;">
                    <?php CMMS_Icons::e( 'alert-circle', 18 ); ?>
                    ביטול החברות
                </h3>
            </div>
            <div class="cmms-section-body">
                <p style="color:#475569; line-height:1.6; margin:0 0 16px;">
                    ביטול החברות יעצור את החיובים החודשיים העתידיים. תוכל להמשיך להשתמש במערכת באופן רגיל עד תום תקופת החיוב הנוכחית
                    <?php if ( $next_charge ) : ?>
                        (<?php echo esc_html( mysql2date( 'd/m/Y', $next_charge ) ); ?>)
                    <?php endif; ?>.
                </p>
                <p style="color:#64748b; font-size:13px; margin:0 0 20px;">
                    הביטול הוא מיידי ולא ניתן לבטל אותו. כדי לחדש את החברות אחרי הביטול — תידרש להירשם מחדש.
                </p>
                <button type="button"
                        id="cmms-cancel-sub-btn"
                        class="cmms-btn"
                        style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca;"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    <?php CMMS_Icons::e( 'x', 16 ); ?>
                    ביטול החברות
                </button>
            </div>
        </section>

        <script>
        (function () {
            var btn = document.getElementById('cmms-cancel-sub-btn');
            if (!btn) return;
            btn.addEventListener('click', function () {
                if ( ! confirm('האם אתה בטוח שברצונך לבטל את החברות?\n\nהביטול יעצור את החיובים העתידיים. תוכל להמשיך להשתמש עד תום התקופה הנוכחית.\n\nפעולה זו אינה הפיכה.') ) return;

                btn.disabled = true;
                btn.textContent = 'מבטל...';

                var fd = new FormData();
                fd.append('action', 'cmms_subscription_cancel');
                fd.append('nonce', btn.dataset.nonce);

                fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.success) {
                        alert(j.data.message || 'בוצע.');
                        location.reload();
                    } else {
                        var msg = (j && j.data && j.data.message) ? j.data.message : 'שגיאה לא ידועה.';
                        alert('הביטול נכשל: ' + msg);
                        btn.disabled = false;
                        btn.textContent = 'ביטול החברות';
                    }
                })
                .catch(function (err) {
                    alert('שגיאת רשת. נסה שוב.');
                    btn.disabled = false;
                    btn.textContent = 'ביטול החברות';
                });
            });
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    private function view_settings_notify( $u ) {
        $settings = CMMS_Notifications::get_account_settings( $u->account_id );
        $last_run = get_option( 'cmms_cron_last_run' );
        $now_ts = current_time( 'timestamp' );
        $last_run_ts = $last_run ? strtotime( $last_run ) : 0;
        $cron_status_class = 'ok';
        $cron_status_msg = $this->t( 'notify.cron_ok' );
        if ( ! $last_run ) {
            $cron_status_class = 'warn';
            $cron_status_msg = $this->t( 'notify.cron_never' );
        } elseif ( $now_ts - $last_run_ts > 30 * MINUTE_IN_SECONDS ) {
            $cron_status_class = 'warn';
            $cron_status_msg = sprintf( $this->t( 'notify.cron_late' ), $this->humanize_time( $last_run ) );
        }
        ?>
        <section class="cmms-section" data-cmms-page="settings-notify">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'bell', 16 ); ?> <?php $this->e( 'notify.settings' ); ?></h3>
                <p class="cmms-section-sub"><?php $this->e( 'notify.settings_sub' ); ?></p>
            </div>
            <div class="cmms-section-body">
                <div class="cmms-cron-status <?php echo esc_attr( $cron_status_class ); ?>">
                    <?php CMMS_Icons::e( $cron_status_class === 'ok' ? 'check-circle' : 'alert-triangle', 16 ); ?>
                    <span><?php echo esc_html( $cron_status_msg ); ?></span>
                </div>

                <!-- Push notifications panel -->
                <div class="cmms-push-panel" data-cmms-push-panel>
                    <div class="cmms-push-panel-head">
                        <strong><?php $this->e( 'push.title' ); ?></strong>
                        <span class="cmms-push-status" data-cmms-push-status></span>
                    </div>
                    <p class="cmms-push-desc" data-cmms-push-desc><?php $this->e( 'push.desc_default' ); ?></p>
                    <button type="button" class="cmms-btn cmms-btn-primary" data-cmms-push-enable hidden>
                        <?php CMMS_Icons::e( 'bell', 16 ); ?> <?php $this->e( 'push.enable' ); ?>
                    </button>
                    <button type="button" class="cmms-btn cmms-btn-ghost" data-cmms-push-disable hidden>
                        <?php $this->e( 'push.disable' ); ?>
                    </button>
                    <button type="button" class="cmms-btn cmms-btn-ghost cmms-btn-sm" data-cmms-push-test hidden>
                        <?php $this->e( 'push.test' ); ?>
                    </button>
                </div>

                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form">
                    <input type="hidden" name="action" value="cmms_notify_settings_save">
                    <?php wp_nonce_field( 'cmms_notify_settings_save', 'cmms_notify_settings_save_nonce' ); ?>

                    <div class="cmms-toggle-row">
                        <div class="cmms-toggle-row-info">
                            <strong><?php $this->e( 'notify.remind_before' ); ?></strong>
                            <span><?php $this->e( 'notify.remind_before_d' ); ?></span>
                        </div>
                        <label class="cmms-checkbox" style="margin:0;">
                            <input type="checkbox" name="remind_before_enabled" value="1" <?php checked( ! empty( $settings['remind_before_enabled'] ) ); ?>>
                        </label>
                    </div>
                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'notify.hours_before' ); ?></label>
                        <input class="cmms-input" type="number" min="1" max="720" name="remind_before_hours" value="<?php echo esc_attr( $settings['remind_before_hours'] ); ?>">
                    </div>

                    <div class="cmms-toggle-row" style="margin-top:24px;">
                        <div class="cmms-toggle-row-info">
                            <strong><?php $this->e( 'notify.remind_after' ); ?></strong>
                            <span><?php $this->e( 'notify.remind_after_d' ); ?></span>
                        </div>
                        <label class="cmms-checkbox" style="margin:0;">
                            <input type="checkbox" name="remind_after_enabled" value="1" <?php checked( ! empty( $settings['remind_after_enabled'] ) ); ?>>
                        </label>
                    </div>
                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'notify.hours_after' ); ?></label>
                        <input class="cmms-input" type="number" min="1" max="720" name="remind_after_hours" value="<?php echo esc_attr( $settings['remind_after_hours'] ); ?>">
                    </div>
                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'notify.repeat_every' ); ?></label>
                        <input class="cmms-input" type="number" min="0" max="720" name="remind_after_repeat" value="<?php echo esc_attr( $settings['remind_after_repeat'] ); ?>">
                    </div>
                    <div class="cmms-field">
                        <label class="cmms-field-label"><?php $this->e( 'notify.repeat_max' ); ?></label>
                        <input class="cmms-input" type="number" min="1" max="20" name="remind_after_max" value="<?php echo esc_attr( $settings['remind_after_max'] ); ?>">
                    </div>

                    <div style="margin-top:24px;">
                        <button type="submit" class="cmms-btn cmms-btn-primary">
                            <?php CMMS_Icons::e( 'save', 16 ); ?> <?php $this->e( 'common.save' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        </section>
        <?php
    }

    private function forbidden() {
        ?>
        <div class="cmms-section"><div class="cmms-section-body">
            <?php $this->empty_state( 'lock', 'common.forbidden', 'common.forbidden_d' ); ?>
        </div></div>
        <?php
    }
    private function not_found() {
        ?>
        <div class="cmms-section"><div class="cmms-section-body">
            <?php $this->empty_state( 'search', 'common.not_found', 'common.not_found_d' ); ?>
        </div></div>
        <?php
    }

    /* ============================================================
       PUBLIC FORM (no auth)
    ============================================================ */
    public function render_public_form( $atts = array() ) {
        $slug = isset( $_GET['cmms_form'] ) ? sanitize_title( wp_unslash( $_GET['cmms_form'] ) ) : '';
        $dir = $this->dir();
        ob_start();
        ?>
        <div class="cmms-public-form-page" dir="<?php echo esc_attr( $dir ); ?>" lang="<?php echo esc_attr( $this->lang() ); ?>">
            <?php $this->lang_switcher(); ?>
            <?php
            if ( empty( $slug ) ) {
                echo '<div class="cmms-public-form-card"><div class="cmms-public-form-body"><div class="cmms-alert cmms-alert-error">' . esc_html( $this->t( 'public.invalid' ) ) . '</div></div></div>';
                return ob_get_clean();
            }
            $form = CMMS_Forms::get_by_slug( $slug );
            if ( ! $form || ! $form->enabled ) {
                echo '<div class="cmms-public-form-card"><div class="cmms-public-form-body"><div class="cmms-alert cmms-alert-error">' . esc_html( $this->t( 'public.not_available' ) ) . '</div></div></div>';
                return ob_get_clean();
            }
            $account = CMMS_Accounts::get( $form->account_id );
            if ( ! $account || $account->status !== 'active' ) {
                echo '<div class="cmms-public-form-card"><div class="cmms-public-form-body"><div class="cmms-alert cmms-alert-error">' . esc_html( $this->t( 'public.not_available' ) ) . '</div></div></div>';
                return ob_get_clean();
            }

            $message = ''; $error = '';
            if ( isset( $_POST['cmms_public_form_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['cmms_public_form_nonce'] ), 'cmms_public_form_' . $form->id ) ) {
                $result = CMMS_Forms::process_submission( $form->slug, $_POST, $_FILES );
                if ( is_wp_error( $result ) ) $error = $result->get_error_message();
                else $message = $this->t( 'public.thanks' );
            }
            $fields = CMMS_Forms::get_fields( $form->id );
            ?>
            <div class="cmms-public-form-card">
                <div class="cmms-public-form-head">
                    <h2><?php echo esc_html( $form->name ); ?></h2>
                    <?php if ( $form->description ) : ?>
                        <p><?php echo esc_html( $form->description ); ?></p>
                    <?php endif; ?>
                </div>
                <div class="cmms-public-form-body">
                    <?php if ( $message ) : ?>
                        <div class="cmms-alert cmms-alert-success"><?php CMMS_Icons::e( 'check-circle', 18 ); ?> <span><?php echo esc_html( $message ); ?></span></div>
                    <?php elseif ( $error ) : ?>
                        <div class="cmms-alert cmms-alert-error"><?php CMMS_Icons::e( 'alert-circle', 18 ); ?> <span><?php echo esc_html( $error ); ?></span></div>
                    <?php endif; ?>

                    <?php if ( ! $message ) : ?>
                    <form method="post" enctype="multipart/form-data" class="cmms-form">
                        <?php wp_nonce_field( 'cmms_public_form_' . $form->id, 'cmms_public_form_nonce' ); ?>
                        <?php foreach ( $fields as $field ) : $name = 'field_' . $field->id; $required = $field->required ? 'required' : ''; ?>
                            <div class="cmms-field">
                                <label class="cmms-field-label" for="<?php echo esc_attr( $name ); ?>">
                                    <?php echo esc_html( $field->label ); ?>
                                    <?php if ( $field->required ) : ?><span class="req">*</span><?php endif; ?>
                                </label>
                                <?php if ( $field->field_type === 'text' ) : ?>
                                    <input class="cmms-input" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" type="text" <?php echo $required; ?>>
                                <?php elseif ( $field->field_type === 'textarea' ) : ?>
                                    <textarea class="cmms-textarea" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="4" <?php echo $required; ?>></textarea>
                                <?php elseif ( $field->field_type === 'date' ) : ?>
                                    <input class="cmms-input" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" type="date" <?php echo $required; ?>>
                                <?php elseif ( $field->field_type === 'dropdown' ) : $opts = array_filter( array_map( 'trim', explode( ',', (string) $field->options ) ) ); ?>
                                    <select class="cmms-select" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required; ?>>
                                        <option value=""><?php $this->e( 'common.select' ); ?></option>
                                        <?php foreach ( $opts as $opt ) : ?>
                                            <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ( $field->field_type === 'checkbox' ) :
                                    // Smart checkbox: if the field has options, render
                                    // a list of checkboxes (multi-select). If it has
                                    // no options, render a single yes/no checkbox where
                                    // the label is the field's own label.
                                    $opts = array_filter( array_map( 'trim', explode( ',', (string) $field->options ) ) );
                                    if ( ! empty( $opts ) ) :
                                        // Multi-checkbox. Submit as name[] so $_POST gets an array.
                                        ?>
                                        <div class="cmms-checkbox-group">
                                            <?php foreach ( $opts as $opt ) : ?>
                                                <label class="cmms-checkbox">
                                                    <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $opt ); ?>">
                                                    <span><?php echo esc_html( $opt ); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else : ?>
                                        <label class="cmms-checkbox">
                                            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php echo $required; ?>>
                                            <span><?php $this->e( 'common.yes' ); ?></span>
                                        </label>
                                    <?php endif; ?>
                                <?php elseif ( $field->field_type === 'file' ) : ?>
                                    <input class="cmms-input" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" type="file" <?php echo $required; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="cmms-btn cmms-btn-primary cmms-btn-block cmms-btn-lg"><?php $this->e( 'public.submit' ); ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ========================================================================
     * PUBLIC ASSET RECORD ([cmms_public_asset])
     *
     * QR codes printed on physical assets land here. URL shape:
     *   /cmms-asset/?asset=<unguessable_token>
     *
     * Two render modes based on whether the visitor is logged in:
     *  - Public mode: minimal info + a small list of allowed actions
     *    (report breakdown, upload photo, submit a public form). NO task
     *    history, NO custom field values that the admin marked sensitive,
     *    NO file list.
     *  - Internal mode: redirect logged-in users to the full /cmms-dashboard/
     *    asset detail page (which has every action and full data).
     * ======================================================================*/
    public function render_public_asset( $atts = array() ) {
        $token = isset( $_GET['asset'] ) ? sanitize_text_field( wp_unslash( $_GET['asset'] ) ) : '';
        $dir = $this->dir();

        // Logged-in CMMS user with access to this account → bounce to full record.
        if ( $token && is_user_logged_in() ) {
            $cu = CMMS_Auth::current_cmms_user();
            if ( $cu ) {
                $asset = CMMS_Assets::get_by_qr_token( $token );
                if ( $asset && (int) $asset->account_id === (int) $cu->account_id ) {
                    $url = add_query_arg(
                        array( 'view' => 'asset', 'id' => (int) $asset->id ),
                        home_url( '/cmms-dashboard/' )
                    );
                    return '<script>location.replace(' . wp_json_encode( $url ) . ');</script>'
                         . '<noscript><a href="' . esc_url( $url ) . '">' . esc_html( $this->t( 'public.asset.open_internal' ) ) . '</a></noscript>';
                }
            }
        }

        ob_start();
        ?>
        <div class="cmms-public-form-page" dir="<?php echo esc_attr( $dir ); ?>" lang="<?php echo esc_attr( $this->lang() ); ?>">
            <?php $this->lang_switcher(); ?>
            <?php
            if ( empty( $token ) ) {
                echo '<div class="cmms-public-form-card"><div class="cmms-public-form-body"><div class="cmms-alert cmms-alert-error">' . esc_html( $this->t( 'public.invalid' ) ) . '</div></div></div>';
                return ob_get_clean();
            }
            $asset = CMMS_Assets::get_by_qr_token( $token );
            if ( ! $asset || $asset->status !== 'active' || empty( $asset->public_qr_enabled ) ) {
                echo '<div class="cmms-public-form-card"><div class="cmms-public-form-body"><div class="cmms-alert cmms-alert-error">' . esc_html( $this->t( 'public.asset.not_found' ) ) . '</div></div></div>';
                return ob_get_clean();
            }
            $allowed_actions = CMMS_Assets::decode_public_actions( $asset );
            $type_label = '';
            $types = CMMS_Assets::asset_types();
            if ( isset( $types[ $asset->asset_type ] ) ) $type_label = $types[ $asset->asset_type ];

            // Was this a successful action submission? Show success state.
            $just_submitted = isset( $_GET['cmms_submitted'] ) ? sanitize_key( wp_unslash( $_GET['cmms_submitted'] ) ) : '';
            ?>
            <div class="cmms-public-asset-card">
                <header class="cmms-public-asset-header">
                    <div class="cmms-public-asset-type"><?php echo esc_html( $type_label ); ?></div>
                    <h1 class="cmms-public-asset-name"><?php echo esc_html( $asset->name ); ?></h1>
                    <?php if ( ! empty( $asset->location ) ) : ?>
                        <div class="cmms-public-asset-location">
                            <?php CMMS_Icons::e( 'map-pin', 14 ); ?>
                            <span><?php echo esc_html( $asset->location ); ?></span>
                        </div>
                    <?php endif; ?>
                </header>

                <?php if ( $just_submitted ) : ?>
                    <div class="cmms-alert cmms-alert-success" style="margin:0 16px 16px;">
                        <strong><?php $this->e( 'public.asset.thanks_title' ); ?></strong>
                        <div style="margin-top:4px;font-size:13px;">
                            <?php $this->e( 'public.asset.thanks_body' ); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                // Public-safe custom fields. Empty array if admin hasn't
                // marked any field as is_public, OR if no values are filled
                // for the public fields. Fail-closed by design.
                $public_fields = CMMS_Assets::decode_public_custom_fields( $asset );
                if ( ! empty( $public_fields ) ) :
                ?>
                <div class="cmms-public-asset-fields">
                    <?php foreach ( $public_fields as $field ) :
                        $val   = isset( $field['value'] ) ? $field['value'] : '';
                        $label = isset( $field['label'] ) ? $field['label'] : $field['field_key'];
                        $type  = isset( $field['type'] ) ? $field['type'] : 'text';
                        if ( $val === '' || $val === null ) continue;
                    ?>
                        <div class="cmms-public-asset-field-row">
                            <span class="cmms-public-asset-field-label"><?php echo esc_html( $label ); ?></span>
                            <span class="cmms-public-asset-field-value">
                                <?php if ( $type === 'checkbox' ) : ?>
                                    <?php echo $val ? '✓' : '—'; ?>
                                <?php else : ?>
                                    <?php echo esc_html( (string) $val ); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="cmms-public-asset-body">
                    <h2 class="cmms-public-asset-section-title"><?php $this->e( 'public.asset.what_to_do' ); ?></h2>
                    <div class="cmms-public-asset-actions">
                        <?php if ( in_array( 'report_breakdown', $allowed_actions, true ) ) : ?>
                            <button type="button" class="cmms-public-asset-action" data-cmms-public-asset-action="breakdown">
                                <span class="cmms-public-asset-action-icon" style="background:#fef2f2;color:#ef4444;">
                                    <?php CMMS_Icons::e( 'alert-triangle', 22 ); ?>
                                </span>
                                <span class="cmms-public-asset-action-label">
                                    <strong><?php $this->e( 'public.asset.action_breakdown' ); ?></strong>
                                    <small><?php $this->e( 'public.asset.action_breakdown_sub' ); ?></small>
                                </span>
                            </button>
                        <?php endif; ?>

                        <?php if ( in_array( 'upload_photo', $allowed_actions, true ) ) : ?>
                            <button type="button" class="cmms-public-asset-action" data-cmms-public-asset-action="photo">
                                <span class="cmms-public-asset-action-icon" style="background:#eff6ff;color:#3b82f6;">
                                    <?php CMMS_Icons::e( 'camera', 22 ); ?>
                                </span>
                                <span class="cmms-public-asset-action-label">
                                    <strong><?php $this->e( 'public.asset.action_photo' ); ?></strong>
                                    <small><?php $this->e( 'public.asset.action_photo_sub' ); ?></small>
                                </span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php
                    // Render the "Report breakdown" form, hidden by default,
                    // shown on action click. Keeps the page to one URL.
                    if ( in_array( 'report_breakdown', $allowed_actions, true ) ) :
                    ?>
                    <form method="post" enctype="multipart/form-data"
                          class="cmms-public-asset-form"
                          data-cmms-public-asset-form="breakdown"
                          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          hidden>
                        <input type="hidden" name="action" value="cmms_public_asset_report">
                        <input type="hidden" name="asset_token" value="<?php echo esc_attr( $token ); ?>">
                        <?php wp_nonce_field( 'cmms_public_asset_' . $token, 'cmms_public_asset_nonce' ); ?>

                        <h3 class="cmms-public-asset-form-title"><?php $this->e( 'public.asset.report_title' ); ?></h3>

                        <label class="cmms-public-asset-field">
                            <span class="cmms-public-asset-label"><?php $this->e( 'public.asset.report_what' ); ?> <span class="cmms-required">*</span></span>
                            <textarea name="problem" rows="3" required maxlength="2000" placeholder="<?php echo esc_attr( $this->t( 'public.asset.report_what_ph' ) ); ?>"></textarea>
                        </label>

                        <label class="cmms-public-asset-field">
                            <span class="cmms-public-asset-label"><?php $this->e( 'public.asset.report_priority' ); ?></span>
                            <select name="priority">
                                <option value="normal"><?php $this->e( 'priority.normal' ); ?></option>
                                <option value="high"><?php $this->e( 'priority.high' ); ?></option>
                                <option value="urgent"><?php $this->e( 'priority.urgent' ); ?></option>
                            </select>
                        </label>

                        <label class="cmms-public-asset-field">
                            <span class="cmms-public-asset-label"><?php $this->e( 'public.asset.report_contact' ); ?></span>
                            <input type="text" name="reporter" maxlength="100" placeholder="<?php echo esc_attr( $this->t( 'public.asset.report_contact_ph' ) ); ?>">
                        </label>

                        <?php if ( in_array( 'upload_photo', $allowed_actions, true ) ) : ?>
                        <label class="cmms-public-asset-field">
                            <span class="cmms-public-asset-label"><?php $this->e( 'public.asset.report_photo' ); ?></span>
                            <input type="file" name="photo" accept="image/*">
                        </label>
                        <?php endif; ?>

                        <button type="submit" class="cmms-btn cmms-btn-primary cmms-btn-block cmms-btn-lg">
                            <?php $this->e( 'public.asset.report_submit' ); ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ( in_array( 'upload_photo', $allowed_actions, true ) ) : ?>
                    <form method="post" enctype="multipart/form-data"
                          class="cmms-public-asset-form"
                          data-cmms-public-asset-form="photo"
                          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          hidden>
                        <input type="hidden" name="action" value="cmms_public_asset_photo">
                        <input type="hidden" name="asset_token" value="<?php echo esc_attr( $token ); ?>">
                        <?php wp_nonce_field( 'cmms_public_asset_' . $token, 'cmms_public_asset_nonce' ); ?>

                        <h3 class="cmms-public-asset-form-title"><?php $this->e( 'public.asset.photo_title' ); ?></h3>

                        <label class="cmms-public-asset-field">
                            <span class="cmms-public-asset-label"><?php $this->e( 'public.asset.photo_select' ); ?></span>
                            <input type="file" name="photo" accept="image/*" required>
                        </label>

                        <label class="cmms-public-asset-field">
                            <span class="cmms-public-asset-label"><?php $this->e( 'public.asset.photo_note' ); ?></span>
                            <input type="text" name="note" maxlength="200">
                        </label>

                        <button type="submit" class="cmms-btn cmms-btn-primary cmms-btn-block cmms-btn-lg">
                            <?php $this->e( 'public.asset.photo_submit' ); ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <p class="cmms-public-asset-note">
                        <?php $this->e( 'public.asset.staff_note' ); ?>
                        <a href="<?php echo esc_url( home_url( '/cmms-login/' ) ); ?>" style="white-space:nowrap;"><?php $this->e( 'public.asset.staff_login' ); ?></a>
                    </p>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var actionBtns = document.querySelectorAll('[data-cmms-public-asset-action]');
            var forms = document.querySelectorAll('[data-cmms-public-asset-form]');
            actionBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var which = btn.getAttribute('data-cmms-public-asset-action');
                    forms.forEach(function (f) { f.hidden = (f.getAttribute('data-cmms-public-asset-form') !== which); });
                    actionBtns.forEach(function (b) { b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    // Scroll the visible form into view on mobile
                    var visible = document.querySelector('[data-cmms-public-asset-form="' + which + '"]');
                    if (visible) {
                        setTimeout(function () { visible.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
                    }
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       1.14.40: Subscription gating helpers.

       The dashboard renders a banner for past-due accounts and blocks
       frozen accounts entirely. We read directly from accounts.subscription_status
       which is kept in sync by CMMS_Subscriptions (the subscriptions
       table is authoritative; accounts.subscription_status is the
       mirrored fast-read field).
    ============================================================ */

    private function get_account_subscription_status( $account_id ) {
        if ( ! $account_id ) return '';
        global $wpdb;
        $t = CMMS_DB::table( 'accounts' );
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT subscription_status FROM $t WHERE id = %d",
            (int) $account_id
        ) );
        return (string) $status;
    }

    /**
     * 1.14.49: Returns true if this account has at least one payment
     * row in 'approved' status. Used as a fallback gate when
     * subscription_status hasn't been updated yet (IPN lag).
     */
    private function account_has_approved_payment( $account_id ) {
        if ( ! $account_id ) return false;
        global $wpdb;
        $t = CMMS_DB::table( 'payments' );
        if ( ! $t ) return false;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE account_id = %d AND status = 'approved'",
            (int) $account_id
        ) );
        return $count > 0;
    }

    /**
     * 1.14.49: Block screen for accounts that haven't completed payment.
     *
     * Triggered when a user reaches /cmms-dashboard/ but their account
     * has no approved payment AND no active subscription. The most
     * common cause is hitting the browser back button between signup
     * and the payment step — the WP session is alive, the account row
     * exists, but no money changed hands.
     *
     * The CTA sends them back to /start/?step=3 to complete payment.
     */
    private function render_payment_required_blocker( $u ) {
        $resume_url = home_url( '/start/?step=3' );
        $logout_url = wp_logout_url( home_url( '/' ) );

        return '<div style="min-height:100dvh;display:flex;align-items:center;justify-content:center;background:#f8fafc;font-family:Arial,Heebo,sans-serif;padding:40px 20px;" dir="rtl">'
            . '<div style="max-width:480px;background:#fff;border-radius:16px;box-shadow:0 12px 32px rgba(15,23,42,0.08);padding:40px 32px;text-align:center;">'
            . '<div style="width:64px;height:64px;background:#fff7ed;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">'
            . '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="#ff6a00" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>'
            . '</div>'
            . '<h1 style="font-size:24px;font-weight:700;color:#0f172a;margin:0 0 12px;">השלמת התשלום נדרשת</h1>'
            . '<p style="color:#475569;line-height:1.6;margin:0 0 24px;">החשבון נוצר בהצלחה, אבל התשלום לא הושלם. כדי להיכנס למערכת — יש להשלים את התשלום.</p>'
            . '<a href="' . esc_url( $resume_url ) . '" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#ff6a00 0%,#ff8a3d 100%);color:#fff;text-decoration:none;border-radius:10px;font-weight:600;font-size:16px;">המשך לתשלום</a>'
            . '<p style="margin:24px 0 0;font-size:13px;color:#94a3b8;">לא הלקוח? <a href="' . esc_url( $logout_url ) . '" style="color:#64748b;">התנתק</a></p>'
            . '</div>'
            . '</div>';
    }

    /**
     * Render the blocking screen for frozen accounts. They cannot use
     * the system until they reactivate via payment. A link sends them
     * back to /start at the payment step.
     */
    private function render_frozen_blocker() {
        $login_url = home_url( '/cmms-login/' );
        // Sending them to /start re-uses the payment flow; if they had
        // a plan selected, the AJAX endpoint will re-create a sale.
        $reactivate_url = home_url( '/start/?step=3' );
        return '<div style="min-height:100dvh;display:flex;align-items:center;justify-content:center;background:#f8fafc;font-family:Arial,Heebo,sans-serif;padding:40px 20px;" dir="rtl">'
            . '<div style="max-width:480px;background:#fff;border-radius:16px;box-shadow:0 12px 32px rgba(15,23,42,0.08);padding:40px 32px;text-align:center;">'
            . '<div style="width:64px;height:64px;background:#fef3c7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">'
            . '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="#d97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
            . '</div>'
            . '<h1 style="font-size:24px;font-weight:700;color:#0f172a;margin:0 0 12px;">החשבון מושעה</h1>'
            . '<p style="color:#475569;line-height:1.6;margin:0 0 24px;">החיוב האחרון לא הושלם, ותקופת החסד הסתיימה. כדי להמשיך להשתמש במערכת — יש לעדכן את אמצעי התשלום.</p>'
            . '<a href="' . esc_url( $reactivate_url ) . '" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);color:#fff;text-decoration:none;border-radius:10px;font-weight:600;font-size:16px;">עדכון אמצעי תשלום</a>'
            . '<p style="margin:20px 0 0;font-size:13px;color:#94a3b8;">בעיה? <a href="mailto:support@cmms.co.il" style="color:#4f46e5;">פנה לתמיכה</a></p>'
            . '</div>'
            . '</div>';
    }

    /* ============================================================
       1.14.45: Setup Checklist renderer.

       Renders inline (CSS + minimal JS) so this is a single drop-in
       block — no separate stylesheet file, no extra HTTP requests.
       The CSS is scoped under .cmms-checklist to avoid leaking onto
       the rest of the dashboard.
    ============================================================ */
    private function render_setup_checklist( $u ) {
        $steps = CMMS_Checklist::get_steps( (int) $u->account_id );
        if ( empty( $steps ) ) return;

        $done   = 0;
        $total  = count( $steps );
        foreach ( $steps as $s ) if ( $s['done'] ) $done++;
        $pct    = $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0;

        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'cmms_checklist' );
        ?>
        <div class="cmms-checklist" data-cmms-checklist>
            <div class="cmms-checklist-header">
                <div class="cmms-checklist-headtext">
                    <h2 class="cmms-checklist-title">בואו נתחיל</h2>
                    <p class="cmms-checklist-sub">השלם את ארבעת השלבים האלה כדי להגדיר את המערכת.</p>
                </div>
                <div class="cmms-checklist-progress">
                    <div class="cmms-checklist-progress-text">
                        <strong><?php echo (int) $done; ?></strong> מתוך <?php echo (int) $total; ?>
                    </div>
                    <div class="cmms-checklist-progress-bar">
                        <div class="cmms-checklist-progress-fill" style="width:<?php echo (int) $pct; ?>%;"></div>
                    </div>
                </div>
            </div>

            <ol class="cmms-checklist-steps">
                <?php foreach ( $steps as $i => $step ) :
                    $num = $i + 1;
                    $done_class = $step['done'] ? ' is-done' : '';
                ?>
                <li class="cmms-checklist-step<?php echo $done_class; ?>" data-step="<?php echo esc_attr( $step['key'] ); ?>">
                    <div class="cmms-checklist-step-marker">
                        <?php if ( $step['done'] ) : ?>
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <?php else : ?>
                            <span class="cmms-checklist-step-num"><?php echo (int) $num; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="cmms-checklist-step-body">
                        <div class="cmms-checklist-step-title"><?php echo esc_html( $step['title'] ); ?></div>
                        <div class="cmms-checklist-step-desc"><?php echo esc_html( $step['description'] ); ?></div>
                    </div>
                    <?php if ( ! $step['done'] ) : ?>
                    <div class="cmms-checklist-step-actions">
                        <a href="<?php echo esc_url( $step['cta_url'] ); ?>" class="cmms-checklist-cta">
                            <?php echo esc_html( $step['cta_label'] ); ?>
                        </a>
                        <?php if ( ! empty( $step['manual'] ) ) : ?>
                            <button type="button"
                                    class="cmms-checklist-mark"
                                    data-cmms-checklist-mark="<?php echo esc_attr( $step['key'] ); ?>">
                                סימנתי כבוצע
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>

            <div class="cmms-checklist-footer">
                <button type="button" class="cmms-checklist-dismiss" data-cmms-checklist-dismiss>
                    אל תציג שוב
                </button>
            </div>
        </div>

        <style>
            .cmms-checklist {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                padding: 20px;
                margin: 0 0 20px;
                box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
                font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif;
            }
            .cmms-checklist.is-hiding { opacity: 0; transform: translateY(-6px); transition: all .25s ease; }
            .cmms-checklist-header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 18px;
                flex-wrap: wrap;
            }
            .cmms-checklist-title {
                font-size: 18px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 4px;
            }
            .cmms-checklist-sub {
                font-size: 13px;
                color: #64748b;
                margin: 0;
                line-height: 1.5;
            }
            .cmms-checklist-progress {
                min-width: 130px;
                text-align: left;
            }
            .cmms-checklist-progress-text {
                font-size: 12px;
                color: #475569;
                margin-bottom: 6px;
            }
            .cmms-checklist-progress-text strong {
                color: #ff6a00;
                font-size: 14px;
            }
            .cmms-checklist-progress-bar {
                height: 6px;
                background: #f1f5f9;
                border-radius: 999px;
                overflow: hidden;
            }
            .cmms-checklist-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #ff6a00 0%, #ff8a3d 100%);
                border-radius: 999px;
                transition: width .35s ease;
            }
            .cmms-checklist-steps {
                list-style: none;
                margin: 0;
                padding: 0;
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .cmms-checklist-step {
                display: flex;
                align-items: flex-start;
                gap: 14px;
                padding: 14px;
                border-radius: 10px;
                background: #f8fafc;
                border: 1px solid transparent;
                transition: background .2s ease, border-color .2s ease;
            }
            .cmms-checklist-step.is-done {
                background: #f0fdf4;
                border-color: #dcfce7;
            }
            .cmms-checklist-step-marker {
                flex: 0 0 32px;
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: #ffffff;
                border: 1.5px solid #cbd5e1;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #64748b;
                font-weight: 700;
                font-size: 14px;
            }
            .cmms-checklist-step.is-done .cmms-checklist-step-marker {
                background: #16a34a;
                border-color: #16a34a;
                color: #ffffff;
            }
            .cmms-checklist-step-num { line-height: 1; }
            .cmms-checklist-step-body {
                flex: 1 1 auto;
                min-width: 0;
            }
            .cmms-checklist-step-title {
                font-size: 14px;
                font-weight: 700;
                color: #0f172a;
                margin-bottom: 2px;
            }
            .cmms-checklist-step.is-done .cmms-checklist-step-title {
                color: #166534;
            }
            .cmms-checklist-step-desc {
                font-size: 12.5px;
                color: #64748b;
                line-height: 1.5;
            }
            .cmms-checklist-step-actions {
                flex: 0 0 auto;
                display: flex;
                gap: 6px;
                align-items: center;
                flex-wrap: wrap;
            }
            .cmms-checklist-cta {
                display: inline-block;
                padding: 8px 14px;
                background: #ff6a00;
                color: #ffffff !important;
                font-size: 13px;
                font-weight: 600;
                border-radius: 8px;
                text-decoration: none;
                transition: background .15s ease;
                white-space: nowrap;
            }
            .cmms-checklist-cta:hover { background: #e85d00; }
            .cmms-checklist-mark {
                background: transparent;
                color: #64748b;
                border: 1px solid #cbd5e1;
                padding: 7px 12px;
                font-size: 12px;
                font-weight: 600;
                border-radius: 8px;
                cursor: pointer;
                font-family: inherit;
                white-space: nowrap;
            }
            .cmms-checklist-mark:hover {
                background: #f1f5f9;
                color: #0f172a;
            }
            .cmms-checklist-footer {
                margin-top: 14px;
                text-align: center;
            }
            .cmms-checklist-dismiss {
                background: transparent;
                color: #94a3b8;
                border: 0;
                font-size: 12px;
                cursor: pointer;
                font-family: inherit;
                text-decoration: underline;
                padding: 6px 10px;
            }
            .cmms-checklist-dismiss:hover { color: #64748b; }

            /* Mobile: stack the step into rows. */
            @media (max-width: 640px) {
                .cmms-checklist { padding: 16px; }
                .cmms-checklist-step {
                    flex-wrap: wrap;
                    padding: 12px;
                }
                .cmms-checklist-step-actions {
                    width: 100%;
                    margin-top: 8px;
                    padding-right: 46px; /* align under text, after marker */
                }
                .cmms-checklist-cta { flex: 1 1 auto; text-align: center; }
                .cmms-checklist-progress {
                    width: 100%;
                    text-align: right;
                }
            }
        </style>

        <script>
        (function () {
            var root = document.querySelector('[data-cmms-checklist]');
            if (!root) return;
            var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

            function post(action, extra, onDone) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', nonce);
                if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return null; }); })
                    .then(function (j) { if (onDone) onDone(j && j.success); })
                    .catch(function () { if (onDone) onDone(false); });
            }

            function fadeOutAndRemove() {
                root.classList.add('is-hiding');
                setTimeout(function () { root.remove(); }, 280);
            }

            // "סימנתי כבוצע" buttons (manual steps).
            root.querySelectorAll('[data-cmms-checklist-mark]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var step = btn.getAttribute('data-cmms-checklist-mark');
                    btn.disabled = true;
                    btn.textContent = '...';
                    post('cmms_checklist_mark', { step: step }, function (ok) {
                        if (ok) {
                            // Reload to re-render the step as done. Cheap and
                            // avoids us having to duplicate the marker styling
                            // logic in JS. If all steps are now done, the
                            // checklist won't render at all on reload.
                            location.reload();
                        } else {
                            btn.disabled = false;
                            btn.textContent = 'סימנתי כבוצע';
                        }
                    });
                });
            });

            // "אל תציג שוב" button.
            var dismiss = root.querySelector('[data-cmms-checklist-dismiss]');
            if (dismiss) {
                dismiss.addEventListener('click', function () {
                    dismiss.disabled = true;
                    post('cmms_checklist_dismiss', {}, function (ok) {
                        if (ok) fadeOutAndRemove();
                        else dismiss.disabled = false;
                    });
                });
            }
        })();
        </script>
        <?php
    }
}
