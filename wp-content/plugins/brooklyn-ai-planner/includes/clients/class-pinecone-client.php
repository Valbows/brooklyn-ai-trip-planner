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
		$endpoint = sprintf( 'https://%s-%s.svc.%s.pinecone.io/query', $index, $this->project, $this->environment );
		return $this->post( $endpoint, $payload );
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
		$endpoint = sprintf( '%s/databases', $this->host );
		return $this->get( $endpoint );
	}

	/**
	 * Issues POST request with JSON body.
	 */
	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>|WP_Error
	 */
	private function post( string $url, array $body ): array|WP_Error {
		$args = array(
			'headers' => $this->headers(),
			'body'    => wp_json_encode( $body ),
			'timeout' => 20,
		);

		$response = wp_remote_post( $url, $args );
		return $this->handle_response( $response );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function get( string $url ): array|WP_Error {
		$args     = array(
			'headers' => $this->headers(),
			'timeout' => 20,
		);
		$response = wp_remote_get( $url, $args );
		return $this->handle_response( $response );
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
	private function handle_response( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			return new WP_Error(
				'batp_pinecone_error',
				__( 'Pinecone request failed.', 'brooklyn-ai-planner' ),
				array(
					'status' => $status,
					'body'   => $body,
				)
			);
		}

		return $body;
	}
}
