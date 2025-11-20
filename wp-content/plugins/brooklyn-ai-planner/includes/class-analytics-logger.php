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
	 * @param array{session_hash?:string,venue_id?:string,metadata?:array<string, mixed>} $context
	 * @return true|WP_Error
	 */
	public function log( string $action_type, array $context = array() ) {
		$action   = sanitize_text_field( $action_type );
		$seed     = isset( $context['session_hash'] ) ? $context['session_hash'] : '';
		$session  = $this->hash_session( $seed );
		$venue_id = isset( $context['venue_id'] ) ? sanitize_text_field( $context['venue_id'] ) : null;
		$metadata = isset( $context['metadata'] ) ? wp_json_encode( $context['metadata'] ) : null;

		$payload = array(
			'action_type'  => $action,
			'session_hash' => $session,
			'venue_id'     => $venue_id,
			'metadata'     => $metadata,
		);

		$response = $this->client->insert( 'analytics_logs', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

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
