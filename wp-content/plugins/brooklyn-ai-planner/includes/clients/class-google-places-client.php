<?php
/**
 * Google Places API client.
 *
 * @package BrooklynAI\Clients
 */

namespace BrooklynAI\Clients;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for Google Places API (Nearby Search, Text Search, Place Details).
 */
class Google_Places_Client {
	private string $api_key;
	private string $base_url = 'https://maps.googleapis.com/maps/api/place';

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Query Google Places Nearby Search.
	 *
	 * @param float       $lat       Latitude.
	 * @param float       $lng       Longitude.
	 * @param string      $type      Place type (restaurant, museum, park, etc.).
	 * @param int         $radius    Search radius in meters (default 3000).
	 * @param bool        $open_now  Filter to only open places.
	 * @param string|null $page_token Pagination token.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function nearby_search(
		float $lat,
		float $lng,
		string $type,
		int $radius = 3000,
		bool $open_now = true,
		?string $page_token = null
	) {
		$params = array(
			'location' => "{$lat},{$lng}",
			'radius'   => $radius,
			'type'     => $type,
			'key'      => $this->api_key,
		);

		if ( $open_now ) {
			$params['opennow'] = 'true';
		}

		if ( $page_token ) {
			$params['pagetoken'] = $page_token;
		}

		$url = $this->base_url . '/nearbysearch/json?' . http_build_query( $params );

		error_log( "BATP: Google Places nearbySearch: type={$type}, lat={$lat}, lng={$lng}, radius={$radius}" );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			error_log( 'BATP: Google Places API error: ' . $response->get_error_message() );
			return new WP_Error(
				'places_api_error',
				__( 'Failed to query Google Places', 'brooklyn-ai-planner' ),
				array( 'details' => $response->get_error_message() )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['status'] ) || 'OK' !== $body['status'] ) {
			$status        = $body['status'] ?? 'UNKNOWN';
			$error_message = $body['error_message'] ?? 'No results found';

			// ZERO_RESULTS is not an error - just means no venues match
			if ( 'ZERO_RESULTS' === $status ) {
				error_log( "BATP: Google Places returned zero results for type={$type}" );
				return array();
			}

			error_log( "BATP: Google Places API status: {$status} - {$error_message}" );
			return new WP_Error(
				'places_api_status',
				/* translators: %s: API status code */
				sprintf( __( 'Places API returned status: %s', 'brooklyn-ai-planner' ), $status ),
				array(
					'status'  => $status,
					'message' => $error_message,
				)
			);
		}

		$results = $body['results'] ?? array();
		error_log( 'BATP: Google Places returned ' . count( $results ) . " venues for type={$type}" );

		return $results;
	}

	/**
	 * Get detailed venue information.
	 *
	 * @param string     $place_id Google Place ID.
	 * @param array|null $fields   Fields to return (null for defaults).
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_details( string $place_id, ?array $fields = null ) {
		$default_fields = array(
			'name',
			'formatted_address',
			'opening_hours',
			'formatted_phone_number',
			'website',
			'business_status',
			'geometry',
			'photos',
			'rating',
			'user_ratings_total',
			'types',
			'price_level',
			'wheelchair_accessible_entrance',
		);

		$fields = $fields ?? $default_fields;

		$params = array(
			'place_id' => $place_id,
			'fields'   => implode( ',', $fields ),
			'key'      => $this->api_key,
		);

		$url = $this->base_url . '/details/json?' . http_build_query( $params );

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'places_details_error',
				__( 'Failed to fetch place details', 'brooklyn-ai-planner' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['status'] ) || 'OK' !== $body['status'] ) {
			return new WP_Error(
				'places_details_status',
				/* translators: %s: API status code */
				sprintf( __( 'Places Details API returned: %s', 'brooklyn-ai-planner' ), $body['status'] ?? 'UNKNOWN' )
			);
		}

		return $body['result'] ?? array();
	}

	/**
	 * Text search for places.
	 *
	 * @param string     $query  Search query.
	 * @param float|null $lat    Optional latitude for location bias.
	 * @param float|null $lng    Optional longitude for location bias.
	 * @param int|null   $radius Optional radius for location bias.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function text_search( string $query, ?float $lat = null, ?float $lng = null, ?int $radius = null ) {
		$params = array(
			'query' => $query,
			'key'   => $this->api_key,
		);

		if ( null !== $lat && null !== $lng ) {
			$params['location'] = "{$lat},{$lng}";
			if ( null !== $radius ) {
				$params['radius'] = $radius;
			}
		}

		$url = $this->base_url . '/textsearch/json?' . http_build_query( $params );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'text_search_error', __( 'Failed to query Places', 'brooklyn-ai-planner' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['status'] ) || 'OK' !== $body['status'] ) {
			if ( 'ZERO_RESULTS' === ( $body['status'] ?? '' ) ) {
				return array();
			}
			/* translators: %s: API status code */
			return new WP_Error( 'text_search_status', sprintf( __( 'Text search returned: %s', 'brooklyn-ai-planner' ), $body['status'] ?? 'UNKNOWN' ) );
		}

		return $body['results'] ?? array();
	}

	/**
	 * Build photo URL from photo reference.
	 *
	 * @param string $photo_reference Photo reference from Places API.
	 * @param int    $max_width       Maximum width of the photo.
	 * @return string
	 */
	public function get_photo_url( string $photo_reference, int $max_width = 400 ): string {
		return sprintf(
			'https://maps.googleapis.com/maps/api/place/photo?maxwidth=%d&photo_reference=%s&key=%s',
			$max_width,
			rawurlencode( $photo_reference ),
			$this->api_key
		);
	}

	/**
	 * Health check for the API.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function health_check() {
		// Simple test query for a known location (Brooklyn)
		$result = $this->nearby_search( 40.6782, -73.9442, 'point_of_interest', 100, false );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'status'  => 'ok',
			'results' => count( $result ),
		);
	}
}
