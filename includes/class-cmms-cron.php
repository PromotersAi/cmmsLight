<?php
/**
 * Cron + event hooks for the notification system.
 *
 * Responsibilities:
 *  - Every 5 minutes, scan the reminders table for due rows and dispatch them.
 *  - On task creation, schedule "before due_at" and "after due_at" reminders
 *    based on the account's notification settings.
 *  - On task assignment change, send an immediate "task assigned" notification
 *    and reschedule the reminder for the new assignee.
 *  - On task status change to closed/completed, cancel pending reminders.
 *  - On task comment, notify the assignee+manager.
 *  - On user invite, send a welcome email.
 *  - On public form submission that creates a task, notify the form's manager.
 *
 * All event hooks are fired from CMMS_Tasks / CMMS_Forms / CMMS_Users via
 * do_action(); we just listen.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Cron {

    const HOOK_DISPATCH = 'cmms_dispatch_reminders';

    public function register() {
        // Cron schedule
        add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
        add_action( self::HOOK_DISPATCH, array( $this, 'dispatch_due_reminders' ) );

        // Task lifecycle
        add_action( 'cmms_task_created',  array( $this, 'on_task_created' ),  10, 2 );
        add_action( 'cmms_task_assigned', array( $this, 'on_task_assigned' ), 10, 3 );
        add_action( 'cmms_task_status_changed', array( $this, 'on_task_status_changed' ), 10, 4 );
        add_action( 'cmms_task_due_changed', array( $this, 'on_task_due_changed' ), 10, 2 );
        add_action( 'cmms_task_commented', array( $this, 'on_task_commented' ), 10, 3 );

        // User lifecycle
        add_action( 'cmms_user_invited', array( $this, 'on_user_invited' ), 10, 3 );

        // Public form
        add_action( 'cmms_public_submitted', array( $this, 'on_public_submitted' ), 10, 2 );
    }

    public function add_schedules( $schedules ) {
        if ( ! isset( $schedules['cmms_5min'] ) ) {
            $schedules['cmms_5min'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 minutes (CMMS reminders)', 'cmms-light' ),
            );
        }
        return $schedules;
    }

    /**
     * 1.15.5: Prune the email inbound log to 30 days. Runs at most
     * once per day — we gate it with a transient so the 5-minute
     * dispatch loop doesn't run a DELETE every 5 minutes. Piggybacking
     * on the existing cron avoids registering a separate daily event.
     */
    private function maybe_prune_email_log() {
        if ( get_transient( 'cmms_email_log_pruned_today' ) ) {
            return;
        }
        if ( class_exists( 'CMMS_Email_Inbox' ) ) {
            CMMS_Email_Inbox::prune_inbound_log();
        }
        // Gate for 24h.
        set_transient( 'cmms_email_log_pruned_today', 1, DAY_IN_SECONDS );
    }

    public static function ensure_scheduled() {
        if ( ! wp_next_scheduled( self::HOOK_DISPATCH ) ) {
            wp_schedule_event( time() + 60, 'cmms_5min', self::HOOK_DISPATCH );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::HOOK_DISPATCH );
        if ( $ts ) wp_unschedule_event( $ts, self::HOOK_DISPATCH );
    }

    /**
     * Wakes up every 5 min, finds due reminders, dispatches them, marks sent.
     * Caps batch size to avoid timeouts on shared hosting.
     */
    public function dispatch_due_reminders() {
        global $wpdb;

        // 1.15.5: Prune the email inbound log (once/day, gated).
        $this->maybe_prune_email_log();

        // 1.14.40: piggyback on the 5-min cron tick to process
        // subscription grace expirations. We throttle to once per day
        // via an option marker — cheap, and grace expiration is not
        // time-critical to the minute (we just need it to happen
        // within ~24 hours of grace_until passing).
        $last_grace_check = get_option( 'cmms_grace_check_last', '' );
        $today_key = gmdate( 'Y-m-d' );
        if ( $last_grace_check !== $today_key && class_exists( 'CMMS_Subscriptions' ) ) {
            CMMS_Subscriptions::process_grace_expirations();
            update_option( 'cmms_grace_check_last', $today_key, false );
        }

        // 1.14.60: Apply any pending plan-change downgrades whose
        // effective date has passed. Idempotent — rows are cleared
        // after apply, so re-running is safe.
        if ( class_exists( 'CMMS_PlanChanges' ) ) {
            CMMS_PlanChanges::apply_pending_changes_now();
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . CMMS_DB::table( 'reminders' ) . "
             WHERE sent_at IS NULL AND cancelled_at IS NULL AND due_at <= %s
             ORDER BY due_at ASC LIMIT 50",
            current_time( 'mysql' )
        ) );

        if ( ! $rows ) {
            update_option( 'cmms_cron_last_run', current_time( 'mysql' ), false );
            return;
        }

        foreach ( $rows as $row ) {
            $this->dispatch_one_reminder( $row );
            $wpdb->update(
                CMMS_DB::table( 'reminders' ),
                array( 'sent_at' => current_time( 'mysql' ) ),
                array( 'id' => (int) $row->id ),
                array( '%s' ), array( '%d' )
            );
        }

        update_option( 'cmms_cron_last_run', current_time( 'mysql' ), false );
    }

    private function dispatch_one_reminder( $row ) {
        // Fetch task. If task no longer open, skip.
        $task = CMMS_Tasks::get( (int) $row->task_id );
        if ( ! $task ) return;
        if ( in_array( $task->status, array( 'completed', 'closed' ), true ) ) return;

        $url = $this->task_url( $task->id );
        // Use the recipient's preferred CMMS language for the notification text.
        $lang = $this->user_lang( (int) $row->user_id );
        if ( $row->kind === 'before' ) {
            $title = sprintf( CMMS_I18n::t_in( 'cron.due_soon_title', $lang ), $task->title );
            $body  = sprintf( CMMS_I18n::t_in( 'cron.due_soon_body',  $lang ), $task->title, $task->due_date );
            $type  = CMMS_Notifications::TYPE_TASK_DUE_BEFORE;
        } else {
            $title = sprintf( CMMS_I18n::t_in( 'cron.overdue_title', $lang ), $task->title );
            $body  = sprintf( CMMS_I18n::t_in( 'cron.overdue_body',  $lang ), $task->title, $task->due_date );
            $type  = CMMS_Notifications::TYPE_TASK_OVERDUE;
        }

        CMMS_Notifications::notify( array(
            'account_id' => (int) $row->account_id,
            'user_id'    => (int) $row->user_id,
            'type'       => $type,
            'task_id'    => (int) $task->id,
            'title'      => $title,
            'body'       => $body,
            'url'        => $url,
            'lang'       => $lang,
        ) );

        // Schedule a follow-up "after" reminder if configured for repeat behavior.
        if ( $row->kind === 'after' ) {
            $this->maybe_schedule_repeat_after( $row, $task );
        }
    }

    /**
     * Best-effort lookup of a user's preferred language. Falls back to the
     * site default. We store language preference in WP user meta when the
     * user picks one in the dashboard.
     */
    private function user_lang( $cmms_user_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT wp_user_id FROM " . CMMS_DB::table( 'users' ) . " WHERE id = %d",
            $cmms_user_id
        ) );
        if ( $row ) {
            $lang = get_user_meta( (int) $row->wp_user_id, 'cmms_language', true );
            if ( $lang && array_key_exists( $lang, CMMS_I18n::langs() ) ) {
                return $lang;
            }
        }
        return CMMS_I18n::default_lang();
    }

    private function maybe_schedule_repeat_after( $row, $task ) {
        $settings = CMMS_Notifications::get_account_settings( (int) $row->account_id );
        if ( empty( $settings['remind_after_repeat'] ) ) return;
        // Count how many "after" reminders already sent for this task+user.
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . CMMS_DB::table( 'reminders' ) . "
             WHERE task_id = %d AND user_id = %d AND kind = 'after' AND sent_at IS NOT NULL",
            (int) $row->task_id, (int) $row->user_id
        ) );
        if ( $count >= (int) $settings['remind_after_max'] ) return;
        $next_due = gmdate( 'Y-m-d H:i:s', strtotime( $row->due_at ) + (int) $settings['remind_after_repeat'] * HOUR_IN_SECONDS );
        $wpdb->insert( CMMS_DB::table( 'reminders' ), array(
            'account_id' => (int) $row->account_id,
            'task_id'    => (int) $row->task_id,
            'user_id'    => (int) $row->user_id,
            'kind'       => 'after',
            'due_at'     => $next_due,
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%d', '%d', '%s', '%s', '%s' ) );
    }

    /* ============================================================
       Event handlers
    ============================================================ */

    public function on_task_created( $task_id, $context = array() ) {
        try {
            $task = CMMS_Tasks::get( (int) $task_id );
            if ( ! $task ) {
                CMMS_Notifications::log_event( 'task_created', (int) $task_id, array(
                    'reason' => 'task_not_found',
                ) );
                return;
            }

            $actor = (int) ( $context['created_by'] ?? 0 );
            $recipients = CMMS_Notifications::resolve_recipients(
                CMMS_Notifications::TYPE_TASK_ASSIGNED, $task, $actor
            );
            CMMS_Notifications::log_event( 'task_created', (int) $task->id, array(
                'actor'      => $actor,
                'recipients' => $recipients,
                'count'      => count( $recipients ),
            ) );

            $url = $this->task_url( $task->id );

            // Send a single, role-appropriate message per recipient. The
            // recipient list already excludes the actor (unless they were
            // the only one) and is deduped — see resolve_recipients().
            foreach ( $recipients as $rcpt ) {
                $is_assignee = ( (int) $rcpt === (int) $task->assigned_to );
                $is_manager  = ( (int) $rcpt === (int) $task->manager_id && ! $is_assignee );

                $type = CMMS_Notifications::TYPE_TASK_ASSIGNED;
                $title_key = 'cron.assigned_title';
                if ( $is_manager ) {
                    $type = CMMS_Notifications::TYPE_TASK_MANAGED;
                    $title_key = 'cron.managed_title';
                } elseif ( ! $is_assignee ) {
                    // Recipient is the creator (or a future watcher) —
                    // they get a "task created" framing rather than an
                    // assignment one.
                    $title_key = 'cron.created_title';
                }
                $lang = $this->user_lang( $rcpt );

                CMMS_Notifications::notify( array(
                    'account_id' => (int) $task->account_id,
                    'user_id'    => (int) $rcpt,
                    'type'       => $type,
                    'task_id'    => (int) $task->id,
                    'title'      => sprintf(
                        CMMS_I18n::t_in( $title_key, $lang ),
                        $task->title
                    ),
                    'body'       => $this->task_summary( $task, $lang ),
                    'url'        => $url,
                    'lang'       => $lang,
                ) );
            }

            // Reminders are independent of the notification fan-out. Done
            // last so a reminder-scheduling glitch doesn't suppress the
            // immediate notifications above.
            $this->schedule_reminders_for_task( $task );
        } catch ( \Throwable $e ) {
            // Notification failures must NEVER break task creation.
            error_log( 'CMMS on_task_created handler crashed: ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine() );
        }
    }

    public function on_task_assigned( $task_id, $old_user_id, $new_user_id ) {
        try {
            if ( (int) $old_user_id === (int) $new_user_id ) return;
            $task = CMMS_Tasks::get( (int) $task_id );
            if ( ! $task ) {
                CMMS_Notifications::log_event( 'task_assigned', (int) $task_id, array(
                    'reason' => 'task_not_found',
                ) );
                return;
            }

            // The reassign event is "new assignee got the task". The
            // resolver will return everyone related — for this event we
            // only want to ping the new assignee directly to avoid
            // doubling up with the reassign manager/creator workflows.
            // (Status change handler already handles those for cases
            // where reassign comes with a status change.)
            if ( $new_user_id ) {
                $lang = $this->user_lang( (int) $new_user_id );
                CMMS_Notifications::log_event( 'task_assigned', (int) $task->id, array(
                    'recipients' => array( (int) $new_user_id ),
                    'old_assignee' => (int) $old_user_id,
                ) );
                CMMS_Notifications::notify( array(
                    'account_id' => (int) $task->account_id,
                    'user_id'    => (int) $new_user_id,
                    'type'       => CMMS_Notifications::TYPE_TASK_ASSIGNED,
                    'task_id'    => (int) $task->id,
                    'title'      => sprintf( CMMS_I18n::t_in( 'cron.assigned_to_you_title', $lang ), $task->title ),
                    'body'       => $this->task_summary( $task, $lang ),
                    'url'        => $this->task_url( $task->id ),
                    'lang'       => $lang,
                ) );
            }
            // Reminders follow the assignment.
            $this->cancel_reminders_for_task( $task->id );
            $this->schedule_reminders_for_task( $task );
        } catch ( \Throwable $e ) {
            error_log( 'CMMS on_task_assigned handler crashed: ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine() );
        }
    }

    public function on_task_status_changed( $task_id, $old_status, $new_status, $by_user_id = 0 ) {
        try {
            $task = CMMS_Tasks::get( (int) $task_id );
            if ( ! $task ) {
                CMMS_Notifications::log_event( 'task_status', (int) $task_id, array(
                    'reason' => 'task_not_found',
                ) );
                return;
            }

            if ( in_array( $new_status, array( 'completed', 'closed' ), true ) ) {
                $this->cancel_reminders_for_task( $task->id );
            }

            $recipients = CMMS_Notifications::resolve_recipients(
                CMMS_Notifications::TYPE_TASK_STATUS, $task, (int) $by_user_id
            );
            CMMS_Notifications::log_event( 'task_status', (int) $task->id, array(
                'actor'      => (int) $by_user_id,
                'old'        => (string) $old_status,
                'new'        => (string) $new_status,
                'recipients' => $recipients,
            ) );

            foreach ( $recipients as $rcpt ) {
                $lang = $this->user_lang( $rcpt );
                $old_label = CMMS_I18n::t_in( 'status.' . $old_status, $lang ) ?: $old_status;
                $new_label = CMMS_I18n::t_in( 'status.' . $new_status, $lang ) ?: $new_status;
                CMMS_Notifications::notify( array(
                    'account_id' => (int) $task->account_id,
                    'user_id'    => (int) $rcpt,
                    'type'       => CMMS_Notifications::TYPE_TASK_STATUS,
                    'task_id'    => (int) $task->id,
                    'title'      => sprintf( CMMS_I18n::t_in( 'cron.status_title', $lang ), $task->title ),
                    'body'       => sprintf( CMMS_I18n::t_in( 'cron.status_body', $lang ), $old_label, $new_label ),
                    'url'        => $this->task_url( $task->id ),
                    'lang'       => $lang,
                ) );
            }
        } catch ( \Throwable $e ) {
            error_log( 'CMMS on_task_status_changed handler crashed: ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine() );
        }
    }

    public function on_task_due_changed( $task_id, $old_due ) {
        $task = CMMS_Tasks::get( (int) $task_id );
        if ( ! $task ) return;
        $this->cancel_reminders_for_task( $task->id );
        $this->schedule_reminders_for_task( $task );
    }

    public function on_task_commented( $task_id, $comment_text, $by_user_id ) {
        try {
            $task = CMMS_Tasks::get( (int) $task_id );
            if ( ! $task ) {
                CMMS_Notifications::log_event( 'task_commented', (int) $task_id, array(
                    'reason' => 'task_not_found',
                ) );
                return;
            }
            $recipients = CMMS_Notifications::resolve_recipients(
                CMMS_Notifications::TYPE_TASK_COMMENTED, $task, (int) $by_user_id
            );
            CMMS_Notifications::log_event( 'task_commented', (int) $task->id, array(
                'actor'      => (int) $by_user_id,
                'recipients' => $recipients,
            ) );
            foreach ( $recipients as $rcpt ) {
                $lang = $this->user_lang( $rcpt );
                CMMS_Notifications::notify( array(
                    'account_id' => (int) $task->account_id,
                    'user_id'    => (int) $rcpt,
                    'type'       => CMMS_Notifications::TYPE_TASK_COMMENTED,
                    'task_id'    => (int) $task->id,
                    'title'      => sprintf( CMMS_I18n::t_in( 'cron.comment_title', $lang ), $task->title ),
                    'body'       => $comment_text,
                    'url'        => $this->task_url( $task->id ),
                    'lang'       => $lang,
                ) );
            }
        } catch ( \Throwable $e ) {
            error_log( 'CMMS on_task_commented handler crashed: ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine() );
        }
    }

    public function on_user_invited( $cmms_user_id, $temp_password, $invited_by_user_id = 0 ) {
        try {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT u.*, a.name AS account_name FROM " . CMMS_DB::table( 'users' ) . " u
                 JOIN " . CMMS_DB::table( 'accounts' ) . " a ON a.id = u.account_id
                 WHERE u.id = %d", (int) $cmms_user_id
            ) );
            if ( ! $row ) {
                CMMS_Notifications::log_event( 'user_invited', 0, array(
                    'reason' => 'cmms_user_not_found',
                    'cmms_user_id' => (int) $cmms_user_id,
                ) );
                return;
            }
            $wp_user = get_user_by( 'id', (int) $row->wp_user_id );
            if ( ! $wp_user || empty( $wp_user->user_email ) ) {
                CMMS_Notifications::log_event( 'user_invited', 0, array(
                    'reason' => 'wp_user_or_email_missing',
                    'cmms_user_id' => (int) $cmms_user_id,
                ) );
                return;
            }

            $login_url = home_url( '/cmms-login/' );
            $body = sprintf(
                __( "Hello %1\$s,\n\nYou have been invited to join \"%2\$s\" on our maintenance management platform.\n\nUse these credentials to sign in:\n\nEmail: %3\$s\nTemporary password: %4\$s\n\nFor security, please change your password after first login.", 'cmms-light' ),
                $wp_user->display_name ?: $wp_user->user_login,
                $row->account_name,
                $wp_user->user_email,
                $temp_password
            );

            // PRIORITY 1: in-app welcome row. Always created so the user
            // sees something on first login even if email never arrives.
            $insert_ok = $wpdb->insert( CMMS_DB::table( 'notifications' ), array(
                'account_id' => (int) $row->account_id,
                'user_id'    => (int) $cmms_user_id,
                'type'       => CMMS_Notifications::TYPE_USER_INVITED,
                'title'      => __( 'Welcome — your account is ready', 'cmms-light' ),
                'body'       => __( 'You have been added to this organization. Check your email for login credentials.', 'cmms-light' ),
                'url'        => home_url( '/cmms-dashboard/' ),
                'created_at' => current_time( 'mysql' ),
            ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );

            // PRIORITY 2: email with the temporary password. (We do NOT
            // include the password in the in-app row above — anyone with
            // dashboard access could read it.)
            $sent = false;
            try {
                $sent = (bool) CMMS_Notifications::send_email_to_user(
                    (int) $cmms_user_id,
                    sprintf( __( 'You\'ve been invited to %s', 'cmms-light' ), $row->account_name ),
                    $body,
                    $login_url
                );
            } catch ( \Throwable $e ) {
                error_log( 'CMMS on_user_invited: email send threw: ' . $e->getMessage() );
            }

            CMMS_Notifications::log_event( 'user_invited', 0, array(
                'cmms_user_id' => (int) $cmms_user_id,
                'invited_by'   => (int) $invited_by_user_id,
                'inapp'        => $insert_ok !== false ? 'ok' : 'db_error',
                'email'        => $sent ? 'ok' : 'failed',
            ) );
        } catch ( \Throwable $e ) {
            error_log( 'CMMS on_user_invited handler crashed: ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine() );
        }
    }

    public function on_public_submitted( $task_id, $form_id ) {
        try {
            $task = CMMS_Tasks::get( (int) $task_id );
            if ( ! $task ) {
                CMMS_Notifications::log_event( 'public_submitted', (int) $task_id, array(
                    'reason' => 'task_not_found',
                ) );
                return;
            }
            // No "actor" — the form was filled by an anonymous public user,
            // not a logged-in cmms user. Pass 0 so no one is filtered out.
            $recipients = CMMS_Notifications::resolve_recipients(
                CMMS_Notifications::TYPE_PUBLIC_SUBMITTED, $task, 0
            );
            CMMS_Notifications::log_event( 'public_submitted', (int) $task->id, array(
                'recipients' => $recipients,
            ) );
            foreach ( $recipients as $rcpt ) {
                $lang = $this->user_lang( $rcpt );
                CMMS_Notifications::notify( array(
                    'account_id' => (int) $task->account_id,
                    'user_id'    => (int) $rcpt,
                    'type'       => CMMS_Notifications::TYPE_PUBLIC_SUBMITTED,
                    'task_id'    => (int) $task->id,
                    'title'      => sprintf( CMMS_I18n::t_in( 'cron.public_title', $lang ), $task->title ),
                    'body'       => sprintf( CMMS_I18n::t_in( 'cron.public_body',  $lang ), $task->title ),
                    'url'        => $this->task_url( $task->id ),
                    'lang'       => $lang,
                ) );
            }
        } catch ( \Throwable $e ) {
            error_log( 'CMMS on_public_submitted handler crashed: ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine() );
        }
    }

    /* ============================================================
       Reminder scheduling helpers
    ============================================================ */

    private function schedule_reminders_for_task( $task ) {
        global $wpdb;
        if ( empty( $task->due_date ) || $task->due_date === '0000-00-00 00:00:00' ) return;
        if ( empty( $task->assigned_to ) ) return;
        if ( in_array( $task->status, array( 'completed', 'closed' ), true ) ) return;

        $settings = CMMS_Notifications::get_account_settings( (int) $task->account_id );
        $due_ts = strtotime( $task->due_date );
        if ( ! $due_ts ) return;
        $now = (int) current_time( 'timestamp' );

        if ( ! empty( $settings['remind_before_enabled'] ) && (int) $settings['remind_before_hours'] > 0 ) {
            $before_at = $due_ts - (int) $settings['remind_before_hours'] * HOUR_IN_SECONDS;
            if ( $before_at > $now ) {
                $wpdb->insert( CMMS_DB::table( 'reminders' ), array(
                    'account_id' => (int) $task->account_id,
                    'task_id'    => (int) $task->id,
                    'user_id'    => (int) $task->assigned_to,
                    'kind'       => 'before',
                    'due_at'     => gmdate( 'Y-m-d H:i:s', $before_at ),
                    'created_at' => current_time( 'mysql' ),
                ), array( '%d', '%d', '%d', '%s', '%s', '%s' ) );
            }
        }

        if ( ! empty( $settings['remind_after_enabled'] ) && (int) $settings['remind_after_hours'] > 0 ) {
            $after_at = $due_ts + (int) $settings['remind_after_hours'] * HOUR_IN_SECONDS;
            if ( $after_at > $now ) {
                $wpdb->insert( CMMS_DB::table( 'reminders' ), array(
                    'account_id' => (int) $task->account_id,
                    'task_id'    => (int) $task->id,
                    'user_id'    => (int) $task->assigned_to,
                    'kind'       => 'after',
                    'due_at'     => gmdate( 'Y-m-d H:i:s', $after_at ),
                    'created_at' => current_time( 'mysql' ),
                ), array( '%d', '%d', '%d', '%s', '%s', '%s' ) );
            }
        }
    }

    private function cancel_reminders_for_task( $task_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . CMMS_DB::table( 'reminders' ) . " SET cancelled_at = %s
             WHERE task_id = %d AND sent_at IS NULL AND cancelled_at IS NULL",
            current_time( 'mysql' ), (int) $task_id
        ) );
    }

    private function task_url( $task_id ) {
        return add_query_arg( array( 'view' => 'task', 'id' => (int) $task_id ), home_url( '/cmms-dashboard/' ) );
    }

    private function task_summary( $task, $lang = null ) {
        if ( ! $lang ) $lang = CMMS_I18n::default_lang();
        $bits = array();
        if ( ! empty( $task->priority ) ) {
            $pri_label = CMMS_I18n::t_in( 'priority.' . $task->priority, $lang ) ?: $task->priority;
            $bits[] = sprintf( CMMS_I18n::t_in( 'cron.priority_label', $lang ), $pri_label );
        }
        if ( ! empty( $task->due_date ) && $task->due_date !== '0000-00-00 00:00:00' ) {
            $bits[] = sprintf( CMMS_I18n::t_in( 'cron.due_label', $lang ), $task->due_date );
        }
        if ( ! empty( $task->description ) ) {
            $bits[] = "\n\n" . wp_strip_all_tags( $task->description );
        }
        return implode( "\n", $bits );
    }
}
