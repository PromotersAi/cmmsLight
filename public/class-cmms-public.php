<?php
/**
 * Frontend asset loader + privacy / noindex enforcement for CMMS pages.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMMS_Public {

    public function register() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
        // Run AFTER all themes/plugins enqueue (priority 999) so we can
        // remove their assets cleanly on CMMS pages only.
        add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_third_party_on_cmms_pages' ), 999 );
        add_action( 'wp_head', array( $this, 'inject_noindex' ), 1 );
        add_action( 'send_headers', array( $this, 'send_robots_header' ) );
        // 1.14.26: cache-control headers on CMMS pages to defeat upstream
        // page caches that would otherwise serve stale HTML pointing at
        // missing assets after a release.
        add_action( 'send_headers', array( $this, 'send_no_cache_headers_on_cmms' ) );
        add_filter( 'wp_robots', array( $this, 'filter_wp_robots' ) );
        add_filter( 'language_attributes', array( $this, 'filter_lang_attrs' ) );
        add_filter( 'body_class', array( $this, 'filter_body_class' ) );

        // Tell Elementor (and similar page builders) that CMMS pages are not
        // their concern. We do this on `wp` so by the time the builder asks
        // "is this a page I should render?", the answer is no.
        add_action( 'wp', array( $this, 'isolate_from_page_builders' ), 1 );
    }

    /**
     * Isolate CMMS app pages from Elementor's content filters and asset loader.
     * Without this, Elementor would try to render its document model on top
     * of the standalone shortcode output, often producing duplicate elements
     * or layout corruption.
     *
     * Elementor checks Plugin::$instance->documents->get_doc_for_frontend().
     * The cleanest way to opt out is to remove its rendering hooks on our
     * pages. We try several strategies because Elementor's API changes:
     *   1. Use the documented elementor/frontend/builder_content/before_render filter
     *   2. As a fallback, remove the_content filter that Elementor uses
     */
    public function isolate_from_page_builders() {
        if ( ! $this->is_cmms_page() ) return;

        // Elementor — opt out of its frontend rendering on CMMS pages.
        // Filter is documented in Elementor's API.
        add_filter( 'elementor/frontend/builder_content/before_render', '__return_false', 999 );
        add_filter( 'elementor/widget/render_content', function( $content ) {
            // If we somehow still render, at least don't inject Elementor markup.
            return $content;
        }, 999 );

        // Some Elementor versions hook into the_content; remove if present.
        if ( class_exists( 'Elementor\Plugin' ) ) {
            remove_all_filters( 'template_include', 9 );
        }
    }

    /**
     * On CMMS app pages (dashboard, login, signup, public form), strip out
     * frontend assets from page builders and theme components that aren't
     * needed for the standalone app UI. This prevents:
     *   - Elementor frontend.min.js trying to bind to non-existent DOM nodes
     *     and throwing console errors
     *   - elementorFrontendConfig dependencies that the app doesn't have
     *   - Theme nav/footer scripts loading on a UI that hides nav/footer anyway
     *
     * IMPORTANT: This only runs on CMMS pages. Marketing pages (the homepage,
     * About, Pricing, etc.) keep all their Elementor and theme assets intact.
     */
    public function dequeue_third_party_on_cmms_pages() {
        if ( ! $this->is_cmms_page() ) return;

        // Elementor frontend handles + their inline data
        $elementor_handles = array(
            'elementor-frontend',
            'elementor-frontend-modules',
            'elementor-pro-frontend',
            'elementor-waypoints',
            'elementor-sticky',
            'elementor-icons',
            'elementor-icons-shared-0',
            'elementor-icons-fa-solid',
            'elementor-icons-fa-regular',
            'elementor-icons-fa-brands',
            'elementor-common',
            'elementor-app-loader',
            'swiper',
            'share-link',
            'smartmenus',
        );
        foreach ( $elementor_handles as $h ) {
            wp_dequeue_script( $h );
            wp_deregister_script( $h );
            wp_dequeue_style( $h );
            wp_deregister_style( $h );
        }

        // Common theme handles that try to attach to header/footer DOM
        // elements that our standalone template doesn't render.
        $theme_handles = array(
            'theme-script',
            'theme-front',
            'header-footer',
            'sticky-header',
        );
        foreach ( $theme_handles as $h ) {
            wp_dequeue_script( $h );
        }
    }

    public function enqueue() {
        if ( ! $this->is_cmms_page() ) {
            return;
        }
        // Cache-busting key combines the plugin version with the file's
        // mtime. Why both:
        //   - The plugin version handles the normal release flow: every
        //     bumped version produces a new ?ver= param.
        //   - filemtime is a safety net for edge cases where someone
        //     edited an asset directly on the server without bumping
        //     CMMS_LIGHT_VERSION, or when a CDN strips query strings
        //     before forwarding to origin (some Sucuri/CloudFlare
        //     configs do this).
        // Both stamped on top of each other is bigger than either alone.
        $css_path = CMMS_LIGHT_DIR . 'assets/css/cmms-light-public.css';
        $js_path  = CMMS_LIGHT_DIR . 'assets/js/cmms-light-public.js';
        $css_ver  = CMMS_LIGHT_VERSION . '.' . ( file_exists( $css_path ) ? filemtime( $css_path ) : 0 );
        $js_ver   = CMMS_LIGHT_VERSION . '.' . ( file_exists( $js_path )  ? filemtime( $js_path )  : 0 );

        wp_enqueue_style(
            'cmms-light-public',
            CMMS_LIGHT_URL . 'assets/css/cmms-light-public.css',
            array(),
            $css_ver
        );
        wp_enqueue_script(
            'cmms-light-public',
            CMMS_LIGHT_URL . 'assets/js/cmms-light-public.js',
            array(),
            $js_ver,
            true
        );
        wp_localize_script( 'cmms-light-public', 'CMMSLight', array(
            'restUrl'         => esc_url_raw( rest_url() ),
            'adminPost'       => esc_url_raw( admin_url( 'admin-post.php' ) ),
            'home'            => esc_url_raw( home_url( '/' ) ),
            // 1.14.26: version stamp lets the client-side recovery code
            // (see inject_self_heal in this file) detect a JS/HTML
            // version mismatch and recover automatically by unregistering
            // the SW and reloading. Without these, a stale JS bundle can
            // load against a new HTML and stay broken silently.
            'pluginVersion'   => defined( 'CMMS_LIGHT_VERSION' ) ? CMMS_LIGHT_VERSION : '0',
            'buildAt'         => $js_ver, // includes filemtime
            'vapidPublicKey'  => class_exists( 'CMMS_Push' ) ? CMMS_Push::public_key() : '',
            'pushSubNonce'    => wp_create_nonce( 'cmms_push_subscribe' ),
            'pushUnsubNonce'  => wp_create_nonce( 'cmms_push_unsubscribe' ),
            'pushOn'          => class_exists( 'CMMS_I18n' ) ? CMMS_I18n::t( 'push.on' ) : '',
            'pushDenied'      => class_exists( 'CMMS_I18n' ) ? CMMS_I18n::t( 'push.denied' ) : '',
            'pushDescDefault' => class_exists( 'CMMS_I18n' ) ? CMMS_I18n::t( 'push.desc_default' ) : '',
            'pushNotSupported'     => class_exists( 'CMMS_I18n' ) ? CMMS_I18n::t( 'push.not_supported' ) : '',
            'pushNotSupportedHelp' => class_exists( 'CMMS_I18n' ) ? CMMS_I18n::t( 'push.not_supported_help' ) : '',
            'installFollowSteps'   => class_exists( 'CMMS_I18n' ) ? CMMS_I18n::t( 'install.follow_steps' ) : '',
        ) );
    }

    /**
     * Print noindex,nofollow on every CMMS page (internal app + public report
     * forms). Public forms are private channels for one organization, not
     * marketing content - they must not be discoverable on search engines.
     */
    public function inject_noindex() {
        if ( ! $this->is_cmms_page() ) {
            return;
        }
        echo '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">' . "\n";
        echo '<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">' . "\n";
        echo '<meta name="bingbot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">' . "\n";

        // 1.14.26: tell the browser not to cache the HTML document itself.
        // The CSS/JS files have version-stamped URLs so they cache fine,
        // but the HTML shell must always be fresh — otherwise the browser
        // serves yesterday's HTML pointing to today's missing-from-cache
        // assets, and the user sees a half-broken page until they hard-
        // reload. These meta tags are belt-and-braces; the real fix is
        // upstream cache config, but we add the hints so any cache layer
        // that respects them does the right thing.
        echo '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">' . "\n";
        echo '<meta http-equiv="Pragma" content="no-cache">' . "\n";
        echo '<meta http-equiv="Expires" content="0">' . "\n";

        // Version stamp visible to client JS so the self-heal code can
        // detect a JS/HTML version mismatch. The JS bundle prints its own
        // version on load; if they differ, we're looking at stale assets
        // and the recovery routine triggers.
        $ver = defined( 'CMMS_LIGHT_VERSION' ) ? CMMS_LIGHT_VERSION : '0';
        echo '<meta name="cmms-version" content="' . esc_attr( $ver ) . '">' . "\n";
    }

    /**
     * Tell server-level page caches (Cloudflare, Sucuri, WP Rocket, etc.)
     * not to cache CMMS pages. The standalone app HTML is dynamic per
     * user (auth state, dashboard counts, etc.); caching it makes both
     * users see each other's data and stale-asset references.
     *
     * Sent on every CMMS page request. Cheap, defensive, idempotent.
     */
    public function send_no_cache_headers_on_cmms() {
        if ( ! $this->is_cmms_page() && ! $this->is_cmms_machine_endpoint() ) return;
        if ( headers_sent() ) return;
        nocache_headers(); // WordPress core helper — sets correct mix.
        header( 'X-CMMS-NoCache: 1' ); // diagnostic marker.
    }

    /**
     * Send X-Robots-Tag header on CMMS pages so even non-HTML responses
     * (manifest, service worker) get covered.
     */
    public function send_robots_header() {
        if ( $this->is_cmms_page() || $this->is_cmms_machine_endpoint() ) {
            if ( ! headers_sent() ) {
                header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex' );
            }
        }
    }

    /**
     * WP 5.7+ uses wp_robots() to build the robots meta. We rewrite it for
     * CMMS pages to be doubly sure.
     */
    public function filter_wp_robots( $robots ) {
        if ( $this->is_cmms_page() ) {
            $robots = array(
                'noindex'      => true,
                'nofollow'     => true,
                'noarchive'    => true,
                'nosnippet'    => true,
                'noimageindex' => true,
            );
        }
        return $robots;
    }

    /**
     * Detect cmms-bearing pages: the four auto-created pages, or any post
     * containing one of our shortcodes.
     */
    private function is_cmms_page() {
        if ( ! is_singular() ) return false;
        global $post;
        if ( ! $post ) return false;

        // 1.14.64: include forgot/reset (added in 1.14.61) so their
        // CSS gets enqueued — without this, those pages render
        // unstyled because cmms-light-public.css never loads.
        $slugs = array( 'cmms-signup', 'cmms-login', 'cmms-dashboard', 'cmms-form', 'cmms-asset', 'start', 'cmms-forgot', 'cmms-reset' );
        if ( in_array( $post->post_name, $slugs, true ) ) {
            return true;
        }
        $shortcodes = array( 'cmms_signup', 'cmms_login', 'cmms_dashboard', 'cmms_public_form', 'cmms_public_asset', 'cmms_onboarding', 'cmms_forgot', 'cmms_reset' );
        foreach ( $shortcodes as $sc ) {
            if ( has_shortcode( (string) $post->post_content, $sc ) ) {
                return true;
            }
        }
        return false;
    }

    private function is_cmms_machine_endpoint() {
        // PWA endpoints are served via query string (?cmms_pwa=manifest|sw)
        // on /cmms-dashboard/. We match those, not the legacy file-style URLs.
        if ( isset( $_GET['cmms_pwa'] ) ) {
            $type = sanitize_key( wp_unslash( $_GET['cmms_pwa'] ) );
            if ( $type === 'manifest' || $type === 'sw' ) return true;
        }
        return false;
    }

    /**
     * On CMMS pages, override <html lang="..." dir="..."> so the language
     * (and rtl/ltr direction) reflects the user's CMMS language choice,
     * independent of the WP site language.
     */
    public function filter_lang_attrs( $output ) {
        if ( ! $this->is_cmms_page() ) {
            return $output;
        }
        if ( ! class_exists( 'CMMS_I18n' ) ) {
            return $output;
        }
        $lang = CMMS_I18n::current();
        $dir  = CMMS_I18n::dir();
        // Strip any existing lang= and dir= so we cleanly replace them.
        $output = preg_replace( '/\s+lang="[^"]*"/i', '', $output );
        $output = preg_replace( '/\s+dir="[^"]*"/i', '', $output );
        $output .= ' lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $dir ) . '"';
        return $output;
    }

    /**
     * Tag <body> so the CSS can target CMMS pages distinctly from theme pages.
     */
    public function filter_body_class( $classes ) {
        if ( $this->is_cmms_page() ) {
            $classes[] = 'cmms-page';
            if ( class_exists( 'CMMS_I18n' ) ) {
                $classes[] = 'cmms-lang-' . sanitize_html_class( CMMS_I18n::current() );
            }
        }
        return $classes;
    }
}
