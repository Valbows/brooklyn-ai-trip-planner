<?php
/**
 * CSV Stream Reader for large venue imports.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Ingestion;

use InvalidArgumentException;
use SplFileObject;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams CSV rows in configurable batches while enforcing schema requirements.
 */
class Csv_Stream_Reader {
	private SplFileObject $file;
	/**
	 * @var array<int, string>
	 */
	private array $header = array();
	/**
	 * @var array<int, string>
	 */
	private array $required_columns;
	private int $batch_size;
	private int $line_number = 0;

	/**
	 * @param array<int, string> $required_columns
	 */
	public function __construct( string $filepath, array $required_columns, int $batch_size = 50 ) {
		if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			throw new InvalidArgumentException( sprintf( 'CSV file %s does not exist or is not readable.', $filepath ) );
		}

		if ( $batch_size < 1 ) {
			throw new InvalidArgumentException( 'Batch size must be at least 1.' );
		}

		$this->file             = new SplFileObject( $filepath, 'r' );
		$this->required_columns = array_values( array_unique( array_map( 'strval', $required_columns ) ) );
		$this->batch_size       = $batch_size;
		$this->configure_file_object();
		$this->header = $this->read_header();
		$this->assert_required_columns_present();
	}

	/**
	 * Returns the normalized CSV header.
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		return $this->header;
	}

	private function configure_file_object(): void {
		$this->file->setFlags( SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE );
		$this->file->setCsvControl( ',', '"', '\\' );
	}

	/**
	 * @return array<int, string>
	 */
	private function read_header(): array {
		while ( ! $this->file->eof() ) {
			$row = $this->file->fgetcsv();
			++$this->line_number;

			if ( empty( $row ) ) {
				continue;
			}

			$row = $this->trim_row( $row );
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			return array_map( array( $this, 'normalize_header_value' ), $row );
		}

		throw new InvalidArgumentException( 'CSV file is missing a header row.' );
	}

	private function assert_required_columns_present(): void {
		$missing = array_diff( $this->required_columns, $this->header );
		if ( ! empty( $missing ) ) {
			throw new InvalidArgumentException( sprintf( 'CSV header missing required columns: %s', implode( ', ', $missing ) ) );
		}
	}

	private function normalize_header_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return $value;
		}

		return strtolower( str_replace( ' ', '_', $value ) );
	}

	/**
	 * Streams CSV rows and invokes callback with batches.
	 *
	 * @param callable $on_batch      function( array<int, array<string, mixed>> $batch ): (bool|null)
	 * @param callable|null $on_error function( WP_Error $error, int $line_number ): void
	 */
	public function each_batch( callable $on_batch, ?callable $on_error = null ): void {
		$batch = array();

		while ( ! $this->file->eof() ) {
			$row = $this->file->fgetcsv();
			++$this->line_number;

			if ( false === $row || null === $row ) {
				continue;
			}

			$row = $this->trim_row( $row );
			if ( empty( array_filter( $row, 'strlen' ) ) ) {
				continue;
			}

			$parsed = $this->parse_row( $row );
			if ( is_wp_error( $parsed ) ) {
				if ( null !== $on_error ) {
					$on_error( $parsed, $this->line_number );
				}
				continue;
			}

			$batch[] = $parsed;

			if ( count( $batch ) >= $this->batch_size ) {
				$should_continue = $on_batch( $batch );
				$batch           = array();

				if ( false === $should_continue ) {
					return;
				}
			}
		}

		if ( ! empty( $batch ) ) {
			$should_continue = $on_batch( $batch );
			if ( false === $should_continue ) {
				return;
			}
		}
	}

	/**
	 * @param array<int, string|null> $row
	 * @return array<string, mixed>|WP_Error
	 */
	private function parse_row( array $row ): array|WP_Error {
		$assoc = array();
		foreach ( $this->header as $index => $column ) {
			if ( '' === $column ) {
				continue;
			}
			$assoc[ $column ] = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
		}

		foreach ( $this->required_columns as $column ) {
			if ( ! isset( $assoc[ $column ] ) || '' === $assoc[ $column ] ) {
				return new WP_Error( 'batp_ingest_missing_column', sprintf( 'Missing required column: %s', $column ), array( 'column' => $column ) );
			}
		}

		$assoc['categories'] = $this->normalize_multi_value( $assoc['categories'] ?? '' );
		$assoc['tags']       = $this->normalize_multi_value( $assoc['tags'] ?? '' );
		$assoc['latitude']   = $this->normalize_coordinate( $assoc['latitude'] ?? '', 'latitude' );
		$assoc['longitude']  = $this->normalize_coordinate( $assoc['longitude'] ?? '', 'longitude' );

		if ( is_wp_error( $assoc['latitude'] ) ) {
			return $assoc['latitude'];
		}

		if ( is_wp_error( $assoc['longitude'] ) ) {
			return $assoc['longitude'];
		}

		return $assoc;
	}

	/**
	 * @param array<int, string|null> $row
	 * @return array<int, string>
	 */
	private function trim_row( array $row ): array {
		return array_map(
			static function ( $value ): string {
				if ( null === $value ) {
					return '';
				}

				return trim( (string) $value );
			},
			$row
		);
	}

	/**
	 * Normalizes delimited string into array of strings.
	 *
	 * @return array<int, string>
	 */
	private function normalize_multi_value( string $value ): array {
		if ( '' === $value ) {
			return array();
		}

		$parts = preg_split( '/\s*[,|]\s*/', $value );
		if ( false === $parts ) {
			return array();
		}

		$parts = array_filter(
			array_map( 'trim', $parts ),
			static function ( $part ): bool {
				return '' !== $part;
			}
		);

		return array_values( $parts );
	}

	private function normalize_coordinate( string $value, string $type ): float|WP_Error {
		if ( '' === $value ) {
			return new WP_Error( 'batp_ingest_invalid_coordinate', sprintf( 'Missing %s coordinate.', $type ) );
		}

		$float = filter_var( $value, FILTER_VALIDATE_FLOAT );
		if ( false === $float ) {
			return new WP_Error( 'batp_ingest_invalid_coordinate', sprintf( 'Invalid %s coordinate: %s', $type, $value ) );
		}

		if ( 'latitude' === $type && ( $float < -90 || $float > 90 ) ) {
			return new WP_Error( 'batp_ingest_invalid_coordinate', sprintf( 'Latitude must be between -90 and 90. Value given: %s', $value ) );
		}

		if ( 'longitude' === $type && ( $float < -180 || $float > 180 ) ) {
			return new WP_Error( 'batp_ingest_invalid_coordinate', sprintf( 'Longitude must be between -180 and 180. Value given: %s', $value ) );
		}

		return round( $float, 6 );
	}
}
