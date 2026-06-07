<?php
/**
 * 1.14.87 — Account-level settings for task lifecycle behaviours.
 *
 * Settings stored here:
 *   require_location_on_complete: bool — when true, the system asks
 *     the technician to share their GPS location when they mark a
 *     task as completed. If they decline, completion still goes
 *     through but is flagged (no location captured).
 *
 * Pattern mirrors CMMS_Notifications::*_account_settings — settings
 * are stored as a single serialized array in a wp_options key
 * named cmms_task_settings_{account_id}. Defaults from default_settings().
 *
 * Future settings to add here (kept as a single class for cohesion):
 *   - default_priority
 *   - auto_close_after_days
 *   - require_photo_on_complete
 *   - etc.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Task_Settings {

    public static function default_settings() {
        return array(
            // 1.14.87: GPS-on-completion. Defaults to OFF — only the
            // accounts that need it (field service, contractors) turn
            // it on. Office/factory accounts never see the GPS prompt.
            'require_location_on_complete' => 0,
        );
    }

    public static function get( $account_id ) {
        $key = 'cmms_task_settings_' . (int) $account_id;
        $stored = get_option( $key );
        if ( ! is_array( $stored ) ) $stored = array();
        return wp_parse_args( $stored, self::default_settings() );
    }

    public static function save( $account_id, $settings ) {
        $defaults = self::default_settings();
        $clean = array();
        foreach ( $defaults as $k => $default ) {
            if ( ! isset( $settings[ $k ] ) ) {
                $clean[ $k ] = $default;
                continue;
            }
            // All current settings are booleans. Add type-specific
            // handling here when more setting types are introduced.
            $clean[ $k ] = $settings[ $k ] ? 1 : 0;
        }
        update_option( 'cmms_task_settings_' . (int) $account_id, $clean, false );
        return $clean;
    }

    /**
     * Shortcut for the most-used flag.
     */
    public static function require_location_on_complete( $account_id ) {
        $s = self::get( $account_id );
        return ! empty( $s['require_location_on_complete'] );
    }
}
