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

    // 1.15.8: Cache of account members for inline-assign dropdowns in
    // the task list. Loaded once per request (lazily) to avoid a query
    // per card. Keyed by account_id.
    private $assign_members_cache = array();

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

        // 1.14.72: read selected user count (seats) from URL. Defaults
        // to the included_seats of the chosen plan when not provided.
        // Bounded by [included, hard_user_limit] for managed plans.
        $included_for_plan = CMMS_Plans::included_seats( $selected_plan, $selected_cycle );
        $hard_for_plan     = CMMS_Plans::hard_user_limit( $selected_plan, $selected_cycle );
        $selected_users    = isset( $_GET['users'] ) ? (int) $_GET['users'] : 0;
        if ( $selected_users <= 0 ) {
            $selected_users = ( $included_for_plan !== null ) ? $included_for_plan : 1;
        }
        if ( $included_for_plan !== null && $selected_users < $included_for_plan ) {
            $selected_users = $included_for_plan;
        }
        if ( $hard_for_plan !== null && $selected_users > $hard_for_plan ) {
            $selected_users = $hard_for_plan;
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

/* ─────────────────────────────────────────────────────────────────
   1.14.72 — Seat Selector
   Each managed plan card has its own selector. Lives between the
   price block and the features list. Mobile-first.
───────────────────────────────────────────────────────────────── */
.cmms-onb-seats {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    margin: 0 0 18px;
}
.cmms-onb-seats-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.cmms-onb-seats-control {
    display: flex;
    align-items: stretch;
    gap: 0;
    background: #fff;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    overflow: hidden;
    height: 42px;
}
.cmms-onb-seats-btn {
    flex: none;
    width: 40px;
    background: #fff;
    border: 0;
    color: #475569;
    font-size: 20px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease;
    line-height: 1;
    padding: 0;
}
.cmms-onb-seats-btn:hover:not(:disabled) {
    background: #f1f5f9;
    color: #0b1c33;
}
.cmms-onb-seats-btn:active:not(:disabled) {
    background: #e2e8f0;
}
.cmms-onb-seats-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}
.cmms-onb-seats-input {
    flex: 1 1 auto;
    width: 100%;
    border: 0;
    border-inline: 1.5px solid #e2e8f0;
    text-align: center;
    font-size: 17px;
    font-weight: 700;
    color: #0b1c33;
    background: #fff;
    padding: 0;
    -moz-appearance: textfield;
    appearance: textfield;
}
.cmms-onb-seats-input::-webkit-outer-spin-button,
.cmms-onb-seats-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.cmms-onb-seats-input:focus {
    outline: 0;
    background: #fffbf5;
}
.cmms-onb-seats-hint {
    margin-top: 8px;
    font-size: 12px;
    color: #64748b;
    line-height: 1.5;
}
.cmms-onb-seats-recommendation {
    margin-top: 8px;
    padding: 8px 10px;
    background: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 6px;
    color: #78350f;
    font-size: 13px;
    line-height: 1.4;
    font-weight: 500;
}
.cmms-onb-seats-recommendation[hidden] { display: none; }
.cmms-onb-seats-warning {
    margin-top: 8px;
    padding: 8px 10px;
    background: #fee2e2;
    border: 1px solid #fca5a5;
    border-radius: 6px;
    color: #7f1d1d;
    font-size: 13px;
    line-height: 1.4;
    font-weight: 500;
}
.cmms-onb-seats-warning[hidden] { display: none; }

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

                // 1.14.72: pull seat config directly from package row.
                // Enterprise / custom packages return null — those keep
                // the simple "contact us" flow (no seat selector).
                $plan_pkg          = CMMS_Plans::get_package( $plan_id, $selected_cycle );
                $plan_included     = $plan_pkg ? $plan_pkg['included_seats']           : null;
                $plan_seat_price   = $plan_pkg ? $plan_pkg['seat_addon_price']         : null;
                $plan_hard_limit   = $plan_pkg ? $plan_pkg['hard_user_limit']          : null;
                $plan_recommend_at = $plan_pkg ? $plan_pkg['upgrade_recommended_at']   : null;
                $plan_base_price   = $plan_pkg ? (float) $plan_pkg['price']            : (float) $plan['price_monthly'];
                $supports_seats    = ( $plan_included !== null && $plan_seat_price !== null );

                // Default user count for THIS card: included for the plan
                // (so each card shows its included default until adjusted).
                $card_users = $plan_included !== null ? $plan_included : 1;

                // For the URL link of the CTA — if user already chose a
                // value for this plan (e.g. came back from step 2), reflect
                // it here so the link respects it.
                if ( $plan_id === $selected_plan && $supports_seats ) {
                    $card_users = $selected_users;
                }

                $next_url = add_query_arg( array(
                    'step'  => 2,
                    'plan'  => $plan_id,
                    'cycle' => $selected_cycle,
                    'users' => $card_users,
                ), home_url( '/start/' ) );
            ?>
                <article class="cmms-onb-plan <?php echo $is_recommended ? 'is-recommended' : ''; ?> <?php echo $is_selected ? 'is-selected' : ''; ?> <?php echo $selected_cycle === 'yearly' ? 'is-cycle-yearly' : ''; ?>"
                         data-plan="<?php echo esc_attr( $plan_id ); ?>"
                         data-included="<?php echo esc_attr( $plan_included !== null ? $plan_included : '' ); ?>"
                         data-seat-price="<?php echo esc_attr( $plan_seat_price !== null ? $plan_seat_price : '' ); ?>"
                         data-hard-limit="<?php echo esc_attr( $plan_hard_limit !== null ? $plan_hard_limit : '' ); ?>"
                         data-recommend-at="<?php echo esc_attr( $plan_recommend_at !== null ? $plan_recommend_at : '' ); ?>"
                         data-base-monthly="<?php echo esc_attr( $plan['price_monthly'] ); ?>"
                         data-base-yearly="<?php echo esc_attr( $plan['price_yearly'] ); ?>">
                    <?php if ( $is_recommended ) : ?>
                        <div class="cmms-onb-plan-badge">המומלץ</div>
                    <?php endif; ?>
                    <div class="cmms-onb-plan-head">
                        <h3 class="cmms-onb-plan-name"><?php echo esc_html( $plan['name'] ); ?></h3>
                        <p class="cmms-onb-plan-tagline"><?php echo esc_html( $plan['tagline'] ); ?></p>
                    </div>

                    <!-- Price block: two prices, only one visible at a time per cycle.
                         1.14.72: the .cmms-onb-price-amount is now data-driven so
                         the live calculator can update it as the user adjusts seats. -->
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
                                <span class="cmms-onb-price-amount" data-base-amount="<?php echo esc_attr( $plan['price_monthly'] ); ?>">₪<?php echo number_format( $plan['price_monthly'] ); ?></span>
                                <span class="cmms-onb-price-suffix">/ חודש</span>
                            </div>
                            <div class="cmms-onb-price-line" data-price-for="yearly">
                                <span class="cmms-onb-price-amount" data-base-amount="<?php echo esc_attr( $plan['price_yearly'] ); ?>">₪<?php echo number_format( $plan['price_yearly'] ); ?></span>
                                <span class="cmms-onb-price-suffix">/ שנה</span>
                                <div class="cmms-onb-price-per-month">≈ ₪<span data-permonth><?php echo number_format( CMMS_Plans::yearly_per_month( $plan_id ) ); ?></span> לחודש</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php // ────────────────────────────────────────
                          // 1.14.72 — Seat selector for managed plans
                          //
                          // Custom-priced plans (Enterprise) don't get
                          // the selector — they fall through to "contact us".
                          // ────────────────────────────────────────
                          if ( $supports_seats && ! $plan['custom_price'] ) : ?>
                    <div class="cmms-onb-seats">
                        <label class="cmms-onb-seats-label" for="seats-<?php echo esc_attr( $plan_id ); ?>">מספר משתמשים</label>
                        <div class="cmms-onb-seats-control">
                            <button type="button" class="cmms-onb-seats-btn" data-seats-action="dec" aria-label="הפחת">−</button>
                            <input type="number"
                                   class="cmms-onb-seats-input"
                                   id="seats-<?php echo esc_attr( $plan_id ); ?>"
                                   data-seats-input
                                   min="<?php echo (int) $plan_included; ?>"
                                   max="<?php echo $plan_hard_limit !== null ? (int) $plan_hard_limit : ''; ?>"
                                   value="<?php echo (int) $card_users; ?>"
                                   inputmode="numeric">
                            <button type="button" class="cmms-onb-seats-btn" data-seats-action="inc" aria-label="הוסף">+</button>
                        </div>
                        <div class="cmms-onb-seats-hint">
                            <span class="cmms-onb-seats-included-hint">
                                כולל <?php echo (int) $plan_included; ?> משתמשים. כל משתמש נוסף — ₪<?php echo number_format( (float) $plan_seat_price, 0 ); ?> <?php echo $selected_cycle === 'yearly' ? '/ שנה' : '/ חודש'; ?>
                            </span>
                        </div>
                        <div class="cmms-onb-seats-recommendation" data-seats-recommendation hidden>
                            <span data-seats-rec-text></span>
                        </div>
                        <div class="cmms-onb-seats-warning" data-seats-warning hidden>
                            <span data-seats-warn-text></span>
                        </div>
                    </div>
                    <?php endif; ?>

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
             for and has a clear way to change their mind.
             1.14.72: now shows the total price with seats and the seat
             breakdown — so user clearly sees the price they'll pay. -->
        <?php if ( $current_plan ) :
            $cycle_label = $selected_cycle === 'yearly' ? 'שנתי' : 'חודשי';

            // 1.14.72: compute total price including extra seats.
            $summary_total = CMMS_Plans::calculate_total_price( $selected_plan, $selected_cycle, $selected_users );
            $summary_included = CMMS_Plans::included_seats( $selected_plan, $selected_cycle );
            $extra_seats = ( $summary_included !== null ) ? max( 0, $selected_users - $summary_included ) : 0;

            $price_display = $current_plan['custom_price']
                ? 'מחיר מותאם'
                : ( $selected_cycle === 'yearly'
                    ? '₪' . number_format( $summary_total ) . ' / שנה'
                    : '₪' . number_format( $summary_total ) . ' / חודש' );

            $change_url = add_query_arg( array(
                'step'  => 1,
                'plan'  => $selected_plan,
                'cycle' => $selected_cycle,
                'users' => $selected_users,
            ), home_url( '/start/' ) );
        ?>
        <div class="cmms-onb-plan-summary">
            <div class="cmms-onb-plan-summary-info">
                <span class="cmms-onb-plan-summary-name"><?php echo esc_html( $current_plan['name'] ); ?></span>
                <span class="cmms-onb-plan-summary-sep">·</span>
                <span class="cmms-onb-plan-summary-cycle"><?php echo esc_html( $cycle_label ); ?></span>
                <?php if ( $summary_included !== null ) : ?>
                <span class="cmms-onb-plan-summary-sep">·</span>
                <span class="cmms-onb-plan-summary-users">
                    <?php echo (int) $selected_users; ?> משתמשים<?php if ( $extra_seats > 0 ) : ?>
                        <span style="color:#94a3b8;font-size:12px;">(<?php echo (int) $summary_included; ?> כלולים + <?php echo (int) $extra_seats; ?> נוספים)</span>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
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

                <!-- 1.14.72: chosen user count (seats). Carries the seat
                     selector value from step 1. AJAX step1 saves it into
                     the subscription as seats_purchased = max(0, users - included). -->
                <input type="hidden" name="users" id="onb-users" value="<?php echo esc_attr( $selected_users ); ?>">

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
                     browser is redirected to the iCredit hosted page.

                     1.14.72: shows the seat breakdown if applicable.
                     IMPORTANT: the amount sent to iCredit is still
                     the base price — Phase 2b will wire dynamic amount.
                     For now, Super Admin handles the extra seats manually
                     after payment. -->
                <?php
                // 1.14.72 — compute display values for the summary card.
                $pkg_included    = isset( $payment_package['included_seats'] )   ? $payment_package['included_seats']   : null;
                $pkg_seat_price  = isset( $payment_package['seat_addon_price'] ) ? $payment_package['seat_addon_price'] : null;
                $payment_supports_seats = ( $pkg_included !== null && $pkg_seat_price !== null );
                $payment_extra_seats    = $payment_supports_seats ? max( 0, $selected_users - $pkg_included ) : 0;
                $payment_base_price     = (float) $payment_package['price'];
                $payment_extras_amount  = $payment_extra_seats * (float) ( $pkg_seat_price ?: 0 );
                $payment_total_display  = $payment_base_price + $payment_extras_amount;
                ?>
                <div class="cmms-onb-payment-summary">
                    <div class="cmms-onb-payment-summary-line">
                        <span>חבילה</span>
                        <strong><?php echo esc_html( $payment_package['display_name'] ); ?></strong>
                    </div>
                    <div class="cmms-onb-payment-summary-line">
                        <span>מחזור חיוב</span>
                        <strong><?php echo esc_html( $cycle_label ); ?></strong>
                    </div>
                    <?php if ( $payment_supports_seats ) : ?>
                    <div class="cmms-onb-payment-summary-line">
                        <span>משתמשים</span>
                        <strong>
                            <?php echo (int) $selected_users; ?>
                            <?php if ( $payment_extra_seats > 0 ) : ?>
                                <span style="font-weight:normal;color:#64748b;font-size:13px;">
                                    (<?php echo (int) $pkg_included; ?> כלולים + <?php echo (int) $payment_extra_seats; ?> נוספים)
                                </span>
                            <?php endif; ?>
                        </strong>
                    </div>
                    <?php elseif ( ! empty( $payment_package['max_users'] ) ) : ?>
                    <div class="cmms-onb-payment-summary-line">
                        <span>משתמשים בחבילה</span>
                        <strong>עד <?php echo (int) $payment_package['max_users']; ?></strong>
                    </div>
                    <?php endif; ?>

                    <?php if ( $payment_extra_seats > 0 ) : ?>
                    <div class="cmms-onb-payment-summary-line">
                        <span>תוספת משתמשים</span>
                        <strong>₪<?php echo number_format( $payment_extras_amount ); ?></strong>
                    </div>
                    <div class="cmms-onb-payment-summary-line">
                        <span style="color:#64748b;font-size:13px;">חבילת בסיס</span>
                        <strong style="color:#64748b;font-size:13px;">₪<?php echo number_format( $payment_base_price ); ?></strong>
                    </div>
                    <?php endif; ?>

                    <div class="cmms-onb-payment-summary-line cmms-onb-payment-summary-total">
                        <span>סכום לתשלום</span>
                        <strong><?php echo '₪' . number_format( $payment_total_display ); ?></strong>
                    </div>
                </div>
                    </div>
                </div>

                <form id="cmms-onb-payment-form" novalidate>
                    <input type="hidden" name="cmms_payment_nonce" value="<?php echo esc_attr( $payment_nonce ); ?>">
                    <input type="hidden" name="plan" value="<?php echo esc_attr( $selected_plan ); ?>">
                    <input type="hidden" name="cycle" value="<?php echo esc_attr( $selected_cycle ); ?>">
                    <!-- 1.14.73: pass the chosen seat count so iCredit
                         gets billed for base + extras. The hidden field
                         is populated from $selected_users which was
                         clamped from URL/sessionStorage at page load. -->
                    <input type="hidden" name="users" value="<?php echo esc_attr( $selected_users ); ?>">

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
        // 1.14.72: carry the chosen user count (seats). Without this,
        // the server would default to the package's included_seats and
        // ignore the user's seat selection from step 1.
        body.set('users', data.get('users') || '');

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
   Pricing step JS (1.14.27 → 1.14.72)
   Only attaches when we're on step 1 (Pricing). Handles:
     - Billing cycle toggle (monthly / yearly) — switches visible prices
       in every plan card without a server round-trip.
     - Persisting the selected cycle to sessionStorage so a refresh
       restores the user's choice.
     - Pre-populating the cycle from sessionStorage if the URL doesn't
       carry it.
     - Updating the CTAs so clicking "Continue with X" lands on
       step 2 with the right ?cycle= URL param.

   1.14.72 additions:
     - Seat selector per managed plan card. Live price calculation
       based on (included_seats, seat_addon_price) attached to the
       card via data- attributes. Soft recommendation (upgrade_recommended_at)
       and hard ceiling (hard_user_limit) warnings.
     - CTA href carries ?users=N so step 2 / step 3 know the count.
     - sessionStorage stores users per plan, so users see their previous
       choice when they come back from step 2.

   No iCredit changes here — Phase 2b will handle dynamic amount
   submission to iCredit. For now, the displayed price is informational.
   ============================================================ */
(function () {
    'use strict';
    var STORAGE_KEY = 'cmms_onb_state';

    // Only run on step 1 (pricing). Detected via presence of .cmms-onb-plans.
    var plansEl = document.querySelector('.cmms-onb-plans');
    if (!plansEl) return;

    var cycleButtons = document.querySelectorAll('.cmms-onb-cycle-btn');
    var planCTAs = document.querySelectorAll('[data-plan-cta]');
    var planCards = document.querySelectorAll('.cmms-onb-plan');
    var priceLines = document.querySelectorAll('.cmms-onb-price-line');

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

    /* ────────────────────────────────────────────────────────────
       1.14.72 — Seat selector logic per card
       ──────────────────────────────────────────────────────────── */

    // Read seat config from card data- attributes (set server-side from
    // the package row). Missing values mean "unmanaged" (Enterprise).
    function cardConfig(card) {
        var inc  = parseInt(card.getAttribute('data-included'),     10);
        var sp   = parseFloat(card.getAttribute('data-seat-price'));
        var hard = parseInt(card.getAttribute('data-hard-limit'),   10);
        var rec  = parseInt(card.getAttribute('data-recommend-at'), 10);
        return {
            included:    isNaN(inc) ? null : inc,
            seatPrice:   isNaN(sp)  ? null : sp,
            hardLimit:   isNaN(hard) ? null : hard,
            recommendAt: isNaN(rec)  ? null : rec,
            baseMonthly: parseFloat(card.getAttribute('data-base-monthly')) || 0,
            baseYearly:  parseFloat(card.getAttribute('data-base-yearly'))  || 0,
            planId:      card.getAttribute('data-plan')
        };
    }

    // Clamp the input value to [included, hard_limit] (or [1, ∞] if no caps).
    function clampSeats(card, raw) {
        var c = cardConfig(card);
        var v = parseInt(raw, 10);
        if (isNaN(v) || v < 1) v = c.included !== null ? c.included : 1;
        if (c.included !== null && v < c.included) v = c.included;
        if (c.hardLimit !== null && v > c.hardLimit) v = c.hardLimit;
        return v;
    }

    // Hebrew number formatter (no fractions).
    function fmtPrice(n) {
        try {
            return '₪' + Math.round(n).toLocaleString('he-IL');
        } catch (e) {
            return '₪' + Math.round(n);
        }
    }

    // Update visible price, ≈ per-month line, recommendation, and warning.
    function updateCardDisplay(card) {
        var c = cardConfig(card);
        var input = card.querySelector('[data-seats-input]');
        if (!input) return; // unmanaged card — skip

        var users = clampSeats(card, input.value);
        if (String(users) !== input.value) input.value = users;

        var cycle = getActiveCycle();
        var base  = (cycle === 'yearly') ? c.baseYearly : c.baseMonthly;
        var extras = c.included !== null ? Math.max(0, users - c.included) : 0;
        var total  = base + (extras * (c.seatPrice || 0));

        // Update price amounts (both monthly and yearly lines on this card).
        card.querySelectorAll('[data-base-amount]').forEach(function (el) {
            // Decide which cycle this element represents.
            var line = el.closest('.cmms-onb-price-line');
            var lineCycle = line ? line.getAttribute('data-price-for') : 'monthly';
            var lineBase  = (lineCycle === 'yearly') ? c.baseYearly : c.baseMonthly;
            var lineTotal = lineBase + (extras * (c.seatPrice || 0));
            el.textContent = fmtPrice(lineTotal);
        });
        // Update ≈ /month line on the yearly card.
        var permonth = card.querySelector('[data-permonth]');
        if (permonth && c.baseYearly > 0) {
            var yearlyTotal = c.baseYearly + (extras * (c.seatPrice || 0));
            permonth.textContent = Math.round(yearlyTotal / 12).toLocaleString('he-IL');
        }

        // Update −/+ button disabled state.
        var dec = card.querySelector('[data-seats-action="dec"]');
        var inc = card.querySelector('[data-seats-action="inc"]');
        if (dec) dec.disabled = ( c.included !== null && users <= c.included );
        if (inc) inc.disabled = ( c.hardLimit !== null && users >= c.hardLimit );

        // Recommendation: show when user count >= (recommendAt% of hardLimit).
        var recEl  = card.querySelector('[data-seats-recommendation]');
        var recTxt = card.querySelector('[data-seats-rec-text]');
        if (recEl && recTxt && c.hardLimit !== null && c.recommendAt) {
            var threshold = Math.ceil(c.hardLimit * (c.recommendAt / 100));
            if (users >= threshold && users < c.hardLimit) {
                var nextLabel = (c.planId === 'starter') ? 'Business' : 'Enterprise';
                recTxt.textContent = '💡 בכמות כזו של משתמשים מומלץ לעבור לחבילת ' + nextLabel + ' — חסכון משמעותי.';
                recEl.hidden = false;
            } else {
                recEl.hidden = true;
            }
        }

        // Hard-limit warning: show when at the ceiling.
        var warnEl  = card.querySelector('[data-seats-warning]');
        var warnTxt = card.querySelector('[data-seats-warn-text]');
        if (warnEl && warnTxt && c.hardLimit !== null) {
            if (users >= c.hardLimit) {
                warnTxt.textContent = '⚠ הגעת לגג המשתמשים של חבילה זו. למספרים גבוהים יותר נדרשת חבילה גדולה יותר.';
                warnEl.hidden = false;
            } else {
                warnEl.hidden = true;
            }
        }

        // Update CTA href with chosen users.
        var cta = card.querySelector('[data-plan-cta]');
        if (cta) {
            try {
                var href = cta.getAttribute('href');
                var url  = new URL(href, window.location.origin);
                url.searchParams.set('users', users);
                url.searchParams.set('cycle', cycle);
                cta.setAttribute('href', url.toString());
            } catch (e) {}
        }

        // Persist per-plan seats so user keeps their choice on back/forward.
        var st = loadState();
        if (!st.users) st.users = {};
        st.users[c.planId] = users;
        saveState({ users: st.users });
    }

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

        // 1.14.72: after cycle switch, recompute every managed card's
        // dynamic price for the new cycle.
        planCards.forEach(function (card) {
            if (card.querySelector('[data-seats-input]')) updateCardDisplay(card);
        });
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
            applyCycle(cycle);
        });
    });

    // 1.14.72 — wire up seat selectors per card.
    planCards.forEach(function (card) {
        var input = card.querySelector('[data-seats-input]');
        if (!input) return; // unmanaged card

        // Restore previous user-chosen value from sessionStorage if available.
        try {
            var s = loadState();
            if (s.users && s.users[card.getAttribute('data-plan')]) {
                input.value = s.users[card.getAttribute('data-plan')];
            }
        } catch (e) {}

        // -/+ buttons
        card.querySelectorAll('[data-seats-action]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var action = btn.getAttribute('data-seats-action');
                var current = parseInt(input.value, 10) || 0;
                input.value = (action === 'inc') ? (current + 1) : (current - 1);
                updateCardDisplay(card);
            });
        });

        // Direct input changes
        input.addEventListener('input', function () { updateCardDisplay(card); });
        input.addEventListener('change', function () { updateCardDisplay(card); });

        // Initial render to apply clamp + price.
        updateCardDisplay(card);
    });

    // Save chosen plan when user clicks a CTA — backup for step 2 in
    // case URL params get lost. 1.14.72: also save the seat count.
    planCTAs.forEach(function (cta) {
        cta.addEventListener('click', function () {
            var card = cta.closest('.cmms-onb-plan');
            var input = card ? card.querySelector('[data-seats-input]') : null;
            var users = input ? parseInt(input.value, 10) : 0;

            var patch = {
                plan:  cta.getAttribute('data-plan-cta'),
                cycle: getActiveCycle(),
            };
            if (users > 0) {
                var st = loadState();
                st.users = st.users || {};
                st.users[patch.plan] = users;
                patch.users = st.users;
                patch.lastUsers = users;
            }
            saveState(patch);
        });
    });
})();

/* ============================================================
   Step 2 hydration from sessionStorage (1.14.27 → 1.14.72)
   If the user lands on step 2 without plan/cycle in URL but has them
   in sessionStorage, refresh the URL silently so all server-rendered
   summary/CTAs reflect the correct state. This handles the case where
   someone bookmarks /start?step=2 or copies a URL without the params.

   1.14.72 additions:
     - If URL has plan+cycle but is missing 'users', AND sessionStorage
       has a saved user count for that plan, reload with the right
       'users' so the summary card and hidden field are correct.
     - Also keep the #onb-users hidden field synced with what we know,
       as a final safety net for AJAX submission.
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
        var hasUrlUsers = urlParams.get('users');

        // 1.14.72: if URL is missing 'users' but we have a recorded
        // selection in sessionStorage for the current plan, reload
        // once with the correct value. The same hydration guard
        // prevents loops.
        if (hasUrlPlan && !hasUrlUsers && st.users && st.users[hasUrlPlan]) {
            if (!sessionStorage.getItem('cmms_onb_hydrated_users')) {
                sessionStorage.setItem('cmms_onb_hydrated_users', '1');
                urlParams.set('users', String(st.users[hasUrlPlan]));
                window.location.replace(window.location.pathname + '?' + urlParams.toString());
                return;
            }
        }

        if (!hasUrlPlan && st.plan) {
            // Reload with the proper params so server-side rendering
            // shows the right summary card. One-shot guarded by sessionStorage
            // flag to avoid loops.
            if (!sessionStorage.getItem('cmms_onb_hydrated')) {
                sessionStorage.setItem('cmms_onb_hydrated', '1');
                urlParams.set('plan', st.plan);
                if (st.cycle) urlParams.set('cycle', st.cycle);
                // 1.14.72: also carry users if available.
                if (st.users && st.users[st.plan]) urlParams.set('users', String(st.users[st.plan]));
                window.location.replace(window.location.pathname + '?' + urlParams.toString());
                return;
            }
        }

        // Got here — either URL has params or no useful sessionStorage.
        // Clear the hydration guards for future visits.
        sessionStorage.removeItem('cmms_onb_hydrated');
        sessionStorage.removeItem('cmms_onb_hydrated_users');

        // 1.14.72: belt-and-braces — make sure the #onb-users hidden
        // field reflects the URL or sessionStorage value. The server
        // already set it from $selected_users, but if we just reloaded
        // with the corrected param, this catches any race.
        var hiddenUsers = document.getElementById('onb-users');
        if (hiddenUsers && hasUrlPlan) {
            var resolvedUsers = urlParams.get('users')
                || (st.users && st.users[hasUrlPlan])
                || hiddenUsers.value;
            if (resolvedUsers) hiddenUsers.value = String(resolvedUsers);
        }
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
        // 1.14.73: carry the chosen seat count so iCredit gets the
        // correct total amount (base + extras × seat price).
        body.set('users', data.get('users') || '');

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
        // 1.14.81.3: Demo accounts skip the payment gate entirely.
        // These accounts are seeded by CMMS_Demo::create() for prospect
        // demos — they never go through onboarding/payment, so they'd
        // otherwise be blocked here. We treat 'demo' as a fully active
        // first-class status. To convert a demo to a real subscription,
        // simply update the column to 'active' (and add a real
        // subscription row).
        if ( $sub_status === 'demo' ) {
            // fall through to normal dashboard rendering — no gate
        }
        // 1.14.56: canceled_pending = user clicked Cancel. Allow access
        // until next_charge_at has passed, then revoke. Check via
        // the subscriptions table.
        elseif ( $sub_status === 'canceled_pending' ) {
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
            // 1.14.81.1: Calendar item — points to the tasks page in
            // calendar display mode. Uses 'extra' so the URL becomes
            // ?view=tasks&display=calendar instead of view=calendar
            // (which would 404). The 'highlight' key tells the nav
            // renderer when to mark this item active (when both
            // view=tasks AND display=calendar are present).
            array( 'view' => 'tasks',    'icon' => 'calendar', 'label' => 'nav.calendar',
                   'extra' => array( 'display' => 'calendar' ),
                   'highlight' => 'calendar' ),
            array( 'view' => 'task_new', 'icon' => 'plus-circle', 'label' => 'nav.new_task' ),
            array( 'view' => 'assets',   'icon' => 'package', 'label' => 'nav.assets' ),
        );
        $nav_items_manage = array(
            array( 'view' => 'reports',    'icon' => 'bar-chart', 'label' => 'nav.reports' ),
            array( 'view' => 'forms',      'icon' => 'clipboard', 'label' => 'nav.forms' ),
            // 1.15.5: Email-to-task inbound log. Sits next to Forms
            // since each form has its own inbound address. Shows all
            // incoming emails across the account in one place.
            array( 'view' => 'email_log',  'icon' => 'mail',      'label' => 'nav.email_log' ),
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
            "SELECT COUNT(*) FROM $t_tasks WHERE account_id = %d AND status IN ('open','in_progress','waiting') AND deleted_at IS NULL",
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
                    <?php
                    // 1.14.81.1: Active-state for sidebar items is now
                    // computed per-item. Two items can share view='tasks'
                    // (Tasks and Calendar) and we differentiate using
                    // the URL's `display` param.
                    $current_display = isset( $_GET['display'] ) ? sanitize_key( wp_unslash( $_GET['display'] ) ) : '';
                    foreach ( $nav_items_work as $item ) :
                        $item_args = array( 'view' => $item['view'] );
                        if ( ! empty( $item['extra'] ) && is_array( $item['extra'] ) ) {
                            $item_args = array_merge( $item_args, $item['extra'] );
                        }
                        // Active state:
                        //   - For items with 'highlight', active when
                        //     view matches AND display matches highlight.
                        //   - For plain tasks item, active when view=tasks
                        //     AND display is NOT 'calendar' (otherwise it
                        //     would highlight both tasks and calendar).
                        //   - For all other items, just match view.
                        if ( ! empty( $item['highlight'] ) ) {
                            $is_active = ( $view === $item['view'] && $current_display === $item['highlight'] );
                        } else if ( $item['view'] === 'tasks' ) {
                            $is_active = ( $view === 'tasks' && $current_display !== 'calendar' );
                        } else {
                            $is_active = ( $view === $item['view'] );
                        }
                    ?>
                        <a href="<?php echo esc_url( $this->url( $item_args ) ); ?>"
                           class="<?php echo $is_active ? 'active' : ''; ?>">
                            <?php CMMS_Icons::e( $item['icon'], 18 ); ?>
                            <span><?php $this->e( $item['label'] ); ?></span>
                            <?php if ( $item['view'] === 'tasks' && empty( $item['extra'] ) && $open_count > 0 ) : ?>
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
                    case 'email_log': $this->view_email_log( $u ); break;
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

        <?php
        // 1.14.88: Floating Help Assistant ("יועץ") — chatbot-style
        // help widget. Replaces the old simple per-page help (1.14.22).
        // The widget shows the user a menu of FAQ categories →
        // questions → step-by-step answers with "take me there" links.
        // All content from CMMS_Help_Center::topics().
        //
        // Permission-aware: if a topic is for a role the user doesn't
        // have, the "take me there" button becomes a polite "this
        // action requires X permission" message instead of navigating.
        $help_topics      = CMMS_Help_Center::topics();
        $help_categories  = CMMS_Help_Center::categories();
        $help_user_role   = $u->role;
        ?>
        <button type="button"
                class="cmms-assistant-fab"
                data-cmms-assistant-trigger
                aria-label="יועץ העזרה"
                title="פתח יועץ העזרה">
            <span aria-hidden="true">💬</span>
        </button>

        <div class="cmms-assistant-panel" data-cmms-assistant-panel hidden role="dialog" aria-modal="false" aria-labelledby="cmms-assistant-title">
            <header class="cmms-assistant-head">
                <div class="cmms-assistant-head-info">
                    <div class="cmms-assistant-avatar">💬</div>
                    <div class="cmms-assistant-headline">
                        <div id="cmms-assistant-title" class="cmms-assistant-title">היועץ של CMMS</div>
                        <div class="cmms-assistant-subtitle">מענה מיידי על שאלות נפוצות</div>
                    </div>
                </div>
                <button type="button" class="cmms-assistant-close" data-cmms-assistant-close aria-label="סגור">×</button>
            </header>

            <div class="cmms-assistant-body" data-cmms-assistant-body>
                <!-- Conversation messages render here. Built by JS. -->
            </div>

            <footer class="cmms-assistant-foot">
                <button type="button"
                        class="cmms-assistant-foot-btn"
                        data-cmms-assistant-reset
                        title="התחל מחדש">
                    🏠 חזור לתפריט הראשי
                </button>
            </footer>
        </div>

        <script>
        (function () {
            'use strict';
            // ─── Data injected from PHP ─────────────────────────────
            var TOPICS     = <?php echo wp_json_encode( $help_topics ); ?>;
            var CATEGORIES = <?php echo wp_json_encode( $help_categories ); ?>;
            var USER_ROLE  = <?php echo wp_json_encode( $help_user_role ); ?>;
            var DASHBOARD_URL = <?php echo wp_json_encode( $this->url( array() ) ); ?>;

            // ─── Elements ────────────────────────────────────────────
            var fab    = document.querySelector('[data-cmms-assistant-trigger]');
            var panel  = document.querySelector('[data-cmms-assistant-panel]');
            var body   = document.querySelector('[data-cmms-assistant-body]');
            var btnClose = document.querySelector('[data-cmms-assistant-close]');
            var btnReset = document.querySelector('[data-cmms-assistant-reset]');

            if (!fab || !panel || !body) return;

            // ─── Open/close ─────────────────────────────────────────
            function open()  { panel.hidden = false; renderMenu(); }
            function close() { panel.hidden = true; }
            fab.addEventListener('click', open);

            // Robust close: any element marked data-cmms-assistant-close
            // closes the panel (the X button is one such element).
            // Using event delegation in case DOM is rebuilt.
            panel.addEventListener('click', function (e) {
                var t = e.target;
                while (t && t !== panel) {
                    if (t.hasAttribute && t.hasAttribute('data-cmms-assistant-close')) {
                        close();
                        return;
                    }
                    t = t.parentNode;
                }
            });

            // ESC key closes the panel.
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !panel.hidden) close();
            });

            // Reset button.
            if (btnReset) {
                btnReset.addEventListener('click', renderMenu);
            }

            // ─── Helpers ────────────────────────────────────────────
            function el(tag, cls, html) {
                var e = document.createElement(tag);
                if (cls) e.className = cls;
                if (html !== undefined) e.innerHTML = html;
                return e;
            }

            function botMessage(html) {
                var m = el('div', 'cmms-assist-msg cmms-assist-msg-bot');
                m.appendChild(el('div', 'cmms-assist-msg-avatar', '💬'));
                m.appendChild(el('div', 'cmms-assist-msg-bubble', html));
                return m;
            }

            function buttonRow(buttons) {
                var row = el('div', 'cmms-assist-buttons');
                buttons.forEach(function (b) {
                    var btn = el('button', 'cmms-assist-btn', b.html);
                    btn.type = 'button';
                    btn.addEventListener('click', b.onClick);
                    row.appendChild(btn);
                });
                return row;
            }

            function scrollToBottom() {
                body.scrollTop = body.scrollHeight;
            }

            function userCanAccess(topic) {
                if (!topic.required_role || !topic.required_role.length) return true;
                return topic.required_role.indexOf(USER_ROLE) !== -1;
            }

            function roleLabel(role) {
                var labels = { owner: 'בעלים', manager: 'מנהל', technician: 'טכנאי', reporter: 'מדווח' };
                return labels[role] || role;
            }

            // ─── VIEW: main menu ────────────────────────────────────
            function renderMenu() {
                body.innerHTML = '';

                body.appendChild(botMessage(
                    '<p>שלום! 👋</p>' +
                    '<p>אני כאן לעזור לך. בחר נושא לקבלת הסבר מהיר:</p>'
                ));

                // Build category buttons, filtering out empties
                var catButtons = [];
                Object.keys(CATEGORIES).forEach(function (catKey) {
                    var cat = CATEGORIES[catKey];
                    // Has any topics in this category?
                    var topicsInCat = TOPICS.filter(function (t) { return t.category === catKey; });
                    if (!topicsInCat.length) return;
                    catButtons.push({
                        html: cat.icon + ' ' + cat.label,
                        onClick: function () { renderCategory(catKey); }
                    });
                });

                body.appendChild(buttonRow(catButtons));
                scrollToBottom();
            }

            // ─── VIEW: questions in a category ─────────────────────
            function renderCategory(catKey) {
                body.innerHTML = '';

                var cat = CATEGORIES[catKey];
                var topicsInCat = TOPICS.filter(function (t) { return t.category === catKey; });

                body.appendChild(botMessage(
                    '<p>' + cat.icon + ' <strong>' + cat.label + '</strong></p>' +
                    '<p>על מה תרצה ללמוד?</p>'
                ));

                var topicButtons = topicsInCat.map(function (t) {
                    return {
                        html: t.icon + ' ' + t.title,
                        onClick: function () { renderTopic(t.id); }
                    };
                });

                topicButtons.push({
                    html: '◀ חזור',
                    onClick: renderMenu
                });

                body.appendChild(buttonRow(topicButtons));
                scrollToBottom();
            }

            // ─── VIEW: a single topic with steps ──────────────────
            function renderTopic(topicId) {
                var topic = TOPICS.find(function (t) { return t.id === topicId; });
                if (!topic) { renderMenu(); return; }

                body.innerHTML = '';

                // Build the answer HTML
                var html = '<p><strong>' + topic.icon + ' ' + topic.title + '</strong></p>';
                if (topic.steps && topic.steps.length) {
                    html += '<ol class="cmms-assist-steps">';
                    topic.steps.forEach(function (s) { html += '<li>' + s + '</li>'; });
                    html += '</ol>';
                }
                if (topic.tip) {
                    html += '<div class="cmms-assist-tip">' + topic.tip + '</div>';
                }

                body.appendChild(botMessage(html));

                // Action buttons
                var actionButtons = [];

                if (topic.link) {
                    if (userCanAccess(topic)) {
                        actionButtons.push({
                            html: '📍 ' + (topic.link_label || 'קח אותי לשם'),
                            onClick: function () {
                                // 1.14.88.2 fix: Build URL properly.
                                // topic.link is "?view=tasks" or "?view=settings&tab=org".
                                // We need to merge with DASHBOARD_URL (which may
                                // already contain params we want to drop, since
                                // we're navigating to a new page).
                                //
                                // Approach: use only the pathname of DASHBOARD_URL
                                // and append topic.link as-is. Cleanest.
                                var parts = DASHBOARD_URL.split('?');
                                var basePath = parts[0]; // "/cmms-dashboard/"
                                var newUrl = basePath + topic.link;
                                window.location.href = newUrl;
                            }
                        });
                    } else {
                        actionButtons.push({
                            html: '🔒 נדרשת הרשאה',
                            onClick: function () {
                                var needRole = topic.required_role.map(roleLabel).join(' / ');
                                body.appendChild(botMessage(
                                    '<p>⚠️ <strong>פעולה זו דורשת הרשאה</strong></p>' +
                                    '<p>כדי לבצע את הפעולה, אתה צריך להיות: <strong>' + needRole + '</strong></p>' +
                                    '<p>פנה לבעלים של החשבון או למנהל כדי לקבל הרשאה מתאימה.</p>'
                                ));
                                scrollToBottom();
                            }
                        });
                    }
                }

                actionButtons.push({
                    html: '◀ חזור',
                    onClick: function () { renderCategory(topic.category); }
                });

                actionButtons.push({
                    html: '🏠 תפריט ראשי',
                    onClick: renderMenu
                });

                body.appendChild(buttonRow(actionButtons));
                scrollToBottom();
            }
        })();
        </script>

        <style>
            /* ─── Floating assistant FAB ───────────────────────── */
            .cmms-assistant-fab {
                position: fixed;
                bottom: 24px;
                inset-inline-end: 24px;
                width: 60px;
                height: 60px;
                border-radius: 50%;
                background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
                color: #fff;
                border: none;
                cursor: pointer;
                box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);
                font-size: 28px;
                line-height: 1;
                z-index: 9998;
                transition: transform .2s ease, box-shadow .2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .cmms-assistant-fab:hover {
                transform: scale(1.08);
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.55);
            }
            .cmms-assistant-fab:active { transform: scale(0.96); }

            /* ─── Panel ────────────────────────────────────────── */
            .cmms-assistant-panel[hidden] {
                display: none !important;
            }
            .cmms-assistant-panel {
                position: fixed;
                bottom: 100px;
                inset-inline-end: 24px;
                width: 380px;
                max-width: calc(100vw - 32px);
                height: 560px;
                max-height: calc(100vh - 140px);
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
                z-index: 9999;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                animation: cmms-assist-in .18s ease;
            }
            @keyframes cmms-assist-in {
                from { opacity: 0; transform: translateY(12px); }
                to   { opacity: 1; transform: translateY(0); }
            }

            /* ─── Head ─────────────────────────────────────────── */
            .cmms-assistant-head {
                background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
                color: #fff;
                padding: 14px 16px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }
            .cmms-assistant-head-info { display: flex; align-items: center; gap: 12px; }
            .cmms-assistant-avatar {
                width: 40px; height: 40px;
                background: rgba(255,255,255,0.2);
                border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                font-size: 22px;
            }
            .cmms-assistant-title { font-weight: 700; font-size: 15px; line-height: 1.2; }
            .cmms-assistant-subtitle { font-size: 12px; opacity: 0.9; margin-top: 2px; }
            .cmms-assistant-close {
                background: rgba(255,255,255,0.15);
                color: #fff;
                border: none;
                width: 32px; height: 32px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 22px;
                line-height: 1;
                display: flex; align-items: center; justify-content: center;
            }
            .cmms-assistant-close:hover { background: rgba(255,255,255,0.28); }

            /* ─── Body (chat area) ─────────────────────────────── */
            .cmms-assistant-body {
                flex: 1;
                overflow-y: auto;
                padding: 16px;
                background: #f8fafc;
                display: flex;
                flex-direction: column;
                gap: 14px;
            }

            /* ─── Messages ─────────────────────────────────────── */
            .cmms-assist-msg {
                display: flex;
                gap: 8px;
                align-items: flex-end;
                animation: cmms-assist-msg-in .15s ease;
            }
            @keyframes cmms-assist-msg-in {
                from { opacity: 0; transform: translateY(4px); }
                to   { opacity: 1; transform: translateY(0); }
            }
            .cmms-assist-msg-avatar {
                width: 28px; height: 28px;
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                font-size: 14px;
                flex-shrink: 0;
            }
            .cmms-assist-msg-bubble {
                background: #fff;
                padding: 10px 14px;
                border-radius: 14px 14px 14px 4px;
                border: 1px solid #e2e8f0;
                font-size: 14px;
                line-height: 1.5;
                color: #1e293b;
                max-width: calc(100% - 40px);
            }
            .cmms-assist-msg-bubble p { margin: 0 0 8px 0; }
            .cmms-assist-msg-bubble p:last-child { margin-bottom: 0; }
            .cmms-assist-msg-bubble strong { color: #6366f1; }

            .cmms-assist-steps {
                margin: 8px 0 0 0;
                padding-inline-start: 22px;
                font-size: 13px;
                line-height: 1.6;
            }
            .cmms-assist-steps li { margin-bottom: 6px; }
            .cmms-assist-steps li:last-child { margin-bottom: 0; }

            .cmms-assist-tip {
                margin-top: 12px;
                padding: 10px 12px;
                background: #fef9c3;
                border-radius: 8px;
                font-size: 12.5px;
                line-height: 1.5;
                color: #713f12;
                border-inline-start: 3px solid #f59e0b;
            }

            /* ─── Action buttons ──────────────────────────────── */
            .cmms-assist-buttons {
                display: flex;
                flex-direction: column;
                gap: 8px;
                margin-top: 4px;
                padding-inline-start: 36px;
            }
            .cmms-assist-btn {
                background: #fff;
                border: 1.5px solid #e2e8f0;
                color: #1e293b;
                padding: 10px 14px;
                border-radius: 10px;
                font-size: 13.5px;
                font-weight: 500;
                cursor: pointer;
                text-align: start;
                transition: all .15s ease;
                line-height: 1.4;
            }
            .cmms-assist-btn:hover {
                background: #6366f1;
                color: #fff;
                border-color: #6366f1;
                transform: translateX(-2px);
            }

            /* ─── Footer ──────────────────────────────────────── */
            .cmms-assistant-foot {
                padding: 10px 16px;
                background: #fff;
                border-top: 1px solid #e2e8f0;
                display: flex;
                justify-content: center;
            }
            .cmms-assistant-foot-btn {
                background: transparent;
                border: none;
                color: #64748b;
                font-size: 12.5px;
                cursor: pointer;
                padding: 6px 12px;
                border-radius: 6px;
                transition: background .15s ease;
            }
            .cmms-assistant-foot-btn:hover {
                background: #f1f5f9;
                color: #1e293b;
            }

            /* ─── Mobile ──────────────────────────────────────── */
            @media (max-width: 480px) {
                .cmms-assistant-fab {
                    bottom: 16px;
                    inset-inline-end: 16px;
                    width: 54px;
                    height: 54px;
                    font-size: 24px;
                }
                .cmms-assistant-panel {
                    bottom: 0;
                    inset-inline-end: 0;
                    inset-inline-start: 0;
                    width: 100%;
                    max-width: 100%;
                    height: 80vh;
                    max-height: 80vh;
                    border-radius: 16px 16px 0 0;
                }
            }
        </style>
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
        $my_open_sql = "SELECT COUNT(*) FROM $t_tasks WHERE account_id = %d AND assigned_to = %d AND status IN ('open','in_progress','waiting') AND deleted_at IS NULL";
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
                    <?php // 1.14.80.4: also emit the inline-status-changer
                          //   assets here (dashboard recent tasks). The
                          //   method is idempotent — its static flag
                          //   makes sure CSS+JS load once even if other
                          //   sections on the page also call it. ?>
                    <?php $this->inline_status_changer_assets(); ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * 1.15.8: Account members for inline-assign dropdowns, cached per
     * request so we run one query regardless of how many cards render.
     */
    private function assign_members( $account_id ) {
        $account_id = (int) $account_id;
        if ( ! isset( $this->assign_members_cache[ $account_id ] ) ) {
            $this->assign_members_cache[ $account_id ] = CMMS_Users::list_by_account( $account_id );
        }
        return $this->assign_members_cache[ $account_id ];
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

            // 1.15.8: Inline assign — managers/owners can reassign the
            // "manager" (אחראי) and "assignee" (מוקצה ל) directly from
            // the list. Compute current names + the member list once.
            $can_assign = $u && in_array( $u->role, array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER ), true );
            $assignee_id   = isset( $task->assigned_to ) ? (int) $task->assigned_to : 0;
            $manager_id    = isset( $task->manager_id )  ? (int) $task->manager_id  : 0;
            $assignee_name = $assignee_id ? CMMS_Users::display_name( $assignee_id ) : '';
            $manager_name  = $manager_id  ? CMMS_Users::display_name( $manager_id )  : '';
            $assign_members = $can_assign ? $this->assign_members( $task->account_id ) : array();

            // 1.14.80: status pill is clickable if the user has
            // permission to change status on this task. The dropdown
            // is rendered inline and toggled by JS. We use a wrapping
            // <span> so we can intercept clicks (and stop propagation
            // to the parent <a> which would otherwise navigate to the
            // task page).
            $can_change_status = $u && CMMS_Auth::can_participate_task( $u, $task );

            // Status options for the dropdown. Excludes legacy 'closed'.
            $status_options = array(
                'open'        => array( 'label' => $this->t( 'status.open' ),        'dot' => '#EF9F27' ),
                'in_progress' => array( 'label' => $this->t( 'status.in_progress' ), 'dot' => '#D85A30' ),
                'waiting'     => array( 'label' => $this->t( 'status.waiting' ),     'dot' => '#F0997B' ),
                'completed'   => array( 'label' => $this->t( 'status.completed' ),   'dot' => '#1D9E75' ),
            );
            $url = $this->url( array( 'view' => 'task', 'id' => $task_id ) );
            ?>
            <a class="cmms-task-card priority-<?php echo esc_attr( $priority ); ?>" href="<?php echo esc_url( $url ); ?>" data-task-card-id="<?php echo (int) $task_id; ?>">
                <div class="cmms-task-card-body">
                    <h4 class="cmms-task-card-title"><?php echo esc_html( $title ); ?></h4>
                    <div class="cmms-task-card-meta">
                        <?php if ( $can_change_status ) : ?>
                            <span class="cmms-status-control"
                                  data-task-id="<?php echo (int) $task_id; ?>"
                                  data-current="<?php echo esc_attr( $status ); ?>">
                                <button type="button" class="cmms-badge cmms-status-trigger status-<?php echo esc_attr( $status ); ?>"
                                        aria-haspopup="menu" aria-expanded="false">
                                    <span class="cmms-badge-dot"></span>
                                    <span class="cmms-status-label"><?php echo esc_html( $this->t( 'status.' . $status ) ); ?></span>
                                    <span class="cmms-status-caret" aria-hidden="true">⌄</span>
                                </button>
                                <template class="cmms-status-menu-template">
                                    <div class="cmms-status-menu" role="menu">
                                        <div class="cmms-status-menu-title"><?php esc_html_e( 'העבר ל:', 'cmms-light' ); ?></div>
                                        <?php foreach ( $status_options as $opt_key => $opt ) :
                                            $is_current = ( $opt_key === $status );
                                        ?>
                                            <div class="cmms-status-menu-item <?php echo $is_current ? 'is-current' : ''; ?>"
                                                  data-status="<?php echo esc_attr( $opt_key ); ?>"
                                                  role="menuitem"
                                                  tabindex="<?php echo $is_current ? '-1' : '0'; ?>"
                                                  aria-disabled="<?php echo $is_current ? 'true' : 'false'; ?>">
                                                <span class="cmms-status-menu-dot" style="background:<?php echo esc_attr( $opt['dot'] ); ?>;"></span>
                                                <span class="cmms-status-menu-label"><?php echo esc_html( $opt['label'] ); ?><?php echo $is_current ? ' ' . esc_html__( '(נוכחי)', 'cmms-light' ) : ''; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </template>
                            </span>
                        <?php else : ?>
                            <span class="cmms-badge status-<?php echo esc_attr( $status ); ?>">
                                <span class="cmms-badge-dot"></span>
                                <?php echo esc_html( $this->t( 'status.' . $status ) ); ?>
                            </span>
                        <?php endif; ?>
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

                    <?php // 1.15.8: Inline assign row (managers/owners only). ?>
                    <?php if ( $can_assign ) : ?>
                        <?php $this->inline_assign_assets(); ?>
                        <div class="cmms-assign-row">
                            <?php
                            // Render one assign control. $field is
                            // 'manager_id' (אחראי) or 'assigned_to' (מוקצה).
                            $render_assign = function( $field, $label, $icon, $current_id, $current_name ) use ( $task_id, $assign_members, $u ) {
                                ?>
                                <span class="cmms-assign-control" data-task-id="<?php echo (int) $task_id; ?>" data-field="<?php echo esc_attr( $field ); ?>" data-current="<?php echo (int) $current_id; ?>">
                                    <button type="button" class="cmms-assign-trigger" aria-haspopup="menu" aria-expanded="false">
                                        <span class="cmms-assign-icon"><?php echo $icon; ?></span>
                                        <span class="cmms-assign-label"><?php echo esc_html( $label ); ?>:</span>
                                        <span class="cmms-assign-value <?php echo $current_id ? '' : 'is-empty'; ?>">
                                            <?php echo $current_id ? esc_html( $current_name ) : esc_html__( 'להקצות', 'cmms-light' ); ?>
                                        </span>
                                        <span class="cmms-assign-caret" aria-hidden="true">⌄</span>
                                    </button>
                                    <template class="cmms-assign-menu-template">
                                        <div class="cmms-assign-menu" role="menu">
                                            <div class="cmms-assign-menu-item <?php echo ! $current_id ? 'is-current' : ''; ?>" data-user-id="0" role="menuitem">
                                                <span class="cmms-assign-menu-label"><?php esc_html_e( '— ללא —', 'cmms-light' ); ?></span>
                                            </div>
                                            <?php foreach ( $assign_members as $m ) :
                                                $mid = (int) $m->id;
                                                $mname = ! empty( $m->display_name ) ? $m->display_name : ( ! empty( $m->user_email ) ? $m->user_email : ( '#' . $mid ) );
                                                $is_cur = ( $mid === (int) $current_id );
                                            ?>
                                                <div class="cmms-assign-menu-item <?php echo $is_cur ? 'is-current' : ''; ?>" data-user-id="<?php echo $mid; ?>" role="menuitem">
                                                    <span class="cmms-assign-menu-label"><?php echo esc_html( $mname ); ?><?php echo $is_cur ? ' ' . esc_html__( '(נוכחי)', 'cmms-light' ) : ''; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </template>
                                </span>
                                <?php
                            };
                            $render_assign( 'manager_id', __( 'אחראי', 'cmms-light' ), '👤', $manager_id, $manager_name );
                            $render_assign( 'assigned_to', __( 'מבצע', 'cmms-light' ), '🔧', $assignee_id, $assignee_name );
                            ?>
                        </div>
                    <?php endif; ?>
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

    /**
     * 1.14.80 — Inline status changer CSS + JS for task cards.
     *
     * Emitted once per page, AFTER the list of task_card() outputs.
     * Static flag prevents double-emit if a page has multiple
     * task lists (e.g. asset detail can have it twice if it ever
     * renders).
     *
     * Reuses the existing cmms_kanban_status AJAX endpoint — that one
     * already does nonce check + can_participate_task + status validation
     * + audit log. No need for a new endpoint.
     */
    private function inline_status_changer_assets() {
        static $emitted = false;
        if ( $emitted ) return;
        $emitted = true;

        $nonce    = wp_create_nonce( 'cmms_kanban' );
        $ajax_url = admin_url( 'admin-ajax.php' );

        // i18n labels passed to JS so they're translatable.
        $labels = array(
            'open'        => $this->t( 'status.open' ),
            'in_progress' => $this->t( 'status.in_progress' ),
            'waiting'     => $this->t( 'status.waiting' ),
            'completed'   => $this->t( 'status.completed' ),
            'current'     => __( '(נוכחי)', 'cmms-light' ),
            'updated'     => __( 'הסטטוס עודכן', 'cmms-light' ),
            'failed'      => __( 'העדכון נכשל. נסה שוב.', 'cmms-light' ),
            'network'     => __( 'שגיאת רשת. נסה שוב.', 'cmms-light' ),
        );
        ?>
        <style>
        /* 1.14.80.1 — Inline status changer.
           Strategy: the trigger lives inside the task <a> (which is fine
           since it's a <button> with preventDefault), but the dropdown
           menu is rendered into a top-level container appended to <body>
           with position: fixed. This bypasses ALL stacking contexts
           (overflow:hidden parents, transforms, z-index issues) and
           guarantees the menu floats above everything. */
        .cmms-status-control {
            position: relative;
            display: inline-block;
        }
        .cmms-status-trigger {
            cursor: pointer;
            border: 1px solid transparent;
            transition: box-shadow .12s ease, border-color .12s ease;
            user-select: none;
            -webkit-user-select: none;
            font: inherit;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            -webkit-appearance: none;
            appearance: none;
        }
        .cmms-status-trigger:hover { border-color: rgba(15,23,42,.18); }
        .cmms-status-trigger:focus-visible {
            outline: 0;
            box-shadow: 0 0 0 2px rgba(79,70,229,.4);
        }
        .cmms-status-trigger.is-open {
            box-shadow: 0 0 0 2px rgba(239,159,39,.4);
            border-color: rgba(239,159,39,.4);
        }
        .cmms-status-caret {
            font-size: 10px;
            margin-inline-start: 2px;
            opacity: .7;
            transition: transform .15s ease;
            display: inline-block;
            line-height: 1;
        }
        .cmms-status-trigger.is-open .cmms-status-caret {
            transform: rotate(180deg);
        }

        /* Floating menu — rendered as a body child. */
        .cmms-status-menu-floating {
            position: fixed;
            background: #fff;
            border: 1px solid rgba(15,23,42,.15);
            border-radius: 10px;
            box-shadow: 0 10px 32px rgba(15,23,42,.18);
            padding: 4px;
            min-width: 180px;
            z-index: 999999;
            display: flex;
            flex-direction: column;
            gap: 1px;
            font-family: Arial, sans-serif;
            animation: cmms-status-menu-in .12s ease;
        }
        @keyframes cmms-status-menu-in {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .cmms-status-menu-title {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            padding: 6px 10px 4px;
        }
        .cmms-status-menu-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13.5px;
            color: #0f172a;
            transition: background .12s ease;
            text-align: start;
            -webkit-tap-highlight-color: transparent;
        }
        .cmms-status-menu-item:hover:not(.is-current) { background: #f8fafc; }
        .cmms-status-menu-item:focus-visible {
            outline: 0;
            background: #eef2ff;
        }
        .cmms-status-menu-item.is-current {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: default;
        }
        .cmms-status-menu-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            flex: none;
        }

        .cmms-task-card.cmms-saving { opacity: .65; pointer-events: none; }
        .cmms-task-card.cmms-error { animation: cmms-status-shake .35s ease; }
        @keyframes cmms-status-shake {
            0%, 100% { transform: translateX(0); }
            25%      { transform: translateX(-4px); }
            75%      { transform: translateX(4px); }
        }

        @media (max-width: 640px) {
            .cmms-status-menu-floating { min-width: 200px; }
            .cmms-status-menu-item { padding: 12px 14px; font-size: 14.5px; }
            .cmms-status-trigger { padding-top: 5px; padding-bottom: 5px; }
        }

        .cmms-inline-status-toast {
            position: fixed;
            bottom: 24px;
            inset-inline-start: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: #fff;
            padding: 11px 20px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 500;
            box-shadow: 0 6px 22px rgba(15,23,42,.3);
            z-index: 999998;
            opacity: 0;
            transition: opacity .2s ease;
            pointer-events: none;
            font-family: Arial, sans-serif;
        }
        .cmms-inline-status-toast.is-visible { opacity: 1; }
        .cmms-inline-status-toast.is-error { background: #b91c1c; }
        </style>

        <script>
        (function () {
            'use strict';
            var controls = document.querySelectorAll('.cmms-status-control');
            if (!controls.length) return;

            var AJAX_URL = <?php echo wp_json_encode( $ajax_url ); ?>;
            var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
            var LABELS   = <?php echo wp_json_encode( $labels ); ?>;
            var STATUS_CLASSES = ['open', 'in_progress', 'waiting', 'completed'];

            // Toast singleton.
            var toast = document.createElement('div');
            toast.className = 'cmms-inline-status-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.appendChild(toast);
            var toastTimer = null;
            function showToast(msg, isError) {
                toast.textContent = msg;
                toast.classList.toggle('is-error', !!isError);
                toast.classList.add('is-visible');
                if (toastTimer) clearTimeout(toastTimer);
                toastTimer = setTimeout(function () {
                    toast.classList.remove('is-visible');
                }, 2200);
            }

            // Currently open menu state (only one can be open).
            var openMenu = null;      // floating DOM node
            var openTrigger = null;   // the button that opened it
            var openControl = null;   // the .cmms-status-control span

            function closeMenu() {
                if (openMenu) {
                    openMenu.remove();
                    openMenu = null;
                }
                if (openTrigger) {
                    openTrigger.classList.remove('is-open');
                    openTrigger.setAttribute('aria-expanded', 'false');
                    openTrigger = null;
                }
                openControl = null;
            }

            // Position the floating menu under the trigger.
            // Falls back to above if no room below.
            function positionMenu(menu, trigger) {
                var rect = trigger.getBoundingClientRect();
                var menuRect = menu.getBoundingClientRect();
                var vw = window.innerWidth;
                var vh = window.innerHeight;

                // Default: place below, aligned to the trigger's start edge.
                var top = rect.bottom + 6;
                var left = rect.left;

                // RTL alignment: in RTL, align right edge of menu to right edge of trigger.
                var isRTL = (document.dir === 'rtl' || document.documentElement.dir === 'rtl' ||
                             getComputedStyle(document.body).direction === 'rtl');
                if (isRTL) {
                    left = rect.right - menuRect.width;
                }

                // Keep on screen horizontally.
                if (left < 8) left = 8;
                if (left + menuRect.width > vw - 8) left = vw - menuRect.width - 8;

                // If menu would overflow bottom, place it above the trigger.
                if (top + menuRect.height > vh - 8) {
                    var aboveTop = rect.top - menuRect.height - 6;
                    if (aboveTop > 8) top = aboveTop;
                    // else: leave below, just clamp later
                }
                if (top < 8) top = 8;

                menu.style.top = top + 'px';
                menu.style.left = left + 'px';
            }

            function openMenuFor(control) {
                if (openControl === control) {
                    closeMenu();
                    return;
                }
                closeMenu();

                var trigger = control.querySelector('.cmms-status-trigger');
                var template = control.querySelector('.cmms-status-menu-template');
                if (!trigger || !template) return;

                // Clone the template content into a floating menu.
                var menuSource = template.content
                    ? template.content.querySelector('.cmms-status-menu')
                    : template.querySelector('.cmms-status-menu'); // fallback if <template> not supported
                if (!menuSource) return;
                var menu = menuSource.cloneNode(true);
                menu.classList.add('cmms-status-menu-floating');
                menu.classList.remove('cmms-status-menu'); // remove the inline-styled class
                document.body.appendChild(menu);

                // First add to DOM so we can measure, then position.
                menu.style.visibility = 'hidden';
                positionMenu(menu, trigger);
                menu.style.visibility = '';

                openMenu = menu;
                openTrigger = trigger;
                openControl = control;
                trigger.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');

                // Wire menu items.
                menu.querySelectorAll('.cmms-status-menu-item').forEach(function (item) {
                    var itemTouchHandled = false;

                    item.addEventListener('touchstart', function (e) {
                        if (item.getAttribute('aria-disabled') === 'true') return;
                        e.preventDefault();
                        e.stopPropagation();
                        itemTouchHandled = true;
                        applyStatusChange(control, item.getAttribute('data-status'));
                    }, { passive: false });

                    item.addEventListener('touchend', function (e) {
                        if (itemTouchHandled) {
                            e.preventDefault();
                            e.stopPropagation();
                            setTimeout(function () { itemTouchHandled = false; }, 300);
                        }
                    }, { passive: false });

                    item.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (itemTouchHandled) return;  // iOS synthesized click — already handled
                        if (item.getAttribute('aria-disabled') === 'true') return;
                        applyStatusChange(control, item.getAttribute('data-status'));
                    });
                });
            }

            function applyStatusChange(control, newStatus) {
                var oldStatus = control.getAttribute('data-current');
                var trigger   = control.querySelector('.cmms-status-trigger');
                var labelEl   = trigger ? trigger.querySelector('.cmms-status-label') : null;
                var card      = control.closest('.cmms-task-card');
                var taskId    = control.getAttribute('data-task-id');

                closeMenu();
                if (!newStatus || newStatus === oldStatus) return;

                // Optimistic UI.
                if (labelEl) labelEl.textContent = LABELS[newStatus] || newStatus;
                if (trigger) {
                    STATUS_CLASSES.forEach(function (s) { trigger.classList.remove('status-' + s); });
                    trigger.classList.add('status-' + newStatus);
                }
                control.setAttribute('data-current', newStatus);
                if (card) card.classList.add('cmms-saving');

                // Save.
                var body = new URLSearchParams();
                body.set('action',  'cmms_kanban_status');
                body.set('nonce',   NONCE);
                body.set('task_id', taskId);
                body.set('status',  newStatus);

                fetch(AJAX_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json().catch(function () { return null; }); })
                .then(function (res) {
                    if (card) card.classList.remove('cmms-saving');
                    if (res && res.success) {
                        showToast(LABELS.updated);
                    } else {
                        rollback();
                        showToast((res && res.data && res.data.message) ? res.data.message : LABELS.failed, true);
                    }
                })
                .catch(function () {
                    if (card) card.classList.remove('cmms-saving');
                    rollback();
                    showToast(LABELS.network, true);
                });

                function rollback() {
                    if (labelEl) labelEl.textContent = LABELS[oldStatus] || oldStatus;
                    if (trigger) {
                        STATUS_CLASSES.forEach(function (s) { trigger.classList.remove('status-' + s); });
                        trigger.classList.add('status-' + oldStatus);
                    }
                    control.setAttribute('data-current', oldStatus);
                    if (card) {
                        card.classList.add('cmms-error');
                        setTimeout(function () { card.classList.remove('cmms-error'); }, 400);
                    }
                }
            }

            // Wire each trigger button.
            controls.forEach(function (control) {
                var trigger = control.querySelector('.cmms-status-trigger');
                if (!trigger) return;

                // 1.14.80.3 — Mobile fix.
                // The trigger lives inside an <a>. On iOS Safari, a tap
                // on this button triggers: touchstart → touchend → click
                // → navigation. To intercept the navigation we MUST
                // preventDefault on touchstart with passive:false, which
                // stops the whole event chain before iOS schedules the
                // synthesized click. Without this, the <a>'s href fires.
                //
                // On desktop, the same flow works via the click handler
                // below — but we still preventDefault on mousedown to
                // stop browsers that begin navigation on mousedown.

                var touchHandled = false;

                trigger.addEventListener('touchstart', function (e) {
                    e.preventDefault();    // CRITICAL: stops the synthesized click + nav
                    e.stopPropagation();
                    touchHandled = true;
                    openMenuFor(control);
                }, { passive: false });

                trigger.addEventListener('touchend', function (e) {
                    if (touchHandled) {
                        e.preventDefault();
                        e.stopPropagation();
                        // Reset flag on next tick so subsequent taps work.
                        setTimeout(function () { touchHandled = false; }, 300);
                    }
                }, { passive: false });

                trigger.addEventListener('mousedown', function (e) {
                    e.stopPropagation();
                }, { passive: true });

                trigger.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    // If this click came from a tap we already handled
                    // via touchstart, ignore it (iOS synthesized click).
                    if (touchHandled) return false;
                    openMenuFor(control);
                    return false;
                });

                // Keyboard support.
                trigger.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        e.stopPropagation();
                        openMenuFor(control);
                    }
                });
            });

            // Click outside → close.
            document.addEventListener('click', function (e) {
                if (!openMenu) return;
                if (e.target.closest('.cmms-status-menu-floating')) return;
                if (e.target.closest('.cmms-status-trigger')) return;
                closeMenu();
            });

            // Escape → close.
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeMenu();
            });

            // Scroll / resize → close (avoids menu drifting away from trigger).
            window.addEventListener('scroll', function () {
                if (openMenu) closeMenu();
            }, true);
            window.addEventListener('resize', function () {
                if (openMenu) closeMenu();
            });
        })();
        </script>
        <?php
    }

    /**
     * 1.15.8 — Inline assign (אחראי / מבצע) CSS + JS for task cards.
     * Emitted once per page (static flag). Mirrors the status changer:
     * a trigger button inside the card opens a menu of account members;
     * picking one POSTs to cmms_task_assign and updates the label inline.
     */
    private function inline_assign_assets() {
        static $done = false;
        if ( $done ) return;
        $done = true;
        $nonce = wp_create_nonce( 'cmms_task_assign' );
        ?>
        <style>
        .cmms-assign-row {
            display: flex; flex-wrap: wrap; gap: 8px;
            margin-top: 8px; padding-top: 8px;
            border-top: 1px solid var(--c-border, #e2e8f0);
        }
        .cmms-assign-control { position: relative; display: inline-flex; }
        .cmms-assign-trigger {
            display: inline-flex; align-items: center; gap: 4px;
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 6px; padding: 3px 8px; font-size: 11px;
            color: #475569; cursor: pointer; line-height: 1.4;
        }
        .cmms-assign-trigger:hover { background: #f1f5f9; border-color: #cbd5e1; }
        .cmms-assign-icon { font-size: 12px; }
        .cmms-assign-label { color: #94a3b8; }
        .cmms-assign-value { font-weight: 600; color: #0f172a; }
        .cmms-assign-value.is-empty { color: #2563eb; font-weight: 500; }
        .cmms-assign-caret { font-size: 9px; opacity: 0.6; }
        .cmms-assign-menu {
            position: absolute; top: calc(100% + 4px); right: 0;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
            box-shadow: 0 8px 24px rgba(15,23,42,0.14);
            padding: 4px; min-width: 180px; max-height: 260px;
            overflow-y: auto; z-index: 9999;
            /* No transition — appear instantly. The card's `transition:
               all` would otherwise animate the menu in over ~200ms,
               which looked like a multi-second lag while it settled. */
            transition: none !important;
            animation: none !important;
        }
        /* The card animates its transform/border on hover; that must not
           cascade into the absolutely-positioned menu. */
        .cmms-assign-control, .cmms-assign-menu, .cmms-assign-menu * {
            transition: none !important;
        }
        .cmms-assign-menu-item {
            padding: 7px 10px; border-radius: 5px; font-size: 12px;
            color: #334155; cursor: pointer; white-space: nowrap;
        }
        .cmms-assign-menu-item:hover { background: #f1f5f9; }
        .cmms-assign-menu-item.is-current { background: #eff6ff; color: #1e40af; font-weight: 600; }
        </style>
        <script>
        (function () {
            if (window.__cmmsAssignBound) return;
            window.__cmmsAssignBound = true;

            var AJAX = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
            var openMenu = null;

            function closeMenu() {
                if (openMenu && openMenu.parentNode) openMenu.parentNode.removeChild(openMenu);
                openMenu = null;
            }

            document.addEventListener('click', function (e) {
                var trigger = e.target.closest('.cmms-assign-trigger');
                if (trigger) {
                    // Don't navigate the parent <a>.
                    e.preventDefault();
                    e.stopPropagation();
                    var control = trigger.closest('.cmms-assign-control');
                    openFor(control, trigger);
                    return;
                }
                var item = e.target.closest('.cmms-assign-menu-item');
                if (item) {
                    e.preventDefault();
                    e.stopPropagation();
                    handlePick(item);
                    return;
                }
                // Click elsewhere → close.
                if (!e.target.closest('.cmms-assign-menu')) closeMenu();
            });

            function openFor(control, trigger) {
                if (openMenu) { closeMenu(); }
                var tpl = control.querySelector('.cmms-assign-menu-template');
                if (!tpl) return;
                var menu = tpl.content.firstElementChild.cloneNode(true);
                menu.__control = control;

                // Render the menu on <body> with fixed positioning rather
                // than inside the card. Inside the card it was being
                // clipped by an ancestor's overflow (the card / list
                // container), which is why it appeared cut off until a
                // reflow "freed" it. On body + fixed, nothing clips it.
                menu.style.position = 'fixed';
                menu.style.zIndex = '99999';
                document.body.appendChild(menu);

                // Position it under the trigger. Align to the trigger's
                // right edge (RTL-friendly) and just below it.
                var rect = trigger.getBoundingClientRect();
                var menuWidth = menu.offsetWidth || 180;
                var left = rect.right - menuWidth;
                if (left < 8) left = 8; // keep on-screen
                var top = rect.bottom + 4;

                // If it would overflow the bottom of the viewport, open
                // upward instead.
                var menuHeight = menu.offsetHeight || 200;
                if (top + menuHeight > window.innerHeight - 8) {
                    top = rect.top - menuHeight - 4;
                    if (top < 8) top = 8;
                }

                menu.style.left = left + 'px';
                menu.style.top = top + 'px';
                menu.style.right = 'auto';

                openMenu = menu;
            }

            function handlePick(item) {
                var menu = item.closest('.cmms-assign-menu');
                var control = menu ? menu.__control : null;
                if (!control) { closeMenu(); return; }

                var taskId = control.getAttribute('data-task-id');
                var field  = control.getAttribute('data-field');
                var userId = item.getAttribute('data-user-id');
                var newName = item.querySelector('.cmms-assign-menu-label').textContent
                                  .replace(' (נוכחי)', '').trim();

                var valueEl = control.querySelector('.cmms-assign-value');
                var oldText = valueEl ? valueEl.textContent : '';
                var card = control.closest('.cmms-task-card');

                closeMenu();

                // Optimistic update.
                if (valueEl) {
                    if (userId === '0') {
                        valueEl.textContent = 'להקצות';
                        valueEl.classList.add('is-empty');
                    } else {
                        valueEl.textContent = newName;
                        valueEl.classList.remove('is-empty');
                    }
                }
                control.setAttribute('data-current', userId);
                if (card) card.classList.add('cmms-saving');

                var body = new URLSearchParams();
                body.set('action', 'cmms_task_assign');
                body.set('nonce', NONCE);
                body.set('task_id', taskId);
                body.set('field', field);
                body.set('user_id', userId);

                fetch(AJAX, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (card) card.classList.remove('cmms-saving');
                    if (!res || !res.success) {
                        // Rollback.
                        if (valueEl) valueEl.textContent = oldText;
                        showToast((res && res.data && res.data.message) || 'שמירה נכשלה', true);
                    } else {
                        showToast('נשמר ✓', false);
                    }
                })
                .catch(function () {
                    if (card) card.classList.remove('cmms-saving');
                    if (valueEl) valueEl.textContent = oldText;
                    showToast('שגיאת רשת', true);
                });
            }

            // Reuse the status toast if present, else make a minimal one.
            function showToast(msg, isError) {
                var toast = document.querySelector('.cmms-inline-status-toast');
                if (!toast) {
                    toast = document.createElement('div');
                    toast.className = 'cmms-inline-status-toast';
                    document.body.appendChild(toast);
                }
                toast.textContent = msg;
                toast.classList.toggle('is-error', !!isError);
                toast.classList.add('is-visible');
                setTimeout(function () { toast.classList.remove('is-visible'); }, 2000);
            }

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && openMenu) closeMenu();
            });
            window.addEventListener('resize', function () {
                if (openMenu) closeMenu();
            });
            // Fixed-positioned menu won't follow the page on scroll, so
            // close it when the user scrolls (capture phase catches
            // scrolls on inner containers too).
            window.addEventListener('scroll', function () {
                if (openMenu) closeMenu();
            }, true);
        })();
        </script>
        <?php
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
        // 1.14.79: "mine" UX filter — applied at the DB level via
        // list_for_user. Server-side, so even with thousands of tasks
        // in the account only the user's own rows are fetched/rendered.
        if ( ! empty( $_GET['mine'] ) ) $filters['mine'] = 1;

        // 1.15.2: Multi-user filter — privileged users (owner/manager)
        // can pick one or more team members and see tasks where any of
        // them is involved (assignee/manager/creator). Comes in as
        // ?users[]=1&users[]=2 in the URL. Sanitized to ints, empties
        // dropped, and only honored for privileged roles (UI hides
        // the dropdown from others; SQL ignores the filter regardless).
        if ( ! empty( $_GET['users'] ) && is_array( $_GET['users'] ) ) {
            $user_ids = array_filter( array_map( 'intval', wp_unslash( $_GET['users'] ) ) );
            if ( ! empty( $user_ids ) && in_array( $u->role, array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER ), true ) ) {
                $filters['users'] = array_values( $user_ids );
            }
        }

        // 1.14.81.1: no_due_date filter — used by the "X משימות ללא
        // תאריך יעד" banner in the calendar view. Lets the user jump
        // from the calendar to a filtered list of just those tasks so
        // they can set due-dates and have them appear on the calendar.
        if ( ! empty( $_GET['no_due_date'] ) ) $filters['no_due_date'] = 1;

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

        <?php // 1.14.79: scope pills — [הכל] [רק שלי].
              // Sits ABOVE the status pills so it's clearly a different
              // axis (scope) than status (state). Privileged users
              // (owner/manager) see the toggle since they're the ones
              // who see "all" by default and might want to narrow to
              // their own work. Non-privileged users only ever see
              // their related set, so the pill is hidden (would be a no-op).
              $is_priv_role = in_array( $u->role, array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER ), true );
              if ( $is_priv_role ) :
                  $url_all  = $this->url( array( 'view' => 'tasks' ) );
                  // Preserve other filters when toggling scope.
                  $current_qs = array_filter( array(
                      'status'      => $filters['status']      ?? '',
                      'as_assignee' => ! empty( $filters['as_assignee'] ) ? 1 : '',
                      'as_manager'  => ! empty( $filters['as_manager'] )  ? 1 : '',
                      'overdue'     => ! empty( $filters['overdue'] )     ? 1 : '',
                      'category_id' => $filters['category_id'] ?? '',
                  ) );
                  $url_mine = $this->url( array_merge( array( 'view' => 'tasks', 'mine' => 1 ), $current_qs ) );
                  $url_all_link = $this->url( array_merge( array( 'view' => 'tasks' ), $current_qs ) );
                  $is_mine = ! empty( $filters['mine'] );

                  // 1.15.2: Multi-user filter dropdown. Load the team
                  // member list once, render a checkbox list, submit
                  // as a form (GET). Selected IDs come back as users[].
                  $team_members      = CMMS_Users::list_by_account( $u->account_id );
                  $selected_user_ids = ! empty( $filters['users'] ) ? array_map( 'intval', $filters['users'] ) : array();
                  $selected_count    = count( $selected_user_ids );
        ?>
        <div class="cmms-flex cmms-gap-2 cmms-mb-3" style="flex-wrap:wrap;">
            <a class="cmms-btn cmms-btn-sm <?php echo ! $is_mine && empty( $selected_user_ids ) ? 'cmms-btn-secondary' : ''; ?>"
               href="<?php echo esc_url( $url_all_link ); ?>"><?php esc_html_e( 'הכל', 'cmms-light' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo $is_mine ? 'cmms-btn-secondary' : ''; ?>"
               href="<?php echo esc_url( $url_mine ); ?>">
                👤 <?php esc_html_e( 'רק שלי', 'cmms-light' ); ?>
            </a>

            <?php // ── Multi-user dropdown ── ?>
            <div class="cmms-user-filter-wrap" style="position:relative;display:inline-block;">
                <button type="button"
                        class="cmms-btn cmms-btn-sm cmms-user-filter-toggle <?php echo $selected_count > 0 ? 'cmms-btn-secondary' : ''; ?>"
                        style="display:inline-flex;align-items:center;gap:6px;">
                    👥
                    <?php if ( $selected_count > 0 ) : ?>
                        <?php printf( esc_html__( '%d משתמשים נבחרו', 'cmms-light' ), $selected_count ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'משתמשים נוספים', 'cmms-light' ); ?>
                    <?php endif; ?>
                    <span style="font-size:10px;opacity:0.7;">▾</span>
                </button>

                <div class="cmms-user-filter-panel"
                     style="display:none;position:absolute;top:calc(100% + 4px);right:0;
                            background:#fff;border:1px solid #e2e8f0;border-radius:8px;
                            box-shadow:0 8px 24px rgba(15,23,42,0.12);
                            padding:10px;min-width:240px;max-width:320px;z-index:50;">
                    <form method="get" action="">
                        <?php
                        // Preserve other GET params when submitting the
                        // user-filter form. Without this, choosing users
                        // would reset status/category/etc filters.
                        $preserved = array_filter( array(
                            'view'        => 'tasks',
                            'status'      => $filters['status']      ?? '',
                            'as_assignee' => ! empty( $filters['as_assignee'] ) ? 1 : '',
                            'as_manager'  => ! empty( $filters['as_manager'] )  ? 1 : '',
                            'overdue'     => ! empty( $filters['overdue'] )     ? 1 : '',
                            'category_id' => $filters['category_id'] ?? '',
                            'mine'        => ! empty( $filters['mine'] ) ? 1 : '',
                        ) );
                        foreach ( $preserved as $key => $val ) :
                        ?>
                            <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $val ); ?>">
                        <?php endforeach; ?>

                        <?php // Search box (helpful when there are many users) ?>
                        <input type="text"
                               class="cmms-input cmms-user-filter-search"
                               placeholder="<?php esc_attr_e( 'חיפוש משתמש...', 'cmms-light' ); ?>"
                               style="width:100%;margin-bottom:8px;font-size:12px;padding:6px 8px;">

                        <div class="cmms-user-filter-list"
                             style="max-height:280px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:6px;padding:6px;">
                            <?php if ( empty( $team_members ) ) : ?>
                                <div style="font-size:12px;color:#64748b;padding:8px;text-align:center;">
                                    <?php esc_html_e( 'אין משתמשים אחרים', 'cmms-light' ); ?>
                                </div>
                            <?php else : ?>
                                <?php foreach ( $team_members as $member ) :
                                    $is_checked   = in_array( (int) $member->id, $selected_user_ids, true );
                                    $is_me        = ( (int) $member->id === (int) $u->id );
                                    // Field name varies historically — display_name is the
                                    // canonical column, but defend against legacy/missing data
                                    // by falling back to user_email or a placeholder.
                                    $display_name = '';
                                    if ( ! empty( $member->display_name ) )      $display_name = $member->display_name;
                                    elseif ( ! empty( $member->name ) )          $display_name = $member->name;
                                    elseif ( ! empty( $member->user_email ) )    $display_name = $member->user_email;
                                    elseif ( ! empty( $member->email ) )         $display_name = $member->email;
                                    else                                          $display_name = '#' . (int) $member->id;
                                    $search_email = $member->user_email ?? ( $member->email ?? '' );
                                ?>
                                    <label class="cmms-user-filter-row"
                                           data-search-text="<?php echo esc_attr( strtolower( $display_name . ' ' . $search_email ) ); ?>"
                                           style="display:flex;align-items:center;gap:8px;padding:6px 4px;cursor:pointer;border-radius:4px;font-size:12px;">
                                        <input type="checkbox"
                                               name="users[]"
                                               value="<?php echo (int) $member->id; ?>"
                                               <?php checked( $is_checked ); ?>
                                               style="margin:0;flex-shrink:0;">
                                        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                            <?php echo esc_html( $display_name ); ?>
                                            <?php if ( $is_me ) : ?>
                                                <span style="color:#64748b;font-size:10px;">(<?php esc_html_e( 'אני', 'cmms-light' ); ?>)</span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div style="display:flex;gap:6px;margin-top:8px;">
                            <button type="submit" class="cmms-btn cmms-btn-sm cmms-btn-primary" style="flex:1;font-size:11px;">
                                <?php esc_html_e( 'החל', 'cmms-light' ); ?>
                            </button>
                            <?php if ( $selected_count > 0 ) : ?>
                                <a href="<?php echo esc_url( $url_all_link ); ?>"
                                   class="cmms-btn cmms-btn-sm cmms-btn-ghost"
                                   style="flex:1;font-size:11px;text-align:center;">
                                    <?php esc_html_e( 'נקה', 'cmms-light' ); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        (function() {
            if ( window.__cmmsUserFilterBound ) return;
            window.__cmmsUserFilterBound = true;

            // ── Toggle dropdown open/close ──
            document.addEventListener('click', function(e) {
                var toggle = e.target.closest('.cmms-user-filter-toggle');
                if ( toggle ) {
                    e.preventDefault();
                    var wrap = toggle.closest('.cmms-user-filter-wrap');
                    var panel = wrap.querySelector('.cmms-user-filter-panel');
                    var isOpen = panel.style.display === 'block';
                    // Close any other open panels (only one open at a time).
                    document.querySelectorAll('.cmms-user-filter-panel').forEach(function(p) {
                        p.style.display = 'none';
                    });
                    panel.style.display = isOpen ? 'none' : 'block';
                    if ( ! isOpen ) {
                        var search = panel.querySelector('.cmms-user-filter-search');
                        if ( search ) setTimeout(function() { search.focus(); }, 50);
                    }
                    return;
                }
                // Click outside any panel — close everything.
                if ( ! e.target.closest('.cmms-user-filter-wrap') ) {
                    document.querySelectorAll('.cmms-user-filter-panel').forEach(function(p) {
                        p.style.display = 'none';
                    });
                }
            });

            // ── Hover effect on user rows ──
            document.addEventListener('mouseover', function(e) {
                var row = e.target.closest('.cmms-user-filter-row');
                if ( row ) row.style.background = '#f8fafc';
            });
            document.addEventListener('mouseout', function(e) {
                var row = e.target.closest('.cmms-user-filter-row');
                if ( row ) row.style.background = '';
            });

            // ── Search filter ──
            document.addEventListener('input', function(e) {
                var search = e.target.closest('.cmms-user-filter-search');
                if ( ! search ) return;
                var query = search.value.toLowerCase().trim();
                var panel = search.closest('.cmms-user-filter-panel');
                panel.querySelectorAll('.cmms-user-filter-row').forEach(function(row) {
                    var text = row.getAttribute('data-search-text') || '';
                    row.style.display = ( query === '' || text.indexOf(query) !== -1 ) ? '' : 'none';
                });
            });
        })();
        </script>
        <?php endif; ?>

        <!-- Filter pills -->
        <div class="cmms-flex cmms-gap-2 cmms-mb-4" style="flex-wrap:wrap;">
            <?php
                // Helper: build a URL that preserves the current "mine"
                // selection across status changes. Without this, clicking
                // a status pill while "mine" is active would silently
                // drop the scope.
                $mine_args = ! empty( $filters['mine'] ) ? array( 'mine' => 1 ) : array();
            ?>
            <a class="cmms-btn cmms-btn-sm <?php echo empty( $filters['status'] ) && empty( $filters['as_assignee'] ) && empty( $filters['as_manager'] ) && empty( $filters['overdue'] ) ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array_merge( array( 'view' => 'tasks' ), $mine_args ) ) ); ?>"><?php $this->e( 'filter.all' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ( $filters['status'] ?? '' ) === 'open' ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array_merge( array( 'view' => 'tasks', 'status' => 'open' ), $mine_args ) ) ); ?>"><?php $this->e( 'status.open' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ( $filters['status'] ?? '' ) === 'in_progress' ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array_merge( array( 'view' => 'tasks', 'status' => 'in_progress' ), $mine_args ) ) ); ?>"><?php $this->e( 'status.in_progress' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ( $filters['status'] ?? '' ) === 'completed' ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array_merge( array( 'view' => 'tasks', 'status' => 'completed' ), $mine_args ) ) ); ?>"><?php $this->e( 'status.completed' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ! empty( $filters['as_assignee'] ) ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array_merge( array( 'view' => 'tasks', 'as_assignee' => 1 ), $mine_args ) ) ); ?>"><?php CMMS_Icons::e( 'wrench', 14 ); ?> <?php $this->e( 'filter.as_assignee' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ! empty( $filters['as_manager'] ) ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array_merge( array( 'view' => 'tasks', 'as_manager' => 1 ), $mine_args ) ) ); ?>"><?php CMMS_Icons::e( 'briefcase', 14 ); ?> <?php $this->e( 'filter.as_manager' ); ?></a>
            <a class="cmms-btn cmms-btn-sm <?php echo ! empty( $filters['overdue'] ) ? 'cmms-btn-secondary' : ''; ?>" href="<?php echo esc_url( $this->url( array_merge( array( 'view' => 'tasks', 'overdue' => 1 ), $mine_args ) ) ); ?>" style="<?php echo ! empty( $filters['overdue'] ) ? '' : 'color:var(--c-red-600);'; ?>"><?php CMMS_Icons::e( 'alert-triangle', 14 ); ?> <?php $this->e( 'task.overdue' ); ?></a>
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
            <?php // 1.14.77 — View toggle: List / Kanban / Calendar.
                  // Default to List (preserves existing behavior). The
                  // chosen view persists in localStorage per browser
                  // so users don't have to flip every visit.
                  // 1.14.81 — Added Calendar view. ?>
            <div class="cmms-tasks-view-toggle" role="tablist" aria-label="<?php esc_attr_e( 'תצוגת משימות', 'cmms-light' ); ?>"
                 style="display:inline-flex;background:#eef2f7;border-radius:8px;padding:3px;margin-bottom:14px;flex-wrap:wrap;">
                <button type="button" data-tasks-view="list" role="tab"
                        class="cmms-tasks-view-btn is-active"
                        aria-selected="true"
                        style="border:0;background:#fff;color:#0f172a;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;box-shadow:0 1px 2px rgba(15,23,42,.08);">
                    📋 רשימה
                </button>
                <button type="button" data-tasks-view="kanban" role="tab"
                        class="cmms-tasks-view-btn"
                        aria-selected="false"
                        style="border:0;background:transparent;color:#64748b;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">
                    🗂 Kanban
                </button>
                <button type="button" data-tasks-view="calendar" role="tab"
                        class="cmms-tasks-view-btn"
                        aria-selected="false"
                        style="border:0;background:transparent;color:#64748b;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">
                    📅 חודש
                </button>
            </div>

            <div class="cmms-list" data-tasks-view-pane="list">
                <?php foreach ( $tasks as $task ) $this->task_card( $task, $u ); ?>
            </div>
            <?php $this->inline_status_changer_assets(); ?>

            <div data-tasks-view-pane="kanban" hidden>
                <?php $this->render_kanban_board( $tasks, $u, 'tasks-main' ); ?>
            </div>

            <div data-tasks-view-pane="calendar" hidden>
                <?php $this->render_calendar_view( $u, $filters ); ?>
            </div>

            <script>
            (function () {
                'use strict';
                var buttons = document.querySelectorAll('[data-tasks-view]');
                var panes   = document.querySelectorAll('[data-tasks-view-pane]');
                if (!buttons.length || !panes.length) return;
                var KEY = 'cmms_tasks_view';

                function apply(view) {
                    buttons.forEach(function (b) {
                        var on = (b.getAttribute('data-tasks-view') === view);
                        b.classList.toggle('is-active', on);
                        b.setAttribute('aria-selected', on ? 'true' : 'false');
                        if (on) {
                            b.style.background = '#fff';
                            b.style.color = '#0f172a';
                            b.style.boxShadow = '0 1px 2px rgba(15,23,42,.08)';
                        } else {
                            b.style.background = 'transparent';
                            b.style.color = '#64748b';
                            b.style.boxShadow = 'none';
                        }
                    });
                    panes.forEach(function (p) {
                        p.hidden = (p.getAttribute('data-tasks-view-pane') !== view);
                    });
                    try { localStorage.setItem(KEY, view); } catch (e) {}
                }

                buttons.forEach(function (b) {
                    b.addEventListener('click', function () {
                        var view = b.getAttribute('data-tasks-view');
                        apply(view);
                        // 1.14.81.1: also update the URL so refreshes
                        // and chevron-navigation in the calendar don't
                        // bounce the user back to list view. We use
                        // history.replaceState (not pushState) so the
                        // back button still goes to where it should.
                        try {
                            var u = new URL(window.location.href);
                            if (view === 'list') {
                                u.searchParams.delete('display');
                            } else {
                                u.searchParams.set('display', view);
                            }
                            // When leaving calendar view, clean up its params.
                            if (view !== 'calendar') {
                                u.searchParams.delete('month');
                                u.searchParams.delete('cmms_day');
                            }
                            window.history.replaceState({}, '', u.toString());
                        } catch (e) {}
                    });
                });

                // 1.14.81.1: URL param wins over localStorage. This is
                // the key fix for the broken month-navigation chevrons.
                // When the user clicks ‹ or › in the calendar header,
                // the page reloads with ?display=calendar&month=2026-04.
                // Without this URL check we'd fall back to whatever was
                // last saved (e.g. "list") and the calendar would be
                // hidden, making it look like the navigation "didn't
                // work" — when in fact the data was reloaded but the
                // wrong pane was shown.
                function getUrlParam(name) {
                    var match = window.location.search.match(new RegExp('[?&]' + name + '=([^&]*)'));
                    return match ? decodeURIComponent(match[1]) : null;
                }
                var urlDisplay = getUrlParam('display');
                if (urlDisplay === 'calendar' || urlDisplay === 'kanban' || urlDisplay === 'list') {
                    apply(urlDisplay);
                } else {
                    // Fall back to localStorage.
                    try {
                        var saved = localStorage.getItem(KEY);
                        if (saved === 'kanban' || saved === 'calendar') apply(saved);
                    } catch (e) {}
                }
            })();
            </script>
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
                <?php
                // 1.14.83: Delete button — permission rules:
                //   - Owner / Manager: always
                //   - Technician: only if they CREATED this task
                //   - Reporter: never
                $can_delete = false;
                if ( in_array( $u->role, array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER ), true ) ) {
                    $can_delete = true;
                } elseif ( $u->role === CMMS_Auth::ROLE_TECHNICIAN ) {
                    $can_delete = ( (int) $task->created_by === (int) $u->id );
                }
                if ( $can_delete ) :
                    $delete_nonce = wp_create_nonce( 'cmms_task_delete' );
                ?>
                    <button type="button"
                            class="cmms-btn cmms-btn-danger-soft"
                            data-cmms-task-delete
                            data-task-id="<?php echo (int) $task->id; ?>"
                            data-task-title="<?php echo esc_attr( $task->title ); ?>"
                            data-nonce="<?php echo esc_attr( $delete_nonce ); ?>">
                        <?php CMMS_Icons::e( 'trash-2', 16 ); ?>
                        <span><?php esc_html_e( 'מחק', 'cmms-light' ); ?></span>
                    </button>
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
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>"
                              class="cmms-flex cmms-gap-2"
                              style="flex-wrap:wrap;"
                              data-cmms-status-form>
                            <input type="hidden" name="action" value="cmms_task_status">
                            <input type="hidden" name="task_id" value="<?php echo (int) $task->id; ?>">
                            <?php wp_nonce_field( 'cmms_task_status', 'cmms_task_status_nonce' ); ?>
                            <?php
                            // 1.14.87: GPS-on-complete fields. Browser
                            // populates these via the Geolocation API
                            // before the form submits, when this
                            // account requires location capture.
                            $require_gps = CMMS_Task_Settings::require_location_on_complete( (int) $u->account_id );
                            ?>
                            <input type="hidden" name="completion_lat"      data-cmms-completion-lat value="">
                            <input type="hidden" name="completion_lng"      data-cmms-completion-lng value="">
                            <input type="hidden" name="completion_address"  data-cmms-completion-address value="">
                            <?php foreach ( CMMS_Tasks::statuses() as $k => $v ) : if ( $k === $task->status ) continue; ?>
                                <button type="submit" name="status" value="<?php echo esc_attr( $k ); ?>"
                                        class="cmms-btn cmms-btn-sm"
                                        <?php if ( $require_gps && $k === 'completed' ) echo 'data-cmms-status-needs-gps="1"'; ?>>
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

                <?php
                // 1.14.87: Time & location tracking section. Shows up
                // when there's anything meaningful to display:
                //   - timestamps (created/started/completed)
                //   - durations (response/execution/total)
                //   - completion location (if captured)
                // For brand-new tasks (open, never started), only the
                // created_at is shown.
                $durations = CMMS_Tasks::calculate_durations( $task );
                $has_location = ! empty( $task->completion_lat ) && ! empty( $task->completion_lng );
                ?>
                <section class="cmms-section">
                    <div class="cmms-section-head">
                        <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'clock', 16 ); ?> זמן וביצוע</h3>
                    </div>
                    <div class="cmms-section-body">
                        <div class="cmms-time-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;">
                            <?php if ( $task->created_at ) : ?>
                                <div class="cmms-time-cell">
                                    <div class="cmms-time-label" style="font-size:12px;color:var(--c-muted);">🟡 נפתחה</div>
                                    <div class="cmms-time-value" style="font-weight:600;color:var(--c-text);">
                                        <?php echo esc_html( mysql2date( 'd/m/Y H:i', $task->created_at ) ); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ( $task->started_at ) : ?>
                                <div class="cmms-time-cell">
                                    <div class="cmms-time-label" style="font-size:12px;color:var(--c-muted);">🟠 בטיפול מ-</div>
                                    <div class="cmms-time-value" style="font-weight:600;color:var(--c-text);">
                                        <?php echo esc_html( mysql2date( 'd/m/Y H:i', $task->started_at ) ); ?>
                                    </div>
                                    <?php if ( $durations['response_seconds'] !== null ) : ?>
                                        <div class="cmms-time-meta" style="font-size:11px;color:var(--c-muted);margin-top:2px;">
                                            זמן תגובה: <?php echo esc_html( CMMS_Tasks::format_duration( $durations['response_seconds'] ) ); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ( $task->completed_at ) : ?>
                                <div class="cmms-time-cell">
                                    <div class="cmms-time-label" style="font-size:12px;color:var(--c-muted);">🟢 הושלמה</div>
                                    <div class="cmms-time-value" style="font-weight:600;color:var(--c-text);">
                                        <?php echo esc_html( mysql2date( 'd/m/Y H:i', $task->completed_at ) ); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ( $durations['execution_seconds'] !== null ) : ?>
                                <div class="cmms-time-cell" style="background:#f0fdf4;padding:10px 12px;border-radius:8px;border:1px solid #86efac;">
                                    <div class="cmms-time-label" style="font-size:12px;color:#16a34a;">⏱ זמן ביצוע נטו</div>
                                    <div class="cmms-time-value" style="font-weight:700;color:#15803d;font-size:15px;">
                                        <?php echo esc_html( CMMS_Tasks::format_duration( $durations['execution_seconds'] ) ); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ( $has_location ) : ?>
                            <div style="margin-top:18px;padding:12px;background:#eff6ff;border-radius:8px;border:1px solid #bfdbfe;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                    <span style="font-size:18px;">📍</span>
                                    <strong style="color:#1e40af;font-size:13px;">מיקום סגירת המשימה</strong>
                                    <?php if ( ! empty( $task->completion_location_source ) ) : ?>
                                        <span style="font-size:11px;color:#3b82f6;margin-inline-start:auto;">
                                            <?php
                                            $src_label = array( 'browser' => 'דפדפן', 'telegram' => 'טלגרם', 'manual' => 'ידני' );
                                            echo esc_html( $src_label[ $task->completion_location_source ] ?? $task->completion_location_source );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ( ! empty( $task->completion_address ) ) : ?>
                                    <div style="font-size:13px;color:#1e3a8a;margin-bottom:6px;padding-inline-start:26px;">
                                        <?php echo esc_html( $task->completion_address ); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="padding-inline-start:26px;">
                                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo (float) $task->completion_lat; ?>,<?php echo (float) $task->completion_lng; ?>"
                                       target="_blank"
                                       rel="noopener"
                                       style="display:inline-flex;align-items:center;gap:4px;color:#2563eb;font-size:13px;font-weight:600;text-decoration:none;">
                                        🗺️ פתח ב-Google Maps
                                    </a>
                                    <span style="font-size:11px;color:#6b7280;margin-inline-start:12px;">
                                        <?php echo esc_html( number_format( (float) $task->completion_lat, 5 ) ); ?>,
                                        <?php echo esc_html( number_format( (float) $task->completion_lng, 5 ) ); ?>
                                    </span>
                                </div>
                            </div>
                        <?php elseif ( $task->status === 'completed' && CMMS_Task_Settings::require_location_on_complete( (int) $u->account_id ) ) : ?>
                            <div style="margin-top:18px;padding:12px;background:#fef3c7;border-radius:8px;border:1px solid #fbbf24;">
                                <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#92400e;">
                                    <span>⚠️</span>
                                    <span>המשימה נסגרה ללא מיקום</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php
                // 1.14.87: GPS capture JS. Only loaded if the account
                // requires GPS-on-complete. Hooks the status form,
                // intercepts "complete" submits, asks for geo location,
                // and reverse-geocodes via the browser's built-in
                // PositionAddress (best-effort).
                if ( CMMS_Task_Settings::require_location_on_complete( (int) $u->account_id ) ) : ?>
                <script>
                (function () {
                    'use strict';
                    var form = document.querySelector('[data-cmms-status-form]');
                    if (!form) return;
                    var latIn  = form.querySelector('[data-cmms-completion-lat]');
                    var lngIn  = form.querySelector('[data-cmms-completion-lng]');
                    var addrIn = form.querySelector('[data-cmms-completion-address]');

                    var DEBUG = true; // 1.14.87.2: temporary debug logging
                    function log(msg) {
                        if (DEBUG && window.console) console.log('[CMMS GPS]', msg);
                    }

                    // Track if we already injected the GPS, so we don't loop.
                    var gpsInjected = false;
                    var submitterStatus = null;
                    var watchdogTimer = null;

                    // Capture which button was actually clicked.
                    form.querySelectorAll('button[type=submit]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            submitterStatus = btn.value;
                            log('Button clicked, status=' + submitterStatus);
                        });
                    });

                    // Helper: programmatically submit the form WITH the
                    // status value of the button that was clicked.
                    function submitWithStatus(status) {
                        log('submitWithStatus(' + status + ')');
                        if (watchdogTimer) { clearTimeout(watchdogTimer); watchdogTimer = null; }
                        var statusInput = form.querySelector('input[type=hidden][name=status]');
                        if (!statusInput) {
                            statusInput = document.createElement('input');
                            statusInput.type = 'hidden';
                            statusInput.name = 'status';
                            form.appendChild(statusInput);
                        }
                        statusInput.value = status;
                        gpsInjected = true;
                        form.submit();
                    }

                    function restoreButton(btn) {
                        if (!btn) return;
                        btn.disabled = false;
                        if (btn.dataset.origText) btn.textContent = btn.dataset.origText;
                    }

                    function askWithoutLocation(btn) {
                        var ok = window.confirm(
                            'לא ניתן לקבל את המיקום שלך.\n\n' +
                            'האם להמשיך בסגירת המשימה ללא מיקום?'
                        );
                        restoreButton(btn);
                        if (ok) submitWithStatus('completed');
                    }

                    form.addEventListener('submit', function (e) {
                        log('submit fired, submitterStatus=' + submitterStatus + ', gpsInjected=' + gpsInjected);

                        // Only intercept the "completed" submit, and only once.
                        if (submitterStatus !== 'completed' || gpsInjected) {
                            log('Allow submit through (not completed or already injected)');
                            return;
                        }

                        // No geolocation API? Just submit as-is.
                        if (!('geolocation' in navigator)) {
                            log('No geolocation API — submitting without');
                            return;
                        }

                        e.preventDefault();
                        log('Intercepted submit; asking for GPS...');

                        var btn = form.querySelector('button[value="completed"]');
                        if (btn) {
                            btn.disabled = true;
                            btn.dataset.origText = btn.textContent;
                            btn.textContent = '📍 מקבל מיקום...';
                        }

                        // 1.14.87.1 bugfix: watchdog. Some browsers
                        // never call success OR error if GPS hangs.
                        // After 20s, fall back to "complete without
                        // location?" confirm dialog.
                        watchdogTimer = setTimeout(function () {
                            log('Watchdog fired — GPS never returned');
                            watchdogTimer = null;
                            askWithoutLocation(btn);
                        }, 20000);

                        navigator.geolocation.getCurrentPosition(function (pos) {
                            log('GPS success: ' + pos.coords.latitude + ', ' + pos.coords.longitude);
                            if (watchdogTimer) { clearTimeout(watchdogTimer); watchdogTimer = null; }
                            latIn.value = pos.coords.latitude.toFixed(7);
                            lngIn.value = pos.coords.longitude.toFixed(7);
                            addrIn.value = '';
                            submitWithStatus('completed');
                        }, function (err) {
                            log('GPS error: code=' + err.code + ' msg=' + err.message);
                            if (watchdogTimer) { clearTimeout(watchdogTimer); watchdogTimer = null; }
                            askWithoutLocation(btn);
                        }, {
                            enableHighAccuracy: true,
                            timeout: 15000,
                            maximumAge: 60000
                        });
                    });
                })();
                </script>
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

                <?php
                // 1.15.3: Signatures section. Shows existing signatures
                // (customer + technician) and offers a button to capture
                // a new one via a modal canvas.
                $signatures = CMMS_Signatures::list_for_task( $task->id );
                $can_sign = $can_status; // Same permission as participating
                ?>
                <section class="cmms-section">
                    <div class="cmms-section-head">
                        <h3 class="cmms-section-title">
                            <?php CMMS_Icons::e( 'edit-3', 16 ); ?>
                            <?php esc_html_e( 'חתימות', 'cmms-light' ); ?>
                            <?php if ( ! empty( $signatures ) ) : ?>
                                <span style="font-size:11px;color:#64748b;font-weight:normal;">(<?php echo count( $signatures ); ?>)</span>
                            <?php endif; ?>
                        </h3>
                    </div>
                    <div class="cmms-section-body">
                        <p style="font-size:12px;color:#64748b;margin:0 0 12px;">
                            <?php esc_html_e( 'איסוף חתימה של לקוח או טכנאי כעדות שהעבודה בוצעה.', 'cmms-light' ); ?>
                        </p>

                        <?php if ( empty( $signatures ) ) : ?>
                            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:20px;text-align:center;color:#64748b;font-size:12px;margin-bottom:12px;">
                                <?php esc_html_e( 'עדיין לא נאספו חתימות במשימה זו.', 'cmms-light' ); ?>
                            </div>
                        <?php else : ?>
                            <div class="cmms-signatures-list" style="display:flex;flex-direction:column;gap:10px;margin-bottom:12px;">
                                <?php foreach ( $signatures as $sig ) :
                                    $type_label = $sig->signer_type === CMMS_Signatures::TYPE_TECHNICIAN
                                        ? __( 'חתימת טכנאי', 'cmms-light' )
                                        : __( 'חתימת לקוח', 'cmms-light' );
                                    $type_color = $sig->signer_type === CMMS_Signatures::TYPE_TECHNICIAN
                                        ? '#0369a1'
                                        : '#15803d';
                                ?>
                                    <div class="cmms-signature-card"
                                         data-signature-id="<?php echo (int) $sig->id; ?>"
                                         style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;gap:8px;flex-wrap:wrap;">
                                            <div>
                                                <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:<?php echo esc_attr( $type_color ); ?>;color:#fff;">
                                                    <?php echo esc_html( $type_label ); ?>
                                                </span>
                                                <div style="margin-top:6px;font-weight:600;font-size:13px;color:#0f172a;">
                                                    <?php echo esc_html( $sig->signer_name ); ?>
                                                </div>
                                                <?php if ( $sig->signer_role ) : ?>
                                                    <div style="font-size:11px;color:#64748b;">
                                                        <?php echo esc_html( $sig->signer_role ); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">
                                                    <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sig->signed_at ) ); ?>
                                                    <?php if ( $sig->signed_ip ) : ?>
                                                        · IP: <?php echo esc_html( $sig->signed_ip ); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ( $can_sign ) : ?>
                                                <button type="button"
                                                        class="cmms-btn cmms-btn-sm cmms-btn-ghost cmms-signature-delete-btn"
                                                        data-signature-id="<?php echo (int) $sig->id; ?>"
                                                        style="color:#b91c1c;font-size:11px;padding:4px 8px;">
                                                    🗑
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px;text-align:center;">
                                            <img src="<?php echo esc_attr( $sig->signature_data ); ?>"
                                                 alt="<?php echo esc_attr( $sig->signer_name ); ?>"
                                                 style="max-width:100%;max-height:120px;object-fit:contain;">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $can_sign ) : ?>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button type="button"
                                        class="cmms-btn cmms-btn-primary cmms-signature-open-btn"
                                        data-signer-type="customer"
                                        data-task-id="<?php echo (int) $task->id; ?>"
                                        style="flex:1;min-width:140px;">
                                    👤 <?php esc_html_e( 'החתמת לקוח', 'cmms-light' ); ?>
                                </button>
                                <button type="button"
                                        class="cmms-btn cmms-btn-secondary cmms-signature-open-btn"
                                        data-signer-type="technician"
                                        data-task-id="<?php echo (int) $task->id; ?>"
                                        style="flex:1;min-width:140px;">
                                    🔧 <?php esc_html_e( 'חתימת טכנאי', 'cmms-light' ); ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php
                // Signature capture modal — hidden by default, opened
                // by the buttons above. Single modal handles both types
                // (customer/technician); the trigger button passes the
                // type via data-signer-type.
                if ( $can_sign ) :
                    $add_nonce    = wp_create_nonce( 'cmms_signature_add' );
                    $delete_nonce = wp_create_nonce( 'cmms_signature_delete' );
                ?>
                <div class="cmms-signature-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:16px;">
                    <div style="background:#fff;border-radius:12px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,0.3);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                            <h3 style="margin:0;font-size:18px;color:#0f172a;" class="cmms-signature-modal-title">
                                <?php esc_html_e( 'איסוף חתימה', 'cmms-light' ); ?>
                            </h3>
                            <button type="button" class="cmms-signature-close-btn"
                                    style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;padding:0;width:28px;height:28px;">✕</button>
                        </div>

                        <div class="cmms-field cmms-mb-3">
                            <label class="cmms-field-label">
                                <?php esc_html_e( 'שם מלא', 'cmms-light' ); ?> <span style="color:#dc2626;">*</span>
                            </label>
                            <input type="text" class="cmms-input cmms-signature-name" placeholder="<?php esc_attr_e( 'לדוגמה: ישראל ישראלי', 'cmms-light' ); ?>" required>
                        </div>

                        <div class="cmms-field cmms-mb-3">
                            <label class="cmms-field-label">
                                <?php esc_html_e( 'תפקיד / חברה (אופציונלי)', 'cmms-light' ); ?>
                            </label>
                            <input type="text" class="cmms-input cmms-signature-role" placeholder="<?php esc_attr_e( 'לדוגמה: מנהל אחזקה / חברת אבק נכסים', 'cmms-light' ); ?>">
                        </div>

                        <div class="cmms-field cmms-mb-3">
                            <label class="cmms-field-label">
                                <?php esc_html_e( 'חתימה', 'cmms-light' ); ?> <span style="color:#dc2626;">*</span>
                            </label>
                            <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:8px;padding:8px;">
                                <canvas class="cmms-signature-canvas"
                                        width="800" height="300"
                                        tabindex="-1"
                                        style="display:block;width:100%;height:auto;background:#fff;border-radius:4px;cursor:crosshair;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;-webkit-tap-highlight-color:transparent;"></canvas>
                            </div>
                            <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:11px;color:#64748b;">
                                <span><?php esc_html_e( 'חתום באצבע או בעכבר', 'cmms-light' ); ?></span>
                                <button type="button" class="cmms-signature-clear-btn"
                                        style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:11px;text-decoration:underline;">
                                    <?php esc_html_e( '🗑 נקה ציור', 'cmms-light' ); ?>
                                </button>
                            </div>
                        </div>

                        <div class="cmms-signature-error" style="display:none;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;padding:8px 10px;font-size:12px;margin-bottom:12px;"></div>

                        <div style="display:flex;gap:8px;">
                            <button type="button" class="cmms-btn cmms-btn-secondary cmms-signature-close-btn" style="flex:1;">
                                <?php esc_html_e( 'ביטול', 'cmms-light' ); ?>
                            </button>
                            <button type="button" class="cmms-btn cmms-btn-primary cmms-signature-save-btn" style="flex:2;">
                                ✓ <?php esc_html_e( 'שמור חתימה', 'cmms-light' ); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <script>
                (function() {
                    if ( window.__cmmsSignatureBound ) return;
                    window.__cmmsSignatureBound = true;

                    var ADD_NONCE    = <?php echo wp_json_encode( $add_nonce ); ?>;
                    var DELETE_NONCE = <?php echo wp_json_encode( $delete_nonce ); ?>;
                    var AJAX_URL     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                    var TASK_ID      = <?php echo (int) $task->id; ?>;

                    var modal       = document.querySelector('.cmms-signature-modal');
                    var canvas      = modal ? modal.querySelector('.cmms-signature-canvas') : null;
                    var nameInput   = modal ? modal.querySelector('.cmms-signature-name')   : null;
                    var roleInput   = modal ? modal.querySelector('.cmms-signature-role')   : null;
                    var errorBox    = modal ? modal.querySelector('.cmms-signature-error')  : null;
                    var titleEl     = modal ? modal.querySelector('.cmms-signature-modal-title') : null;

                    var currentSignerType = 'customer';
                    var ctx = null;
                    var isDrawing = false;
                    var hasDrawn = false;
                    var lastX = 0, lastY = 0;

                    if ( canvas ) {
                        ctx = canvas.getContext('2d');
                        setupCanvas();
                    }

                    // ── Open modal ──
                    document.addEventListener('click', function(e) {
                        var openBtn = e.target.closest('.cmms-signature-open-btn');
                        if ( openBtn ) {
                            e.preventDefault();
                            currentSignerType = openBtn.getAttribute('data-signer-type') || 'customer';
                            if ( titleEl ) {
                                titleEl.textContent = currentSignerType === 'technician'
                                    ? 'חתימת טכנאי'
                                    : 'איסוף חתימה (לקוח)';
                            }
                            // Reset form state
                            if ( nameInput ) nameInput.value = '';
                            if ( roleInput ) roleInput.value = '';
                            if ( errorBox ) errorBox.style.display = 'none';
                            clearCanvas();
                            modal.style.display = 'flex';
                            // Focus name field for fast data entry
                            setTimeout(function() { if ( nameInput ) nameInput.focus(); }, 100);
                            return;
                        }

                        // ── Close modal ──
                        var closeBtn = e.target.closest('.cmms-signature-close-btn');
                        if ( closeBtn ) {
                            e.preventDefault();
                            modal.style.display = 'none';
                            return;
                        }

                        // ── Clear canvas ──
                        var clearBtn = e.target.closest('.cmms-signature-clear-btn');
                        if ( clearBtn ) {
                            e.preventDefault();
                            clearCanvas();
                            return;
                        }

                        // ── Save signature ──
                        var saveBtn = e.target.closest('.cmms-signature-save-btn');
                        if ( saveBtn ) {
                            e.preventDefault();
                            saveSignature(saveBtn);
                            return;
                        }

                        // ── Delete existing signature ──
                        var deleteBtn = e.target.closest('.cmms-signature-delete-btn');
                        if ( deleteBtn ) {
                            e.preventDefault();
                            if ( ! confirm('למחוק את החתימה? פעולה זו אינה הפיכה.') ) return;
                            deleteSignature( deleteBtn.getAttribute('data-signature-id'), deleteBtn );
                            return;
                        }
                    });

                    // ── Canvas setup ──
                    function setupCanvas() {
                        ctx.lineWidth = 2.5;
                        ctx.lineCap = 'round';
                        ctx.lineJoin = 'round';
                        ctx.strokeStyle = '#0f172a';

                        // Mouse events
                        canvas.addEventListener('mousedown', function(e) {
                            startDraw(getCoords(e));
                        });
                        canvas.addEventListener('mousemove', function(e) {
                            if ( isDrawing ) draw(getCoords(e));
                        });
                        canvas.addEventListener('mouseup', endDraw);
                        canvas.addEventListener('mouseleave', endDraw);

                        // Touch events (mobile — the main use case)
                        canvas.addEventListener('touchstart', function(e) {
                            e.preventDefault();
                            startDraw(getCoords(e.touches[0]));
                        }, { passive: false });
                        canvas.addEventListener('touchmove', function(e) {
                            e.preventDefault();
                            if ( isDrawing ) draw(getCoords(e.touches[0]));
                        }, { passive: false });
                        canvas.addEventListener('touchend', function(e) {
                            e.preventDefault();
                            endDraw();
                        }, { passive: false });
                    }

                    function getCoords(evt) {
                        var rect = canvas.getBoundingClientRect();
                        // Translate display coords to canvas coords (the
                        // canvas is rendered at width:100% but the internal
                        // resolution is 800x300 — we need to scale).
                        var scaleX = canvas.width / rect.width;
                        var scaleY = canvas.height / rect.height;
                        return {
                            x: (evt.clientX - rect.left) * scaleX,
                            y: (evt.clientY - rect.top) * scaleY
                        };
                    }

                    function startDraw(coords) {
                        // Close mobile keyboard if it was open from
                        // the name/role fields — otherwise it can
                        // overlap the canvas and prevent signing.
                        if ( document.activeElement && document.activeElement.blur ) {
                            document.activeElement.blur();
                        }
                        isDrawing = true;
                        hasDrawn = true;
                        lastX = coords.x;
                        lastY = coords.y;
                        // Draw a dot for taps
                        ctx.beginPath();
                        ctx.arc(coords.x, coords.y, 1.3, 0, Math.PI * 2);
                        ctx.fillStyle = '#0f172a';
                        ctx.fill();
                    }

                    function draw(coords) {
                        ctx.beginPath();
                        ctx.moveTo(lastX, lastY);
                        ctx.lineTo(coords.x, coords.y);
                        ctx.stroke();
                        lastX = coords.x;
                        lastY = coords.y;
                    }

                    function endDraw() {
                        isDrawing = false;
                    }

                    function clearCanvas() {
                        ctx.fillStyle = '#fff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        ctx.strokeStyle = '#0f172a';
                        hasDrawn = false;
                    }

                    function showError(msg) {
                        if ( ! errorBox ) return;
                        errorBox.textContent = msg;
                        errorBox.style.display = 'block';
                    }

                    function hideError() {
                        if ( errorBox ) errorBox.style.display = 'none';
                    }

                    // ── Save signature via AJAX ──
                    function saveSignature(btn) {
                        hideError();

                        var name = (nameInput.value || '').trim();
                        if ( ! name ) {
                            showError('יש למלא שם מלא של החותם');
                            nameInput.focus();
                            return;
                        }
                        if ( ! hasDrawn ) {
                            showError('יש לחתום על האזור המסומן לפני שמירה');
                            return;
                        }

                        var dataUrl = canvas.toDataURL('image/png');

                        var originalText = btn.innerHTML;
                        btn.disabled = true;
                        btn.innerHTML = 'שומר...';

                        var formData = new FormData();
                        formData.append('action', 'cmms_signature_add');
                        formData.append('_wpnonce', ADD_NONCE);
                        formData.append('task_id', TASK_ID);
                        formData.append('signer_type', currentSignerType);
                        formData.append('signer_name', name);
                        formData.append('signer_role', (roleInput.value || '').trim());
                        formData.append('signature_data', dataUrl);

                        fetch(AJAX_URL, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: formData
                        }).then(function(r) { return r.json(); }).then(function(json) {
                            if ( json && json.success ) {
                                // Reload page to show the new signature
                                // properly (with all its metadata, log
                                // entries, etc).
                                location.reload();
                            } else {
                                var msg = (json && json.data && json.data.message) || 'שגיאה לא ידועה';
                                showError(msg);
                            }
                        }).catch(function() {
                            showError('שגיאת רשת - אנא נסה שוב');
                        }).finally(function() {
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                        });
                    }

                    // ── Delete signature via AJAX ──
                    function deleteSignature(sigId, btn) {
                        var originalText = btn.innerHTML;
                        btn.disabled = true;
                        btn.innerHTML = '...';

                        var formData = new FormData();
                        formData.append('action', 'cmms_signature_delete');
                        formData.append('_wpnonce', DELETE_NONCE);
                        formData.append('signature_id', sigId);

                        fetch(AJAX_URL, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: formData
                        }).then(function(r) { return r.json(); }).then(function(json) {
                            if ( json && json.success ) {
                                // Remove the card from the DOM
                                var card = btn.closest('.cmms-signature-card');
                                if ( card ) card.remove();
                            } else {
                                alert('שגיאה במחיקה: ' + ((json && json.data && json.data.message) || 'לא ידוע'));
                                btn.disabled = false;
                                btn.innerHTML = originalText;
                            }
                        }).catch(function() {
                            alert('שגיאת רשת');
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                        });
                    }
                })();
                </script>
                <?php endif; // can_sign for modal ?>

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

        <?php // 1.14.83: Delete button handler + modal styling.
              // Self-contained — no global JS dependencies. ?>
        <style>
        .cmms-btn-danger-soft {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .cmms-btn-danger-soft:hover {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }
        .cmms-task-delete-modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            padding: 16px;
        }
        .cmms-task-delete-modal.is-open { display: flex; }
        .cmms-task-delete-card {
            background: white;
            border-radius: 14px;
            max-width: 460px;
            width: 100%;
            padding: 24px;
            box-shadow: 0 24px 48px rgba(0,0,0,0.2);
            direction: rtl;
        }
        .cmms-task-delete-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #fee2e2;
            color: #b91c1c;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 14px;
        }
        .cmms-task-delete-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
        }
        .cmms-task-delete-body {
            font-size: 14px;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 6px;
        }
        .cmms-task-delete-task-name {
            font-weight: 600;
            color: #0f172a;
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 6px;
            display: inline-block;
            margin: 6px 0;
            word-break: break-word;
        }
        .cmms-task-delete-note {
            font-size: 12px;
            color: #64748b;
            background: #f8fafc;
            border-right: 3px solid #cbd5e1;
            padding: 10px 12px;
            border-radius: 6px;
            margin: 16px 0;
        }
        .cmms-task-delete-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .cmms-task-delete-actions .cmms-btn { min-height: 40px; }
        @media (max-width: 480px) {
            .cmms-task-delete-card { padding: 20px; }
            .cmms-task-delete-actions { flex-direction: column-reverse; }
            .cmms-task-delete-actions .cmms-btn { width: 100%; justify-content: center; }
        }
        </style>

        <div class="cmms-task-delete-modal" data-cmms-task-delete-modal role="dialog" aria-modal="true" aria-labelledby="cmms-task-delete-title">
            <div class="cmms-task-delete-card">
                <div class="cmms-task-delete-icon">🗑</div>
                <h3 class="cmms-task-delete-title" id="cmms-task-delete-title"><?php esc_html_e( 'מחיקת משימה', 'cmms-light' ); ?></h3>
                <p class="cmms-task-delete-body">
                    <?php esc_html_e( 'האם למחוק את המשימה?', 'cmms-light' ); ?>
                </p>
                <div class="cmms-task-delete-task-name" data-cmms-task-delete-name></div>
                <div class="cmms-task-delete-note">
                    <?php esc_html_e( 'המשימה תוסר מהרשימות, אך תישמר במערכת לצורך שחזור ובקרה.', 'cmms-light' ); ?>
                </div>
                <div class="cmms-task-delete-actions">
                    <button type="button" class="cmms-btn" data-cmms-task-delete-cancel><?php esc_html_e( 'ביטול', 'cmms-light' ); ?></button>
                    <button type="button" class="cmms-btn cmms-btn-danger-soft" data-cmms-task-delete-confirm>
                        <?php CMMS_Icons::e( 'trash-2', 16 ); ?>
                        <span><?php esc_html_e( 'מחק משימה', 'cmms-light' ); ?></span>
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function () {
            'use strict';
            var trigger = document.querySelector('[data-cmms-task-delete]');
            var modal   = document.querySelector('[data-cmms-task-delete-modal]');
            if (!trigger || !modal) return;

            var nameEl    = modal.querySelector('[data-cmms-task-delete-name]');
            var cancelBtn = modal.querySelector('[data-cmms-task-delete-cancel]');
            var confirmBtn = modal.querySelector('[data-cmms-task-delete-confirm]');

            var AJAX_URL  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var TASKS_URL = <?php echo wp_json_encode( $this->url( array( 'view' => 'tasks' ) ) ); ?>;

            function openModal() {
                nameEl.textContent = trigger.getAttribute('data-task-title') || '';
                modal.classList.add('is-open');
            }
            function closeModal() {
                modal.classList.remove('is-open');
            }

            trigger.addEventListener('click', openModal);
            cancelBtn.addEventListener('click', closeModal);

            // Click outside the card closes.
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
            });

            confirmBtn.addEventListener('click', function () {
                confirmBtn.disabled = true;
                cancelBtn.disabled = true;
                var body = new URLSearchParams();
                body.set('action',  'cmms_task_delete');
                body.set('nonce',   trigger.getAttribute('data-nonce'));
                body.set('task_id', trigger.getAttribute('data-task-id'));
                fetch(AJAX_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json().catch(function () { return null; }); })
                  .then(function (res) {
                      if (res && res.success) {
                          // Navigate back to the task list — the deleted
                          // task won't appear there anymore.
                          window.location.href = TASKS_URL;
                      } else {
                          var msg = (res && res.data && res.data.message) ? res.data.message : 'מחיקת המשימה נכשלה';
                          alert(msg);
                          confirmBtn.disabled = false;
                          cancelBtn.disabled = false;
                      }
                  }).catch(function () {
                      alert('שגיאת רשת');
                      confirmBtn.disabled = false;
                      cancelBtn.disabled = false;
                  });
            });
        })();
        </script>
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
                "SELECT * FROM $t_tasks WHERE asset_id = %d AND account_id = %d AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 20",
                $asset->id, $u->account_id
            ) );
            $task_count_total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $t_tasks WHERE asset_id = %d AND account_id = %d AND deleted_at IS NULL",
                $asset->id, $u->account_id
            ) );
        } else {
            $tasks = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $t_tasks
                  WHERE asset_id = %d AND account_id = %d
                    AND deleted_at IS NULL
                    AND ( assigned_to = %d OR manager_id = %d OR created_by = %d )
                  ORDER BY created_at DESC LIMIT 20",
                $asset->id, $u->account_id, $u->id, $u->id, $u->id
            ) );
            $task_count_total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $t_tasks
                  WHERE asset_id = %d AND account_id = %d
                    AND deleted_at IS NULL
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
            <div class="cmms-section-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'list', 16 ); ?> <?php $this->e( 'asset.tasks' ); ?></h3>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <?php if ( $task_count_total > count( $tasks ) ) : ?>
                        <span class="cmms-muted cmms-text-sm"><?php echo esc_html( sprintf( $this->t( 'asset.tasks_showing' ), count( $tasks ), $task_count_total ) ); ?></span>
                    <?php endif; ?>
                    <?php // 1.14.76 — View toggle. Default = list (existing behavior).
                          // The toggle is rendered only when there's at least one task.
                          if ( ! empty( $tasks ) ) : ?>
                    <div class="cmms-asset-tasks-toggle" role="tablist" aria-label="<?php esc_attr_e( 'תצוגת משימות', 'cmms-light' ); ?>">
                        <button type="button"
                                class="cmms-asset-tasks-toggle-btn is-active"
                                data-asset-view="list"
                                role="tab"
                                aria-selected="true">
                            <?php esc_html_e( '📋 רשימה', 'cmms-light' ); ?>
                        </button>
                        <button type="button"
                                class="cmms-asset-tasks-toggle-btn"
                                data-asset-view="gantt"
                                role="tab"
                                aria-selected="false">
                            <?php esc_html_e( '📊 גאנט', 'cmms-light' ); ?>
                        </button>
                        <button type="button"
                                class="cmms-asset-tasks-toggle-btn"
                                data-asset-view="kanban"
                                role="tab"
                                aria-selected="false">
                            <?php esc_html_e( '🗂 Kanban', 'cmms-light' ); ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="cmms-section-body">
                <?php if ( empty( $tasks ) ) : ?>
                    <p class="cmms-muted cmms-text-sm" style="margin:0;"><?php $this->e( 'asset.no_tasks' ); ?></p>
                <?php else : ?>
                    <!-- 1.14.76: List view (the existing rendering, unchanged). -->
                    <div class="cmms-list cmms-asset-tasks-list" data-asset-view-pane="list">
                        <?php foreach ( $tasks as $task ) $this->task_card( $task, $u ); ?>
                    </div>
                    <?php $this->inline_status_changer_assets(); ?>

                    <!-- 1.14.76: Gantt view, hidden by default. -->
                    <div class="cmms-asset-tasks-gantt" data-asset-view-pane="gantt" hidden>
                        <?php $this->render_asset_tasks_gantt( $tasks, $u ); ?>
                    </div>

                    <!-- 1.14.77: Kanban view, hidden by default. -->
                    <div class="cmms-asset-tasks-kanban" data-asset-view-pane="kanban" hidden>
                        <?php
                        // Use a per-asset instance id so multiple boards on
                        // the same page (Tasks + per-asset) get unique DOMs.
                        $asset_id_for_instance = isset( $asset->id ) ? (int) $asset->id : 0;
                        $this->render_kanban_board( $tasks, $u, 'asset-' . $asset_id_for_instance );
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php // 1.14.76 — Inline CSS + JS for the view toggle + Gantt.
              // Scoped to .cmms-asset-tasks-* so nothing leaks to other pages.
              // Only emitted when there are tasks. ?>
        <?php if ( ! empty( $tasks ) ) : ?>
        <style>
        /* ─── Toggle (list / gantt) ─── */
        .cmms-asset-tasks-toggle {
            display: inline-flex;
            background: #eef2f7;
            border-radius: 8px;
            padding: 3px;
            gap: 0;
        }
        .cmms-asset-tasks-toggle-btn {
            border: 0;
            background: transparent;
            color: #64748b;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s ease, color .15s ease;
            font-family: inherit;
        }
        .cmms-asset-tasks-toggle-btn:hover { color: #0f172a; }
        .cmms-asset-tasks-toggle-btn.is-active {
            background: #fff;
            color: #0f172a;
            box-shadow: 0 1px 2px rgba(15,23,42,.08);
        }

        /* ─── Gantt chart ─── */
        .cmms-gantt {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .cmms-gantt-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .cmms-gantt-inner {
            position: relative;
            min-width: 100%;
        }
        .cmms-gantt-header {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .cmms-gantt-header-labels {
            flex: none;
            width: 200px;
            padding: 10px 14px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            border-inline-end: 1px solid #e2e8f0;
            position: sticky;
            inset-inline-start: 0;
            background: #f8fafc;
            z-index: 2;
        }
        .cmms-gantt-header-timeline {
            flex: 1;
            position: relative;
            min-width: 600px;
        }
        .cmms-gantt-tick {
            position: absolute;
            top: 0;
            bottom: 0;
            border-inline-start: 1px solid #e2e8f0;
            padding: 10px 6px 0;
            font-size: 11px;
            color: #94a3b8;
            white-space: nowrap;
        }
        .cmms-gantt-row {
            display: flex;
            border-bottom: 1px solid #f1f5f9;
            min-height: 44px;
        }
        .cmms-gantt-row:last-child { border-bottom: 0; }
        .cmms-gantt-row:hover .cmms-gantt-row-label { background: #f8fafc; }
        .cmms-gantt-row-label {
            flex: none;
            width: 200px;
            padding: 10px 14px;
            font-size: 13px;
            color: #0f172a;
            border-inline-end: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 6px;
            position: sticky;
            inset-inline-start: 0;
            background: #fff;
            z-index: 1;
            min-width: 0;
        }
        .cmms-gantt-row-label-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 500;
            min-width: 0;
        }
        .cmms-gantt-row-track {
            flex: 1;
            position: relative;
            min-width: 600px;
        }
        .cmms-gantt-row-track::before {
            /* Light vertical grid lines mirror the header ticks */
            content: '';
            position: absolute;
            inset: 0;
            background-image: linear-gradient(to right, #f1f5f9 1px, transparent 1px);
            background-size: var(--cmms-gantt-tick-px, 100px) 100%;
            pointer-events: none;
        }
        .cmms-gantt-bar {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            height: 22px;
            border-radius: 6px;
            min-width: 6px;
            background: #3b82f6;
            display: flex;
            align-items: center;
            padding: 0 8px;
            font-size: 11px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
            transition: filter .15s ease, transform .1s ease;
            white-space: nowrap;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15,23,42,.1);
        }
        .cmms-gantt-bar:hover { filter: brightness(1.08); }
        .cmms-gantt-bar:active { transform: translateY(-50%) scale(.98); }
        .cmms-gantt-bar.is-open       { background: #3b82f6; }
        .cmms-gantt-bar.is-progress   { background: #ea580c; }
        .cmms-gantt-bar.is-waiting    { background: #eab308; color:#451a03; }
        .cmms-gantt-bar.is-completed  { background: #10b981; }
        .cmms-gantt-bar.is-overdue    { background: #ef4444; }
        .cmms-gantt-bar-warn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.25);
            border-radius: 4px;
            padding: 0 4px;
            margin-inline-start: 4px;
            font-size: 10px;
        }

        /* "today" marker - vertical orange line through whole chart */
        .cmms-gantt-today {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #ea580c;
            z-index: 3;
            pointer-events: none;
        }
        .cmms-gantt-today::before {
            content: 'היום';
            position: absolute;
            top: 4px;
            inset-inline-start: 4px;
            background: #ea580c;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 4px;
            white-space: nowrap;
        }

        .cmms-gantt-legend {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            padding: 10px 14px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 12px;
            color: #64748b;
        }
        .cmms-gantt-legend-item { display: inline-flex; align-items: center; gap: 5px; }
        .cmms-gantt-legend-dot {
            width: 10px; height: 10px; border-radius: 3px; display: inline-block;
        }

        /* Mobile: tighter labels, ensure horizontal scroll works */
        @media (max-width: 640px) {
            .cmms-gantt-header-labels,
            .cmms-gantt-row-label { width: 140px; padding: 8px 10px; font-size: 12px; }
            .cmms-gantt-header-timeline,
            .cmms-gantt-row-track { min-width: 480px; }
            .cmms-gantt-bar { height: 20px; font-size: 10px; padding: 0 6px; }
        }
        </style>
        <script>
        (function () {
            'use strict';
            var section = document.getElementById('cmms-asset-tasks');
            if (!section) return;
            var buttons = section.querySelectorAll('[data-asset-view]');
            var panes   = section.querySelectorAll('[data-asset-view-pane]');
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var view = btn.getAttribute('data-asset-view');
                    buttons.forEach(function (b) {
                        var on = (b === btn);
                        b.classList.toggle('is-active', on);
                        b.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    panes.forEach(function (p) {
                        p.hidden = (p.getAttribute('data-asset-view-pane') !== view);
                    });
                });
            });
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    /**
     * 1.14.76 — Render a read-only Gantt chart of the tasks attached to
     * an asset. Hidden by default; revealed by the "📊 גאנט" toggle.
     *
     * Time range for each task:
     *   start = created_at
     *   end   = if completed → completed_at
     *           else if has due_date → due_date
     *           else (open with no due) → "today" + an ⚠ marker
     *
     * The chart range expands to cover the earliest start and the latest
     * end (or today, whichever is later). We bucket the timeline into
     * sensible tick widths so the bars are visible without zooming.
     *
     * SVG-free implementation: pure CSS positioning so we keep the
     * same render pipeline and avoid pulling in a chart library. The
     * scrollable container guarantees the bars stay readable on mobile.
     *
     * Click on a bar = scroll-into-view of the matching task card in
     * the list pane and flip the toggle back to "list" — so the user
     * gets the full task details without leaving the asset page.
     */
    private function render_asset_tasks_gantt( $tasks, $u ) {
        if ( empty( $tasks ) ) return;

        // ─── Compute time bounds ────────────────────────────────────
        $now_ts = current_time( 'timestamp' );
        $min_ts = null;
        $max_ts = null;

        $task_rows = array();
        foreach ( $tasks as $task ) {
            $start_ts = ! empty( $task->created_at ) ? strtotime( $task->created_at ) : null;
            if ( ! $start_ts ) continue;

            $is_done = in_array( $task->status, array( 'completed', 'closed' ), true );
            $end_ts  = null;
            $no_due  = false;

            if ( $is_done ) {
                // Prefer completed_at; fall back to updated_at or due_date.
                if ( ! empty( $task->completed_at ) ) {
                    $end_ts = strtotime( $task->completed_at );
                } elseif ( ! empty( $task->due_date ) ) {
                    $end_ts = strtotime( $task->due_date );
                } elseif ( ! empty( $task->updated_at ) ) {
                    $end_ts = strtotime( $task->updated_at );
                }
            } else {
                if ( ! empty( $task->due_date ) ) {
                    $end_ts = strtotime( $task->due_date );
                } else {
                    // Open with no due date — extend to "now" and mark it.
                    $end_ts = $now_ts;
                    $no_due = true;
                }
            }

            if ( ! $end_ts ) continue;
            // Edge: end before start (data inconsistency) — give it a small slot.
            if ( $end_ts < $start_ts ) $end_ts = $start_ts + DAY_IN_SECONDS;

            // Status class for colouring.
            $is_overdue = ( ! $is_done
                            && ! empty( $task->due_date )
                            && strtotime( $task->due_date ) < $now_ts );
            $bar_class = 'is-open';
            if ( $is_done )                           $bar_class = 'is-completed';
            elseif ( $is_overdue )                    $bar_class = 'is-overdue';
            elseif ( $task->status === 'in_progress' ) $bar_class = 'is-progress';
            elseif ( $task->status === 'waiting' )    $bar_class = 'is-waiting';

            $task_rows[] = array(
                'task'      => $task,
                'start_ts'  => $start_ts,
                'end_ts'    => $end_ts,
                'no_due'    => $no_due,
                'bar_class' => $bar_class,
            );

            if ( $min_ts === null || $start_ts < $min_ts ) $min_ts = $start_ts;
            if ( $max_ts === null || $end_ts   > $max_ts ) $max_ts = $end_ts;
        }

        if ( empty( $task_rows ) ) {
            echo '<p class="cmms-muted cmms-text-sm" style="margin:0;">' . esc_html__( 'אין נתונים להצגה בגאנט.', 'cmms-light' ) . '</p>';
            return;
        }

        // Always include "today" inside the visible range, and add a
        // small breathing margin on each side so the first/last bars
        // don't hug the edges.
        if ( $now_ts < $min_ts ) $min_ts = $now_ts;
        if ( $now_ts > $max_ts ) $max_ts = $now_ts;
        $padding = max( DAY_IN_SECONDS * 2, (int) ( ( $max_ts - $min_ts ) * 0.05 ) );
        $min_ts -= $padding;
        $max_ts += $padding;
        $total_span = max( 1, $max_ts - $min_ts );

        // ─── Decide tick interval ────────────────────────────────────
        // Goal: 5-10 ticks across the visible width. Choose a tick
        // unit that fits the data span.
        $days_span = $total_span / DAY_IN_SECONDS;
        if ( $days_span <= 14 ) {
            $tick_unit = 'day';     $tick_seconds = DAY_IN_SECONDS;
        } elseif ( $days_span <= 90 ) {
            $tick_unit = 'week';    $tick_seconds = 7 * DAY_IN_SECONDS;
        } elseif ( $days_span <= 730 ) {
            $tick_unit = 'month';   $tick_seconds = 30 * DAY_IN_SECONDS;
        } else {
            $tick_unit = 'quarter'; $tick_seconds = 90 * DAY_IN_SECONDS;
        }

        // ─── Helper to convert timestamp → percentage ───────────────
        $pct = function ( $ts ) use ( $min_ts, $total_span ) {
            $p = ( $ts - $min_ts ) / $total_span * 100;
            if ( $p < 0 )   $p = 0;
            if ( $p > 100 ) $p = 100;
            return $p;
        };

        // Format tick label by unit type.
        $fmt_tick = function ( $ts ) use ( $tick_unit ) {
            switch ( $tick_unit ) {
                case 'day':     return date_i18n( 'd/m', $ts );
                case 'week':    return date_i18n( 'd/m', $ts );
                case 'month':   return date_i18n( 'M Y', $ts );
                case 'quarter': return date_i18n( 'M Y', $ts );
            }
            return date_i18n( 'd/m', $ts );
        };
        ?>
        <div class="cmms-gantt">
            <div class="cmms-gantt-scroll">
                <div class="cmms-gantt-inner">
                    <!-- Header row: empty corner cell + timeline ticks -->
                    <div class="cmms-gantt-header">
                        <div class="cmms-gantt-header-labels"><?php esc_html_e( 'משימה', 'cmms-light' ); ?></div>
                        <div class="cmms-gantt-header-timeline">
                            <?php
                            // Render ticks from min_ts to max_ts at tick_seconds intervals.
                            // First tick: align to start of unit so labels look clean.
                            $tick_ts = $min_ts;
                            // Snap to start of day at minimum.
                            $tick_ts = strtotime( date( 'Y-m-d', $tick_ts ) );
                            while ( $tick_ts <= $max_ts ) {
                                $left = $pct( $tick_ts );
                                ?>
                                <div class="cmms-gantt-tick" style="inset-inline-start: <?php echo esc_attr( $left ); ?>%;">
                                    <?php echo esc_html( $fmt_tick( $tick_ts ) ); ?>
                                </div>
                                <?php
                                $tick_ts += $tick_seconds;
                            }
                            // Today marker.
                            $today_left = $pct( $now_ts );
                            ?>
                            <div class="cmms-gantt-today" style="inset-inline-start: <?php echo esc_attr( $today_left ); ?>%;"></div>
                        </div>
                    </div>

                    <!-- Body rows: one per task -->
                    <?php foreach ( $task_rows as $row ) :
                        $task = $row['task'];
                        $bar_left  = $pct( $row['start_ts'] );
                        $bar_right = $pct( $row['end_ts'] );
                        $bar_width = max( 0.4, $bar_right - $bar_left );

                        // Hebrew-friendly span: format like "12/03 → 28/03"
                        $start_label = date_i18n( 'd/m/Y', $row['start_ts'] );
                        $end_label   = date_i18n( 'd/m/Y', $row['end_ts'] );
                        $tooltip     = $task->title . ' — ' . $start_label . ' ← ' . $end_label;
                    ?>
                    <div class="cmms-gantt-row">
                        <div class="cmms-gantt-row-label">
                            <span class="cmms-gantt-row-label-text" title="<?php echo esc_attr( $task->title ); ?>">
                                <?php echo esc_html( $task->title ); ?>
                            </span>
                        </div>
                        <div class="cmms-gantt-row-track">
                            <a href="#"
                               class="cmms-gantt-bar <?php echo esc_attr( $row['bar_class'] ); ?>"
                               style="inset-inline-start: <?php echo esc_attr( $bar_left ); ?>%; width: <?php echo esc_attr( $bar_width ); ?>%;"
                               data-gantt-task-id="<?php echo (int) $task->id; ?>"
                               title="<?php echo esc_attr( $tooltip ); ?>">
                                <?php echo esc_html( $start_label ); ?> ← <?php echo esc_html( $end_label ); ?>
                                <?php if ( $row['no_due'] ) : ?>
                                    <span class="cmms-gantt-bar-warn" title="<?php esc_attr_e( 'ללא תאריך יעד', 'cmms-light' ); ?>">⚠</span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Legend -->
            <div class="cmms-gantt-legend">
                <span class="cmms-gantt-legend-item"><span class="cmms-gantt-legend-dot" style="background:#3b82f6;"></span><?php esc_html_e( 'פתוח', 'cmms-light' ); ?></span>
                <span class="cmms-gantt-legend-item"><span class="cmms-gantt-legend-dot" style="background:#ea580c;"></span><?php esc_html_e( 'בביצוע', 'cmms-light' ); ?></span>
                <span class="cmms-gantt-legend-item"><span class="cmms-gantt-legend-dot" style="background:#eab308;"></span><?php esc_html_e( 'ממתין', 'cmms-light' ); ?></span>
                <span class="cmms-gantt-legend-item"><span class="cmms-gantt-legend-dot" style="background:#10b981;"></span><?php esc_html_e( 'הושלם', 'cmms-light' ); ?></span>
                <span class="cmms-gantt-legend-item"><span class="cmms-gantt-legend-dot" style="background:#ef4444;"></span><?php esc_html_e( 'באיחור', 'cmms-light' ); ?></span>
                <span class="cmms-gantt-legend-item" style="margin-inline-start:auto;color:#94a3b8;">
                    <?php esc_html_e( '⚠ = ללא תאריך יעד', 'cmms-light' ); ?>
                </span>
            </div>
        </div>
        <script>
        // 1.14.76 — bar click: flip back to list view and scroll the
        // matching task card into focus. We avoid changing routes — this
        // keeps the existing list rendering authoritative.
        (function () {
            'use strict';
            var section = document.getElementById('cmms-asset-tasks');
            if (!section) return;
            section.querySelectorAll('[data-gantt-task-id]').forEach(function (bar) {
                bar.addEventListener('click', function (e) {
                    e.preventDefault();
                    var id = bar.getAttribute('data-gantt-task-id');
                    // Switch toggle to list.
                    var listBtn = section.querySelector('[data-asset-view="list"]');
                    if (listBtn) listBtn.click();
                    // Find the matching task card. The list renders each
                    // task card with the task id as an attribute via
                    // task_card(); fall back to data-task-id on common
                    // wrappers if the exact selector misses.
                    var card = section.querySelector('[data-task-id="' + id + '"]')
                             || section.querySelector('#task-' + id)
                             || section.querySelector('[data-id="' + id + '"]');
                    if (card && card.scrollIntoView) {
                        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        card.style.transition = 'box-shadow .3s ease';
                        card.style.boxShadow = '0 0 0 3px rgba(234,88,12,.4)';
                        setTimeout(function () { card.style.boxShadow = ''; }, 1500);
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * 1.14.77 — Render a Kanban board for a list of tasks.
     *
     * Used in two places:
     *   1. Main /tasks view (toggle: List / Kanban)
     *   2. Asset detail tasks block (toggle: List / Gantt / Kanban)
     *
     * Layout: one column per status (open, in_progress, waiting,
     * completed). Each column has its tasks as cards. Drag-and-drop
     * between columns updates the task's status via AJAX (no page
     * reload). Optimistic UI — the card moves immediately; if the
     * server rejects (auth, validation), we roll back.
     *
     * Authorization: server-side. cmms_kanban_status checks
     * can_participate_task; users without permission see the board
     * but cannot drop cards into different columns successfully.
     *
     * @param array  $tasks      Task rows from CMMS_Tasks::list_for_user.
     * @param object $u          Current user (for permission checks in UI).
     * @param string $instance   Unique DOM id suffix so we can render
     *                           multiple Kanbans on a single page
     *                           without DOM collisions.
     */
    private function render_kanban_board( $tasks, $u, $instance = 'main' ) {
        if ( empty( $tasks ) ) {
            echo '<p class="cmms-muted cmms-text-sm" style="margin:0;">' . esc_html__( 'אין משימות להצגה ב-Kanban.', 'cmms-light' ) . '</p>';
            return;
        }

        // Column definitions: status key + label + accent colour.
        $columns = array(
            'open' => array(
                'label' => 'פתוח',
                'color' => '#3b82f6',
                'icon'  => '📋',
            ),
            'in_progress' => array(
                'label' => 'בביצוע',
                'color' => '#ea580c',
                'icon'  => '⚙',
            ),
            'waiting' => array(
                'label' => 'ממתין',
                'color' => '#eab308',
                'icon'  => '⏸',
            ),
            'completed' => array(
                'label' => 'הושלם',
                'color' => '#10b981',
                'icon'  => '✓',
            ),
        );

        // Bucket tasks by status. Treat legacy 'closed' as 'completed'
        // so it doesn't fall off the board.
        $bucketed = array_fill_keys( array_keys( $columns ), array() );
        foreach ( $tasks as $task ) {
            $st = $task->status === 'closed' ? 'completed' : $task->status;
            if ( ! isset( $bucketed[ $st ] ) ) continue;
            $bucketed[ $st ][] = $task;
        }

        $kanban_id  = 'cmms-kanban-' . sanitize_key( $instance );
        $now_ts     = current_time( 'timestamp' );
        $nonce      = wp_create_nonce( 'cmms_kanban' );
        $ajax_url   = admin_url( 'admin-ajax.php' );

        $priority_labels = array(
            'low'    => array( 'label' => 'נמוכה',  'color' => '#94a3b8' ),
            'normal' => array( 'label' => 'רגילה',  'color' => '#64748b' ),
            'high'   => array( 'label' => 'גבוהה',  'color' => '#ea580c' ),
            'urgent' => array( 'label' => 'דחופה',  'color' => '#dc2626' ),
        );

        ?>
        <div class="cmms-kanban" id="<?php echo esc_attr( $kanban_id ); ?>"
             data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>">

            <div class="cmms-kanban-board">
                <?php foreach ( $columns as $status_key => $col ) :
                    $col_tasks = $bucketed[ $status_key ];
                    $count = count( $col_tasks );
                ?>
                <div class="cmms-kanban-column"
                     data-status="<?php echo esc_attr( $status_key ); ?>"
                     style="--cmms-col-color: <?php echo esc_attr( $col['color'] ); ?>;">
                    <div class="cmms-kanban-column-head">
                        <span class="cmms-kanban-column-icon"><?php echo esc_html( $col['icon'] ); ?></span>
                        <span class="cmms-kanban-column-label"><?php echo esc_html( $col['label'] ); ?></span>
                        <span class="cmms-kanban-column-count"><?php echo (int) $count; ?></span>
                    </div>
                    <div class="cmms-kanban-column-body" data-drop-status="<?php echo esc_attr( $status_key ); ?>">
                        <?php if ( $count === 0 ) : ?>
                            <div class="cmms-kanban-column-empty">
                                <?php esc_html_e( 'גרור משימות לכאן', 'cmms-light' ); ?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ( $col_tasks as $task ) :
                            $can_move = CMMS_Auth::can_participate_task( $u, $task );
                            $due_ts   = ! empty( $task->due_date ) ? strtotime( $task->due_date ) : null;
                            $is_overdue = ( $status_key !== 'completed'
                                           && $due_ts && $due_ts < $now_ts );
                            $pri_meta = $priority_labels[ $task->priority ] ?? $priority_labels['normal'];

                            // Assignee name
                            $assignee_name = '';
                            if ( ! empty( $task->assigned_to ) ) {
                                $a_user = CMMS_Users::get( (int) $task->assigned_to );
                                if ( $a_user ) {
                                    $assignee_name = $a_user->display_name ?: '';
                                }
                            }

                            $task_url = $this->url( array( 'view' => 'task', 'id' => $task->id ) );
                        ?>
                        <div class="cmms-kanban-card <?php echo $is_overdue ? 'is-overdue' : ''; ?>"
                             draggable="<?php echo $can_move ? 'true' : 'false'; ?>"
                             data-task-id="<?php echo (int) $task->id; ?>"
                             data-current-status="<?php echo esc_attr( $status_key ); ?>"
                             data-task-url="<?php echo esc_url( $task_url ); ?>"
                             data-can-move="<?php echo $can_move ? '1' : '0'; ?>">
                            <?php // 1.14.78: ⋯ menu button — mobile backup
                                  //  for users who can't (or won't) drag.
                                  //  Opens an inline dropdown with 4
                                  //  status options. Shown only when the
                                  //  user has permission to change status.
                                  if ( $can_move ) : ?>
                            <button type="button"
                                    class="cmms-kanban-card-menu-btn"
                                    aria-label="<?php esc_attr_e( 'תפריט פעולות', 'cmms-light' ); ?>"
                                    aria-expanded="false">⋯</button>
                            <div class="cmms-kanban-card-menu" hidden role="menu">
                                <p class="cmms-kanban-card-menu-title"><?php esc_html_e( 'העבר ל:', 'cmms-light' ); ?></p>
                                <?php foreach ( $columns as $target_status => $target_col ) :
                                    $is_current = ( $target_status === $status_key ); ?>
                                <button type="button"
                                        class="cmms-kanban-card-menu-item <?php echo $is_current ? 'is-current' : ''; ?>"
                                        data-move-to="<?php echo esc_attr( $target_status ); ?>"
                                        <?php echo $is_current ? 'disabled' : ''; ?>
                                        role="menuitem">
                                    <span class="cmms-kanban-card-menu-dot" style="background:<?php echo esc_attr( $target_col['color'] ); ?>;"></span>
                                    <span><?php echo esc_html( $target_col['icon'] ); ?> <?php echo esc_html( $target_col['label'] ); ?><?php echo $is_current ? ' ' . esc_html__( '(נוכחי)', 'cmms-light' ) : ''; ?></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="cmms-kanban-card-title">
                                <?php echo esc_html( $task->title ); ?>
                            </div>
                            <div class="cmms-kanban-card-meta">
                                <?php if ( $assignee_name ) : ?>
                                    <span class="cmms-kanban-card-assignee" title="מבצע">
                                        👤 <?php echo esc_html( $assignee_name ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( $due_ts ) : ?>
                                    <span class="cmms-kanban-card-due <?php echo $is_overdue ? 'is-overdue' : ''; ?>" title="תאריך יעד">
                                        📅 <?php echo esc_html( date_i18n( 'd/m', $due_ts ) ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( $task->priority !== 'normal' ) : ?>
                                    <span class="cmms-kanban-card-priority"
                                          style="background:<?php echo esc_attr( $pri_meta['color'] ); ?>;">
                                        <?php echo esc_html( $pri_meta['label'] ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        // Render the Kanban CSS+JS only once per page, even if we render
        // multiple boards. A static flag tracks whether we've emitted it.
        static $kanban_assets_emitted = false;
        if ( $kanban_assets_emitted ) return;
        $kanban_assets_emitted = true;
        ?>

        <style>
        /* 1.14.77 — Kanban board styles. Scoped to .cmms-kanban-*. */
        .cmms-kanban {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            overflow: hidden;
        }
        .cmms-kanban-board {
            display: grid;
            grid-template-columns: repeat(4, minmax(220px, 1fr));
            gap: 12px;
        }
        @media (max-width: 900px) {
            .cmms-kanban {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .cmms-kanban-board {
                grid-template-columns: repeat(4, 260px);
                min-width: max-content;
            }
        }

        .cmms-kanban-column {
            background: #fff;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 200px;
            border-top: 3px solid var(--cmms-col-color);
        }
        .cmms-kanban-column-head {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 14px;
            background: #fff;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
        }
        .cmms-kanban-column-icon { font-size: 14px; }
        .cmms-kanban-column-label { flex: 1; }
        .cmms-kanban-column-count {
            background: #f1f5f9;
            color: #475569;
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 12px;
            min-width: 22px;
            text-align: center;
        }
        .cmms-kanban-column-body {
            flex: 1;
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: background .15s ease;
            min-height: 100px;
        }
        .cmms-kanban-column-body.is-drag-over {
            background: rgba(79, 70, 229, 0.06);
        }
        .cmms-kanban-column-empty {
            color: #cbd5e1;
            font-size: 12px;
            text-align: center;
            padding: 18px 8px;
            border: 1.5px dashed #e2e8f0;
            border-radius: 8px;
        }

        .cmms-kanban-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            cursor: pointer;
            transition: transform .12s ease, box-shadow .15s ease, border-color .15s ease;
            position: relative;
            /* 1.14.78: prevent native text selection during long-press on mobile */
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
            -webkit-tap-highlight-color: transparent;
        }
        .cmms-kanban-card:hover {
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            border-color: #cbd5e1;
        }
        .cmms-kanban-card[draggable="true"] { cursor: grab; }
        .cmms-kanban-card.is-dragging {
            opacity: 0.5;
            cursor: grabbing;
        }
        /* 1.14.78: long-press "armed" state — card lifts and gets a violet ring
           so the user sees the gesture took effect before they start moving. */
        .cmms-kanban-card.is-press-armed {
            border-color: #4f46e5;
            border-width: 2px;
            transform: scale(1.04);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.25);
            z-index: 5;
        }
        .cmms-kanban-card.is-press-armed::after {
            content: '📌 גרור לעמודה אחרת';
            position: absolute;
            top: calc(100% + 6px);
            inset-inline-start: 50%;
            transform: translateX(-50%);
            background: #eef2ff;
            color: #4f46e5;
            font-size: 10.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
            white-space: nowrap;
            pointer-events: none;
            z-index: 6;
        }
        .cmms-kanban-card.is-overdue {
            border-color: #fca5a5;
            background: #fef2f2;
        }
        .cmms-kanban-card.is-saving {
            pointer-events: none;
            opacity: 0.7;
        }
        .cmms-kanban-card.is-error {
            animation: cmms-kanban-shake .35s ease;
            border-color: #ef4444;
        }
        @keyframes cmms-kanban-shake {
            0%, 100% { transform: translateX(0); }
            25%      { transform: translateX(-6px); }
            75%      { transform: translateX(6px); }
        }

        /* 1.14.78: ⋯ menu button — small, in the card corner */
        .cmms-kanban-card-menu-btn {
            position: absolute;
            top: 6px;
            inset-inline-end: 6px;
            width: 30px;
            height: 30px;
            border: 0;
            background: transparent;
            color: #64748b;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            border-radius: 6px;
            padding: 0;
            font-family: inherit;
            transition: background .15s ease, color .15s ease;
            z-index: 2;
        }
        .cmms-kanban-card-menu-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .cmms-kanban-card-menu-btn:focus-visible {
            outline: 2px solid #4f46e5;
            outline-offset: 1px;
        }
        /* Push card title a bit to leave room for the ⋯ button */
        .cmms-kanban-card-menu-btn + .cmms-kanban-card-title {
            padding-inline-end: 30px;
        }

        /* 1.14.78: inline dropdown menu (shown above card body) */
        .cmms-kanban-card-menu {
            position: absolute;
            top: 38px;
            inset-inline-start: 6px;
            inset-inline-end: 6px;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15);
            padding: 4px;
            z-index: 10;
        }
        .cmms-kanban-card-menu[hidden] { display: none; }
        .cmms-kanban-card-menu-title {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            margin: 0;
            padding: 6px 10px 4px;
        }
        .cmms-kanban-card-menu-item {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 8px 10px;
            background: transparent;
            border: 0;
            font-size: 13px;
            color: #0f172a;
            cursor: pointer;
            border-radius: 4px;
            text-align: start;
            font-family: inherit;
            transition: background .12s ease;
        }
        .cmms-kanban-card-menu-item:hover:not(:disabled) {
            background: #f8fafc;
        }
        .cmms-kanban-card-menu-item:disabled,
        .cmms-kanban-card-menu-item.is-current {
            background: #f8fafc;
            color: #94a3b8;
            cursor: default;
        }
        .cmms-kanban-card-menu-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex: none;
        }

        .cmms-kanban-card-title {
            font-size: 13.5px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 6px;
            line-height: 1.4;
        }
        .cmms-kanban-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 11.5px;
            color: #64748b;
        }
        .cmms-kanban-card-assignee,
        .cmms-kanban-card-due {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #f8fafc;
            border-radius: 4px;
            padding: 2px 6px;
        }
        .cmms-kanban-card-due.is-overdue {
            background: #fee2e2;
            color: #991b1b;
            font-weight: 600;
        }
        .cmms-kanban-card-priority {
            color: #fff;
            border-radius: 4px;
            padding: 2px 7px;
            font-size: 10.5px;
            font-weight: 700;
        }

        /* Toast feedback after a save */
        .cmms-kanban-toast {
            position: fixed;
            bottom: 24px;
            inset-inline-start: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: #fff;
            padding: 10px 18px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 500;
            box-shadow: 0 6px 20px rgba(15,23,42,.3);
            z-index: 99999;
            opacity: 0;
            transition: opacity .2s ease;
            pointer-events: none;
        }
        .cmms-kanban-toast.is-visible { opacity: 1; }
        .cmms-kanban-toast.is-error { background: #b91c1c; }
        </style>

        <script>
        (function () {
            'use strict';
            // Find every Kanban board on the page (could be more than one).
            var boards = document.querySelectorAll('.cmms-kanban');
            if (!boards.length) return;

            // Shared toast — one element for all boards on the page.
            var toast = document.createElement('div');
            toast.className = 'cmms-kanban-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.appendChild(toast);
            var toastTimer = null;
            function showToast(msg, isError) {
                toast.textContent = msg;
                toast.classList.toggle('is-error', !!isError);
                toast.classList.add('is-visible');
                if (toastTimer) clearTimeout(toastTimer);
                toastTimer = setTimeout(function () {
                    toast.classList.remove('is-visible');
                }, 2500);
            }

            // Detect touch capability. We treat the FIRST input modality
            // we see as authoritative for THIS page-load — hybrid devices
            // (touchscreen laptop) start in whichever mode the user uses
            // first. Either way, the menu (⋯) button is always present
            // as a safety net for permission-holders.
            var hasTouch = ('ontouchstart' in window)
                         || (navigator.maxTouchPoints > 0);

            // Globally track if a long-press is "armed" so click handlers
            // can avoid firing while dragging.
            var anyArmed = false;

            boards.forEach(function (board) {
                var ajaxUrl   = board.getAttribute('data-ajax-url');
                var nonce     = board.getAttribute('data-nonce');
                var cards     = board.querySelectorAll('.cmms-kanban-card');
                var dropZones = board.querySelectorAll('.cmms-kanban-column-body');
                var draggedCard = null;

                /* ─────────────────────────────────────────────────────
                   HTML5 desktop drag (unchanged from 1.14.77).
                ───────────────────────────────────────────────────── */
                var dragStarted = false;
                cards.forEach(function (card) {
                    if (card.getAttribute('draggable') === 'true') {
                        card.addEventListener('dragstart', function (e) {
                            dragStarted = true;
                            draggedCard = card;
                            card.classList.add('is-dragging');
                            try { e.dataTransfer.setData('text/plain', card.getAttribute('data-task-id')); } catch (_) {}
                            try { e.dataTransfer.effectAllowed = 'move'; } catch (_) {}
                        });
                        card.addEventListener('dragend', function () {
                            card.classList.remove('is-dragging');
                            draggedCard = null;
                            setTimeout(function () { dragStarted = false; }, 50);
                        });
                    }

                    /* ─────────────────────────────────────────────────
                       Click on card (NOT menu/drag) → open task.
                    ───────────────────────────────────────────────── */
                    card.addEventListener('click', function (e) {
                        // Don't navigate if user pressed the ⋯ menu or
                        // its inner items — those have their own logic.
                        if (e.target.closest('.cmms-kanban-card-menu-btn')) return;
                        if (e.target.closest('.cmms-kanban-card-menu'))     return;
                        // Don't navigate if a drag finished or a long-press is armed.
                        if (dragStarted || anyArmed) { dragStarted = false; return; }
                        var url = card.getAttribute('data-task-url');
                        if (url) window.location.href = url;
                    });
                });

                /* ─────────────────────────────────────────────────────
                   Desktop dropZones.
                ───────────────────────────────────────────────────── */
                dropZones.forEach(function (zone) {
                    zone.addEventListener('dragover', function (e) {
                        if (!draggedCard) return;
                        e.preventDefault();
                        try { e.dataTransfer.dropEffect = 'move'; } catch (_) {}
                        zone.classList.add('is-drag-over');
                    });
                    zone.addEventListener('dragleave', function (e) {
                        if (e.target !== zone) return;
                        zone.classList.remove('is-drag-over');
                    });
                    zone.addEventListener('drop', function (e) {
                        e.preventDefault();
                        zone.classList.remove('is-drag-over');
                        if (!draggedCard) return;
                        commitMove(draggedCard, zone);
                    });
                });

                /* ─────────────────────────────────────────────────────
                   1.14.78 — Touch / long-press support.

                   Strategy:
                     • touchstart → schedule a 400ms timer
                     • if the finger moves >10px before the timer fires
                       → cancel (treated as a scroll, not a press)
                     • if the timer fires → "arm" the card (visual hint)
                     • after armed, touchmove tracks finger position
                       against drop zones; matched zone gets is-drag-over
                     • touchend on an armed card → commit move into the
                       matched zone, or rollback if none matched
                ───────────────────────────────────────────────────── */
                if (hasTouch) {
                    var pressTimer = null;
                    var pressedCard = null;
                    var pressStartX = 0;
                    var pressStartY = 0;
                    var armed = false;
                    var lastZoneOver = null;
                    var LONG_PRESS_MS = 400;
                    var MOVE_TOLERANCE_PX = 10;

                    cards.forEach(function (card) {
                        if (card.getAttribute('data-can-move') !== '1') return;

                        card.addEventListener('touchstart', function (e) {
                            if (e.touches.length !== 1) return;
                            var t = e.touches[0];
                            pressedCard = card;
                            pressStartX = t.clientX;
                            pressStartY = t.clientY;
                            armed = false;
                            anyArmed = false;
                            pressTimer = setTimeout(function () {
                                // Long-press fired: arm the card.
                                armed = true;
                                anyArmed = true;
                                card.classList.add('is-press-armed');
                                // Haptic feedback if available.
                                if (navigator.vibrate) {
                                    try { navigator.vibrate(15); } catch (_) {}
                                }
                                // Once armed, prevent scrolling and let
                                // touchmove dictate drag position. Note:
                                // we don't preventDefault here — we do
                                // that on touchmove (after arming) to
                                // keep scroll working until the user
                                // actually long-presses.
                            }, LONG_PRESS_MS);
                        }, { passive: true });

                        card.addEventListener('touchmove', function (e) {
                            if (!pressedCard) return;
                            var t = e.touches[0];
                            var dx = Math.abs(t.clientX - pressStartX);
                            var dy = Math.abs(t.clientY - pressStartY);

                            if (!armed) {
                                // Not armed yet: any meaningful move cancels.
                                if (dx > MOVE_TOLERANCE_PX || dy > MOVE_TOLERANCE_PX) {
                                    if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
                                    pressedCard = null;
                                }
                                return;
                            }

                            // Armed: track which dropZone the finger is over.
                            // preventDefault keeps the page from scrolling
                            // while the user drags. Without it iOS Safari
                            // happily scrolls the column out from under
                            // the finger.
                            try { e.preventDefault(); } catch (_) {}

                            var el = document.elementFromPoint(t.clientX, t.clientY);
                            var zone = el ? el.closest('.cmms-kanban-column-body') : null;
                            if (zone !== lastZoneOver) {
                                if (lastZoneOver) lastZoneOver.classList.remove('is-drag-over');
                                if (zone)         zone.classList.add('is-drag-over');
                                lastZoneOver = zone;
                            }
                        }, { passive: false });

                        card.addEventListener('touchend', function () {
                            if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
                            if (armed && pressedCard) {
                                pressedCard.classList.remove('is-press-armed');
                                if (lastZoneOver) {
                                    lastZoneOver.classList.remove('is-drag-over');
                                    commitMove(pressedCard, lastZoneOver);
                                }
                            }
                            armed = false;
                            // Slight delay so the synthesized click event
                            // (which fires after touchend on iOS) sees
                            // anyArmed=true and aborts.
                            setTimeout(function () { anyArmed = false; }, 50);
                            pressedCard = null;
                            lastZoneOver = null;
                        });

                        card.addEventListener('touchcancel', function () {
                            if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
                            if (lastZoneOver) lastZoneOver.classList.remove('is-drag-over');
                            if (pressedCard)  pressedCard.classList.remove('is-press-armed');
                            armed = false;
                            anyArmed = false;
                            pressedCard = null;
                            lastZoneOver = null;
                        });
                    });
                }

                /* ─────────────────────────────────────────────────────
                   1.14.78 — ⋯ menu button (mobile backup + keyboard a11y).
                ───────────────────────────────────────────────────── */
                board.querySelectorAll('.cmms-kanban-card-menu-btn').forEach(function (btn) {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        var card = btn.closest('.cmms-kanban-card');
                        var menu = card.querySelector('.cmms-kanban-card-menu');
                        if (!menu) return;
                        var isOpen = !menu.hidden;
                        // Close any other open menus on this board first.
                        board.querySelectorAll('.cmms-kanban-card-menu').forEach(function (m) {
                            m.hidden = true;
                            var b = m.parentElement && m.parentElement.querySelector('.cmms-kanban-card-menu-btn');
                            if (b) b.setAttribute('aria-expanded', 'false');
                        });
                        menu.hidden = isOpen;
                        btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                    });
                });

                // Click outside → close any open menus on this board.
                document.addEventListener('click', function (e) {
                    if (e.target.closest('.cmms-kanban-card-menu-btn')) return;
                    if (e.target.closest('.cmms-kanban-card-menu'))     return;
                    board.querySelectorAll('.cmms-kanban-card-menu').forEach(function (m) {
                        m.hidden = true;
                        var b = m.parentElement && m.parentElement.querySelector('.cmms-kanban-card-menu-btn');
                        if (b) b.setAttribute('aria-expanded', 'false');
                    });
                });

                // Wire each menu item: click → move card to that status.
                board.querySelectorAll('.cmms-kanban-card-menu-item').forEach(function (item) {
                    item.addEventListener('click', function (e) {
                        e.stopPropagation();
                        if (item.disabled) return;
                        var target = item.getAttribute('data-move-to');
                        var card   = item.closest('.cmms-kanban-card');
                        var zone   = board.querySelector('[data-drop-status="' + target + '"]');
                        if (!card || !zone) return;
                        // Close the menu.
                        var menu = item.closest('.cmms-kanban-card-menu');
                        if (menu) menu.hidden = true;
                        var btn = card.querySelector('.cmms-kanban-card-menu-btn');
                        if (btn) btn.setAttribute('aria-expanded', 'false');
                        commitMove(card, zone);
                    });
                });

                /* ─────────────────────────────────────────────────────
                   Shared mover — used by desktop drag, touch drag, and
                   the ⋯ menu. Optimistic UI + rollback on failure.
                ───────────────────────────────────────────────────── */
                function commitMove(card, zone) {
                    var newStatus = zone.getAttribute('data-drop-status');
                    var oldStatus = card.getAttribute('data-current-status');
                    if (newStatus === oldStatus) return;

                    var taskId     = card.getAttribute('data-task-id');
                    var sourceZone = card.parentElement;

                    var emptyEl = zone.querySelector('.cmms-kanban-column-empty');
                    if (emptyEl) emptyEl.remove();

                    zone.appendChild(card);
                    card.setAttribute('data-current-status', newStatus);
                    card.classList.add('is-saving');

                    if (sourceZone && !sourceZone.querySelector('.cmms-kanban-card')) {
                        var emp = document.createElement('div');
                        emp.className = 'cmms-kanban-column-empty';
                        emp.textContent = 'גרור משימות לכאן';
                        sourceZone.appendChild(emp);
                    }
                    updateColumnCounts(board);
                    // Also refresh the menu's "(נוכחי)" state on this card.
                    refreshCardMenu(card, newStatus);

                    var body = new URLSearchParams();
                    body.set('action',  'cmms_kanban_status');
                    body.set('nonce',   nonce);
                    body.set('task_id', taskId);
                    body.set('status',  newStatus);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    })
                    .then(function (r) { return r.json().catch(function () { return null; }); })
                    .then(function (res) {
                        card.classList.remove('is-saving');
                        if (res && res.success) {
                            showToast('הסטטוס עודכן');
                        } else {
                            rollback(card, sourceZone, oldStatus);
                            var msg = (res && res.data && res.data.message) ? res.data.message : 'העדכון נכשל. נסה שוב.';
                            showToast(msg, true);
                        }
                    })
                    .catch(function () {
                        card.classList.remove('is-saving');
                        rollback(card, sourceZone, oldStatus);
                        showToast('שגיאת רשת. נסה שוב.', true);
                    });
                }

                function rollback(card, sourceZone, oldStatus) {
                    if (!sourceZone) return;
                    var emp = sourceZone.querySelector('.cmms-kanban-column-empty');
                    if (emp) emp.remove();
                    sourceZone.appendChild(card);
                    card.setAttribute('data-current-status', oldStatus);
                    card.classList.add('is-error');
                    setTimeout(function () { card.classList.remove('is-error'); }, 400);
                    updateColumnCounts(board);
                    refreshCardMenu(card, oldStatus);
                }

                function updateColumnCounts(board) {
                    board.querySelectorAll('.cmms-kanban-column').forEach(function (col) {
                        var n = col.querySelectorAll('.cmms-kanban-card').length;
                        var c = col.querySelector('.cmms-kanban-column-count');
                        if (c) c.textContent = String(n);
                    });
                }

                // After moving a card, update the menu items so the new
                // current status is marked (נוכחי) and the previous one
                // becomes clickable again.
                function refreshCardMenu(card, currentStatus) {
                    var items = card.querySelectorAll('.cmms-kanban-card-menu-item');
                    items.forEach(function (it) {
                        var target = it.getAttribute('data-move-to');
                        var label  = it.querySelector('span:last-child');
                        if (!label) return;
                        var isCurrent = (target === currentStatus);
                        it.classList.toggle('is-current', isCurrent);
                        it.disabled = isCurrent;
                        // Strip any existing " (נוכחי)" suffix and re-add if needed.
                        var clean = label.textContent.replace(/\s*\(נוכחי\)\s*$/u, '');
                        label.textContent = clean + (isCurrent ? ' (נוכחי)' : '');
                    });
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * 1.14.81 — Calendar (month) view.
     *
     * Renders a full-month grid (desktop) or a compact month grid +
     * selected-day list (mobile). Only tasks with a non-empty due_date
     * are shown — tasks without due_date are summarized in a banner.
     *
     * Server-side scoped: list_for_user is called with date_from /
     * date_to restricting the SQL to the requested month + a small
     * overlap (covers the prev/next month days visible in the grid).
     * So even with thousands of tasks in the account, only ~30-50
     * rows are fetched per render.
     *
     * URL params:
     *   ?view=tasks&display=calendar      → current month
     *   &month=2026-05                    → specific month (YYYY-MM)
     *   &day=2026-05-20                   → preselected day on mobile
     *
     * The chosen view (list/kanban/calendar) is persisted in
     * localStorage — see the script at the bottom of view_tasks.
     *
     * @param object $u       Current CMMS user.
     * @param array  $filters Filters carried over from view_tasks
     *                        (mine, status, etc) — passed through so
     *                        the calendar respects the same scoping.
     */
    private function render_calendar_view( $u, $filters = array() ) {
        // ─────────────────────────────────────────────────────────────
        // 1. Resolve the month to render. Default = current month.
        // ─────────────────────────────────────────────────────────────
        $month_param = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
        if ( $month_param && preg_match( '/^(\d{4})-(\d{2})$/', $month_param, $m ) ) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
            if ( $month < 1 || $month > 12 ) {
                $year  = (int) date_i18n( 'Y' );
                $month = (int) date_i18n( 'n' );
            }
        } else {
            $year  = (int) date_i18n( 'Y' );
            $month = (int) date_i18n( 'n' );
        }
        $first_day_ts = mktime( 0, 0, 0, $month, 1, $year );
        $days_in_mon  = (int) date( 't', $first_day_ts );
        $first_dow    = (int) date( 'w', $first_day_ts ); // 0=Sun, ..., 6=Sat (Hebrew calendar week starts Sunday)

        // 2. Build the visible grid range (always 6 weeks = 42 cells
        //    so the calendar height is stable).
        //    grid_start_ts = the Sunday that contains or precedes day 1.
        $grid_start_ts = strtotime( '-' . $first_dow . ' days', $first_day_ts );
        $grid_end_ts   = $grid_start_ts + ( 42 * 86400 ) - 1;

        // 3. Fetch tasks for this window — server-side, date-bound.
        //    list_for_user supports date_from / date_to / date_field.
        $calendar_filters = $filters;
        $calendar_filters['date_from']  = date( 'Y-m-d', $grid_start_ts );
        $calendar_filters['date_to']    = date( 'Y-m-d', $grid_end_ts );
        $calendar_filters['date_field'] = 'due_date';
        $calendar_filters['limit']      = 500; // safety cap, same as default
        $tasks = CMMS_Tasks::list_for_user( $u, $calendar_filters );

        // 4. Count tasks without due_date (separately, lightweight).
        //    Only show banner if there are any — keeps UI quiet.
        $no_due_filters = $filters;
        $no_due_filters['no_due_date'] = 1;
        $no_due_filters['limit']       = 50; // we only need the count, but the
                                              // method returns rows; we just count()
        $no_due_count = 0;
        if ( method_exists( 'CMMS_Tasks', 'list_for_user' ) ) {
            // We don't have a dedicated count method, so fetch the rows
            // (up to limit) and count. Cheap with the index on due_date.
            // If the list_for_user doesn't support no_due_date, this
            // falls through harmlessly returning 0.
            $no_due_rows = CMMS_Tasks::list_for_user( $u, $no_due_filters );
            $no_due_count = is_array( $no_due_rows ) ? count( $no_due_rows ) : 0;
        }

        // 5. Bucket tasks by their due-date YYYY-MM-DD.
        $by_day = array();
        foreach ( $tasks as $t ) {
            if ( empty( $t->due_date ) ) continue;
            $key = substr( (string) $t->due_date, 0, 10 );
            if ( ! isset( $by_day[ $key ] ) ) $by_day[ $key ] = array();
            $by_day[ $key ][] = $t;
        }

        // 6. Resolve "selected day" for mobile (defaults to today if it's
        //    in the current month, else day 1).
        $today_ymd = date_i18n( 'Y-m-d' );
        // 1.14.81.2: 'day' is a reserved WordPress query var (used by
        // date-archive rewrites like /2026/05/14/). When we pass
        // ?day=2026-05-14 in our URL, WP intercepts and tries to load
        // a non-existent date archive → 404. Rename to cmms_day.
        $selected_day_param = isset( $_GET['cmms_day'] ) ? sanitize_text_field( wp_unslash( $_GET['cmms_day'] ) ) : '';
        if ( $selected_day_param && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $selected_day_param ) ) {
            $selected_day = $selected_day_param;
        } else {
            // Default to today if today falls in this month, else day 1.
            if ( (int) date( 'n', strtotime( $today_ymd ) ) === $month
                 && (int) date( 'Y', strtotime( $today_ymd ) ) === $year ) {
                $selected_day = $today_ymd;
            } else {
                $selected_day = sprintf( '%04d-%02d-01', $year, $month );
            }
        }

        // 7. Build navigation URLs.
        $prev_ts = mktime( 0, 0, 0, $month - 1, 1, $year );
        $next_ts = mktime( 0, 0, 0, $month + 1, 1, $year );
        $base_args = array( 'view' => 'tasks', 'display' => 'calendar' );
        if ( ! empty( $filters['mine'] ) )        $base_args['mine'] = 1;
        if ( ! empty( $filters['status'] ) )      $base_args['status'] = $filters['status'];
        if ( ! empty( $filters['as_assignee'] ) ) $base_args['as_assignee'] = 1;
        if ( ! empty( $filters['as_manager'] ) )  $base_args['as_manager'] = 1;
        if ( ! empty( $filters['overdue'] ) )     $base_args['overdue'] = 1;

        $url_prev  = $this->url( array_merge( $base_args, array( 'month' => date( 'Y-m', $prev_ts ) ) ) );
        $url_next  = $this->url( array_merge( $base_args, array( 'month' => date( 'Y-m', $next_ts ) ) ) );
        $url_today = $this->url( array_merge( $base_args, array( 'month' => date_i18n( 'Y-m' ) ) ) );

        $month_names_he = array(
            1 => 'ינואר', 2 => 'פברואר', 3 => 'מרץ',     4 => 'אפריל',
            5 => 'מאי',    6 => 'יוני',    7 => 'יולי',    8 => 'אוגוסט',
            9 => 'ספטמבר', 10 => 'אוקטובר', 11 => 'נובמבר', 12 => 'דצמבר',
        );

        // Status → dot color. Synced with the inline-status-changer
        // dot colors so the calendar feels consistent with the rest.
        $status_colors = array(
            'open'        => '#8b5cf6',
            'in_progress' => '#f97316',
            'waiting'     => '#94a3b8',
            'completed'   => '#10b981',
            'closed'      => '#10b981',
        );

        $is_priv = in_array( $u->role, array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER ), true );

        ?>
        <div class="cmms-calendar" data-current-month="<?php echo esc_attr( sprintf( '%04d-%02d', $year, $month ) ); ?>">

            <div class="cmms-cal-head">
                <div class="cmms-cal-head-title">
                    <div class="cmms-cal-head-month-name"><?php echo esc_html( $month_names_he[ $month ] ); ?></div>
                    <h2 class="cmms-cal-head-year"><?php echo (int) $year; ?></h2>
                </div>
                <div class="cmms-cal-head-actions">
                    <a class="cmms-cal-nav-btn" href="<?php echo esc_url( $url_prev ); ?>" aria-label="<?php esc_attr_e( 'חודש קודם', 'cmms-light' ); ?>">‹</a>
                    <a class="cmms-cal-today-btn" href="<?php echo esc_url( $url_today ); ?>"><?php esc_html_e( 'היום', 'cmms-light' ); ?></a>
                    <a class="cmms-cal-nav-btn" href="<?php echo esc_url( $url_next ); ?>" aria-label="<?php esc_attr_e( 'חודש הבא', 'cmms-light' ); ?>">›</a>
                </div>
            </div>

            <?php if ( $no_due_count > 0 ) :
                // 1.14.81.1: link to a filtered list view so the user
                // can actually fix those tasks. Carries the user's
                // existing scope (mine/status/etc) and adds no_due_date=1
                // which view_tasks now respects.
                $list_url = $this->url( array_merge( $base_args, array(
                    'display'     => 'list',
                    'no_due_date' => 1,
                ) ) );
            ?>
            <div class="cmms-cal-no-due-banner">
                ⓘ <strong><?php echo (int) $no_due_count; ?> <?php esc_html_e( 'משימות ללא תאריך יעד', 'cmms-light' ); ?></strong>
                · <a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'הצג ברשימה', 'cmms-light' ); ?></a>
            </div>
            <?php endif; ?>

            <div class="cmms-cal-grid-wrap">
                <div class="cmms-cal-weekdays">
                    <div>ראשון</div>
                    <div>שני</div>
                    <div>שלישי</div>
                    <div>רביעי</div>
                    <div>חמישי</div>
                    <div>שישי</div>
                    <div>שבת</div>
                </div>
                <div class="cmms-cal-grid">
                    <?php
                    // Render 42 cells (6 weeks × 7 days).
                    for ( $i = 0; $i < 42; $i++ ) {
                        $cell_ts   = $grid_start_ts + ( $i * 86400 );
                        $cell_ymd  = date( 'Y-m-d', $cell_ts );
                        $cell_dnum = (int) date( 'j', $cell_ts );
                        $cell_mon  = (int) date( 'n', $cell_ts );

                        $in_current_month = ( $cell_mon === $month );
                        $is_today  = ( $cell_ymd === $today_ymd );
                        $day_tasks = $by_day[ $cell_ymd ] ?? array();

                        // Determine if any task on this day is overdue
                        // (i.e. before today AND not completed/closed).
                        $has_overdue = false;
                        foreach ( $day_tasks as $dt ) {
                            if ( $cell_ymd < $today_ymd
                                 && ! in_array( $dt->status, array( 'completed', 'closed' ), true ) ) {
                                $has_overdue = true;
                                break;
                            }
                        }

                        $cell_classes = array( 'cmms-cal-cell' );
                        if ( ! $in_current_month ) $cell_classes[] = 'is-other-month';
                        if ( $is_today )           $cell_classes[] = 'is-today';
                        if ( $has_overdue )        $cell_classes[] = 'has-overdue';
                        if ( $cell_ymd === $selected_day ) $cell_classes[] = 'is-selected';

                        // Build a day-select URL for mobile (keeps month
                        // but changes the day param). Using cmms_day
                        // (not day) to avoid clashing with WordPress
                        // date-archive query var.
                        $day_args = array_merge( $base_args, array(
                            'month'    => sprintf( '%04d-%02d', $year, $month ),
                            'cmms_day' => $cell_ymd,
                        ) );
                        $day_url = $this->url( $day_args );
                        ?>
                        <a class="<?php echo esc_attr( implode( ' ', $cell_classes ) ); ?>"
                           href="<?php echo esc_url( $day_url ); ?>"
                           data-day="<?php echo esc_attr( $cell_ymd ); ?>">
                            <div class="cmms-cal-cell-day-num"><?php echo (int) $cell_dnum; ?><?php if ( $is_today ) : ?><span class="cmms-cal-today-badge"></span><?php endif; ?></div>
                            <?php if ( ! empty( $day_tasks ) ) : ?>
                                <div class="cmms-cal-cell-tasks">
                                    <?php
                                    $shown = 0;
                                    foreach ( $day_tasks as $dt ) {
                                        if ( $shown >= 3 ) break;
                                        $is_dt_overdue = ( $cell_ymd < $today_ymd
                                                           && ! in_array( $dt->status, array( 'completed', 'closed' ), true ) );
                                        $dot_color = $is_dt_overdue ? '#dc2626' : ( $status_colors[ $dt->status ] ?? '#94a3b8' );
                                        $task_url  = $this->url( array( 'view' => 'task', 'id' => (int) $dt->id ) );
                                        ?>
                                        <span class="cmms-cal-task <?php echo $is_dt_overdue ? 'is-overdue' : ''; ?>"
                                              data-task-url="<?php echo esc_url( $task_url ); ?>"
                                              title="<?php echo esc_attr( $dt->title ); ?>">
                                            <span class="cmms-cal-task-dot" style="background:<?php echo esc_attr( $dot_color ); ?>;"></span>
                                            <span class="cmms-cal-task-title"><?php echo esc_html( $dt->title ); ?></span>
                                        </span>
                                        <?php
                                        $shown++;
                                    }
                                    $remaining = count( $day_tasks ) - $shown;
                                    if ( $remaining > 0 ) : ?>
                                        <span class="cmms-cal-more">+ <?php echo (int) $remaining; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="cmms-cal-cell-dots">
                                    <?php
                                    // Mobile-only: stacked dots showing task statuses.
                                    $shown_dot = 0;
                                    foreach ( $day_tasks as $dt ) {
                                        if ( $shown_dot >= 3 ) break;
                                        $is_dt_overdue = ( $cell_ymd < $today_ymd
                                                           && ! in_array( $dt->status, array( 'completed', 'closed' ), true ) );
                                        $dot_color = $is_dt_overdue ? '#dc2626' : ( $status_colors[ $dt->status ] ?? '#94a3b8' );
                                        ?>
                                        <span class="cmms-cal-mini-dot" style="background:<?php echo esc_attr( $dot_color ); ?>;"></span>
                                        <?php
                                        $shown_dot++;
                                    }
                                    if ( count( $day_tasks ) > 3 ) : ?>
                                        <span class="cmms-cal-mini-dot" style="background:#94a3b8;"></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </a>
                        <?php
                    }
                    ?>
                </div>
            </div>

            <?php // Mobile-only: list of tasks for the selected day. ?>
            <div class="cmms-cal-day-list">
                <?php
                $sel_ts = strtotime( $selected_day );
                $sel_tasks = $by_day[ $selected_day ] ?? array();
                $sel_dow_he = array( 'ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת' );
                $sel_dnum = (int) date( 'j', $sel_ts );
                $sel_mon  = (int) date( 'n', $sel_ts );
                $is_sel_today = ( $selected_day === $today_ymd );
                ?>
                <div class="cmms-cal-day-list-head">
                    <div class="cmms-cal-day-list-date"><?php echo (int) $sel_dnum; ?> <?php echo esc_html( $month_names_he[ $sel_mon ] ); ?></div>
                    <div class="cmms-cal-day-list-dow">
                        <?php if ( $is_sel_today ) : ?>
                            <span class="cmms-cal-day-list-today"><?php esc_html_e( 'היום', 'cmms-light' ); ?> · </span>
                        <?php endif; ?>
                        <?php echo esc_html( $sel_dow_he[ (int) date( 'w', $sel_ts ) ] ); ?>
                    </div>
                </div>
                <?php if ( empty( $sel_tasks ) ) : ?>
                    <div class="cmms-cal-day-list-empty"><?php esc_html_e( 'אין משימות ביום זה', 'cmms-light' ); ?></div>
                <?php else :
                    foreach ( $sel_tasks as $dt ) :
                        $is_dt_overdue = ( $selected_day < $today_ymd
                                           && ! in_array( $dt->status, array( 'completed', 'closed' ), true ) );
                        $dot_color = $is_dt_overdue ? '#dc2626' : ( $status_colors[ $dt->status ] ?? '#94a3b8' );
                        $task_url  = $this->url( array( 'view' => 'task', 'id' => (int) $dt->id ) );

                        // Tiny meta line: assignee / asset.
                        $meta_parts = array();
                        if ( ! empty( $dt->assigned_to ) ) {
                            $au = CMMS_Users::get( (int) $dt->assigned_to );
                            if ( $au && ! empty( $au->display_name ) ) {
                                $meta_parts[] = '👤 ' . $au->display_name;
                            }
                        }
                        if ( ! empty( $dt->asset_id ) ) {
                            $an = CMMS_Assets::asset_name( (int) $dt->asset_id );
                            if ( $an ) $meta_parts[] = '📍 ' . $an;
                        }
                        ?>
                        <a class="cmms-cal-day-task <?php echo $is_dt_overdue ? 'is-overdue' : ''; ?>"
                           href="<?php echo esc_url( $task_url ); ?>">
                            <div class="cmms-cal-day-task-main">
                                <span class="cmms-cal-day-task-dot" style="background:<?php echo esc_attr( $dot_color ); ?>;"></span>
                                <span class="cmms-cal-day-task-title"><?php echo esc_html( $dt->title ); ?></span>
                            </div>
                            <?php if ( ! empty( $meta_parts ) ) : ?>
                                <div class="cmms-cal-day-task-meta"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach;
                endif; ?>
            </div>

        </div>

        <?php $this->calendar_view_assets(); ?>
        <?php
    }

    /**
     * 1.14.81 — CSS + JS for the calendar view. Emitted once per page
     * (static flag). Separate from inline_status_changer_assets so the
     * two features can be used independently.
     */
    private function calendar_view_assets() {
        static $emitted = false;
        if ( $emitted ) return;
        $emitted = true;
        ?>
        <style>
        /* 1.14.81 — Calendar view, Apple-inspired aesthetic.
           Two layouts: desktop (full 7×6 grid with inline task pills)
           and mobile (compact grid with colored dots + selected-day list). */

        .cmms-calendar {
            background: #fafafa;
            border-radius: 16px;
            padding: 20px;
            font-family: -apple-system, 'Segoe UI', Arial, sans-serif;
        }

        .cmms-cal-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .cmms-cal-head-title { line-height: 1; }
        .cmms-cal-head-month-name {
            font-size: 13px;
            color: #f97316;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .cmms-cal-head-year {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.5px;
            line-height: 1;
        }
        .cmms-cal-head-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .cmms-cal-nav-btn,
        .cmms-cal-today-btn {
            background: white;
            border: 0;
            border-radius: 999px;
            cursor: pointer;
            color: #475569;
            text-decoration: none;
            font-family: inherit;
            transition: box-shadow .15s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .cmms-cal-nav-btn { width: 34px; height: 34px; font-size: 16px; }
        .cmms-cal-today-btn { padding: 7px 14px; font-size: 12px; font-weight: 600; }
        .cmms-cal-nav-btn:hover,
        .cmms-cal-today-btn:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }

        .cmms-cal-no-due-banner {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            margin-bottom: 14px;
        }
        .cmms-cal-no-due-banner a { color: #c2410c; text-decoration: underline; }

        .cmms-cal-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            margin-bottom: 4px;
        }
        .cmms-cal-weekdays > div {
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            font-weight: 500;
            padding: 8px 0;
        }
        .cmms-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }
        .cmms-cal-cell {
            min-height: 100px;
            padding: 8px;
            border-radius: 10px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            transition: background .15s ease;
        }
        .cmms-cal-cell:hover { background: #f1f5f9; }
        .cmms-cal-cell.is-other-month .cmms-cal-cell-day-num { color: #cbd5e1; }
        .cmms-cal-cell.has-overdue { background: rgba(239,68,68,0.04); }
        .cmms-cal-cell.has-overdue:hover { background: rgba(239,68,68,0.08); }

        .cmms-cal-cell-day-num {
            font-size: 14px;
            font-weight: 500;
            color: #475569;
            margin-bottom: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .cmms-cal-cell.is-today .cmms-cal-cell-day-num {
            color: white;
            background: #f97316;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
        }
        .cmms-cal-today-badge { display: none; } /* not needed on desktop; cell styling handles it */

        .cmms-cal-cell-tasks {
            display: flex;
            flex-direction: column;
            gap: 3px;
            flex: 1;
            min-height: 0;
        }
        .cmms-cal-task {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: #475569;
            text-decoration: none;
            padding: 1px 0;
            overflow: hidden;
        }
        .cmms-cal-task:hover { color: #0f172a; }
        .cmms-cal-task.is-overdue { color: #dc2626; font-weight: 500; }
        .cmms-cal-task-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            flex: none;
        }
        .cmms-cal-task-title {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cmms-cal-more {
            font-size: 10px;
            color: #94a3b8;
            margin-top: 2px;
        }
        .cmms-cal-cell-dots { display: none; }

        /* Mobile-only day list (hidden on desktop). */
        .cmms-cal-day-list { display: none; }

        /* ─────────────────────────────────────────────────────
           MOBILE (<= 640px)
           Compact grid + selected day's task list below.
        ───────────────────────────────────────────────────── */
        @media (max-width: 640px) {
            .cmms-calendar { padding: 14px; border-radius: 20px; }
            .cmms-cal-head-year { font-size: 22px; }
            .cmms-cal-nav-btn { width: 30px; height: 30px; font-size: 13px; }
            .cmms-cal-today-btn { padding: 6px 11px; font-size: 11px; }

            .cmms-cal-grid-wrap {
                background: white;
                border-radius: 14px;
                padding: 12px 8px;
                margin-bottom: 14px;
            }

            .cmms-cal-weekdays > div { font-size: 10px; padding: 4px 0; }
            .cmms-cal-grid { gap: 2px; }
            .cmms-cal-cell {
                min-height: 0;
                aspect-ratio: 1;
                padding: 4px 0;
                align-items: center;
                justify-content: center;
                border-radius: 8px;
            }
            .cmms-cal-cell-day-num {
                font-size: 12px;
                margin-bottom: 2px;
            }
            .cmms-cal-cell.is-today .cmms-cal-cell-day-num {
                width: 22px;
                height: 22px;
                font-size: 11px;
            }
            .cmms-cal-cell.is-selected:not(.is-today) {
                background: #fef3c7;
            }
            .cmms-cal-cell-tasks { display: none; }
            .cmms-cal-cell-dots {
                display: flex;
                gap: 2px;
                justify-content: center;
                margin-top: 1px;
            }
            .cmms-cal-mini-dot {
                width: 4px;
                height: 4px;
                border-radius: 50%;
            }

            .cmms-cal-day-list {
                display: block;
            }
            .cmms-cal-day-list-head {
                display: flex;
                align-items: baseline;
                gap: 8px;
                margin-bottom: 10px;
                padding: 0 4px;
            }
            .cmms-cal-day-list-date {
                font-size: 18px;
                font-weight: 700;
                color: #0f172a;
            }
            .cmms-cal-day-list-dow {
                font-size: 12px;
                color: #94a3b8;
            }
            .cmms-cal-day-list-today {
                color: #f97316;
                font-weight: 600;
            }
            .cmms-cal-day-list-empty {
                background: white;
                padding: 18px;
                text-align: center;
                color: #94a3b8;
                font-size: 13px;
                border-radius: 12px;
            }
            .cmms-cal-day-task {
                background: white;
                padding: 12px 14px;
                border-radius: 12px;
                margin-bottom: 6px;
                text-decoration: none;
                color: inherit;
                display: block;
            }
            .cmms-cal-day-task.is-overdue {
                background: #fff1f0;
                border: 1px solid #fecaca;
            }
            .cmms-cal-day-task-main {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .cmms-cal-day-task-dot {
                width: 6px;
                height: 6px;
                border-radius: 50%;
                flex: none;
            }
            .cmms-cal-day-task-title {
                font-size: 13.5px;
                color: #0f172a;
                flex: 1;
            }
            .cmms-cal-day-task.is-overdue .cmms-cal-day-task-title {
                color: #991b1b;
                font-weight: 500;
            }
            .cmms-cal-day-task-meta {
                font-size: 11px;
                color: #94a3b8;
                margin-top: 4px;
                padding-inline-start: 14px;
            }
            .cmms-cal-day-task.is-overdue .cmms-cal-day-task-meta { color: #dc2626; }
        }
        </style>

        <script>
        (function () {
            'use strict';
            // Desktop click handling: clicking a task pill inside a cell
            // should navigate to the task, not select the day. Cells
            // themselves are <a>s that change the selected day (URL
            // param). The task <span> inside intercepts.
            document.querySelectorAll('.cmms-cal-task').forEach(function (taskEl) {
                taskEl.addEventListener('click', function (e) {
                    var url = taskEl.getAttribute('data-task-url');
                    if (!url) return;
                    e.preventDefault();
                    e.stopPropagation();
                    window.location.href = url;
                });
            });
        })();
        </script>
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

    /**
     * 1.15.5: Email-to-task inbound log — a dedicated screen showing
     * all incoming emails across the account, newest first. Replaces
     * the idea of cramming this into each form's edit page (which
     * would add weight and scatter the data per-form).
     */
    private function view_email_log( $u ) {
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) { $this->forbidden(); return; }

        // Optional filter by form.
        $filter_form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;

        $rows  = CMMS_Email_Inbox::get_inbound_log( $u->account_id, 100, $filter_form_id );
        $forms = CMMS_Forms::list_by_account( $u->account_id );

        // Map form_id → name for display.
        $form_names = array();
        foreach ( $forms as $f ) {
            $form_names[ (int) $f->id ] = $f->name;
        }
        ?>
        <div class="cmms-page-head">
            <div>
                <h1 class="cmms-page-title">
                    📥 <?php esc_html_e( 'מיילים נכנסים', 'cmms-light' ); ?>
                </h1>
                <p class="cmms-page-sub">
                    <?php esc_html_e( 'מיילים שהתקבלו דרך Email-to-Task והפכו למשימות. נשמרים 30 יום אחורה.', 'cmms-light' ); ?>
                </p>
            </div>
        </div>

        <div class="cmms-section">
            <div class="cmms-section-body">
                <?php // ── Filter by form ── ?>
                <form method="get" action="" class="cmms-flex cmms-gap-2 cmms-mb-3" style="align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="view" value="email_log">
                    <label style="font-size:13px;color:#475569;"><?php esc_html_e( 'סנן לפי טופס:', 'cmms-light' ); ?></label>
                    <select name="form_id" class="cmms-input" style="max-width:260px;" onchange="this.form.submit()">
                        <option value="0"><?php esc_html_e( 'כל הטפסים', 'cmms-light' ); ?></option>
                        <?php foreach ( $forms as $f ) : ?>
                            <option value="<?php echo (int) $f->id; ?>" <?php selected( $filter_form_id, (int) $f->id ); ?>>
                                <?php echo esc_html( $f->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ( empty( $rows ) ) : ?>
                    <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:32px;text-align:center;color:#64748b;">
                        <div style="font-size:32px;margin-bottom:8px;">📭</div>
                        <div style="font-size:14px;font-weight:600;color:#475569;margin-bottom:4px;">
                            <?php esc_html_e( 'עדיין לא התקבלו מיילים', 'cmms-light' ); ?>
                        </div>
                        <div style="font-size:12px;">
                            <?php esc_html_e( 'מיילים שיישלחו לכתובות ה-Email-to-Task יופיעו כאן.', 'cmms-light' ); ?>
                        </div>
                    </div>
                <?php else : ?>
                    <div style="overflow-x:auto;">
                        <table class="cmms-table" style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr style="border-bottom:2px solid #e2e8f0;text-align:right;">
                                    <th style="padding:8px;"><?php esc_html_e( 'נושא', 'cmms-light' ); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e( 'שולח', 'cmms-light' ); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e( 'טופס', 'cmms-light' ); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e( 'תאריך', 'cmms-light' ); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e( 'סטטוס', 'cmms-light' ); ?></th>
                                    <th style="padding:8px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $rows as $row ) :
                                    $is_ok = ( $row->status === 'created' );
                                    $status_bg    = $is_ok ? '#dcfce7' : '#fee2e2';
                                    $status_color = $is_ok ? '#166534' : '#991b1b';
                                    $status_text  = $is_ok ? __( 'נוצרה משימה', 'cmms-light' ) : __( 'נכשל', 'cmms-light' );
                                    $sender = $row->from_name ? $row->from_name : $row->from_email;
                                ?>
                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                        <td style="padding:8px;font-weight:500;color:#0f172a;max-width:240px;">
                                            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                <?php echo esc_html( $row->subject ?: __( '(ללא נושא)', 'cmms-light' ) ); ?>
                                            </div>
                                            <?php if ( ! $is_ok && $row->error_message ) : ?>
                                                <div style="font-size:11px;color:#b91c1c;margin-top:2px;">
                                                    <?php echo esc_html( $row->error_message ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:8px;color:#475569;max-width:180px;">
                                            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                <?php echo esc_html( $sender ?: '—' ); ?>
                                            </div>
                                        </td>
                                        <td style="padding:8px;color:#64748b;font-size:12px;">
                                            <?php echo esc_html( $form_names[ (int) $row->form_id ] ?? '—' ); ?>
                                        </td>
                                        <td style="padding:8px;color:#64748b;font-size:12px;white-space:nowrap;">
                                            <?php echo esc_html( mysql2date( 'd/m/Y H:i', $row->received_at ) ); ?>
                                        </td>
                                        <td style="padding:8px;">
                                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:<?php echo esc_attr( $status_bg ); ?>;color:<?php echo esc_attr( $status_color ); ?>;white-space:nowrap;">
                                                <?php echo esc_html( $status_text ); ?>
                                            </span>
                                        </td>
                                        <td style="padding:8px;white-space:nowrap;">
                                            <?php if ( $is_ok && $row->task_id ) : ?>
                                                <a class="cmms-btn cmms-btn-sm cmms-btn-ghost"
                                                   href="<?php echo esc_url( $this->url( array( 'view' => 'task', 'id' => (int) $row->task_id ) ) ); ?>"
                                                   style="font-size:11px;">
                                                    <?php esc_html_e( 'פתח משימה ←', 'cmms-light' ); ?>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
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
                                <?php // 1.15.11: Default assignee ("מוקצה ל") — the
                                      // technician who performs the work. Unlike the
                                      // manager (owner/manager roles only), the assignee
                                      // can be any account member, since technicians
                                      // are often the 'tech' role. ?>
                                <div class="cmms-field">
                                    <label class="cmms-field-label"><?php esc_html_e( 'מוקצה ל (ברירת מחדל)', 'cmms-light' ); ?></label>
                                    <select class="cmms-select" name="default_assignee_id">
                                        <option value=""><?php $this->e( 'common.none' ); ?></option>
                                        <?php foreach ( $users as $usr ) : ?>
                                            <option value="<?php echo (int) $usr->id; ?>" <?php selected( isset( $form->default_assignee_id ) && $form->default_assignee_id == $usr->id ); ?>><?php echo esc_html( $usr->display_name ); ?></option>
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

                <?php
                // 1.14.94: Webhook API section. Renders the API key UI,
                // documentation, and copy-paste cURL example. Hidden
                // behind a click-to-reveal because most users won't
                // need it — keeps the UI uncluttered for the 90% case.
                $this->render_form_webhook_section( $form );

                // 1.14.99: Email-to-Task section. Same lifecycle as the
                // webhook section (generate/rotate/revoke a public
                // identifier) but the identifier is an email address
                // local-part instead of an API key.
                $this->render_form_email_inbox_section( $form );
                ?>
            </aside>
        </div>
        <?php
    }

    /**
     * 1.14.94: Render the Webhook API section in the form-edit page
     * sidebar. Shown to managers/owners. The UI has two states:
     *
     *  - No key issued: a single "Generate API Key" button.
     *  - Key issued:    masked key + endpoint URL + cURL example +
     *                   field-mapping reference + rotate/revoke buttons.
     *
     * If a key was just generated (passed via ?cmms_new_key=... in the
     * URL), we show the FULL plaintext key once with a "save this" alert.
     */
    private function render_form_webhook_section( $form ) {
        // Permission: only managers/owners can manage webhook keys.
        // Reporters/technicians don't see this section at all.
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) return;

        $key_info  = CMMS_Webhook::get_key_info( $form->id );
        $webhook_url = CMMS_Webhook::webhook_url( $form );

        // One-time plaintext key reveal. Only shown right after generation.
        // The plaintext is passed as a transient (5 min TTL) so we don't
        // need to expose it via URL parameters.
        $just_generated_key = null;
        if ( isset( $_GET['cmms_key_token'] ) ) {
            $token = sanitize_key( wp_unslash( $_GET['cmms_key_token'] ) );
            $stored = get_transient( 'cmms_webhook_new_key_' . $token );
            if ( $stored && isset( $stored['form_id'] ) && (int) $stored['form_id'] === (int) $form->id ) {
                $just_generated_key = $stored['plaintext'];
                delete_transient( 'cmms_webhook_new_key_' . $token );
            }
        }

        $schema = CMMS_Webhook::get_schema_for_ui( $form );
        ?>
        <section class="cmms-section" style="margin-top:16px;">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title">
                    <?php CMMS_Icons::e( 'zap', 16 ); ?>
                    <?php echo esc_html__( 'Webhook API', 'cmms-light' ); ?>
                </h3>
            </div>
            <div class="cmms-section-body">
                <p class="cmms-text-sm cmms-mb-3" style="color:#475569;">
                    <?php echo esc_html__( 'Allow external systems (customer portals, ticketing systems) to create tasks on this form automatically by sending JSON over HTTPS.', 'cmms-light' ); ?>
                </p>

                <?php if ( $just_generated_key ) : ?>
                    <div class="cmms-alert cmms-alert-success" style="display:block;margin-bottom:12px;">
                        <div style="font-weight:600;margin-bottom:6px;">
                            <?php CMMS_Icons::e( 'check-circle', 16 ); ?>
                            <?php echo esc_html__( 'New API key created', 'cmms-light' ); ?>
                        </div>
                        <div style="font-size:13px;margin-bottom:8px;color:#065f46;">
                            <?php echo esc_html__( 'Copy this key now — it will not be shown again for security reasons. If you lose it, generate a new one.', 'cmms-light' ); ?>
                        </div>
                        <div style="display:flex;gap:6px;align-items:stretch;">
                            <input type="text" class="cmms-input" readonly
                                   value="<?php echo esc_attr( $just_generated_key ); ?>"
                                   id="cmms-webhook-new-key"
                                   onclick="this.select();"
                                   style="font-family:monospace;font-size:12px;background:#fff;">
                            <button type="button" class="cmms-btn cmms-btn-secondary cmms-btn-sm"
                                    onclick="(function(b){var i=document.getElementById('cmms-webhook-new-key');i.select();document.execCommand('copy');b.textContent='<?php echo esc_js( __( 'Copied', 'cmms-light' ) ); ?>';setTimeout(function(){b.textContent='<?php echo esc_js( __( 'Copy', 'cmms-light' ) ); ?>';},2000);})(this)">
                                <?php echo esc_html__( 'Copy', 'cmms-light' ); ?>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( $key_info ) : ?>
                    <?php // Key exists — show the live integration details. ?>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:12px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <div style="font-size:12px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.5px;">
                                <?php echo esc_html__( 'Status', 'cmms-light' ); ?>
                            </div>
                            <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;background:#dcfce7;color:#166534;">
                                <?php echo esc_html__( 'Active', 'cmms-light' ); ?>
                            </span>
                        </div>
                        <div style="font-size:12px;color:#64748b;line-height:1.6;">
                            <div><strong><?php echo esc_html__( 'Key', 'cmms-light' ); ?>:</strong> <code style="font-family:monospace;background:#fff;padding:1px 4px;border-radius:3px;"><?php echo esc_html( $key_info->key_prefix ); ?>…</code></div>
                            <div><strong><?php echo esc_html__( 'Requests', 'cmms-light' ); ?>:</strong> <?php echo (int) $key_info->request_count; ?></div>
                            <div><strong><?php echo esc_html__( 'Last used', 'cmms-light' ); ?>:</strong> <?php echo $key_info->last_used_at ? esc_html( $key_info->last_used_at ) : esc_html__( 'Never', 'cmms-light' ); ?></div>
                        </div>
                    </div>

                    <div class="cmms-field cmms-mb-3">
                        <label class="cmms-field-label"><?php echo esc_html__( 'Endpoint URL', 'cmms-light' ); ?></label>
                        <input type="text" class="cmms-input" readonly
                               value="<?php echo esc_attr( $webhook_url ); ?>"
                               onclick="this.select();"
                               style="font-family:monospace;font-size:11px;">
                    </div>

                    <?php
                    // ─────────────────────────────────────────────────────────
                    // Test connection panel (1.14.97)
                    // ─────────────────────────────────────────────────────────
                    // The user pastes their API key, clicks "Test connection",
                    // and we send a POST with "_test": true directly from the
                    // browser to the webhook endpoint — exactly the same path
                    // an external client would use. This validates end-to-end:
                    // the REST route is reachable, the key is correct, and the
                    // server-side handler returns the expected test response.
                    // The key is never sent to our own backend — it stays
                    // in the input field and flows straight to the webhook.
                    ?>
                    <div class="cmms-field cmms-mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;">
                        <label class="cmms-field-label" style="display:block;margin-bottom:6px;">
                            🔌 <?php echo esc_html__( 'Test connection', 'cmms-light' ); ?>
                        </label>
                        <div style="font-size:11px;color:#64748b;margin-bottom:8px;">
                            <?php if ( $just_generated_key ) : ?>
                                <?php echo esc_html__( 'Your new API key is filled in below — just click Test.', 'cmms-light' ); ?>
                            <?php else : ?>
                                <?php echo esc_html__( 'Paste an API key and click Test. Sends a real POST with "_test": true — no task is created.', 'cmms-light' ); ?>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:6px;align-items:stretch;">
                            <input type="password"
                                   class="cmms-input cmms-webhook-test-key"
                                   placeholder="cmms_..."
                                   value="<?php echo $just_generated_key ? esc_attr( $just_generated_key ) : ''; ?>"
                                   autocomplete="off"
                                   spellcheck="false"
                                   style="flex:1;font-family:monospace;font-size:12px;">
                            <button type="button"
                                    class="cmms-btn cmms-btn-sm cmms-btn-primary cmms-webhook-test-btn"
                                    data-webhook-url="<?php echo esc_attr( $webhook_url ); ?>"
                                    style="white-space:nowrap;">
                                <?php echo esc_html__( 'Test', 'cmms-light' ); ?>
                            </button>
                        </div>
                        <div class="cmms-webhook-test-result" style="display:none;margin-top:8px;padding:8px 10px;border-radius:4px;font-size:11px;line-height:1.6;"></div>
                    </div>
                    <script>
                    (function() {
                        // Global handler — survives multiple webhook sections
                        // on the same page (each form has its own).
                        if ( window.__cmmsWebhookTestBound ) return;
                        window.__cmmsWebhookTestBound = true;

                        document.addEventListener('click', function(e) {
                            var btn = e.target.closest('.cmms-webhook-test-btn');
                            if ( ! btn ) return;
                            e.preventDefault();

                            // The panel that contains this button — we look
                            // up the key input and result div relative to it,
                            // so multiple forms on the same page don't collide.
                            var panel = btn.closest('.cmms-field');
                            var keyInput = panel.querySelector('.cmms-webhook-test-key');
                            var resultDiv = panel.querySelector('.cmms-webhook-test-result');
                            var url = btn.getAttribute('data-webhook-url');
                            var key = (keyInput.value || '').trim();

                            // ── Validation ──
                            if ( ! key ) {
                                showResult(resultDiv, 'warn', '⚠️ ' + 'הדבק מפתח API לפני בדיקה');
                                keyInput.focus();
                                return;
                            }

                            // ── UI state: loading ──
                            var originalText = btn.textContent;
                            btn.disabled = true;
                            btn.textContent = '...';
                            showResult(resultDiv, 'info', '⏳ שולח בקשת בדיקה...');

                            // ── The actual request ──
                            // Same path an external client uses: POST with
                            // Bearer token + _test:true in JSON body.
                            fetch(url, {
                                method: 'POST',
                                headers: {
                                    'Authorization': 'Bearer ' + key,
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({ _test: true })
                            }).then(function(response) {
                                // Try to parse JSON regardless of status —
                                // we want the server's message on errors too.
                                return response.json().then(function(body) {
                                    return { status: response.status, body: body };
                                }).catch(function() {
                                    return { status: response.status, body: null };
                                });
                            }).then(function(result) {
                                renderTestResult(resultDiv, result);
                            }).catch(function(err) {
                                // Network error, CORS, DNS, etc.
                                showResult(resultDiv, 'error',
                                    '✗ <strong>שגיאת רשת</strong><br>' +
                                    '<span style="font-size:10px;opacity:0.85;">' +
                                    'לא ניתן להתחבר ל-endpoint. בדוק שהאתר נגיש ושאין חוסם רשת.' +
                                    '</span>'
                                );
                            }).finally(function() {
                                btn.disabled = false;
                                btn.textContent = originalText;
                            });
                        });

                        function renderTestResult(div, result) {
                            var status = result.status;
                            var body = result.body || {};

                            // ── 200: success ──
                            if ( status === 200 && body.success ) {
                                var preview = body.would_create_task || {};
                                var html = '✅ <strong>החיבור עובד!</strong><br>';
                                html += '<span style="font-size:10px;opacity:0.85;">';
                                html += 'הראוט פעיל, ה-API key תקין, השרת מוכן לקבל בקשות.';
                                html += '</span>';
                                if ( preview.form_name ) {
                                    html += '<div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(0,0,0,0.1);font-size:10px;">';
                                    html += '<strong>הטופס שזוהה:</strong> ' + escapeHtml(preview.form_name);
                                    if ( typeof preview.file_count === 'number' ) {
                                        html += ' &nbsp;·&nbsp; <strong>שדות שזוהו:</strong> ' +
                                                (preview.mapped_fields ? Object.keys(preview.mapped_fields).length : 0);
                                    }
                                    html += '</div>';
                                }
                                showResult(div, 'success', html);
                                return;
                            }

                            // ── 401 / 403: auth issue ──
                            if ( status === 401 || status === 403 ) {
                                showResult(div, 'error',
                                    '✗ <strong>מפתח API לא תקין</strong><br>' +
                                    '<span style="font-size:10px;opacity:0.85;">' +
                                    'הראוט פעיל, אבל המפתח שהדבקת נדחה. ייתכן שהוא בוטל או הוחלף.' +
                                    '</span>'
                                );
                                return;
                            }

                            // ── 404: route not registered ──
                            if ( status === 404 ) {
                                showResult(div, 'error',
                                    '✗ <strong>הראוט לא רשום</strong><br>' +
                                    '<span style="font-size:10px;opacity:0.85;">' +
                                    'לך ל: <em>הגדרות → קישורים קבועים → שמור</em>. זה ירענן את ה-rewrite rules ויפעיל את הוובהוק.' +
                                    '</span>'
                                );
                                return;
                            }

                            // ── 400: malformed (shouldn't happen with _test only) ──
                            if ( status === 400 ) {
                                var msg = body.message || body.code || 'בקשה לא תקינה';
                                showResult(div, 'warn',
                                    '⚠️ <strong>שגיאת בקשה</strong><br>' +
                                    '<span style="font-size:10px;opacity:0.85;">' + escapeHtml(msg) + '</span>'
                                );
                                return;
                            }

                            // ── Other ──
                            showResult(div, 'error',
                                '✗ <strong>שגיאה לא צפויה</strong> (HTTP ' + status + ')<br>' +
                                '<span style="font-size:10px;opacity:0.85;">' +
                                escapeHtml(body.message || body.code || 'אין פרטים נוספים') +
                                '</span>'
                            );
                        }

                        function showResult(div, kind, html) {
                            var styles = {
                                success: { bg: '#dcfce7', border: '#86efac', color: '#166534' },
                                error:   { bg: '#fee2e2', border: '#fca5a5', color: '#991b1b' },
                                warn:    { bg: '#fef3c7', border: '#fcd34d', color: '#92400e' },
                                info:    { bg: '#dbeafe', border: '#93c5fd', color: '#1e40af' }
                            };
                            var s = styles[kind] || styles.info;
                            div.style.display = 'block';
                            div.style.background = s.bg;
                            div.style.border = '1px solid ' + s.border;
                            div.style.color = s.color;
                            div.innerHTML = html;
                        }

                        function escapeHtml(s) {
                            if ( s == null ) return '';
                            return String(s)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;')
                                .replace(/'/g, '&#039;');
                        }
                    })();
                    </script>

                    <details style="margin-bottom:12px;">
                        <summary style="cursor:pointer;font-weight:600;font-size:13px;color:#0f172a;padding:6px 0;">
                            📋 <?php echo esc_html__( 'cURL example', 'cmms-light' ); ?>
                        </summary>
                        <pre style="background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;font-size:11px;overflow-x:auto;margin-top:6px;line-height:1.5;"><code><?php
                        $curl_example = "curl -X POST '" . esc_html( $webhook_url ) . "' \\\n"
                            . "  -H 'Authorization: Bearer YOUR_API_KEY' \\\n"
                            . "  -H 'Content-Type: application/json' \\\n"
                            . "  -d '" . wp_json_encode( array(
                                'title'       => 'Example task',
                                'description' => 'Created via webhook',
                                'priority'    => 'normal',
                            ), JSON_UNESCAPED_UNICODE ) . "'";
                        echo esc_html( $curl_example );
                        ?></code></pre>
                    </details>

                    <details style="margin-bottom:12px;">
                        <summary style="cursor:pointer;font-weight:600;font-size:13px;color:#0f172a;padding:6px 0;">
                            🗺️ <?php echo esc_html__( 'Accepted fields', 'cmms-light' ); ?>
                            (<?php echo count( $schema['standard'] ) + count( $schema['dynamic'] ); ?>)
                        </summary>
                        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px;margin-top:6px;font-size:12px;line-height:1.6;">
                            <div style="font-weight:600;color:#475569;margin-bottom:4px;">
                                <?php echo esc_html__( 'Standard fields', 'cmms-light' ); ?>
                            </div>
                            <?php foreach ( $schema['standard'] as $entry ) : ?>
                                <div style="padding:2px 0;">
                                    <code style="font-family:monospace;background:#f1f5f9;padding:1px 4px;border-radius:3px;"><?php echo esc_html( $entry['key'] ); ?></code>
                                    <span style="color:#94a3b8;">(<?php echo esc_html( $entry['type'] ); ?>)</span>
                                    <span style="color:#64748b;display:block;font-size:11px;padding-right:8px;"><?php echo esc_html( $entry['note'] ); ?></span>
                                </div>
                            <?php endforeach; ?>

                            <?php if ( ! empty( $schema['dynamic'] ) ) : ?>
                                <div style="font-weight:600;color:#475569;margin-top:8px;margin-bottom:4px;">
                                    <?php echo esc_html__( 'Form fields', 'cmms-light' ); ?>
                                </div>
                                <?php foreach ( $schema['dynamic'] as $entry ) : ?>
                                    <div style="padding:2px 0;">
                                        <code style="font-family:monospace;background:#f1f5f9;padding:1px 4px;border-radius:3px;"><?php echo esc_html( $entry['key'] ); ?></code>
                                        <span style="color:#94a3b8;">(<?php echo esc_html( $entry['type'] ); ?>)</span>
                                        <span style="color:#64748b;display:block;font-size:11px;padding-right:8px;">
                                            <?php echo esc_html( $entry['label'] ); ?>
                                            <?php if ( ! empty( $entry['accepted_values'] ) ) : ?>
                                                — <?php echo esc_html( implode( ', ', $entry['accepted_values'] ) ); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </details>

                    <details style="margin-bottom:12px;">
                        <summary style="cursor:pointer;font-weight:600;font-size:13px;color:#0f172a;padding:6px 0;">
                            👨‍💻 <?php echo esc_html__( 'Integration guide for developers', 'cmms-light' ); ?>
                        </summary>
                        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:12px;margin-top:6px;font-size:12px;line-height:1.7;color:#334155;">

                            <?php
                            // ===== Copy-to-clipboard button =====
                            // Builds the full integration guide as plain text so the user
                            // can paste it into WhatsApp/email/Slack to send to a developer.
                            // We build it server-side (single source of truth — same data
                            // shown on screen) rather than trying to scrape the rendered HTML.
                            $guide_text  = "📘 מדריך אינטגרציה - Webhook API\n";
                            $guide_text .= str_repeat( '=', 50 ) . "\n\n";
                            $guide_text .= "Endpoint URL:\n" . $webhook_url . "\n\n";
                            $guide_text .= "Method: POST\n\n";
                            $guide_text .= "Headers נדרשים:\n";
                            $guide_text .= "  Authorization: Bearer YOUR_API_KEY\n";
                            $guide_text .= "  Content-Type: application/json\n\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "קודי תשובה:\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "  200 OK  - המשימה נוצרה. תשובה: {\"success\":true,\"task_id\":123}\n";
                            $guide_text .= "  400     - JSON לא תקין או שדה לא חוקי\n";
                            $guide_text .= "  401     - מפתח API חסר או שגוי\n";
                            $guide_text .= "  403     - הגישה נדחתה (מפתח בוטל)\n";
                            $guide_text .= "  404     - הטופס לא נמצא\n\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "מצב בדיקה:\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= 'הוסף "_test": true ל-JSON כדי לבדוק את החיבור בלי ליצור משימה אמיתית.' . "\n\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "דוגמת cURL:\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "curl -X POST '" . $webhook_url . "' \\\n";
                            $guide_text .= "  -H 'Authorization: Bearer YOUR_API_KEY' \\\n";
                            $guide_text .= "  -H 'Content-Type: application/json' \\\n";
                            $guide_text .= "  -d '{\"title\":\"תקלה חדשה\",\"description\":\"פרטי התקלה\",\"priority\":\"high\"}'\n\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "דוגמת PHP:\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "<?php\n";
                            $guide_text .= "\$response = wp_remote_post( '" . $webhook_url . "',\n";
                            $guide_text .= "    array(\n";
                            $guide_text .= "        'headers' => array(\n";
                            $guide_text .= "            'Authorization' => 'Bearer YOUR_API_KEY',\n";
                            $guide_text .= "            'Content-Type'  => 'application/json',\n";
                            $guide_text .= "        ),\n";
                            $guide_text .= "        'body' => wp_json_encode( array(\n";
                            $guide_text .= "            'title'       => 'תקלה חדשה מהפורטל',\n";
                            $guide_text .= "            'description' => 'פרטי התקלה',\n";
                            $guide_text .= "            'priority'    => 'high',\n";
                            $guide_text .= "        ) ),\n";
                            $guide_text .= "        'timeout' => 15,\n";
                            $guide_text .= "    )\n";
                            $guide_text .= ");\n\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "דוגמת JavaScript (Node.js / Browser):\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "const response = await fetch('" . $webhook_url . "', {\n";
                            $guide_text .= "    method: 'POST',\n";
                            $guide_text .= "    headers: {\n";
                            $guide_text .= "        'Authorization': 'Bearer YOUR_API_KEY',\n";
                            $guide_text .= "        'Content-Type': 'application/json'\n";
                            $guide_text .= "    },\n";
                            $guide_text .= "    body: JSON.stringify({\n";
                            $guide_text .= "        title: 'תקלה חדשה מהפורטל',\n";
                            $guide_text .= "        description: 'פרטי התקלה',\n";
                            $guide_text .= "        priority: 'high'\n";
                            $guide_text .= "    })\n";
                            $guide_text .= "});\n";
                            $guide_text .= "const data = await response.json();\n";
                            $guide_text .= "// data.task_id — מזהה המשימה שנוצרה\n\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "דוגמת Python (requests):\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "import requests\n\n";
                            $guide_text .= "response = requests.post(\n";
                            $guide_text .= "    '" . $webhook_url . "',\n";
                            $guide_text .= "    headers={\n";
                            $guide_text .= "        'Authorization': 'Bearer YOUR_API_KEY',\n";
                            $guide_text .= "        'Content-Type': 'application/json'\n";
                            $guide_text .= "    },\n";
                            $guide_text .= "    json={\n";
                            $guide_text .= "        'title': 'תקלה חדשה מהפורטל',\n";
                            $guide_text .= "        'description': 'פרטי התקלה',\n";
                            $guide_text .= "        'priority': 'high'\n";
                            $guide_text .= "    },\n";
                            $guide_text .= "    timeout=15\n";
                            $guide_text .= ")\n";
                            $guide_text .= "task_id = response.json().get('task_id')\n\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "טיפים חשובים:\n";
                            $guide_text .= str_repeat( '-', 50 ) . "\n";
                            $guide_text .= "  • אל תחשוף את ה-API key בקוד client (HTML/JS בדפדפן). השתמש בו רק בצד שרת.\n";
                            $guide_text .= "  • אפשר לשלוח שדות לפי שם השדה (label) או לפי field_<ID>.\n";
                            $guide_text .= "  • שדות לא מזוהים יחזרו ב-ignored_keys בתשובה — שימושי לדיבוג.\n";
                            $guide_text .= '  • במצב בדיקה ("_test": true) לא נוצרת משימה ולא נשלחות התראות.' . "\n";
                            $guide_text .= "  • לקבצים שלח URL ציבורי בשדה הקובץ - השרת יוריד וצרף.\n";
                            ?>

                            <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
                                <button type="button"
                                        class="cmms-btn cmms-btn-sm cmms-btn-secondary cmms-copy-guide-btn"
                                        data-guide-text="<?php echo esc_attr( $guide_text ); ?>"
                                        style="font-size:11px;display:inline-flex;align-items:center;gap:4px;">
                                    📋 <span class="cmms-copy-guide-label"><?php echo esc_html__( 'Copy full guide', 'cmms-light' ); ?></span>
                                </button>
                            </div>
                            <script>
                            (function() {
                                // Single delegated handler — survives re-renders and
                                // works for multiple forms on the same page (each form
                                // has its own copy button with its own guide text).
                                if ( window.__cmmsCopyGuideBound ) return;
                                window.__cmmsCopyGuideBound = true;
                                document.addEventListener('click', function(e) {
                                    var btn = e.target.closest('.cmms-copy-guide-btn');
                                    if ( ! btn ) return;
                                    e.preventDefault();
                                    var text = btn.getAttribute('data-guide-text') || '';
                                    var label = btn.querySelector('.cmms-copy-guide-label');
                                    var originalLabel = label ? label.textContent : '';
                                    var showFeedback = function( msg ) {
                                        if ( ! label ) return;
                                        label.textContent = msg;
                                        setTimeout(function() { label.textContent = originalLabel; }, 2000);
                                    };
                                    // Modern clipboard API (requires HTTPS or localhost).
                                    if ( navigator.clipboard && navigator.clipboard.writeText ) {
                                        navigator.clipboard.writeText( text ).then(function() {
                                            showFeedback('✓ הועתק!');
                                        }).catch(function() {
                                            // Fallback for permission-denied edge cases.
                                            fallbackCopy( text, showFeedback );
                                        });
                                    } else {
                                        fallbackCopy( text, showFeedback );
                                    }
                                });
                                // Fallback: textarea + execCommand. Used on non-HTTPS
                                // installs (some local dev environments) and very old browsers.
                                function fallbackCopy( text, showFeedback ) {
                                    var ta = document.createElement('textarea');
                                    ta.value = text;
                                    ta.style.position = 'fixed';
                                    ta.style.left = '-9999px';
                                    document.body.appendChild(ta);
                                    ta.select();
                                    try {
                                        document.execCommand('copy');
                                        showFeedback('✓ הועתק!');
                                    } catch (err) {
                                        showFeedback('✗ שגיאה');
                                    }
                                    document.body.removeChild(ta);
                                }
                            })();
                            </script>

                            <?php // ===== Overview ===== ?>
                            <div style="background:#eff6ff;border-right:3px solid #3b82f6;padding:8px 10px;border-radius:4px;margin-bottom:12px;">
                                <div style="font-weight:600;color:#1e40af;margin-bottom:4px;">סקירה כללית</div>
                                <div style="color:#1e3a8a;font-size:11px;">
                                    שלח בקשת <code style="background:#dbeafe;padding:1px 4px;border-radius:3px;">POST</code> עם גוף בפורמט JSON ל-endpoint. כל בקשה מוצלחת תיצור משימה חדשה במערכת. נדרש מפתח API ב-header.
                                </div>
                            </div>

                            <?php // ===== Endpoint & Auth ===== ?>
                            <div style="margin-bottom:14px;">
                                <div style="font-weight:600;color:#0f172a;margin-bottom:4px;font-size:12px;">
                                    1. כתובת ו-Headers נדרשים
                                </div>
                                <div style="margin-bottom:6px;">
                                    <code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;font-size:11px;">POST <?php echo esc_html( $webhook_url ); ?></code>
                                </div>
                                <div style="font-size:11px;color:#475569;">חובה לשלוח שני headers:</div>
                                <pre style="background:#0f172a;color:#e2e8f0;padding:8px 10px;border-radius:4px;font-size:11px;margin:4px 0 0 0;overflow-x:auto;direction:ltr;text-align:left;"><code>Authorization: Bearer YOUR_API_KEY
Content-Type: application/json</code></pre>
                            </div>

                            <?php // ===== Response codes ===== ?>
                            <div style="margin-bottom:14px;">
                                <div style="font-weight:600;color:#0f172a;margin-bottom:4px;font-size:12px;">
                                    2. קודי תשובה
                                </div>
                                <div style="font-size:11px;">
                                    <div style="padding:3px 0;">
                                        <code style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:3px;font-weight:600;">200 OK</code>
                                        — המשימה נוצרה. גוף התשובה: <code style="background:#f1f5f9;padding:1px 4px;border-radius:3px;">{"success":true,"task_id":123}</code>
                                    </div>
                                    <div style="padding:3px 0;">
                                        <code style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-weight:600;">400</code>
                                        — JSON לא תקין או שדה לא חוקי
                                    </div>
                                    <div style="padding:3px 0;">
                                        <code style="background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:3px;font-weight:600;">401</code>
                                        — מפתח API חסר או שגוי
                                    </div>
                                    <div style="padding:3px 0;">
                                        <code style="background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:3px;font-weight:600;">403</code>
                                        — הגישה נדחתה (מפתח בוטל)
                                    </div>
                                    <div style="padding:3px 0;">
                                        <code style="background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:3px;font-weight:600;">404</code>
                                        — הטופס לא נמצא
                                    </div>
                                </div>
                            </div>

                            <?php // ===== Test mode ===== ?>
                            <div style="margin-bottom:14px;">
                                <div style="font-weight:600;color:#0f172a;margin-bottom:4px;font-size:12px;">
                                    3. מצב בדיקה
                                </div>
                                <div style="font-size:11px;color:#475569;">
                                    הוסף <code style="background:#f1f5f9;padding:1px 4px;border-radius:3px;">"_test": true</code> ל-JSON כדי לבדוק את החיבור בלי ליצור משימה אמיתית. תקבל תשובה שמראה איך המערכת פירשה את הנתונים שלך.
                                </div>
                            </div>

                            <?php // ===== Examples in different languages ===== ?>
                            <div style="margin-bottom:14px;">
                                <div style="font-weight:600;color:#0f172a;margin-bottom:6px;font-size:12px;">
                                    4. דוגמאות קוד
                                </div>

                                <?php // ----- PHP example ----- ?>
                                <div style="font-size:11px;color:#475569;margin:8px 0 4px 0;font-weight:600;">PHP</div>
                                <pre style="background:#0f172a;color:#e2e8f0;padding:10px;border-radius:4px;font-size:11px;overflow-x:auto;margin:0;line-height:1.5;direction:ltr;text-align:left;"><code><?php
$php_example = '<?php' . "\n"
    . '$response = wp_remote_post( ' . "'" . esc_html( $webhook_url ) . "',\n"
    . '    array(' . "\n"
    . '        \'headers\' => array(' . "\n"
    . '            \'Authorization\' => \'Bearer YOUR_API_KEY\',' . "\n"
    . '            \'Content-Type\'  => \'application/json\',' . "\n"
    . '        ),' . "\n"
    . '        \'body\' => wp_json_encode( array(' . "\n"
    . '            \'title\'       => \'תקלה חדשה מהפורטל\',' . "\n"
    . '            \'description\' => \'פרטי התקלה\',' . "\n"
    . '            \'priority\'    => \'high\',' . "\n"
    . '        ) ),' . "\n"
    . '        \'timeout\' => 15,' . "\n"
    . '    )' . "\n"
    . ');' . "\n\n"
    . 'if ( is_wp_error( $response ) ) {' . "\n"
    . '    error_log( $response->get_error_message() );' . "\n"
    . '} else {' . "\n"
    . '    $body = json_decode( wp_remote_retrieve_body( $response ), true );' . "\n"
    . '    // $body[\'task_id\'] — מזהה המשימה שנוצרה' . "\n"
    . '}';
echo esc_html( $php_example );
?></code></pre>

                                <?php // ----- JavaScript example ----- ?>
                                <div style="font-size:11px;color:#475569;margin:10px 0 4px 0;font-weight:600;">JavaScript (Node.js / Browser)</div>
                                <pre style="background:#0f172a;color:#e2e8f0;padding:10px;border-radius:4px;font-size:11px;overflow-x:auto;margin:0;line-height:1.5;direction:ltr;text-align:left;"><code><?php
$js_example = "const response = await fetch('" . esc_html( $webhook_url ) . "', {\n"
    . "  method: 'POST',\n"
    . "  headers: {\n"
    . "    'Authorization': 'Bearer YOUR_API_KEY',\n"
    . "    'Content-Type': 'application/json'\n"
    . "  },\n"
    . "  body: JSON.stringify({\n"
    . "    title: 'תקלה חדשה מהפורטל',\n"
    . "    description: 'פרטי התקלה',\n"
    . "    priority: 'high'\n"
    . "  })\n"
    . "});\n\n"
    . "const data = await response.json();\n"
    . "console.log('Task ID:', data.task_id);";
echo esc_html( $js_example );
?></code></pre>

                                <?php // ----- Python example ----- ?>
                                <div style="font-size:11px;color:#475569;margin:10px 0 4px 0;font-weight:600;">Python</div>
                                <pre style="background:#0f172a;color:#e2e8f0;padding:10px;border-radius:4px;font-size:11px;overflow-x:auto;margin:0;line-height:1.5;direction:ltr;text-align:left;"><code><?php
$py_example = "import requests\n\n"
    . "response = requests.post(\n"
    . "    '" . esc_html( $webhook_url ) . "',\n"
    . "    headers={\n"
    . "        'Authorization': 'Bearer YOUR_API_KEY',\n"
    . "        'Content-Type': 'application/json'\n"
    . "    },\n"
    . "    json={\n"
    . "        'title': 'תקלה חדשה מהפורטל',\n"
    . "        'description': 'פרטי התקלה',\n"
    . "        'priority': 'high'\n"
    . "    },\n"
    . "    timeout=15\n"
    . ")\n\n"
    . "data = response.json()\n"
    . "print('Task ID:', data['task_id'])";
echo esc_html( $py_example );
?></code></pre>
                            </div>

                            <?php // ===== Tips ===== ?>
                            <div style="background:#fefce8;border-right:3px solid #eab308;padding:8px 10px;border-radius:4px;">
                                <div style="font-weight:600;color:#854d0e;margin-bottom:4px;">💡 טיפים חשובים</div>
                                <ul style="margin:0;padding-right:18px;color:#713f12;font-size:11px;">
                                    <li>אל תחשוף את מפתח ה-API בקוד client-side (דפדפן/אפליקציה). השתמש בו רק בשרת.</li>
                                    <li>כל שדה מותר להישלח גם לפי <code style="background:#fef3c7;padding:1px 4px;border-radius:3px;">field_ID</code> וגם לפי שם השדה (כפי שמופיע ברשימת השדות למעלה).</li>
                                    <li>שדות לא מזוהים יוחזרו ברשימת <code style="background:#fef3c7;padding:1px 4px;border-radius:3px;">ignored_keys</code> בתשובה — שימושי לדיבוג.</li>
                                    <li>במצב בדיקה (<code style="background:#fef3c7;padding:1px 4px;border-radius:3px;">_test: true</code>) לא נוצרת משימה ולא נשלחות התראות.</li>
                                    <li>הקובץ צריך להישלח כ-URL ציבורי בשדה הקובץ — השרת יוריד אותו ויצרף אותו למשימה.</li>
                                </ul>
                            </div>

                        </div>
                    </details>

                    <div style="display:flex;gap:8px;">
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="flex:1;"
                              onsubmit="return confirm('<?php echo esc_js( __( 'Generate a new key? The current key will stop working immediately.', 'cmms-light' ) ); ?>');">
                            <input type="hidden" name="action" value="cmms_webhook_rotate">
                            <input type="hidden" name="form_id" value="<?php echo (int) $form->id; ?>">
                            <?php wp_nonce_field( 'cmms_webhook_rotate', 'cmms_webhook_rotate_nonce' ); ?>
                            <button type="submit" class="cmms-btn cmms-btn-sm cmms-btn-secondary" style="width:100%;">
                                <?php CMMS_Icons::e( 'refresh-cw', 14 ); ?>
                                <?php echo esc_html__( 'Rotate key', 'cmms-light' ); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="flex:1;"
                              onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this key? External integrations using it will stop working.', 'cmms-light' ) ); ?>');">
                            <input type="hidden" name="action" value="cmms_webhook_revoke">
                            <input type="hidden" name="form_id" value="<?php echo (int) $form->id; ?>">
                            <?php wp_nonce_field( 'cmms_webhook_revoke', 'cmms_webhook_revoke_nonce' ); ?>
                            <button type="submit" class="cmms-btn cmms-btn-sm cmms-btn-ghost" style="width:100%;color:#b91c1c;">
                                <?php CMMS_Icons::e( 'x-circle', 14 ); ?>
                                <?php echo esc_html__( 'Revoke', 'cmms-light' ); ?>
                            </button>
                        </form>
                    </div>

                    <?php
                    // ─────────────────────────────────────────────────────────
                    // Destructive-action warning (1.14.97)
                    // ─────────────────────────────────────────────────────────
                    // Both Rotate and Revoke break running integrations
                    // instantly. Users need to know what each one does and
                    // when to use it BEFORE clicking — the confirm() dialog
                    // alone isn't enough context.
                    ?>
                    <div style="margin-top:10px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:10px 12px;font-size:11px;line-height:1.6;color:#78350f;">
                        <div style="font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:4px;">
                            ⚠️ <?php echo esc_html__( 'Important — these actions break running integrations', 'cmms-light' ); ?>
                        </div>
                        <div style="margin-bottom:5px;">
                            <strong><?php echo esc_html__( 'Rotate key', 'cmms-light' ); ?>:</strong>
                            <?php echo esc_html__( 'cancels the current key and creates a new one. Use this when the key may have leaked, after staff turnover, or for periodic security refresh. You\'ll need to send the new key to anyone using the integration.', 'cmms-light' ); ?>
                        </div>
                        <div style="margin-bottom:5px;">
                            <strong><?php echo esc_html__( 'Revoke', 'cmms-light' ); ?>:</strong>
                            <?php echo esc_html__( 'cancels the key without creating a new one. The webhook stops accepting requests entirely. Use this when ending a partnership or to immediately block a suspected leak.', 'cmms-light' ); ?>
                        </div>
                        <div style="margin-top:8px;padding-top:6px;border-top:1px solid #fcd34d;font-size:10px;">
                            💡 <?php echo esc_html__( 'Before rotating: make sure you have a fast way to deliver the new key to whoever is using the integration. Until they update it, their requests will return 401.', 'cmms-light' ); ?>
                        </div>
                    </div>

                <?php else : ?>
                    <?php // No key issued yet — single CTA. ?>
                    <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>">
                        <input type="hidden" name="action" value="cmms_webhook_generate">
                        <input type="hidden" name="form_id" value="<?php echo (int) $form->id; ?>">
                        <?php wp_nonce_field( 'cmms_webhook_generate', 'cmms_webhook_generate_nonce' ); ?>
                        <button type="submit" class="cmms-btn cmms-btn-primary" style="width:100%;">
                            <?php CMMS_Icons::e( 'zap', 16 ); ?>
                            <?php echo esc_html__( 'Generate API key', 'cmms-light' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * 1.14.99: Render the Email-to-Task section in the form-edit page.
     * Same shape as the webhook section. Two states:
     *
     *  - No route: a single "Create email address" button.
     *  - Route exists: shows the public address, status, copy button,
     *                  Test panel, and rotate/revoke controls.
     *
     * The email address is NOT a secret (unlike webhook keys) — it's
     * the public local-part of the address users will forward emails
     * to. So we show it in plain text, no transient/one-time reveal.
     */
    private function render_form_email_inbox_section( $form ) {
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) return;

        $route = CMMS_Email_Inbox::get_route_info( $form->id );
        $email_address = $route ? CMMS_Email_Inbox::email_address( $route ) : '';
        ?>
        <section class="cmms-section" style="margin-top:16px;">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title">
                    <?php CMMS_Icons::e( 'mail', 16 ); ?>
                    <?php echo esc_html__( 'Email-to-Task', 'cmms-light' ); ?>
                </h3>
            </div>
            <div class="cmms-section-body">
                <p class="cmms-text-sm cmms-mb-3" style="color:#475569;">
                    <?php echo esc_html__( 'Forward emails to a dedicated address and they will be turned into tasks automatically. The subject becomes the title, the body becomes the description, and attachments are saved with the task.', 'cmms-light' ); ?>
                </p>

                <?php if ( $route ) : ?>
                    <?php
                    // ── Status badge ──
                    $status_class = $route->enabled ? 'background:#dcfce7;color:#166534;' : 'background:#fee2e2;color:#991b1b;';
                    $status_text  = $route->enabled ? __( 'Active', 'cmms-light' ) : __( 'Disabled', 'cmms-light' );
                    ?>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:12px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span>
                            <strong><?php echo esc_html__( 'STATUS', 'cmms-light' ); ?></strong>
                        </div>
                        <div style="color:#475569;line-height:1.7;">
                            <div><strong><?php echo esc_html__( 'Created', 'cmms-light' ); ?>:</strong> <?php echo esc_html( $route->created_at ); ?></div>
                            <div><strong><?php echo esc_html__( 'Emails received', 'cmms-light' ); ?>:</strong> <?php echo (int) $route->request_count; ?></div>
                            <div><strong><?php echo esc_html__( 'Last email', 'cmms-light' ); ?>:</strong> <?php echo $route->last_used_at ? esc_html( $route->last_used_at ) : esc_html__( 'Never', 'cmms-light' ); ?></div>
                        </div>
                        <?php // 1.15.5: Link to the dedicated inbound log, filtered to this form. ?>
                        <div style="margin-top:8px;padding-top:8px;border-top:1px solid #e2e8f0;">
                            <a href="<?php echo esc_url( $this->url( array( 'view' => 'email_log', 'form_id' => (int) $form->id ) ) ); ?>"
                               style="font-size:12px;color:#0369a1;text-decoration:none;font-weight:600;">
                                📥 <?php esc_html_e( 'צפה במיילים שהתקבלו בטופס זה', 'cmms-light' ); ?> ←
                            </a>
                        </div>
                    </div>

                    <?php // ── The email address (the main thing) ── ?>
                    <div class="cmms-field cmms-mb-3">
                        <label class="cmms-field-label">
                            📧 <?php echo esc_html__( 'Email address — forward emails here', 'cmms-light' ); ?>
                        </label>
                        <div style="display:flex;gap:6px;align-items:stretch;">
                            <input type="text"
                                   class="cmms-input cmms-email-inbox-address"
                                   readonly
                                   value="<?php echo esc_attr( $email_address ); ?>"
                                   onclick="this.select();"
                                   style="flex:1;font-family:monospace;font-size:12px;background:#fff;">
                            <button type="button"
                                    class="cmms-btn cmms-btn-sm cmms-btn-secondary cmms-email-inbox-copy-btn"
                                    data-address="<?php echo esc_attr( $email_address ); ?>"
                                    style="white-space:nowrap;">
                                <span class="cmms-email-inbox-copy-label">📋 <?php echo esc_html__( 'Copy', 'cmms-light' ); ?></span>
                            </button>
                        </div>
                        <div style="font-size:11px;color:#64748b;margin-top:6px;">
                            <?php echo esc_html__( 'Send or forward any email to this address — a task will be created automatically.', 'cmms-light' ); ?>
                        </div>
                    </div>

                    <?php // ── Two ways to use it ── ?>
                    <details style="margin-bottom:12px;">
                        <summary style="cursor:pointer;font-weight:600;font-size:13px;color:#0f172a;padding:6px 0;">
                            💡 <?php echo esc_html__( 'How to use it (2 ways)', 'cmms-light' ); ?>
                        </summary>
                        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:12px;margin-top:6px;font-size:12px;line-height:1.7;color:#334155;">

                            <?php // ===== Way 1: Manual forwarding ===== ?>
                            <div style="margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;">
                                <div style="font-weight:600;color:#0f172a;margin-bottom:4px;">
                                    <?php echo esc_html__( '1. Manual forwarding (no setup needed)', 'cmms-light' ); ?>
                                </div>
                                <div style="font-size:11px;color:#475569;">
                                    <?php echo esc_html__( 'Got an email about an issue? Forward it to this address. A task will be created with the subject as the title and the body as the description.', 'cmms-light' ); ?>
                                </div>
                            </div>

                            <?php // ===== Way 2: Automatic forwarding ===== ?>
                            <div>
                                <div style="font-weight:600;color:#0f172a;margin-bottom:6px;">
                                    <?php echo esc_html__( '2. Automatic forwarding (setup once, runs forever)', 'cmms-light' ); ?>
                                </div>
                                <div style="font-size:11px;color:#475569;margin-bottom:10px;">
                                    <?php echo esc_html__( 'Forward every email arriving at your support address (e.g. support@yourcompany.com) to this address. Choose your email provider:', 'cmms-light' ); ?>
                                </div>

                                <?php
                                // ── Build the per-provider instructions as
                                // plain text. We render them on screen, AND
                                // make them copyable so the user can send
                                // them to a customer/IT person.
                                // ──
                                // The customer needs to know the destination
                                // address — we embed it in each instruction
                                // so they don't have to remember where they
                                // got it from.
                                $gmail_steps_text  = "📧 הוראות הגדרת forwarding ב-Gmail\n";
                                $gmail_steps_text .= str_repeat( '=', 50 ) . "\n\n";
                                $gmail_steps_text .= "כתובת היעד (העבר את כל המיילים לכאן):\n";
                                $gmail_steps_text .= $email_address . "\n\n";
                                $gmail_steps_text .= "שלבים:\n";
                                $gmail_steps_text .= "1. היכנס לג'ימייל בדפדפן (לא באפליקציה)\n";
                                $gmail_steps_text .= "2. לחץ על גלגל השיניים (⚙) בפינה הימנית העליונה\n";
                                $gmail_steps_text .= "   ובחר 'See all settings' / 'הצג את כל ההגדרות'\n";
                                $gmail_steps_text .= "3. עבור לטאב 'Forwarding and POP/IMAP' /\n";
                                $gmail_steps_text .= "   'העברה ו-POP/IMAP'\n";
                                $gmail_steps_text .= "4. לחץ 'Add a forwarding address' /\n";
                                $gmail_steps_text .= "   'הוסף כתובת העברה'\n";
                                $gmail_steps_text .= "5. הדבק את כתובת היעד:\n";
                                $gmail_steps_text .= "   " . $email_address . "\n";
                                $gmail_steps_text .= "6. גוגל ישלח מייל אישור לכתובת היעד.\n";
                                $gmail_steps_text .= "   ⚠️ חשוב: תצטרך לבקש את קוד האישור מנותן השירות\n";
                                $gmail_steps_text .= "   (CMMS Light) שיאמת את כתובת היעד.\n";
                                $gmail_steps_text .= "7. אחרי האישור, חזור להגדרות וסמן\n";
                                $gmail_steps_text .= "   'Forward a copy of incoming mail to...'\n";
                                $gmail_steps_text .= "8. שמור את השינויים בתחתית הדף\n\n";
                                $gmail_steps_text .= "טיפ: אם רוצים להעביר רק חלק מהמיילים\n";
                                $gmail_steps_text .= "(למשל לפי נושא או שולח) - השתמש בפילטרים:\n";
                                $gmail_steps_text .= "Settings → Filters and Blocked Addresses →\n";
                                $gmail_steps_text .= "Create a new filter → בחר 'Forward it to:'\n";

                                $outlook_steps_text  = "📧 הוראות הגדרת forwarding ב-Outlook\n";
                                $outlook_steps_text .= str_repeat( '=', 50 ) . "\n\n";
                                $outlook_steps_text .= "כתובת היעד:\n";
                                $outlook_steps_text .= $email_address . "\n\n";
                                $outlook_steps_text .= "שלבים (Outlook 365 / Outlook.com):\n";
                                $outlook_steps_text .= "1. היכנס ל-Outlook בדפדפן\n";
                                $outlook_steps_text .= "2. לחץ על גלגל השיניים (⚙) בפינה הימנית\n";
                                $outlook_steps_text .= "   העליונה ובחר 'View all Outlook settings'\n";
                                $outlook_steps_text .= "3. Mail → Forwarding\n";
                                $outlook_steps_text .= "4. סמן 'Enable forwarding'\n";
                                $outlook_steps_text .= "5. הדבק את כתובת היעד:\n";
                                $outlook_steps_text .= "   " . $email_address . "\n";
                                $outlook_steps_text .= "6. (מומלץ) סמן גם 'Keep a copy of forwarded\n";
                                $outlook_steps_text .= "   messages' כדי שעותק יישאר אצלך\n";
                                $outlook_steps_text .= "7. Save\n\n";
                                $outlook_steps_text .= "Outlook עם Exchange ארגוני:\n";
                                $outlook_steps_text .= "Settings → Mail → Rules → Add new rule →\n";
                                $outlook_steps_text .= "Action: Forward to → " . $email_address . "\n";

                                $other_steps_text  = "📧 העברה אוטומטית במערכות אחרות\n";
                                $other_steps_text .= str_repeat( '=', 50 ) . "\n\n";
                                $other_steps_text .= "כתובת היעד:\n";
                                $other_steps_text .= $email_address . "\n\n";
                                $other_steps_text .= "כל מערכת דואר תומכת ב-forwarding.\n";
                                $other_steps_text .= "חפש את ההגדרה תחת אחד מהמונחים:\n\n";
                                $other_steps_text .= "  - Forwarding\n";
                                $other_steps_text .= "  - Auto-forward\n";
                                $other_steps_text .= "  - Mail forwarding rules\n";
                                $other_steps_text .= "  - העברה / העברה אוטומטית\n\n";
                                $other_steps_text .= "במערכות מסוימות נדרש אישור הכתובת לפני\n";
                                $other_steps_text .= "ההפעלה - אם תקבל בקשה לקוד אישור,\n";
                                $other_steps_text .= "פנה לנותן השירות.\n";
                                ?>

                                <?php // ── Provider tabs ── ?>
                                <div class="cmms-email-tabs" style="display:flex;gap:4px;margin-bottom:8px;">
                                    <button type="button"
                                            class="cmms-email-tab-btn cmms-active"
                                            data-tab="gmail"
                                            style="flex:1;padding:6px 8px;border:1px solid #e2e8f0;background:#fff;border-radius:4px;font-size:11px;cursor:pointer;font-weight:600;">
                                        Gmail
                                    </button>
                                    <button type="button"
                                            class="cmms-email-tab-btn"
                                            data-tab="outlook"
                                            style="flex:1;padding:6px 8px;border:1px solid #e2e8f0;background:#fff;border-radius:4px;font-size:11px;cursor:pointer;">
                                        Outlook
                                    </button>
                                    <button type="button"
                                            class="cmms-email-tab-btn"
                                            data-tab="other"
                                            style="flex:1;padding:6px 8px;border:1px solid #e2e8f0;background:#fff;border-radius:4px;font-size:11px;cursor:pointer;">
                                        <?php echo esc_html__( 'Other', 'cmms-light' ); ?>
                                    </button>
                                </div>

                                <?php // ── Gmail panel ── ?>
                                <div class="cmms-email-tab-panel cmms-email-tab-gmail" style="display:block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:10px;font-size:11px;line-height:1.6;">
                                    <ol style="margin:0;padding-right:18px;color:#334155;">
                                        <li><?php echo esc_html__( 'Sign in to Gmail in a web browser (not the app)', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'Click the gear icon ⚙ → "See all settings"', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'Go to the "Forwarding and POP/IMAP" tab', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'Click "Add a forwarding address" and paste the address above', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'Google will send a verification email. Contact your service provider for the verification code.', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'After verification, select "Forward a copy of incoming mail to..." and save', 'cmms-light' ); ?></li>
                                    </ol>
                                    <div style="margin-top:8px;padding:6px 8px;background:#dbeafe;border-radius:3px;color:#1e40af;font-size:10px;">
                                        💡 <?php echo esc_html__( 'Tip: To forward only specific emails, use Gmail filters: Settings → Filters and Blocked Addresses → Create a new filter.', 'cmms-light' ); ?>
                                    </div>
                                </div>

                                <?php // ── Outlook panel ── ?>
                                <div class="cmms-email-tab-panel cmms-email-tab-outlook" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:10px;font-size:11px;line-height:1.6;">
                                    <div style="font-weight:600;margin-bottom:4px;color:#0f172a;">Outlook 365 / Outlook.com</div>
                                    <ol style="margin:0 0 10px 0;padding-right:18px;color:#334155;">
                                        <li><?php echo esc_html__( 'Sign in to Outlook in a web browser', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'Click the gear icon ⚙ → "View all Outlook settings"', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'Mail → Forwarding', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'Check "Enable forwarding" and paste the address above', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'Recommended: also check "Keep a copy of forwarded messages"', 'cmms-light' ); ?></li>
                                        <li><?php echo esc_html__( 'Save', 'cmms-light' ); ?></li>
                                    </ol>
                                    <div style="font-weight:600;margin-bottom:4px;color:#0f172a;"><?php echo esc_html__( 'Outlook with corporate Exchange', 'cmms-light' ); ?></div>
                                    <div style="color:#475569;">
                                        <?php echo esc_html__( 'Settings → Mail → Rules → Add new rule → Action: Forward to → paste the address.', 'cmms-light' ); ?>
                                    </div>
                                </div>

                                <?php // ── Other panel ── ?>
                                <div class="cmms-email-tab-panel cmms-email-tab-other" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:10px;font-size:11px;line-height:1.6;color:#334155;">
                                    <p style="margin:0 0 8px 0;">
                                        <?php echo esc_html__( 'Most email systems support forwarding. Look for one of these settings:', 'cmms-light' ); ?>
                                    </p>
                                    <ul style="margin:0;padding-right:18px;">
                                        <li>Forwarding</li>
                                        <li>Auto-forward</li>
                                        <li>Mail forwarding rules</li>
                                        <li><?php echo esc_html__( 'העברה / העברה אוטומטית', 'cmms-light' ); ?></li>
                                    </ul>
                                    <div style="margin-top:8px;padding:6px 8px;background:#fef3c7;border-radius:3px;color:#78350f;font-size:10px;">
                                        ⚠️ <?php echo esc_html__( 'Some systems require address verification before activation. If you get a verification request, contact your service provider.', 'cmms-light' ); ?>
                                    </div>
                                </div>

                                <?php // ── Copy instructions button ── ?>
                                <div style="margin-top:10px;display:flex;justify-content:flex-end;">
                                    <button type="button"
                                            class="cmms-btn cmms-btn-sm cmms-btn-secondary cmms-email-copy-instructions-btn"
                                            data-gmail="<?php echo esc_attr( $gmail_steps_text ); ?>"
                                            data-outlook="<?php echo esc_attr( $outlook_steps_text ); ?>"
                                            data-other="<?php echo esc_attr( $other_steps_text ); ?>"
                                            style="font-size:11px;display:inline-flex;align-items:center;gap:4px;">
                                        📋 <span class="cmms-email-copy-instructions-label"><?php echo esc_html__( 'Copy instructions to send to customer', 'cmms-light' ); ?></span>
                                    </button>
                                </div>

                                <script>
                                (function() {
                                    if ( window.__cmmsEmailTabsBound ) return;
                                    window.__cmmsEmailTabsBound = true;

                                    // ── Tab switching ──
                                    document.addEventListener('click', function(e) {
                                        var tabBtn = e.target.closest('.cmms-email-tab-btn');
                                        if ( tabBtn ) {
                                            e.preventDefault();
                                            var container = tabBtn.closest('details') || document;
                                            var tab = tabBtn.getAttribute('data-tab');
                                            // Active state on buttons
                                            container.querySelectorAll('.cmms-email-tab-btn').forEach(function(b) {
                                                b.classList.remove('cmms-active');
                                                b.style.background = '#fff';
                                                b.style.fontWeight = 'normal';
                                            });
                                            tabBtn.classList.add('cmms-active');
                                            tabBtn.style.background = '#eff6ff';
                                            tabBtn.style.fontWeight = '600';
                                            // Show/hide panels
                                            container.querySelectorAll('.cmms-email-tab-panel').forEach(function(p) {
                                                p.style.display = 'none';
                                            });
                                            var panel = container.querySelector('.cmms-email-tab-' + tab);
                                            if ( panel ) panel.style.display = 'block';
                                            return;
                                        }

                                        // ── Copy instructions button ──
                                        // Copies the CURRENTLY ACTIVE tab's
                                        // instructions to clipboard. So if
                                        // user is on Gmail tab, they get
                                        // Gmail instructions — what they see
                                        // matches what they get.
                                        var copyBtn = e.target.closest('.cmms-email-copy-instructions-btn');
                                        if ( ! copyBtn ) return;
                                        e.preventDefault();

                                        var container = copyBtn.closest('details') || document;
                                        var activeBtn = container.querySelector('.cmms-email-tab-btn.cmms-active');
                                        var tab = activeBtn ? activeBtn.getAttribute('data-tab') : 'gmail';
                                        var text = copyBtn.getAttribute('data-' + tab) || '';

                                        var label = copyBtn.querySelector('.cmms-email-copy-instructions-label');
                                        var original = label ? label.textContent : '';
                                        var done = function() {
                                            if ( label ) {
                                                label.textContent = '✓ הועתק!';
                                                setTimeout(function() { label.textContent = original; }, 2000);
                                            }
                                        };
                                        if ( navigator.clipboard && navigator.clipboard.writeText ) {
                                            navigator.clipboard.writeText( text ).then( done ).catch(function() {
                                                fallbackCopyInstr( text, done );
                                            });
                                        } else {
                                            fallbackCopyInstr( text, done );
                                        }
                                    });

                                    function fallbackCopyInstr( text, done ) {
                                        var ta = document.createElement('textarea');
                                        ta.value = text;
                                        ta.style.position = 'fixed';
                                        ta.style.left = '-9999px';
                                        document.body.appendChild(ta);
                                        ta.select();
                                        try { document.execCommand('copy'); } catch (err) {}
                                        document.body.removeChild(ta);
                                        done();
                                    }
                                })();
                                </script>

                            </div>
                        </div>
                    </details>

                    <?php
                    // ── Test panel (1.14.99) ──
                    // Sends a real POST with _test:true to the email-inbox
                    // endpoint. Same idea as the webhook test panel, but
                    // for the email route. Simulates a single email arrival.
                    ?>
                    <div class="cmms-field cmms-mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;">
                        <label class="cmms-field-label" style="display:block;margin-bottom:6px;">
                            🔌 <?php echo esc_html__( 'Test connection', 'cmms-light' ); ?>
                        </label>
                        <div style="font-size:11px;color:#64748b;margin-bottom:8px;">
                            <?php echo esc_html__( 'Sends a simulated email to the endpoint — no real task is created.', 'cmms-light' ); ?>
                        </div>
                        <?php
                        $email_inbox_url = rest_url( 'cmms-light/v1/email-inbox/' . $route->email_slug );
                        ?>
                        <button type="button"
                                class="cmms-btn cmms-btn-sm cmms-btn-primary cmms-email-inbox-test-btn"
                                data-endpoint-url="<?php echo esc_attr( $email_inbox_url ); ?>"
                                style="width:100%;">
                            <?php echo esc_html__( 'Test', 'cmms-light' ); ?>
                        </button>
                        <div class="cmms-email-inbox-test-result" style="display:none;margin-top:8px;padding:8px 10px;border-radius:4px;font-size:11px;line-height:1.6;"></div>
                    </div>

                    <script>
                    (function() {
                        // Single global handler — survives multiple
                        // email-inbox sections on the same page.
                        if ( window.__cmmsEmailInboxBound ) return;
                        window.__cmmsEmailInboxBound = true;

                        document.addEventListener('click', function(e) {
                            // ── Copy address button ──
                            var copyBtn = e.target.closest('.cmms-email-inbox-copy-btn');
                            if ( copyBtn ) {
                                e.preventDefault();
                                var addr = copyBtn.getAttribute('data-address') || '';
                                var label = copyBtn.querySelector('.cmms-email-inbox-copy-label');
                                var original = label ? label.innerHTML : '';
                                var done = function() {
                                    if ( label ) {
                                        label.innerHTML = '✓ הועתק!';
                                        setTimeout(function() { label.innerHTML = original; }, 2000);
                                    }
                                };
                                if ( navigator.clipboard && navigator.clipboard.writeText ) {
                                    navigator.clipboard.writeText( addr ).then( done ).catch(function() {
                                        fallbackCopy( addr, done );
                                    });
                                } else {
                                    fallbackCopy( addr, done );
                                }
                                return;
                            }

                            // ── Test button ──
                            var testBtn = e.target.closest('.cmms-email-inbox-test-btn');
                            if ( ! testBtn ) return;
                            e.preventDefault();

                            var panel = testBtn.closest('.cmms-field');
                            var resultDiv = panel.querySelector('.cmms-email-inbox-test-result');
                            var url = testBtn.getAttribute('data-endpoint-url');

                            var originalText = testBtn.textContent;
                            testBtn.disabled = true;
                            testBtn.textContent = '...';
                            showResult(resultDiv, 'info', '⏳ שולח מייל-בדיקה...');

                            // Simulate a real incoming email. _test:true means
                            // the endpoint will preview but not create a task.
                            fetch(url, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    _test: true,
                                    from: 'test@example.com',
                                    from_name: 'בדיקה',
                                    subject: 'מייל בדיקה - לא משימה אמיתית',
                                    text: 'זוהי בדיקה לוודא שהחיבור עובד. לא תיווצר משימה בפועל.'
                                })
                            }).then(function(response) {
                                return response.json().then(function(body) {
                                    return { status: response.status, body: body };
                                }).catch(function() {
                                    return { status: response.status, body: null };
                                });
                            }).then(function(result) {
                                renderEmailTestResult(resultDiv, result);
                            }).catch(function() {
                                showResult(resultDiv, 'error',
                                    '✗ <strong>שגיאת רשת</strong><br>' +
                                    '<span style="font-size:10px;opacity:0.85;">לא ניתן להתחבר ל-endpoint.</span>'
                                );
                            }).finally(function() {
                                testBtn.disabled = false;
                                testBtn.textContent = originalText;
                            });
                        });

                        function renderEmailTestResult(div, result) {
                            var status = result.status;
                            var body = result.body || {};

                            if ( status === 200 && body.test_mode ) {
                                var preview = body.would_create_task || {};
                                var html = '✅ <strong>החיבור עובד!</strong><br>';
                                html += '<span style="font-size:10px;opacity:0.85;">';
                                html += 'השרת קיבל את המייל וזיהה את הטופס בהצלחה.';
                                html += '</span>';
                                if ( preview.form_name ) {
                                    html += '<div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(0,0,0,0.1);font-size:10px;">';
                                    html += '<strong>טופס:</strong> ' + escapeHtml(preview.form_name);
                                    html += '</div>';
                                }
                                showResult(div, 'success', html);
                                return;
                            }
                            if ( status === 401 ) {
                                showResult(div, 'error',
                                    '✗ <strong>נדרשת חתימה</strong><br>' +
                                    '<span style="font-size:10px;opacity:0.85;">המערכת מוגדרת לחייב חתימת HMAC, ובדיקה זו לא חתומה.</span>'
                                );
                                return;
                            }
                            if ( status === 404 ) {
                                showResult(div, 'error',
                                    '✗ <strong>הראוט לא רשום</strong><br>' +
                                    '<span style="font-size:10px;opacity:0.85;">' +
                                    'לך ל: <em>הגדרות → קישורים קבועים → שמור</em>.' +
                                    '</span>'
                                );
                                return;
                            }
                            showResult(div, 'error',
                                '✗ <strong>שגיאה</strong> (HTTP ' + status + ')<br>' +
                                '<span style="font-size:10px;opacity:0.85;">' +
                                escapeHtml(body.message || body.code || '') +
                                '</span>'
                            );
                        }

                        function showResult(div, kind, html) {
                            var styles = {
                                success: { bg: '#dcfce7', border: '#86efac', color: '#166534' },
                                error:   { bg: '#fee2e2', border: '#fca5a5', color: '#991b1b' },
                                warn:    { bg: '#fef3c7', border: '#fcd34d', color: '#92400e' },
                                info:    { bg: '#dbeafe', border: '#93c5fd', color: '#1e40af' }
                            };
                            var s = styles[kind] || styles.info;
                            div.style.display = 'block';
                            div.style.background = s.bg;
                            div.style.border = '1px solid ' + s.border;
                            div.style.color = s.color;
                            div.innerHTML = html;
                        }

                        function fallbackCopy(text, done) {
                            var ta = document.createElement('textarea');
                            ta.value = text;
                            ta.style.position = 'fixed';
                            ta.style.left = '-9999px';
                            document.body.appendChild(ta);
                            ta.select();
                            try { document.execCommand('copy'); } catch (err) {}
                            document.body.removeChild(ta);
                            done();
                        }

                        function escapeHtml(s) {
                            if ( s == null ) return '';
                            return String(s)
                                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                                .replace(/'/g, '&#039;');
                        }
                    })();
                    </script>

                    <?php // ── Rotate / Revoke buttons ── ?>
                    <div style="display:flex;gap:8px;">
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="flex:1;"
                              onsubmit="return confirm('<?php echo esc_js( __( 'Generate a new email address? The current one will stop working immediately.', 'cmms-light' ) ); ?>');">
                            <input type="hidden" name="action" value="cmms_email_inbox_rotate">
                            <input type="hidden" name="form_id" value="<?php echo (int) $form->id; ?>">
                            <?php wp_nonce_field( 'cmms_email_inbox_rotate', 'cmms_email_inbox_rotate_nonce' ); ?>
                            <button type="submit" class="cmms-btn cmms-btn-sm cmms-btn-secondary" style="width:100%;">
                                <?php CMMS_Icons::e( 'refresh-cw', 14 ); ?>
                                <?php echo esc_html__( 'Rotate address', 'cmms-light' ); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" style="flex:1;"
                              onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this email address? Forwarded emails will stop creating tasks.', 'cmms-light' ) ); ?>');">
                            <input type="hidden" name="action" value="cmms_email_inbox_revoke">
                            <input type="hidden" name="form_id" value="<?php echo (int) $form->id; ?>">
                            <?php wp_nonce_field( 'cmms_email_inbox_revoke', 'cmms_email_inbox_revoke_nonce' ); ?>
                            <button type="submit" class="cmms-btn cmms-btn-sm cmms-btn-ghost" style="width:100%;color:#b91c1c;">
                                <?php CMMS_Icons::e( 'x-circle', 14 ); ?>
                                <?php echo esc_html__( 'Revoke', 'cmms-light' ); ?>
                            </button>
                        </form>
                    </div>

                    <?php // ── Destructive-action warning ── ?>
                    <div style="margin-top:10px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:10px 12px;font-size:11px;line-height:1.6;color:#78350f;">
                        <div style="font-weight:600;margin-bottom:6px;">
                            ⚠️ <?php echo esc_html__( 'Important — these actions break existing forwards', 'cmms-light' ); ?>
                        </div>
                        <div style="margin-bottom:5px;">
                            <strong><?php echo esc_html__( 'Rotate address', 'cmms-light' ); ?>:</strong>
                            <?php echo esc_html__( 'replaces the current email address with a new one. Forwarding rules pointing to the old address will silently fail. Use this when the address has leaked publicly.', 'cmms-light' ); ?>
                        </div>
                        <div>
                            <strong><?php echo esc_html__( 'Revoke', 'cmms-light' ); ?>:</strong>
                            <?php echo esc_html__( 'deletes the address entirely. Forwarded emails will no longer create tasks. Use this when ending a partnership or shutting down a specific channel.', 'cmms-light' ); ?>
                        </div>
                    </div>

                <?php else : ?>
                    <?php // ── No route yet — single CTA ── ?>
                    <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>">
                        <input type="hidden" name="action" value="cmms_email_inbox_generate">
                        <input type="hidden" name="form_id" value="<?php echo (int) $form->id; ?>">
                        <?php wp_nonce_field( 'cmms_email_inbox_generate', 'cmms_email_inbox_generate_nonce' ); ?>
                        <button type="submit" class="cmms-btn cmms-btn-primary" style="width:100%;">
                            <?php CMMS_Icons::e( 'mail', 16 ); ?>
                            <?php echo esc_html__( 'Create email address', 'cmms-light' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
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
            // 1.14.71: user_limit message updated to suggest contacting support.
            $error_map = array(
                'email'      => 'כתובת אימייל לא תקינה.',
                'add'        => 'הוספת המשתמש נכשלה. ייתכן שהאימייל כבר קיים במערכת.',
                'user_limit' => 'הגעת למגבלת המשתמשים בחבילה. להוספת משתמשים נוספים או לשדרוג חבילה — פנו אלינו בטלפון 03-3094405.',
            );
            if ( isset( $error_map[ $err_code ] ) ) {
                $err_msg = $error_map[ $err_code ];
            } elseif ( isset( $_GET['cmms_msg'] ) ) {
                $err_msg = sanitize_text_field( wp_unslash( $_GET['cmms_msg'] ) );
            }
        }
        $updated = isset( $_GET['cmms_msg'] ) && $_GET['cmms_msg'] === 'added';
        $just_added = isset( $_GET['cmms_msg'] ) && $_GET['cmms_msg'] === 'added';

        // 1.14.50 → 1.14.71: compute slot usage. The 1.14.71 upgrade
        // pulls the rich snapshot from CMMS_Seats so we can show:
        //   - included + purchased breakdown
        //   - hard limit
        //   - upgrade recommendation when nearing the ceiling
        //   - "contact us" hint when at the absolute ceiling
        //
        // Falls back to the simple max_users count for accounts on
        // Enterprise / unmanaged plans.
        $seat_info = class_exists( 'CMMS_Seats' )
            ? CMMS_Seats::get_account_seats( (int) $u->account_id )
            : null;

        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $users_t    = CMMS_DB::table( 'users' );

        if ( $seat_info && $seat_info['is_managed'] ) {
            $max_users     = $seat_info['total_allowed'];
            $current_count = $seat_info['currently_used'];
            $hard_limit    = $seat_info['hard_limit'];
            $upgrade_hint  = $seat_info['upgrade_recommended'];
            $at_hard_cap   = $seat_info['at_hard_limit'];
            $included      = $seat_info['included'];
            $purchased     = $seat_info['purchased'];
        } else {
            // Legacy / Enterprise fallback.
            $max_users = $wpdb->get_var( $wpdb->prepare(
                "SELECT max_users FROM $accounts_t WHERE id = %d", (int) $u->account_id
            ) );
            $max_users = ( $max_users === null || $max_users === '' ) ? null : (int) $max_users;
            $current_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $users_t WHERE account_id = %d AND status IN ('active','invited')",
                (int) $u->account_id
            ) );
            $hard_limit   = null;
            $upgrade_hint = false;
            $at_hard_cap  = false;
            $included     = null;
            $purchased    = 0;
        }
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

        <?php // 1.14.71 — Seat status widget (read-only).
              // 1.14.74: now includes "Add Users" button for managed plans
              // when there's room within the hard_user_limit. Click opens
              // the seat purchase modal.
              //
              // Shown for managed plans (Starter / Business). Hidden for
              // Enterprise / legacy where the included/purchased breakdown
              // doesn't apply.
              if ( $seat_info && $seat_info['is_managed'] ) :
                  $pct = ( $hard_limit && $hard_limit > 0 )
                      ? min( 100, round( ( $current_count / $hard_limit ) * 100 ) )
                      : 0;
                  $bar_color = $at_hard_cap ? '#dc2626' : ( $upgrade_hint ? '#d97706' : '#10b981' );

                  // 1.14.74: show "Add Users" button only when the
                  // customer has any room left under hard_user_limit.
                  // The role check matches plan-changes — only owners
                  // can purchase, since billing is on the account.
                  $can_buy_seats = (
                      $u->role === CMMS_Auth::ROLE_OWNER
                      && $hard_limit !== null
                      && $seat_info['total_allowed'] < $hard_limit
                  );

                  // Seats nonce — used by the modal AJAX calls.
                  $seats_nonce = wp_create_nonce( 'cmms_seats' );
        ?>
        <div class="cmms-section" style="margin-bottom:16px;">
            <div class="cmms-section-body" style="padding:16px 18px;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                    <div style="font-size:14px;color:#475569;">
                        <strong style="color:#0b1c33;font-size:16px;">
                            <?php echo (int) $current_count; ?>
                            <?php esc_html_e( 'משתמשים פעילים', 'cmms-light' ); ?>
                        </strong>
                        <span style="color:#64748b;">
                            <?php echo esc_html( sprintf( __( 'מתוך %d מותרים', 'cmms-light' ), (int) $max_users ) ); ?>
                        </span>
                        <?php if ( $purchased > 0 ) : ?>
                            <span style="color:#64748b;font-size:13px;">
                                (<?php echo esc_html( sprintf( __( '%1$d כלולים + %2$d נוספים', 'cmms-light' ), (int) $included, (int) $purchased ) ); ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ( $hard_limit !== null ) : ?>
                    <div style="font-size:12px;color:#94a3b8;">
                        <?php echo esc_html( sprintf( __( 'גג חבילה: עד %d משתמשים', 'cmms-light' ), (int) $hard_limit ) ); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="height:6px;background:#e2e8f0;border-radius:999px;overflow:hidden;">
                    <div style="height:100%;width:<?php echo (int) $pct; ?>%;background:<?php echo esc_attr( $bar_color ); ?>;transition:width .3s ease;"></div>
                </div>

                <?php if ( $at_hard_cap || $is_full ) : ?>
                    <div style="margin-top:12px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:13.5px;color:#7f1d1d;">
                        <strong><?php esc_html_e( 'הגעת לגג המשתמשים בחבילה.', 'cmms-light' ); ?></strong>
                        <?php esc_html_e( 'להוספת משתמשים נוספים פנו אלינו:', 'cmms-light' ); ?>
                        <a href="tel:+97233094405" style="color:#7f1d1d;font-weight:700;text-decoration:underline;">03-3094405</a>
                    </div>
                <?php elseif ( $upgrade_hint ) : ?>
                    <div style="margin-top:12px;padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13.5px;color:#78350f;">
                        <strong><?php esc_html_e( 'מתקרבים לגג החבילה.', 'cmms-light' ); ?></strong>
                        <?php esc_html_e( 'מומלץ לשדרג לחבילה גדולה יותר. לפרטים פנו אלינו:', 'cmms-light' ); ?>
                        <a href="tel:+97233094405" style="color:#78350f;font-weight:700;text-decoration:underline;">03-3094405</a>
                    </div>
                <?php endif; ?>

                <?php if ( $can_buy_seats ) : ?>
                <div style="margin-top:14px;display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span style="font-size:12px;color:#64748b;">
                        <?php esc_html_e( 'צריך יותר מקומות?', 'cmms-light' ); ?>
                    </span>
                    <button type="button"
                            data-seats-open
                            style="background:#fff;color:#ea580c;border:1.5px solid #ea580c;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background .15s ease,color .15s ease;"
                            onmouseover="this.style.background='#fff7ed'"
                            onmouseout="this.style.background='#fff'">
                        <span style="font-size:14px;">💳</span>
                        <span><?php esc_html_e( 'רכוש משתמשים נוספים לחבילה', 'cmms-light' ); ?></span>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php // ─────────────────────────────────────────────────────────
              // 1.14.74 — Seat Purchase Modal
              //
              // Rendered only when the plan is managed AND the user is
              // the account owner AND there's room left under hard_user_limit.
              // Triggered by [data-seats-open] buttons elsewhere on the page.
              // ─────────────────────────────────────────────────────────
              if ( isset( $can_buy_seats ) && $can_buy_seats ) :
                  $modal_room_left = $hard_limit - $seat_info['total_allowed'];
                  $seats_nonce_for_modal = wp_create_nonce( 'cmms_seats' );
        ?>
        <div class="cmms-seats-modal" id="cmms-seats-modal" hidden aria-hidden="true" role="dialog" aria-labelledby="cmms-seats-modal-title">
            <div class="cmms-seats-modal-backdrop" data-seats-close></div>
            <div class="cmms-seats-modal-panel">
                <header class="cmms-seats-modal-head">
                    <h2 id="cmms-seats-modal-title">רכישת משתמשים נוספים לחבילה</h2>
                    <button type="button" class="cmms-seats-modal-close" data-seats-close aria-label="סגור">×</button>
                </header>
                <div class="cmms-seats-modal-body">
                    <div class="cmms-seats-modal-status">
                        <div>
                            <div class="cmms-seats-modal-status-label">משתמשים פעילים כעת</div>
                            <div class="cmms-seats-modal-status-value"><?php echo (int) $current_count; ?> / <?php echo (int) $max_users; ?></div>
                        </div>
                        <div>
                            <div class="cmms-seats-modal-status-label">גג החבילה</div>
                            <div class="cmms-seats-modal-status-value"><?php echo (int) $hard_limit; ?> משתמשים</div>
                        </div>
                    </div>
                    <div class="cmms-seats-modal-picker">
                        <label class="cmms-seats-modal-picker-label">כמה משתמשים נוספים?</label>
                        <div class="cmms-seats-modal-picker-control">
                            <button type="button" class="cmms-seats-modal-picker-btn" data-seats-action="dec" aria-label="פחות">−</button>
                            <input type="number" class="cmms-seats-modal-picker-input" id="cmms-seats-count" value="1" min="1" max="<?php echo (int) $modal_room_left; ?>" inputmode="numeric">
                            <button type="button" class="cmms-seats-modal-picker-btn" data-seats-action="inc" aria-label="עוד">+</button>
                        </div>
                        <div class="cmms-seats-modal-picker-hint">ניתן להוסיף עד <?php echo (int) $modal_room_left; ?> משתמשים בחבילה הנוכחית</div>
                    </div>
                    <div class="cmms-seats-modal-quote" data-seats-quote>
                        <div class="cmms-seats-modal-quote-loading" data-seats-loading>טוען חישוב…</div>
                        <div class="cmms-seats-modal-quote-content" data-seats-content hidden>
                            <div class="cmms-seats-modal-quote-line"><span>תוספת חודשית</span><strong data-seats-monthly>—</strong></div>
                            <div class="cmms-seats-modal-quote-line"><span>עלות יחסית לתקופה שנותרה</span><strong data-seats-prorated-detail>—</strong></div>
                            <div class="cmms-seats-modal-quote-line cmms-seats-modal-quote-line-emphasis"><span>💳 לתשלום היום</span><strong data-seats-pay-today>—</strong></div>
                            <div class="cmms-seats-modal-quote-line"><span>🔁 מהחודש הבא</span><strong data-seats-next-cycle>—</strong></div>
                            <p class="cmms-seats-modal-quote-note" data-seats-explain></p>
                        </div>
                        <div class="cmms-seats-modal-quote-error" data-seats-error hidden></div>
                    </div>
                </div>
                <footer class="cmms-seats-modal-foot">
                    <button type="button" class="cmms-seats-modal-cancel" data-seats-close>ביטול</button>
                    <button type="button" class="cmms-seats-modal-pay" data-seats-pay disabled>
                        <span class="cmms-seats-modal-pay-label">המשך לתשלום מאובטח</span>
                        <span class="cmms-seats-modal-pay-spinner" hidden></span>
                    </button>
                </footer>
                <p class="cmms-seats-modal-trust">🔒 תשלום מאובטח דרך iCredit · פרטי האשראי לא נשמרים אצלנו</p>
            </div>
        </div>

        <div class="cmms-seats-toast" id="cmms-seats-toast" hidden role="status" aria-live="polite">
            <span class="cmms-seats-toast-icon">🎉</span>
            <span class="cmms-seats-toast-text" data-toast-text>הוספת המשתמשים הצליחה!</span>
            <button type="button" class="cmms-seats-toast-close" data-toast-close aria-label="סגור">×</button>
        </div>

        <style>
            .cmms-seats-modal { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: flex-end; justify-content: center; font-family: Arial, sans-serif; }
            .cmms-seats-modal[hidden] { display: none; }
            .cmms-seats-modal-backdrop { position: absolute; inset: 0; background: rgba(15,23,42,0.5); animation: cmms-seats-fade-in .2s ease; }
            .cmms-seats-modal-panel { position: relative; background: #fff; width: 100%; max-width: 480px; max-height: 92vh; overflow-y: auto; border-radius: 18px 18px 0 0; animation: cmms-seats-slide-up .25s ease; display: flex; flex-direction: column; }
            @media (min-width: 640px) { .cmms-seats-modal { align-items: center; } .cmms-seats-modal-panel { border-radius: 18px; animation: cmms-seats-pop .2s ease; } }
            @keyframes cmms-seats-fade-in { from { opacity: 0 } to { opacity: 1 } }
            @keyframes cmms-seats-slide-up { from { transform: translateY(100%) } to { transform: translateY(0) } }
            @keyframes cmms-seats-pop { from { opacity: 0; transform: scale(.96) } to { opacity: 1; transform: scale(1) } }
            .cmms-seats-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; background: #fff; z-index: 1; }
            .cmms-seats-modal-head h2 { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
            .cmms-seats-modal-close { background: transparent; border: 0; font-size: 28px; line-height: 1; color: #64748b; cursor: pointer; padding: 4px 10px; border-radius: 6px; }
            .cmms-seats-modal-close:hover { background: #f1f5f9; color: #0f172a; }
            .cmms-seats-modal-body { padding: 20px; display: flex; flex-direction: column; gap: 20px; }
            .cmms-seats-modal-status { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; }
            .cmms-seats-modal-status-label { font-size: 12px; color: #64748b; font-weight: 500; margin-bottom: 4px; }
            .cmms-seats-modal-status-value { font-size: 16px; font-weight: 700; color: #0f172a; }
            .cmms-seats-modal-picker-label { display: block; font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 8px; }
            .cmms-seats-modal-picker-control { display: flex; align-items: stretch; border: 1.5px solid #cbd5e1; border-radius: 10px; overflow: hidden; height: 52px; }
            .cmms-seats-modal-picker-btn { flex: none; width: 56px; background: #fff; border: 0; color: #475569; font-size: 24px; font-weight: 600; cursor: pointer; line-height: 1; }
            .cmms-seats-modal-picker-btn:hover:not(:disabled) { background: #f1f5f9; color: #0f172a; }
            .cmms-seats-modal-picker-btn:disabled { opacity: .35; cursor: not-allowed; }
            .cmms-seats-modal-picker-input { flex: 1; border: 0; border-inline: 1.5px solid #e2e8f0; text-align: center; font-size: 20px; font-weight: 700; color: #0f172a; background: #fff; -moz-appearance: textfield; appearance: textfield; }
            .cmms-seats-modal-picker-input::-webkit-outer-spin-button, .cmms-seats-modal-picker-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
            .cmms-seats-modal-picker-input:focus { outline: 0; background: #fffbf5; }
            .cmms-seats-modal-picker-hint { margin-top: 8px; font-size: 12px; color: #64748b; }
            .cmms-seats-modal-quote { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; min-height: 80px; position: relative; }
            .cmms-seats-modal-quote-loading { text-align: center; color: #64748b; font-size: 13px; padding: 8px 0; }
            .cmms-seats-modal-quote-line { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; font-size: 14px; color: #475569; }
            .cmms-seats-modal-quote-line + .cmms-seats-modal-quote-line { border-top: 1px dashed #e2e8f0; }
            .cmms-seats-modal-quote-line strong { color: #0f172a; font-weight: 600; }
            .cmms-seats-modal-quote-line-emphasis { font-size: 15px; margin-top: 4px; padding-top: 12px !important; border-top: 2px solid #cbd5e1 !important; }
            .cmms-seats-modal-quote-line-emphasis strong { font-size: 18px; color: #4f46e5; }
            .cmms-seats-modal-quote-note { margin: 12px 0 0; font-size: 12px; color: #64748b; line-height: 1.5; }
            .cmms-seats-modal-quote-error { color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 12px; font-size: 13.5px; }
            .cmms-seats-modal-foot { display: flex; gap: 10px; padding: 16px 20px; border-top: 1px solid #e2e8f0; background: #fff; }
            .cmms-seats-modal-cancel { flex: 0 0 auto; background: #fff; color: #475569; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 0 18px; height: 48px; font-size: 14px; font-weight: 600; cursor: pointer; }
            .cmms-seats-modal-cancel:hover { background: #f8fafc; color: #0f172a; }
            .cmms-seats-modal-pay { flex: 1; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color: #fff; border: 0; border-radius: 10px; padding: 0 18px; height: 48px; font-size: 14px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 14px rgba(79,70,229,.32); display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
            .cmms-seats-modal-pay:hover:not(:disabled) { box-shadow: 0 6px 18px rgba(79,70,229,.4); }
            .cmms-seats-modal-pay:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }
            .cmms-seats-modal-pay-spinner { width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: cmms-seats-spin .7s linear infinite; }
            .cmms-seats-modal-pay-spinner[hidden] { display: none; }
            @keyframes cmms-seats-spin { to { transform: rotate(360deg); } }
            .cmms-seats-modal-trust { text-align: center; padding: 0 20px 16px; margin: 0; font-size: 11.5px; color: #94a3b8; }
            .cmms-seats-toast { position: fixed; top: 18px; left: 50%; transform: translateX(-50%); z-index: 9998; background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; border-radius: 12px; padding: 12px 18px; box-shadow: 0 8px 24px rgba(16,185,129,.18); display: flex; align-items: center; gap: 12px; font-family: Arial, sans-serif; font-size: 14px; font-weight: 500; max-width: 90vw; animation: cmms-seats-toast-in .3s ease; }
            .cmms-seats-toast[hidden] { display: none; }
            .cmms-seats-toast-icon { font-size: 20px; line-height: 1; }
            .cmms-seats-toast-text { flex: 1; }
            .cmms-seats-toast-close { background: transparent; border: 0; color: #047857; font-size: 22px; line-height: 1; cursor: pointer; padding: 0 4px; }
            @keyframes cmms-seats-toast-in { from { opacity: 0; transform: translate(-50%, -10px); } to { opacity: 1; transform: translate(-50%, 0); } }
        </style>

        <script>
        (function () {
            'use strict';
            var modal = document.getElementById('cmms-seats-modal');
            if (!modal) return;
            var openButtons = document.querySelectorAll('[data-seats-open]');
            var closeButtons = modal.querySelectorAll('[data-seats-close]');
            var input = document.getElementById('cmms-seats-count');
            var decBtn = modal.querySelector('[data-seats-action="dec"]');
            var incBtn = modal.querySelector('[data-seats-action="inc"]');
            var payBtn = modal.querySelector('[data-seats-pay]');
            var paySpinner = modal.querySelector('.cmms-seats-modal-pay-spinner');
            var paylabel = modal.querySelector('.cmms-seats-modal-pay-label');
            var loadingEl = modal.querySelector('[data-seats-loading]');
            var contentEl = modal.querySelector('[data-seats-content]');
            var errorEl = modal.querySelector('[data-seats-error]');
            var monthlyEl = modal.querySelector('[data-seats-monthly]');
            var proratedDetailEl = modal.querySelector('[data-seats-prorated-detail]');
            var payTodayEl = modal.querySelector('[data-seats-pay-today]');
            var nextCycleEl = modal.querySelector('[data-seats-next-cycle]');
            var explainEl = modal.querySelector('[data-seats-explain]');
            var nonce = <?php echo wp_json_encode( $seats_nonce_for_modal ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var maxAllowed = parseInt(input.getAttribute('max'), 10) || 1;
            var lastQuoteAbort = null;
            var quoteDebounce = null;
            function fmtPrice(n) { try { return '₪' + Math.round(n).toLocaleString('he-IL'); } catch (e) { return '₪' + Math.round(n); } }
            function clampInput() {
                var v = parseInt(input.value, 10);
                if (isNaN(v) || v < 1) v = 1;
                if (v > maxAllowed) v = maxAllowed;
                if (String(v) !== input.value) input.value = v;
                decBtn.disabled = (v <= 1);
                incBtn.disabled = (v >= maxAllowed);
                return v;
            }
            function setLoading() { loadingEl.hidden = false; contentEl.hidden = true; errorEl.hidden = true; payBtn.disabled = true; }
            function showError(msg) { loadingEl.hidden = true; contentEl.hidden = true; errorEl.hidden = false; errorEl.textContent = msg || 'אירעה שגיאה. נסה שוב.'; payBtn.disabled = true; }
            function showQuote(data) {
                loadingEl.hidden = true; errorEl.hidden = true; contentEl.hidden = false; payBtn.disabled = false;
                var count = data.count; var seatPrice = data.seat_price; var monthlyAdd = count * seatPrice;
                monthlyEl.textContent = count + ' משתמשים × ' + fmtPrice(seatPrice) + ' = ' + fmtPrice(monthlyAdd);
                proratedDetailEl.textContent = fmtPrice(monthlyAdd) + ' × ' + data.days_remaining + ' / ' + data.days_in_cycle;
                payTodayEl.textContent = fmtPrice(data.prorated_amount);
                nextCycleEl.textContent = fmtPrice(data.new_recurring) + ' / חודש';
                explainEl.innerHTML = 'ℹ העלות היחסית מבוססת על <strong>' + data.days_remaining + '</strong> הימים שנותרו עד החיוב הבא.';
            }
            function fetchQuote() {
                var count = clampInput();
                setLoading();
                if (lastQuoteAbort) { try { lastQuoteAbort.abort(); } catch (e) {} }
                var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                lastQuoteAbort = ctrl;
                var body = new URLSearchParams();
                body.set('action', 'cmms_seats_quote');
                body.set('nonce', nonce);
                body.set('count', String(count));
                fetch(ajaxUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(), signal: ctrl ? ctrl.signal : undefined
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.success && res.data) { showQuote(res.data); }
                    else { showError(res && res.data && res.data.message ? res.data.message : 'אירעה שגיאה.'); }
                })
                .catch(function (err) { if (err && err.name === 'AbortError') return; showError('שגיאת רשת. בדוק את החיבור ונסה שוב.'); });
            }
            function debouncedFetch() { if (quoteDebounce) clearTimeout(quoteDebounce); quoteDebounce = setTimeout(fetchQuote, 200); }
            function openModal() { modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; input.value = '1'; clampInput(); fetchQuote(); }
            function closeModal() { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
            openButtons.forEach(function (b) { b.addEventListener('click', openModal); });
            closeButtons.forEach(function (b) { b.addEventListener('click', closeModal); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeModal(); });
            decBtn.addEventListener('click', function () { input.value = parseInt(input.value, 10) - 1; clampInput(); debouncedFetch(); });
            incBtn.addEventListener('click', function () { input.value = parseInt(input.value, 10) + 1; clampInput(); debouncedFetch(); });
            input.addEventListener('input', function () { clampInput(); debouncedFetch(); });
            payBtn.addEventListener('click', function () {
                if (payBtn.disabled) return;
                var count = clampInput();
                payBtn.disabled = true; paySpinner.hidden = false; paylabel.textContent = 'מעביר לתשלום...';
                var body = new URLSearchParams();
                body.set('action', 'cmms_seats_purchase');
                body.set('nonce', nonce);
                body.set('count', String(count));
                fetch(ajaxUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.success && res.data && res.data.redirect) {
                        try { sessionStorage.setItem('cmms_seats_pending', String(count)); } catch (e) {}
                        window.location.href = res.data.redirect; return;
                    }
                    var err = res && res.data || {};
                    if (err.redirect) { window.location.href = err.redirect; return; }
                    showError(err.message || 'לא הצלחנו להעביר לתשלום. נסה שוב.');
                    payBtn.disabled = false; paySpinner.hidden = true; paylabel.textContent = 'המשך לתשלום מאובטח';
                })
                .catch(function () { showError('שגיאת רשת. בדוק את החיבור ונסה שוב.'); payBtn.disabled = false; paySpinner.hidden = true; paylabel.textContent = 'המשך לתשלום מאובטח'; });
            });
            var toast = document.getElementById('cmms-seats-toast');
            if (toast) {
                var toastText = toast.querySelector('[data-toast-text]');
                var closeBtnT = toast.querySelector('[data-toast-close]');
                function showToast(seatsCount) {
                    toastText.textContent = seatsCount + ' משתמשים נוספים נפתחו בחשבונך 🎉';
                    toast.hidden = false;
                    setTimeout(function () { toast.hidden = true; }, 6000);
                }
                if (closeBtnT) closeBtnT.addEventListener('click', function () { toast.hidden = true; });
                try {
                    var pendingCount = sessionStorage.getItem('cmms_seats_pending');
                    var urlSeatsAdded = (new URLSearchParams(window.location.search)).get('seats_added');
                    if (urlSeatsAdded) { showToast(parseInt(urlSeatsAdded, 10) || pendingCount || 0); sessionStorage.removeItem('cmms_seats_pending'); }
                } catch (e) {}
            }
        })();
        </script>
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
                        <span>הגעת למגבלת המשתמשים בחבילה (<?php echo (int) $max_users; ?>). להוספת משתמשים נוספים פנו אלינו בטלפון <strong dir="ltr">03-3094405</strong>.</span>
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

        // 1.14.90: Pre-load this account's forms so the manager can extend
        // the bulk grid with form-specific columns (e.g. "External ticket #",
        // "Customer reference"). The form's fields are surfaced as extra
        // columns; values flow into the task's external_data — the same
        // place public form submissions store their answers, so existing
        // task views render them identically.
        $forms_raw = CMMS_Forms::list_by_account( (int) $u->account_id );
        $forms_for_js = array();
        foreach ( (array) $forms_raw as $f ) {
            if ( empty( $f->enabled ) ) continue;
            $fields = CMMS_Forms::get_fields( (int) $f->id );
            $fields_payload = array();
            foreach ( (array) $fields as $fld ) {
                // File fields cannot be filled from a bulk grid — there is
                // no per-cell upload UX and supporting one here would blow
                // up the paste-from-Excel flow. We still surface them as
                // a (disabled) column so the manager understands the
                // form's full shape; they can always be filled later from
                // the task view.
                $opts = array();
                if ( ! empty( $fld->options ) ) {
                    foreach ( explode( ',', (string) $fld->options ) as $opt ) {
                        $opt = trim( $opt );
                        if ( $opt !== '' ) $opts[] = $opt;
                    }
                }
                $fields_payload[] = array(
                    'id'         => (int) $fld->id,
                    'label'      => (string) $fld->label,
                    'field_type' => (string) $fld->field_type,
                    'options'    => $opts,
                );
            }
            $forms_for_js[] = array(
                'id'     => (int) $f->id,
                'name'   => (string) $f->name,
                'fields' => $fields_payload,
            );
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
            'forms'     => $forms_for_js, // 1.14.90
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
                // 1.14.90: form-extension UI
                'form_label'       => $this->t( 'bulk.form_label' ),
                'form_none'        => $this->t( 'bulk.form_none' ),
                'form_hint'        => $this->t( 'bulk.form_hint' ),
                'file_unsupported' => $this->t( 'bulk.file_unsupported' ),
                'select_value'     => $this->t( 'bulk.select_value' ),
                'form_empty'       => $this->t( 'bulk.form_empty' ),
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
                <?php if ( ! empty( $forms_for_js ) ) : ?>
                    <div class="cmms-bulk-form-picker" data-cmms-bulk-form-picker>
                        <label for="cmms-bulk-form-select" class="cmms-bulk-form-picker-label">
                            <?php $this->e( 'bulk.form_label' ); ?>
                        </label>
                        <select id="cmms-bulk-form-select" data-cmms-bulk-form-select class="cmms-bulk-form-select">
                            <option value=""><?php $this->e( 'bulk.form_none' ); ?></option>
                            <?php foreach ( $forms_for_js as $f_opt ) : ?>
                                <option value="<?php echo (int) $f_opt['id']; ?>">
                                    <?php echo esc_html( $f_opt['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="cmms-bulk-form-hint"><?php $this->e( 'bulk.form_hint' ); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="cmms-bulk-grid-wrap" data-cmms-bulk-root>
                <table class="cmms-bulk-grid" data-cmms-bulk-grid>
                    <thead>
                        <tr data-cmms-bulk-head>
                            <th class="cmms-bulk-th-num">#</th>
                            <th><?php $this->e( 'bulk.col_title' ); ?> <span class="cmms-required">*</span></th>
                            <th><?php $this->e( 'bulk.col_description' ); ?></th>
                            <th><?php $this->e( 'bulk.col_asset' ); ?></th>
                            <th><?php $this->e( 'bulk.col_priority' ); ?></th>
                            <th><?php $this->e( 'bulk.col_assignee' ); ?></th>
                            <th><?php $this->e( 'bulk.col_due' ); ?></th>
                            <?php /* 1.14.90: extra <th>s are appended here by JS when a form is selected */ ?>
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
        // 1.14.84: Telegram tab. Available to every user — each user
        // links their own Telegram account from here.
        if ( CMMS_Telegram::is_enabled() ) $valid_tabs[] = 'telegram';
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
            <?php if ( CMMS_Telegram::is_enabled() ) : ?>
            <a href="<?php echo esc_url( $this->url( array( 'view' => 'settings', 'tab' => 'telegram' ) ) ); ?>"
               class="cmms-settings-tab <?php echo $tab === 'telegram' ? 'active' : ''; ?>">
                📱 טלגרם
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

        <?php
        // 1.14.87: Task lifecycle settings. Only owners and managers
        // can change these — but it's part of the org tab because
        // they're operational settings, not personal preferences.
        if ( in_array( $u->role, array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER ), true ) ) :
            $task_settings = CMMS_Task_Settings::get( (int) $u->account_id );
            $gps_saved = ! empty( $_GET['task_settings_saved'] );
        ?>
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title">📍 הגדרות משימות</h3>
            </div>
            <div class="cmms-section-body">
                <?php if ( $gps_saved ) : ?>
                    <div class="cmms-alert cmms-alert-success" style="margin-bottom:14px;">
                        ✅ ההגדרות נשמרו בהצלחה
                    </div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( $this->admin_post_url() ); ?>" class="cmms-form">
                    <input type="hidden" name="action" value="cmms_task_settings_update">
                    <?php wp_nonce_field( 'cmms_task_settings_update', 'cmms_task_settings_update_nonce' ); ?>

                    <div class="cmms-field" style="display:flex;gap:12px;align-items:flex-start;padding:14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
                        <input type="checkbox"
                               id="require_location_on_complete"
                               name="require_location_on_complete"
                               value="1"
                               <?php checked( ! empty( $task_settings['require_location_on_complete'] ) ); ?>
                               style="margin-top:3px;width:18px;height:18px;cursor:pointer;">
                        <label for="require_location_on_complete" style="cursor:pointer;flex:1;">
                            <div style="font-weight:600;font-size:14px;color:#0f172a;margin-bottom:4px;">
                                בקש מיקום (GPS) בסגירת משימה
                            </div>
                            <div style="font-size:12.5px;color:#64748b;line-height:1.5;">
                                כשמשתמש מסמן משימה כ"הושלם ונסגר", המערכת תבקש את המיקום שלו מהדפדפן/הטלפון.
                                המיקום ישמר עם המשימה כהוכחה שהטכנאי באמת הגיע לאתר. אם המשתמש מסרב לשתף מיקום -
                                המשימה עדיין תיסגר, אך ללא מיקום.
                            </div>
                            <div style="font-size:11px;color:#94a3b8;margin-top:6px;">
                                💡 מתאים לחברות שירות שטח, ספקים לפרויקטים, ותחזוקה במספר אתרים
                            </div>
                        </label>
                    </div>

                    <div style="margin-top:14px;">
                        <button type="submit" class="cmms-btn cmms-btn-primary">
                            <?php CMMS_Icons::e( 'save', 16 ); ?> שמור הגדרות
                        </button>
                    </div>
                </form>
            </div>
        </section>
        <?php endif; ?>

        <?php elseif ( $tab === 'categories' ) : ?>
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title"><?php CMMS_Icons::e( 'tag', 16 ); ?> <?php $this->e( 'settings.categories' ); ?></h3>
            </div>
            <div class="cmms-section-body">
                <?php // 1.14.82: Full self-service category management.
                      // Customers can rename, hide, add, and delete their
                      // own maintenance categories. All operations go
                      // through cmms_category_* AJAX endpoints. ?>
                <p class="cmms-muted cmms-text-sm" style="margin:0 0 18px;">
                    <?php esc_html_e( 'התאם את קטגוריות התחזוקה לטרמינולוגיה של החברה שלך. ניתן לשנות שם, להסתיר קטגוריות שאינך משתמש בהן, ולהוסיף קטגוריות חדשות.', 'cmms-light' ); ?>
                </p>

                <div class="cmms-cat-list" data-cmms-categories>
                    <?php foreach ( $cats as $c ) : ?>
                        <div class="cmms-cat-row<?php echo $c->enabled ? '' : ' is-disabled'; ?>" data-cat-id="<?php echo (int) $c->id; ?>" data-is-default="<?php echo (int) $c->is_default; ?>">
                            <div class="cmms-cat-name-wrap">
                                <span class="cmms-cat-name" data-cmms-cat-name><?php echo esc_html( $c->name ); ?></span>
                                <?php if ( $c->is_default ) : ?>
                                    <span class="cmms-cat-badge"><?php esc_html_e( 'ברירת מחדל', 'cmms-light' ); ?></span>
                                <?php endif; ?>
                                <?php if ( ! $c->enabled ) : ?>
                                    <span class="cmms-cat-badge cmms-cat-badge-off" data-cmms-cat-disabled-badge><?php esc_html_e( 'מוסתרת', 'cmms-light' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="cmms-cat-actions">
                                <button type="button" class="cmms-cat-action-btn" data-cmms-cat-edit aria-label="<?php esc_attr_e( 'שנה שם', 'cmms-light' ); ?>">
                                    <span class="cmms-cat-action-icon">✏</span>
                                    <span class="cmms-cat-action-label"><?php esc_html_e( 'שנה שם', 'cmms-light' ); ?></span>
                                </button>
                                <button type="button" class="cmms-cat-action-btn" data-cmms-cat-toggle aria-label="<?php echo $c->enabled ? esc_attr__( 'הסתר', 'cmms-light' ) : esc_attr__( 'הצג', 'cmms-light' ); ?>">
                                    <span class="cmms-cat-action-icon"><?php echo $c->enabled ? '👁' : '🚫'; ?></span>
                                    <span class="cmms-cat-action-label" data-cmms-toggle-label><?php echo $c->enabled ? esc_html__( 'הסתר', 'cmms-light' ) : esc_html__( 'הצג', 'cmms-light' ); ?></span>
                                </button>
                                <?php if ( ! $c->is_default ) : ?>
                                    <button type="button" class="cmms-cat-action-btn cmms-cat-action-btn-danger" data-cmms-cat-delete aria-label="<?php esc_attr_e( 'מחק', 'cmms-light' ); ?>">
                                        <span class="cmms-cat-action-icon">🗑</span>
                                        <span class="cmms-cat-action-label"><?php esc_html_e( 'מחק', 'cmms-light' ); ?></span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="cmms-cat-add-row" style="margin-top:16px;">
                    <input type="text" class="cmms-input" data-cmms-cat-new-name placeholder="<?php esc_attr_e( 'שם קטגוריה חדשה', 'cmms-light' ); ?>" maxlength="100" style="flex:1;">
                    <button type="button" class="cmms-btn cmms-btn-secondary" data-cmms-cat-add>
                        <?php CMMS_Icons::e( 'plus', 16 ); ?>
                        <?php esc_html_e( 'הוסף קטגוריה', 'cmms-light' ); ?>
                    </button>
                </div>

                <div class="cmms-cat-feedback" data-cmms-cat-feedback role="status" aria-live="polite" hidden></div>
            </div>
        </section>

        <style>
        /* 1.14.82 — Category manager UI
           1.14.82.1 — Bigger, clearer action buttons with text labels.
           Previous design used 32px icon-only buttons which were too
           subtle for non-technical users to discover. Now: icon + text,
           larger hit target, hover state. */
        .cmms-cat-list { display: flex; flex-direction: column; gap: 10px; }
        .cmms-cat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            background: #fafafa;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 14px 18px;
            transition: opacity .15s ease, background .15s ease;
        }
        .cmms-cat-row.is-disabled { opacity: .55; }
        .cmms-cat-row.is-editing { background: #eef2ff; border-color: #c7d2fe; }
        .cmms-cat-name-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            flex: 1;
            min-width: 0;
        }
        .cmms-cat-name {
            font-size: 15px;
            font-weight: 500;
            color: #0f172a;
            word-break: break-word;
        }
        .cmms-cat-name-input {
            font-size: 15px;
            padding: 8px 12px;
            border: 2px solid #6366f1;
            border-radius: 8px;
            font-family: inherit;
            min-width: 220px;
            background: white;
        }
        .cmms-cat-name-input:focus {
            outline: 0;
            box-shadow: 0 0 0 3px rgba(99,102,241,.18);
        }
        /* 1.14.82.1: Edit-mode hint shown above the input. */
        .cmms-cat-edit-hint {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: #6366f1;
            font-weight: 500;
        }
        .cmms-cat-badge {
            background: #e0e7ff;
            color: #4338ca;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 999px;
        }
        .cmms-cat-badge-off {
            background: #fee2e2;
            color: #b91c1c;
        }
        .cmms-cat-actions {
            display: flex;
            gap: 6px;
            flex: none;
            flex-wrap: wrap;
        }
        /* 1.14.82.1: Action buttons — bigger, with icon + text. */
        .cmms-cat-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #475569;
            font-family: inherit;
            transition: background .12s ease, color .12s ease, border-color .12s ease;
            min-height: 36px;
        }
        .cmms-cat-action-btn:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        .cmms-cat-action-btn.cmms-cat-action-btn-danger:hover {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #b91c1c;
        }
        .cmms-cat-action-btn:disabled { opacity: .4; cursor: not-allowed; }
        .cmms-cat-action-icon { font-size: 16px; line-height: 1; }
        .cmms-cat-action-label { font-size: 13px; }
        .cmms-cat-add-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .cmms-cat-feedback {
            margin-top: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
        }
        .cmms-cat-feedback.is-success { background: #dcfce7; color: #166534; }
        .cmms-cat-feedback.is-error   { background: #fee2e2; color: #b91c1c; }
        @media (max-width: 640px) {
            .cmms-cat-row {
                padding: 12px 14px;
                flex-direction: column;
                align-items: stretch;
            }
            .cmms-cat-actions {
                margin-top: 4px;
                justify-content: flex-start;
            }
            .cmms-cat-name-input { min-width: 0; flex: 1; width: 100%; }
            .cmms-cat-add-row { flex-direction: column; align-items: stretch; }
            .cmms-cat-action-btn { flex: 1; justify-content: center; }
        }
        </style>

        <script>
        (function () {
            'use strict';
            var listEl = document.querySelector('[data-cmms-categories]');
            if (!listEl) return;

            var addBtn   = document.querySelector('[data-cmms-cat-add]');
            var addInput = document.querySelector('[data-cmms-cat-new-name]');
            var feedback = document.querySelector('[data-cmms-cat-feedback]');

            var AJAX_URL = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var NONCE    = <?php echo wp_json_encode( wp_create_nonce( 'cmms_categories' ) ); ?>;

            function showFeedback(message, isError) {
                if (!feedback) return;
                feedback.textContent = message;
                feedback.classList.toggle('is-error',   !!isError);
                feedback.classList.toggle('is-success', !isError);
                feedback.hidden = false;
                clearTimeout(feedback._t);
                feedback._t = setTimeout(function () {
                    feedback.hidden = true;
                }, 3500);
            }

            function postAjax(action, data) {
                var body = new URLSearchParams();
                body.set('action', action);
                body.set('nonce',  NONCE);
                Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
                return fetch(AJAX_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json().catch(function () { return null; }); });
            }

            // Edit name (inline).
            listEl.addEventListener('click', function (e) {
                var row = e.target.closest('.cmms-cat-row');
                if (!row) return;
                var catId = row.getAttribute('data-cat-id');

                if (e.target.closest('[data-cmms-cat-edit]')) {
                    enterEditMode(row);
                } else if (e.target.closest('[data-cmms-cat-toggle]')) {
                    toggleEnabled(row);
                } else if (e.target.closest('[data-cmms-cat-delete]')) {
                    deleteCategory(row);
                }
            });

            function enterEditMode(row) {
                if (row.classList.contains('is-editing')) return;
                row.classList.add('is-editing');
                var nameEl = row.querySelector('[data-cmms-cat-name]');
                var oldName = nameEl.textContent.trim();
                // 1.14.82.1: Wrap input + hint together so the user knows
                // they should press Enter (no save button on purpose —
                // inline editing pattern like Google Sheets).
                var wrap = document.createElement('span');
                wrap.setAttribute('data-cmms-edit-wrap', '');
                wrap.style.display = 'inline-block';
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'cmms-cat-name-input';
                input.value = oldName;
                input.maxLength = 100;
                var hint = document.createElement('span');
                hint.className = 'cmms-cat-edit-hint';
                hint.textContent = '↵ Enter לשמירה · Esc לביטול';
                wrap.appendChild(input);
                wrap.appendChild(hint);
                nameEl.replaceWith(wrap);
                input.focus();
                input.select();

                function commit() {
                    var newName = input.value.trim();
                    if (!newName || newName === oldName) {
                        cancel();
                        return;
                    }
                    postAjax('cmms_category_update', {
                        id:   row.getAttribute('data-cat-id'),
                        name: newName
                    }).then(function (res) {
                        if (res && res.success) {
                            renderName(row, res.data.name || newName);
                            row.classList.remove('is-editing');
                            showFeedback('הקטגוריה עודכנה');
                        } else {
                            var msg = (res && res.data && res.data.message) ? res.data.message : 'העדכון נכשל';
                            showFeedback(msg, true);
                            input.focus();
                        }
                    }).catch(function () {
                        showFeedback('שגיאת רשת', true);
                    });
                }

                function cancel() {
                    renderName(row, oldName);
                    row.classList.remove('is-editing');
                }

                input.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); commit(); }
                    else if (ev.key === 'Escape') { ev.preventDefault(); cancel(); }
                });
                input.addEventListener('blur', commit);
            }

            function renderName(row, newName) {
                var wrap = row.querySelector('[data-cmms-edit-wrap]');
                if (!wrap) return;
                var span = document.createElement('span');
                span.className = 'cmms-cat-name';
                span.setAttribute('data-cmms-cat-name', '');
                span.textContent = newName;
                wrap.replaceWith(span);
            }

            function toggleEnabled(row) {
                // Toggle by checking the current "off badge" presence.
                var isCurrentlyEnabled = !row.querySelector('[data-cmms-cat-disabled-badge]');
                postAjax('cmms_category_toggle', {
                    id: row.getAttribute('data-cat-id'),
                    enabled: isCurrentlyEnabled ? 0 : 1
                }).then(function (res) {
                    if (res && res.success) {
                        applyEnabledState(row, !!res.data.enabled);
                        showFeedback(res.data.enabled ? 'הקטגוריה הוצגה' : 'הקטגוריה הוסתרה');
                    } else {
                        var msg = (res && res.data && res.data.message) ? res.data.message : 'הפעולה נכשלה';
                        showFeedback(msg, true);
                    }
                }).catch(function () {
                    showFeedback('שגיאת רשת', true);
                });
            }

            function applyEnabledState(row, enabled) {
                row.classList.toggle('is-disabled', !enabled);
                var existingBadge = row.querySelector('[data-cmms-cat-disabled-badge]');
                var toggleBtn = row.querySelector('[data-cmms-cat-toggle]');
                if (enabled && existingBadge) {
                    existingBadge.remove();
                } else if (!enabled && !existingBadge) {
                    var badge = document.createElement('span');
                    badge.className = 'cmms-cat-badge cmms-cat-badge-off';
                    badge.setAttribute('data-cmms-cat-disabled-badge', '');
                    badge.textContent = 'מוסתרת';
                    row.querySelector('.cmms-cat-name-wrap').appendChild(badge);
                }
                if (toggleBtn) {
                    // 1.14.82.1: Update both icon AND text label so the
                    // user sees clearly what the button now does.
                    var icon  = toggleBtn.querySelector('.cmms-cat-action-icon');
                    var label = toggleBtn.querySelector('[data-cmms-toggle-label]');
                    if (icon)  icon.textContent  = enabled ? '👁' : '🚫';
                    if (label) label.textContent = enabled ? 'הסתר' : 'הצג';
                    toggleBtn.setAttribute('aria-label', enabled ? 'הסתר' : 'הצג');
                }
            }

            function deleteCategory(row) {
                if (!confirm('למחוק את הקטגוריה? פעולה זו לא ניתנת לביטול.')) return;
                postAjax('cmms_category_delete', {
                    id: row.getAttribute('data-cat-id')
                }).then(function (res) {
                    if (res && res.success) {
                        row.style.transition = 'opacity .2s, height .2s';
                        row.style.opacity = '0';
                        setTimeout(function () { row.remove(); }, 200);
                        showFeedback('הקטגוריה נמחקה');
                    } else {
                        var msg = (res && res.data && res.data.message) ? res.data.message : 'המחיקה נכשלה';
                        showFeedback(msg, true);
                    }
                }).catch(function () {
                    showFeedback('שגיאת רשת', true);
                });
            }

            // Add new.
            function doAdd() {
                var name = (addInput.value || '').trim();
                if (!name) {
                    addInput.focus();
                    return;
                }
                addBtn.disabled = true;
                postAjax('cmms_category_create', { name: name }).then(function (res) {
                    addBtn.disabled = false;
                    if (res && res.success) {
                        appendNewRow(res.data);
                        addInput.value = '';
                        addInput.focus();
                        showFeedback('הקטגוריה נוספה');
                    } else {
                        var msg = (res && res.data && res.data.message) ? res.data.message : 'ההוספה נכשלה';
                        showFeedback(msg, true);
                    }
                }).catch(function () {
                    addBtn.disabled = false;
                    showFeedback('שגיאת רשת', true);
                });
            }

            function appendNewRow(data) {
                var row = document.createElement('div');
                row.className = 'cmms-cat-row';
                row.setAttribute('data-cat-id',    String(data.id));
                row.setAttribute('data-is-default', '0');
                // 1.14.82.1: New rows use the same large button styling
                // as the server-rendered ones — icon + text label.
                row.innerHTML =
                    '<div class="cmms-cat-name-wrap">' +
                        '<span class="cmms-cat-name" data-cmms-cat-name></span>' +
                    '</div>' +
                    '<div class="cmms-cat-actions">' +
                        '<button type="button" class="cmms-cat-action-btn" data-cmms-cat-edit aria-label="שנה שם">' +
                            '<span class="cmms-cat-action-icon">✏</span>' +
                            '<span class="cmms-cat-action-label">שנה שם</span>' +
                        '</button>' +
                        '<button type="button" class="cmms-cat-action-btn" data-cmms-cat-toggle aria-label="הסתר">' +
                            '<span class="cmms-cat-action-icon">👁</span>' +
                            '<span class="cmms-cat-action-label" data-cmms-toggle-label>הסתר</span>' +
                        '</button>' +
                        '<button type="button" class="cmms-cat-action-btn cmms-cat-action-btn-danger" data-cmms-cat-delete aria-label="מחק">' +
                            '<span class="cmms-cat-action-icon">🗑</span>' +
                            '<span class="cmms-cat-action-label">מחק</span>' +
                        '</button>' +
                    '</div>';
                row.querySelector('[data-cmms-cat-name]').textContent = data.name;
                listEl.appendChild(row);
            }

            if (addBtn) {
                addBtn.addEventListener('click', doAdd);
            }
            if (addInput) {
                addInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); doAdd(); }
                });
            }
        })();
        </script>

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
        <?php // 1.14.84: Telegram tab — per-user linking to bot. ?>
        <?php if ( $tab === 'telegram' && CMMS_Telegram::is_enabled() ) : $this->view_settings_telegram( $u ); endif; ?>
        <?php
    }

    /**
     * 1.14.56: Billing & Subscription settings tab.
     *
     * Owner-only. Shows the current plan, billing cycle, next charge
     * date, and (most importantly) a "Cancel subscription" button that
     * cancels the recurring charge at iCredit and marks the account
    /**
     * 1.14.84: Telegram linking tab. Each user can link/unlink their
     * own Telegram account here. The token is per-user, single-use,
     * and expires in 30 minutes. After clicking "Connect Telegram",
     * the user opens the deep-link in Telegram, the bot fires /start
     * <token>, and the binding completes.
     */
    private function view_settings_telegram( $u ) {
        // Handle disconnect action
        if ( ! empty( $_POST['cmms_telegram_unlink'] ) ) {
            check_admin_referer( 'cmms_telegram_unlink', 'cmms_telegram_unlink_nonce' );
            CMMS_Telegram::unlink( (int) $u->id );
            // Re-fetch to show updated state
            wp_safe_redirect( $this->url( array( 'view' => 'settings', 'tab' => 'telegram', 'unlinked' => 1 ) ) );
            exit;
        }

        $link        = CMMS_Telegram::get_link( (int) $u->id );
        $is_linked   = ( $link && ! empty( $link->telegram_user_id ) );
        $bot_username= CMMS_Telegram::bot_username();
        $unlinked    = ! empty( $_GET['unlinked'] );
        ?>
        <section class="cmms-section">
            <div class="cmms-section-head">
                <h3 class="cmms-section-title">
                    📱 חיבור לטלגרם
                </h3>
            </div>
            <div class="cmms-section-body">

                <?php if ( $unlinked ) : ?>
                    <div class="cmms-alert cmms-alert-success" style="margin-bottom:14px;">
                        ✅ החיבור לטלגרם בוטל בהצלחה
                    </div>
                <?php endif; ?>

                <p class="cmms-muted" style="margin:0 0 18px;">
                    קבל התראות על משימות חדשות ועדכן סטטוסים ישירות מטלגרם.
                    הבוט שולח לך הודעה כשמשימה מוקצית אליך, ומאפשר לך לסמן
                    סטטוסים, לצרף תמונות ולכתוב הערות.
                </p>

                <?php if ( $is_linked ) : ?>
                    <div style="background:#f0fdf4;border:1px solid #86efac;padding:14px 18px;border-radius:10px;margin-bottom:14px;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                            <span style="font-size:18px;">✅</span>
                            <strong style="color:#166534;font-size:14px;">החשבון מחובר לטלגרם</strong>
                        </div>
                        <?php if ( $link->telegram_first_name || $link->telegram_username ) : ?>
                            <div style="font-size:13px;color:#15803d;padding-inline-start:28px;">
                                <?php
                                $name_parts = array();
                                if ( $link->telegram_first_name ) $name_parts[] = $link->telegram_first_name;
                                if ( $link->telegram_username )   $name_parts[] = '@' . $link->telegram_username;
                                echo esc_html( implode( ' · ', $name_parts ) );
                                ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $link->connected_at ) : ?>
                            <div style="font-size:11px;color:#16a34a;padding-inline-start:28px;margin-top:4px;">
                                מחובר מאז: <?php echo esc_html( mysql2date( 'd/m/Y H:i', $link->connected_at ) ); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field( 'cmms_telegram_unlink', 'cmms_telegram_unlink_nonce' ); ?>
                        <input type="hidden" name="cmms_telegram_unlink" value="1">
                        <button type="submit" class="cmms-btn cmms-btn-secondary"
                                onclick="return confirm('לבטל את החיבור לטלגרם? תפסיק לקבל הודעות מהבוט.');">
                            🔌 נתק את הטלגרם
                        </button>
                    </form>

                <?php else :
                    // Not linked — generate a fresh token and show the deep-link.
                    $token = CMMS_Telegram::generate_link_token( (int) $u->id );
                    $deep_link = $token ? CMMS_Telegram::build_link_url( $token ) : '';
                    ?>
                    <?php if ( ! $bot_username || ! $deep_link ) : ?>
                        <div class="cmms-alert cmms-alert-warning">
                            ⚠️ הבוט לא מוגדר במלואו. פנה למנהל המערכת.
                        </div>
                    <?php else : ?>
                        <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:16px 18px;border-radius:10px;">
                            <div style="font-weight:600;color:#1e40af;margin-bottom:10px;">
                                כדי להתחבר:
                            </div>
                            <ol style="margin:0 0 14px;padding-inline-start:20px;color:#1e3a8a;font-size:13.5px;line-height:1.8;">
                                <li>לחץ על הכפתור הכחול למטה</li>
                                <li>טלגרם ייפתח עם הבוט שלנו</li>
                                <li>לחץ "START" או שלח /start</li>
                                <li>תקבל הודעת אישור והחשבון יחובר</li>
                            </ol>
                            <a href="<?php echo esc_url( $deep_link ); ?>"
                               target="_blank"
                               class="cmms-btn cmms-btn-primary"
                               style="background:#229ED9;border-color:#229ED9;">
                                📱 פתח את הבוט בטלגרם
                            </a>
                            <div style="font-size:11px;color:#3b82f6;margin-top:10px;">
                                הקישור תקף ל-30 דקות.
                                אם פג תוקף — רענן את הדף.
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * 1.14.56: Billing & Subscription settings tab.
     * Allows users with manage permission (owner) to view their plan,
     * change seat count, and cancel — flipping subscription_status
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
        $subs_t     = CMMS_DB::table( 'subscriptions' );        $account = $wpdb->get_row( $wpdb->prepare(
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
