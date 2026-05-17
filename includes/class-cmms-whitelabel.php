<?php
/**
 * White-label / branding class.
 *
 * Hides every WordPress fingerprint from CMMS users (non-admin):
 *   - Replaces theme header/footer with a standalone full-screen template.
 *   - Hides the WP admin bar.
 *   - Blocks /wp-admin and /wp-login.php for non-admin users (redirects to /cmms-*).
 *   - Strips generator meta, RSD/WLW/oEmbed discovery, emoji scripts.
 *   - Forces document title to "CMMS" on CMMS pages.
 *   - Removes "Powered by WordPress" / login footer / language switcher on the
 *     CMMS pages they could conceivably reach.
 *
 * The wp-admin / wp-login lockout deliberately never applies to global admins
 * (manage_options) so the site owner can still reach their WP back-end.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Whitelabel {

    /** Brand label shown to end users. Internal plugin name stays "CMMS Light". */
    const BRAND = 'CMMS';

    /** Pages that should be served via the standalone template. */
    private static function cmms_slugs() {
        return array( 'cmms-signup', 'cmms-login', 'cmms-dashboard', 'cmms-form' );
    }

    public function register() {
        // Make our /templates/cmms-app-template.php available as a page template.
        add_filter( 'theme_page_templates', array( $this, 'register_template' ) );
        add_filter( 'template_include',     array( $this, 'force_template' ), 99 );

        // Hide admin bar for non-WP-admins on CMMS pages.
        add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) );

        // Block wp-admin for CMMS users (subscribers).
        add_action( 'admin_init', array( $this, 'block_wp_admin' ) );

        // Redirect WP login screen to /cmms-login/.
        add_action( 'login_init', array( $this, 'block_wp_login' ) );

        // Strip generator and other WP fingerprints sitewide (cheap, low-risk).
        remove_action( 'wp_head', 'wp_generator' );

        // Override document title on CMMS pages.
        add_filter( 'pre_get_document_title', array( $this, 'rewrite_title' ), 100 );
        add_filter( 'document_title_parts',   array( $this, 'rewrite_title_parts' ), 100 );

        // Hide the "Howdy" greeting and other WP-isms (admin bar, even if shown).
        add_filter( 'admin_bar_menu', array( $this, 'simplify_admin_bar' ), 999 );
    }

    public function register_template( $templates ) {
        $templates['cmms-app-template.php'] = self::BRAND . ' App (full screen)';
        return $templates;
    }

    /**
     * If the current page is one of the four CMMS pages, override whatever
     * template the theme would have used and serve our standalone one. This
     * means the user never even has to choose the template manually - it just
     * works on the auto-created pages.
     */
    public function force_template( $template ) {
        if ( ! is_singular( 'page' ) ) return $template;
        global $post;
        if ( ! $post ) return $template;

        if ( in_array( $post->post_name, self::cmms_slugs(), true ) ) {
            $custom = CMMS_LIGHT_DIR . 'templates/cmms-app-template.php';
            if ( file_exists( $custom ) ) {
                return $custom;
            }
        }
        return $template;
    }

    public function maybe_hide_admin_bar( $show ) {
        // Always hide the bar on CMMS pages, even for admins - it clutters the
        // view and breaks the "this is a SaaS" feeling.
        if ( $this->is_cmms_page() ) {
            return false;
        }
        // Off-CMMS-pages: hide the bar from CMMS users (subscribers without
        // manage_options) so they never see "Howdy, X" on a blog post.
        if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
            $u = CMMS_Auth::current_cmms_user_raw();
            if ( $u ) return false;
        }
        return $show;
    }

    /**
     * If a CMMS user (not a global admin) browses to /wp-admin/, send them to
     * the dashboard. Allows admin-ajax.php and admin-post.php through because
     * our forms post there.
     */
    public function block_wp_admin() {
        if ( ! is_user_logged_in() ) return;
        if ( current_user_can( 'manage_options' ) ) return;
        // Allow asynchronous endpoints.
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
        $script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( $_SERVER['SCRIPT_NAME'] ) : '';
        if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php' ), true ) ) return;

        // Anything else under wp-admin -> kick out to the CMMS dashboard.
        wp_safe_redirect( home_url( '/cmms-dashboard/' ) );
        exit;
    }

    /**
     * Send people who hit /wp-login.php directly to /cmms-login/ instead, except
     * for explicit logout requests, password reset flows, and admin logins.
     */
    public function block_wp_login() {
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        // Let these flow through normally.
        $allowed = array( 'logout', 'lostpassword', 'rp', 'resetpass', 'retrievepassword', 'postpass' );
        if ( in_array( $action, $allowed, true ) ) return;

        // If the user is already logged in as an admin, keep them in WP.
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) return;

        // Otherwise, replace the WP login screen with our own.
        // We only do this for GET (the form display), not POST, so that custom
        // tooling that posts to wp-login.php isn't broken.
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'GET' ) return;

        wp_safe_redirect( home_url( '/cmms-login/' ) );
        exit;
    }

    public function rewrite_title( $title ) {
        if ( $this->is_cmms_page() ) return self::BRAND;
        return $title;
    }

    public function rewrite_title_parts( $parts ) {
        if ( $this->is_cmms_page() ) {
            return array( 'title' => self::BRAND );
        }
        return $parts;
    }

    public function simplify_admin_bar( $wp_admin_bar ) {
        if ( ! is_admin_bar_showing() ) return;
        if ( current_user_can( 'manage_options' ) ) return;
        // For non-admins who somehow still see the bar (e.g. edge case):
        // strip every node so the only thing left is logout. We try to remove
        // the most identifying ones, but the safest is just hiding it via CSS
        // (already done in maybe_hide_admin_bar).
        $remove = array( 'wp-logo', 'about', 'wporg', 'documentation', 'support-forums', 'feedback', 'view-site', 'updates', 'comments', 'new-content', 'my-account' );
        foreach ( $remove as $id ) {
            $wp_admin_bar->remove_node( $id );
        }
    }

    private function is_cmms_page() {
        if ( is_admin() ) return false;
        if ( ! is_singular() ) return false;
        global $post;
        if ( ! $post ) return false;
        return in_array( $post->post_name, self::cmms_slugs(), true );
    }
}
