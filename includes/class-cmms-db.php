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
            'email_templates'   => $p . 'cmms_email_templates',
            // 1.14.84: Telegram bot integration
            'telegram_users'    => $p . 'cmms_telegram_users',
            'telegram_logs'     => $p . 'cmms_telegram_logs',
            // 1.14.85: Mapping of task notifications to chat_id+message_id
            // for in-place edits when status changes
            'telegram_task_messages' => $p . 'cmms_telegram_task_messages',
            // 1.14.94: Webhook API keys per form for external integrations
            'webhook_keys'      => $p . 'cmms_webhook_keys',
            // 1.14.98: Email-to-task routing - one email slug per form,
            // letting external email forwards create tasks. Slug is the
            // local-part of an email address: <slug>@tasks.cmms.co.il
            'email_routes'      => $p . 'cmms_email_routes',
            // 1.15.3: Task signatures - external party (customer)
            // signing off that work was performed. Stored as PNG
            // (base64-decoded blob) plus metadata (signer name, role,
            // type — customer vs technician). Multiple signatures per
            // task allowed for cases with multiple visits or both
            // parties signing.
            'task_signatures'   => $p . 'cmms_task_signatures',
            // 1.15.5: Email-to-task inbound log. One row per incoming
            // email (success or failure) so customers can see what
            // arrived and debug missing tasks. Auto-pruned after 30 days.
            'email_inbox_log'   => $p . 'cmms_email_inbox_log',
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
            completion_lat DECIMAL(10,7) DEFAULT NULL,
            completion_lng DECIMAL(10,7) DEFAULT NULL,
            completion_address VARCHAR(500) DEFAULT NULL,
            completion_location_source VARCHAR(20) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY status (status),
            KEY assigned_to (assigned_to),
            KEY manager_id (manager_id),
            KEY created_by (created_by),
            KEY category_id (category_id),
            KEY asset_id (asset_id),
            KEY due_date (due_date),
            KEY started_at (started_at),
            KEY completed_at (completed_at),
            KEY deleted_at (deleted_at),
            KEY account_status (account_id, status)
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
            default_assignee_id BIGINT(20) UNSIGNED DEFAULT NULL,
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
            source_type VARCHAR(20) NOT NULL DEFAULT 'public_form',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY account_id (account_id),
            KEY task_id (task_id),
            KEY source_type (source_type)
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
            included_seats INT(11) DEFAULT NULL,
            seat_addon_price DECIMAL(10,2) DEFAULT NULL,
            hard_user_limit INT(11) DEFAULT NULL,
            upgrade_recommended_at INT(11) DEFAULT NULL,
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
            seats_added INT(11) NOT NULL DEFAULT 0,
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
            debug_payload LONGTEXT DEFAULT NULL,
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
            base_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            seats_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            seats_purchased INT(11) NOT NULL DEFAULT 0,
            pending_seats_change INT(11) DEFAULT NULL,
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

        // 1.14.84: Telegram users — mapping between CMMS users and
        // Telegram accounts. One row per CMMS user that connected.
        //   - link_token: short-lived nonce used in the /start deep link.
        //     Generated when user clicks "Connect Telegram" in profile;
        //     consumed (and cleared) when user runs /start <token>.
        //   - telegram_user_id: Telegram's numeric user ID, set after
        //     successful linking. NULL means "linking pending".
        //   - last_active_task_id: which task the user is currently
        //     working on. Used to route incoming photos/comments
        //     when no Reply context is available (Phase 3).
        $sql[] = "CREATE TABLE {$t['telegram_users']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            cmms_user_id BIGINT(20) UNSIGNED NOT NULL,
            telegram_user_id BIGINT(20) DEFAULT NULL,
            telegram_username VARCHAR(100) DEFAULT NULL,
            telegram_first_name VARCHAR(100) DEFAULT NULL,
            link_token VARCHAR(64) DEFAULT NULL,
            link_token_expires DATETIME DEFAULT NULL,
            last_active_task_id BIGINT(20) UNSIGNED DEFAULT NULL,
            connected_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cmms_user_id (cmms_user_id),
            KEY account_id (account_id),
            KEY telegram_user_id (telegram_user_id),
            KEY link_token (link_token)
        ) $charset;";

        // 1.14.84: Telegram message logs — every inbound and outbound
        // message for forensics + rate-limit detection. We keep raw
        // payloads (JSON) so when something misbehaves we can replay
        // the exact data that came in.
        //   - direction: 'in' (Telegram → us) or 'out' (us → Telegram)
        //   - kind: 'message', 'callback', 'photo', 'webhook_set', etc
        //   - task_id: if the log relates to a specific task (for
        //     filtering audit views)
        $sql[] = "CREATE TABLE {$t['telegram_logs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED DEFAULT NULL,
            cmms_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            telegram_user_id BIGINT(20) DEFAULT NULL,
            task_id BIGINT(20) UNSIGNED DEFAULT NULL,
            direction VARCHAR(10) NOT NULL,
            kind VARCHAR(40) NOT NULL,
            payload LONGTEXT,
            response_code INT(11) DEFAULT NULL,
            error_text TEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY telegram_user_id (telegram_user_id),
            KEY task_id (task_id),
            KEY created_at (created_at)
        ) $charset;";

        // 1.14.85: Track every Telegram message that was sent for
        // a task, per recipient. Enables in-place editing when the
        // status changes — we look up (task_id, telegram_user_id)
        // and call editMessageText with the stored message_id.
        //
        //   - chat_id: where the message was delivered (usually the
        //     telegram_user_id of the recipient)
        //   - message_id: Telegram's internal id, needed for editing
        //   - last_status: the status the message currently reflects.
        //     Used to skip no-op edits when nothing changed.
        $sql[] = "CREATE TABLE {$t['telegram_task_messages']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            cmms_user_id BIGINT(20) UNSIGNED NOT NULL,
            telegram_user_id BIGINT(20) NOT NULL,
            chat_id BIGINT(20) NOT NULL,
            message_id BIGINT(20) NOT NULL,
            last_status VARCHAR(40) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY task_recipient (task_id, cmms_user_id),
            KEY account_id (account_id),
            KEY task_id (task_id),
            KEY telegram_user_id (telegram_user_id)
        ) $charset;";

        // 1.14.94: Webhook API keys - one key per form, allows external
        // systems to create tasks via REST API by POSTing JSON.
        $sql[] = "CREATE TABLE {$t['webhook_keys']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            api_key_hash CHAR(64) NOT NULL,
            key_prefix VARCHAR(16) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_used_at DATETIME DEFAULT NULL,
            request_count INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY form_id (form_id),
            KEY account_id (account_id),
            KEY api_key_hash (api_key_hash)
        ) $charset;";

        // 1.14.98: Email-to-task routes. Each form gets a unique slug
        // (e.g. 'elevators-x7k2'); external systems forward emails to
        // <slug>@tasks.cmms.co.il and CloudFlare Email Worker POSTs them
        // to /email-inbox/<slug>. We auth via a shared HMAC secret in a
        // header — not Bearer token like webhooks, because the Worker
        // can't access per-form keys (it's a generic forwarder).
        //
        // Design rationale:
        //   - Slug is the public identifier (the email address local-part).
        //     Unguessable by design — built from random chars.
        //   - HMAC secret is shared between CloudFlare Worker and this
        //     plugin. The Worker signs the request body; we verify it.
        //     One secret per account (not per form) so the Worker doesn't
        //     need to look up secrets per incoming email.
        //   - enabled flag lets us disable a route without deleting it,
        //     useful when a customer leaks their email address.
        $sql[] = "CREATE TABLE {$t['email_routes']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            email_slug VARCHAR(64) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_used_at DATETIME DEFAULT NULL,
            request_count INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY form_id (form_id),
            UNIQUE KEY email_slug (email_slug),
            KEY account_id (account_id)
        ) $charset;";

        // 1.15.3: Task signatures. Each row is one signing event.
        // Use cases:
        //   - Customer signs to confirm work was performed (legal proof)
        //   - Technician signs to acknowledge responsibility
        //   - Multiple visits → multiple signatures
        //
        // Design rationale:
        //   - signature_data is a base64-encoded PNG (canvas.toDataURL).
        //     We store it as LONGTEXT rather than as a file because:
        //     (a) signatures are small (~5-30KB),
        //     (b) it's atomic with the row,
        //     (c) deleting the task deletes the signature automatically.
        //   - signer_type distinguishes customer from technician so
        //     the UI can show "Customer signature" vs "Technician signature".
        //   - signer_name is mandatory — a signature without a name is
        //     not legally binding in most jurisdictions.
        //   - signer_role is the company/title (e.g. "Maintenance Manager"
        //     or "ABC Corp") — optional but commonly recorded.
        //   - signed_ip helps with disputes (proves who signed from where).
        //   - signed_by_user_id ties the signature to a logged-in user
        //     when applicable (the technician); null for external customers.
        $sql[] = "CREATE TABLE {$t['task_signatures']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            signer_type VARCHAR(20) NOT NULL DEFAULT 'customer',
            signer_name VARCHAR(190) NOT NULL,
            signer_role VARCHAR(190) DEFAULT NULL,
            signature_data LONGTEXT NOT NULL,
            signed_by_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            signed_ip VARCHAR(45) DEFAULT NULL,
            signed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY task_id (task_id),
            KEY account_id (account_id),
            KEY signer_type (signer_type)
        ) $charset;";

        // 1.15.5: Email-to-task inbound log. One row per incoming email.
        //
        // Purpose:
        //   - Visibility: customer sees what emails arrived and which
        //     became tasks.
        //   - Debugging: failed emails (too large, bad form, etc) are
        //     logged with the failure reason so issues are diagnosable.
        //
        // Design rationale:
        //   - status: 'created' (task made) | 'failed' (rejected) | 'test'
        //   - task_id is nullable — failures and tests have no task.
        //   - We store subject/sender but NOT the full body (privacy +
        //     storage). The body lives in the task that was created.
        //   - error_message holds the failure reason for failed rows.
        //   - Auto-pruned after 30 days via prune_email_log (scheduled).
        $sql[] = "CREATE TABLE {$t['email_inbox_log']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            form_id BIGINT(20) UNSIGNED DEFAULT NULL,
            email_slug VARCHAR(64) DEFAULT NULL,
            from_email VARCHAR(190) DEFAULT NULL,
            from_name VARCHAR(190) DEFAULT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'created',
            task_id BIGINT(20) UNSIGNED DEFAULT NULL,
            error_message VARCHAR(255) DEFAULT NULL,
            received_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY form_id (form_id),
            KEY received_at (received_at),
            KEY status (status)
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
