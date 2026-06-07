<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Tasks {

    public static function statuses() {
        return array(
            'open'        => __( 'Open', 'cmms-light' ),
            'in_progress' => __( 'In Progress', 'cmms-light' ),
            'waiting'     => __( 'Waiting', 'cmms-light' ),
            'completed'   => __( 'Completed', 'cmms-light' ),
        );
    }

    /**
     * Statuses considered "done". Includes the legacy 'closed' value so that
     * existing rows from older versions still count correctly.
     */
    public static function done_statuses() {
        return array( 'completed', 'closed' );
    }

    public static function priorities() {
        return array(
            'low'      => __( 'Low', 'cmms-light' ),
            'normal'   => __( 'Normal', 'cmms-light' ),
            'high'     => __( 'High', 'cmms-light' ),
            'urgent'   => __( 'Urgent', 'cmms-light' ),
        );
    }

    public static function recurrence_types() {
        return array(
            'one_time' => __( 'One-time', 'cmms-light' ),
            'weekly'   => __( 'Weekly', 'cmms-light' ),
            'monthly'  => __( 'Monthly', 'cmms-light' ),
            'every_x'  => __( 'Every X days', 'cmms-light' ),
        );
    }

    public static function create( $account_id, $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'tasks' );
        $account_id = intval( $account_id );

        $title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
        if ( empty( $title ) ) return new WP_Error( 'invalid', __( 'Title required', 'cmms-light' ) );

        $status = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'open';
        if ( ! array_key_exists( $status, self::statuses() ) ) $status = 'open';

        $priority = isset( $data['priority'] ) ? sanitize_text_field( $data['priority'] ) : 'normal';
        if ( ! array_key_exists( $priority, self::priorities() ) ) $priority = 'normal';

        $rec_type = isset( $data['recurrence_type'] ) ? sanitize_text_field( $data['recurrence_type'] ) : 'one_time';
        if ( ! array_key_exists( $rec_type, self::recurrence_types() ) ) $rec_type = 'one_time';

        $rec_int = isset( $data['recurrence_interval'] ) ? intval( $data['recurrence_interval'] ) : 0;

        $due = isset( $data['due_date'] ) && $data['due_date'] ? self::sanitize_datetime( $data['due_date'] ) : null;

        // Recurring tasks MUST have an end date — otherwise we'd generate
        // infinite future occurrences. We require both due_date AND
        // recurrence_until for any non-one-time task.
        $rec_until = isset( $data['recurrence_until'] ) && $data['recurrence_until'] ? self::sanitize_datetime( $data['recurrence_until'] ) : null;
        if ( $rec_type !== 'one_time' ) {
            if ( ! $due ) {
                return new WP_Error( 'invalid', __( 'A recurring task needs a target date — that date will also be the first occurrence. Please pick a "Target date".', 'cmms-light' ) );
            }
            if ( ! $rec_until ) {
                return new WP_Error( 'invalid', __( 'A recurring task needs an end date so the system knows when to stop creating copies.', 'cmms-light' ) );
            }
            if ( strtotime( $rec_until ) < strtotime( $due ) ) {
                return new WP_Error( 'invalid', __( 'The recurrence end date must be after the target date.', 'cmms-light' ) );
            }
        }

        // Cross-tenant FK guard: drop any reference whose target row belongs to a
        // different account. Defense in depth on top of the AJAX-layer checks.
        $category_id = self::scope_fk( $data, 'category_id', 'categories', $account_id );
        $asset_id    = self::scope_fk( $data, 'asset_id',    'assets',     $account_id );
        $manager_id  = self::scope_fk( $data, 'manager_id',  'users',      $account_id );
        $assigned_to = self::scope_fk( $data, 'assigned_to', 'users',      $account_id );

        $now = current_time( 'mysql' );

        $insert = array(
            'account_id'          => $account_id,
            'title'               => $title,
            'description'         => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
            'category_id'         => $category_id,
            'asset_id'            => $asset_id,
            'manager_id'          => $manager_id,
            'assigned_to'         => $assigned_to,
            'status'              => $status,
            'priority'            => $priority,
            'due_date'            => $due,
            'recurrence_type'     => $rec_type,
            'recurrence_interval' => $rec_int,
            'recurrence_until'    => $rec_until,
            'next_run'            => $rec_type !== 'one_time' ? $due : null,
            'source'              => isset( $data['source'] ) ? sanitize_text_field( $data['source'] ) : 'internal',
            'external_data'       => isset( $data['external_data'] ) ? wp_json_encode( $data['external_data'] ) : null,
            'created_by'          => isset( $data['created_by'] ) ? intval( $data['created_by'] ) : null,
            'created_at'          => $now,
            'updated_at'          => $now,
        );

        $wpdb->insert( $table, $insert );
        $id = $wpdb->insert_id;

        if ( $id ) {
            self::log( $id, $account_id, $insert['created_by'], 'created', __( 'Task created', 'cmms-light' ) );
            /**
             * Fires when a task is created. Notification system uses this to
             * email the assignee+manager and schedule reminders.
             *
             * @param int   $id      Task id.
             * @param array $context Original input data (incl. created_by).
             */
            do_action( 'cmms_task_created', (int) $id, $insert );
        }
        return $id;
    }

    /**
     * Returns intval(data[key]) only if it's a row in the named table belonging
     * to the given account. Otherwise returns null. Prevents tenant A from
     * pointing a task at tenant B's category/asset/user.
     */
    private static function scope_fk( $data, $key, $table_key, $account_id ) {
        if ( empty( $data[ $key ] ) ) return null;
        global $wpdb;
        $id = intval( $data[ $key ] );
        if ( $id <= 0 ) return null;
        $table = CMMS_DB::table( $table_key );
        if ( ! $table ) return null;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT account_id FROM $table WHERE id = %d LIMIT 1",
            $id
        ) );
        if ( ! $row || (int) $row->account_id !== (int) $account_id ) return null;
        return $id;
    }

    public static function update( $id, $data, $actor_user_id = null ) {
        global $wpdb;
        $table = CMMS_DB::table( 'tasks' );
        $task = self::get( $id );
        if ( ! $task ) return false;

        $update = array();
        $format = array();

        $editable = array(
            'title' => '%s', 'description' => '%s', 'category_id' => '%d', 'asset_id' => '%d',
            'manager_id' => '%d', 'assigned_to' => '%d', 'status' => '%s', 'priority' => '%s',
            'due_date' => '%s', 'recurrence_type' => '%s', 'recurrence_interval' => '%d',
            'recurrence_until' => '%s',
        );

        foreach ( $editable as $field => $fmt ) {
            if ( ! array_key_exists( $field, $data ) ) continue;
            $val = $data[ $field ];
            if ( $field === 'title' ) $val = sanitize_text_field( $val );
            elseif ( $field === 'description' ) $val = wp_kses_post( $val );
            elseif ( $field === 'status' && ! array_key_exists( $val, self::statuses() ) ) continue;
            elseif ( $field === 'priority' && ! array_key_exists( $val, self::priorities() ) ) continue;
            elseif ( $field === 'recurrence_type' && ! array_key_exists( $val, self::recurrence_types() ) ) continue;
            elseif ( $field === 'due_date' ) $val = $val ? self::sanitize_datetime( $val ) : null;
            elseif ( $field === 'recurrence_until' ) $val = $val ? self::sanitize_datetime( $val ) : null;
            elseif ( in_array( $fmt, array( '%d' ), true ) ) $val = $val !== '' && $val !== null ? intval( $val ) : null;

            // Cross-tenant FK guard.
            if ( $val && in_array( $field, array( 'category_id', 'asset_id', 'manager_id', 'assigned_to' ), true ) ) {
                $fk_table = ( $field === 'category_id' ) ? 'categories'
                          : ( ( $field === 'asset_id' ) ? 'assets' : 'users' );
                $val = self::scope_fk( array( $field => $val ), $field, $fk_table, $task->account_id );
            }

            $update[ $field ] = $val;
            $format[] = $fmt;
        }

        if ( empty( $update ) ) return false;

        // Lifecycle timestamp tracking (1.14.23). The two columns are:
        //   started_at   — when the task first transitioned out of "open"
        //                  into a working/closed state. Set ONCE; never
        //                  overwritten. Even if a task moves through
        //                  multiple in_progress/waiting cycles or gets
        //                  reopened, started_at preserves the original
        //                  first-touch moment for response-time math.
        //   completed_at — when the task most recently entered a final
        //                  state (completed or closed). Cleared if the
        //                  task gets reopened. Re-set on next completion.
        //
        // We treat both 'completed' AND 'closed' as completion states so
        // closure-time reports are not misled by accounts that prefer
        // 'closed' as their finishing status.
        if ( isset( $update['status'] ) && $update['status'] !== $task->status ) {
            $new = $update['status'];
            $old = $task->status;

            // started_at — first time leaving 'open'. Once set, never touch.
            if ( empty( $task->started_at ) && $new !== 'open' ) {
                $update['started_at'] = current_time( 'mysql' );
                $format[] = '%s';
            }

            // completed_at — set on entering a final state, cleared on reopen.
            $finals = array( 'completed', 'closed' );
            $entering_final = in_array( $new, $finals, true ) && ! in_array( $old, $finals, true );
            $leaving_final  = in_array( $old, $finals, true ) && ! in_array( $new, $finals, true );
            if ( $entering_final ) {
                $update['completed_at'] = current_time( 'mysql' );
                $format[] = '%s';

                // 1.14.87: Capture completion location if the caller
                // provided GPS data. We accept lat/lng/address/source
                // ONLY when transitioning into a final state — never
                // mid-flight, never on other status changes. The
                // browser/Telegram client is the one collecting these
                // and passing them in.
                if ( isset( $data['completion_lat'] ) && isset( $data['completion_lng'] ) ) {
                    $lat = (float) $data['completion_lat'];
                    $lng = (float) $data['completion_lng'];
                    // Sanity-check the coordinates. GPS-ish range.
                    if ( $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 ) {
                        $update['completion_lat'] = $lat;
                        $update['completion_lng'] = $lng;
                        $format[] = '%f';
                        $format[] = '%f';
                        if ( ! empty( $data['completion_address'] ) ) {
                            $update['completion_address'] = mb_substr( sanitize_text_field( (string) $data['completion_address'] ), 0, 500 );
                            $format[] = '%s';
                        }
                        $src = isset( $data['completion_location_source'] ) ? sanitize_text_field( (string) $data['completion_location_source'] ) : 'browser';
                        // Whitelist sources to avoid garbage in reports.
                        if ( ! in_array( $src, array( 'browser', 'telegram', 'manual' ), true ) ) {
                            $src = 'browser';
                        }
                        $update['completion_location_source'] = $src;
                        $format[] = '%s';
                    }
                }
            } elseif ( $leaving_final ) {
                // Reopened — clear completed_at so reports don't double-count.
                $update['completed_at'] = null;
                $format[] = '%s';
                // 1.14.87: Also clear location — old location is stale.
                $update['completion_lat'] = null;
                $update['completion_lng'] = null;
                $update['completion_address'] = null;
                $update['completion_location_source'] = null;
                $format[] = '%s'; $format[] = '%s'; $format[] = '%s'; $format[] = '%s';
            }
        }

        $update['updated_at'] = current_time( 'mysql' );
        $format[] = '%s';

        $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

        if ( isset( $update['status'] ) && $update['status'] !== $task->status ) {
            self::log( $id, $task->account_id, $actor_user_id, 'status_changed', '', $task->status, $update['status'] );
            do_action( 'cmms_task_status_changed', (int) $id, $task->status, $update['status'], (int) $actor_user_id );
        }
        if ( isset( $update['assigned_to'] ) && intval( $update['assigned_to'] ) !== intval( $task->assigned_to ) ) {
            self::log( $id, $task->account_id, $actor_user_id, 'assigned', '', $task->assigned_to, $update['assigned_to'] );
            do_action( 'cmms_task_assigned', (int) $id, (int) $task->assigned_to, (int) $update['assigned_to'] );
        }
        if ( array_key_exists( 'due_date', $update ) && $update['due_date'] !== $task->due_date ) {
            do_action( 'cmms_task_due_changed', (int) $id, $task->due_date );
        }

        return true;
    }

    public static function get( $id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'tasks' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    }

    /**
     * 1.14.83: Soft-delete a task. Sets deleted_at to NOW() so the
     * task disappears from normal lists but remains in the DB. This
     * keeps:
     *   - Reports historically accurate (admin can include archived)
     *   - Audit log intact (task_logs not touched)
     *   - Attachments accessible if a recovery is needed
     *
     * Returns true on success, false if the task doesn't exist.
     */
    public static function soft_delete( $id, $by_user_id = 0 ) {
        global $wpdb;
        $task = self::get( $id );
        if ( ! $task ) return false;
        // Already soft-deleted? Idempotent — no-op.
        if ( ! empty( $task->deleted_at ) ) return true;
        $wpdb->update(
            CMMS_DB::table( 'tasks' ),
            array(
                'deleted_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
        // Log the deletion to task_logs so we have a who/when/what
        // record even after the task is hidden from the UI.
        $logs_t = CMMS_DB::table( 'task_logs' );
        if ( $logs_t ) {
            $wpdb->insert( $logs_t, array(
                'task_id'    => (int) $id,
                'account_id' => (int) $task->account_id,
                'user_id'    => (int) $by_user_id,
                'action'     => 'deleted',
                'detail'     => 'Task soft-deleted',
                'created_at' => current_time( 'mysql' ),
            ), array( '%d', '%d', '%d', '%s', '%s', '%s' ) );
        }
        return true;
    }

    /**
     * 1.14.83: Restore a soft-deleted task. Clears deleted_at. Used
     * by the (future) archive page; not exposed in this version's UI
     * but the backend method exists so the archive can be added later
     * with zero changes here.
     */
    public static function restore( $id, $by_user_id = 0 ) {
        global $wpdb;
        $task = self::get( $id );
        if ( ! $task ) return false;
        $wpdb->update(
            CMMS_DB::table( 'tasks' ),
            array(
                'deleted_at' => null,
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
        $logs_t = CMMS_DB::table( 'task_logs' );
        if ( $logs_t ) {
            $wpdb->insert( $logs_t, array(
                'task_id'    => (int) $id,
                'account_id' => (int) $task->account_id,
                'user_id'    => (int) $by_user_id,
                'action'     => 'restored',
                'detail'     => 'Task restored from soft-delete',
                'created_at' => current_time( 'mysql' ),
            ), array( '%d', '%d', '%d', '%s', '%s', '%s' ) );
        }
        return true;
    }

    /**
     * Legacy hard-delete. Kept ONLY for account-wide cleanup (when an
     * account is deleted entirely from CMMS_Accounts). Not for normal
     * user-initiated deletion — those go through soft_delete().
     */
    public static function delete( $id ) {
        global $wpdb;
        $task = self::get( $id );
        if ( ! $task ) return false;
        $wpdb->delete( CMMS_DB::table( 'tasks' ), array( 'id' => $id ), array( '%d' ) );
        $wpdb->delete( CMMS_DB::table( 'task_logs' ), array( 'task_id' => $id ), array( '%d' ) );
        $wpdb->delete( CMMS_DB::table( 'attachments' ), array( 'object_type' => 'task', 'object_id' => $id ), array( '%s', '%d' ) );
        return true;
    }

    public static function list_for_user( $cmms_user, $filters = array() ) {
        global $wpdb;
        $table = CMMS_DB::table( 'tasks' );
        $where = array();
        $args = array();

        $where[] = "account_id = %d";
        $args[] = $cmms_user->account_id;

        // 1.14.83: Exclude soft-deleted tasks from normal lists. The
        // archive (when built) will use a separate query path that
        // intentionally INCLUDES deleted_at IS NOT NULL.
        $where[] = "deleted_at IS NULL";

        // Visibility scoping (1.14.19): same rules as can_view_task.
        //   Owners/managers — see everything in the account.
        //   Everyone else — assignee OR manager OR creator (the related set).
        // Previously this list silently differed from can_view_task —
        // technicians who were a task's manager_id, for example, didn't
        // see it in their list even though detail-view permission allowed
        // it. Centralize so the answer to "can I see it?" is one rule.
        $is_priv = in_array(
            $cmms_user->role,
            array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER ),
            true
        );
        if ( ! $is_priv ) {
            $where[] = "( assigned_to = %d OR manager_id = %d OR created_by = %d )";
            $args[] = $cmms_user->id;
            $args[] = $cmms_user->id;
            $args[] = $cmms_user->id;
        }

        // 1.14.79: 'mine' filter — UX-level scoping that lets even
        // privileged users (owner/manager) narrow the list to tasks
        // they are personally involved in (assignee / manager / creator).
        //
        // This is a server-side filter applied at the SQL level — NOT
        // a CSS hide. With hundreds of tasks per account this is the
        // only way to keep the page fast: the DB returns only the rows
        // the user actually wants, the renderer only iterates those.
        //
        // For non-privileged users the visibility scope above already
        // restricts to their related set, so applying 'mine' on top is
        // a no-op for them. We still allow it (harmless redundancy) so
        // the caller can pass the same filter regardless of role.
        if ( ! empty( $filters['mine'] ) ) {
            $where[] = "( assigned_to = %d OR manager_id = %d OR created_by = %d )";
            $args[] = $cmms_user->id;
            $args[] = $cmms_user->id;
            $args[] = $cmms_user->id;
        }

        // 1.15.2: Filter by selected user IDs (multi-select dropdown
        // in the tasks page). Shows tasks where ANY of the selected
        // users is the assignee, manager, or creator — same scope as
        // the 'mine' filter, just for a chosen set of users instead
        // of the current user. Only privileged roles (owner/manager)
        // can use this filter because the UI hides it from others;
        // we still defend in depth at the SQL layer.
        if ( ! empty( $filters['users'] ) && is_array( $filters['users'] ) ) {
            $clean_ids = array_filter( array_map( 'intval', $filters['users'] ) );
            if ( ! empty( $clean_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $clean_ids ), '%d' ) );
                // Each user matches if they're assignee OR manager OR creator.
                // We need the same set of IDs three times (once per column).
                $where[] = "( assigned_to IN ($placeholders) "
                         . "OR manager_id IN ($placeholders) "
                         . "OR created_by IN ($placeholders) )";
                $args = array_merge( $args, $clean_ids, $clean_ids, $clean_ids );
            }
        }

        if ( ! empty( $filters['status'] ) ) {
            $where[] = "status = %s";
            $args[] = $filters['status'];
        }
        if ( ! empty( $filters['as_assignee'] ) ) {
            $where[] = "assigned_to = %d";
            $args[] = (int) $cmms_user->id;
        }
        if ( ! empty( $filters['as_manager'] ) ) {
            $where[] = "manager_id = %d";
            $args[] = (int) $cmms_user->id;
        }
        if ( ! empty( $filters['overdue'] ) ) {
            $where[] = "status NOT IN ('completed','closed') AND due_date IS NOT NULL AND due_date < %s";
            $args[] = current_time( 'mysql' );
        }
        if ( ! empty( $filters['category_id'] ) ) {
            $where[] = "category_id = %d";
            $args[] = intval( $filters['category_id'] );
        }
        if ( ! empty( $filters['asset_id'] ) ) {
            $where[] = "asset_id = %d";
            $args[] = intval( $filters['asset_id'] );
        }
        if ( ! empty( $filters['assigned_to'] ) ) {
            $where[] = "assigned_to = %d";
            $args[] = intval( $filters['assigned_to'] );
        }
        if ( ! empty( $filters['priority'] ) ) {
            $where[] = "priority = %s";
            $args[] = $filters['priority'];
        }
        if ( ! empty( $filters['search'] ) ) {
            $where[] = "(title LIKE %s OR description LIKE %s)";
            $like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
            $args[] = $like;
            $args[] = $like;
        }

        // Time-range scoping. Caller passes 'date_from' and 'date_to' (MySQL
        // datetime strings) and 'date_field' = 'created_at' (default) or
        // 'due_date'. Past ranges should use created_at; future ranges should
        // use due_date — the caller decides.
        if ( ! empty( $filters['date_from'] ) || ! empty( $filters['date_to'] ) ) {
            $field = ( ! empty( $filters['date_field'] ) && in_array( $filters['date_field'], array( 'created_at', 'due_date' ), true ) )
                ? $filters['date_field'] : 'created_at';
            if ( ! empty( $filters['date_from'] ) ) {
                $where[] = "$field >= %s";
                $args[] = $filters['date_from'];
            }
            if ( ! empty( $filters['date_to'] ) ) {
                $where[] = "$field <= %s";
                $args[] = $filters['date_to'];
            }
        }

        // 1.14.81: Filter for tasks WITHOUT a due_date — used by the
        // calendar's "X tasks without due date" banner so we can link
        // users to the list view to fix those tasks. Mutually exclusive
        // with date_from/date_to using due_date (those queries by
        // definition exclude NULL due_date rows).
        if ( ! empty( $filters['no_due_date'] ) ) {
            $where[] = "( due_date IS NULL OR due_date = '' OR due_date = '0000-00-00 00:00:00' )";
        }

        // 1.14.79: hard LIMIT to keep page render fast even with
        // thousands of tasks. Default 500 rows per query covers all
        // reasonable usage (a manager browsing their inbox); pagination
        // is the proper next step once accounts grow past this.
        //
        // Callers can override via $filters['limit'] (0 = unlimited,
        // used by background reports and counts).
        $limit = isset( $filters['limit'] ) ? (int) $filters['limit'] : 500;
        $limit_sql = '';
        if ( $limit > 0 ) {
            $limit_sql = ' LIMIT ' . $limit;
            if ( ! empty( $filters['offset'] ) ) {
                $limit_sql .= ' OFFSET ' . (int) $filters['offset'];
            }
        }

        $sql = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . " ORDER BY 
            FIELD(status, 'open','in_progress','waiting','completed','closed'),
            FIELD(priority, 'urgent','high','normal','low'),
            (due_date IS NULL), due_date ASC, id DESC" . $limit_sql;
        return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
    }

    public static function log( $task_id, $account_id, $user_id, $action, $note = '', $old_value = null, $new_value = null ) {
        global $wpdb;
        $table = CMMS_DB::table( 'task_logs' );
        $wpdb->insert( $table, array(
            'task_id'    => intval( $task_id ),
            'account_id' => intval( $account_id ),
            'user_id'    => $user_id ? intval( $user_id ) : null,
            'action'     => sanitize_text_field( $action ),
            'note'       => sanitize_text_field( $note ),
            'old_value'  => $old_value !== null ? (string) $old_value : null,
            'new_value'  => $new_value !== null ? (string) $new_value : null,
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );
    }

    public static function get_logs( $task_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'task_logs' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE task_id = %d ORDER BY created_at DESC, id DESC", $task_id ) );
    }

    private static function sanitize_datetime( $val ) {
        $val = sanitize_text_field( $val );
        $ts = strtotime( $val );
        if ( ! $ts ) return null;
        return date( 'Y-m-d H:i:s', $ts );
    }

    public static function counts_for_account( $account_id, $range = null ) {
        global $wpdb;
        $table = CMMS_DB::table( 'tasks' );
        $now = current_time( 'mysql' );

        $extra = '';
        $extra_args = array();
        if ( $range && ( ! empty( $range['from'] ) || ! empty( $range['to'] ) ) ) {
            // Use created_at for past windows, due_date for future ones.
            $field = ( in_array( $range['key'] ?? '', array( 'next_month', 'next_quarter' ), true ) ) ? 'due_date' : 'created_at';
            if ( ! empty( $range['from'] ) ) {
                $extra .= " AND $field >= %s";
                $extra_args[] = $range['from'];
            }
            if ( ! empty( $range['to'] ) ) {
                $extra .= " AND $field <= %s";
                $extra_args[] = $range['to'];
            }
        }

        // 1.14.83: Counters always exclude soft-deleted tasks.
        $del_clause = " AND deleted_at IS NULL";

        $open      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE account_id = %d AND status IN ('open','in_progress','waiting')$del_clause$extra", array_merge( array( $account_id ), $extra_args ) ) );
        $completed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE account_id = %d AND status IN ('completed','closed')$del_clause$extra", array_merge( array( $account_id ), $extra_args ) ) );
        $overdue   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE account_id = %d AND status NOT IN ('completed','closed') AND due_date IS NOT NULL AND due_date < %s$del_clause$extra", array_merge( array( $account_id, $now ), $extra_args ) ) );
        $total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE account_id = %d$del_clause$extra", array_merge( array( $account_id ), $extra_args ) ) );
        return compact( 'open', 'completed', 'overdue', 'total' );
    }

    /* ================================================================
       TIME / DURATION HELPERS (1.14.87)
    ================================================================ */

    /**
     * Format a duration in seconds to a Hebrew-friendly string.
     * Examples:
     *   30      → "30 שניות"
     *   90      → "דקה ו-30 שניות"
     *   3600    → "שעה"
     *   3900    → "1ש 5ד"
     *   86400   → "1 י 0ש"
     *   90000   → "1 י 1ש"
     *
     * Used in the task detail page to show "execution time" and
     * "total time". Short labels because mobile screens are narrow.
     */
    public static function format_duration( $seconds ) {
        $seconds = (int) $seconds;
        if ( $seconds < 0 ) return '';
        if ( $seconds < 60 ) return $seconds . ' שניות';

        $minutes = (int) floor( $seconds / 60 );
        if ( $minutes < 60 ) {
            return $minutes . ' דק׳';
        }

        $hours = (int) floor( $minutes / 60 );
        $rem_minutes = $minutes - ( $hours * 60 );
        if ( $hours < 24 ) {
            if ( $rem_minutes === 0 ) return $hours . ' ש׳';
            return $hours . 'ש ' . $rem_minutes . 'ד';
        }

        $days = (int) floor( $hours / 24 );
        $rem_hours = $hours - ( $days * 24 );
        if ( $rem_hours === 0 ) return $days . ' י׳';
        return $days . 'י ' . $rem_hours . 'ש';
    }

    /**
     * Compute task durations given its timestamps.
     * Returns array with:
     *   response_seconds  — created_at → started_at (waiting before work)
     *   execution_seconds — started_at → completed_at (actual work time)
     *   total_seconds     — created_at → completed_at
     * Any of these may be null if the relevant timestamps are missing.
     */
    public static function calculate_durations( $task ) {
        $result = array(
            'response_seconds'  => null,
            'execution_seconds' => null,
            'total_seconds'     => null,
        );
        if ( empty( $task->created_at ) ) return $result;
        $created_ts = strtotime( $task->created_at );

        if ( ! empty( $task->started_at ) ) {
            $started_ts = strtotime( $task->started_at );
            if ( $started_ts >= $created_ts ) {
                $result['response_seconds'] = $started_ts - $created_ts;
            }
        }
        if ( ! empty( $task->completed_at ) ) {
            $completed_ts = strtotime( $task->completed_at );
            if ( $completed_ts >= $created_ts ) {
                $result['total_seconds'] = $completed_ts - $created_ts;
            }
            if ( ! empty( $task->started_at ) ) {
                $started_ts = strtotime( $task->started_at );
                if ( $completed_ts >= $started_ts ) {
                    $result['execution_seconds'] = $completed_ts - $started_ts;
                }
            }
        }
        return $result;
    }
}
