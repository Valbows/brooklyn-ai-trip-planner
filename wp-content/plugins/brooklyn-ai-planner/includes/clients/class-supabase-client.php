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
		$url  = sprintf( '%s/rest/v1/%s', $this->base_url, urlencode( $table ) );
		$args = array(
			'headers' => $this->headers() + array(
				'Prefer' => 'return=representation',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			return new WP_Error(
				'batp_supabase_error',
				__( 'Supabase request failed.', 'brooklyn-ai-planner' ),
				array(
					'status' => $status,
					'body'   => $body,
				)
			);
		}

		return $body;
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
}
