<?php
/**
 * Google Maps API client (Places + Distance Matrix).
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Clients;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleMaps_Client {
	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Calls Places Text Search endpoint.
	 *
	 * @param array $params
	 * @return array|WP_Error
	 */
	/**
	 * @param array<string, string> $params
	 * @return array<string, mixed>|WP_Error
	 */
	public function places( array $params ): array|WP_Error {
		return $this->request( 'https://maps.googleapis.com/maps/api/place/textsearch/json', $params );
	}

	/**
	 * Calls Distance Matrix endpoint.
	 *
	 * @param array $params
	 * @return array|WP_Error
	 */
	/**
	 * @param array<string, string> $params
	 * @return array<string, mixed>|WP_Error
	 */
	public function distance_matrix( array $params ): array|WP_Error {
		return $this->request( 'https://maps.googleapis.com/maps/api/distancematrix/json', $params );
	}

	private function request( string $url, array $params = array() ): array|WP_Error {
		$endpoint = $this->build_endpoint( $url, $params );
		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->handle_response( $response, $endpoint );
	}

	private function build_endpoint( string $url, array $params ): string {
		$params        = array_filter( array_map( 'sanitize_text_field', $params ) );
		$params['key'] = $this->api_key;

		return add_query_arg( $params, $url );
	}

	private function handle_response( array $response, string $endpoint ): array|WP_Error {
		$status     = wp_remote_retrieve_response_code( $response );
		$body       = json_decode( wp_remote_retrieve_body( $response ), true );
		$api_status = isset( $body['status'] ) ? (string) $body['status'] : 'UNKNOWN';

		if ( $status >= 400 || ( 'OK' !== $api_status && 'ZERO_RESULTS' !== $api_status ) ) {
			return new WP_Error(
				'batp_maps_error',
				__( 'Google Maps request failed.', 'brooklyn-ai-planner' ),
				array(
					'http_status' => $status,
					'api_status'  => $api_status,
					'body'        => $body,
					'endpoint'    => $endpoint,
				)
			);
		}

		return is_array( $body ) ? $body : array();
	}
}
