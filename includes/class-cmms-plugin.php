<?php
/**
 * Plugin orchestrator. Instantiates and wires the moving parts.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMMS_Plugin {

    public function run() {
        load_plugin_textdomain( 'cmms-light', false, dirname( CMMS_LIGHT_BASENAME ) . '/languages' );

        // Auto-upgrade: when version changes, ONLY ensure new DB tables exist.
        // We deliberately do NOT call full activate() here — that runs page
        // creation, file system writes, and rewrite flushes, any of which can
        // fail loudly on certain hosts and take down the dashboard.
        $stored = get_option( 'cmms_light_version' );
        if ( $stored !== CMMS_LIGHT_VERSION ) {
            if ( class_exists( 'CMMS_DB' ) ) {
                CMMS_DB::create_tables(); // dbDelta is idempotent
            }
            update_option( 'cmms_light_version', CMMS_LIGHT_VERSION );
            // Schedule a rewrite flush for the next request, not this one.
            update_option( 'cmms_needs_rewrite_flush', 1 );
        }

        // If a flush was scheduled, do it now (after this request finishes).
        if ( get_option( 'cmms_needs_rewrite_flush' ) ) {
            add_action( 'shutdown', function() {
                flush_rewrite_rules( false );
                delete_option( 'cmms_needs_rewrite_flush' );
            } );
        }

        // Shortcodes (constructor self-registers).
        new CMMS_Shortcodes();

        // PWA (constructor self-registers).
        new CMMS_PWA();

        // Form action handlers.
        $ajax = new CMMS_AJAX();
        $ajax->register();

        // Frontend assets.
        $public = new CMMS_Public();
        $public->register();

        // White-label / branding hooks (hide WP from end users).
        $whitelabel = new CMMS_Whitelabel();
        $whitelabel->register();

        // Notification cron + event hooks.
        $cron = new CMMS_Cron();
        $cron->register();
        // Make sure the recurring sweep is scheduled.
        CMMS_Cron::ensure_scheduled();

        // 1.14.84: Telegram webhook (REST API endpoint).
        // Self-registers via add_action('rest_api_init', ...) so it's
        // safe to call even when Telegram is disabled — the route
        // exists but rejects every request without a valid secret.
        CMMS_Telegram_Webhook::init();

        // 1.14.94: Webhook API for external task creation. Same pattern
        // as Telegram_Webhook — registers a REST route that does its own
        // auth (Bearer token in Authorization header).
        add_action( 'rest_api_init', array( 'CMMS_Webhook', 'register_routes' ) );

        // 1.14.98: Email-to-task. Receives forwarded emails as JSON
        // (delivered via CloudFlare Email Worker) and creates tasks.
        // Auth is HMAC signature in 'X-CMMS-Signature' header.
        add_action( 'rest_api_init', array( 'CMMS_Email_Inbox', 'register_routes' ) );

        // Global admin (only inside wp-admin).
        if ( is_admin() ) {
            $admin = new CMMS_Admin();
            $admin->register();
        }
    }
}
