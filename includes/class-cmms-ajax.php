<?php
/**
 * CMMS AJAX / admin-post handlers.
 * Every cmms_* form action posted from the dashboard shortcode lands here.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMMS_AJAX {

    public function register() {
        $actions = array(
            'cmms_task_create',
            'cmms_task_update',
            'cmms_task_status',
            'cmms_task_attach',
            'cmms_task_comment',
            'cmms_asset_create',
            'cmms_asset_update',
            'cmms_asset_delete',
            'cmms_user_add',
            'cmms_user_update',
            'cmms_user_delete',
            'cmms_account_update',
            'cmms_categories_update',
            'cmms_category_add',
            'cmms_form_create',
            'cmms_form_update',
            'cmms_form_delete',
            'cmms_field_add',
            'cmms_field_update',
            'cmms_field_delete',
            'cmms_field_reorder',
            'cmms_notify_mark_read',
            'cmms_notify_mark_all_read',
            'cmms_notify_settings_save',
            'cmms_smtp_warning_dismiss',
            'cmms_push_subscribe',
            'cmms_push_unsubscribe',
            'cmms_push_test',
            'cmms_import_template',
            'cmms_import_upload',
            'cmms_import_errors_csv',
            'cmms_bulk_tasks_save',
            'cmms_search',
            'cmms_notify_debug',
            'cmms_help_get',
            'cmms_onboarding_step1',
        );
        foreach ( $actions as $a ) {
            add_action( 'admin_post_' . $a, array( $this, $a ) );
        }

        // Public auth handlers (signup, login) - must be available to logged-out users
        // via admin_post_nopriv. We use admin-post.php instead of having the shortcode
        // process POST inline because page caches (LiteSpeed, WP Rocket, Cloudflare)
        // sometimes interfere with POST requests to public pages.
        add_action( 'admin_post_nopriv_cmms_signup', array( $this, 'cmms_signup' ) );
        add_action( 'admin_post_cmms_signup',        array( $this, 'cmms_signup' ) );
        add_action( 'admin_post_nopriv_cmms_login',  array( $this, 'cmms_login' ) );
        add_action( 'admin_post_cmms_login',         array( $this, 'cmms_login' ) );

        // 1.14.32: onboarding signup endpoint also must work for
        // logged-out users (it IS the signup flow). Without nopriv,
        // WordPress returns 400 Bad Request when an anonymous browser
        // POSTs the form. The admin_post_ version above (registered
        // via the loop) covers the rare admin-creating-an-account case;
        // this nopriv handler covers the actual public onboarding path.
        add_action( 'admin_post_nopriv_cmms_onboarding_step1', array( $this, 'cmms_onboarding_step1' ) );

        // 1.14.36: iCredit payment endpoints. All three need nopriv
        // counterparts because:
        //   - payment_init: the user is logged in as the cmms_user we
        //     just created, but if there's a session race they might
        //     not be — accept either.
        //   - icredit_ipn: server-to-server webhook, no user session.
        //   - icredit_return: user comes back from external page; their
        //     session may have expired by then, especially on slow
        //     payment flows.
        add_action( 'admin_post_cmms_payment_init',          array( $this, 'cmms_payment_init' ) );
        add_action( 'admin_post_nopriv_cmms_payment_init',   array( $this, 'cmms_payment_init' ) );
        add_action( 'admin_post_cmms_icredit_ipn',           array( 'CMMS_Icredit', 'handle_ipn' ) );
        add_action( 'admin_post_nopriv_cmms_icredit_ipn',    array( 'CMMS_Icredit', 'handle_ipn' ) );
        add_action( 'admin_post_cmms_icredit_return',        array( 'CMMS_Icredit', 'handle_return' ) );
        add_action( 'admin_post_nopriv_cmms_icredit_return', array( 'CMMS_Icredit', 'handle_return' ) );

        // 1.14.45: Setup checklist actions (logged-in dashboard only).
        // No nopriv variants — guest users can't have a checklist.
        add_action( 'wp_ajax_cmms_checklist_mark',     array( $this, 'cmms_checklist_mark' ) );
        add_action( 'wp_ajax_cmms_checklist_dismiss', array( $this, 'cmms_checklist_dismiss' ) );

        // 1.14.56: Subscription cancellation (owner only, logged-in).
        add_action( 'wp_ajax_cmms_subscription_cancel', array( $this, 'cmms_subscription_cancel' ) );

        // 1.14.60: Plan change (upgrade/downgrade) — owner only, logged-in.
        add_action( 'wp_ajax_cmms_plan_change_preview',       array( $this, 'cmms_plan_change_preview' ) );
        add_action( 'wp_ajax_cmms_plan_change_apply',         array( $this, 'cmms_plan_change_apply' ) );
        add_action( 'wp_ajax_cmms_plan_change_cancel_pending', array( $this, 'cmms_plan_change_cancel_pending' ) );

        // 1.14.61: Forgot/Reset password — BOTH logged-in and nopriv
        // versions because users hitting these endpoints are by
        // definition not logged in (they forgot their password).
        add_action( 'wp_ajax_nopriv_cmms_forgot_password', array( $this, 'cmms_forgot_password' ) );
        add_action( 'wp_ajax_cmms_forgot_password',        array( $this, 'cmms_forgot_password' ) );
        add_action( 'wp_ajax_nopriv_cmms_reset_password',  array( $this, 'cmms_reset_password' ) );
        add_action( 'wp_ajax_cmms_reset_password',         array( $this, 'cmms_reset_password' ) );

        // Public asset record (QR scan target). Must support nopriv.
        add_action( 'admin_post_nopriv_cmms_public_asset_report', array( $this, 'cmms_public_asset_report' ) );
        add_action( 'admin_post_cmms_public_asset_report',        array( $this, 'cmms_public_asset_report' ) );
        add_action( 'admin_post_nopriv_cmms_public_asset_photo',  array( $this, 'cmms_public_asset_photo' ) );
        add_action( 'admin_post_cmms_public_asset_photo',         array( $this, 'cmms_public_asset_photo' ) );

        // Internal: custom field definitions management (settings page)
        add_action( 'admin_post_cmms_field_def_add',    array( $this, 'cmms_field_def_add' ) );
        add_action( 'admin_post_cmms_field_def_delete', array( $this, 'cmms_field_def_delete' ) );
    }

    /* ============ AUTH (public) ============ */
    public function cmms_signup() {
        if ( ! isset( $_POST['cmms_signup_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['cmms_signup_nonce'] ), 'cmms_signup' ) ) {
            wp_safe_redirect( add_query_arg( 'cmms_err', 'failed', home_url( '/cmms-signup/' ) ) );
            exit;
        }
        $name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password     = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
        $organization = isset( $_POST['organization'] ) ? sanitize_text_field( wp_unslash( $_POST['organization'] ) ) : '';
        $phone        = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

        if ( $name === '' || $email === '' || $password === '' || $organization === '' ) {
            wp_safe_redirect( add_query_arg( 'cmms_err', 'missing', home_url( '/cmms-signup/' ) ) );
            exit;
        }
        $result = CMMS_Accounts::signup( array(
            'name'         => $name,
            'email'        => $email,
            'password'     => $password,
            'organization' => $organization,
            'phone'        => $phone,
        ) );
        if ( is_wp_error( $result ) ) {
            $code = $result->get_error_code();
            $valid = array( 'invalid_email', 'weak_pass', 'exists' );
            $err = in_array( $code, $valid, true ) ? $code : 'failed';
            wp_safe_redirect( add_query_arg( 'cmms_err', $err, home_url( '/cmms-signup/' ) ) );
            exit;
        }
        wp_safe_redirect( home_url( '/cmms-dashboard/' ) );
        exit;
    }

    public function cmms_login() {
        if ( ! isset( $_POST['cmms_login_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['cmms_login_nonce'] ), 'cmms_login' ) ) {
            wp_safe_redirect( add_query_arg( 'cmms_err', 'invalid', home_url( '/cmms-login/' ) ) );
            exit;
        }
        $creds = array(
            'user_login'    => isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
            'user_password' => isset( $_POST['password'] ) ? (string) $_POST['password'] : '',
            'remember'      => true,
        );
        $user = wp_signon( $creds, is_ssl() );
        if ( is_wp_error( $user ) ) {
            wp_safe_redirect( add_query_arg( 'cmms_err', 'invalid', home_url( '/cmms-login/' ) ) );
            exit;
        }
        // Verify the user is either a global admin or has an active CMMS row.
        wp_set_current_user( $user->ID );
        if ( ! CMMS_Auth::is_global_admin() && ! CMMS_Auth::current_cmms_user() ) {
            wp_logout();
            wp_safe_redirect( add_query_arg( 'cmms_err', 'inactive', home_url( '/cmms-login/' ) ) );
            exit;
        }
        wp_safe_redirect( home_url( '/cmms-dashboard/' ) );
        exit;
    }

    /* ------------ helpers ------------ */
    private function require_user() {
        // current_cmms_user() now returns null for inactive users / accounts.
        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) {
            wp_safe_redirect( home_url( '/cmms-login/' ) );
            exit;
        }
        return $u;
    }
    private function check_nonce( $action ) {
        $field = $action . '_nonce';
        if ( ! isset( $_POST[ $field ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $field ] ), $action ) ) {
            wp_die( esc_html__( 'Security check failed.', 'cmms-light' ), 403 );
        }
    }
    private function back( $args = array() ) {
        $url = home_url( '/cmms-dashboard/' );
        if ( ! empty( $args ) ) {
            $url = add_query_arg( $args, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }

    /* ============ TASKS ============ */
    public function cmms_task_create() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_task_create' );
        if ( ! CMMS_Auth::can( 'create_task' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $data = array(
            'title'                => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'description'          => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'category_id'          => (int) ( $_POST['category_id'] ?? 0 ),
            'asset_id'             => (int) ( $_POST['asset_id'] ?? 0 ),
            'manager_id'           => (int) ( $_POST['manager_id'] ?? 0 ),
            'assigned_to'          => (int) ( $_POST['assigned_to'] ?? 0 ),
            'status'               => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'open' ) ),
            'priority'             => sanitize_text_field( wp_unslash( $_POST['priority'] ?? 'normal' ) ),
            'due_date'             => sanitize_text_field( wp_unslash( $_POST['due_date'] ?? '' ) ),
            'recurrence_type'      => sanitize_text_field( wp_unslash( $_POST['recurrence_type'] ?? 'one_time' ) ),
            'recurrence_interval'  => (int) ( $_POST['recurrence_interval'] ?? 0 ),
            'recurrence_until'     => sanitize_text_field( wp_unslash( $_POST['recurrence_until'] ?? '' ) ),
            'created_by'           => $u->id,
            'source'               => 'manual',
        );
        if ( empty( $data['title'] ) ) {
            $this->back( array( 'view' => 'task_new', 'cmms_err' => 'title' ) );
        }
        $task_id = CMMS_Tasks::create( $u->account_id, $data );
        if ( is_wp_error( $task_id ) ) {
            $msg = $task_id->get_error_message();
            $this->back( array( 'view' => 'task_new', 'cmms_err' => 'create', 'cmms_msg' => urlencode( $msg ) ) );
        }
        if ( ! $task_id ) {
            $this->back( array( 'view' => 'task_new', 'cmms_err' => 'create' ) );
        }
        if ( ! empty( $_FILES['attachments']['name'][0] ) ) {
            $this->handle_multi_upload( 'attachments', 'task', $task_id, $u );
        }
        $this->back( array( 'view' => 'task', 'id' => $task_id ) );
    }

    public function cmms_task_update() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_task_update' );
        $task_id = (int) ( $_POST['task_id'] ?? 0 );
        $task = CMMS_Tasks::get( $task_id );
        // Centralized check: not-found and tenant-mismatch both surface
        // as "Not found." to avoid leaking the existence of cross-tenant rows.
        if ( ! $task || (int) $task->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        if ( ! CMMS_Auth::can_edit_task( $u, $task ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $fields = array(
            'title'                => sanitize_text_field( wp_unslash( $_POST['title'] ?? $task->title ) ),
            'description'          => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? $task->description ) ),
            'category_id'          => (int) ( $_POST['category_id'] ?? $task->category_id ),
            'asset_id'             => (int) ( $_POST['asset_id'] ?? $task->asset_id ),
            'manager_id'           => (int) ( $_POST['manager_id'] ?? $task->manager_id ),
            'assigned_to'          => (int) ( $_POST['assigned_to'] ?? $task->assigned_to ),
            'status'               => sanitize_text_field( wp_unslash( $_POST['status'] ?? $task->status ) ),
            'priority'             => sanitize_text_field( wp_unslash( $_POST['priority'] ?? $task->priority ) ),
            'due_date'             => sanitize_text_field( wp_unslash( $_POST['due_date'] ?? $task->due_date ) ),
            'recurrence_type'      => sanitize_text_field( wp_unslash( $_POST['recurrence_type'] ?? $task->recurrence_type ) ),
            'recurrence_interval'  => (int) ( $_POST['recurrence_interval'] ?? $task->recurrence_interval ),
            'recurrence_until'     => sanitize_text_field( wp_unslash( $_POST['recurrence_until'] ?? $task->recurrence_until ?? '' ) ),
        );
        CMMS_Tasks::update( $task_id, $fields, $u->id );
        $this->back( array( 'view' => 'task', 'id' => $task_id ) );
    }

    public function cmms_task_status() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_task_status' );
        $task_id = (int) ( $_POST['task_id'] ?? 0 );
        $status  = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
        $task = CMMS_Tasks::get( $task_id );
        if ( ! $task || (int) $task->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        // Status is a participation action: assignees in the field, the
        // creator, owners, and managers can all push status updates.
        // Pure viewers cannot.
        if ( ! CMMS_Auth::can_participate_task( $u, $task ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        CMMS_Tasks::update( $task_id, array( 'status' => $status ), $u->id );
        $this->back( array( 'view' => 'task', 'id' => $task_id ) );
    }

    public function cmms_task_attach() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_task_attach' );
        $task_id = (int) ( $_POST['task_id'] ?? 0 );
        $task = CMMS_Tasks::get( $task_id );
        if ( ! $task || (int) $task->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        // Attaching files is participation: technicians in the field need
        // to upload photos/docs without full edit rights. Centralized so
        // the rule stays consistent with comments and status updates.
        if ( ! CMMS_Auth::can_participate_task( $u, $task ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        if ( ! empty( $_FILES['attachments']['name'][0] ) ) {
            $this->handle_multi_upload( 'attachments', 'task', $task_id, $u );
        }
        $this->back( array( 'view' => 'task', 'id' => $task_id ) );
    }

    public function cmms_task_comment() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_task_comment' );
        $task_id = (int) ( $_POST['task_id'] ?? 0 );
        $note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
        $task = CMMS_Tasks::get( $task_id );
        if ( ! $task || (int) $task->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        // Comments are participation, not viewing — a viewer who happens
        // to see a task in admin views shouldn't be able to push comments
        // into the audit log. Same rule as attachments and status.
        if ( ! CMMS_Auth::can_participate_task( $u, $task ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        if ( $note !== '' ) {
            CMMS_Tasks::log( $task_id, $u->account_id, $u->id, 'comment', $note );
            do_action( 'cmms_task_commented', (int) $task_id, $note, (int) $u->id );
        }
        $this->back( array( 'view' => 'task', 'id' => $task_id ) );
    }

    /* ============ ASSETS ============ */
    public function cmms_asset_create() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_asset_create' );
        if ( ! CMMS_Auth::can( 'manage_assets' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $data = array(
            'name'              => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
            'asset_type'        => sanitize_text_field( wp_unslash( $_POST['asset_type'] ?? 'machine' ) ),
            'location'          => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
            'description'       => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'custom_fields'     => $this->build_custom_fields_payload(),
            'public_qr_enabled' => ! empty( $_POST['public_qr_enabled'] ),
            'public_actions'    => isset( $_POST['public_actions'] ) && is_array( $_POST['public_actions'] )
                                       ? array_map( 'sanitize_key', wp_unslash( $_POST['public_actions'] ) )
                                       : array(),
            'created_by'        => $u->id,
        );
        if ( empty( $data['name'] ) ) {
            $this->back( array( 'view' => 'asset_new', 'cmms_err' => 'name' ) );
        }
        $asset_id = CMMS_Assets::create( $u->account_id, $data );
        $this->back( array( 'view' => 'asset', 'id' => $asset_id ) );
    }

    public function cmms_asset_update() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_asset_update' );
        if ( ! CMMS_Auth::can( 'manage_assets' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $asset_id = (int) ( $_POST['asset_id'] ?? 0 );
        $asset = CMMS_Assets::get( $asset_id );
        if ( ! $asset || (int) $asset->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        $fields = array(
            'name'              => sanitize_text_field( wp_unslash( $_POST['name'] ?? $asset->name ) ),
            'asset_type'        => sanitize_text_field( wp_unslash( $_POST['asset_type'] ?? $asset->asset_type ) ),
            'location'          => sanitize_text_field( wp_unslash( $_POST['location'] ?? $asset->location ) ),
            'description'       => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? $asset->description ) ),
            'custom_fields'     => $this->build_custom_fields_payload(),
            'public_qr_enabled' => ! empty( $_POST['public_qr_enabled'] ),
            'public_actions'    => isset( $_POST['public_actions'] ) && is_array( $_POST['public_actions'] )
                                       ? array_map( 'sanitize_key', wp_unslash( $_POST['public_actions'] ) )
                                       : array(),
        );
        CMMS_Assets::update( $asset_id, $fields );
        $this->back( array( 'view' => 'asset', 'id' => $asset_id ) );
    }

    /**
     * Build the structured custom_fields array from posted form data.
     * Form posts:
     *   cf[<key>]                = value
     *   cf_meta[<key>][label]    = label
     *   cf_meta[<key>][type]     = type (text/number/date/...)
     */
    private function build_custom_fields_payload() {
        $cf      = isset( $_POST['cf'] )      && is_array( $_POST['cf'] )      ? wp_unslash( $_POST['cf'] )      : array();
        $cf_meta = isset( $_POST['cf_meta'] ) && is_array( $_POST['cf_meta'] ) ? wp_unslash( $_POST['cf_meta'] ) : array();
        $items = array();
        foreach ( $cf as $key => $value ) {
            $key = sanitize_key( $key );
            if ( $key === '' ) continue;
            $label = isset( $cf_meta[ $key ]['label'] ) ? sanitize_text_field( $cf_meta[ $key ]['label'] ) : '';
            $type  = isset( $cf_meta[ $key ]['type'] )  ? sanitize_key( $cf_meta[ $key ]['type'] )  : 'text';
            $items[] = array(
                'field_key' => $key,
                'label'     => $label,
                'type'      => $type,
                'value'     => $value, // sanitization happens in CMMS_Assets::sanitize_custom_fields per type
            );
        }
        return $items;
    }

    public function cmms_asset_delete() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_asset_delete' );
        if ( ! CMMS_Auth::can( 'manage_assets' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $asset_id = (int) ( $_POST['asset_id'] ?? 0 );
        $asset = CMMS_Assets::get( $asset_id );
        if ( ! $asset || (int) $asset->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        CMMS_Assets::delete( $asset_id );
        $this->back( array( 'view' => 'assets' ) );
    }

    /* ============ USERS ============ */
    public function cmms_user_add() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_user_add' );
        if ( ! CMMS_Auth::can( 'manage_users' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $name     = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
        $phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $role     = sanitize_text_field( wp_unslash( $_POST['role'] ?? 'technician' ) );
        $password = (string) ( $_POST['password'] ?? '' );
        if ( $password === '' ) { $password = wp_generate_password( 12, false ); }

        if ( ! is_email( $email ) ) {
            $this->back( array( 'view' => 'users', 'cmms_err' => 'email' ) );
        }
        if ( $name === '' ) { $name = current( explode( '@', $email ) ); }
        $valid_roles = array( CMMS_Auth::ROLE_MANAGER, CMMS_Auth::ROLE_TECHNICIAN, CMMS_Auth::ROLE_REPORTER );
        if ( ! in_array( $role, $valid_roles, true ) ) {
            $role = CMMS_Auth::ROLE_TECHNICIAN;
        }

        // 1.14.50: enforce the package's user limit. The account row's
        // max_users column was populated at signup from the package
        // catalog. NULL = unlimited (Enterprise). Otherwise, count the
        // active+invited users currently on the account and refuse if
        // adding one more would exceed the limit.
        if ( ! self::within_user_limit( $u->account_id ) ) {
            $this->back( array( 'view' => 'users', 'cmms_err' => 'user_limit' ) );
        }

        $res = CMMS_Users::add_user_to_account( $u->account_id, $email, $name, $password, $role, $phone );
        if ( is_wp_error( $res ) ) {
            $this->back( array( 'view' => 'users', 'cmms_err' => 'add' ) );
        }
        $this->back( array( 'view' => 'users', 'cmms_msg' => 'added' ) );
    }

    public function cmms_user_update() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_user_update' );
        if ( ! CMMS_Auth::can( 'manage_users' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        $target = CMMS_Users::get( $user_id );
        if ( ! $target || (int) $target->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }

        $new_role   = sanitize_text_field( wp_unslash( $_POST['role'] ?? $target->role ) );
        $new_status = sanitize_text_field( wp_unslash( $_POST['status'] ?? $target->status ) );

        // Privilege guard: only an existing owner (or global admin) can promote
        // or keep someone as owner. Prevents managers from escalating themselves
        // or others to owner.
        if ( $new_role === CMMS_Auth::ROLE_OWNER
             && $u->role !== CMMS_Auth::ROLE_OWNER
             && ! CMMS_Auth::is_global_admin() ) {
            wp_die( esc_html__( 'Only an owner can assign the owner role.', 'cmms-light' ) );
        }

        // Last-owner protection: if target is the only active owner, block any
        // change that would remove their owner+active state.
        if ( $target->role === CMMS_Auth::ROLE_OWNER && $target->status === 'active' ) {
            $owner_count = CMMS_Users::count_active_owners( $u->account_id );
            $would_lose_owner = ( $new_role !== CMMS_Auth::ROLE_OWNER ) || ( $new_status !== 'active' );
            if ( $owner_count <= 1 && $would_lose_owner ) {
                wp_die( esc_html__( 'Cannot demote or deactivate the last active owner.', 'cmms-light' ) );
            }
        }

        // Self-protection: a manager editing themselves cannot raise their own role.
        if ( (int) $target->id === (int) $u->id && $new_role !== $target->role ) {
            wp_die( esc_html__( 'You cannot change your own role.', 'cmms-light' ) );
        }

        $fields = array(
            'display_name' => sanitize_text_field( wp_unslash( $_POST['display_name'] ?? $target->display_name ) ),
            'phone'        => sanitize_text_field( wp_unslash( $_POST['phone'] ?? $target->phone ) ),
            'role'         => $new_role,
            'status'       => $new_status,
        );
        // Email is optional - only update if provided and non-empty.
        if ( isset( $_POST['email'] ) && trim( wp_unslash( $_POST['email'] ) ) !== '' ) {
            $fields['email'] = sanitize_email( wp_unslash( $_POST['email'] ) );
        }
        $result = CMMS_Users::update( $user_id, $fields );
        if ( is_wp_error( $result ) ) {
            $this->back( array( 'view' => 'users', 'cmms_err' => $result->get_error_code(), 'cmms_msg' => urlencode( $result->get_error_message() ) ) );
        }
        $this->back( array( 'view' => 'users', 'cmms_msg' => 'updated' ) );
    }

    public function cmms_user_delete() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_user_delete' );
        if ( ! CMMS_Auth::can( 'manage_users' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        $target = CMMS_Users::get( $user_id );
        if ( ! $target || (int) $target->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        if ( (int) $target->id === (int) $u->id ) {
            wp_die( esc_html__( 'Cannot delete yourself.', 'cmms-light' ) );
        }
        // Last-owner protection.
        if ( $target->role === CMMS_Auth::ROLE_OWNER && $target->status === 'active' ) {
            if ( CMMS_Users::count_active_owners( $u->account_id ) <= 1 ) {
                wp_die( esc_html__( 'Cannot delete the last active owner.', 'cmms-light' ) );
            }
        }
        // A manager cannot delete an owner.
        if ( $target->role === CMMS_Auth::ROLE_OWNER
             && $u->role !== CMMS_Auth::ROLE_OWNER
             && ! CMMS_Auth::is_global_admin() ) {
            wp_die( esc_html__( 'Only an owner can delete an owner.', 'cmms-light' ) );
        }
        CMMS_Users::delete( $user_id );
        $this->back( array( 'view' => 'users', 'cmms_msg' => 'deleted' ) );
    }

    /* ============ ACCOUNT / SETTINGS ============ */
    public function cmms_account_update() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_account_update' );
        if ( ! CMMS_Auth::can( 'manage_account' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( $name !== '' ) {
            CMMS_Accounts::update_name( $u->account_id, $name );
        }
        $this->back( array( 'view' => 'settings', 'cmms_msg' => 'saved' ) );
    }

    public function cmms_categories_update() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_categories_update' );
        if ( ! CMMS_Auth::can( 'manage_categories' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        if ( ! empty( $_POST['delete_id'] ) ) {
            $cid = (int) $_POST['delete_id'];
            $cat = CMMS_Categories::get( $cid );
            if ( $cat && (int) $cat->account_id === (int) $u->account_id ) {
                CMMS_Categories::delete( $cid );
            }
            $this->back( array( 'view' => 'settings', 'cmms_msg' => 'cat_deleted' ) );
        }
        $enabled = isset( $_POST['enabled'] ) && is_array( $_POST['enabled'] ) ? array_map( 'intval', $_POST['enabled'] ) : array();
        $all_ids = isset( $_POST['cat_ids'] ) && is_array( $_POST['cat_ids'] ) ? array_map( 'intval', $_POST['cat_ids'] ) : array();
        foreach ( $all_ids as $cid ) {
            $cat = CMMS_Categories::get( $cid );
            if ( $cat && (int) $cat->account_id === (int) $u->account_id ) {
                CMMS_Categories::set_enabled( $cid, in_array( $cid, $enabled, true ) ? 1 : 0 );
            }
        }
        $this->back( array( 'view' => 'settings', 'cmms_msg' => 'saved' ) );
    }

    public function cmms_category_add() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_category_add' );
        if ( ! CMMS_Auth::can( 'manage_categories' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( $name !== '' ) {
            CMMS_Categories::create( $u->account_id, $name );
        }
        $this->back( array( 'view' => 'settings', 'cmms_msg' => 'cat_added' ) );
    }

    /* ============ FORMS ============ */
    public function cmms_form_create() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_form_create' );
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $data = array(
            'name'                => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
            'description'         => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'manager_id'          => (int) ( $_POST['manager_id'] ?? 0 ),
            'default_category_id' => (int) ( $_POST['default_category_id'] ?? 0 ),
            'default_priority'    => sanitize_text_field( wp_unslash( $_POST['default_priority'] ?? 'normal' ) ),
            'default_status'      => sanitize_text_field( wp_unslash( $_POST['default_status'] ?? 'open' ) ),
            'created_by'          => $u->id,
        );
        if ( empty( $data['name'] ) ) {
            $this->back( array( 'view' => 'forms', 'cmms_err' => 'name' ) );
        }
        $form_id = CMMS_Forms::create_form( $u->account_id, $data );
        if ( is_wp_error( $form_id ) || ! $form_id ) {
            $this->back( array( 'view' => 'forms', 'cmms_err' => 'create' ) );
        }
        $this->back( array( 'view' => 'form_edit', 'id' => $form_id ) );
    }

    public function cmms_form_update() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_form_update' );
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $form_id = (int) ( $_POST['form_id'] ?? 0 );
        $form = CMMS_Forms::get( $form_id );
        if ( ! $form || (int) $form->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        $fields = array(
            'name'                => sanitize_text_field( wp_unslash( $_POST['name'] ?? $form->name ) ),
            'description'         => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? $form->description ) ),
            'manager_id'          => (int) ( $_POST['manager_id'] ?? $form->manager_id ),
            'default_category_id' => (int) ( $_POST['default_category_id'] ?? $form->default_category_id ),
            'default_priority'    => sanitize_text_field( wp_unslash( $_POST['default_priority'] ?? $form->default_priority ) ),
            'default_status'      => sanitize_text_field( wp_unslash( $_POST['default_status'] ?? $form->default_status ) ),
            'enabled'             => isset( $_POST['enabled'] ) ? 1 : 0,
        );
        CMMS_Forms::update_form( $form_id, $fields );
        $this->back( array( 'view' => 'form_edit', 'id' => $form_id, 'cmms_msg' => 'saved' ) );
    }

    public function cmms_form_delete() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_form_delete' );
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $form_id = (int) ( $_POST['form_id'] ?? 0 );
        $form = CMMS_Forms::get( $form_id );
        if ( ! $form || (int) $form->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        CMMS_Forms::delete_form( $form_id );
        $this->back( array( 'view' => 'forms' ) );
    }

    public function cmms_field_add() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_field_add' );
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $form_id = (int) ( $_POST['form_id'] ?? 0 );
        $form = CMMS_Forms::get( $form_id );
        if ( ! $form || (int) $form->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        $data = array(
            'label'      => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
            'field_type' => sanitize_text_field( wp_unslash( $_POST['field_type'] ?? 'text' ) ),
            'options'    => sanitize_textarea_field( wp_unslash( $_POST['options'] ?? '' ) ),
            'required'   => isset( $_POST['required'] ) ? 1 : 0,
            'sort_order' => (int) ( $_POST['sort_order'] ?? 0 ),
        );
        if ( $data['label'] !== '' ) {
            CMMS_Forms::add_field( $form_id, $data );
        }
        $this->back( array( 'view' => 'form_edit', 'id' => $form_id ) );
    }

    public function cmms_field_update() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_field_update' );
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $field_id = (int) ( $_POST['field_id'] ?? 0 );
        $field = CMMS_Forms::get_field( $field_id );
        if ( ! $field ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        $form = CMMS_Forms::get( $field->form_id );
        if ( ! $form || (int) $form->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        $fields = array(
            'label'      => sanitize_text_field( wp_unslash( $_POST['label'] ?? $field->label ) ),
            'field_type' => sanitize_text_field( wp_unslash( $_POST['field_type'] ?? $field->field_type ) ),
            'options'    => sanitize_textarea_field( wp_unslash( $_POST['options'] ?? $field->options ) ),
            'required'   => isset( $_POST['required'] ) ? 1 : 0,
            'sort_order' => (int) ( $_POST['sort_order'] ?? $field->sort_order ),
        );
        CMMS_Forms::update_field( $field_id, $fields );
        $this->back( array( 'view' => 'form_edit', 'id' => $form->id ) );
    }

    public function cmms_field_delete() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_field_delete' );
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $field_id = (int) ( $_POST['field_id'] ?? 0 );
        $field = CMMS_Forms::get_field( $field_id );
        if ( ! $field ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        $form = CMMS_Forms::get( $field->form_id );
        if ( ! $form || (int) $form->account_id !== (int) $u->account_id ) {
            wp_die( esc_html__( 'Not found.', 'cmms-light' ) );
        }
        CMMS_Forms::delete_field( $field_id );
        $this->back( array( 'view' => 'form_edit', 'id' => $form->id ) );
    }

    /**
     * Persist a new ordering of fields after drag-and-drop. Receives JSON:
     *   { nonce: "...", form_id: 12, order: [3, 1, 5, 2, 4] }
     * The order array is the field IDs in their new top-to-bottom order.
     * We update each field's sort_order to match its index in the array.
     */
    public function cmms_field_reorder() {
        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) wp_send_json_error( array( 'error' => 'auth_required' ), 401 );
        if ( ! CMMS_Auth::can( 'manage_forms' ) ) {
            wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
        }

        $raw = file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( array( 'error' => 'invalid_json' ), 400 );
        }
        if ( empty( $data['nonce'] ) || ! wp_verify_nonce( $data['nonce'], 'cmms_field_reorder' ) ) {
            wp_send_json_error( array( 'error' => 'bad_nonce' ), 403 );
        }

        $form_id = isset( $data['form_id'] ) ? (int) $data['form_id'] : 0;
        $form = CMMS_Forms::get( $form_id );
        if ( ! $form || (int) $form->account_id !== (int) $u->account_id ) {
            wp_send_json_error( array( 'error' => 'not_found' ), 404 );
        }

        $order = isset( $data['order'] ) && is_array( $data['order'] ) ? $data['order'] : array();
        // Reasonable cap so a malformed request can't kick off thousands of
        // UPDATE queries.
        if ( count( $order ) > 200 ) {
            wp_send_json_error( array( 'error' => 'too_many' ), 400 );
        }

        global $wpdb;
        $fields_t = CMMS_DB::table( 'form_fields' );
        $position = 0;
        $updated = 0;
        foreach ( $order as $field_id ) {
            $field_id = (int) $field_id;
            if ( $field_id <= 0 ) continue;

            // Tenant boundary check on every row — never trust the client.
            // We only update fields that actually belong to this form,
            // ignoring any IDs that don't.
            $belongs = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $fields_t WHERE id = %d AND form_id = %d",
                $field_id, $form_id
            ) );
            if ( ! $belongs ) continue;

            $wpdb->update(
                $fields_t,
                array( 'sort_order' => $position ),
                array( 'id' => $field_id ),
                array( '%d' ),
                array( '%d' )
            );
            $position++;
            $updated++;
        }

        wp_send_json_success( array( 'updated' => $updated ) );
    }

    /* ============ helpers ============ */
    private function handle_multi_upload( $input, $object_type, $object_id, $cmms_user ) {
        if ( empty( $_FILES[ $input ]['name'] ) ) return;
        $count = is_array( $_FILES[ $input ]['name'] ) ? count( $_FILES[ $input ]['name'] ) : 1;
        for ( $i = 0; $i < $count; $i++ ) {
            if ( is_array( $_FILES[ $input ]['name'] ) ) {
                if ( empty( $_FILES[ $input ]['name'][ $i ] ) ) continue;
                $file = array(
                    'name'     => $_FILES[ $input ]['name'][ $i ],
                    'type'     => $_FILES[ $input ]['type'][ $i ],
                    'tmp_name' => $_FILES[ $input ]['tmp_name'][ $i ],
                    'error'    => $_FILES[ $input ]['error'][ $i ],
                    'size'     => $_FILES[ $input ]['size'][ $i ],
                );
            } else {
                $file = $_FILES[ $input ];
            }
            CMMS_Attachments::handle_upload( $cmms_user->account_id, $object_type, $object_id, $file, $cmms_user->id );
        }
    }

    /* ============ NOTIFICATIONS ============ */

    public function cmms_notify_mark_read() {
        $u = $this->require_user();
        // The bell-dropdown items are GET links with ?_wpnonce=...
        // check_admin_referer accepts the nonce from either GET or POST.
        check_admin_referer( 'cmms_nonce_cmms_notify_mark_read' );
        $id = (int) ( $_GET['id'] ?? $_POST['id'] ?? 0 );
        if ( $id ) {
            CMMS_Notifications::mark_one_read( $u->id, $id );
        }
        $redirect = isset( $_GET['redirect'] )
            ? esc_url_raw( wp_unslash( $_GET['redirect'] ) )
            : ( isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '' );
        if ( $redirect ) {
            $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
            $redirect_host = wp_parse_url( $redirect, PHP_URL_HOST );
            if ( $home_host && $redirect_host === $home_host ) {
                wp_safe_redirect( $redirect );
                exit;
            }
        }
        $this->back();
    }

    public function cmms_notify_mark_all_read() {
        $u = $this->require_user();
        check_admin_referer( 'cmms_nonce_cmms_notify_mark_all_read' );
        CMMS_Notifications::mark_all_read( $u->id );
        $this->back();
    }

    public function cmms_notify_settings_save() {
        $u = $this->require_user();
        $this->check_nonce( 'cmms_notify_settings_save' );
        if ( ! CMMS_Auth::can( 'manage_account' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        $settings = array(
            'remind_before_enabled' => ! empty( $_POST['remind_before_enabled'] ) ? 1 : 0,
            'remind_before_hours'   => isset( $_POST['remind_before_hours'] ) ? (int) $_POST['remind_before_hours'] : 24,
            'remind_after_enabled'  => ! empty( $_POST['remind_after_enabled'] ) ? 1 : 0,
            'remind_after_hours'    => isset( $_POST['remind_after_hours'] ) ? (int) $_POST['remind_after_hours'] : 24,
            'remind_after_repeat'   => isset( $_POST['remind_after_repeat'] ) ? (int) $_POST['remind_after_repeat'] : 0,
            'remind_after_max'      => isset( $_POST['remind_after_max'] ) ? (int) $_POST['remind_after_max'] : 3,
        );
        CMMS_Notifications::save_account_settings( $u->account_id, $settings );
        $this->back( array( 'view' => 'settings', 'tab' => 'notify', 'cmms_msg' => 'saved' ) );
    }

    public function cmms_smtp_warning_dismiss() {
        // Only WP admin can dismiss the global warning.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'cmms-light' ) );
        }
        check_admin_referer( 'cmms_smtp_warning_dismiss' );
        update_option( 'cmms_smtp_warning_dismissed', 1, false );
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }

    /* ============ PUSH NOTIFICATIONS ============ */

    /**
     * Save a Web Push subscription. Called via fetch() from the dashboard
     * after the browser grants permission. Payload (JSON):
     *   { endpoint, keys: { p256dh, auth }, nonce }
     */
    public function cmms_push_subscribe() {
        $u = $this->require_user();
        $raw = file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( array( 'error' => 'invalid_json' ), 400 );
        }
        if ( empty( $data['nonce'] ) || ! wp_verify_nonce( $data['nonce'], 'cmms_push_subscribe' ) ) {
            wp_send_json_error( array( 'error' => 'bad_nonce' ), 403 );
        }
        $endpoint = isset( $data['endpoint'] ) ? esc_url_raw( $data['endpoint'] ) : '';
        $p256dh   = isset( $data['keys']['p256dh'] ) ? sanitize_text_field( $data['keys']['p256dh'] ) : '';
        $auth     = isset( $data['keys']['auth'] )   ? sanitize_text_field( $data['keys']['auth'] )   : '';
        if ( ! $endpoint || ! $p256dh || ! $auth ) {
            wp_send_json_error( array( 'error' => 'missing_fields' ), 400 );
        }
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
        $id = CMMS_Push::save_subscription( $u->id, $u->account_id, $endpoint, $p256dh, $auth, $ua );
        wp_send_json_success( array( 'id' => $id ) );
    }

    public function cmms_push_unsubscribe() {
        $this->require_user();
        $raw = file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( array( 'error' => 'invalid_json' ), 400 );
        }
        if ( empty( $data['nonce'] ) || ! wp_verify_nonce( $data['nonce'], 'cmms_push_unsubscribe' ) ) {
            wp_send_json_error( array( 'error' => 'bad_nonce' ), 403 );
        }
        $endpoint = isset( $data['endpoint'] ) ? esc_url_raw( $data['endpoint'] ) : '';
        if ( $endpoint ) {
            CMMS_Push::delete_subscription_by_endpoint( $endpoint );
        }
        wp_send_json_success();
    }

    public function cmms_push_test() {
        $u = $this->require_user();
        $raw = file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( array( 'error' => 'invalid_json' ), 400 );
        }
        if ( empty( $data['nonce'] ) || ! wp_verify_nonce( $data['nonce'], 'cmms_push_subscribe' ) ) {
            wp_send_json_error( array( 'error' => 'bad_nonce' ), 403 );
        }
        $count = CMMS_Push::send_to_user(
            (int) $u->id,
            __( 'Test notification', 'cmms-light' ),
            __( 'If you can read this, push notifications are working on this device.', 'cmms-light' ),
            home_url( '/cmms-dashboard/' )
        );
        wp_send_json_success( array( 'sent' => $count ) );
    }

    /* ============ IMPORT (Excel) ============ */

    /**
     * Stream the .xlsx template to the user. No DB writes — safe for any
     * logged-in CMMS user.
     */
    public function cmms_import_template() {
        $u = $this->require_user();
        if ( ! in_array( $u->role, array( 'owner', 'manager' ), true ) ) {
            wp_die( esc_html__( 'Manager role required', 'cmms-light' ), 403 );
        }
        $tmp = CMMS_Import::generate_template();
        if ( ! $tmp || ! file_exists( $tmp ) ) {
            wp_die( esc_html__( 'Could not generate template', 'cmms-light' ), 500 );
        }
        $size = filesize( $tmp );

        // Clean output buffer so the binary file isn't corrupted by stray output.
        while ( ob_get_level() > 0 ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="cmms-import-template.xlsx"' );
        header( 'Content-Length: ' . $size );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $tmp );
        @unlink( $tmp );
        exit;
    }

    /**
     * Receive the uploaded .xlsx (Assets only), validate, run import.
     */
    public function cmms_import_upload() {
        $u = $this->require_user();
        if ( ! in_array( $u->role, array( 'owner', 'manager' ), true ) ) {
            wp_die( esc_html__( 'Manager role required', 'cmms-light' ), 403 );
        }
        if ( ! isset( $_POST['cmms_import_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['cmms_import_nonce'] ), 'cmms_import' ) ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'import', 'cmms_err' => 'failed' ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }
        if ( empty( $_FILES['cmms_xlsx'] ) || ! is_array( $_FILES['cmms_xlsx'] ) ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'import', 'cmms_err' => 'no_file' ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }
        $file = $_FILES['cmms_xlsx'];
        if ( ! empty( $file['error'] ) ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'import', 'cmms_err' => 'upload' ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }
        if ( $file['size'] > CMMS_Import::MAX_FILE_BYTES ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'import', 'cmms_err' => 'too_large' ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'xlsx' ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'import', 'cmms_err' => 'bad_ext' ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }
        if ( ! CMMS_Import::is_real_xlsx( $file['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'import', 'cmms_err' => 'corrupt' ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }

        $upload_dir = wp_upload_dir();
        $cmms_dir = trailingslashit( $upload_dir['basedir'] ) . 'cmms-imports';
        if ( ! file_exists( $cmms_dir ) ) wp_mkdir_p( $cmms_dir );
        $sandboxed = $cmms_dir . '/import-' . wp_generate_password( 12, false ) . '.xlsx';
        if ( ! @move_uploaded_file( $file['tmp_name'], $sandboxed ) ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'import', 'cmms_err' => 'sandbox' ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }

        $summary = CMMS_Import::import_file( $sandboxed, (int) $u->account_id, $u );
        @unlink( $sandboxed );

        set_transient( 'cmms_import_result_' . (int) $u->id, $summary, HOUR_IN_SECONDS );

        wp_safe_redirect( add_query_arg( array( 'view' => 'import', 'cmms_done' => 1 ), home_url( '/cmms-dashboard/' ) ) );
        exit;
    }

    /**
     * Download the failed-rows CSV from the most recent import.
     */
    public function cmms_import_errors_csv() {
        $u = $this->require_user();
        if ( ! in_array( $u->role, array( 'owner', 'manager' ), true ) ) {
            wp_die( esc_html__( 'Manager role required', 'cmms-light' ), 403 );
        }
        $summary = get_transient( 'cmms_import_result_' . (int) $u->id );
        if ( ! $summary || empty( $summary['skipped_rows'] ) ) {
            wp_die( esc_html__( 'No import errors to download.', 'cmms-light' ), 404 );
        }
        $csv = CMMS_Import::build_error_csv( $summary['skipped_rows'] );

        while ( ob_get_level() > 0 ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="cmms-import-errors.csv"' );
        header( 'Content-Length: ' . strlen( $csv ) );
        echo $csv;
        exit;
    }

    /**
     * Bulk-create tasks from the in-app grid.
     *
     * Expects JSON body:
     * {
     *   nonce: "...",
     *   rows: [
     *     { title, description, asset_id, priority, assigned_to, due_date },
     *     ...
     *   ]
     * }
     *
     * Returns:
     * {
     *   saved: 12,
     *   failed: [ { row_index: 3, reason: "title required" }, ... ]
     * }
     */
    public function cmms_bulk_tasks_save() {
        $u = $this->require_user();
        if ( ! in_array( $u->role, array( 'owner', 'manager' ), true ) ) {
            wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
        }
        $raw = file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( array( 'error' => 'invalid_json' ), 400 );
        }
        if ( empty( $data['nonce'] ) || ! wp_verify_nonce( $data['nonce'], 'cmms_bulk_tasks' ) ) {
            wp_send_json_error( array( 'error' => 'bad_nonce' ), 403 );
        }

        $rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
        if ( count( $rows ) > 1000 ) {
            wp_send_json_error( array( 'error' => 'too_many_rows', 'limit' => 1000 ), 400 );
        }

        $saved  = 0;
        $failed = array();
        $allowed_priorities = array( 'low', 'normal', 'high', 'urgent' );

        foreach ( $rows as $i => $row ) {
            // Skip blank rows silently.
            $title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
            $other_fields = array( 'description', 'asset_id', 'priority', 'assigned_to', 'due_date' );
            $is_empty = ( $title === '' );
            if ( $is_empty ) {
                foreach ( $other_fields as $f ) {
                    if ( ! empty( $row[ $f ] ) && trim( (string) $row[ $f ] ) !== '' ) {
                        $is_empty = false;
                        break;
                    }
                }
            }
            if ( $is_empty ) continue;

            // Title required.
            if ( $title === '' ) {
                $failed[] = array( 'row_index' => $i, 'reason' => 'title required' );
                continue;
            }

            $description = isset( $row['description'] ) ? (string) $row['description'] : '';
            $priority    = isset( $row['priority'] ) ? strtolower( (string) $row['priority'] ) : 'normal';
            if ( ! in_array( $priority, $allowed_priorities, true ) ) {
                $priority = 'normal';
            }
            $asset_id    = isset( $row['asset_id'] )    && $row['asset_id']    ? (int) $row['asset_id']    : 0;
            $assigned_to = isset( $row['assigned_to'] ) && $row['assigned_to'] ? (int) $row['assigned_to'] : 0;
            $due_date    = isset( $row['due_date'] )    ? trim( (string) $row['due_date'] ) : '';

            $task_data = array(
                'title'       => $title,
                'description' => $description,
                'priority'    => $priority,
                'status'      => 'open',
                'created_by'  => (int) $u->id,
            );
            if ( $asset_id )    $task_data['asset_id']    = $asset_id;
            if ( $assigned_to ) $task_data['assigned_to'] = $assigned_to;
            if ( $due_date )    $task_data['due_date']    = $due_date;

            $new_id = CMMS_Tasks::create( (int) $u->account_id, $task_data );
            if ( is_wp_error( $new_id ) || ! $new_id ) {
                $msg = is_wp_error( $new_id ) ? $new_id->get_error_message() : 'database error';
                $failed[] = array( 'row_index' => $i, 'reason' => $msg );
                continue;
            }
            $saved++;
        }

        wp_send_json_success( array(
            'saved'  => $saved,
            'failed' => $failed,
        ) );
    }

    /* ============ PUBLIC ASSET QR ACTIONS ============ */

    /**
     * Common pre-flight for the two public asset endpoints. Validates the
     * asset token, checks that QR is enabled and the requested action is
     * allowed. Bails (with a redirect to the public landing) on any failure.
     *
     * Returns the asset row on success.
     */
    private function public_asset_preflight( $required_action ) {
        $token = isset( $_POST['asset_token'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_token'] ) ) : '';
        if ( empty( $token ) ) {
            wp_safe_redirect( home_url( '/cmms-asset/' ) );
            exit;
        }
        if ( ! isset( $_POST['cmms_public_asset_nonce'] )
             || ! wp_verify_nonce( wp_unslash( $_POST['cmms_public_asset_nonce'] ), 'cmms_public_asset_' . $token ) ) {
            wp_safe_redirect( add_query_arg( array( 'asset' => $token, 'cmms_err' => 'nonce' ), home_url( '/cmms-asset/' ) ) );
            exit;
        }
        $asset = CMMS_Assets::get_by_qr_token( $token );
        if ( ! $asset || $asset->status !== 'active' || empty( $asset->public_qr_enabled ) ) {
            wp_safe_redirect( add_query_arg( array( 'cmms_err' => 'unavailable' ), home_url( '/cmms-asset/' ) ) );
            exit;
        }
        $allowed = CMMS_Assets::decode_public_actions( $asset );
        if ( ! in_array( $required_action, $allowed, true ) ) {
            wp_safe_redirect( add_query_arg( array( 'asset' => $token, 'cmms_err' => 'forbidden' ), home_url( '/cmms-asset/' ) ) );
            exit;
        }
        return $asset;
    }

    /**
     * Public "report a breakdown" submission. Creates a task in the asset's
     * account, with the asset linked. Marked as a "public-submitted" task
     * so internal users can see where it came from.
     */
    public function cmms_public_asset_report() {
        $asset = $this->public_asset_preflight( 'report_breakdown' );
        $token = $asset->qr_token;

        $problem  = isset( $_POST['problem'] )  ? sanitize_textarea_field( wp_unslash( $_POST['problem'] ) )  : '';
        $reporter = isset( $_POST['reporter'] ) ? sanitize_text_field( wp_unslash( $_POST['reporter'] ) ) : '';
        $priority = isset( $_POST['priority'] ) ? sanitize_key( wp_unslash( $_POST['priority'] ) ) : 'normal';
        if ( ! in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) $priority = 'normal';

        if ( $problem === '' ) {
            wp_safe_redirect( add_query_arg( array( 'asset' => $token, 'cmms_err' => 'no_problem' ), home_url( '/cmms-asset/' ) ) );
            exit;
        }
        // Cap problem length defensively; sanitize_textarea_field doesn't enforce a limit.
        $problem = mb_substr( $problem, 0, 2000 );

        // Build a clear, readable task title and description.
        $title = sprintf( __( 'Breakdown: %s', 'cmms-light' ), $asset->name );
        if ( mb_strlen( $title ) > 250 ) $title = mb_substr( $title, 0, 250 );

        $body_lines = array();
        $body_lines[] = $problem;
        $body_lines[] = '';
        $body_lines[] = '— ' . __( 'Submitted via QR code', 'cmms-light' );
        if ( $reporter ) $body_lines[] = __( 'Reporter:', 'cmms-light' ) . ' ' . $reporter;

        $task_id = CMMS_Tasks::create( (int) $asset->account_id, array(
            'title'       => $title,
            'description' => implode( "\n", $body_lines ),
            'asset_id'    => (int) $asset->id,
            'priority'    => $priority,
            'status'      => 'open',
        ) );

        if ( is_wp_error( $task_id ) || ! $task_id ) {
            wp_safe_redirect( add_query_arg( array( 'asset' => $token, 'cmms_err' => 'create_failed' ), home_url( '/cmms-asset/' ) ) );
            exit;
        }

        // Optional photo attached to the breakdown report.
        if ( ! empty( $_FILES['photo'] ) && empty( $_FILES['photo']['error'] ) && class_exists( 'CMMS_Attachments' ) ) {
            // Signature: handle_upload($account_id, $object_type, $object_id, $file, $uploaded_by = null)
            CMMS_Attachments::handle_upload(
                (int) $asset->account_id,
                'task',
                (int) $task_id,
                $_FILES['photo'],
                null
            );
        }

        // Notify the account owner / managers via the existing notification pipeline.
        do_action( 'cmms_public_submitted', (int) $task_id, 0 );

        wp_safe_redirect( add_query_arg( array( 'asset' => $token, 'cmms_submitted' => 'breakdown' ), home_url( '/cmms-asset/' ) ) );
        exit;
    }

    /**
     * Public "upload photo" submission. Just attaches a photo to the asset
     * (without creating a task). Useful e.g. for documenting condition over
     * time or capturing nameplate info on first visit.
     */
    public function cmms_public_asset_photo() {
        $asset = $this->public_asset_preflight( 'upload_photo' );
        $token = $asset->qr_token;

        if ( empty( $_FILES['photo'] ) || ! empty( $_FILES['photo']['error'] ) ) {
            wp_safe_redirect( add_query_arg( array( 'asset' => $token, 'cmms_err' => 'no_photo' ), home_url( '/cmms-asset/' ) ) );
            exit;
        }
        $note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

        if ( class_exists( 'CMMS_Attachments' ) ) {
            CMMS_Attachments::handle_upload(
                (int) $asset->account_id,
                'asset',
                (int) $asset->id,
                $_FILES['photo'],
                null
            );
        }

        wp_safe_redirect( add_query_arg( array( 'asset' => $token, 'cmms_submitted' => 'photo' ), home_url( '/cmms-asset/' ) ) );
        exit;
    }

    /* ============ CUSTOM FIELD DEFINITIONS ============ */

    public function cmms_field_def_add() {
        $u = $this->require_user();
        if ( ! in_array( $u->role, array( 'owner', 'manager' ), true ) ) {
            wp_die( esc_html__( 'Manager role required', 'cmms-light' ), 403 );
        }
        if ( ! isset( $_POST['cmms_field_def_nonce'] )
             || ! wp_verify_nonce( wp_unslash( $_POST['cmms_field_def_nonce'] ), 'cmms_field_def' ) ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'settings', 'tab' => 'asset_fields', 'cmms_err' => 'failed' ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }
        $result = CMMS_Assets::add_field_def( (int) $u->account_id, array(
            'label'      => isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '',
            'field_key'  => isset( $_POST['field_key'] ) ? wp_unslash( $_POST['field_key'] ) : '',
            'field_type' => isset( $_POST['field_type'] ) ? wp_unslash( $_POST['field_type'] ) : 'text',
            'options'    => isset( $_POST['options'] ) ? wp_unslash( $_POST['options'] ) : '',
            'required'   => ! empty( $_POST['required'] ),
            'is_public'  => ! empty( $_POST['is_public'] ),
        ) );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'settings', 'tab' => 'asset_fields', 'cmms_err' => $result->get_error_code() ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }
        wp_safe_redirect( add_query_arg( array( 'view' => 'settings', 'tab' => 'asset_fields', 'cmms_done' => 'field_added' ), home_url( '/cmms-dashboard/' ) ) );
        exit;
    }

    public function cmms_field_def_delete() {
        $u = $this->require_user();
        if ( ! in_array( $u->role, array( 'owner', 'manager' ), true ) ) {
            wp_die( esc_html__( 'Manager role required', 'cmms-light' ), 403 );
        }
        if ( ! isset( $_POST['cmms_field_def_del_nonce'] )
             || ! wp_verify_nonce( wp_unslash( $_POST['cmms_field_def_del_nonce'] ), 'cmms_field_def_del' ) ) {
            wp_safe_redirect( add_query_arg( array( 'view' => 'settings', 'tab' => 'asset_fields', 'cmms_err' => 'failed' ), home_url( '/cmms-dashboard/' ) ) );
            exit;
        }
        $def_id = isset( $_POST['def_id'] ) ? (int) $_POST['def_id'] : 0;
        CMMS_Assets::delete_field_def( (int) $u->account_id, $def_id );
        wp_safe_redirect( add_query_arg( array( 'view' => 'settings', 'tab' => 'asset_fields', 'cmms_done' => 'field_deleted' ), home_url( '/cmms-dashboard/' ) ) );
        exit;
    }

    /* ============ GLOBAL SEARCH ============ */

    /**
     * Global search across tasks, assets, and users for the logged-in
     * user's account (tenant scope is enforced in the WHERE clause).
     *
     * Request: GET ?action=cmms_search&q=...&_=<nonce>
     * Returns JSON:
     *   { tasks: [...], assets: [...], users: [...] }
     *
     * Each section is capped at 5 results — the front-end shows them
     * in three groups in a dropdown/overlay.
     *
     * Implementation notes:
     *   - Plain LIKE queries with %term% wildcards. Fast enough for the
     *     dataset sizes we're targeting (a few thousand rows per tenant).
     *     If a customer grows beyond that, we'll add FULLTEXT or wp_query.
     *   - Tasks: searches title + description + status + priority.
     *     Status/priority are short tokens, so LIKE is generous (matching
     *     "open" or "high" surfaces those rows even with a partial query).
     *   - Assets: searches name + location + asset_type + custom_fields
     *     (the JSON column is just text from MySQL's perspective, so a
     *     LIKE on it surfaces rows whose stored values contain the term).
     *   - Users: searches the wp_users table joined to wp_cmms_users,
     *     restricted to the same account.
     */
    public function cmms_search() {
        // Don't use require_user() here — it does a redirect that comes back
        // as HTML to the fetch() caller. We want a clean JSON 401 instead.
        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) {
            wp_send_json_error( array( 'error' => 'auth_required' ), 401 );
        }

        $q = isset( $_GET['q'] ) ? trim( wp_unslash( $_GET['q'] ) ) : '';

        // Per spec: do not search before 2 chars. Defense in depth — JS
        // also enforces this, but server must be authoritative.
        if ( mb_strlen( $q ) < 2 ) {
            wp_send_json_success( array(
                'tasks'  => array(),
                'assets' => array(),
                'users'  => array(),
            ) );
        }
        // Cap query length to prevent DoS via huge LIKE patterns.
        if ( mb_strlen( $q ) > 100 ) $q = mb_substr( $q, 0, 100 );

        $account_id = (int) $u->account_id;

        wp_send_json_success( array(
            'tasks'  => $this->search_tasks(  $u, $q ),
            'assets' => $this->search_assets( $account_id, $q ),
            'users'  => $this->search_users(  $account_id, $q ),
        ) );
    }

    /**
     * Search the tasks table, scoped to what the user is allowed to see.
     *
     * Visibility rules mirror CMMS_Auth::can_view_task:
     *   - Owners and managers: every task in the account.
     *   - Everyone else: only tasks where they are assignee, manager,
     *     or creator. Pure viewers (no relation) get no rows back.
     *
     * The scoping is done in SQL so we never load and discard rows the
     * user can't see — important for performance, more important for not
     * leaking metadata via timing or row counts.
     */
    private function search_tasks( $cmms_user, $q ) {
        global $wpdb;
        $tasks_t  = CMMS_DB::table( 'tasks' );
        $assets_t = CMMS_DB::table( 'assets' );
        $account_id = (int) $cmms_user->account_id;
        $user_id    = (int) $cmms_user->id;

        // See note in search_assets() — schema-aware LOCATE search.
        static $task_cols = null;
        if ( $task_cols === null ) {
            $task_cols = array();
            $rows = $wpdb->get_results( "SHOW COLUMNS FROM $tasks_t" );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $r ) {
                    if ( isset( $r->Field ) ) $task_cols[ $r->Field ] = true;
                }
            }
        }

        $needle = esc_sql( $q );
        $prefix_needle = esc_sql( $q );
        $account_id = (int) $account_id;

        $where_parts = array(
            "LOCATE('$needle', t.title)    > 0",
            "LOCATE('$needle', t.status)   > 0",
            "LOCATE('$needle', t.priority) > 0",
        );
        if ( isset( $task_cols['description'] ) ) {
            $where_parts[] = "LOCATE('$needle', t.description) > 0";
        }
        $where_match = '( ' . implode( ' OR ', $where_parts ) . ' )';

        // Permission scoping: owners/managers see everything in the
        // account; everyone else only the rows they're related to.
        // The "is OR each leg" form lets MySQL still use the (account_id,
        // assigned_to) / (account_id, created_by) indexes when present.
        $is_priv = in_array(
            $cmms_user->role,
            array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER ),
            true
        );
        if ( $is_priv ) {
            $perm_clause = '1=1';
        } else {
            $perm_clause = "( t.assigned_to = $user_id "
                         . " OR t.manager_id  = $user_id "
                         . " OR t.created_by  = $user_id )";
        }

        $sql = "SELECT t.id, t.title, t.status, t.priority, t.asset_id,
                       a.name AS asset_name
                  FROM $tasks_t t
                  LEFT JOIN $assets_t a ON a.id = t.asset_id AND a.account_id = t.account_id
                 WHERE t.account_id = $account_id
                   AND $perm_clause
                   AND $where_match
                 ORDER BY
                   /* Boost exact-prefix matches on title — feels more relevant */
                   CASE WHEN LOCATE('$prefix_needle', t.title) = 1 THEN 0 ELSE 1 END,
                   t.created_at DESC
                 LIMIT 5";
        $rows = $wpdb->get_results( $sql );

        $out = array();
        foreach ( (array) $rows as $r ) {
            $out[] = array(
                'id'         => (int) $r->id,
                'title'      => (string) $r->title,
                'status'     => (string) $r->status,
                'priority'   => (string) $r->priority,
                'asset_id'   => $r->asset_id ? (int) $r->asset_id : null,
                'asset_name' => $r->asset_name ? (string) $r->asset_name : null,
                'url'        => add_query_arg(
                    array( 'view' => 'task', 'id' => (int) $r->id ),
                    home_url( '/cmms-dashboard/' )
                ),
            );
        }
        return $out;
    }

    private function search_assets( $account_id, $q ) {
        global $wpdb;
        $assets_t = CMMS_DB::table( 'assets' );

        // Schema-aware: we LOCATE() across whatever text columns the
        // assets table actually has. Older accounts may be missing
        // custom_fields/description if a past dbDelta upgrade silently
        // skipped the ALTER (we've seen this on managed hosting). The
        // 1.14.17 activator backfills those columns; this probe is the
        // belt-and-braces path so search keeps working even if the
        // ALTER was rejected.
        //
        // Why LOCATE instead of LIKE: wpdb's query layer treats '%' as
        // a printf placeholder marker even inside string literals,
        // which broke "name LIKE '%term%'" silently for some inputs.
        // LOCATE(needle, haystack) is functionally identical to a
        // double-sided LIKE but contains no '%' anywhere.
        static $cols_cache = null;
        if ( $cols_cache === null ) {
            $cols_cache = array();
            $rows_cols = $wpdb->get_results( "SHOW COLUMNS FROM $assets_t" );
            if ( is_array( $rows_cols ) ) {
                foreach ( $rows_cols as $r ) {
                    if ( isset( $r->Field ) ) $cols_cache[ $r->Field ] = true;
                }
            }
        }

        $needle = esc_sql( $q );
        $prefix_needle = esc_sql( $q );
        $account_id = (int) $account_id;

        $where_parts = array(
            "LOCATE('$needle', name)       > 0",
            "LOCATE('$needle', location)   > 0",
            "LOCATE('$needle', asset_type) > 0",
        );
        if ( isset( $cols_cache['custom_fields'] ) ) {
            $where_parts[] = "LOCATE('$needle', custom_fields) > 0";
        }
        if ( isset( $cols_cache['description'] ) ) {
            $where_parts[] = "LOCATE('$needle', description) > 0";
        }
        $where_match = '( ' . implode( ' OR ', $where_parts ) . ' )';

        $sql = "SELECT id, name, location, asset_type
                  FROM $assets_t
                 WHERE account_id = $account_id
                   AND status = 'active'
                   AND $where_match
                 ORDER BY
                   CASE WHEN LOCATE('$prefix_needle', name) = 1 THEN 0 ELSE 1 END,
                   name ASC
                 LIMIT 5";
        $rows = $wpdb->get_results( $sql );

        $out = array();
        foreach ( (array) $rows as $r ) {
            $out[] = array(
                'id'         => (int) $r->id,
                'name'       => (string) $r->name,
                'location'   => $r->location ? (string) $r->location : '',
                'asset_type' => (string) $r->asset_type,
                'url'        => add_query_arg(
                    array( 'view' => 'asset', 'id' => (int) $r->id ),
                    home_url( '/cmms-dashboard/' )
                ),
            );
        }
        return $out;
    }

    private function search_users( $account_id, $q ) {
        global $wpdb;
        $cmms_users_t = CMMS_DB::table( 'users' );

        // See note in search_assets() — LOCATE() avoids %-placeholder bugs.
        $needle = esc_sql( $q );
        $prefix_needle = esc_sql( $q );
        $account_id = (int) $account_id;
        $wpu = $wpdb->users;

        $sql = "SELECT cu.id, cu.role, w.display_name, w.user_email, w.user_login
                  FROM $cmms_users_t cu
                  INNER JOIN $wpu w ON w.ID = cu.wp_user_id
                 WHERE cu.account_id = $account_id
                   AND cu.status = 'active'
                   AND ( LOCATE('$needle', w.display_name) > 0
                      OR LOCATE('$needle', w.user_email)   > 0
                      OR LOCATE('$needle', w.user_login)   > 0
                      OR LOCATE('$needle', cu.role)        > 0 )
                 ORDER BY
                   CASE WHEN LOCATE('$prefix_needle', w.display_name) = 1 THEN 0 ELSE 1 END,
                   w.display_name ASC
                 LIMIT 5";
        $rows = $wpdb->get_results( $sql );

        $out = array();
        foreach ( (array) $rows as $r ) {
            $name = $r->display_name ?: $r->user_login;
            $out[] = array(
                'id'    => (int) $r->id,
                'name'  => (string) $name,
                'email' => (string) $r->user_email,
                'role'  => (string) $r->role,
                'url'   => add_query_arg(
                    array( 'view' => 'users' ),
                    home_url( '/cmms-dashboard/' )
                ),
            );
        }
        return $out;
    }

    /**
     * Diagnostic endpoint for notifications. Read-only, returns the
     * current user's notification state as JSON. Lives under
     * admin-post.php?action=cmms_notify_debug.
     *
     * Temporary — remove once we've stabilized the notification flow
     * (planned 1.14.20+).
     */
    public function cmms_notify_debug() {
        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) {
            wp_send_json_error( array( 'error' => 'auth_required' ), 401 );
        }
        global $wpdb;
        $notif_t = CMMS_DB::table( 'notifications' );

        // Count + recent
        $total   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $notif_t WHERE user_id = %d",
            $u->id
        ) );
        $unread  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $notif_t WHERE user_id = %d AND read_at IS NULL",
            $u->id
        ) );
        $recent = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, type, title, body, url, task_id,
                    email_sent, email_sent_at, read_at, created_at
               FROM $notif_t WHERE user_id = %d
              ORDER BY created_at DESC LIMIT 15",
            $u->id
        ) );

        // Group by type to see distribution
        $by_type = $wpdb->get_results( $wpdb->prepare(
            "SELECT type, COUNT(*) AS n,
                    SUM(CASE WHEN email_sent = 1 THEN 1 ELSE 0 END) AS emailed,
                    SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread
               FROM $notif_t WHERE user_id = %d
              GROUP BY type ORDER BY n DESC",
            $u->id
        ) );

        // Mute settings (per WP user)
        $muted = get_user_meta( $u->wp_user_id, 'cmms_email_muted_types', true );
        if ( ! is_array( $muted ) ) $muted = array();

        // Push subscriptions for this user
        $push_table = CMMS_DB::table( 'push_subs' );
        $push_table_exists = ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $push_table
        ) ) > 0 );
        $push_subs = array();
        if ( $push_table_exists ) {
            $push_subs = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, LEFT(endpoint, 60) AS endpoint_prefix,
                        device_info, created_at
                   FROM $push_table WHERE user_id = %d",
                $u->id
            ) );
        }

        // Cron status
        $cron_next = wp_next_scheduled( 'cmms_dispatch_reminders' );
        $cron_last = get_option( 'cmms_cron_last_run' );

        // SMTP heuristic
        $smtp_likely = method_exists( 'CMMS_Notifications', 'smtp_likely_configured' )
            ? (bool) CMMS_Notifications::smtp_likely_configured() : null;

        wp_send_json_success( array(
            'cmms_user_id'   => (int) $u->id,
            'cmms_user_role' => (string) $u->role,
            'wp_user_id'     => (int) $u->wp_user_id,
            'account_id'     => (int) $u->account_id,
            'totals'         => array(
                'total'  => $total,
                'unread' => $unread,
            ),
            'by_type'        => $by_type,
            'recent_15'      => $recent,
            'muted_types'    => array_values( $muted ),
            'push' => array(
                'table_exists'   => $push_table_exists,
                'subscriptions'  => $push_subs,
                'subscription_count' => count( (array) $push_subs ),
            ),
            'cron' => array(
                'next_run_unix'  => $cron_next,
                'next_run_human' => $cron_next ? gmdate( 'Y-m-d H:i:s', $cron_next ) . ' UTC' : null,
                'last_run'       => $cron_last,
            ),
            'smtp_likely_configured' => $smtp_likely,
        ) );
    }

    /**
     * Public-ish endpoint that returns help articles. Used by the
     * contextual help button to populate the modal asynchronously.
     *
     * Mode:
     *   - ?article=<id>     return that one article rendered HTML
     *   - ?page=<page-id>   return the list of articles for that page
     *
     * Auth: required (must be a CMMS user) so we don't open a help-text
     * scraping endpoint to the public web.
     */
    public function cmms_help_get() {
        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) wp_send_json_error( array( 'error' => 'auth_required' ), 401 );

        $article_id = isset( $_GET['article'] ) ? sanitize_key( wp_unslash( $_GET['article'] ) ) : '';
        $page_id    = isset( $_GET['page'] )    ? sanitize_key( wp_unslash( $_GET['page'] ) )    : '';

        if ( $article_id ) {
            $article = CMMS_Help::get_article( $article_id );
            if ( ! $article ) wp_send_json_error( array( 'error' => 'not_found' ), 404 );
            wp_send_json_success( array(
                'article' => $article,
                'html'    => CMMS_Help::render_article_html( $article ),
            ) );
        } elseif ( $page_id ) {
            $articles = CMMS_Help::articles_for_page( $page_id );
            // Return rendered HTML for each so the client can drop it
            // straight into the modal — saves a roundtrip per article.
            $rendered = array();
            foreach ( $articles as $a ) {
                $rendered[] = array(
                    'id'      => $a['id'],
                    'title'   => $a['title'],
                    'summary' => $a['summary'] ?? '',
                    'html'    => CMMS_Help::render_article_html( $a ),
                );
            }
            wp_send_json_success( array( 'articles' => $rendered ) );
        }

        wp_send_json_error( array( 'error' => 'missing_param' ), 400 );
    }

    /**
     * Onboarding step 1 — account creation (1.14.25).
     *
     * This is the AJAX target for the new modern wizard at /start. It
     * intentionally lives SEPARATE from the legacy cmms_signup endpoint:
     *
     *   - Legacy cmms_signup: form POST, redirect-based, used by [cmms_signup]
     *   - This cmms_onboarding_step1: JSON, used by the JS wizard. Lets the
     *     wizard show inline validation, error states, and a smooth
     *     transition to step 2 without a page reload.
     *
     * Both eventually call CMMS_Accounts::signup() so account creation
     * logic stays single-sourced — we're not duplicating user creation
     * code, just changing the front-door experience.
     *
     * Returns JSON:
     *   - success: { redirect: '/start?step=2' }  (step 2 lands here in 1.14.26)
     *   - error:   { field: 'email', code: 'exists', message: '...' }
     *
     * Field-scoped errors let the UI highlight the specific input
     * instead of showing a top banner — the modern SaaS standard.
     */
    public function cmms_onboarding_step1() {
        // Nonce: separate from legacy cmms_signup to avoid accidental
        // collision; rotated per page load by the wizard JS.
        if ( ! isset( $_POST['cmms_onboarding_nonce'] )
          || ! wp_verify_nonce( wp_unslash( $_POST['cmms_onboarding_nonce'] ), 'cmms_onboarding' ) ) {
            wp_send_json_error( array(
                'field'   => null,
                'code'    => 'nonce',
                'message' => __( 'הקישור פג, נסה לרענן את העמוד.', 'cmms-light' ),
            ), 403 );
        }

        $company   = isset( $_POST['company'] )   ? sanitize_text_field( wp_unslash( $_POST['company'] ) )   : '';
        $name      = isset( $_POST['name'] )      ? sanitize_text_field( wp_unslash( $_POST['name'] ) )      : '';
        $phone     = isset( $_POST['phone'] )     ? sanitize_text_field( wp_unslash( $_POST['phone'] ) )     : '';
        $email     = isset( $_POST['email'] )     ? sanitize_email( wp_unslash( $_POST['email'] ) )          : '';
        $password  = isset( $_POST['password'] )  ? (string) $_POST['password']                              : '';
        $terms     = ! empty( $_POST['terms'] );

        // 1.14.27: plan + cycle persistence. Fall back to safe defaults
        // if missing (e.g. someone POSTed directly without going through
        // step 1). We validate strictly against the catalog so an invalid
        // plan id never lands in the DB.
        $plan_id   = isset( $_POST['plan'] )  ? sanitize_key( wp_unslash( $_POST['plan'] ) )  : '';
        $cycle     = isset( $_POST['cycle'] ) ? sanitize_key( wp_unslash( $_POST['cycle'] ) ) : '';
        if ( ! in_array( $plan_id, CMMS_Plans::valid_ids(), true ) ) $plan_id = CMMS_Plans::default_id();
        if ( ! in_array( $cycle, CMMS_Plans::valid_cycles(), true ) ) $cycle = CMMS_Plans::default_cycle();
        $plan_def = CMMS_Plans::get( $plan_id );

        // Field-by-field validation. Hebrew messages, field-scoped so the
        // UI can ring the specific input. Order matters: most basic
        // problems first (empty), then format problems, then DB-level.
        if ( $company === '' ) {
            wp_send_json_error( array( 'field' => 'company', 'code' => 'required',
                'message' => __( 'יש להזין את שם החברה.', 'cmms-light' ) ), 422 );
        }
        if ( $name === '' ) {
            wp_send_json_error( array( 'field' => 'name', 'code' => 'required',
                'message' => __( 'יש להזין שם מלא.', 'cmms-light' ) ), 422 );
        }
        if ( $phone === '' ) {
            wp_send_json_error( array( 'field' => 'phone', 'code' => 'required',
                'message' => __( 'יש להזין מספר טלפון.', 'cmms-light' ) ), 422 );
        }
        // Loose phone validation — allow Israeli formats with or without dashes.
        if ( ! preg_match( '/^[0-9+\-\s()]{7,20}$/', $phone ) ) {
            wp_send_json_error( array( 'field' => 'phone', 'code' => 'invalid',
                'message' => __( 'מספר הטלפון אינו תקין.', 'cmms-light' ) ), 422 );
        }
        if ( $email === '' || ! is_email( $email ) ) {
            wp_send_json_error( array( 'field' => 'email', 'code' => 'invalid',
                'message' => __( 'כתובת האימייל אינה תקינה.', 'cmms-light' ) ), 422 );
        }
        if ( email_exists( $email ) ) {
            wp_send_json_error( array( 'field' => 'email', 'code' => 'exists',
                'message' => __( 'כתובת האימייל כבר רשומה במערכת. אפשר להתחבר במקום.', 'cmms-light' ) ), 409 );
        }
        // Password strength: 8+ chars, at least one English letter and one digit.
        // We explicitly disallow Hebrew (and other non-Latin scripts) in
        // passwords because:
        //   - Some browsers and mobile keyboards encode/transmit non-Latin
        //     chars inconsistently, leading to "I created my password but
        //     can't log in from my phone" support tickets.
        //   - WordPress's wp_signon flow is also encoding-sensitive.
        //   - It's the standard across Israeli SaaS (Monday, Fireberry).
        // Order matters: check length, then detect Hebrew (give the
        // clearest possible reason), then the composition rule.
        if ( strlen( $password ) < 8 ) {
            wp_send_json_error( array( 'field' => 'password', 'code' => 'weak',
                'message' => __( 'הסיסמה חייבת להכיל לפחות 8 תווים.', 'cmms-light' ) ), 422 );
        }
        // Hebrew range \x{0590}-\x{05FF}. /u flag so PHP treats the
        // string as UTF-8 instead of bytes — without /u this check would
        // silently fail and the user would see the generic message.
        if ( preg_match( '/[\x{0590}-\x{05FF}]/u', $password ) ) {
            wp_send_json_error( array( 'field' => 'password', 'code' => 'hebrew',
                'message' => __( 'הסיסמה לא יכולה להכיל אותיות בעברית. השתמש באותיות באנגלית וספרות.', 'cmms-light' ) ), 422 );
        }
        if ( ! preg_match( '/[A-Za-z]/', $password ) || ! preg_match( '/[0-9]/', $password ) ) {
            wp_send_json_error( array( 'field' => 'password', 'code' => 'weak',
                'message' => __( 'הסיסמה חייבת להכיל אותיות באנגלית וגם ספרות.', 'cmms-light' ) ), 422 );
        }
        if ( ! $terms ) {
            wp_send_json_error( array( 'field' => 'terms', 'code' => 'required',
                'message' => __( 'יש לאשר את תנאי השימוש.', 'cmms-light' ) ), 422 );
        }

        // Reuse the existing signup pipeline so account/user creation
        // logic is single-sourced. Auto-login = true so by the time
        // they land on /start?step=2 they're already authenticated.
        $result = CMMS_Accounts::signup( array(
            'name'         => $name,
            'email'        => $email,
            'password'     => $password,
            'organization' => $company,
            'phone'        => $phone,
        ), true );

        if ( is_wp_error( $result ) ) {
            // signup() error codes we recognize; everything else is generic.
            $code = $result->get_error_code();
            $msg  = $result->get_error_message();
            $field = null;
            if ( strpos( $code, 'email' ) !== false || strpos( $msg, 'email' ) !== false ) $field = 'email';
            wp_send_json_error( array(
                'field'   => $field,
                'code'    => $code ?: 'failed',
                'message' => $msg ?: __( 'אירעה שגיאה ביצירת החשבון, נסה שוב.', 'cmms-light' ),
            ), 500 );
        }

        // 1.14.27: persist the chosen plan onto the new account row.
        // We do this AFTER signup succeeds (not inside it) so the signup
        // pipeline stays single-purpose and the plan fields are an
        // additive concern. If this UPDATE somehow fails the account
        // still works — it just has null plan fields, which the system
        // treats as "no plan selected yet". Super Admin can fill in later.
        $account_id = (int) $result;
        if ( $account_id > 0 ) {
            global $wpdb;
            $accounts_t = CMMS_DB::table( 'accounts' );

            // 1.14.34: read the specific package row (plan_type × cycle)
            // from the catalog so we get its declared max_users and
            // billing_mode rather than guessing. Falls back to the plan
            // tier defaults if for some reason the precise row is missing.
            $pkg = class_exists( 'CMMS_Plans' )
                ? CMMS_Plans::get_package( $plan_id, $cycle )
                : null;

            $max_users    = $pkg ? $pkg['max_users'] : ( isset( $plan_def['max_users'] ) ? $plan_def['max_users'] : null );
            // billing_mode in the package is the source of truth:
            //   'recurring' → automatic billing via iCredit (when 1.14.36 ships)
            //   'one_time'  → single charge
            //   'manual'    → admin handles billing offline
            // For now (no iCredit yet), even 'recurring' rows get
            // billing_type='manual' since we haven't wired the recurring
            // engine. 1.14.36 will switch this to mirror billing_mode.
            $billing_type = 'manual';

            $wpdb->update(
                $accounts_t,
                array(
                    'plan_type'           => $plan_id,
                    'billing_cycle'       => $cycle,
                    'max_users'           => $max_users,
                    'billing_type'        => $billing_type,
                    // 'trial' lets new accounts use the system right away
                    // without blocking on payment. Super Admin flips to
                    // 'active' after billing, or it changes automatically
                    // once payment hooks are wired in 1.14.36.
                    'subscription_status' => 'trial',
                ),
                array( 'id' => $account_id ),
                array( '%s', '%s', $max_users === null ? null : '%d', '%s', '%s' ),
                array( '%d' )
            );
        }

        // Success. 1.14.36: redirect to Step 3 (Payment) carrying the
        // chosen plan/cycle forward. The new account is in 'trial'
        // status — Payment + IPN will flip it to 'active'.
        $redirect = add_query_arg( array(
            'step'  => 3,
            'plan'  => $plan_id,
            'cycle' => $cycle,
        ), home_url( '/start/' ) );

        wp_send_json_success( array(
            'account_id' => (int) $result,
            'redirect'   => $redirect,
        ) );
    }

    /**
     * Create an iCredit sale for the current account and return the
     * payment-page URL. Called by Step 3 (Payment) of the wizard.
     *
     * Expects POST: plan, cycle, cmms_payment_nonce.
     * The account is identified by the logged-in cmms_user (created
     * in Step 2 — auto_login=true means they're already in session).
     */
    public function cmms_payment_init() {
        if ( ! isset( $_POST['cmms_payment_nonce'] )
          || ! wp_verify_nonce( wp_unslash( $_POST['cmms_payment_nonce'] ), 'cmms_payment' ) ) {
            wp_send_json_error( array(
                'message' => 'הקישור פג, נסה לרענן את העמוד.',
            ), 403 );
        }

        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) {
            // No session — they probably timed out between steps.
            // Send them back to login.
            wp_send_json_error( array(
                'message' => 'נדרשת התחברות מחדש לפני התשלום.',
                'redirect' => home_url( '/cmms-login/' ),
            ), 401 );
        }

        $plan_id = isset( $_POST['plan'] )  ? sanitize_key( wp_unslash( $_POST['plan'] ) )  : '';
        $cycle   = isset( $_POST['cycle'] ) ? sanitize_key( wp_unslash( $_POST['cycle'] ) ) : '';
        if ( ! in_array( $plan_id, CMMS_Plans::valid_ids(), true ) )    $plan_id = CMMS_Plans::default_id();
        if ( ! in_array( $cycle, CMMS_Plans::valid_cycles(), true ) )   $cycle   = CMMS_Plans::default_cycle();

        $package = CMMS_Plans::get_package( $plan_id, $cycle );
        if ( ! $package ) {
            wp_send_json_error( array(
                'message' => 'החבילה הנבחרת אינה זמינה.',
            ), 404 );
        }

        // Pull contact details for iCredit to pre-fill the payment form.
        // 1.14.53: chain to wp_users for email (cmms_users doesn't have
        // it). display_name falls back to wp_users.display_name, then to
        // the email's local part if nothing else is available. Without
        // this, iCredit shows "לקוח לקוח" in the customer fields and the
        // billing receipt comes out anonymous.
        global $wpdb;
        $users_t = CMMS_DB::table( 'users' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT cu.display_name AS cmms_name, cu.phone AS phone,
                    wpu.user_email AS email,
                    wpu.display_name AS wp_name,
                    wpu.first_name AS wp_first_name_meta_placeholder
             FROM $users_t cu
             LEFT JOIN {$wpdb->users} wpu ON wpu.ID = cu.wp_user_id
             WHERE cu.id = %d",
            (int) $u->id
        ), ARRAY_A );

        // Resolve the best name available, in order of preference.
        $name = '';
        if ( ! empty( $row['cmms_name'] ) ) $name = trim( (string) $row['cmms_name'] );
        if ( $name === '' && ! empty( $row['wp_name'] ) ) $name = trim( (string) $row['wp_name'] );
        // Last resort: WP first_name + last_name meta (if set).
        if ( $name === '' && ! empty( $u->id ) ) {
            $wp_uid = $wpdb->get_var( $wpdb->prepare(
                "SELECT wp_user_id FROM $users_t WHERE id = %d", (int) $u->id
            ) );
            if ( $wp_uid ) {
                $first = get_user_meta( (int) $wp_uid, 'first_name', true );
                $last  = get_user_meta( (int) $wp_uid, 'last_name', true );
                $combined = trim( $first . ' ' . $last );
                if ( $combined !== '' ) $name = $combined;
            }
        }

        $result = CMMS_Icredit::create_sale( array(
            'account_id' => (int) $u->account_id,
            'package'    => $package,
            'name'       => $name,
            'email'      => $row['email'] ?? '',
            'phone'      => $row['phone'] ?? '',
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ), 400 );
        }

        wp_send_json_success( array(
            'redirect'   => $result['url'],
            'payment_id' => $result['payment_id'],
        ) );
    }

    /**
     * Mark a manual checklist step as complete (e.g. PWA install).
     * 1.14.45.
     */
    public function cmms_checklist_mark() {
        check_ajax_referer( 'cmms_checklist', 'nonce' );
        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) wp_send_json_error( array( 'message' => 'not logged in' ), 401 );

        $step_key = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
        if ( ! $step_key ) wp_send_json_error( array( 'message' => 'missing step' ), 400 );

        $ok = CMMS_Checklist::mark_manual( (int) $u->account_id, $step_key );
        if ( ! $ok ) wp_send_json_error( array( 'message' => 'invalid step' ), 400 );
        wp_send_json_success( array( 'step' => $step_key ) );
    }

    /**
     * Permanently dismiss the setup checklist for this account.
     * 1.14.45.
     */
    public function cmms_checklist_dismiss() {
        check_ajax_referer( 'cmms_checklist', 'nonce' );
        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) wp_send_json_error( array( 'message' => 'not logged in' ), 401 );

        CMMS_Checklist::dismiss( (int) $u->account_id );
        wp_send_json_success();
    }

    /**
     * 1.14.50: Check if an account can add one more user given its
     * package's max_users limit.
     *
     * Returns true when:
     *   - max_users is NULL (unlimited tier, e.g. Enterprise)
     *   - current active+invited user count is strictly below max_users
     *
     * "Active+invited" means we count both users who completed sign-in
     * AND users created but yet to log in. We do NOT count 'inactive'
     * (deactivated) users — those slots are free.
     */
    public static function within_user_limit( $account_id ) {
        $account_id = (int) $account_id;
        if ( ! $account_id ) return false;

        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $users_t    = CMMS_DB::table( 'users' );

        $max = $wpdb->get_var( $wpdb->prepare(
            "SELECT max_users FROM $accounts_t WHERE id = %d",
            $account_id
        ) );

        // NULL = unlimited.
        if ( $max === null || $max === '' ) return true;
        $max = (int) $max;
        if ( $max <= 0 ) return true;

        $current = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $users_t
              WHERE account_id = %d
                AND status IN ('active','invited')",
            $account_id
        ) );

        return $current < $max;
    }

    /**
     * 1.14.56: Cancel the user's recurring subscription.
     *
     * Permission model: ONLY the account owner can cancel. Managers
     * (and below) are forbidden — billing decisions are reserved for
     * the account owner who took on the financial commitment.
     *
     * Behavior:
     *   - Call iCredit's RecurringSaleCancel API
     *   - On success: mark subscription as 'canceled' in our DB,
     *     mark account.subscription_status as 'canceled_pending'
     *     (user keeps access until next_charge_at)
     *   - On failure: return error, don't change anything
     *
     * We DO NOT immediately revoke access on cancel — that would
     * effectively rob the user of paid time. Instead the dashboard
     * blocker only kicks in after next_charge_at has passed.
     */
    public function cmms_subscription_cancel() {
        check_ajax_referer( 'cmms_subscription_cancel', 'nonce' );

        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) {
            wp_send_json_error( array( 'message' => 'נדרשת התחברות.' ), 401 );
        }

        // OWNER-ONLY gate.
        if ( $u->role !== CMMS_Auth::ROLE_OWNER ) {
            wp_send_json_error( array(
                'message' => 'רק בעלי החשבון יכולים לבטל את החברות.'
            ), 403 );
        }

        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );
        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $subs_t
              WHERE account_id = %d AND status IN ('active','past_due')
              ORDER BY id DESC LIMIT 1",
            (int) $u->account_id
        ), ARRAY_A );

        if ( ! $sub ) {
            wp_send_json_error( array(
                'message' => 'לא נמצאה הוראת קבע פעילה לחשבון זה.'
            ), 404 );
        }

        // 1.14.56: external_subscription_id is iCredit's RecurringId.
        // We use the generic field name in DB schema so other providers
        // could be added in the future without renaming.
        $recurring_id = $sub['external_subscription_id'] ?? '';
        if ( empty( $recurring_id ) ) {
            wp_send_json_error( array(
                'message' => 'הוראת הקבע אינה תקינה (חסר מזהה). אנא פנה לתמיכה.'
            ), 400 );
        }

        // Call iCredit. This will hit the network and may take a moment.
        $result = CMMS_Icredit::cancel_recurring( $recurring_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ), 502 );
        }

        // iCredit said OK. Mirror the state on our side.
        $now = current_time( 'mysql' );
        $wpdb->update(
            $subs_t,
            array(
                'status'        => 'canceled',
                'canceled_at'   => $now,
                'updated_at'    => $now,
            ),
            array( 'id' => (int) $sub['id'] ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        // Mark the account as canceled_pending — user keeps access
        // until next_charge_at, then dashboard blocker kicks in.
        $accounts_t = CMMS_DB::table( 'accounts' );
        $wpdb->update(
            $accounts_t,
            array(
                'subscription_status' => 'canceled_pending',
                'updated_at'          => $now,
            ),
            array( 'id' => (int) $u->account_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // TODO future: send a confirmation email so the user has a
        // record of the cancellation timestamp.

        wp_send_json_success( array(
            'message'      => 'הוראת הקבע בוטלה. תוכל להמשיך להשתמש במערכת עד תום תקופת החיוב הנוכחית.',
            'period_end'   => $sub['next_charge_at'] ?? null,
        ) );
    }

    /* ================================================================
       1.14.60: Plan change endpoints.
       All three are OWNER-ONLY (matches subscription cancel policy).
    ================================================================ */

    /**
     * Preview a plan change: compute proration, return display data.
     * No DB mutation, no iCredit call — purely informational.
     */
    public function cmms_plan_change_preview() {
        check_ajax_referer( 'cmms_plan_change', 'nonce' );

        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) {
            wp_send_json_error( array( 'message' => 'נדרשת התחברות.' ), 401 );
        }
        if ( $u->role !== CMMS_Auth::ROLE_OWNER ) {
            wp_send_json_error( array(
                'message' => 'רק בעלי החשבון יכולים לשנות חבילה.'
            ), 403 );
        }

        $new_plan  = isset( $_POST['plan_type'] )     ? sanitize_key( wp_unslash( $_POST['plan_type'] ) )     : '';
        $new_cycle = isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : '';
        if ( ! in_array( $new_plan, array( 'starter', 'business', 'enterprise' ), true ) ) {
            wp_send_json_error( array( 'message' => 'חבילה לא חוקית.' ), 400 );
        }
        if ( ! in_array( $new_cycle, array( 'monthly', 'yearly' ), true ) ) {
            wp_send_json_error( array( 'message' => 'מחזור חיוב לא חוקי.' ), 400 );
        }

        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );
        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $subs_t WHERE account_id = %d ORDER BY id DESC LIMIT 1",
            (int) $u->account_id
        ), ARRAY_A );
        if ( ! $sub ) {
            wp_send_json_error( array( 'message' => 'לא נמצאה הוראת קבע פעילה.' ), 404 );
        }
        if ( ! in_array( $sub['status'], array( 'active', 'past_due' ), true ) ) {
            wp_send_json_error( array(
                'message' => 'לא ניתן לשנות חבילה כשהוראת הקבע אינה פעילה.'
            ), 400 );
        }
        // Phase 1 limitation: same cycle only.
        if ( $sub['billing_cycle'] !== $new_cycle ) {
            wp_send_json_error( array(
                'message' => 'בשלב זה לא ניתן לעבור בין חיוב חודשי לשנתי. ניתן לפנות לתמיכה.'
            ), 400 );
        }
        // Same plan — nothing to change.
        if ( $sub['plan_type'] === $new_plan ) {
            wp_send_json_error( array(
                'message' => 'כבר נמצאים בחבילה זו.'
            ), 400 );
        }

        $new_pkg = CMMS_Plans::get_package( $new_plan, $new_cycle );
        if ( ! $new_pkg ) {
            wp_send_json_error( array( 'message' => 'החבילה הנבחרת אינה זמינה.' ), 404 );
        }

        $proration = CMMS_PlanChanges::calculate_proration( $sub, $new_pkg );

        wp_send_json_success( array(
            'proration'      => $proration,
            'new_package'    => array(
                'plan_type'     => $new_pkg['plan_type'],
                'billing_cycle' => $new_pkg['billing_cycle'],
                'display_name'  => $new_pkg['display_name'],
                'price'         => (float) $new_pkg['price'],
                'max_users'     => isset( $new_pkg['max_users'] ) ? (int) $new_pkg['max_users'] : null,
            ),
            'current_sub'    => array(
                'plan_type'     => $sub['plan_type'],
                'billing_cycle' => $sub['billing_cycle'],
                'amount'        => (float) $sub['amount'],
                'next_charge_at' => $sub['next_charge_at'],
            ),
        ) );
    }

    /**
     * Apply a plan change. Routes to upgrade vs downgrade based on price.
     *
     * Upgrade  → returns a redirect_url to iCredit hosted page for the
     *            prorated delta charge. IPN handler applies the change.
     * Downgrade → updates DB pending fields. Returns success message.
     */
    public function cmms_plan_change_apply() {
        check_ajax_referer( 'cmms_plan_change', 'nonce' );

        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) {
            wp_send_json_error( array( 'message' => 'נדרשת התחברות.' ), 401 );
        }
        if ( $u->role !== CMMS_Auth::ROLE_OWNER ) {
            wp_send_json_error( array(
                'message' => 'רק בעלי החשבון יכולים לשנות חבילה.'
            ), 403 );
        }

        $new_plan  = isset( $_POST['plan_type'] )     ? sanitize_key( wp_unslash( $_POST['plan_type'] ) )     : '';
        $new_cycle = isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : '';
        if ( ! in_array( $new_plan, array( 'starter', 'business', 'enterprise' ), true ) ) {
            wp_send_json_error( array( 'message' => 'חבילה לא חוקית.' ), 400 );
        }
        if ( ! in_array( $new_cycle, array( 'monthly', 'yearly' ), true ) ) {
            wp_send_json_error( array( 'message' => 'מחזור חיוב לא חוקי.' ), 400 );
        }

        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );
        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $subs_t WHERE account_id = %d ORDER BY id DESC LIMIT 1",
            (int) $u->account_id
        ), ARRAY_A );
        if ( ! $sub ) {
            wp_send_json_error( array( 'message' => 'לא נמצאה הוראת קבע.' ), 404 );
        }
        if ( $sub['billing_cycle'] !== $new_cycle ) {
            wp_send_json_error( array(
                'message' => 'בשלב זה לא ניתן לעבור בין חיוב חודשי לשנתי.'
            ), 400 );
        }
        if ( $sub['plan_type'] === $new_plan ) {
            wp_send_json_error( array( 'message' => 'כבר נמצאים בחבילה זו.' ), 400 );
        }

        $new_pkg = CMMS_Plans::get_package( $new_plan, $new_cycle );
        if ( ! $new_pkg ) {
            wp_send_json_error( array( 'message' => 'החבילה אינה זמינה.' ), 404 );
        }
        $proration = CMMS_PlanChanges::calculate_proration( $sub, $new_pkg );

        // Route based on direction.
        if ( $proration['is_upgrade'] ) {
            $result = CMMS_PlanChanges::apply_upgrade(
                (int) $u->account_id, $new_plan, $new_cycle
            );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array(
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ), 502 );
            }
            wp_send_json_success( array(
                'mode'         => 'redirect_to_payment',
                'redirect_url' => $result['redirect_url'],
                'message'      => 'מעבירים אותך לדף תשלום מאובטח לחיוב ההפרש...',
            ) );
        }

        if ( $proration['is_downgrade'] ) {
            $result = CMMS_PlanChanges::schedule_downgrade(
                (int) $u->account_id, $new_plan, $new_cycle
            );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array(
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ), 400 );
            }
            $when = mysql2date( 'd/m/Y', $result['effective_at'] );
            wp_send_json_success( array(
                'mode'         => 'downgrade_scheduled',
                'effective_at' => $result['effective_at'],
                'message'      => 'השינוי לחבילה החדשה ייכנס לתוקף ב-' . $when . '. עד אז תמשיך/י להשתמש בחבילה הנוכחית.',
            ) );
        }

        wp_send_json_error( array( 'message' => 'לא נמצא שינוי תקף.' ), 400 );
    }

    /**
     * Cancel a previously-scheduled pending change (downgrade).
     */
    public function cmms_plan_change_cancel_pending() {
        check_ajax_referer( 'cmms_plan_change', 'nonce' );

        $u = CMMS_Auth::current_cmms_user();
        if ( ! $u ) {
            wp_send_json_error( array( 'message' => 'נדרשת התחברות.' ), 401 );
        }
        if ( $u->role !== CMMS_Auth::ROLE_OWNER ) {
            wp_send_json_error( array(
                'message' => 'רק בעלי החשבון יכולים לבטל שינויים.'
            ), 403 );
        }

        $result = CMMS_PlanChanges::cancel_pending_change( (int) $u->account_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
            ), 400 );
        }
        wp_send_json_success( array(
            'message' => 'השינוי המתוכנן בוטל. תמשיך/י עם החבילה הנוכחית.',
        ) );
    }

    /* ================================================================
       1.14.61: Forgot / Reset password endpoints.

       Security model:
         - cmms_forgot_password: rate-limited per IP (1 / 60s) to
           prevent enumeration / spam. Returns identical response
           whether the user exists or not — no info leak about
           which emails are registered.
         - cmms_reset_password: validates WP reset key (built-in
           one-time token), accepts new password, applies it via
           reset_password() — WP standard.

       We accept BOTH "email" and "username" because our login form
       does, and because some users won't remember their email.
       The user lookup chain: email_exists() → get_user_by('login').
    ================================================================ */

    public function cmms_forgot_password() {
        check_ajax_referer( 'cmms_forgot_password', 'nonce' );

        // Rate limit: 1 request per IP per 60s, mainly to prevent spam.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
        $rate_key = 'cmms_forgot_rl_' . md5( $ip );
        if ( get_transient( $rate_key ) ) {
            // Same response shape — don't tip off automation.
            wp_send_json_success( array(
                'message' => 'אם הכתובת קיימת במערכת, נשלח אליה קישור לאיפוס סיסמה תוך מספר דקות.',
            ) );
        }
        set_transient( $rate_key, 1, 60 );

        $identifier = isset( $_POST['identifier'] )
            ? sanitize_text_field( wp_unslash( $_POST['identifier'] ) )
            : '';
        $identifier = trim( $identifier );
        if ( $identifier === '' ) {
            wp_send_json_error( array(
                'message' => 'אנא הזן את כתובת האימייל.',
            ), 400 );
        }

        // Resolve to a WP user. Try email first (most common), then username.
        $user = false;
        if ( is_email( $identifier ) ) {
            $user = get_user_by( 'email', $identifier );
        }
        if ( ! $user ) {
            $user = get_user_by( 'login', $identifier );
        }

        // If not found OR not a CMMS user, we still return success to
        // not reveal which identifiers exist. Just log internally.
        $is_cmms_user = false;
        if ( $user ) {
            global $wpdb;
            $is_cmms_user = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM " . CMMS_DB::table( 'users' ) . " WHERE wp_user_id = %d",
                $user->ID
            ) );
        }

        if ( ! $user || ! $is_cmms_user ) {
            wp_send_json_success( array(
                'message' => 'אם הכתובת קיימת במערכת, נשלח אליה קישור לאיפוס סיסמה תוך מספר דקות.',
            ) );
        }

        // 1.14.62: User found but their email is missing or invalid.
        // This can happen when an admin created the user manually with
        // a placeholder email, or imported users from elsewhere. We
        // give a clear (slightly more revealing) message because at
        // this point the user has demonstrated they know a real login,
        // so info-leak protection is less important than guidance.
        if ( empty( $user->user_email ) || ! is_email( $user->user_email ) ) {
            wp_send_json_error( array(
                'message' => 'לחשבון זה אין כתובת אימייל רשומה ולכן לא ניתן לשלוח קישור איפוס. אנא פנה לתמיכה ב-guy@promoters.co.il כדי לקבל סיסמה חדשה.',
                'code'    => 'no_email',
            ), 400 );
        }

        // Generate reset key via WP's standard mechanism. This stores
        // a hashed token in the DB and returns the plaintext. The
        // token is single-use and expires after 24h (WP default).
        $key = get_password_reset_key( $user );
        if ( is_wp_error( $key ) ) {
            error_log( 'CMMS forgot password: get_password_reset_key failed for user ' . $user->ID . ': ' . $key->get_error_message() );
            // Still return success to user (avoid info leak).
            wp_send_json_success( array(
                'message' => 'אם הכתובת קיימת במערכת, נשלח אליה קישור לאיפוס סיסמה תוך מספר דקות.',
            ) );
        }

        // Build the reset URL pointing at OUR /cmms-reset/ page (not
        // the wp-login.php default). The user-visible flow stays
        // entirely on the CMMS-branded site.
        $reset_url = home_url( '/cmms-reset/?key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ) );

        // Send via the email-template system so admin can edit the
        // copy from the Email Templates page.
        $vars = array(
            'FULL_NAME'     => $user->display_name ?: $user->user_login,
            'COMPANY_NAME'  => '',
            'USER_EMAIL'    => $user->user_email,
            'RESET_URL'     => $reset_url,
            'DASHBOARD_URL' => home_url( '/cmms-dashboard/' ),
            'SUPPORT_EMAIL' => 'guy@promoters.co.il',
            'SITE_URL'      => home_url( '/' ),
        );
        // Try to look up company name from the user's CMMS account.
        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $users_t    = CMMS_DB::table( 'users' );
        $company = $wpdb->get_var( $wpdb->prepare(
            "SELECT a.name FROM $accounts_t a
              JOIN $users_t cu ON cu.account_id = a.id
              WHERE cu.wp_user_id = %d LIMIT 1",
            $user->ID
        ) );
        if ( $company ) $vars['COMPANY_NAME'] = $company;

        CMMS_Mailer::send_templated( 'password_reset', $user->user_email, $vars );

        wp_send_json_success( array(
            'message' => 'אם הכתובת קיימת במערכת, נשלח אליה קישור לאיפוס סיסמה תוך מספר דקות.',
        ) );
    }

    public function cmms_reset_password() {
        check_ajax_referer( 'cmms_reset_password', 'nonce' );

        $key   = isset( $_POST['key'] )   ? sanitize_text_field( wp_unslash( $_POST['key'] ) )   : '';
        $login = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) )       : '';
        $pwd1  = isset( $_POST['password1'] ) ? (string) wp_unslash( $_POST['password1'] ) : '';
        $pwd2  = isset( $_POST['password2'] ) ? (string) wp_unslash( $_POST['password2'] ) : '';

        if ( $key === '' || $login === '' ) {
            wp_send_json_error( array( 'message' => 'הקישור אינו תקין. אנא בקש קישור חדש.' ), 400 );
        }
        if ( strlen( $pwd1 ) < 6 ) {
            wp_send_json_error( array( 'message' => 'הסיסמה חייבת להיות לפחות 6 תווים.' ), 400 );
        }
        if ( $pwd1 !== $pwd2 ) {
            wp_send_json_error( array( 'message' => 'הסיסמאות אינן זהות.' ), 400 );
        }

        // Validate the reset key. WP's check_password_reset_key
        // verifies both the key and the login, and returns either a
        // WP_User or a WP_Error. The key is single-use — successful
        // reset_password() below clears it.
        $user = check_password_reset_key( $key, $login );
        if ( is_wp_error( $user ) ) {
            wp_send_json_error( array(
                'message' => 'הקישור פג תוקף או שכבר נעשה בו שימוש. אנא בקש קישור חדש.',
            ), 400 );
        }

        // Apply the new password. WP handles hashing, cache clear,
        // and invalidating other sessions.
        reset_password( $user, $pwd1 );

        wp_send_json_success( array(
            'message'      => 'הסיסמה עודכנה. אפשר להתחבר עם הסיסמה החדשה.',
            'redirect_url' => home_url( '/cmms-login/' ),
        ) );
    }
}
