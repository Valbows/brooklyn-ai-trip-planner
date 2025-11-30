<?php
/**
 * WP-CLI diagnostics command.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\CLI;

use BrooklynAI\Clients\Supabase_Client;
use BrooklynAI\Clients\GoogleMaps_Client;
use BrooklynAI\Clients\Google_Places_Client;
use BrooklynAI\Clients\Google_Directions_Client;
use BrooklynAI\Plugin;
use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Batp_Diagnostics_Command extends WP_CLI_Command {
	/**
	 * Checks connectivity to Supabase, Google Places, Google Directions, and Gemini APIs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp batp diagnostics connectivity
	 */
	public function connectivity( array $args, array $assoc_args ): void {
		$plugin = Plugin::instance();
		$plugin->boot();

		$results = array(
			$this->check_supabase( $plugin->supabase() ),
			$this->check_analytics( $plugin->supabase() ),
			$this->check_google_places( $plugin->google_places() ),
			$this->check_google_directions( $plugin->google_directions() ),
			$this->check_gemini( $plugin->gemini() ),
		);

		Utils::format_items( 'table', $results, array( 'service', 'status', 'latency_ms', 'details' ) );

		$failures = array_filter( $results, static fn( $item ) => 'error' === $item['status'] );
		if ( ! empty( $failures ) ) {
			WP_CLI::warning( 'One or more services reported errors. See details above.' );
			return;
		}

		WP_CLI::success( 'All connectivity checks passed.' );
	}

	/**
	 * @return array<string, string|int>
	 */
	private function check_analytics( Supabase_Client $client ): array {
		$start = microtime( true );

		// 1. Check RPC
		$rpc = $client->rpc( 'get_analytics_stats' );
		if ( is_wp_error( $rpc ) ) {
			$msg = $rpc->get_error_message();
			if ( strpos( $msg, 'function' ) !== false ) {
				$msg = 'RPC missing. Run 060_analytics_reporting.sql';
			}
			return $this->format_result( 'Analytics', 'error', 0, $msg );
		}

		// 2. Check Insert
		$insert = $client->insert(
			'analytics_logs',
			array(
				'action_type'  => 'diagnostics_check',
				'session_hash' => 'cli_test',
				'metadata'     => wp_json_encode( array( 'source' => 'cli' ) ),
			)
		);
		if ( is_wp_error( $insert ) ) {
			return $this->format_result( 'Analytics', 'error', 0, 'Insert failed: ' . $insert->get_error_message() );
		}

		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

		return $this->format_result( 'Analytics', 'ok', $latency, 'RPC active, Insert allowed.' );
	}

	/**
	 * @return array<string, string|int>
	 */
	private function check_supabase( Supabase_Client $client ): array {
		$start    = microtime( true );
		$response = $client->health_check();
		$latency  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $this->format_result( 'Supabase', 'error', $latency, $response->get_error_message() );
		}

		return $this->format_result( 'Supabase', 'ok', $latency, 'Authenticated + reachable.' );
	}

	/**
	 * @return array<string, string|int>
	 */
	private function check_google_places( ?Google_Places_Client $client ): array {
		if ( null === $client ) {
			return $this->format_result( 'Google Places', 'skipped', 0, 'API key not configured.' );
		}

		$start    = microtime( true );
		$response = $client->health_check();
		$latency  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $this->format_result( 'Google Places', 'error', $latency, $response->get_error_message() );
		}

		$results = $response['results'] ?? 0;
		return $this->format_result( 'Google Places', 'ok', $latency, "Nearby search returned {$results} results." );
	}

	/**
	 * @return array<string, string|int>
	 */
	private function check_google_directions( ?Google_Directions_Client $client ): array {
		if ( null === $client ) {
			return $this->format_result( 'Google Directions', 'skipped', 0, 'API key not configured.' );
		}

		$start    = microtime( true );
		$response = $client->health_check();
		$latency  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $this->format_result( 'Google Directions', 'error', $latency, $response->get_error_message() );
		}

		return $this->format_result( 'Google Directions', 'ok', $latency, 'Distance matrix OK.' );
	}

	/**
	 * @return array<string, string|int>
	 */
	private function check_gemini( $client ): array {
		if ( null === $client ) {
			return $this->format_result( 'Gemini', 'skipped', 0, 'API key not configured.' );
		}

		$start    = microtime( true );
		$response = $client->generate_content( 'Say "OK" if you can read this.' );
		$latency  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $this->format_result( 'Gemini', 'error', $latency, $response->get_error_message() );
		}

		return $this->format_result( 'Gemini', 'ok', $latency, 'LLM response received.' );
	}

	/**
	 * @param string $service
	 * @param string $status
	 * @param int    $latency
	 * @param string $message
	 * @return array<string, string|int>
	 */
	private function format_result( string $service, string $status, int $latency, string $message ): array {
		return array(
			'service'    => $service,
			'status'     => $status,
			'latency_ms' => $latency,
			'details'    => $message,
		);
	}
}
