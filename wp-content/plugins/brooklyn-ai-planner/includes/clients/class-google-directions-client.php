<?php
/**
 * Google Directions API client.
 *
 * @package BrooklynAI\Clients
 */

namespace BrooklynAI\Clients;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for Google Directions API and Distance Matrix API.
 */
class Google_Directions_Client {
	private string $api_key;
	private string $directions_url      = 'https://maps.googleapis.com/maps/api/directions/json';
	private string $distance_matrix_url = 'https://maps.googleapis.com/maps/api/distancematrix/json';

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Get multi-stop directions and polyline.
	 *
	 * @param array<float> $origin     Origin coordinates [lat, lng].
	 * @param array<array<float>> $waypoints Array of waypoint coordinates [[lat, lng], ...].
	 * @param string       $mode       Travel mode: walking, driving, transit, bicycling.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_multi_stop_route( array $origin, array $waypoints, string $mode = 'walking' ) {
		if ( empty( $waypoints ) ) {
			return new WP_Error( 'no_waypoints', __( 'At least one waypoint is required', 'brooklyn-ai-planner' ) );
		}

		// Destination is last waypoint
		$destination = end( $waypoints );

		// Intermediate waypoints (all except last)
		$intermediate = array_slice( $waypoints, 0, -1 );

		$params = array(
			'origin'      => "{$origin[0]},{$origin[1]}",
			'destination' => "{$destination[0]},{$destination[1]}",
			'mode'        => $mode,
			'key'         => $this->api_key,
		);

		// Add waypoints if any intermediate stops
		if ( ! empty( $intermediate ) ) {
			$waypoints_str       = implode(
				'|',
				array_map( fn( $w ) => "{$w[0]},{$w[1]}", $intermediate )
			);
			$params['waypoints'] = $waypoints_str;
		}

		$url = $this->directions_url . '?' . http_build_query( $params );

		error_log( "BATP: Google Directions request: origin={$origin[0]},{$origin[1]}, waypoints=" . count( $waypoints ) . ", mode={$mode}" );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			error_log( 'BATP: Google Directions API error: ' . $response->get_error_message() );
			return new WP_Error( 'directions_error', __( 'Failed to fetch directions', 'brooklyn-ai-planner' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['status'] ) || 'OK' !== $body['status'] ) {
			$status = $body['status'] ?? 'UNKNOWN';
			error_log( "BATP: Google Directions API status: {$status}" );
			/* translators: %s: API status code */
			return new WP_Error( 'directions_status', sprintf( __( 'Directions API: %s', 'brooklyn-ai-planner' ), $status ) );
		}

		$route = $body['routes'][0] ?? null;
		if ( ! $route ) {
			return new WP_Error( 'no_route', __( 'No route found', 'brooklyn-ai-planner' ) );
		}

		// Extract polyline for map rendering
		$polyline = $route['overview_polyline']['points'] ?? '';
		$legs     = $route['legs'] ?? array();

		// Calculate total time
		$total_seconds = 0;
		foreach ( $legs as $leg ) {
			$total_seconds += $leg['duration']['value'] ?? 0;
		}

		error_log( 'BATP: Google Directions returned route with ' . count( $legs ) . ' legs, total time: ' . $this->seconds_to_human( $total_seconds ) );

		return array(
			'polyline'      => $polyline,
			'legs'          => $legs,
			'total_seconds' => $total_seconds,
			'total_text'    => $this->seconds_to_human( $total_seconds ),
			'overview_url'  => $this->build_maps_url( $origin, $waypoints, $mode ),
		);
	}

	/**
	 * Get Distance Matrix (for pre-filtering long distances).
	 *
	 * @param array<array<float>> $origins      Array of origin coordinates [[lat, lng], ...].
	 * @param array<array<float>> $destinations Array of destination coordinates [[lat, lng], ...].
	 * @param string              $mode         Travel mode.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function distance_matrix( array $origins, array $destinations, string $mode = 'walking' ) {
		$origins_str      = implode(
			'|',
			array_map( fn( $o ) => "{$o[0]},{$o[1]}", $origins )
		);
		$destinations_str = implode(
			'|',
			array_map( fn( $d ) => "{$d[0]},{$d[1]}", $destinations )
		);

		$params = array(
			'origins'      => $origins_str,
			'destinations' => $destinations_str,
			'mode'         => $mode,
			'key'          => $this->api_key,
		);

		$url = $this->distance_matrix_url . '?' . http_build_query( $params );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'distance_matrix_error', __( 'Failed to fetch distances', 'brooklyn-ai-planner' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['status'] ) || 'OK' !== $body['status'] ) {
			/* translators: %s: API status code */
			return new WP_Error( 'distance_matrix_status', sprintf( __( 'Distance Matrix API: %s', 'brooklyn-ai-planner' ), $body['status'] ?? 'UNKNOWN' ) );
		}

		return $body['rows'] ?? array();
	}

	/**
	 * Build shareable Google Maps URL for multi-stop route.
	 *
	 * @param array<float>        $origin    Origin coordinates [lat, lng].
	 * @param array<array<float>> $waypoints Array of waypoint coordinates.
	 * @param string              $mode      Travel mode.
	 * @return string
	 */
	public function build_maps_url( array $origin, array $waypoints, string $mode = 'walking' ): string {
		if ( empty( $waypoints ) ) {
			return '#';
		}

		$destination  = end( $waypoints );
		$intermediate = array_slice( $waypoints, 0, -1 );

		$params = array(
			'api'         => 1,
			'origin'      => "{$origin[0]},{$origin[1]}",
			'destination' => "{$destination[0]},{$destination[1]}",
			'travelmode'  => $mode,
		);

		if ( ! empty( $intermediate ) ) {
			$params['waypoints'] = implode(
				'|',
				array_map( fn( $w ) => "{$w[0]},{$w[1]}", $intermediate )
			);
		}

		return 'https://www.google.com/maps/dir/?' . http_build_query( $params );
	}

	/**
	 * Format seconds to human-readable time.
	 *
	 * @param int $seconds Total seconds.
	 * @return string
	 */
	public function seconds_to_human( int $seconds ): string {
		$hours   = intval( $seconds / 3600 );
		$minutes = intval( ( $seconds % 3600 ) / 60 );

		if ( $hours > 0 ) {
			return "{$hours}h {$minutes}m";
		}
		return "{$minutes}m";
	}

	/**
	 * Health check for the API.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function health_check() {
		// Simple test: get distance from Brooklyn to Manhattan
		$result = $this->distance_matrix(
			array( array( 40.6782, -73.9442 ) ), // Brooklyn
			array( array( 40.7128, -74.0060 ) ), // Manhattan
			'walking'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'status' => 'ok',
			'rows'   => count( $result ),
		);
	}
}
