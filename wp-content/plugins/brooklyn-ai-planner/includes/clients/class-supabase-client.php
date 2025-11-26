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
	 * Select rows from a table with optional limit/order controls.
	 *
	 * @param array<string, mixed> $options
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function select( string $table, array $options = array() ) {
		$select = isset( $options['select'] ) && is_string( $options['select'] ) ? $options['select'] : '*';
		$limit  = isset( $options['limit'] ) && is_numeric( $options['limit'] ) ? (int) $options['limit'] : null;
		$order  = isset( $options['order'] ) && is_string( $options['order'] ) ? $options['order'] : null;

		$query_args = array( 'select' => $select );

		if ( null !== $limit ) {
			$query_args['limit'] = $limit;
		}

		if ( null !== $order ) {
			$query_args['order'] = $order;
		}

		$query = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
		$path  = sprintf( '/rest/v1/%s?%s', urlencode( $table ), $query );

		return $this->request( 'GET', $path, array() );
	}

	/**
	 * Select rows where the provided column matches any value in the list.
	 *
	 * @param array<int, string|int|float> $values
	 * @param array<string, mixed>         $options
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function select_in( string $table, string $column, array $values, array $options = array() ) {
		$column = sanitize_key( $column );
		if ( '' === $column ) {
			return new WP_Error( 'batp_supabase_invalid_column', __( 'Supabase select_in requires a valid column name.', 'brooklyn-ai-planner' ) );
		}

		$filtered_values = $this->sanitize_filter_values( $values );
		if ( empty( $filtered_values ) ) {
			return new WP_Error( 'batp_supabase_empty_filter', __( 'Supabase select_in requires at least one value.', 'brooklyn-ai-planner' ) );
		}

		$select = isset( $options['select'] ) && is_string( $options['select'] ) ? $options['select'] : '*';
		$limit  = isset( $options['limit'] ) && is_numeric( $options['limit'] ) ? (int) $options['limit'] : null;
		$order  = isset( $options['order'] ) && is_string( $options['order'] ) ? $options['order'] : null;

		$query_args = array(
			'select' => $select,
			$column  => sprintf( 'in.(%s)', $this->format_in_values( $filtered_values ) ),
		);

		if ( null !== $limit ) {
			$query_args['limit'] = $limit;
		}

		if ( null !== $order ) {
			$query_args['order'] = $order;
		}

		$query = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
		$path  = sprintf( '/rest/v1/%s?%s', urlencode( $table ), $query );

		return $this->request( 'GET', $path, array() );
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
	 * @param array<int, string|int|float> $values
	 * @return array<int, string>
	 */
	private function sanitize_filter_values( array $values ): array {
		$clean = array();

		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$normalized = sanitize_text_field( (string) $value );
				if ( '' !== $normalized ) {
					$clean[] = $normalized;
				}
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param array<int, string> $values
	 */
	private function format_in_values( array $values ): string {
		$wrapped = array_map(
			static function ( string $value ): string {
				return sprintf( '"%s"', str_replace( '"', '\\"', $value ) );
			},
			$values
		);

		return implode( ',', $wrapped );
	}

	/**
	 * Deletes rows matching conditions.
	 *
	 * @param string               $table      Table name.
	 * @param array<string, mixed> $conditions Key-value pairs for equality check.
	 * @return array<mixed>|WP_Error
	 */
	public function delete( string $table, array $conditions ) {
		if ( empty( $conditions ) ) {
			return new WP_Error( 'batp_supabase_delete_unsafe', 'Delete requires conditions.' );
		}

		$query_args = array();
		foreach ( $conditions as $col => $val ) {
			$query_args[ $col ] = 'eq.' . $val;
		}

		$query = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
		$path  = sprintf( '/rest/v1/%s?%s', urlencode( $table ), $query );

		return $this->request(
			'DELETE',
			$path,
			array(
				'headers' => array( 'Prefer' => 'return=representation' ),
			)
		);
	}

	/**
	 * Calls a Postgres function via RPC.
	 *
	 * @param string               $function Function name.
	 * @param array<string, mixed> $params   Function arguments.
	 * @return array<mixed>|WP_Error
	 */
	public function rpc( string $function, array $params = array() ) {
		return $this->request(
			'POST',
			'/rest/v1/rpc/' . urlencode( $function ),
			array(
				'body' => wp_json_encode( $params ),
			)
		);
	}

	/**
	 * Trigger the MBA Association Rules generation via RPC.
	 *
	 * @param float $min_support
	 * @param float $min_confidence
	 * @param float $min_lift
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_mba_job( float $min_support = 0.005, float $min_confidence = 0.1, float $min_lift = 1.2 ) {
		$payload = array(
			'min_support'    => $min_support,
			'min_confidence' => $min_confidence,
			'min_lift'       => $min_lift,
		);

		return $this->request(
			'POST',
			'/rest/v1/rpc/generate_association_rules',
			array(
				'body' => wp_json_encode( $payload ),
			)
		);
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

		error_log( "BATP: Supabase Request $method $url" );

		$response = wp_remote_request(
			$url,
			array(
				'method'    => $method,
				'headers'   => $args['headers'],
				'timeout'   => $args['timeout'],
				'body'      => $args['body'] ?? null,
				'sslverify' => false, // TEMP FIX: Disable SSL verify to rule out cert issues in dev
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'BATP: Supabase connection error: ' . $response->get_error_message() );
			return $response;
		}

		error_log( 'BATP: Supabase Response Code: ' . wp_remote_retrieve_response_code( $response ) );

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
