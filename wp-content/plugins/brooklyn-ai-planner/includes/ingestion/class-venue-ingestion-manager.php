<?php
/**
 * Coordinates venue ingestion batches.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Ingestion;

use BrooklynAI\Analytics_Logger;
use BrooklynAI\Clients\Pinecone_Client;
use BrooklynAI\Clients\Supabase_Client;
use InvalidArgumentException;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Venue_Ingestion_Manager {
	private Supabase_Client $supabase;
	private ?Pinecone_Client $pinecone;
	private Venue_Enrichment_Service $enrichment;
	private Analytics_Logger $analytics;
	/**
	 * @var array<string, mixed>
	 */
	private array $options                    = array();
	private int $processed                    = 0;
	private int $skipped                      = 0;
	private ?Ingestion_Checkpoint $checkpoint = null;
	private string $resume_from_slug          = '';
	private bool $resume_unlocked             = true;
	private ?string $last_processed_slug      = null;
	/**
	 * @var callable|null
	 */
	private $progress_callback = null;

	public function __construct( Supabase_Client $supabase, ?Pinecone_Client $pinecone, Venue_Enrichment_Service $enrichment, Analytics_Logger $analytics ) {
		$this->supabase   = $supabase;
		$this->pinecone   = $pinecone;
		$this->enrichment = $enrichment;
		$this->analytics  = $analytics;
	}

	public function on_progress( callable $callback ): self {
		$this->progress_callback = $callback;
		return $this;
	}

	/**
	 * Streams CSV file and orchestrates enrichment/upserts.
	 *
	 * @param array{batch_size?:int,limit?:int,offset?:int,dry_run?:bool,pinecone_index?:string,required_columns?:array<int,string>} $options
	 * @return Batch_Result|WP_Error
	 */
	public function ingest( string $filepath, array $options = array() ) {
		$defaults                  = array(
			'batch_size'       => 50,
			'limit'            => 0,
			'offset'           => 0,
			'dry_run'          => false,
			'pinecone_index'   => '',
			'required_columns' => Ingestion_Constants::REQUIRED_COLUMNS,
			'resume'           => false,
			'checkpoint_file'  => defined( 'BATP_PLUGIN_PATH' ) ? BATP_PLUGIN_PATH . '.batp_ingest_state.json' : ABSPATH . '.batp_ingest_state.json',
		);
		$this->options             = array_merge( $defaults, $options );
		$this->processed           = 0;
		$this->skipped             = 0;
		$this->resume_from_slug    = '';
		$this->resume_unlocked     = true;
		$this->last_processed_slug = null;
		$this->checkpoint          = $this->build_checkpoint( (string) $this->options['checkpoint_file'] );

		if ( $this->options['resume'] && null !== $this->checkpoint ) {
			$this->resume_from_slug = $this->checkpoint->get_last_slug();
			$this->resume_unlocked  = '' === $this->resume_from_slug;
		}

		try {
			$reader = new Csv_Stream_Reader( $filepath, $this->options['required_columns'], (int) $this->options['batch_size'] );
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'batp_ingest_file_error', $exception->getMessage() );
		}

		$result     = new Batch_Result( (bool) $this->options['dry_run'] );
		$start_time = microtime( true );
		$this->log_event( Ingestion_Constants::ACTION_VENUES_START, array( Ingestion_Constants::CONTEXT_METADATA => array( Ingestion_Constants::CONTEXT_DRY_RUN => $this->options['dry_run'] ) ) );

		$reader->each_batch(
			function ( array $batch ) use ( $result ): bool {
				return $this->process_batch( $batch, $result );
			},
			function ( WP_Error $error ) use ( $result ): void {
				$result->add_error( $error );
				$this->log_event( Ingestion_Constants::ACTION_VALIDATION_ERROR, array( Ingestion_Constants::CONTEXT_METADATA => array( Ingestion_Constants::CONTEXT_ERROR => $error->get_error_message() ) ) );
				$this->notify_progress( $result, array( 'status' => 'error' ) );
			}
		);

		$this->log_event(
			Ingestion_Constants::ACTION_VENUES_COMPLETED,
			array(
				Ingestion_Constants::CONTEXT_METADATA => array(
					Ingestion_Constants::CONTEXT_ROWS     => $result->row_count,
					Ingestion_Constants::CONTEXT_ENRICHED => $result->enriched_count,
					Ingestion_Constants::CONTEXT_SUPABASE => $result->supabase_upserts,
					Ingestion_Constants::CONTEXT_PINECONE => $result->pinecone_upserts,
					Ingestion_Constants::CONTEXT_ERRORS   => count( $result->errors ),
					Ingestion_Constants::CONTEXT_DURATION => round( microtime( true ) - $start_time, 2 ),
				),
			)
		);

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $records
	 */
	private function process_batch( array $records, Batch_Result $result ): bool {
		$supabase_payloads = array();
		$pinecone_vectors  = array();

		foreach ( $records as $record ) {
			$current_slug = isset( $record['slug'] ) ? (string) $record['slug'] : '';
			if ( ! $this->resume_unlocked ) {
				if ( '' === $current_slug ) {
					continue;
				}
				if ( $current_slug === $this->resume_from_slug ) {
					$this->resume_unlocked = true;
					continue;
				}
				continue;
			}

			if ( $this->skipped < (int) $this->options['offset'] ) {
				++$this->skipped;
				continue;
			}

			if ( $this->options['limit'] > 0 && $this->processed >= (int) $this->options['limit'] ) {
				return false;
			}

			$result->record_row();
			$enriched = $this->enrichment->enrich( $record, (bool) $this->options['dry_run'] );
			if ( is_wp_error( $enriched ) ) {
				$slug = isset( $record['slug'] ) ? (string) $record['slug'] : null;
				$result->add_error( $enriched, $slug );
				$this->log_event(
					Ingestion_Constants::ACTION_VALIDATION_ERROR,
					array(
						Ingestion_Constants::CONTEXT_METADATA => array(
							Ingestion_Constants::CONTEXT_SLUG  => $slug,
							Ingestion_Constants::CONTEXT_ERROR => $enriched->get_error_message(),
						),
					)
				);
				$this->notify_progress(
					$result,
					array(
						'status' => 'error',
						'slug'   => $slug,
					)
				);
				continue;
			}

			$result->record_enriched();
			$supabase_payloads[] = $enriched['supabase'];
			if ( ! empty( $enriched['pinecone'] ) ) {
				$pinecone_vectors[] = $enriched['pinecone'];
			}
			$this->last_processed_slug = isset( $enriched['supabase']['slug'] ) ? (string) $enriched['supabase']['slug'] : $this->last_processed_slug;

			++$this->processed;
			$this->notify_progress(
				$result,
				array(
					'status' => 'processed',
					'slug'   => $current_slug,
				)
			);
		}

		if ( empty( $supabase_payloads ) ) {
			return true;
		}

		if ( ! $this->options['dry_run'] ) {
			$upsert = $this->request_with_retry(
				function () use ( $supabase_payloads ) {
					return $this->supabase->upsert( 'venues', $supabase_payloads, array( 'slug' ) );
				},
				'supabase_upsert'
			);
			if ( is_wp_error( $upsert ) ) {
				$result->add_error( $upsert );
				$this->log_event( Ingestion_Constants::ACTION_BATCH_FAILURE, array( Ingestion_Constants::CONTEXT_METADATA => array( Ingestion_Constants::CONTEXT_ERROR => $upsert->get_error_message() ) ) );
			} else {
				$result->record_supabase_upsert( count( $supabase_payloads ) );
			}

			if ( ! empty( $pinecone_vectors ) ) {
				$this->handle_pinecone_upsert( $pinecone_vectors, $result );
			}
		} else {
			$result->record_supabase_upsert( count( $supabase_payloads ) );
			$result->record_pinecone_upsert( count( $pinecone_vectors ) );
		}

		if ( null !== $this->last_processed_slug && null !== $this->checkpoint ) {
			$this->checkpoint->save_state( $this->last_processed_slug, $this->processed );
		}

		return true;
	}

	/**
	 * @param array<int, array<string, mixed>> $vectors
	 */
	private function handle_pinecone_upsert( array $vectors, Batch_Result $result ): void {
		if ( null === $this->pinecone ) {
			$result->add_error( new WP_Error( 'batp_pinecone_missing', __( 'Pinecone client not configured.', 'brooklyn-ai-planner' ) ) );
			return;
		}

		if ( empty( $this->options['pinecone_index'] ) ) {
			$result->add_error( new WP_Error( 'batp_pinecone_index_missing', __( 'Pinecone index name not provided.', 'brooklyn-ai-planner' ) ) );
			return;
		}

		$response = $this->request_with_retry(
			function () use ( $vectors ) {
				return $this->pinecone->upsert( (string) $this->options['pinecone_index'], $vectors );
			},
			'pinecone_upsert'
		);
		if ( is_wp_error( $response ) ) {
			$result->add_error( $response );
			$this->log_event(
				Ingestion_Constants::ACTION_BATCH_FAILURE,
				array(
					Ingestion_Constants::CONTEXT_METADATA => array(
						Ingestion_Constants::CONTEXT_ERROR => $response->get_error_message(),
						Ingestion_Constants::CONTEXT_TARGET => 'pinecone',
					),
				)
			);
			return;
		}

		$result->record_pinecone_upsert( count( $vectors ) );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log_event( string $action, array $context = array() ): void {
		$this->analytics->log( $action, $context );
		$this->debug_log( $action, $context[ Ingestion_Constants::CONTEXT_METADATA ] ?? $context );
	}

	private function notify_progress( Batch_Result $result, array $context = array() ): void {
		if ( null === $this->progress_callback ) {
			return;
		}

		call_user_func(
			$this->progress_callback,
			array_merge(
				array(
					'rows'      => $result->row_count,
					'enriched'  => $result->enriched_count,
					'processed' => $this->processed,
				),
				$context
			)
		);
	}

	private function build_checkpoint( string $path ): ?Ingestion_Checkpoint {
		if ( '' === trim( $path ) ) {
			return null;
		}

		return new Ingestion_Checkpoint( $path );
	}

	/**
	 * @return array|string|WP_Error
	 */
	private function request_with_retry( callable $callback, string $operation, int $attempts = 3, float $delay = 1.0 ) {
		$attempt = 0;
		$error   = null;

		while ( $attempt < $attempts ) {
			++$attempt;
			$result = $callback();
			if ( ! is_wp_error( $result ) ) {
				if ( $attempt > 1 ) {
					$this->log_event(
						Ingestion_Constants::ACTION_BATCH_RETRY,
						array(
							Ingestion_Constants::CONTEXT_METADATA => array(
								Ingestion_Constants::CONTEXT_OPERATION => $operation,
								Ingestion_Constants::CONTEXT_ATTEMPT   => $attempt,
							),
						)
					);
				}
				return $result;
			}

			$error = $result;
			$this->log_event(
				Ingestion_Constants::ACTION_BATCH_RETRY,
				array(
					Ingestion_Constants::CONTEXT_METADATA => array(
						Ingestion_Constants::CONTEXT_OPERATION => $operation,
						Ingestion_Constants::CONTEXT_ATTEMPT => $attempt,
						Ingestion_Constants::CONTEXT_ERROR => $result->get_error_message(),
					),
				)
			);

			if ( $attempt < $attempts ) {
				usleep( (int) ( $delay * 1000000 ) );
				$delay *= 2;
			}
		}

		return $error instanceof WP_Error ? $error : new WP_Error( 'batp_ingest_unknown_error', __( 'Unknown ingestion error.', 'brooklyn-ai-planner' ) );
	}

	private function debug_log( string $type, array $context = array() ): void {
		$payload = wp_json_encode( $context );
		error_log( sprintf( '[BATP][%s] %s', $type, $payload ? $payload : '' ) );
	}
}
