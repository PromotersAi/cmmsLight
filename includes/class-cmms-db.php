<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_DB {

    public static function tables() {
        global $wpdb;
        $p = $wpdb->prefix;
        return array(
            'accounts'         => $p . 'cmms_accounts',
            'users'            => $p . 'cmms_users',
            'tasks'            => $p . 'cmms_tasks',
            'task_logs'        => $p . 'cmms_task_logs',
            'assets'           => $p . 'cmms_assets',
            'asset_field_defs' => $p . 'cmms_asset_field_defs',
            'attachments'      => $p . 'cmms_attachments',
            'categories'       => $p . 'cmms_categories',
            'forms'            => $p . 'cmms_forms',
            'form_fields'      => $p . 'cmms_form_fields',
            'form_submissions' => $p . 'cmms_form_submissions',
            'notifications'    => $p . 'cmms_notifications',
            'reminders'        => $p . 'cmms_reminders',
            'push_subs'        => $p . 'cmms_push_subs',
            'packages'         => $p . 'cmms_packages',
            'payments'         => $p . 'cmms_payments',
            'subscriptions'    => $p . 'cmms_subscriptions',
            'email_templates'  => $p . 'cmms_email_templates',
        );
    }

    public static function table( $key ) {
        $tables = self::tables();
        return isset( $tables[ $key ] ) ? $tables[ $key ] : null;
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $t = self::tables();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = array();

        $sql[] = "CREATE TABLE {$t['accounts']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            owner_user_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            plan_type VARCHAR(20) DEFAULT NULL,
            billing_cycle VARCHAR(10) DEFAULT NULL,
            max_users INT(11) DEFAULT NULL,
            billing_type VARCHAR(20) DEFAULT 'manual',
            subscription_status VARCHAR(20) DEFAULT 'trial',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY owner_user_id (owner_user_id),
            KEY status (status),
            KEY plan_type (plan_type),
            KEY subscription_status (subscription_status)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['users']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT(20) UNSIGNED NOT NULL,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'technician',
            display_name VARCHAR(190) DEFAULT NULL,
            phone VARCHAR(40) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY account_id (account_id),
            KEY role (role)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['categories']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY slug (slug)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['assets']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            asset_type VARCHAR(50) DEFAULT 'machine',
            location VARCHAR(255) DEFAULT NULL,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            -- Per-asset structured custom fields. Stored as JSON for now to
            -- avoid the complexity of normalized tables; each entry has
            -- {field_key, label, type, value}. We can migrate to a real
            -- table later without changing the read API since CMMS_Assets
            -- is the only path that touches this column.
            custom_fields LONGTEXT,
            -- QR + public-view fields (added 1.11.0):
            qr_token VARCHAR(40) DEFAULT NULL,
            public_qr_enabled TINYINT(1) NOT NULL DEFAULT 1,
            public_actions TEXT,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY asset_type (asset_type),
            UNIQUE KEY qr_token (qr_token)
        ) $charset;";

        // Field definitions for the Asset Record. One row per (account, field).
        // Each customer (account) configures their own set of fields:
        //   Hotel:    Floor, Room number, View
        //   Workshop: Serial number, Manufacturer, Voltage
        // Per-asset values live in `assets.custom_fields` (JSON).
        // Note: `asset_type` column was added in 1.11.0 to allow per-type
        // scoping of fields. Per spec change in 1.12.0 we no longer USE
        // this column (all fields apply to all assets), but we keep it in
        // the schema so we don't have to break/migrate later when scoping
        // is reintroduced.
        $sql[] = "CREATE TABLE {$t['asset_field_defs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            field_key VARCHAR(64) NOT NULL,
            label VARCHAR(120) NOT NULL,
            field_type VARCHAR(20) NOT NULL DEFAULT 'text',
            options TEXT,
            required TINYINT(1) NOT NULL DEFAULT 0,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT(11) NOT NULL DEFAULT 0,
            asset_type VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY account_field (account_id, field_key),
            KEY account_id (account_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['tasks']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT,
            category_id BIGINT(20) UNSIGNED DEFAULT NULL,
            asset_id BIGINT(20) UNSIGNED DEFAULT NULL,
            manager_id BIGINT(20) UNSIGNED DEFAULT NULL,
            assigned_to BIGINT(20) UNSIGNED DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            due_date DATETIME DEFAULT NULL,
            recurrence_type VARCHAR(20) DEFAULT 'one_time',
            recurrence_interval INT(11) DEFAULT 0,
            recurrence_until DATETIME DEFAULT NULL,
            next_run DATETIME DEFAULT NULL,
            source VARCHAR(50) DEFAULT 'internal',
            external_data LONGTEXT,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY status (status),
            KEY assigned_to (assigned_to),
            KEY manager_id (manager_id),
            KEY category_id (category_id),
            KEY asset_id (asset_id),
            KEY due_date (due_date),
            KEY started_at (started_at),
            KEY completed_at (completed_at)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['task_logs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            action VARCHAR(60) NOT NULL,
            note TEXT,
            old_value VARCHAR(255) DEFAULT NULL,
            new_value VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY task_id (task_id),
            KEY account_id (account_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['attachments']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            object_type VARCHAR(40) NOT NULL,
            object_id BIGINT(20) UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) DEFAULT NULL,
            file_url TEXT NOT NULL,
            file_path TEXT,
            mime_type VARCHAR(100) DEFAULT NULL,
            uploaded_by BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY object_type_id (object_type, object_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['forms']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            slug VARCHAR(190) NOT NULL,
            manager_id BIGINT(20) UNSIGNED DEFAULT NULL,
            default_category_id BIGINT(20) UNSIGNED DEFAULT NULL,
            default_priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            default_status VARCHAR(20) NOT NULL DEFAULT 'open',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY account_id (account_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['form_fields']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            label VARCHAR(255) NOT NULL,
            field_type VARCHAR(30) NOT NULL,
            options TEXT,
            required TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['form_submissions']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            task_id BIGINT(20) UNSIGNED DEFAULT NULL,
            data LONGTEXT,
            ip_address VARCHAR(60) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY account_id (account_id),
            KEY task_id (task_id)
        ) $charset;";

        // In-app notification feed (one row per recipient per event).
        $sql[] = "CREATE TABLE {$t['notifications']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            task_id BIGINT(20) UNSIGNED DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT,
            url VARCHAR(500) DEFAULT NULL,
            read_at DATETIME DEFAULT NULL,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            email_sent_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY account_user (account_id, user_id),
            KEY user_unread (user_id, read_at),
            KEY task_id (task_id)
        ) $charset;";

        // Scheduled reminders queue. Cron sweeps and dispatches when due_at <= NOW().
        $sql[] = "CREATE TABLE {$t['reminders']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            kind VARCHAR(40) NOT NULL,
            due_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY due_pending (due_at, sent_at, cancelled_at),
            KEY task_id (task_id),
            KEY account_id (account_id)
        ) $charset;";

        // Web Push subscriptions. One row per user-per-device.
        // The endpoint is the unique identifier (Google FCM / Mozilla / Apple Push URL).
        $sql[] = "CREATE TABLE {$t['push_subs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth_key VARCHAR(255) NOT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            failure_count INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY endpoint_uniq (endpoint(191)),
            KEY user_id (user_id),
            KEY account_id (account_id)
        ) $charset;";

        // Packages catalog (1.14.34).
        $sql[] = "CREATE TABLE {$t['packages']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            internal_name VARCHAR(64) NOT NULL,
            display_name VARCHAR(190) NOT NULL,
            plan_type VARCHAR(20) DEFAULT NULL,
            billing_cycle VARCHAR(10) NOT NULL DEFAULT 'monthly',
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'ILS',
            max_users INT(11) DEFAULT NULL,
            billing_mode VARCHAR(20) NOT NULL DEFAULT 'one_time',
            icredit_page_id VARCHAR(190) DEFAULT NULL,
            external_payment_reference VARCHAR(190) DEFAULT NULL,
            success_redirect_url VARCHAR(500) DEFAULT NULL,
            failure_redirect_url VARCHAR(500) DEFAULT NULL,
            cancel_redirect_url VARCHAR(500) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            show_on_pricing TINYINT(1) NOT NULL DEFAULT 1,
            manual_only TINYINT(1) NOT NULL DEFAULT 0,
            recommended TINYINT(1) NOT NULL DEFAULT 0,
            custom_price TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT(11) NOT NULL DEFAULT 0,
            tagline VARCHAR(255) DEFAULT NULL,
            features LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY plan_cycle (plan_type, billing_cycle),
            KEY is_active (is_active),
            KEY show_on_pricing (show_on_pricing),
            KEY sort_order (sort_order)
        ) $charset;";

        // Payments ledger (1.14.36).
        // One row per attempted payment. We record EVERY attempt — even
        // failures — because:
        //   - Debugging: when a customer says "I tried to pay 3 times",
        //     we need to see all 3 attempts.
        //   - Audit: chargebacks and refunds need the full trail.
        //   - Reconciliation: matching what iCredit shows vs what we
        //     recorded relies on having all events.
        //
        // Status lifecycle:
        //   pending  → row created, redirect to iCredit
        //   approved → IPN webhook confirmed success
        //   declined → IPN webhook confirmed failure
        //   expired  → user never returned (> 24h since pending)
        //   refunded → admin refunded later (future feature)
        //
        // The iCredit tokens (PublicSaleToken, PrivateSaleToken) are
        // stored separately. PublicSaleToken is the one returned in
        // the URL when the user comes back; PrivateSaleToken is used
        // for server-side Verify calls.
        $sql[] = "CREATE TABLE {$t['payments']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            package_id BIGINT(20) UNSIGNED DEFAULT NULL,
            plan_type VARCHAR(20) DEFAULT NULL,
            billing_cycle VARCHAR(10) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'ILS',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            provider VARCHAR(20) NOT NULL DEFAULT 'icredit',
            provider_mode VARCHAR(10) DEFAULT 'test',
            icredit_public_token VARCHAR(64) DEFAULT NULL,
            icredit_private_token VARCHAR(64) DEFAULT NULL,
            icredit_sale_id VARCHAR(64) DEFAULT NULL,
            icredit_document_number VARCHAR(64) DEFAULT NULL,
            charge_type VARCHAR(20) NOT NULL DEFAULT 'initial',
            subscription_id BIGINT(20) UNSIGNED DEFAULT NULL,
            ipn_raw LONGTEXT DEFAULT NULL,
            ipn_received_at DATETIME DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY status (status),
            KEY icredit_public_token (icredit_public_token),
            KEY subscription_id (subscription_id),
            KEY created_at (created_at)
        ) $charset;";

        // Subscriptions (1.14.40).
        // One row per active subscription. Created when the FIRST
        // payment for an account is approved (charge_type='initial'),
        // updated on subsequent recurring charges. Lifecycle:
        //   active   → currently paid up, system fully accessible
        //   past_due → last charge failed, in grace period (7 days)
        //   frozen   → grace expired, account access blocked
        //   canceled → admin canceled or user requested cancellation
        //
        // next_charge_at is informational (we don't initiate charges —
        // iCredit does). grace_until is what gates the frozen transition.
        //
        // 1.14.60: pending_* columns track scheduled downgrades. When a
        // user requests a downgrade, we don't change the active plan
        // immediately — we mark pending_plan_type/pending_billing_cycle
        // and pending_change_effective_at. A cron job (and the IPN
        // handler on next recurring charge) applies the change once
        // the effective_at date is reached.
        $sql[] = "CREATE TABLE {$t['subscriptions']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            package_id BIGINT(20) UNSIGNED DEFAULT NULL,
            plan_type VARCHAR(20) DEFAULT NULL,
            billing_cycle VARCHAR(10) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'ILS',
            provider VARCHAR(20) NOT NULL DEFAULT 'icredit',
            external_subscription_id VARCHAR(190) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            started_at DATETIME NOT NULL,
            last_charge_at DATETIME DEFAULT NULL,
            next_charge_at DATETIME DEFAULT NULL,
            last_failure_at DATETIME DEFAULT NULL,
            grace_until DATETIME DEFAULT NULL,
            canceled_at DATETIME DEFAULT NULL,
            cancellation_reason VARCHAR(255) DEFAULT NULL,
            pending_plan_type VARCHAR(20) DEFAULT NULL,
            pending_billing_cycle VARCHAR(10) DEFAULT NULL,
            pending_change_effective_at DATETIME DEFAULT NULL,
            pending_change_type VARCHAR(20) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY account_id (account_id),
            KEY status (status),
            KEY next_charge_at (next_charge_at),
            KEY grace_until (grace_until),
            KEY pending_change_effective_at (pending_change_effective_at)
        ) $charset;";

        // 1.14.59: Email Templates table. Stores per-key the editable
        // subject + HTML body. Used by CMMS_Mailer at send time.
        // If a key has no row, mailer falls back to hardcoded defaults.
        //
        // template_key examples: 'welcome', 'past_due', 'frozen',
        // 'subscription_canceled'. Unique per key — overwriting an
        // existing key updates the row (one template per key).
        $sql[] = "CREATE TABLE {$t['email_templates']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(60) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html LONGTEXT NOT NULL,
            updated_by BIGINT(20) UNSIGNED DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY template_key (template_key)
        ) $charset;";

        foreach ( $sql as $q ) {
            dbDelta( $q );
        }
    }

    public static function drop_tables() {
        global $wpdb;
        foreach ( self::tables() as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }
    }
}
