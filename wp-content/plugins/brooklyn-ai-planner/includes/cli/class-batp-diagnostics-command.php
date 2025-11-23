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
			return $this->format_result( 'Pinecone', 'skipped', 0, 'API key/project not configured.' );
		}

		$start    = microtime( true );
		$response = $client->list_indexes();
		$latency  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $this->format_result( 'Pinecone', 'error', $latency, $response->get_error_message() );
		}

		return $this->format_result( 'Pinecone', 'ok', $latency, sprintf( 'Indexes detected: %d', isset( $response['databases'] ) ? count( (array) $response['databases'] ) : 0 ) );
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
