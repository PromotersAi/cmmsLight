<?php
/**
 * CMMS Light - Uninstall handler
 *
 * Fired when the plugin is deleted from WordPress admin.
 *
 * SAFE BY DEFAULT: We do NOT drop tables or delete pages on uninstall unless
 * the site administrator has explicitly opted in by setting the option 
 * `cmms_light_drop_data_on_uninstall` to a truthy value. This is to prevent
 * accidental, irreversible data loss when the plugin is upgraded by deleting
 * and re-uploading the ZIP (which is what less experienced users sometimes do).
 *
 * Opt-in to actually drop data:
 *     update_option( 'cmms_light_drop_data_on_uninstall', 1 );
 *
 * @package CMMSLight
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Default: PRESERVE all data. The user can re-install / upgrade safely.
$drop = (int) get_option( 'cmms_light_drop_data_on_uninstall' );
if ( ! $drop ) {
	return;
}

// User explicitly opted in. Drop everything.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cmms-db.php';

if ( class_exists( 'CMMS_DB' ) ) {
	CMMS_DB::drop_tables();
}

// Remove plugin pages created on activation.
$slugs = array( 'cmms-signup', 'cmms-login', 'cmms-dashboard', 'cmms-form' );
foreach ( $slugs as $slug ) {
	$page = get_page_by_path( $slug );
	if ( $page ) {
		wp_delete_post( $page->ID, true );
	}
}

// Remove plugin options.
delete_option( 'cmms_light_version' );
delete_option( 'cmms_light_db_version' );
delete_option( 'cmms_light_drop_data_on_uninstall' );
delete_option( 'cmms_smtp_warning_dismissed' );
delete_option( 'cmms_cron_last_run' );

// Per-account notify settings - find and delete.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cmms_notify_settings_%'" );

flush_rewrite_rules();

