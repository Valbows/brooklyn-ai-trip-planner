<?php
/**
 * Pinecone REST client.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Clients;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pinecone_Client {
	private string $api_key;
	private string $project;
	private string $environment;
	private string $host;

	public function __construct( string $api_key, string $project, string $environment, string $host = '' ) {
		$this->api_key     = $api_key;
		$this->project     = $project;
		$this->environment = $environment;
		$this->host        = $host ? $host : sprintf( 'https://controller.%s.pinecone.io', $environment );
	}

	/**
	 * Queries a Pinecone index.
	 *
	 * @param string $index
	 * @param array  $payload
	 * @return array|WP_Error
	 */
	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|WP_Error
	 */
	public function query( string $index, array $payload ): array|WP_Error {
		return $this->request( 'POST', $this->index_url( $index, 'query' ), $payload );
	}

	/**
	 * Describes indexes (metadata).
	 *
	 * @return array|WP_Error
	 */
	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_indexes(): array|WP_Error {
		return $this->request( 'GET', $this->controller_url( 'databases' ) );
	}

	/**
	 * Upserts vectors into a Pinecone index.
	 *
	 * @param array<int, array<string, mixed>> $vectors
	 * @param array<string, mixed>             $options
	 * @return array<string, mixed>|WP_Error
	 */
	public function upsert( string $index, array $vectors, array $options = array() ): array|WP_Error {
		$payload = array(
			'vectors' => array_map( array( $this, 'prepare_vector' ), $vectors ),
		);

		if ( isset( $options['namespace'] ) && '' !== $options['namespace'] ) {
			$payload['namespace'] = sanitize_key( (string) $options['namespace'] );
		}

		return $this->request( 'POST', $this->index_url( $index, 'vectors/upsert' ), $payload );
	}

	/**
	 * Unified request helper for Pinecone endpoints.
	 *
	 * @param array<string, mixed>|null $body
	 */
	private function request( string $method, string $url, ?array $body = null ): array|WP_Error {
		$args = array(
			'headers' => $this->headers(),
			'timeout' => 20,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request(
			$url,
			array_merge( $args, array( 'method' => $method ) )
		);

		return $this->handle_response( $response, $url, $method );
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'Api-Key'      => $this->api_key,
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
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

	private function controller_url( string $path = '' ): string {
		$path = ltrim( $path, '/' );
		return $this->host . ( $path ? '/' . $path : '' );
	}

	private function index_url( string $index, string $path ): string {
		$host = sprintf( 'https://%s-%s.svc.%s.pinecone.io', sanitize_key( $index ), $this->project, $this->environment );
		return $host . '/' . ltrim( $path, '/' );
	}

	private function format_error( array $context ): WP_Error {
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
