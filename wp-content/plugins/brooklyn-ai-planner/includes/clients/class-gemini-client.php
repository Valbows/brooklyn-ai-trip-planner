<?php
/**
 * Gemini REST client.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Clients;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gemini_Client {
	private string $api_key;
	private string $model;
	private string $base_url;

	public function __construct( string $api_key, string $model = 'gemini-2.0-flash', string $base_url = 'https://generativelanguage.googleapis.com/v1beta' ) {
		$this->api_key  = $api_key;
		$this->model    = $model;
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * Calls generateContent endpoint.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|WP_Error
	 */
	public function generate_content( array $payload ): array|WP_Error {
		$endpoint = sprintf( '%s/models/%s:generateContent?key=%s', $this->base_url, $this->model, rawurlencode( $this->api_key ) );
		return $this->post( $endpoint, $payload );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|WP_Error
	 */
	private function post( string $url, array $payload ): array|WP_Error {
		$args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			return new WP_Error(
				'batp_gemini_error',
				__( 'Gemini request failed.', 'brooklyn-ai-planner' ),
				array(
					'status' => $status,
					'body'   => $body,
				)
			);
		}

		return $body;
	}
}
