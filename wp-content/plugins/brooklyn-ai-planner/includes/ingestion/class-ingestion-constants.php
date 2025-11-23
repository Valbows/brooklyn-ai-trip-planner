<?php
/**
 * Shared constants for ingestion logging + metadata keys.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Ingestion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ingestion_Constants {
	public const ACTION_VENUES_START     = 'ingest.venues.start';
	public const ACTION_VENUES_COMPLETED = 'ingest.venues.completed';
	public const ACTION_VALIDATION_ERROR = 'ingest.validation_error';
	public const ACTION_BATCH_FAILURE    = 'ingest.batch_failure';
	public const ACTION_BATCH_RETRY      = 'ingest.batch_retry';

	public const CONTEXT_DRY_RUN   = 'dry_run';
	public const CONTEXT_ERROR     = 'error';
	public const CONTEXT_METADATA  = 'metadata';
	public const CONTEXT_OPERATION = 'operation';
	public const CONTEXT_ATTEMPT   = 'attempt';
	public const CONTEXT_SLUG      = 'slug';
	public const CONTEXT_TARGET    = 'target';
	public const CONTEXT_DURATION  = 'duration_s';
	public const CONTEXT_ROWS      = 'rows';
	public const CONTEXT_ENRICHED  = 'enriched';
	public const CONTEXT_SUPABASE  = 'supabase';
	public const CONTEXT_PINECONE  = 'pinecone';
	public const CONTEXT_ERRORS    = 'errors';

	public const REQUIRED_COLUMNS = array( 'name', 'slug', 'borough', 'latitude', 'longitude' );
}
