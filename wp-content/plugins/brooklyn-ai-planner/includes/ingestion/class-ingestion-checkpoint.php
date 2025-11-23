<?php
/**
 * Lightweight checkpoint persistence for resumable ingestion jobs.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Ingestion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ingestion_Checkpoint {
	private string $path;
	private ?string $last_checksum = null;

	public function __construct( string $path ) {
		$this->path = $path;
	}

	public function path(): string {
		return $this->path;
	}

	public function get_last_slug(): string {
		$data = $this->load();
		return isset( $data['last_slug'] ) ? (string) $data['last_slug'] : '';
	}

	public function save_state( string $slug, int $processed ): void {
		if ( '' === $this->path ) {
			return;
		}

		wp_mkdir_p( dirname( $this->path ) );
		$payload             = array(
			'last_slug'  => $slug,
			'processed'  => $processed,
			'updated_at' => gmdate( 'c' ),
		);
		$payload['checksum'] = $this->checksum( $payload );

		file_put_contents( $this->path, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$this->last_checksum = $payload['checksum'];
	}

	public function clear(): void {
		if ( '' !== $this->path && file_exists( $this->path ) ) {
			unlink( $this->path );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load(): array {
		if ( '' === $this->path || ! file_exists( $this->path ) ) {
			return array();
		}

		$raw = file_get_contents( $this->path );
		if ( false === $raw ) {
			return array();
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		if ( isset( $data['checksum'] ) ) {
			$this->last_checksum = $data['checksum'];
			if ( $data['checksum'] !== $this->checksum( $data ) ) {
				return array();
			}
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function checksum( array $payload ): string {
		$copy = $payload;
		unset( $copy['checksum'] );
		return hash( 'sha256', wp_json_encode( $copy ) );
	}
}
