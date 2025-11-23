<?php
/**
 * DTO for ingestion batch results.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Ingestion;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Batch_Result {
	public int $row_count        = 0;
	public int $enriched_count   = 0;
	public int $supabase_upserts = 0;
	public int $pinecone_upserts = 0;
	/**
	 * @var array<int, array{slug?:string,error:WP_Error}>
	 */
	public array $errors = array();
	public bool $dry_run;

	public function __construct( bool $dry_run = false ) {
		$this->dry_run = $dry_run;
	}

	public function record_row(): void {
		++$this->row_count;
	}

	public function record_enriched(): void {
		++$this->enriched_count;
	}

	public function record_supabase_upsert( int $count ): void {
		$this->supabase_upserts += $count;
	}

	public function record_pinecone_upsert( int $count ): void {
		$this->pinecone_upserts += $count;
	}

	public function add_error( WP_Error $error, ?string $slug = null ): void {
		$this->errors[] = array(
			'error' => $error,
			'slug'  => $slug,
		);
	}
}
