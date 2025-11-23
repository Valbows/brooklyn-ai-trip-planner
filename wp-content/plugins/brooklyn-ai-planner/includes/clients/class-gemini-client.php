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
	private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

	private string $api_key;
	private string $model;
	private string $base_url;

	public function __construct( string $api_key, string $model = 'gemini-2.0-flash', string $base_url = self::DEFAULT_BASE_URL ) {
		$this->api_key  = $api_key;
		$this->model    = $model;
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * Calls embedContent endpoint to retrieve embeddings.
	 *
	 * @param array<string, mixed> $payload
	 * @param string               $model
	 * @return array<string, mixed>|WP_Error
	 */
	public function embed_content( array $payload, string $model = 'text-embedding-004' ): array|WP_Error {
		$path = sprintf( 'models/%s:embedContent', rawurlencode( $model ) );
		return $this->post( $path, $payload, array( 'model' => $model ) );
	}

	/**
	 * Calls generateContent endpoint.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|WP_Error
	 */
	public function generate_content( array $payload ): array|WP_Error {
		$path = sprintf( 'models/%s:generateContent', rawurlencode( $this->model ) );
		return $this->post( $path, $payload, array( 'model' => $this->model ) );
	}

	/**
	 * Posts to Gemini API with shared error handling.
	 *
	 * @param array<string, mixed> $payload
	 * @param array<string, string> $context
	 * @return array<string, mixed>|WP_Error
	 */
	private function post( string $path, array $payload, array $context = array() ): array|WP_Error {
		$endpoint = $this->build_endpoint( $path );
		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->handle_response( $response, $context );
	}

	private function headers(): array {
		return array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
	}

	private function build_endpoint( string $path ): string {
		$path = ltrim( $path, '/' );
		$url  = sprintf( '%s/%s', $this->base_url, $path );

		return add_query_arg( 'key', $this->api_key, $url );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function handle_response( array $response, array $context = array() ): array|WP_Error {
		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			return new WP_Error(
				'batp_gemini_error',
				__( 'Gemini request failed.', 'brooklyn-ai-planner' ),
				array_merge(
					$context,
					array(
						'status' => $status,
						'body'   => $body,
					)
				)
			);
		}

		return is_array( $body ) ? $body : array();
	}
}
