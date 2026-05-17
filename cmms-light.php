<?php
/**
 * Plugin Name: CMMS Light
 * Plugin URI: https://example.com/cmms-light
 * Description: A complete Computerized Maintenance Management System (CMMS) with multi-tenant accounts, tasks, assets, forms, and PWA support.
 * Version: 1.14.70
 * Author: CMMS Light
 * License: GPL-2.0+
 * Text Domain: cmms-light
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CMMS_LIGHT_VERSION', '1.14.70' );
define( 'CMMS_LIGHT_FILE', __FILE__ );
define( 'CMMS_LIGHT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CMMS_LIGHT_URL', plugin_dir_url( __FILE__ ) );
define( 'CMMS_LIGHT_BASENAME', plugin_basename( __FILE__ ) );

require_once CMMS_LIGHT_DIR . 'includes/class-cmms-db.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-i18n.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-icons.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-time-range.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-activator.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-deactivator.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-auth.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-accounts.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-users.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-categories.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-assets.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-tasks.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-forms.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-attachments.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-reports.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-notifications.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-push.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-cron.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-demo.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-pwa.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-whitelabel.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-help.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-plans.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-subscriptions.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-email-templates.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-mailer.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-plan-changes.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-icredit.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-checklist.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-shortcodes.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-ajax.php';
require_once CMMS_LIGHT_DIR . 'vendor/simplexlsx/SimpleXLSX.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-import.php';
require_once CMMS_LIGHT_DIR . 'admin/class-cmms-admin.php';
require_once CMMS_LIGHT_DIR . 'admin/class-cmms-admin-packages.php';
require_once CMMS_LIGHT_DIR . 'admin/class-cmms-admin-icredit.php';
require_once CMMS_LIGHT_DIR . 'admin/class-cmms-admin-payments.php';
require_once CMMS_LIGHT_DIR . 'admin/class-cmms-admin-email-templates.php';
require_once CMMS_LIGHT_DIR . 'public/class-cmms-public.php';
require_once CMMS_LIGHT_DIR . 'includes/class-cmms-plugin.php';

register_activation_hook( __FILE__, array( 'CMMS_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CMMS_Deactivator', 'deactivate' ) );

function cmms_light_run() {
    $plugin = new CMMS_Plugin();
    $plugin->run();
}
add_action( 'plugins_loaded', 'cmms_light_run' );
