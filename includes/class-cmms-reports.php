<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Reports {

    /**
     * KPI dashboard metrics (1.14.24).
     *
     * Returns 8 operational indicators for the reports page header:
     *   - open_count, overdue_count: snapshot of current backlog
     *   - completed_this_week: throughput indicator
     *   - avg_response_minutes: average minutes from creation to first
     *     transition out of 'open' (started_at - created_at). Computed
     *     only over tasks where started_at is set, so legacy rows that
     *     pre-date 1.14.23 are excluded.
     *   - avg_closure_hours: average hours from creation to completion
     *     (completed_at - created_at). Same caveat as above.
     *   - sla_compliance_pct: of completed tasks that HAD a due_date
     *     set, what % were completed by the due_date. Tasks without a
     *     due_date are excluded from both numerator and denominator —
     *     better to say "not measured" than fake a number.
     *   - top_tech_overloaded: cmms_user with highest open_count
     *   - top_problem_asset: asset with most tasks total in window
     *
     * Each metric returns a structured shape so the view can render
     * gracefully even when there is no data (new accounts, empty
     * windows, etc.) — the value field is null in that case.
     *
     * Date scoping: respects the same TimeRange contract as
     * dashboard_stats. Open/overdue counts are always "now" — they
     * don't make sense in a historical window. Completion-based
     * metrics use the window.
     */
    public static function kpi_dashboard( $account_id, $range = null ) {
        global $wpdb;
        $tasks_t  = CMMS_DB::table( 'tasks' );
        $users_t  = CMMS_DB::table( 'users' );
        $assets_t = CMMS_DB::table( 'assets' );
        $account_id = (int) $account_id;

        // ---- snapshot metrics (always "as of now", ignore range) ----
        $open_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $tasks_t
              WHERE account_id = %d AND status IN ('open','in_progress','waiting')",
            $account_id
        ) );

        // Overdue = past due_date AND still in a non-final state. We
        // intentionally don't count due-date-less tasks as overdue here;
        // SLA without a deadline is not measurable.
        $overdue_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $tasks_t
              WHERE account_id = %d
                AND status IN ('open','in_progress','waiting')
                AND due_date IS NOT NULL
                AND due_date < %s",
            $account_id, current_time( 'mysql' )
        ) );

        // ---- throughput: completed in the last 7 days ----
        // Uses completed_at — populated by the lifecycle code from
        // 1.14.23. Legacy rows without completed_at are silently
        // skipped, which is correct (we can't know when they finished).
        $week_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days', current_time( 'timestamp' ) ) );
        $completed_this_week = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $tasks_t
              WHERE account_id = %d
                AND completed_at IS NOT NULL
                AND completed_at >= %s",
            $account_id, $week_ago
        ) );

        // ---- response time avg (creation -> started_at) ----
        // TIMESTAMPDIFF returns minutes here. We only include tasks
        // where started_at is non-null. AVG returns null on empty set,
        // which we coerce to null below.
        $avg_response_minutes = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, started_at))
               FROM $tasks_t
              WHERE account_id = %d AND started_at IS NOT NULL",
            $account_id
        ) );
        $avg_response_minutes = $avg_response_minutes !== null
            ? (int) round( (float) $avg_response_minutes )
            : null;

        // ---- closure time avg (creation -> completed_at) ----
        // In hours; for very long-running tasks minutes get unwieldy.
        $avg_closure_hours = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at))
               FROM $tasks_t
              WHERE account_id = %d AND completed_at IS NOT NULL",
            $account_id
        ) );
        $avg_closure_hours = $avg_closure_hours !== null
            ? round( (float) $avg_closure_hours, 1 )
            : null;

        // ---- SLA compliance % (using due_date as the SLA target) ----
        // Numerator: completed tasks where completed_at <= due_date.
        // Denominator: completed tasks that HAD a due_date set.
        // If denominator is 0 we return null so the UI says "not measured"
        // rather than misleading 0% or 100%.
        $sla_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN completed_at <= due_date THEN 1 ELSE 0 END) AS on_time,
                COUNT(*) AS measured
               FROM $tasks_t
              WHERE account_id = %d
                AND completed_at IS NOT NULL
                AND due_date IS NOT NULL",
            $account_id
        ) );
        $sla_compliance_pct = ( $sla_row && (int) $sla_row->measured > 0 )
            ? (int) round( ( (int) $sla_row->on_time / (int) $sla_row->measured ) * 100 )
            : null;
        $sla_measured_count = $sla_row ? (int) $sla_row->measured : 0;

        // ---- most overloaded technician (by open count) ----
        $top_tech = $wpdb->get_row( $wpdb->prepare(
            "SELECT u.id, u.display_name, COUNT(t.id) AS open_count
               FROM $users_t u
               INNER JOIN $tasks_t t ON t.assigned_to = u.id
              WHERE u.account_id = %d
                AND t.status IN ('open','in_progress','waiting')
              GROUP BY u.id, u.display_name
              ORDER BY open_count DESC
              LIMIT 1",
            $account_id
        ) );

        // ---- most problematic asset (by total tasks in window) ----
        // We use total tasks rather than just open ones, because an
        // asset that generated 20 tasks (most now closed) is still the
        // problem child. The window from the time-range picker scopes
        // this so a long-replaced asset doesn't dominate forever.
        $window_clause = '';
        $window_args = array( $account_id );
        if ( $range && ! empty( $range['from'] ) ) {
            $window_clause = " AND t.created_at >= %s";
            $window_args[] = $range['from'];
        }
        if ( $range && ! empty( $range['to'] ) ) {
            $window_clause .= " AND t.created_at <= %s";
            $window_args[] = $range['to'];
        }
        $top_asset = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.name, COUNT(t.id) AS task_count
               FROM $assets_t a
               INNER JOIN $tasks_t t ON t.asset_id = a.id
              WHERE a.account_id = %d $window_clause
              GROUP BY a.id, a.name
              ORDER BY task_count DESC
              LIMIT 1",
            $window_args
        ) );

        return array(
            'open_count'           => $open_count,
            'overdue_count'        => $overdue_count,
            'completed_this_week'  => $completed_this_week,
            'avg_response_minutes' => $avg_response_minutes,
            'avg_closure_hours'    => $avg_closure_hours,
            'sla_compliance_pct'   => $sla_compliance_pct,
            'sla_measured_count'   => $sla_measured_count,
            'top_tech'             => $top_tech,
            'top_asset'            => $top_asset,
        );
    }

    public static function dashboard_stats( $account_id, $range = null ) {
        global $wpdb;
        $tasks_t = CMMS_DB::table( 'tasks' );
        $users_t = CMMS_DB::table( 'users' );
        $cats_t  = CMMS_DB::table( 'categories' );

        $counts = CMMS_Tasks::counts_for_account( $account_id, $range );

        // Build optional date scoping for the JOINed task queries.
        $task_where = '';
        $extra_args = array();
        if ( $range && ( ! empty( $range['from'] ) || ! empty( $range['to'] ) ) ) {
            $field = ( in_array( $range['key'] ?? '', array( 'next_month', 'next_quarter' ), true ) ) ? 'due_date' : 'created_at';
            if ( ! empty( $range['from'] ) ) {
                $task_where .= " AND t.$field >= %s";
                $extra_args[] = $range['from'];
            }
            if ( ! empty( $range['to'] ) ) {
                $task_where .= " AND t.$field <= %s";
                $extra_args[] = $range['to'];
            }
        }

        $by_tech = $wpdb->get_results( $wpdb->prepare(
            "SELECT u.id, u.display_name,
                SUM(CASE WHEN t.status IN ('open','in_progress','waiting') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN t.status IN ('completed','closed') THEN 1 ELSE 0 END) AS completed_count,
                COUNT(t.id) AS total_count
            FROM $users_t u
            LEFT JOIN $tasks_t t ON t.assigned_to = u.id $task_where
            WHERE u.account_id = %d AND u.role = %s
            GROUP BY u.id, u.display_name
            ORDER BY u.display_name ASC",
            array_merge( $extra_args, array( $account_id, CMMS_Auth::ROLE_TECHNICIAN ) )
        ) );

        $by_cat = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, c.name,
                SUM(CASE WHEN t.status IN ('open','in_progress','waiting') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN t.status IN ('completed','closed') THEN 1 ELSE 0 END) AS completed_count,
                COUNT(t.id) AS total_count
            FROM $cats_t c
            LEFT JOIN $tasks_t t ON t.category_id = c.id $task_where
            WHERE c.account_id = %d
            GROUP BY c.id, c.name
            ORDER BY c.name ASC",
            array_merge( $extra_args, array( $account_id ) )
        ) );

        return array(
            'counts'  => $counts,
            'by_tech' => $by_tech,
            'by_cat'  => $by_cat,
        );
    }
}
