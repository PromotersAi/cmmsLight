<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Deactivator {
    public static function deactivate() {
        // Stop the reminder sweep so it doesn't keep running on a disabled plugin.
        if ( class_exists( 'CMMS_Cron' ) ) {
            CMMS_Cron::unschedule();
        }
        flush_rewrite_rules();
    }
}
