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

$batp_custom_autoloader = BATP_PLUGIN_PATH . 'includes/class-autoloader.php';
if ( file_exists( $batp_custom_autoloader ) ) {
	require_once $batp_custom_autoloader;
	BrooklynAI\Autoloader::register();
}

/**
 * Ensures all required class files are loaded when Composer autoload is unavailable.
 */
function brooklyn_ai_planner_load_dependencies(): void {
	static $batp_loaded = false;
	if ( $batp_loaded ) {
		return;
	}

	$batp_files = array(
		BATP_PLUGIN_PATH . 'includes/class-plugin.php',
		BATP_PLUGIN_PATH . 'includes/class-security-manager.php',
		BATP_PLUGIN_PATH . 'includes/class-cache-service.php',
		BATP_PLUGIN_PATH . 'includes/class-analytics-logger.php',
		BATP_PLUGIN_PATH . 'admin/class-settings-page.php',
		BATP_PLUGIN_PATH . 'includes/clients/class-supabase-client.php',
		BATP_PLUGIN_PATH . 'includes/clients/class-pinecone-client.php',
		BATP_PLUGIN_PATH . 'includes/clients/class-gemini-client.php',
		BATP_PLUGIN_PATH . 'includes/clients/class-googlemaps-client.php',
	);

	foreach ( $batp_files as $batp_file ) {
		if ( file_exists( $batp_file ) ) {
			require_once $batp_file;
		}
	}

	$batp_loaded = true;
}

brooklyn_ai_planner_load_dependencies();

register_activation_hook( __FILE__, array( 'BrooklynAI\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BrooklynAI\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', 'brooklyn_ai_planner_bootstrap' );

/**
 * Boots the main plugin instance once WordPress is fully loaded.
 */
function brooklyn_ai_planner_bootstrap(): void {
	brooklyn_ai_planner_load_dependencies();

	if ( ! class_exists( 'BrooklynAI\\Plugin' ) ) {
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

	BrooklynAI\Plugin::instance()->boot();
}
