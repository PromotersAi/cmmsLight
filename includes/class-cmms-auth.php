<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Auth {

    const ROLE_OWNER      = 'owner';
    const ROLE_MANAGER    = 'manager';
    const ROLE_TECHNICIAN = 'technician';
    const ROLE_REPORTER   = 'reporter';

    /**
     * Returns the current cmms_user row ONLY if both the user and their account are active.
     * Returns null otherwise. This is the single source of truth for "who is the logged-in CMMS user?".
     */
    public static function current_cmms_user() {
        if ( ! is_user_logged_in() ) {
            return null;
        }
        global $wpdb;
        $wp_user_id = get_current_user_id();
        $users_t    = CMMS_DB::table( 'users' );
        $accts_t    = CMMS_DB::table( 'accounts' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT u.* FROM $users_t u
             INNER JOIN $accts_t a ON a.id = u.account_id
             WHERE u.wp_user_id = %d
               AND u.status = 'active'
               AND a.status = 'active'
             LIMIT 1",
            $wp_user_id
        ) );
        return $row ? $row : null;
    }

    /**
     * Raw lookup ignoring active status - for global-admin tooling only.
     */
    public static function current_cmms_user_raw() {
        if ( ! is_user_logged_in() ) {
            return null;
        }
        global $wpdb;
        $wp_user_id = get_current_user_id();
        $users_t    = CMMS_DB::table( 'users' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $users_t WHERE wp_user_id = %d LIMIT 1",
            $wp_user_id
        ) );
        return $row ? $row : null;
    }

    public static function current_account() {
        $user = self::current_cmms_user();
        if ( ! $user ) return null;
        global $wpdb;
        $table = CMMS_DB::table( 'accounts' );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND status = 'active'",
            $user->account_id
        ) );
    }

    public static function is_global_admin() {
        return is_user_logged_in() && current_user_can( 'manage_options' );
    }

    /**
     * Hard gate: logged in AND has active cmms_user in active account (or is global admin).
     */
    public static function require_login() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( home_url( '/cmms-login' ) );
            exit;
        }
        if ( ! self::is_global_admin() && ! self::current_cmms_user() ) {
            wp_safe_redirect( home_url( '/cmms-login?reason=inactive' ) );
            exit;
        }
    }

    public static function role_label( $role ) {
        $map = array(
            self::ROLE_OWNER      => __( 'Account Owner', 'cmms-light' ),
            self::ROLE_MANAGER    => __( 'Manager', 'cmms-light' ),
            self::ROLE_TECHNICIAN => __( 'Technician', 'cmms-light' ),
            self::ROLE_REPORTER   => __( 'Reporter', 'cmms-light' ),
        );
        return isset( $map[ $role ] ) ? $map[ $role ] : ucfirst( (string) $role );
    }

    public static function can( $action, $context = array() ) {
        if ( self::is_global_admin() ) return true;
        $user = self::current_cmms_user();
        if ( ! $user ) return false;

        $role = $user->role;

        switch ( $action ) {
            case 'manage_account':
            case 'manage_users':
            case 'manage_categories':
            case 'manage_forms':
            case 'manage_assets':
            case 'view_reports':
            case 'view_all_tasks':
            case 'edit_task':
                return in_array( $role, array( self::ROLE_OWNER, self::ROLE_MANAGER ), true );

            case 'create_task':
                return in_array( $role, array( self::ROLE_OWNER, self::ROLE_MANAGER, self::ROLE_REPORTER, self::ROLE_TECHNICIAN ), true );

            case 'update_task_status':
                return in_array( $role, array( self::ROLE_OWNER, self::ROLE_MANAGER, self::ROLE_TECHNICIAN ), true );

            default:
                return false;
        }
    }

    public static function ensure_active_account( $user_row ) {
        if ( ! $user_row ) return false;
        global $wpdb;
        $table = CMMS_DB::table( 'accounts' );
        $account = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $user_row->account_id
        ) );
        return ( $account && $account->status === 'active' );
    }

    /**
     * Tenant isolation: returns true if the given account_id belongs to the current user
     * (or the caller is global admin). Use on EVERY object load to prevent cross-tenant access.
     */
    public static function owns( $object_account_id ) {
        if ( self::is_global_admin() ) return true;
        $user = self::current_cmms_user();
        if ( ! $user ) return false;
        return ( (int) $object_account_id === (int) $user->account_id );
    }

    /* ============================================================
     * Task-level permission helpers (1.14.19)
     *
     * Three distinct levels, in increasing power:
     *   can_view_task        — can SEE the task at all (lists, detail, search)
     *   can_participate_task — can comment, attach files, change status
     *   can_edit_task        — can change title/description/assignments/dates
     *
     * Every site that loads or acts on a task should call exactly one of
     * these — never re-implement the rules locally. The rules below ARE
     * the single source of truth.
     *
     * All three return false for cross-tenant access. Tenant isolation
     * is non-negotiable; nothing inside an account exposes another's data.
     *
     * `$user` may be a cmms_users row (object), a cmms_user id (int), or
     * null/false (returns false). `$task` may be a tasks row (object) or
     * a tasks id (int); a missing task always returns false.
     * ============================================================ */

    /**
     * Resolve a user argument into a cmms_users row, or null if invalid.
     * Accepts: object (passes through), int id, null. Internal helper.
     */
    private static function _resolve_user( $user ) {
        if ( is_object( $user ) ) return $user;
        if ( is_numeric( $user ) && (int) $user > 0 ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM " . CMMS_DB::table( 'users' ) . " WHERE id = %d LIMIT 1",
                (int) $user
            ) );
            return $row ?: null;
        }
        return null;
    }

    /**
     * Resolve a task argument. Accepts object or id. Returns null if missing.
     */
    private static function _resolve_task( $task ) {
        if ( is_object( $task ) ) return $task;
        if ( is_numeric( $task ) && (int) $task > 0 && class_exists( 'CMMS_Tasks' ) ) {
            $row = CMMS_Tasks::get( (int) $task );
            return $row ?: null;
        }
        return null;
    }

    /**
     * Is this user one of the task's "related users" — assignee, manager,
     * creator, or (future) a watcher? Tenant check is enforced first.
     *
     * Other plugins (or future watchers feature) can extend this list via
     * the 'cmms_task_related_users' filter — receives an array of cmms
     * user ids and the task object, returns the (possibly extended) list.
     *
     * Returns false on tenant mismatch or missing args. Owners/managers
     * are NOT auto-related here — that broader access is in can_view_task.
     */
    public static function is_user_related_to_task( $user, $task ) {
        $user = self::_resolve_user( $user );
        $task = self::_resolve_task( $task );
        if ( ! $user || ! $task ) return false;
        if ( (int) $user->account_id !== (int) $task->account_id ) return false;

        $uid = (int) $user->id;
        $related = array_filter( array(
            (int) ( $task->assigned_to ?? 0 ),
            (int) ( $task->manager_id  ?? 0 ),
            (int) ( $task->created_by  ?? 0 ),
        ) );
        /**
         * Extend the set of users considered "related" to a task.
         * Future watchers feature will hook in here. Filter receives the
         * current list of cmms user ids and the task row.
         */
        $related = (array) apply_filters( 'cmms_task_related_users', $related, $task );
        $related = array_map( 'intval', $related );

        return in_array( $uid, $related, true );
    }

    /**
     * Returns the deduped list of cmms user ids related to a task.
     * Used by the notification recipient resolver (1.14.20+) and any
     * other code that needs "everyone who should know about this task".
     */
    public static function task_related_user_ids( $task ) {
        $task = self::_resolve_task( $task );
        if ( ! $task ) return array();
        $related = array_filter( array(
            (int) ( $task->assigned_to ?? 0 ),
            (int) ( $task->manager_id  ?? 0 ),
            (int) ( $task->created_by  ?? 0 ),
        ) );
        $related = (array) apply_filters( 'cmms_task_related_users', $related, $task );
        $related = array_unique( array_map( 'intval', $related ) );
        $related = array_values( array_filter( $related, function( $id ) { return $id > 0; } ) );
        return $related;
    }

    /**
     * CAN VIEW: can the user see this task at all?
     *
     * Rules:
     *   - Cross-tenant: never.
     *   - Owners and managers see everything in their account (they run it).
     *   - Anyone else must be related to the task (assignee/manager/creator/watcher).
     *
     * If a task has no assignee/manager yet, the creator can still see and
     * work it — that's covered because creator is in the related set.
     */
    public static function can_view_task( $user, $task ) {
        $user = self::_resolve_user( $user );
        $task = self::_resolve_task( $task );
        if ( ! $user || ! $task ) return false;
        if ( (int) $user->account_id !== (int) $task->account_id ) return false;

        if ( in_array( $user->role, array( self::ROLE_OWNER, self::ROLE_MANAGER ), true ) ) {
            return true;
        }
        return self::is_user_related_to_task( $user, $task );
    }

    /**
     * CAN EDIT: can the user change task metadata (title, description,
     * assignments, dates, recurrence)?
     *
     * Rules:
     *   - Must can_view_task first.
     *   - Owners and managers: yes.
     *   - The task creator: yes (so reporters/technicians can clean up
     *     a task they filed before assignment is decided).
     *   - Everyone else: no — even the assignee can't rewrite the brief.
     */
    public static function can_edit_task( $user, $task ) {
        $user = self::_resolve_user( $user );
        $task = self::_resolve_task( $task );
        if ( ! $user || ! $task ) return false;
        if ( ! self::can_view_task( $user, $task ) ) return false;

        if ( in_array( $user->role, array( self::ROLE_OWNER, self::ROLE_MANAGER ), true ) ) {
            return true;
        }
        if ( (int) $task->created_by === (int) $user->id ) return true;

        return false;
    }

    /**
     * CAN PARTICIPATE: can the user actively contribute to the task —
     * comment, upload attachments, change status?
     *
     * Rules:
     *   - Must can_view_task first.
     *   - Anyone with can_edit_task: yes (edit > participate).
     *   - The assignee: yes (a technician working the task must be able
     *     to update status and post photos from the field, even though
     *     they can't rewrite the title).
     *   - Anyone else with view-only access: no — viewing alone doesn't
     *     grant the right to push comments/files into the record.
     */
    public static function can_participate_task( $user, $task ) {
        $user = self::_resolve_user( $user );
        $task = self::_resolve_task( $task );
        if ( ! $user || ! $task ) return false;
        if ( ! self::can_view_task( $user, $task ) ) return false;

        if ( self::can_edit_task( $user, $task ) ) return true;
        if ( (int) ( $task->assigned_to ?? 0 ) === (int) $user->id ) return true;

        return false;
    }
}
