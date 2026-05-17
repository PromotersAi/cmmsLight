<?php
/**
 * Setup Checklist (1.14.45).
 *
 * Computes the state of the new-account onboarding checklist and
 * renders it into the dashboard home view.
 *
 * Steps (in order):
 *   1. invite_users — Auto: completed when account has > 1 user
 *   2. create_asset — Auto: completed when account has >= 1 asset
 *   3. create_task  — Auto: completed when account has >= 1 task
 *   4. install_app  — Manual: user clicks "I did this" or installs PWA
 *
 * Dismissal:
 *   - Auto-hide when all steps completed
 *   - Manual hide via "Don't show again" button (saves checklist_dismissed_at)
 *
 * Architecture notes:
 *   - Status is computed live on each page load (cheap — 3 COUNT queries).
 *     We do NOT cache; an account that just created a task should see
 *     the step flip to ✓ immediately on next dashboard load.
 *   - Manual flags stored as JSON in accounts.checklist_manual_flags.
 *     Currently only install_app uses this, but the schema is generic
 *     so we can add more manual steps later without DB changes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Checklist {

    /** Step identifiers — stable across versions, used as JSON keys. */
    const STEP_INVITE_USERS = 'invite_users';
    const STEP_CREATE_ASSET = 'create_asset';
    const STEP_CREATE_TASK  = 'create_task';
    const STEP_INSTALL_APP  = 'install_app';

    /**
     * Should the checklist be shown for this account?
     * Returns false when:
     *   - account dismissed it explicitly
     *   - all steps are already complete (no point showing 100% checklist)
     */
    public static function should_show( $account_id ) {
        $account_id = (int) $account_id;
        if ( ! $account_id ) return false;

        $account = self::get_account( $account_id );
        if ( ! $account ) return false;
        if ( ! empty( $account['checklist_dismissed_at'] ) ) return false;

        $steps = self::compute_steps( $account_id, $account );
        $all_done = true;
        foreach ( $steps as $s ) {
            if ( ! $s['done'] ) { $all_done = false; break; }
        }
        return ! $all_done;
    }

    /**
     * Return all steps with their current completion state.
     * Each step has: key, title, description, cta_label, cta_url, done.
     */
    public static function get_steps( $account_id ) {
        $account = self::get_account( $account_id );
        if ( ! $account ) return array();
        return self::compute_steps( (int) $account_id, $account );
    }

    /**
     * Mark a manual step as complete (e.g. PWA install).
     * Only manual steps are affected — auto steps ignore manual flags.
     */
    public static function mark_manual( $account_id, $step_key ) {
        $account_id = (int) $account_id;
        if ( ! $account_id ) return false;
        $allowed = array( self::STEP_INSTALL_APP );
        if ( ! in_array( $step_key, $allowed, true ) ) return false;

        $account = self::get_account( $account_id );
        if ( ! $account ) return false;

        $flags = self::decode_flags( $account['checklist_manual_flags'] ?? '' );
        $flags[ $step_key ] = '1';

        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $wpdb->update(
            $accounts_t,
            array( 'checklist_manual_flags' => wp_json_encode( $flags ) ),
            array( 'id' => $account_id ),
            array( '%s' ),
            array( '%d' )
        );
        return true;
    }

    /** Dismiss the checklist permanently for this account. */
    public static function dismiss( $account_id ) {
        $account_id = (int) $account_id;
        if ( ! $account_id ) return false;
        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $wpdb->update(
            $accounts_t,
            array( 'checklist_dismissed_at' => current_time( 'mysql' ) ),
            array( 'id' => $account_id ),
            array( '%s' ),
            array( '%d' )
        );
        return true;
    }

    /* ============================================================
       Internals
    ============================================================ */

    private static function get_account( $account_id ) {
        global $wpdb;
        $t = CMMS_DB::table( 'accounts' );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, checklist_dismissed_at, checklist_manual_flags FROM $t WHERE id = %d",
            (int) $account_id
        ), ARRAY_A );
    }

    /**
     * Compute the live state of every step. Each step's `done` is a
     * boolean derived from either DB counts (auto) or the manual flags
     * JSON (manual). The order returned here is the display order.
     */
    private static function compute_steps( $account_id, $account_row ) {
        global $wpdb;
        $users_t  = CMMS_DB::table( 'users' );
        $assets_t = CMMS_DB::table( 'assets' );
        $tasks_t  = CMMS_DB::table( 'tasks' );

        // Auto signals — single COUNT queries, very cheap.
        // We treat "more than 1 user" as completion of invite_users
        // because every account starts with the owner; an extra user
        // means an actual invitation happened.
        $user_count  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $users_t WHERE account_id = %d",
            $account_id
        ) );
        $asset_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $assets_t WHERE account_id = %d",
            $account_id
        ) );
        $task_count  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $tasks_t WHERE account_id = %d",
            $account_id
        ) );

        $flags = self::decode_flags( $account_row['checklist_manual_flags'] ?? '' );
        $install_done = ! empty( $flags[ self::STEP_INSTALL_APP ] );

        // CTA URLs — relative to the dashboard so the routing flag (?view=)
        // takes the user straight to the relevant screen.
        $dashboard = home_url( '/cmms-dashboard/' );

        return array(
            array(
                'key'         => self::STEP_INVITE_USERS,
                'title'       => 'הזמן משתמשים לצוות',
                'description' => 'הוסף עוד אנשים מהארגון שיעבדו במערכת.',
                'cta_label'   => 'ניהול משתמשים',
                'cta_url'     => add_query_arg( 'view', 'users', $dashboard ),
                'done'        => $user_count > 1,
                'auto'        => true,
            ),
            array(
                'key'         => self::STEP_CREATE_ASSET,
                'title'       => 'צור נכס / מכונה',
                'description' => 'הוסף את הנכסים שתרצה לתחזק במערכת.',
                'cta_label'   => 'הוסף נכס',
                'cta_url'     => add_query_arg( 'view', 'assets', $dashboard ),
                'done'        => $asset_count > 0,
                'auto'        => true,
            ),
            array(
                'key'         => self::STEP_CREATE_TASK,
                'title'       => 'צור משימת אחזקה ראשונה',
                'description' => 'התחל לעבוד — צור משימה ראשונה לעצמך או לצוות.',
                'cta_label'   => 'צור משימה',
                'cta_url'     => add_query_arg( 'view', 'task_new', $dashboard ),
                'done'        => $task_count > 0,
                'auto'        => true,
            ),
            array(
                'key'         => self::STEP_INSTALL_APP,
                'title'       => 'הורד את האפליקציה למובייל',
                'description' => 'התקן את האפליקציה לעבודה נוחה מהטלפון.',
                'cta_label'   => 'הוראות התקנה',
                'cta_url'     => '#cmms-install-banner', // scrolls to existing banner
                'done'        => $install_done,
                'auto'        => false,
                'manual'      => true,
            ),
        );
    }

    /**
     * Tolerant JSON decoder. Returns an assoc array (possibly empty).
     */
    private static function decode_flags( $raw ) {
        if ( empty( $raw ) ) return array();
        if ( is_array( $raw ) ) return $raw;
        $decoded = json_decode( (string) $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
