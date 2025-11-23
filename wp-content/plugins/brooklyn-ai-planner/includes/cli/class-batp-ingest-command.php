<?php
/**
 * WP-CLI commands for BATP ingestion workflows.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\CLI;

use BrooklynAI\Clients\Gemini_Client;
use BrooklynAI\Ingestion\Synthetic_Itinerary_Generator;
use BrooklynAI\Ingestion\Venue_Enrichment_Service;
use BrooklynAI\Ingestion\Venue_Ingestion_Manager;
use BrooklynAI\Plugin;
use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles `wp batp ingest ...` commands.
 */
class Batp_Ingest_Command extends WP_CLI_Command {
	/**
	 * Ingests venue CSV data via Gemini + Supabase + Pinecone.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Absolute path to the CSV file containing venues.
	 *
	 * [--batch-size=<number>]
	 * : Number of rows per batch (default: 50).
	 *
	 * [--limit=<number>]
	 * : Stop after processing N rows (0 = no limit).
	 *
	 * [--offset=<number>]
	 * : Skip the first N rows before processing.
	 *
	 * [--dry-run]
	 * : Skip external API calls and database writes; useful for validation.
	 *
	 * [--pinecone-index=<name>]
	 * : Pinecone index name used for vector upserts.
	 *
	 * [--required-columns=<csv>]
	 * : Override required CSV columns (comma-delimited list).
	 *
	 * [--resume]
	 * : Resume ingestion from the last saved checkpoint (`.batp_ingest_state.json`).
	 *
	 * [--checkpoint-file=<path>]
	 * : Custom path for checkpoint state (defaults to plugin root).
	 *
	 * [--verbose]
	 * : Output detailed per-batch diagnostics.
	 */
	public function venues( array $args, array $assoc_args ): void {
		$file = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		if ( '' === $file ) {
			WP_CLI::error( 'You must provide --file pointing to the venue CSV.' );
		}

		if ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file ) );
		}

		$dry_run        = Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$verbose        = Utils\get_flag_value( $assoc_args, 'verbose', false );
		$resume         = Utils\get_flag_value( $assoc_args, 'resume', false );
		$batch_size     = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 50;
		$limit          = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$offset         = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;
		$pinecone_index = isset( $assoc_args['pinecone-index'] ) ? sanitize_key( (string) $assoc_args['pinecone-index'] ) : '';
		$checkpoint     = isset( $assoc_args['checkpoint-file'] ) ? (string) $assoc_args['checkpoint-file'] : '';

		$required_columns = Ingestion_Constants::REQUIRED_COLUMNS;
		if ( isset( $assoc_args['required-columns'] ) ) {
			$required_columns = array_filter(
				array_map( 'trim', explode( ',', (string) $assoc_args['required-columns'] ) )
			);
		}

		$plugin = Plugin::instance();
		$plugin->boot();

		$gemini = $plugin->gemini();
		if ( null === $gemini ) {
			if ( ! $dry_run ) {
				WP_CLI::error( 'Gemini API key missing. Configure it in plugin settings or via environment constants.' );
			}

			WP_CLI::warning( 'Gemini client missing; using mock responses (dry-run).' );
			$gemini = new Gemini_Client( 'dry-run-placeholder-key' );
		}

		$enrichment = new Venue_Enrichment_Service( $gemini );
		$manager    = new Venue_Ingestion_Manager(
			$plugin->supabase(),
			$plugin->pinecone(),
			$enrichment,
			$plugin->analytics()
		);
		$manager->on_progress(
			function ( array $payload ) use ( $verbose ) {
				if ( ! $verbose ) {
						return;
				}

				$status = isset( $payload['status'] ) ? strtoupper( (string) $payload['status'] ) : 'INFO';
				$slug   = isset( $payload['slug'] ) ? sprintf( ' [%s]', $payload['slug'] ) : '';
				WP_CLI::log( sprintf( '(%s) Rows:%d Enriched:%d Processed:%d%s', $status, $payload['rows'], $payload['enriched'], $payload['processed'], $slug ) );
			}
		);

		$options = array(
			'batch_size'       => $batch_size,
			'limit'            => $limit,
			'offset'           => $offset,
			'dry_run'          => $dry_run,
			'pinecone_index'   => $pinecone_index,
			'required_columns' => $required_columns,
			'resume'           => $resume,
			'checkpoint_file'  => $checkpoint,
		);

		if ( $dry_run ) {
			WP_CLI::log( 'Running in dry-run mode. No external writes will occur.' );
		}

		WP_CLI::log( sprintf( 'Processing %s with batch size %d...', $file, $batch_size ) );

		$result = $manager->ingest( $file, $options );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Rows processed: %d | Enriched: %d | Supabase upserts: %d | Pinecone upserts: %d', $result->row_count, $result->enriched_count, $result->supabase_upserts, $result->pinecone_upserts ) );

		if ( ! empty( $result->errors ) ) {
			WP_CLI::warning( sprintf( 'Encountered %d errors.', count( $result->errors ) ) );
			if ( $verbose ) {
				foreach ( $result->errors as $entry ) {
					/** @var \WP_Error $error */
					$error = $entry['error'];
					$slug  = isset( $entry['slug'] ) ? $entry['slug'] : 'n/a';
					WP_CLI::warning( sprintf( '[%s] %s', $slug, $error->get_error_message() ) );
				}
			}
		}
	}

	/**
	 * Generates synthetic itineraries and stores them in Supabase.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<number>]
	 * : Number of itineraries to create (default 100).
	 *
	 * [--dry-run]
	 * : Skip Supabase writes; useful for validating prompts.
	 *
	 * [--boroughs=<csv>]
	 * : Override borough pool (comma-delimited).
	 *
	 * [--interests=<csv>]
	 * : Override interest pool (comma-delimited keywords).
	 *
	 * [--party-min=<number>]
	 * [--party-max=<number>]
	 * [--duration-min=<number>]
	 * [--duration-max=<number>]
	 * : Customize randomization bounds.
	 */
	public function synthetic( array $args, array $assoc_args ): void {
		$count        = isset( $assoc_args['count'] ) ? max( 1, (int) $assoc_args['count'] ) : 100;
		$dry_run      = Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$boroughs     = isset( $assoc_args['boroughs'] ) ? $this->csv_to_array( $assoc_args['boroughs'] ) : array();
		$interests    = isset( $assoc_args['interests'] ) ? $this->csv_to_array( $assoc_args['interests'] ) : array();
		$party_min    = isset( $assoc_args['party-min'] ) ? max( 1, (int) $assoc_args['party-min'] ) : 1;
		$party_max    = isset( $assoc_args['party-max'] ) ? max( $party_min, (int) $assoc_args['party-max'] ) : 6;
		$duration_min = isset( $assoc_args['duration-min'] ) ? max( 1, (int) $assoc_args['duration-min'] ) : 1;
		$duration_max = isset( $assoc_args['duration-max'] ) ? max( $duration_min, (int) $assoc_args['duration-max'] ) : 4;

		$plugin = Plugin::instance();
		$plugin->boot();

		$gemini = $plugin->gemini();
		if ( null === $gemini ) {
			if ( ! $dry_run ) {
				WP_CLI::error( 'Gemini API key missing. Configure it before generating itineraries.' );
			}
			WP_CLI::warning( 'Gemini client missing; using mock itineraries.' );
			$gemini = new Gemini_Client( 'dry-run-placeholder-key' );
		}

		$generator = new Synthetic_Itinerary_Generator( $plugin->supabase(), $gemini );
		$options   = array(
			'dry_run'      => $dry_run,
			'boroughs'     => empty( $boroughs ) ? null : $boroughs,
			'interests'    => empty( $interests ) ? null : $interests,
			'party_min'    => $party_min,
			'party_max'    => $party_max,
			'duration_min' => $duration_min,
			'duration_max' => $duration_max,
		);

		$stats = $generator->generate( $count, array_filter( $options, static fn( $value ) => null !== $value ) );
		if ( is_wp_error( $stats ) ) {
			WP_CLI::error( $stats->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Synthetic itineraries attempted: %d | success: %d | failed: %d', $stats['attempted'], $stats['success'], $stats['failed'] ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function csv_to_array( string $csv ): array {
		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', $csv ) ),
				static fn( $value ) => '' !== $value
			)
		);
	}
}
