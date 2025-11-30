<?php
/**
 * Core plugin bootstrap.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI;

use BrooklynAI\Admin\Settings_Page;
use BrooklynAI\Admin\Reports_Page;
use BrooklynAI\API\REST_Controller;
use BrooklynAI\Clients\Gemini_Client;
use BrooklynAI\Clients\GoogleMaps_Client;
use BrooklynAI\Clients\Google_Places_Client;
use BrooklynAI\Clients\Google_Directions_Client;
use BrooklynAI\Clients\Supabase_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private static ?Plugin $instance = null;
	private bool $booted             = false;
	private Security_Manager $security;
	private Cache_Service $cache;
	private Analytics_Logger $analytics;
	private Settings_Page $settings_page;
	private Reports_Page $reports_page;
	private REST_Controller $rest_controller;
	private Supabase_Client $supabase;
	private ?Gemini_Client $gemini                       = null;
	private ?GoogleMaps_Client $maps                     = null;
	private ?Google_Places_Client $google_places         = null;
	private ?Google_Directions_Client $google_directions = null;
	/**
	 * @var array<string, mixed>
	 */
	private array $settings = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate(): void {
		flush_rewrite_rules();
		// MBA scheduled jobs removed - using Google Places API for real-time data
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
		// Clean up any legacy MBA scheduled hooks
		wp_clear_scheduled_hook( 'batp_daily_mba_refresh' );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->load_settings();
		$this->initialize_services();
		$this->register_hooks();
		$this->maybe_register_cli_commands();

		$this->booted = true;
	}

	private function load_settings(): void {
		$stored         = get_option( 'batp_settings', array() );
		$this->settings = is_array( $stored ) ? $stored : array();
	}

	private function initialize_services(): void {
		$this->security        = new Security_Manager();
		$this->cache           = new Cache_Service();
		$this->supabase        = $this->make_supabase_client();
		$this->analytics       = new Analytics_Logger( $this->supabase );
		$this->settings_page   = new Settings_Page();
		$this->reports_page    = new Reports_Page();
		$this->rest_controller = new REST_Controller();
	}

	private function register_hooks(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		// MBA scheduled hook removed - using Google Places API
		$this->settings_page->register();
		$this->reports_page->register();
	}

	private function maybe_register_cli_commands(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && function_exists( 'WP_CLI' ) ) {
			$this->register_cli_commands();
		}
	}

	private function register_cli_commands(): void {
		// Only diagnostics command remains - ingest and MBA removed (using Google Places API)
		$commands = array(
			'batp diagnostics' => array(
				'file'  => BATP_PLUGIN_PATH . 'includes/cli/class-batp-diagnostics-command.php',
				'class' => '\\BrooklynAI\\CLI\\Batp_Diagnostics_Command',
			),
		);

		foreach ( $commands as $command => $config ) {
			if ( ! file_exists( $config['file'] ) ) {
				continue;
			}

			require_once $config['file'];
			if ( class_exists( $config['class'] ) ) {
				\WP_CLI::add_command( $command, $config['class'] );
			}
		}
	}

	public function supabase(): Supabase_Client {
		return $this->supabase;
	}

	public function security(): Security_Manager {
		return $this->security;
	}

	public function cache(): Cache_Service {
		return $this->cache;
	}

	public function analytics(): Analytics_Logger {
		return $this->analytics;
	}

	/**
	 * Get Google Places API client.
	 *
	 * @return Google_Places_Client|null
	 */
	public function google_places(): ?Google_Places_Client {
		if ( null === $this->google_places ) {
			$api_key = $this->get_maps_api_key();
			if ( '' !== $api_key ) {
				$this->google_places = new Google_Places_Client( $api_key );
			}
		}

		return $this->google_places;
	}

	/**
	 * Get Google Directions API client.
	 *
	 * @return Google_Directions_Client|null
	 */
	public function google_directions(): ?Google_Directions_Client {
		if ( null === $this->google_directions ) {
			$api_key = $this->get_maps_api_key();
			if ( '' !== $api_key ) {
				$this->google_directions = new Google_Directions_Client( $api_key );
			}
		}

		return $this->google_directions;
	}

	public function gemini(): ?Gemini_Client {
		if ( null === $this->gemini ) {
			$api_key = $this->setting_with_env_fallback( 'gemini_api_key', 'BATP_GEMINI_API_KEY' );
			if ( '' !== $api_key ) {
				$this->gemini = new Gemini_Client( $api_key );
			}
		}

		return $this->gemini;
	}

	public function maps(): ?GoogleMaps_Client {
		if ( null === $this->maps ) {
			$api_key = $this->get_maps_api_key();
			if ( '' !== $api_key ) {
				$this->maps = new GoogleMaps_Client( $api_key );
			}
		}

		return $this->maps;
	}

	public function get_maps_api_key(): string {
		return $this->setting_with_env_fallback( 'maps_api_key', 'BATP_MAPS_API_KEY' );
	}

	private function make_supabase_client(): Supabase_Client {
		$base_url = $this->setting_with_env_fallback( 'supabase_url', 'BATP_SUPABASE_URL', defined( 'BATP_SUPABASE_URL' ) ? BATP_SUPABASE_URL : '' );
		$api_key  = $this->setting_with_env_fallback( 'supabase_service_key', 'BATP_SUPABASE_SERVICE_KEY', defined( 'BATP_SUPABASE_SERVICE_KEY' ) ? BATP_SUPABASE_SERVICE_KEY : '' );

		return new Supabase_Client( (string) $base_url, (string) $api_key );
	}

	private function get_setting( string $key, string $fallback = '' ): string {
		$value = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : '';
		return ( is_string( $value ) && '' !== $value ) ? $value : $fallback;
	}

	private function setting_with_env_fallback( string $option_key, string $env_key, string $default_value = '' ): string {
		$env_value = getenv( $env_key );

		// Priority 1: Environment Variable (Hard override)
		if ( $env_value && '' !== trim( $env_value ) ) {
			return trim( $env_value );
		}

		// Priority 2: Database Setting
		// Priority 3: Default Value (via fallback arg in get_setting)
		return trim( $this->get_setting( $option_key, $default_value ) );
	}

	// MBA scheduled job removed - using Google Places API for real-time data

	public function register_blocks(): void {
		$manifest = BATP_PLUGIN_PATH . 'build/blocks-manifest.php';

		if ( function_exists( 'wp_register_block_types_from_metadata_collection' ) && file_exists( $manifest ) ) {
			wp_register_block_types_from_metadata_collection( BATP_PLUGIN_PATH . 'build', $manifest );
			return;
		}

		if ( function_exists( 'wp_register_block_metadata_collection' ) && file_exists( $manifest ) ) {
			wp_register_block_metadata_collection( BATP_PLUGIN_PATH . 'build', $manifest );
		}

		if ( file_exists( $manifest ) ) {
			$manifest_data = require $manifest;
			foreach ( array_keys( $manifest_data ) as $block_type ) {
				register_block_type( BATP_PLUGIN_PATH . "build/{$block_type}" );
			}
		}
	}

	public function engine(): Engine {
		return new Engine(
			$this->security,
			$this->cache,
			$this->supabase,
			$this->google_places(),
			$this->google_directions(),
			$this->maps(),
			$this->gemini(),
			$this->analytics
		);
	}

	// ingestion_manager() and mba_generator() removed - using Google Places API
}
