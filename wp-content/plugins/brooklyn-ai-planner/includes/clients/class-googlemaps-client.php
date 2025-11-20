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
		return $this->get( 'https://maps.googleapis.com/maps/api/place/textsearch/json', $params );
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
		$url = add_query_arg( $params, 'https://maps.googleapis.com/maps/api/distancematrix/json' );
		return $this->get( $url );
	}

	/**
	 * @param array<string, string> $params
	 * @return array<string, mixed>|WP_Error
	 */
	private function get( string $url, array $params = array() ): array|WP_Error {
		$params['key'] = $this->api_key;
		$endpoint      = add_query_arg( $params, $url );
		$response      = wp_remote_get( $endpoint, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 || ( isset( $body['status'] ) && 'OK' !== $body['status'] ) ) {
			return new WP_Error(
				'batp_maps_error',
				__( 'Google Maps request failed.', 'brooklyn-ai-planner' ),
				array(
					'status' => $status,
					'body'   => $body,
				)
			);
		}

		return $body;
	}
}
