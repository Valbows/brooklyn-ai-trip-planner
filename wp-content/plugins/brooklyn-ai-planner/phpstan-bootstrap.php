<?php
// Minimal stubs so PHPStan understands WordPress constants/functions.
if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'BATP_PLUGIN_PATH' ) ) {
	define( 'BATP_PLUGIN_PATH', __DIR__ . '/' );
}

if ( ! defined( 'BATP_PLUGIN_URL' ) ) {
	define( 'BATP_PLUGIN_URL', 'https://example.com/wp-content/plugins/brooklyn-ai-planner/' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text ) {
		return $text;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $option_group, $option_name, $args = array() ) { // phpcs:ignore
	}
}

if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( $id, $title, $callback, $page ) { // phpcs:ignore
	}
}

if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) { // phpcs:ignore
	}
}

if ( ! function_exists( 'add_options_page' ) ) {
	function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback = null ) { // phpcs:ignore
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_scalar( $str ) ? (string) $str : '';
	}
}

if ( ! function_exists( 'wp_register_block_types_from_metadata_collection' ) ) {
	function wp_register_block_types_from_metadata_collection() { // phpcs:ignore
	}
}

if ( ! function_exists( 'wp_register_block_metadata_collection' ) ) {
	function wp_register_block_metadata_collection() { // phpcs:ignore
	}
}

if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type() { // phpcs:ignore
	}
}

if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( $option_group ) { // phpcs:ignore
	}
}

if ( ! function_exists( 'do_settings_sections' ) ) {
	function do_settings_sections( $page ) { // phpcs:ignore
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button() { // phpcs:ignore
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return new class() {
			public function add_help_tab( $args ) { // phpcs:ignore
			}
		};
	}
}
