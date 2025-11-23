<?php
/**
 * Supabase client wrapper.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Clients;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supabase_Client {
	private string $base_url;
	private string $api_key;

	public function __construct( string $base_url, string $api_key ) {
		$this->base_url = rtrim( $base_url, '/' );
		$this->api_key  = $api_key;
	}

	/**
	 * Inserts row into table using Supabase REST API.
	 *
	 * @param array<string, mixed> $payload Payload to insert.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function insert( string $table, array $payload ) {
		return $this->request(
			'POST',
			sprintf( '/rest/v1/%s', urlencode( $table ) ),
			array(
				'headers' => array( 'Prefer' => 'return=representation' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Upserts rows into a table, merging on conflict columns.
	 *
	 * @param array<int, array<string, mixed>>|array<string, mixed> $payloads Rows to upsert.
	 * @param array<int, string>                                    $conflict_columns Column names defining uniqueness (e.g., slug).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function upsert( string $table, array $payloads, array $conflict_columns ) {
		if ( empty( $conflict_columns ) ) {
			return new WP_Error( 'batp_supabase_conflict_columns_missing', __( 'Supabase upsert requires at least one conflict column.', 'brooklyn-ai-planner' ) );
		}

		$rows = $this->normalize_rows( $payloads );
		if ( empty( $rows ) ) {
			return new WP_Error( 'batp_supabase_empty_payload', __( 'Supabase upsert payload cannot be empty.', 'brooklyn-ai-planner' ) );
		}

		$query = sprintf( '?on_conflict=%s', rawurlencode( implode( ',', $conflict_columns ) ) );
		return $this->request(
			'POST',
			sprintf( '/rest/v1/%s%s', urlencode( $table ), $query ),
			array(
				'headers' => array( 'Prefer' => 'resolution=merge-duplicates,return=representation' ),
				'body'    => wp_json_encode( $rows ),
			)
		);
	}

	/**
	 * Normalizes payload rows to a consistently indexed array.
	 *
	 * @param array<int, array<string, mixed>>|array<string, mixed> $payload
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_rows( array $payload ): array {
		if ( empty( $payload ) ) {
			return array();
		}

		$keys       = array_keys( $payload );
		$sequential = array_keys( $keys ) === $keys;

		if ( $sequential && isset( $payload[0] ) && is_array( $payload[0] ) ) {
			return $payload;
		}

		return array( $payload );
	}

	/**
	 * Lightweight health-check query for diagnostics.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function health_check() {
		return $this->request( 'GET', '/rest/v1/venues?select=id&limit=1', array() );
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'apikey'        => $this->api_key,
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Executes a REST request against Supabase with unified error handling.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $method, string $path, array $args ) {
		$url             = $this->build_url( $path );
		$defaults        = array(
			'headers' => $this->headers(),
			'timeout' => 20,
		);
		$args            = array_merge( $defaults, $args );
		$args['headers'] = array_merge( $defaults['headers'], $args['headers'] ?? array() );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $args['headers'],
				'timeout' => $args['timeout'],
				'body'    => $args['body'] ?? null,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->handle_response( $response, $path, $method );
	}

	private function build_url( string $path ): string {
		$path = '/' === $path[0] ? $path : '/' . $path;
		return $this->base_url . $path;
	}

	private function handle_response( array $response, string $path, string $method ) {
		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			return $this->format_error(
				array(
					'path'   => $path,
					'method' => $method,
					'body'   => $body,
					'status' => $status,
				)
			);
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function format_error( array $context ): WP_Error {
		return new WP_Error( 'batp_supabase_http_error', __( 'Supabase request failed.', 'brooklyn-ai-planner' ), $context );
	}
}
