<?php
/**
 * Global Admin UI - for the WordPress site administrator (manage_options).
 * Provides oversight across ALL accounts.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMMS_Admin {

    public function register() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_smtp_notice' ) );
    }

    /**
     * Show a one-line, dismissable warning in WP admin if email is going to
     * fall through to PHP mail() (which usually means delivery failures /
     * spam folders for end users). We only show this once the plugin actually
     * has at least one CMMS account, so a fresh install isn't nagged before
     * it's relevant.
     */
    public function maybe_show_smtp_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! class_exists( 'CMMS_Notifications' ) ) return;
        if ( CMMS_Notifications::smtp_likely_configured() ) return;

        global $wpdb;
        $has_accounts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . CMMS_DB::table( 'accounts' ) );
        if ( ! $has_accounts ) return;

        $dismiss_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=cmms_smtp_warning_dismiss' ),
            'cmms_smtp_warning_dismiss'
        );
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'CMMS — Email delivery may be unreliable.', 'cmms-light' ); ?></strong>
                <?php esc_html_e( 'WordPress is using PHP mail() to send notifications. To make sure your team and customers actually receive emails (instead of them landing in spam), install a free SMTP plugin such as FluentSMTP or WP Mail SMTP and connect it to your email provider.', 'cmms-light' ); ?>
                <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=fluent+smtp&tab=search' ) ); ?>" class="button button-small" style="margin-inline-start:8px;"><?php esc_html_e( 'Install FluentSMTP', 'cmms-light' ); ?></a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-inline-start:8px;"><?php esc_html_e( 'Dismiss', 'cmms-light' ); ?></a>
            </p>
        </div>
        <?php
    }

    public function menu() {
        add_menu_page(
            __( 'CMMS Light', 'cmms-light' ),
            __( 'CMMS Light', 'cmms-light' ),
            'manage_options',
            'cmms-light',
            array( $this, 'page_dashboard' ),
            'dashicons-clipboard',
            56
        );
        add_submenu_page(
            'cmms-light',
            __( 'Dashboard', 'cmms-light' ),
            __( 'Dashboard', 'cmms-light' ),
            'manage_options',
            'cmms-light',
            array( $this, 'page_dashboard' )
        );
        add_submenu_page(
            'cmms-light',
            __( 'Accounts', 'cmms-light' ),
            __( 'Accounts', 'cmms-light' ),
            'manage_options',
            'cmms-light-accounts',
            array( $this, 'page_accounts' )
        );
        add_submenu_page(
            'cmms-light',
            __( 'Users', 'cmms-light' ),
            __( 'Users', 'cmms-light' ),
            'manage_options',
            'cmms-light-users',
            array( $this, 'page_users' )
        );
        add_submenu_page(
            'cmms-light',
            __( 'Setup Pages', 'cmms-light' ),
            __( 'Setup Pages', 'cmms-light' ),
            'manage_options',
            'cmms-light-setup',
            array( $this, 'page_setup' )
        );
        // 1.14.35: Packages (Super Admin pricing management).
        add_submenu_page(
            'cmms-light',
            __( 'Packages', 'cmms-light' ),
            __( 'Packages', 'cmms-light' ),
            'manage_options',
            CMMS_Admin_Packages::SLUG,
            array( 'CMMS_Admin_Packages', 'render' )
        );
        // 1.14.36: iCredit settings.
        add_submenu_page(
            'cmms-light',
            __( 'iCredit', 'cmms-light' ),
            __( 'iCredit', 'cmms-light' ),
            'manage_options',
            CMMS_Admin_Icredit::SLUG,
            array( 'CMMS_Admin_Icredit', 'render' )
        );
        // 1.14.44: Payments + Subscriptions list.
        add_submenu_page(
            'cmms-light',
            __( 'Payments', 'cmms-light' ),
            __( 'Payments', 'cmms-light' ),
            'manage_options',
            CMMS_Admin_Payments::SLUG,
            array( 'CMMS_Admin_Payments', 'render' )
        );
        // 1.14.59: Email Templates editor.
        add_submenu_page(
            'cmms-light',
            __( 'Email Templates', 'cmms-light' ),
            __( 'Email Templates', 'cmms-light' ),
            'manage_options',
            CMMS_Admin_EmailTemplates::SLUG,
            array( 'CMMS_Admin_EmailTemplates', 'render' )
        );
    }

    public function handle_actions() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
        if ( empty( $_GET['page'] ) || strpos( $_GET['page'], 'cmms-light' ) !== 0 ) return;

        if ( isset( $_POST['cmms_admin_action'] ) ) {
            if ( ! isset( $_POST['cmms_admin_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['cmms_admin_nonce'] ), 'cmms_admin' ) ) {
                wp_die( esc_html__( 'Security check failed.', 'cmms-light' ) );
            }
            $action = sanitize_text_field( wp_unslash( $_POST['cmms_admin_action'] ) );
            $aid    = (int) ( $_POST['account_id'] ?? 0 );

            switch ( $action ) {
                case 'activate':
                    if ( $aid ) CMMS_Accounts::set_status( $aid, 'active' );
                    break;
                case 'deactivate':
                    if ( $aid ) CMMS_Accounts::set_status( $aid, 'inactive' );
                    break;
                case 'delete':
                    if ( $aid ) CMMS_Accounts::delete_account( $aid );
                    break;
                case 'rebuild_pages':
                    CMMS_Activator::activate();
                    break;
                case 'create_demo':
                    $result = CMMS_Demo::create();
                    if ( is_wp_error( $result ) ) {
                        set_transient( 'cmms_demo_result_' . get_current_user_id(), array( 'error' => $result->get_error_message() ), 300 );
                    } else {
                        set_transient( 'cmms_demo_result_' . get_current_user_id(), $result, 300 );
                    }
                    wp_safe_redirect( add_query_arg( array( 'page' => 'cmms-light-setup', 'cmms_msg' => 'demo_created' ), admin_url( 'admin.php' ) ) );
                    exit;
            }
            wp_safe_redirect( add_query_arg( array( 'page' => sanitize_text_field( $_GET['page'] ), 'cmms_msg' => 'done' ), admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    /* =================== DASHBOARD =================== */
    public function page_dashboard() {
        global $wpdb;
        $t_acc   = CMMS_DB::table( 'accounts' );
        $t_users = CMMS_DB::table( 'users' );
        $t_tasks = CMMS_DB::table( 'tasks' );
        $t_assets = CMMS_DB::table( 'assets' );

        $totals = array(
            'accounts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t_acc" ),
            'active'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t_acc WHERE status = 'active'" ),
            'users'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t_users" ),
            'tasks'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t_tasks" ),
            'open'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t_tasks WHERE status IN ('open','in_progress','waiting')" ),
            'assets'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t_assets" ),
        );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'CMMS Light - Overview', 'cmms-light' ); ?></h1>
            <?php if ( ! empty( $_GET['cmms_msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Action completed.', 'cmms-light' ); ?></p></div>
            <?php endif; ?>

            <div style="display:flex; flex-wrap:wrap; gap:16px; margin-top:20px;">
                <?php
                $cards = array(
                    array( __( 'Total Accounts', 'cmms-light' ), $totals['accounts'] ),
                    array( __( 'Active Accounts', 'cmms-light' ), $totals['active'] ),
                    array( __( 'Total Users', 'cmms-light' ), $totals['users'] ),
                    array( __( 'Total Tasks', 'cmms-light' ), $totals['tasks'] ),
                    array( __( 'Open Tasks', 'cmms-light' ), $totals['open'] ),
                    array( __( 'Total Assets', 'cmms-light' ), $totals['assets'] ),
                );
                foreach ( $cards as $c ) :
                ?>
                <div style="background:#fff; border:1px solid #ddd; padding:18px 24px; min-width:160px; border-radius:6px;">
                    <div style="font-size:28px; font-weight:600;"><?php echo esc_html( $c[1] ); ?></div>
                    <div style="color:#666;"><?php echo esc_html( $c[0] ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <h2 style="margin-top:30px;"><?php esc_html_e( 'Recent Accounts', 'cmms-light' ); ?></h2>
            <?php
            $recent = $wpdb->get_results( "SELECT * FROM $t_acc ORDER BY created_at DESC LIMIT 10" );
            if ( $recent ) :
            ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php esc_html_e( 'Name', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'cmms-light' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $recent as $a ) : ?>
                    <tr>
                        <td><?php echo esc_html( $a->id ); ?></td>
                        <td><?php echo esc_html( $a->name ); ?></td>
                        <td><?php echo esc_html( $a->status ); ?></td>
                        <td><?php echo esc_html( $a->created_at ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <p><?php esc_html_e( 'No accounts yet. Send users to the signup page to begin.', 'cmms-light' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =================== ACCOUNTS =================== */
    public function page_accounts() {
        $accounts = CMMS_Accounts::all();
        global $wpdb;
        $t_users = CMMS_DB::table( 'users' );
        $t_tasks = CMMS_DB::table( 'tasks' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Accounts', 'cmms-light' ); ?></h1>
            <?php if ( ! empty( $_GET['cmms_msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Updated.', 'cmms-light' ); ?></p></div>
            <?php endif; ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php esc_html_e( 'Name', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Owner', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Users', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Tasks', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'cmms-light' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $accounts ) ) : ?>
                    <tr><td colspan="8"><?php esc_html_e( 'No accounts.', 'cmms-light' ); ?></td></tr>
                <?php else : foreach ( $accounts as $a ) :
                    $owner = $a->owner_user_id ? get_userdata( $a->owner_user_id ) : null;
                    $u_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t_users WHERE account_id = %d", $a->id ) );
                    $t_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t_tasks WHERE account_id = %d", $a->id ) );
                ?>
                    <tr>
                        <td><?php echo esc_html( $a->id ); ?></td>
                        <td><strong><?php echo esc_html( $a->name ); ?></strong></td>
                        <td><?php echo $owner ? esc_html( $owner->user_email ) : '—'; ?></td>
                        <td><?php echo esc_html( $u_count ); ?></td>
                        <td><?php echo esc_html( $t_count ); ?></td>
                        <td>
                            <span style="padding:3px 8px; border-radius:3px; <?php echo $a->status === 'active' ? 'background:#d4edda; color:#155724;' : 'background:#f8d7da; color:#721c24;'; ?>">
                                <?php echo esc_html( ucfirst( $a->status ) ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $a->created_at ); ?></td>
                        <td>
                            <form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'Confirm action?', 'cmms-light' ); ?>');">
                                <?php wp_nonce_field( 'cmms_admin', 'cmms_admin_nonce' ); ?>
                                <input type="hidden" name="account_id" value="<?php echo esc_attr( $a->id ); ?>">
                                <?php if ( $a->status === 'active' ) : ?>
                                    <button class="button" name="cmms_admin_action" value="deactivate"><?php esc_html_e( 'Deactivate', 'cmms-light' ); ?></button>
                                <?php else : ?>
                                    <button class="button" name="cmms_admin_action" value="activate"><?php esc_html_e( 'Activate', 'cmms-light' ); ?></button>
                                <?php endif; ?>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'PERMANENTLY delete this account and all its data?', 'cmms-light' ); ?>');">
                                <?php wp_nonce_field( 'cmms_admin', 'cmms_admin_nonce' ); ?>
                                <input type="hidden" name="account_id" value="<?php echo esc_attr( $a->id ); ?>">
                                <button class="button button-link-delete" name="cmms_admin_action" value="delete"><?php esc_html_e( 'Delete', 'cmms-light' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* =================== USERS =================== */
    public function page_users() {
        global $wpdb;
        $t_users = CMMS_DB::table( 'users' );
        $t_acc   = CMMS_DB::table( 'accounts' );
        $rows = $wpdb->get_results(
            "SELECT u.*, a.name AS account_name FROM $t_users u
             LEFT JOIN $t_acc a ON a.id = u.account_id
             ORDER BY u.created_at DESC"
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'All CMMS Users', 'cmms-light' ); ?></h1>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php esc_html_e( 'Account', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'cmms-light' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No users yet.', 'cmms-light' ); ?></td></tr>
                <?php else : foreach ( $rows as $r ) :
                    $wp = $r->wp_user_id ? get_userdata( $r->wp_user_id ) : null;
                ?>
                    <tr>
                        <td><?php echo esc_html( $r->id ); ?></td>
                        <td><?php echo esc_html( $r->account_name ); ?> (#<?php echo esc_html( $r->account_id ); ?>)</td>
                        <td><?php echo esc_html( $r->display_name ); ?></td>
                        <td><?php echo $wp ? esc_html( $wp->user_email ) : '—'; ?></td>
                        <td><?php echo esc_html( CMMS_Auth::role_label( $r->role ) ); ?></td>
                        <td><?php echo esc_html( ucfirst( $r->status ) ); ?></td>
                        <td><?php echo esc_html( $r->created_at ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* =================== SETUP PAGES =================== */
    public function page_setup() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'CMMS Light - Setup', 'cmms-light' ); ?></h1>
            <p><?php esc_html_e( 'CMMS Light needs four published pages to work:', 'cmms-light' ); ?></p>
            <table class="widefat" style="max-width:720px;">
                <thead>
                    <tr><th><?php esc_html_e( 'Slug', 'cmms-light' ); ?></th><th><?php esc_html_e( 'Shortcode', 'cmms-light' ); ?></th><th><?php esc_html_e( 'Status', 'cmms-light' ); ?></th></tr>
                </thead>
                <tbody>
                <?php
                $needed = array(
                    'cmms-signup'    => '[cmms_signup]',
                    'cmms-login'     => '[cmms_login]',
                    'cmms-dashboard' => '[cmms_dashboard]',
                    'cmms-form'      => '[cmms_public_form]',
                );
                foreach ( $needed as $slug => $sc ) :
                    $page = get_page_by_path( $slug );
                ?>
                <tr>
                    <td><code><?php echo esc_html( $slug ); ?></code></td>
                    <td><code><?php echo esc_html( $sc ); ?></code></td>
                    <td>
                        <?php if ( $page ) : ?>
                            <a href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'cmms-light' ); ?></a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( get_edit_post_link( $page->ID ) ); ?>"><?php esc_html_e( 'Edit', 'cmms-light' ); ?></a>
                        <?php else : ?>
                            <span style="color:#a00;"><?php esc_html_e( 'Missing', 'cmms-light' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" style="margin-top:20px;">
                <?php wp_nonce_field( 'cmms_admin', 'cmms_admin_nonce' ); ?>
                <button class="button button-primary" name="cmms_admin_action" value="rebuild_pages"><?php esc_html_e( 'Recreate Missing Pages', 'cmms-light' ); ?></button>
            </form>

            <hr style="margin:30px 0;">

            <h2><?php esc_html_e( 'Demo Account', 'cmms-light' ); ?></h2>
            <p><?php esc_html_e( 'Generate a fully populated demo organization with sample users, assets, tasks, and a public report form. Use this to explore how CMMS Light works from each role\'s perspective.', 'cmms-light' ); ?></p>

            <?php
            $demo = get_transient( 'cmms_demo_result_' . get_current_user_id() );
            if ( $demo ) :
                delete_transient( 'cmms_demo_result_' . get_current_user_id() );
                if ( isset( $demo['error'] ) ) :
            ?>
                <div class="notice notice-error" style="max-width:720px;">
                    <p><strong><?php esc_html_e( 'Demo creation failed:', 'cmms-light' ); ?></strong> <?php echo esc_html( $demo['error'] ); ?></p>
                </div>
            <?php else : ?>
                <div class="notice notice-success" style="max-width:720px; padding: 12px;">
                    <h3 style="margin-top:0;">✅ <?php esc_html_e( 'Demo organization created successfully!', 'cmms-light' ); ?></h3>
                    <p><strong><?php esc_html_e( 'Organization', 'cmms-light' ); ?>:</strong> <?php echo esc_html( $demo['organization'] ); ?></p>
                    <p><strong><?php esc_html_e( 'Password for all demo users', 'cmms-light' ); ?>:</strong> <code style="font-size:14px;">demo1234</code></p>
                    <p><strong><?php esc_html_e( 'Login URL', 'cmms-light' ); ?>:</strong>
                        <a href="<?php echo esc_url( $demo['login_url'] ); ?>" target="_blank"><?php echo esc_html( $demo['login_url'] ); ?></a>
                    </p>
                    <p><strong><?php esc_html_e( 'Demo accounts', 'cmms-light' ); ?>:</strong></p>
                    <table class="widefat striped" style="max-width:640px;">
                        <thead><tr><th><?php esc_html_e( 'Role', 'cmms-light' ); ?></th><th><?php esc_html_e( 'Email / Username', 'cmms-light' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $demo['users'] as $u ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $u['role'] ); ?></strong></td>
                                <td><code><?php echo esc_html( $u['email'] ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ( ! empty( $demo['form_url'] ) ) : ?>
                    <p style="margin-top:14px;"><strong><?php esc_html_e( 'Public report form', 'cmms-light' ); ?>:</strong>
                        <a href="<?php echo esc_url( $demo['form_url'] ); ?>" target="_blank"><?php echo esc_html( $demo['form_url'] ); ?></a>
                    </p>
                    <?php endif; ?>
                    <p class="description" style="margin-top:14px;">
                        <?php esc_html_e( 'Tip: open the login URL in an incognito window to log in as a demo user without affecting your WordPress admin session.', 'cmms-light' ); ?>
                    </p>
                </div>
            <?php endif; endif; ?>

            <form method="post" style="margin-top:10px;">
                <?php wp_nonce_field( 'cmms_admin', 'cmms_admin_nonce' ); ?>
                <button class="button button-secondary" name="cmms_admin_action" value="create_demo" onclick="return confirm('<?php echo esc_js( __( 'This will create a new demo organization with 4 users, 4 assets, 6 tasks, and a public form. You can run this multiple times - each run creates a fresh demo. Continue?', 'cmms-light' ) ); ?>');">
                    <?php esc_html_e( '🎬 Create Demo Account', 'cmms-light' ); ?>
                </button>
            </form>
        </div>
        <?php
    }
}
