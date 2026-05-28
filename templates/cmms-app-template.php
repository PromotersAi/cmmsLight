<?php
/**
 * Template Name: CMMS App
 * Template Post Type: page
 *
 * Renders a CMMS page (signup, login, dashboard, public form) as a full-screen
 * standalone application with no theme chrome.
 *
 * @package CMMSLight
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! headers_sent() ) {
    header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex' ); 
}

add_filter( 'show_admin_bar', '__return_false' );

remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'rest_output_link_wp_head' );
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
remove_action( 'wp_head', 'wp_oembed_add_host_js' );
remove_action( 'wp_head', 'feed_links', 2 );
remove_action( 'wp_head', 'feed_links_extra', 3 );
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );

add_filter( 'pre_get_document_title', function() { return 'CMMS'; }, 100 );

$lang = CMMS_I18n::current();
$dir  = CMMS_I18n::dir();
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="format-detection" content="telephone=no">
<title>CMMS</title>
<?php
// Note: manifest, theme-color, and other PWA meta tags are injected by
// CMMS_PWA::manifest_link() via the wp_head() call below. We deliberately
// do NOT hardcode <link rel="manifest"> here, because that would create
// a duplicate (and incorrect) reference to /cmms-manifest.json — a path
// that doesn't exist. The real manifest URL is /cmms-dashboard/?cmms_pwa=manifest.
?>
<style>
    html, body { margin: 0 !important; padding: 0 !important; background: #f1f5f9 !important; }
    body { color: #0f172a; min-height: 100vh; }
    body > header, body > footer, body > .site-header, body > .site-footer,
    .elementor-location-header, .elementor-location-footer,
    #wpadminbar, html.wp-toolbar { display: none !important; }
    html { margin-top: 0 !important; }
</style>
<?php wp_head(); ?>
</head>
<body class="cmms-standalone" dir="<?php echo esc_attr( $dir ); ?>">
<?php
while ( have_posts() ) :
    the_post();
    the_content();
endwhile;
?>
<?php wp_footer(); ?>
</body>
</html>
