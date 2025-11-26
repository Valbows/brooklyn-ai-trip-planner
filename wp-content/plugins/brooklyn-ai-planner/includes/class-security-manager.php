<?php
/**
 * Security guardrail utilities.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Security_Manager {
	private const RATE_LIMIT_REQUESTS = 5;
	private const RATE_LIMIT_WINDOW   = HOUR_IN_SECONDS;

	private int $rate_limit_requests;
	private int $rate_limit_window;

	public function __construct() {
		$this->rate_limit_requests = $this->resolve_env_int( 'BATP_RATE_LIMIT_REQUESTS', self::RATE_LIMIT_REQUESTS );
		$this->rate_limit_window   = $this->resolve_env_int( 'BATP_RATE_LIMIT_WINDOW', self::RATE_LIMIT_WINDOW );
	}

	/**
	 * Enforce rate limit per client IP.
	 *
	 * @return true|WP_Error
	 */
	public function enforce_rate_limit( ?string $ip = null ) {
		if ( $this->rate_limit_requests <= 0 ) {
			return true;
		}

		$ip      = $ip ? sanitize_text_field( $ip ) : $this->detect_ip();
		$ip_hash = md5( $ip );
		$key     = 'batp_limit_' . $ip_hash;
		$count   = (int) get_transient( $key );

		if ( $count >= $this->rate_limit_requests ) {
			return new WP_Error(
				'batp_rate_limited',
				__( 'Too many itinerary requests. Please try again later.', 'brooklyn-ai-planner' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, max( 60, $this->rate_limit_window ) );
		return true;
	}

	public function reset_rate_limit( ?string $ip = null ): void {
		$ip      = $ip ? sanitize_text_field( $ip ) : $this->detect_ip();
		$ip_hash = md5( $ip );
		$key     = 'batp_limit_' . $ip_hash;
		delete_transient( $key );
	}

	public function create_nonce( string $action ): string {
		return wp_create_nonce( $action );
	}

	/**
	 * @return true|WP_Error
	 */
	public function verify_nonce( string $nonce, string $action ) {
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return new WP_Error( 'batp_invalid_nonce', __( 'Security check failed. Please refresh and try again.', 'brooklyn-ai-planner' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public function sanitize_recursive( $value ) {
		return map_deep( $value, array( $this, 'sanitize_scalar' ) );
	}

	/**
	 * @param array<string, mixed> $coords
	 * @return array{lat: float, lng: float}|WP_Error
	 */
	public function sanitize_coordinates( array $coords ) {
		$lat = isset( $coords['lat'] ) ? filter_var( $coords['lat'], FILTER_VALIDATE_FLOAT ) : null;
		$lng = isset( $coords['lng'] ) ? filter_var( $coords['lng'], FILTER_VALIDATE_FLOAT ) : null;

		if ( null === $lat || $lat < -90 || $lat > 90 ) {
			return new WP_Error( 'batp_invalid_lat', __( 'Invalid latitude provided.', 'brooklyn-ai-planner' ), array( 'status' => 400 ) );
		}

		if ( null === $lng || $lng < -180 || $lng > 180 ) {
			return new WP_Error( 'batp_invalid_lng', __( 'Invalid longitude provided.', 'brooklyn-ai-planner' ), array( 'status' => 400 ) );
		}

		return array(
			'lat' => (float) $lat,
			'lng' => (float) $lng,
		);
	}

	private function detect_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		return sanitize_text_field( $ip );
	}

	private function resolve_env_int( string $env_key, int $default ): int {
		$value = getenv( $env_key );
		if ( false === $value ) {
			return $default;
		}

		$int_value = (int) $value;
		return $int_value >= 0 ? $int_value : $default;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function sanitize_scalar( $value ) {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
	}
}
