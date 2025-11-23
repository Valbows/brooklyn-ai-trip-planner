<?php
/**
 * Core plugin bootstrap.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI;

use BrooklynAI\Admin\Settings_Page;
use BrooklynAI\Clients\Gemini_Client;
use BrooklynAI\Clients\GoogleMaps_Client;
use BrooklynAI\Clients\Pinecone_Client;
use BrooklynAI\Clients\Supabase_Client;
use BrooklynAI\Ingestion\Venue_Enrichment_Service;
use BrooklynAI\Ingestion\Venue_Ingestion_Manager;

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
	private Supabase_Client $supabase;
	private ?Pinecone_Client $pinecone = null;
	private ?Gemini_Client $gemini     = null;
	private ?GoogleMaps_Client $maps   = null;
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
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
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
		$this->security      = new Security_Manager();
		$this->cache         = new Cache_Service();
		$this->supabase      = $this->make_supabase_client();
		$this->analytics     = new Analytics_Logger( $this->supabase );
		$this->settings_page = new Settings_Page();
	}

	private function register_hooks(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		$this->settings_page->register();
	}

	private function maybe_register_cli_commands(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && function_exists( 'WP_CLI' ) ) {
			$this->register_cli_commands();
		}
	}

	private function register_cli_commands(): void {
		$commands = array(
			'batp ingest'      => array(
				'file'  => BATP_PLUGIN_PATH . 'includes/cli/class-batp-ingest-command.php',
				'class' => '\\BrooklynAI\\CLI\\Batp_Ingest_Command',
			),
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

	public function pinecone(): ?Pinecone_Client {
		if ( null === $this->pinecone ) {
			$api_key = $this->setting_with_env_fallback( 'pinecone_api_key', 'BATP_PINECONE_API_KEY' );
			$project = $this->setting_with_env_fallback( 'pinecone_project', 'BATP_PINECONE_PROJECT' );
			$env     = $this->setting_with_env_fallback( 'pinecone_environment', 'BATP_PINECONE_ENVIRONMENT', 'us-east-1' );

			if ( '' !== $api_key && '' !== $project ) {
				$this->pinecone = new Pinecone_Client( $api_key, $project, $env );
			}
		}

		return $this->pinecone;
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
			$api_key = $this->setting_with_env_fallback( 'maps_api_key', 'BATP_MAPS_API_KEY' );
			if ( '' !== $api_key ) {
				$this->maps = new GoogleMaps_Client( $api_key );
			}
		}

		return $this->maps;
	}

	private function make_supabase_client(): Supabase_Client {
		$base_url = $this->setting_with_env_fallback( 'supabase_url', 'BATP_SUPABASE_URL', defined( 'BATP_SUPABASE_URL' ) ? BATP_SUPABASE_URL : '' );
		$api_key  = $this->setting_with_env_fallback( 'supabase_service_key', 'BATP_SUPABASE_SERVICE_KEY', defined( 'BATP_SUPABASE_SERVICE_KEY' ) ? BATP_SUPABASE_SERVICE_KEY : '' );

		return new Supabase_Client( (string) $base_url, (string) $api_key );
	}

	private function get_setting( string $key, string $fallback = '' ): string {
		$value = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $fallback;
		return is_string( $value ) ? $value : $fallback;
	}

	private function setting_with_env_fallback( string $option_key, string $env_key, string $default_value = '' ): string {
		$env_value = getenv( $env_key );
		$fallback  = '' !== $default_value ? $default_value : ( $env_value ? $env_value : '' );

		return trim( $this->get_setting( $option_key, $fallback ) );
	}

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

	public function ingestion_manager(): Venue_Ingestion_Manager {
		return new Venue_Ingestion_Manager(
			$this->supabase(),
			$this->pinecone(),
			new Venue_Enrichment_Service( $this->gemini() ?? new Gemini_Client( 'dry-run-placeholder' ) ),
			$this->analytics()
		);
	}
}
