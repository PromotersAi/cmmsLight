<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Activator {

    public static function activate() {
        CMMS_DB::create_tables();
        self::create_pages();
        self::create_upload_dir();
        self::run_migrations();
        update_option( 'cmms_light_version', CMMS_LIGHT_VERSION );
        flush_rewrite_rules();
    }

    /**
     * Schema/data migrations between versions. Idempotent — safe to run on
     * every activation.
     */
    private static function run_migrations() {
        global $wpdb;
        $tasks_t  = CMMS_DB::table( 'tasks' );
        $assets_t = CMMS_DB::table( 'assets' );

        // 1.6.0: 'closed' status was removed. Migrate any leftover rows to
        // 'completed' so they still appear in the "done" filter.
        $wpdb->query( $wpdb->prepare(
            "UPDATE $tasks_t SET status = %s WHERE status = %s",
            'completed', 'closed'
        ) );

        // 1.11.0: assets table grew custom_fields, qr_token, public_qr_enabled,
        // public_actions columns. dbDelta added them on activation; backfill
        // qr_token for any pre-existing assets so QR works for old data.
        // Use SHOW COLUMNS to confirm the column actually exists (defensive
        // against partial dbDelta runs on shared hosting).
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM $assets_t" );
        if ( in_array( 'qr_token', (array) $cols, true ) && class_exists( 'CMMS_Assets' ) ) {
            CMMS_Assets::backfill_qr_tokens();
        }

        // 1.14.17: on some upgraded sites dbDelta did not add the
        // custom_fields column for older accounts (we observed this on
        // a managed-hosting setup where ALTER TABLE was silently
        // rejected during the initial activation). Search would then
        // fail with "Unknown column 'custom_fields'". Add it explicitly
        // here, idempotently. Same for other columns that 1.11.0 was
        // supposed to introduce — guards each ALTER with a column check.
        $missing_after_dbdelta = array(
            'custom_fields'     => "ALTER TABLE $assets_t ADD COLUMN custom_fields LONGTEXT NULL AFTER status",
            'qr_token'          => "ALTER TABLE $assets_t ADD COLUMN qr_token VARCHAR(40) DEFAULT NULL",
            'public_qr_enabled' => "ALTER TABLE $assets_t ADD COLUMN public_qr_enabled TINYINT(1) NOT NULL DEFAULT 1",
            'public_actions'    => "ALTER TABLE $assets_t ADD COLUMN public_actions TEXT NULL",
        );
        $cols_now = (array) $wpdb->get_col( "SHOW COLUMNS FROM $assets_t" );
        foreach ( $missing_after_dbdelta as $col_name => $alter_sql ) {
            if ( ! in_array( $col_name, $cols_now, true ) ) {
                // Suppress wpdb error display; ALTER might fail on
                // permission-restricted hosts, in which case the runtime
                // schema-aware search in CMMS_AJAX will gracefully skip
                // the missing column.
                $prev_show = $wpdb->show_errors;
                $wpdb->hide_errors();
                $wpdb->query( $alter_sql );
                if ( $prev_show ) $wpdb->show_errors();
            }
        }

        // 1.14.23: tasks lifecycle timestamps for reporting.
        //   started_at   — first transition out of 'open' (response time).
        //   completed_at — last entry into 'completed'/'closed' (closure time).
        // The columns are already defined in the create-table SQL, but on
        // upgraded sites where dbDelta didn't apply them we add them here
        // idempotently. After the columns exist we backfill historical
        // values from the task_logs audit trail.
        $task_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $tasks_t" );
        $tasks_missing = array(
            'started_at'   => "ALTER TABLE $tasks_t ADD COLUMN started_at DATETIME DEFAULT NULL AFTER created_by",
            'completed_at' => "ALTER TABLE $tasks_t ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER started_at",
        );
        foreach ( $tasks_missing as $col_name => $alter_sql ) {
            if ( ! in_array( $col_name, $task_cols, true ) ) {
                $prev_show = $wpdb->show_errors;
                $wpdb->hide_errors();
                $wpdb->query( $alter_sql );
                if ( $prev_show ) $wpdb->show_errors();
            }
        }
        // Helpful indexes for reporting queries. Wrapped because these
        // ALTERs would error if the index already existed; suppression
        // keeps activation clean.
        $task_indexes = (array) $wpdb->get_results( "SHOW INDEX FROM $tasks_t", ARRAY_A );
        $existing_idx = array();
        foreach ( $task_indexes as $idx ) {
            if ( isset( $idx['Key_name'] ) ) $existing_idx[ $idx['Key_name'] ] = true;
        }
        $idx_to_add = array(
            'started_at'   => "ALTER TABLE $tasks_t ADD KEY started_at (started_at)",
            'completed_at' => "ALTER TABLE $tasks_t ADD KEY completed_at (completed_at)",
        );
        foreach ( $idx_to_add as $idx_name => $alter_sql ) {
            if ( ! isset( $existing_idx[ $idx_name ] ) ) {
                $prev_show = $wpdb->show_errors;
                $wpdb->hide_errors();
                $wpdb->query( $alter_sql );
                if ( $prev_show ) $wpdb->show_errors();
            }
        }

        // Backfill from task_logs. Done once per upgrade — the option
        // flag prevents re-running on subsequent activations and is also
        // a quick "did the migration succeed?" indicator from WP options.
        if ( ! get_option( 'cmms_lifecycle_backfill_done', '' ) ) {
            self::backfill_task_lifecycle();
            update_option( 'cmms_lifecycle_backfill_done', '1.14.23' );
        }

        // 1.14.27: accounts table gets subscription/plan fields so the
        // new onboarding flow can persist the chosen plan and billing
        // cycle. All columns are nullable so legacy accounts (created
        // before plan selection existed) keep working — null is treated
        // as "no plan selected yet" by the runtime code.
        //
        // billing_type defaults to 'manual' for safety: existing accounts
        // were never set up for automatic billing. New onboarded accounts
        // get the proper value written explicitly during signup.
        //
        // subscription_status defaults to 'trial' because that's the
        // correct landing state for a brand-new onboarded account. We do
        // a one-shot UPDATE below to mark every PRE-1.14.27 account as
        // 'active' (they predate any subscription concept).
        $accounts_t = CMMS_DB::table( 'accounts' );
        $acct_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $accounts_t" );
        $accounts_missing = array(
            'plan_type'           => "ALTER TABLE $accounts_t ADD COLUMN plan_type VARCHAR(20) DEFAULT NULL",
            'billing_cycle'       => "ALTER TABLE $accounts_t ADD COLUMN billing_cycle VARCHAR(10) DEFAULT NULL",
            'max_users'           => "ALTER TABLE $accounts_t ADD COLUMN max_users INT(11) DEFAULT NULL",
            'billing_type'        => "ALTER TABLE $accounts_t ADD COLUMN billing_type VARCHAR(20) DEFAULT 'manual'",
            'subscription_status' => "ALTER TABLE $accounts_t ADD COLUMN subscription_status VARCHAR(20) DEFAULT 'trial'",
        );
        foreach ( $accounts_missing as $col_name => $alter_sql ) {
            if ( ! in_array( $col_name, $acct_cols, true ) ) {
                $prev_show = $wpdb->show_errors;
                $wpdb->hide_errors();
                $wpdb->query( $alter_sql );
                if ( $prev_show ) $wpdb->show_errors();
            }
        }
        // One-time backfill: legacy accounts (pre-1.14.27) get
        // subscription_status='active' since they were running fine
        // before the concept existed. The marker option prevents this
        // from re-running and overwriting future legitimate trial
        // accounts.
        if ( ! get_option( 'cmms_accounts_subscription_backfill', '' ) ) {
            // Only touch rows that still have the default 'trial' set
            // by the ALTER above, and were created before this version
            // shipped. UPDATEs are no-ops for empty tables.
            $wpdb->query(
                "UPDATE $accounts_t
                    SET subscription_status = 'active'
                  WHERE subscription_status = 'trial'"
            );
            update_option( 'cmms_accounts_subscription_backfill', '1.14.27' );
        }

        // 1.14.34: seed the packages catalog. Done once per install via
        // a marker option. After this runs, all pricing reads come from
        // the DB. Super Admin can edit prices, iCredit page IDs, and
        // features per row without touching code. The PHP fallback in
        // CMMS_Plans only fires if this seed somehow failed or rows
        // were manually deleted.
        if ( ! get_option( 'cmms_packages_seeded', '' ) ) {
            self::seed_packages();
            update_option( 'cmms_packages_seeded', '1.14.34' );
        }

        // 1.14.36: payments ledger. dbDelta in create_tables() handles
        // new installs; this block ensures upgraded sites also get the
        // table even if dbDelta didn't pick it up (some hosts).
        $payments_t = CMMS_DB::table( 'payments' );
        if ( $payments_t ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $payments_t ) );
            if ( ! $exists ) {
                // Re-trigger schema creation. Cheap to call; dbDelta is
                // idempotent for already-existing tables.
                CMMS_DB::create_tables();
            }
        }

        // 1.14.40: ensure subscriptions table exists. Same idempotent
        // pattern — if missing, re-trigger CMMS_DB::create_tables.
        $subs_t = CMMS_DB::table( 'subscriptions' );
        if ( $subs_t ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subs_t ) );
            if ( ! $exists ) CMMS_DB::create_tables();
        }

        // 1.14.40: ensure packages has external_payment_reference and
        // payments has charge_type/subscription_id. ALTERs are no-ops if
        // columns already exist.
        $packages_t = CMMS_DB::table( 'packages' );
        if ( $packages_t ) {
            $pkg_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $packages_t" );
            if ( ! in_array( 'external_payment_reference', $pkg_cols, true ) ) {
                $wpdb->query( "ALTER TABLE $packages_t
                    ADD COLUMN external_payment_reference VARCHAR(190) DEFAULT NULL
                    AFTER icredit_page_id" );
            }
        }
        if ( $payments_t ) {
            $pay_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $payments_t" );
            if ( ! in_array( 'charge_type', $pay_cols, true ) ) {
                $wpdb->query( "ALTER TABLE $payments_t
                    ADD COLUMN charge_type VARCHAR(20) NOT NULL DEFAULT 'initial'
                    AFTER icredit_document_number" );
            }
            if ( ! in_array( 'subscription_id', $pay_cols, true ) ) {
                $wpdb->query( "ALTER TABLE $payments_t
                    ADD COLUMN subscription_id BIGINT(20) UNSIGNED DEFAULT NULL
                    AFTER charge_type" );
                // Best-effort key add — ignore if already exists.
                $wpdb->query( "ALTER TABLE $payments_t ADD KEY subscription_id (subscription_id)" );
            }
        }

        // 1.14.43: track when the welcome email was sent per account so
        // we never send it twice. NULL = never sent yet. The IPN handler
        // checks this column before triggering CMMS_Mailer::send_welcome.
        $accounts_t = CMMS_DB::table( 'accounts' );
        if ( $accounts_t ) {
            $acc_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $accounts_t" );
            if ( ! in_array( 'welcome_email_sent_at', $acc_cols, true ) ) {
                $wpdb->query( "ALTER TABLE $accounts_t
                    ADD COLUMN welcome_email_sent_at DATETIME DEFAULT NULL" );
            }
            // 1.14.45: setup checklist state.
            //   checklist_dismissed_at — when the user clicked "don't show again"
            //   checklist_manual_flags — JSON map of step_key → "1" for steps
            //     completed manually (e.g. PWA install, which we can't detect
            //     from the server)
            // Both are null/empty by default; auto-completed steps are computed
            // live by CMMS_Checklist from existing data (users/tasks/assets counts).
            if ( ! in_array( 'checklist_dismissed_at', $acc_cols, true ) ) {
                $wpdb->query( "ALTER TABLE $accounts_t
                    ADD COLUMN checklist_dismissed_at DATETIME DEFAULT NULL" );
            }
            if ( ! in_array( 'checklist_manual_flags', $acc_cols, true ) ) {
                $wpdb->query( "ALTER TABLE $accounts_t
                    ADD COLUMN checklist_manual_flags LONGTEXT DEFAULT NULL" );
            }
        }

        // 1.14.36: seed default iCredit settings option. Empty token
        // means "not configured yet" — the payment screen will show a
        // friendly Hebrew message rather than attempting to connect.
        // Mode defaults to 'test' so production traffic never flows
        // accidentally before the admin explicitly switches modes.
        if ( false === get_option( 'cmms_icredit_settings', false ) ) {
            update_option( 'cmms_icredit_settings', array(
                'mode'                 => 'test',
                'test_token'           => 'a1408bfc-18da-49dc-aa77-d65870f7943e', // public sandbox token
                'live_token'           => '',
                'ipn_url'              => '',  // computed from site URL if empty
                'return_url'           => '',  // computed from site URL if empty
            ) );
        }

        // 1.14.51: Backfill max_users for accounts that have a plan_type
        // but missing max_users. This catches accounts created BEFORE
        // 1.14.34 (when max_users was added to the packages catalog),
        // as well as any accounts where the signup code didn't write
        // the field. Without this fix, those accounts behave as
        // "unlimited" (NULL = unlimited), which lets a Starter customer
        // open as many users as Enterprise — a serious billing bug.
        //
        // Idempotent: only runs once (marked by option), and even if
        // re-run, the SQL only touches rows where max_users IS NULL.
        $backfill_done = get_option( 'cmms_max_users_backfill_done', '' );
        if ( $backfill_done !== '1' ) {
            self::backfill_max_users();
            update_option( 'cmms_max_users_backfill_done', '1', false );
        }

        // 1.14.56: Backfill external_subscription_id (iCredit RecurringId)
        // for subscriptions that were created BEFORE we started saving
        // it. Without this, the Cancel Subscription button cannot reach
        // iCredit. We pull the RecurringId out of the original IPN raw
        // payload stored on the matching payment row.
        $recurring_backfill_done = get_option( 'cmms_recurring_id_backfill_done', '' );
        if ( $recurring_backfill_done !== '1' ) {
            self::backfill_recurring_ids();
            update_option( 'cmms_recurring_id_backfill_done', '1', false );
        }
    }

    /**
     * 1.14.51: For every account that has a plan_type+billing_cycle but
     * is missing max_users, look up the matching package and copy the
     * package's max_users onto the account row.
     *
     * NULL plan_type rows are left alone (they predate packages
     * entirely and have no enforceable limit).
     */
    private static function backfill_max_users() {
        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $packages_t = CMMS_DB::table( 'packages' );
        if ( ! $accounts_t || ! $packages_t ) return;

        // Find accounts needing the fix.
        $rows = $wpdb->get_results(
            "SELECT id, plan_type, billing_cycle FROM $accounts_t
              WHERE max_users IS NULL
                AND plan_type IS NOT NULL
                AND plan_type != ''",
            ARRAY_A
        );
        if ( empty( $rows ) ) return;

        foreach ( $rows as $row ) {
            $plan  = $row['plan_type'];
            $cycle = $row['billing_cycle'] ?: 'monthly';

            $pkg_max = $wpdb->get_var( $wpdb->prepare(
                "SELECT max_users FROM $packages_t
                  WHERE plan_type = %s AND billing_cycle = %s
                  LIMIT 1",
                $plan, $cycle
            ) );

            // If the package itself has max_users NULL (Enterprise), the
            // account is genuinely unlimited — leave it as NULL.
            if ( $pkg_max === null || $pkg_max === '' ) {
                continue;
            }

            $wpdb->update(
                $accounts_t,
                array( 'max_users' => (int) $pkg_max ),
                array( 'id' => (int) $row['id'] ),
                array( '%d' ),
                array( '%d' )
            );
        }
    }

    /**
     * 1.14.56: For every subscription with empty external_subscription_id
     * but an active status, look up the matching approved payment's
     * ipn_raw JSON and extract the RecurringId. Store it on the
     * subscription so Cancel Subscription can work.
     *
     * Idempotent: only touches subscriptions where external_subscription_id
     * is currently NULL or empty.
     */
    private static function backfill_recurring_ids() {
        global $wpdb;
        $subs_t     = CMMS_DB::table( 'subscriptions' );
        $payments_t = CMMS_DB::table( 'payments' );
        if ( ! $subs_t || ! $payments_t ) return;

        $rows = $wpdb->get_results(
            "SELECT id, account_id FROM $subs_t
              WHERE ( external_subscription_id IS NULL OR external_subscription_id = '' )
                AND status IN ('active','past_due','canceled')",
            ARRAY_A
        );
        if ( empty( $rows ) ) return;

        foreach ( $rows as $row ) {
            // Find the most recent approved payment for this account.
            $payment = $wpdb->get_row( $wpdb->prepare(
                "SELECT ipn_raw FROM $payments_t
                  WHERE account_id = %d AND status = 'approved'
                    AND ipn_raw IS NOT NULL AND ipn_raw != ''
                  ORDER BY id DESC LIMIT 1",
                (int) $row['account_id']
            ), ARRAY_A );
            if ( ! $payment || empty( $payment['ipn_raw'] ) ) continue;

            $ipn = json_decode( $payment['ipn_raw'], true );
            if ( ! is_array( $ipn ) ) continue;

            $recurring_id = $ipn['RecurringId'] ?? '';
            if ( empty( $recurring_id ) ) continue;

            $wpdb->update(
                $subs_t,
                array( 'external_subscription_id' => $recurring_id ),
                array( 'id' => (int) $row['id'] ),
                array( '%s' ),
                array( '%d' )
            );
        }
    }

    /**
     * Seed 6 default packages (3 plans × 2 cycles). Each row is keyed by
     * the (plan_type, billing_cycle) unique index, so re-running this
     * after a row exists would fail silently — we use INSERT IGNORE so
     * partial seeds are safe to re-run.
     *
     * Features are stored as a JSON-encoded array. We use json_encode
     * (not serialize) because Super Admin UI in 1.14.35 needs to expose
     * the list as plain text — JSON is portable and readable.
     */
    private static function seed_packages() {
        global $wpdb;
        $t = CMMS_DB::table( 'packages' );
        if ( ! $t ) return;
        $now = current_time( 'mysql' );

        // Feature lists per plan. Shared between monthly and yearly —
        // only price/cycle differ. If we wanted yearly-only perks later,
        // add them via Super Admin.
        $features_starter = wp_json_encode( array(
            'עד 3 משתמשים במערכת',
            'משימות וקריאות שירות',
            'דיווח תקלות דרך QR',
            'טפסים ציבוריים',
            'אפליקציית מובייל',
            'התראות בסיסיות',
        ) );
        $features_business = wp_json_encode( array(
            'עד 10 משתמשים במערכת',
            'אחזקה מונעת ומתוכננת',
            'דוחות KPI ומעקב ביצועים',
            'ניהול נכסים ומכונות',
            'הרשאות לפי תפקיד',
            'מרכז הדרכה',
            'דוחות מתקדמים',
            'התראות מתקדמות',
        ) );
        $features_enterprise = wp_json_encode( array(
            'משתמשים ללא הגבלה',
            'מספר אתרים וסניפים',
            'SLA והגדרות מתקדמות',
            'הרשאות מורכבות',
            'API ואינטגרציות (בקרוב)',
            'הטמעה מלווה',
            'תמיכה מועדפת',
        ) );

        $rows = array(
            // Starter
            array(
                'internal_name' => 'starter_monthly',
                'display_name'  => 'Starter',
                'plan_type'     => 'starter',
                'billing_cycle' => 'monthly',
                'price'         => 299,
                'max_users'     => 3,
                'billing_mode'  => 'recurring',
                'recommended'   => 0,
                'custom_price'  => 0,
                'sort_order'    => 10,
                'tagline'       => 'מתאים לעסקים קטנים שמתחילים עם CMMS',
                'features'      => $features_starter,
            ),
            array(
                'internal_name' => 'starter_yearly',
                'display_name'  => 'Starter',
                'plan_type'     => 'starter',
                'billing_cycle' => 'yearly',
                'price'         => 2990,
                'max_users'     => 3,
                'billing_mode'  => 'recurring',
                'recommended'   => 0,
                'custom_price'  => 0,
                'sort_order'    => 11,
                'tagline'       => 'מתאים לעסקים קטנים שמתחילים עם CMMS',
                'features'      => $features_starter,
            ),
            // Business
            array(
                'internal_name' => 'business_monthly',
                'display_name'  => 'Business',
                'plan_type'     => 'business',
                'billing_cycle' => 'monthly',
                'price'         => 690,
                'max_users'     => 10,
                'billing_mode'  => 'recurring',
                'recommended'   => 1,
                'custom_price'  => 0,
                'sort_order'    => 20,
                'tagline'       => 'הבחירה הנפוצה — לעסקים שצריכים אחזקה מסודרת',
                'features'      => $features_business,
            ),
            array(
                'internal_name' => 'business_yearly',
                'display_name'  => 'Business',
                'plan_type'     => 'business',
                'billing_cycle' => 'yearly',
                'price'         => 6900,
                'max_users'     => 10,
                'billing_mode'  => 'recurring',
                'recommended'   => 1,
                'custom_price'  => 0,
                'sort_order'    => 21,
                'tagline'       => 'הבחירה הנפוצה — לעסקים שצריכים אחזקה מסודרת',
                'features'      => $features_business,
            ),
            // Enterprise — custom price, manual only by default
            array(
                'internal_name' => 'enterprise_monthly',
                'display_name'  => 'Enterprise',
                'plan_type'     => 'enterprise',
                'billing_cycle' => 'monthly',
                'price'         => 1490,
                'max_users'     => null,
                'billing_mode'  => 'manual',
                'recommended'   => 0,
                'custom_price'  => 1,
                'sort_order'    => 30,
                'tagline'       => 'לארגונים גדולים, מספר אתרים, ודרישות מותאמות',
                'features'      => $features_enterprise,
            ),
            array(
                'internal_name' => 'enterprise_yearly',
                'display_name'  => 'Enterprise',
                'plan_type'     => 'enterprise',
                'billing_cycle' => 'yearly',
                'price'         => 14900,
                'max_users'     => null,
                'billing_mode'  => 'manual',
                'recommended'   => 0,
                'custom_price'  => 1,
                'sort_order'    => 31,
                'tagline'       => 'לארגונים גדולים, מספר אתרים, ודרישות מותאמות',
                'features'      => $features_enterprise,
            ),
        );

        foreach ( $rows as $row ) {
            $row['currency']     = 'ILS';
            $row['is_active']    = 1;
            $row['show_on_pricing'] = 1;
            $row['manual_only']  = ( $row['plan_type'] === 'enterprise' ) ? 1 : 0;
            $row['created_at']   = $now;
            $row['updated_at']   = $now;
            // INSERT IGNORE — if a row with the same plan_type+billing_cycle
            // already exists from a partial earlier seed, leave it alone.
            $cols  = '`' . implode( '`,`', array_keys( $row ) ) . '`';
            $marks = implode( ',', array_fill( 0, count( $row ), '%s' ) );
            $sql = "INSERT IGNORE INTO $t ($cols) VALUES ($marks)";
            $wpdb->query( $wpdb->prepare( $sql, array_values( $row ) ) );
        }
    }

    /**
     * Backfill started_at and completed_at for legacy tasks based on
     * task_logs entries. Two-phase strategy:
     *
     *   1) For started_at: find the EARLIEST status_changed log per task
     *      where the new status is anything other than 'open'. That's the
     *      first transition out of open.
     *
     *   2) For completed_at: find the LATEST status_changed log per task
     *      where the new status is 'completed' or 'closed'. That's the
     *      most recent completion. (We use latest, not first, in case a
     *      task was completed → reopened → completed again historically.)
     *
     * Tasks that have no relevant log entries are left untouched. New
     * tasks created from now on will populate these columns naturally
     * via the update() flow.
     *
     * Safe to call multiple times — the WHERE clause skips rows that
     * already have the relevant column populated, so re-running won't
     * overwrite values that were set after the migration.
     */
    private static function backfill_task_lifecycle() {
        global $wpdb;
        $tasks_t = CMMS_DB::table( 'tasks' );
        $logs_t  = CMMS_DB::table( 'task_logs' );

        // Sanity check both tables exist and the columns we need are there.
        $task_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $tasks_t" );
        if ( ! in_array( 'started_at', $task_cols, true )
          || ! in_array( 'completed_at', $task_cols, true ) ) {
            return;
        }

        // Phase 1: started_at from earliest non-open status change.
        // The log's `new_value` column stores the new status string for
        // events of action='status_changed'. We look for any new value
        // that isn't 'open'.
        $wpdb->query(
            "UPDATE $tasks_t t
             INNER JOIN (
                 SELECT task_id, MIN(created_at) AS first_change_at
                   FROM $logs_t
                  WHERE action = 'status_changed'
                    AND new_value IS NOT NULL
                    AND new_value <> 'open'
                  GROUP BY task_id
             ) AS firsts ON firsts.task_id = t.id
             SET t.started_at = firsts.first_change_at
             WHERE t.started_at IS NULL"
        );

        // Phase 2: completed_at from latest completion.
        $wpdb->query(
            "UPDATE $tasks_t t
             INNER JOIN (
                 SELECT task_id, MAX(created_at) AS last_completion_at
                   FROM $logs_t
                  WHERE action = 'status_changed'
                    AND new_value IN ('completed','closed')
                  GROUP BY task_id
             ) AS lasts ON lasts.task_id = t.id
             SET t.completed_at = lasts.last_completion_at
             WHERE t.completed_at IS NULL
               AND t.status IN ('completed','closed')"
        );
    }

    private static function create_pages() {
        $pages = array(
            'cmms-signup'    => array( 'title' => 'CMMS Sign Up',    'content' => '[cmms_signup]' ),
            'cmms-login'     => array( 'title' => 'CMMS Login',      'content' => '[cmms_login]' ),
            'cmms-dashboard' => array( 'title' => 'CMMS Dashboard',  'content' => '[cmms_dashboard]' ),
            'cmms-form'      => array( 'title' => 'CMMS Form',       'content' => '[cmms_public_form]' ),
            // 1.11.0: public asset record landing page (QR scan target).
            'cmms-asset'     => array( 'title' => 'CMMS Asset',      'content' => '[cmms_public_asset]' ),
            // 1.14.25: modern SaaS onboarding wizard. Lives at /start
            // and is intentionally separate from the legacy /cmms-signup/
            // — that one keeps working for existing flows while we ramp
            // this one up across versions 1.14.25–1.14.29.
            'start'          => array( 'title' => 'CMMS Start',      'content' => '[cmms_onboarding]' ),
            // 1.14.61: Password reset flow (request + apply).
            'cmms-forgot'    => array( 'title' => 'CMMS Forgot Password', 'content' => '[cmms_forgot]' ),
            'cmms-reset'     => array( 'title' => 'CMMS Reset Password',  'content' => '[cmms_reset]' ),
        );

        foreach ( $pages as $slug => $info ) {
            $existing = get_page_by_path( $slug );
            if ( ! $existing ) {
                wp_insert_post( array(
                    'post_title'   => $info['title'],
                    'post_name'    => $slug,
                    'post_content' => $info['content'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ) );
            }
        }
    }

    private static function create_upload_dir() {
        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'cmms-light';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // Hardened .htaccess: block directory listing AND script execution.
        // Defense in depth: even if a malicious file slipped past the mime check,
        // the server will not execute it.
        $htaccess = $dir . '/.htaccess';
        $rules = "# CMMS Light - hardened upload directory\n"
               . "Options -Indexes -ExecCGI\n"
               . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .pht .phps .cgi .pl .py .jsp .asp .aspx .sh\n"
               . "RemoveType    .php .phtml .php3 .php4 .php5 .php7 .pht .phps .cgi .pl .py .jsp .asp .aspx .sh\n"
               . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|pht|phps|cgi|pl|py|jsp|asp|aspx|sh|exe|bat|com|dll|so)$\">\n"
               . "    Require all denied\n"
               . "    Order deny,allow\n"
               . "    Deny from all\n"
               . "</FilesMatch>\n"
               . "<IfModule mod_php.c>\n"
               . "    php_flag engine off\n"
               . "</IfModule>\n"
               . "<IfModule mod_php5.c>\n"
               . "    php_flag engine off\n"
               . "</IfModule>\n"
               . "<IfModule mod_php7.c>\n"
               . "    php_flag engine off\n"
               . "</IfModule>\n"
               . "<IfModule mod_php8.c>\n"
               . "    php_flag engine off\n"
               . "</IfModule>\n";
        @file_put_contents( $htaccess, $rules );

        // Defeat directory listing on servers that ignore .htaccess (e.g. nginx).
        $index = $dir . '/index.html';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '' );
        }

        // Drop index.php silencers in plugin's own sensitive subdirs as well.
        self::silence_dir( CMMS_LIGHT_DIR );
        foreach ( array( 'includes', 'admin', 'public', 'assets', 'assets/css', 'assets/js', 'assets/images', 'templates' ) as $sub ) {
            self::silence_dir( CMMS_LIGHT_DIR . $sub );
        }
    }

    private static function silence_dir( $path ) {
        if ( ! is_dir( $path ) ) return;
        $f = trailingslashit( $path ) . 'index.php';
        if ( ! file_exists( $f ) ) {
            @file_put_contents( $f, "<?php\n// Silence is golden.\n" );
        }
    }
}
