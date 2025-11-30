<?php
/**
 * Analytics logging without PII.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI;

use BrooklynAI\Clients\Supabase_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Analytics_Logger {
	private Supabase_Client $client;

	public function __construct( Supabase_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Log an analytics event.
	 *
	 * @param string $action_type Event type (e.g., 'itinerary_generated', 'website_click').
	 * @param array{session_hash?:string,place_id?:string,metadata?:array<string, mixed>} $context
	 * @return true|WP_Error
	 */
	public function log( string $action_type, array $context = array() ) {
		$action  = sanitize_text_field( $action_type );
		$seed    = isset( $context['session_hash'] ) ? $context['session_hash'] : '';
		$session = $this->hash_session( $seed );

		// Use place_id for Google Places references (string, not UUID)
		$place_id = isset( $context['place_id'] ) && '' !== $context['place_id']
			? sanitize_text_field( $context['place_id'] )
			: null;

		// Pass metadata as array - Supabase client handles JSON encoding
		// Do NOT pre-encode or it will be double-encoded
		$metadata = isset( $context['metadata'] ) && is_array( $context['metadata'] )
			? $context['metadata']
			: null;

		$payload = array(
			'action_type'  => $action,
			'session_hash' => $session,
			'metadata'     => $metadata,
		);

		// Only include place_id if provided
		if ( null !== $place_id ) {
			$payload['place_id'] = $place_id;
		}

		error_log( 'BATP Analytics: Logging event "' . $action . '" with metadata: ' . wp_json_encode( $metadata ) );

		$response = $this->client->insert( 'analytics_logs', $payload );

		if ( is_wp_error( $response ) ) {
			error_log( 'BATP Analytics Error: ' . $response->get_error_message() );
			return $response;
		}

		error_log( 'BATP Analytics: Successfully logged "' . $action . '"' );
		return true;
	}

	private function hash_session( string $seed ): string {
		if ( '' === $seed ) {
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
			$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
			$seed       = $user_agent . '|' . $ip;
		}
		return hash_hmac( 'sha256', $seed, wp_salt( 'auth' ) );
	}
}
