<?php
/**
 * WP-CLI diagnostics command.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\CLI;

use BrooklynAI\Clients\Pinecone_Client;
use BrooklynAI\Clients\Supabase_Client;
use BrooklynAI\Clients\GoogleMaps_Client;
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
	 * Checks connectivity to Supabase, Pinecone, and Google Maps APIs.
	 */
	public function connectivity( array $args, array $assoc_args ): void {
		$plugin = Plugin::instance();
		$plugin->boot();

		$results = array(
			$this->check_supabase( $plugin->supabase() ),
			$this->check_analytics( $plugin->supabase() ),
			$this->check_pinecone( $plugin->pinecone() ),
			$this->check_google( $plugin->maps() ),
		);

		Utils::format_items( 'table', $results, array( 'service', 'status', 'latency_ms', 'details' ) );

		if ( array_filter( $results, static fn( $item ) => 'ok' !== $item['status'] ) ) {
			WP_CLI::warning( 'One or more services reported issues. See details above.' );
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
	private function check_pinecone( ?Pinecone_Client $client ): array {
		if ( null === $client ) {
			return $this->format_result( 'Pinecone', 'skipped', 0, 'API key or index host not configured.' );
		}

		$start    = microtime( true );
		$response = $client->list_indexes();
		$latency  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $this->format_result( 'Pinecone', 'error', $latency, $response->get_error_message() );
		}

		// New API returns 'indexes' array, old API used 'databases'
		$indexes     = $response['indexes'] ?? $response['databases'] ?? array();
		$index_count = count( (array) $indexes );
		$host_info   = $client->is_configured() ? 'Host: ' . substr( $client->get_index_host(), 0, 30 ) . '...' : 'Host not set';

		return $this->format_result( 'Pinecone', 'ok', $latency, sprintf( 'Indexes: %d | %s', $index_count, $host_info ) );
	}

	/**
	 * @return array<string, string|int>
	 */
	private function check_google( ?GoogleMaps_Client $client ): array {
		if ( null === $client ) {
			return $this->format_result( 'Google Maps', 'skipped', 0, 'API key not configured.' );
		}

		$start    = microtime( true );
		$response = $client->places(
			array(
				'query' => 'Brooklyn Museum',
			)
		);
		$latency  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $this->format_result( 'Google Maps', 'error', $latency, $response->get_error_message() );
		}

		$status = isset( $response['status'] ) ? (string) $response['status'] : 'UNKNOWN';
		return $this->format_result( 'Google Maps', 'ok', $latency, 'Status: ' . $status );
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
