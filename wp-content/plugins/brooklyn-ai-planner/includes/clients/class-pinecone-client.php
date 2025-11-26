<?php
/**
 * Pinecone REST client for serverless indexes.
 *
 * @package BrooklynAI
 * @see https://docs.pinecone.io/guides/get-started/overview
 */

namespace BrooklynAI\Clients;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pinecone_Client {
	private const CONTROL_PLANE_URL = 'https://api.pinecone.io';
	private const API_VERSION       = '2025-10';

	private string $api_key;
	private string $index_host;
	private bool $verify_ssl;

	/**
	 * Initialize the Pinecone client.
	 *
	 * @param string $api_key    Pinecone API key.
	 * @param string $index_host Unique index host (e.g., my-index-abc123.svc.us-east1-aws.pinecone.io).
	 * @param bool   $verify_ssl Whether to verify SSL certificates.
	 */
	public function __construct( string $api_key, string $index_host = '', bool $verify_ssl = true ) {
		$this->api_key    = $api_key;
		$this->index_host = $this->normalize_host( $index_host );
		$this->verify_ssl = $verify_ssl;
	}

	/**
	 * Normalize the host by removing https:// prefix if present.
	 */
	private function normalize_host( string $host ): string {
		$host = trim( $host );
		$host = preg_replace( '#^https?://#', '', $host );
		return rtrim( $host, '/' );
	}

	/**
	 * Queries the Pinecone index.
	 *
	 * @param string               $index   Index name (unused for serverless, kept for backward compat).
	 * @param array<string, mixed> $payload Query payload with vector, topK, filter, etc.
	 * @return array<string, mixed>|WP_Error
	 */
	public function query( string $index, array $payload ): array|WP_Error {
		if ( '' === $this->index_host ) {
			return new WP_Error( 'batp_pinecone_error', __( 'Pinecone index host not configured.', 'brooklyn-ai-planner' ) );
		}
		return $this->request( 'POST', $this->data_url( 'query' ), $payload );
	}

	/**
	 * Lists all indexes in the project via control plane.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_indexes(): array|WP_Error {
		return $this->request( 'GET', self::CONTROL_PLANE_URL . '/indexes' );
	}

	/**
	 * Describes a specific index by name.
	 *
	 * @param string $index_name The index name.
	 * @return array<string, mixed>|WP_Error Index details including host.
	 */
	public function describe_index( string $index_name ): array|WP_Error {
		return $this->request( 'GET', self::CONTROL_PLANE_URL . '/indexes/' . rawurlencode( $index_name ) );
	}

	/**
	 * Upserts vectors into the Pinecone index.
	 *
	 * @param string                           $index   Index name (unused for serverless).
	 * @param array<int, array<string, mixed>> $vectors Vectors to upsert.
	 * @param array<string, mixed>             $options Optional namespace.
	 * @return array<string, mixed>|WP_Error
	 */
	public function upsert( string $index, array $vectors, array $options = array() ): array|WP_Error {
		if ( '' === $this->index_host ) {
			return new WP_Error( 'batp_pinecone_error', __( 'Pinecone index host not configured.', 'brooklyn-ai-planner' ) );
		}

		$payload = array(
			'vectors' => array_map( array( $this, 'prepare_vector' ), $vectors ),
		);

		if ( isset( $options['namespace'] ) && '' !== $options['namespace'] ) {
			$payload['namespace'] = sanitize_key( (string) $options['namespace'] );
		}

		return $this->request( 'POST', $this->data_url( 'vectors/upsert' ), $payload );
	}

	/**
	 * Check if the client is properly configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key && '' !== $this->index_host;
	}

	/**
	 * Get the current index host.
	 *
	 * @return string
	 */
	public function get_index_host(): string {
		return $this->index_host;
	}

	/**
	 * Unified request helper for Pinecone endpoints.
	 *
	 * @param array<string, mixed>|null $body
	 */
	private function request( string $method, string $url, ?array $body = null ): array|WP_Error {
		$args = array(
			'headers'   => $this->headers(),
			'timeout'   => 20,
			'sslverify' => $this->verify_ssl,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		// Inject custom CA cert if verification is enabled and cert exists
		$cert_path = dirname( __DIR__, 2 ) . '/certs/cacert.pem';
		$use_cert  = $this->verify_ssl && file_exists( $cert_path );

		$hook = function ( $handle ) use ( $cert_path ) {
			curl_setopt( $handle, CURLOPT_CAINFO, $cert_path );
		};

		if ( $use_cert ) {
			add_action( 'http_api_curl', $hook );
		}

		$response = wp_remote_request(
			$url,
			array_merge( $args, array( 'method' => $method ) )
		);

		if ( $use_cert ) {
			remove_action( 'http_api_curl', $hook );
		}

		return $this->handle_response( $response, $url, $method );
	}

	/**
	 * Build request headers with API version.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'Api-Key'                => $this->api_key,
			'Content-Type'           => 'application/json',
			'Accept'                 => 'application/json',
			'X-Pinecone-Api-Version' => self::API_VERSION,
		);
	}

	/**
	 * Build data plane URL for index operations.
	 *
	 * @param string $path API path (e.g., 'query', 'vectors/upsert').
	 * @return string Full URL.
	 */
	private function data_url( string $path ): string {
		return 'https://' . $this->index_host . '/' . ltrim( $path, '/' );
	}

	/**
	 * @param array<string, mixed>|WP_Error $response
	 * @return array<string, mixed>|WP_Error
	 */
	private function handle_response( array|WP_Error $response, string $url, string $method ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			return $this->format_error(
				array(
					'status' => $status,
					'body'   => $body,
					'url'    => $url,
					'method' => $method,
				)
			);
		}

		return is_array( $body ) ? $body : array();
	}

	private function format_error( array $context ): WP_Error {
		// Log detailed error for debugging
		$message = isset( $context['body']['message'] ) ? $context['body']['message'] : 'Unknown error';
		error_log( sprintf(
			'BATP Pinecone Error: %s | Status: %d | URL: %s | Body: %s',
			$message,
			$context['status'] ?? 0,
			$context['url'] ?? 'unknown',
			wp_json_encode( $context['body'] ?? array() )
		) );
		return new WP_Error( 'batp_pinecone_error', __( 'Pinecone request failed.', 'brooklyn-ai-planner' ), $context );
	}

	/**
	 * @param array<string, mixed> $vector
	 * @return array<string, mixed>
	 */
	private function prepare_vector( array $vector ): array {
		$payload = array(
			'id'     => isset( $vector['id'] ) ? sanitize_text_field( (string) $vector['id'] ) : '',
			'values' => array_map( 'floatval', $vector['values'] ?? array() ),
		);

		if ( isset( $vector['metadata'] ) && is_array( $vector['metadata'] ) ) {
			$payload['metadata'] = $this->sanitize_metadata( $vector['metadata'] );
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private function sanitize_metadata( array $metadata ): array {
		$clean = array();
		foreach ( $metadata as $key => $value ) {
			if ( is_array( $value ) ) {
				$clean[ sanitize_key( (string) $key ) ] = $this->sanitize_metadata( $value );
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$clean[ sanitize_key( (string) $key ) ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			}
		}

		return $clean;
	}
}
