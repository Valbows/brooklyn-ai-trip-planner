<?php
/**
 * Plugin Name:       Brooklyn AI Planner
 * Description:       Intelligent itinerary builder for VisitBrooklyn using Gemini, Pinecone, and Supabase.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            VisitBrooklyn Engineering
 * License:           Proprietary
 * Text Domain:       brooklyn-ai-planner
 *
 * @package BrooklynAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BATP_PLUGIN_FILE', __FILE__ );
define( 'BATP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'BATP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'BATP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BATP_PLUGIN_VERSION', '0.1.0' );

$batp_autoloader = BATP_PLUGIN_PATH . 'vendor/autoload.php';
if ( file_exists( $batp_autoloader ) ) {
	require $batp_autoloader;
}

$batp_custom_autoloader = BATP_PLUGIN_PATH . 'includes/class-autoloader.php';
if ( file_exists( $batp_custom_autoloader ) ) {
	require_once $batp_custom_autoloader;
	BrooklynAI\Autoloader::register();
}

$batp_plugin_class   = BATP_PLUGIN_PATH . 'includes/class-plugin.php';
$batp_security_class = BATP_PLUGIN_PATH . 'includes/class-security-manager.php';
$batp_cache_class    = BATP_PLUGIN_PATH . 'includes/class-cache-service.php';
$batp_logger_class   = BATP_PLUGIN_PATH . 'includes/class-analytics-logger.php';
$batp_settings_class = BATP_PLUGIN_PATH . 'admin/class-settings-page.php';
$batp_supabase_class = BATP_PLUGIN_PATH . 'includes/clients/class-supabase-client.php';
$batp_pinecone_class = BATP_PLUGIN_PATH . 'includes/clients/class-pinecone-client.php';
$batp_gemini_class   = BATP_PLUGIN_PATH . 'includes/clients/class-gemini-client.php';
$batp_maps_class     = BATP_PLUGIN_PATH . 'includes/clients/class-googlemaps-client.php';

foreach ( array( $batp_security_class, $batp_cache_class, $batp_logger_class, $batp_settings_class, $batp_supabase_class, $batp_pinecone_class, $batp_gemini_class, $batp_maps_class, $batp_plugin_class ) as $batp_file ) {
	if ( file_exists( $batp_file ) ) {
		require_once $batp_file;
	}
}

if ( ! class_exists( 'BrooklynAI\\Plugin' ) ) {
	// Surface an admin notice if autoloading fails so misconfiguration is obvious.
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Brooklyn AI Planner is missing required dependencies. Please run Composer install.', 'brooklyn-ai-planner' )
			);
		}
	);
	return;
}

register_activation_hook( __FILE__, array( 'BrooklynAI\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BrooklynAI\\Plugin', 'deactivate' ) );

BrooklynAI\Plugin::instance()->boot();
