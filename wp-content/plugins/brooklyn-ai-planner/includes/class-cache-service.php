<?php
/**
 * Cache helper built on WordPress transients.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cache_Service {
	public const TTL_PIPELINE = HOUR_IN_SECONDS;      // 1 hour for RAG pipeline caches.
	public const TTL_GEMINI   = DAY_IN_SECONDS;       // 24 hours for Gemini responses.

	/**
	 * @param array<string, mixed> $payload
	 * @return mixed
	 */
	public function get( string $context, array $payload ) {
		$key = $this->build_key( $context, $payload );
		return get_transient( $key );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param mixed                $value
	 */
	public function set( string $context, array $payload, $value, int $ttl = self::TTL_PIPELINE ): void {
		$key = $this->build_key( $context, $payload );
		set_transient( $key, $value, $ttl );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function delete( string $context, array $payload ): void {
		$key = $this->build_key( $context, $payload );
		delete_transient( $key );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function build_key( string $context, array $payload ): string {
		$hash = md5( wp_json_encode( $payload ) );
		// Updated prefix to 'batp_v2_' to invalidate previous cache (e.g. empty itineraries from before auth fix).
		return sprintf( 'batp_v2_%s_%s', sanitize_key( $context ), $hash );
	}
}
