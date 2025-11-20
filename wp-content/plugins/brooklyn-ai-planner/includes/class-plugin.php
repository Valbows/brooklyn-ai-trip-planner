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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private static ?Plugin $instance = null;
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
		$this->settings = get_option( 'batp_settings', array() );

		$this->security      = new Security_Manager();
		$this->cache         = new Cache_Service();
		$this->supabase      = $this->make_supabase_client();
		$this->analytics     = new Analytics_Logger( $this->supabase );
		$this->settings_page = new Settings_Page();

		add_action( 'init', array( $this, 'register_blocks' ) );
		$this->settings_page->register();
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
			$env_api_key = getenv( 'BATP_PINECONE_API_KEY' );
			$env_project = getenv( 'BATP_PINECONE_PROJECT' );
			$env_region  = getenv( 'BATP_PINECONE_ENVIRONMENT' );
			$api_key     = $this->get_setting( 'pinecone_api_key', $env_api_key ? $env_api_key : '' );
			$project     = $this->get_setting( 'pinecone_project', $env_project ? $env_project : '' );
			$env         = $this->get_setting( 'pinecone_environment', $env_region ? $env_region : 'us-east-1' );

			if ( $api_key && $project ) {
				$this->pinecone = new Pinecone_Client( $api_key, $project, $env );
			}
		}

		return $this->pinecone;
	}

	public function gemini(): ?Gemini_Client {
		if ( null === $this->gemini ) {
			$env_api_key = getenv( 'BATP_GEMINI_API_KEY' );
			$api_key     = $this->get_setting( 'gemini_api_key', $env_api_key ? $env_api_key : '' );
			if ( $api_key ) {
				$this->gemini = new Gemini_Client( $api_key );
			}
		}

		return $this->gemini;
	}

	public function maps(): ?GoogleMaps_Client {
		if ( null === $this->maps ) {
			$env_api_key = getenv( 'BATP_MAPS_API_KEY' );
			$api_key     = $this->get_setting( 'maps_api_key', $env_api_key ? $env_api_key : '' );
			if ( $api_key ) {
				$this->maps = new GoogleMaps_Client( $api_key );
			}
		}

		return $this->maps;
	}

	private function make_supabase_client(): Supabase_Client {
		$base_url = $this->get_setting( 'supabase_url', defined( 'BATP_SUPABASE_URL' ) ? BATP_SUPABASE_URL : getenv( 'BATP_SUPABASE_URL' ) );
		$api_key  = $this->get_setting( 'supabase_service_key', defined( 'BATP_SUPABASE_SERVICE_KEY' ) ? BATP_SUPABASE_SERVICE_KEY : getenv( 'BATP_SUPABASE_SERVICE_KEY' ) );

		return new Supabase_Client( (string) $base_url, (string) $api_key );
	}

	private function get_setting( string $key, string $fallback = '' ): string {
		$value = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $fallback;
		return is_string( $value ) ? $value : $fallback;
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
}
