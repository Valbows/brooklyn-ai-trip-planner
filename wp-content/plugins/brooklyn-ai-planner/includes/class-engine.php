<?php
/**
 * Recommendation Engine Core.
 *
 * Orchestrates the multi-stage itinerary generation pipeline:
 * 0. Guardrails (Rate limit, Validation)
 * 1. K-Means Lookup (Pinecone)
 * 2. RAG Semantic Search (Pinecone)
 * 3. MBA Boost (Supabase Association Rules)
 * 4. Filters & Constraints (Google Maps, Time, Budget)
 * 5. LLM Ordering (Gemini)
 *
 * @package BrooklynAI
 */

namespace BrooklynAI;

use BrooklynAI\Clients\Gemini_Client;
use BrooklynAI\Clients\GoogleMaps_Client;
use BrooklynAI\Clients\Pinecone_Client;
use BrooklynAI\Clients\Supabase_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Engine {
	private const VENUE_SELECT_FIELDS = 'id,slug,name,borough,categories,latitude,longitude,budget,vibe_summary,is_sbrn_member,accessibility,website,phone,address,hours';
	private const MBA_SELECT_FIELDS   = 'seed_slug,recommendation_slug,lift,confidence';
	private const MBA_MAX_SEEDS       = 5;
	private const MBA_MIN_LIFT        = 1.2;
	private const SBRN_BOOST          = 1.2;
	private const LLM_MAX_CANDIDATES  = 12;
	private const LLM_PROMPT_VERSION  = 'v1';

	private Security_Manager $security;
	private Cache_Service $cache;
	private Pinecone_Client $pinecone;
	private Supabase_Client $supabase;
	private GoogleMaps_Client $maps;
	private Gemini_Client $gemini;
	private Analytics_Logger $analytics;
	private bool $pinecone_available = true;
	/** @var array<string, array<string, mixed>> */
	private array $venue_cache = array();

	public function __construct(
		Security_Manager $security,
		Cache_Service $cache,
		Pinecone_Client $pinecone,
		Supabase_Client $supabase,
		GoogleMaps_Client $maps,
		Gemini_Client $gemini,
		Analytics_Logger $analytics
	) {
		$this->security  = $security;
		$this->cache     = $cache;
		$this->pinecone  = $pinecone;
		$this->supabase  = $supabase;
		$this->maps      = $maps;
		$this->gemini    = $gemini;
		$this->analytics = $analytics;
	}

	/**
	 * Generates a personalized itinerary.
	 *
	 * @param array<string, mixed> $request Raw request parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public function generate_itinerary( array $request ) {
		$start_time = microtime( true );
		error_log( 'BATP: Starting itinerary generation.' );

		// Stage 0: Guardrails
		$validated = $this->stage_guardrails( $request );
		if ( is_wp_error( $validated ) ) {
			$this->log_stage_error( 'guardrails', $validated );
			error_log( 'BATP: Guardrails failed: ' . $validated->get_error_message() );
			return $validated;
		}

		// Check Cache
		$cached = $this->cache->get( 'itinerary', $validated );
		if ( $cached ) {
			$this->log_stage_success( 'cache_hit', array( 'duration' => microtime( true ) - $start_time ) );
			error_log( 'BATP: Cache hit returning cached itinerary.' );
			return $cached;
		}

		// Stage 1: K-Means Lookup (Candidate Retrieval)
		$candidates = $this->stage_kmeans_lookup( $validated );
		if ( is_wp_error( $candidates ) ) {
			$this->log_stage_error( 'kmeans', $candidates );
			error_log( 'BATP: K-Means failed: ' . $candidates->get_error_message() );
			return $candidates;
		}
		$this->log_stage_success( 'kmeans', array( 'count' => count( $candidates ) ) );
		error_log( 'BATP: K-Means candidates found: ' . count( $candidates ) );

		// Stage 2: Semantic RAG (Pinecone semantic search)
		$semantic = $this->stage_semantic_rag( $validated, $candidates );
		if ( is_wp_error( $semantic ) ) {
			$this->log_stage_error( 'semantic', $semantic );
			error_log( 'BATP: Semantic RAG failed: ' . $semantic->get_error_message() );
			return $semantic;
		}
		$candidates = $semantic;
		$this->log_stage_success( 'semantic', array( 'count' => count( $candidates ) ) );
		error_log( 'BATP: Semantic RAG count: ' . count( $candidates ) );

		// Stage 3: MBA boost
		$boosted = $this->stage_mba_boost( $candidates );
		if ( is_wp_error( $boosted ) ) {
			$this->log_stage_error( 'mba', $boosted );
			// Non-fatal error: Log it and proceed with original candidates
			error_log( 'BATP: MBA Boost failed (non-fatal): ' . $boosted->get_error_message() );
			$candidates = $candidates;
		} else {
			$candidates = $boosted;
			$this->log_stage_success( 'mba', array( 'count' => count( $candidates ) ) );
		}

		// Stage 4: Filters & constraints
		$filtered = $this->stage_filters_and_constraints( $validated, $candidates );
		if ( is_wp_error( $filtered ) ) {
			$this->log_stage_error( 'filters', $filtered );
			return $filtered;
		}
		$candidates = $filtered;
		$this->log_stage_success( 'filters', array( 'count' => count( $candidates ) ) );
		error_log( 'BATP: Post-filter candidates: ' . count( $candidates ) );

		// Stage 5: LLM ordering
		$ordered = $this->stage_llm_ordering( $validated, $candidates );
		if ( is_wp_error( $ordered ) ) {
			$this->log_stage_error( 'llm', $ordered );
			error_log( 'BATP: LLM ordering failed: ' . $ordered->get_error_message() );
			return $ordered;
		}
		$candidates = $ordered['candidates'];
		$itinerary  = $ordered['itinerary'];
		$meta       = array_merge( $ordered['meta'], array( 'duration' => microtime( true ) - $start_time ) );
		$status     = empty( $itinerary['items'] ) ? 'partial' : 'complete';
		$this->log_stage_success( 'llm', array( 'items' => count( $itinerary['items'] ?? array() ) ) );
		error_log( 'BATP: Itinerary items generated: ' . count( $itinerary['items'] ?? array() ) );

		$response = array(
			'candidates' => $candidates,
			'itinerary'  => $itinerary,
			'meta'       => $meta,
			'status'     => $status,
		);

		$this->cache->set( 'itinerary', $validated, $response, Cache_Service::TTL_GEMINI );

		return $response;
	}

	/**
	 * Stage 0: Guardrails.
	 * Validates input, checks rate limits and nonces.
	 *
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>|WP_Error
	 */
	private function stage_guardrails( array $request ) {
		// 1. Nonce Check (Frontend should send 'nonce')
		if ( ! isset( $request['nonce'] ) ) {
			return new WP_Error( 'batp_invalid_nonce', __( 'Invalid security token.', 'brooklyn-ai-planner' ), array( 'status' => 403 ) );
		}

		if ( ! wp_verify_nonce( $request['nonce'], 'batp_generate_itinerary' ) ) {
			error_log( sprintf( 'BATP nonce failed for user %d, token %s', get_current_user_id(), $request['nonce'] ) );
			return new WP_Error( 'batp_invalid_nonce', __( 'Invalid security token.', 'brooklyn-ai-planner' ), array( 'status' => 403 ) );
		}

		// 2. Rate Limit
		$rate_limit = $this->security->enforce_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		// 3. Input Validation
		$defaults = array(
			'interests'   => array(),
			'budget'      => 'medium', // low, medium, high
			'time_window' => 240, // minutes
			'latitude'    => null,
			'longitude'   => null,
		);

		$data = array_merge( $defaults, $request );

		if ( ! is_array( $data['interests'] ) ) {
			return new WP_Error( 'batp_invalid_input', __( 'Interests must be an array.', 'brooklyn-ai-planner' ) );
		}

		if ( ! is_numeric( $data['time_window'] ) || $data['time_window'] < 30 || $data['time_window'] > 720 ) {
			return new WP_Error( 'batp_invalid_input', __( 'Time window must be between 30 minutes and 12 hours.', 'brooklyn-ai-planner' ) );
		}

		if ( null !== $data['latitude'] && ( ! is_numeric( $data['latitude'] ) || $data['latitude'] < -90 || $data['latitude'] > 90 ) ) {
			return new WP_Error( 'batp_invalid_input', __( 'Invalid latitude.', 'brooklyn-ai-planner' ) );
		}

		return $data;
	}

	/**
	 * Stage 1: K-Means Lookup.
	 * Finds closest centroid in Pinecone and retrieves candidates from Supabase.
	 *
	 * @param array<string, mixed> $data
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function stage_kmeans_lookup( array $data ) {
		// If no location provided, we might need a fallback or default centroid strategy.
		// For now, assuming user location or Brooklyn center.
		$lat = $data['latitude'] ?? 40.6782; // Default to Brooklyn center
		$lng = $data['longitude'] ?? -73.9442;

		// 1. Query Pinecone for centroids
		// Note: This assumes a separate index or namespace for centroids, or a metadata filter.
		// Here we assume a 'centroids' namespace.
		error_log( "BATP: Pinecone query at lat: $lat, lng: $lng" );
		$results = $this->pinecone->query(
			'brooklyn-centroids', // Index name - this should probably be config
			array_fill( 0, 768, 0 ), // Zero vector if we rely purely on metadata/geo, or we embed the user location context?
			// Actually, usually K-means inference happens locally or we query closest centroid vector.
			// Simplification: Query venues index directly with geo-filter if no centroids index exists yet.
			// Let's use a radius search on the main index for Phase 4 start.
			array(
				'filter' => array(
					'latitude'  => array(
						'$gte' => $lat - 0.05,
						'$lte' => $lat + 0.05,
					),
					'longitude' => array(
						'$gte' => $lng - 0.05,
						'$lte' => $lng + 0.05,
					),
				),
				'topK'   => 50,
			)
		);

		if ( is_wp_error( $results ) ) {
			error_log( 'BATP: Pinecone query error: ' . $results->get_error_message() );
			if ( 'http_request_failed' === $results->get_error_code() ) {
				$this->pinecone_available = false;
				$this->log_stage_error( 'pinecone_connect', $results );
				error_log( 'BATP: Pinecone unavailable, attempting Supabase fallback.' );
				$fallback = $this->load_supabase_fallback_candidates();
				error_log( 'BATP: Supabase fallback count: ' . count( $fallback ) );
				if ( ! empty( $fallback ) ) {
					return $fallback;
				}
				return array();
			}

			return $results;
		}

		error_log( 'BATP: Pinecone query raw match count: ' . count( $results['matches'] ?? array() ) );

		$matches = $results['matches'] ?? array();
		if ( empty( $matches ) ) {
			return array();
		}

		$ordered_matches = array();
		$slugs           = array();
		foreach ( $matches as $match ) {
			if ( ! isset( $match['id'] ) ) {
				continue;
			}

			$slug = sanitize_text_field( (string) $match['id'] );
			if ( '' === $slug ) {
				continue;
			}

			$ordered_matches[] = array(
				'slug'  => $slug,
				'score' => isset( $match['score'] ) ? (float) $match['score'] : null,
			);
			$slugs[]           = $slug;
		}

		if ( empty( $ordered_matches ) ) {
			return array();
		}

		$unique_slugs = array_values( array_unique( $slugs ) );
		error_log( 'BATP: Unique slugs from Pinecone: ' . implode( ', ', $unique_slugs ) );
		$records = $this->load_venues_by_slugs( $unique_slugs );
		if ( is_wp_error( $records ) ) {
			error_log( 'BATP: Supabase load venues error: ' . $records->get_error_message() );
			return $records;
		}
		error_log( 'BATP: Venues loaded from Supabase: ' . count( $records ) );

		foreach ( $records as $slug => $record ) {
			$this->cache_venue_record( $slug, $record );
		}

		$candidates = array();
		foreach ( $ordered_matches as $match ) {
			$slug = $match['slug'];
			$data = $this->venue_cache[ $slug ] ?? array( 'slug' => $slug );

			$candidates[] = array(
				'slug'    => $slug,
				'score'   => $match['score'],
				'data'    => $data,
				'sources' => array( 'kmeans' ),
			);
		}

		return $candidates;
	}

	/**
	 * Stage 2: Semantic RAG search using embeddings + Pinecone.
	 *
	 * @param array<string, mixed>              $data
	 * @param array<int, array<string, mixed>>  $seed_candidates
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function stage_semantic_rag( array $data, array $seed_candidates ) {
		if ( ! $this->pinecone_available || empty( $data['interests'] ) ) {
			return $seed_candidates;
		}

		$prompt    = $this->build_interest_prompt( $data );
		$embedding = $this->get_interest_embedding( $prompt );
		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}

		$payload = array(
			'includeMetadata' => true,
			'topK'            => 40,
			'namespace'       => 'venues',
			'vector'          => $embedding,
		);

		if ( isset( $data['latitude'], $data['longitude'] ) && is_numeric( $data['latitude'] ) && is_numeric( $data['longitude'] ) ) {
			$payload['filter'] = array(
				'latitude'  => array(
					'$gte' => (float) $data['latitude'] - 0.05,
					'$lte' => (float) $data['latitude'] + 0.05,
				),
				'longitude' => array(
					'$gte' => (float) $data['longitude'] - 0.05,
					'$lte' => (float) $data['longitude'] + 0.05,
				),
			);
		}

		$results = $this->pinecone->query( 'brooklyn-venues', $payload );
		if ( is_wp_error( $results ) ) {
			if ( 'http_request_failed' === $results->get_error_code() ) {
				$this->pinecone_available = false;
				$this->log_stage_error( 'pinecone_semantic', $results );
				if ( ! empty( $seed_candidates ) ) {
					return $seed_candidates;
				}
				$fallback = $this->load_supabase_fallback_candidates();
				if ( ! empty( $fallback ) ) {
					return $fallback;
				}
			}
			return $results;
		}

		$matches = $results['matches'] ?? array();
		if ( empty( $matches ) ) {
			return $seed_candidates;
		}

		$semantic_candidates = array();
		foreach ( $matches as $match ) {
			$slug = $this->normalize_slug( $match['metadata']['slug'] ?? $match['id'] ?? null );
			if ( '' === $slug ) {
				continue;
			}

			if ( isset( $match['metadata'] ) && is_array( $match['metadata'] ) ) {
				$this->cache_venue_record( $slug, $match['metadata'] );
			}

			$data = $this->venue_cache[ $slug ] ?? array( 'slug' => $slug );

			$semantic_candidates[] = array(
				'slug'    => $slug,
				'score'   => isset( $match['score'] ) ? (float) $match['score'] : null,
				'data'    => $data,
				'sources' => array( 'semantic' ),

			);
		}

		return $this->merge_candidates( $seed_candidates, $semantic_candidates );
	}

	/**
	 * Fallback when Pinecone is unavailable: load recent venues directly from Supabase.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function load_supabase_fallback_candidates(): array {
		error_log( 'BATP: Loading Supabase fallback candidates.' );
		$response = $this->supabase->select(
			'venues',
			array(
				'select' => self::VENUE_SELECT_FIELDS,
				'limit'  => 25,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'BATP: Supabase select error: ' . $response->get_error_message() );
			return array();
		}

		if ( empty( $response ) ) {
			error_log( 'BATP: Supabase select returned empty result.' );
			return array();
		}

		error_log( 'BATP: Supabase fallback found ' . count( $response ) . ' records.' );

		$candidates = array();
		foreach ( $response as $record ) {
			if ( empty( $record['slug'] ) ) {
				continue;
			}

			$slug = $this->normalize_slug( $record['slug'] );
			$this->cache_venue_record( $slug, $record );
			$candidates[] = array(
				'slug'    => $slug,
				'score'   => null,
				'data'    => $record,
				'sources' => array( 'supabase_fallback' ),
			);
		}

		return $candidates;
	}

	/**
	 * @param array<int, array<string, mixed>> $seed
	 * @param array<int, array<string, mixed>> $semantic
	 * @return array<int, array<string, mixed>>
	 */
	private function merge_candidates( array $seed, array $semantic ): array {
		$combined = array();

		foreach ( $seed as $candidate ) {
			$slug              = $candidate['slug'];
			$combined[ $slug ] = $candidate;
		}

		foreach ( $semantic as $candidate ) {
			$slug = $candidate['slug'];
			if ( isset( $combined[ $slug ] ) ) {
				$combined[ $slug ]['score']   = $this->combine_scores( $combined[ $slug ]['score'], $candidate['score'] );
				$combined[ $slug ]['sources'] = array_values( array_unique( array_merge( $combined[ $slug ]['sources'], $candidate['sources'] ) ) );
				continue;
			}

			$combined[ $slug ] = $candidate;
		}

		return array_values( $combined );
	}

	private function combine_scores( $primary, $secondary ) {
		if ( null === $primary ) {
			return $secondary;
		}

		if ( null === $secondary ) {
			return $primary;
		}

		return max( $primary, $secondary );
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function stage_mba_boost( array $candidates ) {
		if ( empty( $candidates ) ) {
			return $candidates;
		}

		$seed_slugs = $this->determine_seed_slugs( $candidates );
		if ( empty( $seed_slugs ) ) {
			return $candidates;
		}

		$rules = $this->load_association_rules( $seed_slugs );
		if ( is_wp_error( $rules ) ) {
			return $rules;
		}

		$indexed_rules = $this->index_mba_rules( $rules );
		if ( empty( $indexed_rules ) ) {
			return $candidates;
		}

		foreach ( $candidates as &$candidate ) {
			$slug = $candidate['slug'] ?? '';
			if ( isset( $indexed_rules[ $slug ] ) ) {
				$boost                = $indexed_rules[ $slug ];
				$candidate['score']   = $this->apply_lift( $candidate['score'] ?? null, $boost['lift'] );
				$existing_sources     = isset( $candidate['sources'] ) && is_array( $candidate['sources'] ) ? $candidate['sources'] : array();
				$candidate['sources'] = array_values( array_unique( array_merge( $existing_sources, array( 'mba' ) ) ) );
				$meta                 = isset( $candidate['meta'] ) && is_array( $candidate['meta'] ) ? $candidate['meta'] : array();
				$meta['mba']          = array(
					'seed'       => $boost['seed'],
					'lift'       => $boost['lift'],
					'confidence' => $boost['confidence'],
				);
				$candidate['meta']    = $meta;
			}
		}
		unset( $candidate );

		return $candidates;
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, string>
	 */
	private function determine_seed_slugs( array $candidates ): array {
		$sorted = $candidates;
		usort(
			$sorted,
			function ( $a, $b ) {
				$score_a = isset( $a['score'] ) ? (float) $a['score'] : 0.0;
				$score_b = isset( $b['score'] ) ? (float) $b['score'] : 0.0;
				return $score_b <=> $score_a;
			}
		);

		$seeds = array();
		foreach ( $sorted as $candidate ) {
			$slug = $this->normalize_slug( $candidate['slug'] ?? null );
			if ( '' === $slug ) {
				continue;
			}

			if ( ! in_array( $slug, $seeds, true ) ) {
				$seeds[] = $slug;
			}

			if ( count( $seeds ) >= self::MBA_MAX_SEEDS ) {
				break;
			}
		}

		return $seeds;
	}

	/**
	 * @param array<int, string> $seed_slugs
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function load_association_rules( array $seed_slugs ) {
		if ( empty( $seed_slugs ) ) {
			return array();
		}

		return $this->supabase->select_in(
			'association_rules',
			'seed_slug',
			$seed_slugs,
			array(
				'select' => self::MBA_SELECT_FIELDS,
				'limit'  => 200,
				'order'  => 'lift.desc',
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rules
	 * @return array<string, array{seed:string,lift:float,confidence:float|null}>
	 */
	private function index_mba_rules( array $rules ): array {
		$indexed = array();

		foreach ( $rules as $rule ) {
			$seed       = $this->normalize_slug( $rule['seed_slug'] ?? null );
			$recommend  = $this->normalize_slug( $rule['recommendation_slug'] ?? null );
			$lift       = isset( $rule['lift'] ) ? (float) $rule['lift'] : 0.0;
			$confidence = isset( $rule['confidence'] ) ? (float) $rule['confidence'] : null;

			if ( '' === $seed || '' === $recommend || $lift < self::MBA_MIN_LIFT ) {
				continue;
			}

			if ( ! isset( $indexed[ $recommend ] ) || $lift > $indexed[ $recommend ]['lift'] ) {
				$indexed[ $recommend ] = array(
					'seed'       => $seed,
					'lift'       => $lift,
					'confidence' => $confidence,
				);
			}
		}

		return $indexed;
	}

	private function apply_lift( $score, float $lift ) {
		if ( null === $score ) {
			return $lift;
		}

		return $score * $lift;
	}

	/**
	 * @param array<string, mixed>             $request
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function stage_filters_and_constraints( array $request, array $candidates ) {
		$result   = $this->apply_budget_filter( $request, $candidates );
		$result   = $this->apply_accessibility_filter( $request, $result );
		$distance = $this->apply_distance_constraint( $request, $result );
		if ( is_wp_error( $distance ) ) {
			return $distance;
		}
		$result = $distance;
		$result = $this->apply_sbrn_boost( $result );

		return $result;
	}

	/**
	 * @param array<string, mixed>             $request
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_budget_filter( array $request, array $candidates ): array {
		$requested = $this->normalize_budget( $request['budget'] ?? 'medium' );
		if ( null === $requested ) {
			return $candidates;
		}

		return array_values(
			array_filter(
				$candidates,
				function ( $candidate ) use ( $requested ) {
					$budget = isset( $candidate['data']['budget'] ) ? $this->normalize_budget( $candidate['data']['budget'] ) : null;
					if ( null === $budget ) {
						return true;
					}

					return $budget <= $requested;
				}
			)
		);
	}

	private function normalize_budget( $budget ): ?int {
		$map = array(
			'low'    => 1,
			'medium' => 2,
			'high'   => 3,
		);

		if ( ! is_string( $budget ) ) {
			return null;
		}

		$normalized = strtolower( sanitize_text_field( $budget ) );
		return $map[ $normalized ] ?? null;
	}

	/**
	 * @param array<string, mixed>             $request
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_accessibility_filter( array $request, array $candidates ): array {
		$preferences = isset( $request['accessibility_preferences'] ) ? $this->sanitize_string_array( (array) $request['accessibility_preferences'] ) : array();
		if ( empty( $preferences ) ) {
			return $candidates;
		}

		return array_values(
			array_filter(
				$candidates,
				function ( $candidate ) use ( $preferences ) {
					$data       = isset( $candidate['data']['accessibility'] ) ? $candidate['data']['accessibility'] : array();
					$attributes = $this->sanitize_string_array( (array) $data );
					if ( empty( $attributes ) ) {
						return false;
					}

					return empty( array_diff( $preferences, $attributes ) );
				}
			)
		);
	}

	/**
	 * @param array<int, string> $values
	 * @return array<int, string>
	 */
	private function sanitize_string_array( array $values ): array {
		$clean = array();
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$normalized = sanitize_key( $value );
				if ( '' !== $normalized ) {
					$clean[] = $normalized;
				}
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param array<string, mixed>             $request
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function apply_distance_constraint( array $request, array $candidates ) {
		$origin = $this->resolve_origin( $request );
		if ( null === $origin ) {
			return $candidates;
		}

		$destinations = $this->collect_destination_coordinates( $candidates );
		if ( empty( $destinations ) ) {
			return $candidates;
		}

		$matrix = $this->fetch_distance_matrix( $origin, $destinations );
		if ( is_wp_error( $matrix ) ) {
			return $matrix;
		}

		$max_minutes = $this->max_travel_minutes( (int) ( $request['time_window'] ?? 240 ) );
		$filtered    = array();

		foreach ( $candidates as $candidate ) {
			$slug = $candidate['slug'] ?? '';
			if ( isset( $matrix[ $slug ] ) ) {
				$minutes = $matrix[ $slug ];
				if ( $minutes > $max_minutes ) {
					continue;
				}
				$meta                   = isset( $candidate['meta'] ) && is_array( $candidate['meta'] ) ? $candidate['meta'] : array();
				$meta['travel_minutes'] = $minutes;
				$candidate['meta']      = $meta;
			}

			$filtered[] = $candidate;
		}

		return empty( $filtered ) ? $candidates : array_values( $filtered );
	}

	private function resolve_origin( array $request ): ?array {
		if ( isset( $request['latitude'], $request['longitude'] ) && is_numeric( $request['latitude'] ) && is_numeric( $request['longitude'] ) ) {
			return array(
				'lat' => (float) $request['latitude'],
				'lng' => (float) $request['longitude'],
			);
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, array{slug:string,lat:float,lng:float}>
	 */
	private function collect_destination_coordinates( array $candidates ): array {
		$destinations = array();
		foreach ( $candidates as $candidate ) {
			$lat = $candidate['data']['latitude'] ?? null;
			$lng = $candidate['data']['longitude'] ?? null;
			if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
				$destinations[] = array(
					'slug' => $candidate['slug'],
					'lat'  => (float) $lat,
					'lng'  => (float) $lng,
				);
			}
		}

		return $destinations;
	}

	/**
	 * @param array{lat:float,lng:float}                $origin
	 * @param array<int, array{slug:string,lat:float,lng:float}> $destinations
	 * @return array<string, int>|WP_Error
	 */
	private function fetch_distance_matrix( array $origin, array $destinations ) {
		$cache_key = array(
			'origin'       => $origin,
			'destinations' => $destinations,
		);
		$cached    = $this->cache->get( 'distance', $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$origin_string      = sprintf( '%f,%f', $origin['lat'], $origin['lng'] );
		$destination_string = implode(
			'|',
			array_map(
				static function ( $destination ) {
					return sprintf( '%f,%f', $destination['lat'], $destination['lng'] );
				},
				$destinations
			)
		);

		$params = array(
			'origins'      => $origin_string,
			'destinations' => $destination_string,
			'mode'         => 'walking',
			'units'        => 'metric',
		);

		$response = $this->maps->distance_matrix( $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$durations = array();
		$elements  = $response['rows'][0]['elements'] ?? array();
		foreach ( $elements as $index => $element ) {
			if ( ! isset( $destinations[ $index ] ) ) {
				continue;
			}

			if ( isset( $element['status'] ) && 'OK' !== $element['status'] ) {
				continue;
			}

			$seconds = isset( $element['duration']['value'] ) ? (int) $element['duration']['value'] : null;
			if ( null === $seconds ) {
				continue;
			}

			$slug               = $destinations[ $index ]['slug'];
			$durations[ $slug ] = (int) ceil( $seconds / 60 );
		}

		$this->cache->set( 'distance', $cache_key, $durations, Cache_Service::TTL_PIPELINE );

		return $durations;
	}

	private function max_travel_minutes( int $time_window ): int {
		$minutes = (int) floor( $time_window / 2 );
		return max( 15, min( 180, $minutes ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_sbrn_boost( array $candidates ): array {
		foreach ( $candidates as &$candidate ) {
			$is_member = ! empty( $candidate['data']['is_sbrn_member'] );
			if ( ! $is_member ) {
				continue;
			}

			$candidate['score']   = ( $candidate['score'] ?? 1 ) * self::SBRN_BOOST;
			$existing_sources     = isset( $candidate['sources'] ) && is_array( $candidate['sources'] ) ? $candidate['sources'] : array();
			$existing_sources[]   = 'sbrn';
			$candidate['sources'] = array_values( array_unique( $existing_sources ) );
		}
		unset( $candidate );

		return $candidates;
	}

	/**
	 * Stage 5: LLM ordering and narrative generation.
	 *
	 * @param array<string, mixed>             $request
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<string, mixed>|WP_Error
	 */
	private function stage_llm_ordering( array $request, array $candidates ) {
		if ( empty( $candidates ) ) {
			return array(
				'candidates' => $candidates,
				'itinerary'  => array(
					'items' => array(),
					'meta'  => array(),
				),
				'meta'       => array(
					'prompt_version'  => self::LLM_PROMPT_VERSION,
					'cached'          => false,
					'candidate_count' => 0,
				),
			);
		}

		$prepared = $this->prepare_llm_candidates( $candidates );
		if ( empty( $prepared ) ) {
			return array(
				'candidates' => $candidates,
				'itinerary'  => array(
					'items' => array(),
					'meta'  => array(),
				),
				'meta'       => array(
					'prompt_version'  => self::LLM_PROMPT_VERSION,
					'cached'          => false,
					'candidate_count' => count( $candidates ),
				),
			);
		}

		$context   = $this->build_llm_context( $request, $prepared );
		$cache_key = array(
			'context' => $context,
			'version' => self::LLM_PROMPT_VERSION,
		);

		$cached = $this->cache->get( 'llm', $cache_key );
		if ( $cached ) {
			if ( ! isset( $cached['meta'] ) || ! is_array( $cached['meta'] ) ) {
				$cached['meta'] = array();
			}
			$cached['meta']['cached'] = true;
			return $cached;
		}

		$payload  = $this->build_llm_payload( $context );
		$response = $this->gemini->generate_content( $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->extract_gemini_text( $response );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'batp_llm_invalid_json', __( 'Gemini response was not valid JSON.', 'brooklyn-ai-planner' ) );
		}

		$items = $this->normalize_llm_items( isset( $decoded['items'] ) && is_array( $decoded['items'] ) ? $decoded['items'] : array() );

		$itinerary = array(
			'items' => $items,
			'meta'  => isset( $decoded['meta'] ) && is_array( $decoded['meta'] ) ? $decoded['meta'] : array(),
		);

		$ordered_candidates = $this->apply_llm_order_to_candidates( $candidates, $items );

		$result = array(
			'candidates' => $ordered_candidates,
			'itinerary'  => $itinerary,
			'meta'       => array(
				'prompt_version'  => self::LLM_PROMPT_VERSION,
				'cached'          => false,
				'candidate_count' => count( $candidates ),
			),
		);

		$this->cache->set( 'llm', $cache_key, $result, Cache_Service::TTL_GEMINI );

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, array<string, mixed>>
	 */
	private function prepare_llm_candidates( array $candidates ): array {
		$limited  = array_slice( $candidates, 0, self::LLM_MAX_CANDIDATES );
		$prepared = array();

		foreach ( $limited as $candidate ) {
			$slug = $this->normalize_slug( $candidate['slug'] ?? null );
			if ( '' === $slug ) {
				continue;
			}

			$data       = isset( $candidate['data'] ) && is_array( $candidate['data'] ) ? $candidate['data'] : array();
			$categories = isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array();
			$prepared[] = array(
				'slug'           => $slug,
				'name'           => sanitize_text_field( $data['name'] ?? $slug ),
				'borough'        => sanitize_text_field( $data['borough'] ?? '' ),
				'budget'         => sanitize_text_field( (string) ( $data['budget'] ?? '' ) ),
				'categories'     => array_values( array_filter( array_map( 'sanitize_text_field', (array) $categories ) ) ),
				'vibe_summary'   => sanitize_text_field( $data['vibe_summary'] ?? '' ),
				'website'        => esc_url_raw( $data['website'] ?? '' ),
				'phone_number'   => sanitize_text_field( $data['phone'] ?? '' ), // Map DB 'phone' to 'phone_number'
				'address'        => sanitize_text_field( $data['address'] ?? '' ),
				'travel_minutes' => isset( $candidate['meta']['travel_minutes'] ) ? (int) $candidate['meta']['travel_minutes'] : null,
				'sources'        => isset( $candidate['sources'] ) && is_array( $candidate['sources'] ) ? array_values( $candidate['sources'] ) : array(),
				'score'          => isset( $candidate['score'] ) ? (float) $candidate['score'] : null,
			);
		}

		return $prepared;
	}

	/**
	 * @param array<string, mixed>             $request
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<string, mixed>
	 */
	private function build_llm_context( array $request, array $candidates ): array {
		$interests     = $this->sanitize_string_array( (array) ( $request['interests'] ?? array() ) );
		$accessibility = isset( $request['accessibility_preferences'] ) ? $this->sanitize_string_array( (array) $request['accessibility_preferences'] ) : array();
		$time_window   = isset( $request['time_window'] ) ? (int) $request['time_window'] : 240;
		$max_travel    = $this->max_travel_minutes( $time_window );
		$party_size    = isset( $request['party_size'] ) ? (int) $request['party_size'] : 2;

		return array(
			'version'     => self::LLM_PROMPT_VERSION,
			'profile'     => array(
				'interests'     => $interests,
				'budget'        => sanitize_text_field( (string) ( $request['budget'] ?? 'medium' ) ),
				'time_window'   => $time_window,
				'accessibility' => $accessibility,
				'party_size'    => max( 1, $party_size ),
			),
			'constraints' => array(
				'max_travel_minutes' => $max_travel,
				'candidate_count'    => count( $candidates ),
			),
			'candidates'  => $candidates,
		);
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private function build_llm_payload( array $context ): array {
		$instructions = 'You are the Brooklyn AI Trip Concierge. Using only the provided venue candidates, build an ordered same-day itinerary. Reference travel time, accessibility, and budget constraints. Respond ONLY with JSON (no markdown) following this schema: {"meta":{"summary":"..."},"items":[{"slug":"venue-slug","title":"string","order":1,"arrival_minute":0,"duration_minutes":60,"notes":"string"}]}.';
		$json_context = wp_json_encode( $context );
		if ( false === $json_context ) {
			$json_context = json_encode( $context );
		}

		return array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $instructions ),
						array( 'text' => (string) $json_context ),
					),
				),
			),
			'generationConfig' => array(
				'maxOutputTokens'  => 768,
				'temperature'      => 0.4,
				'responseMimeType' => 'application/json',
			),
		);
	}

	/**
	 * @param array<string, mixed> $response
	 * @return string|WP_Error
	 */
	private function extract_gemini_text( array $response ) {
		if ( empty( $response['candidates'][0]['content']['parts'] ) ) {
			return new WP_Error( 'batp_llm_missing_text', __( 'Gemini response missing text.', 'brooklyn-ai-planner' ) );
		}

		$parts = $response['candidates'][0]['content']['parts'];
		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) && '' !== trim( (string) $part['text'] ) ) {
				$text = trim( (string) $part['text'] );
				if ( str_starts_with( $text, '```' ) ) {
					$text = preg_replace( '/^```(?:json)?/i', '', $text );
					$text = preg_replace( '/```$/', '', $text );
				}
				return trim( $text );
			}
		}

		return new WP_Error( 'batp_llm_missing_text', __( 'Gemini response missing text.', 'brooklyn-ai-planner' ) );
	}

	/**
	 * @param array<int, mixed> $items
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_llm_items( array $items ): array {
		$normalized = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$slug = $this->normalize_slug( $item['slug'] ?? null );
			if ( '' === $slug ) {
				continue;
			}

			$normalized[] = array(
				'slug'             => $slug,
				'title'            => sanitize_text_field( $item['title'] ?? '' ),
				'order'            => isset( $item['order'] ) ? (int) $item['order'] : $index + 1,
				'arrival_minute'   => isset( $item['arrival_minute'] ) ? (int) $item['arrival_minute'] : 0,
				'duration_minutes' => isset( $item['duration_minutes'] ) ? (int) $item['duration_minutes'] : 0,
				'notes'            => isset( $item['notes'] ) ? sanitize_text_field( $item['notes'] ) : '',
				'sources'          => isset( $item['sources'] ) && is_array( $item['sources'] ) ? $this->sanitize_string_array( $item['sources'] ) : array(),
			);
		}

		return $normalized;
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_llm_order_to_candidates( array $candidates, array $items ): array {
		if ( empty( $items ) ) {
			return $candidates;
		}

		$indexed = array();
		foreach ( $candidates as $candidate ) {
			$slug = $this->normalize_slug( $candidate['slug'] ?? null );
			if ( '' === $slug ) {
				continue;
			}
			$indexed[ $slug ] = $candidate;
		}

		$ordered = array();
		$used    = array();
		foreach ( $items as $item ) {
			$slug = $item['slug'];
			if ( ! isset( $indexed[ $slug ] ) ) {
				continue;
			}

			$candidate            = $indexed[ $slug ];
			$meta                 = isset( $candidate['meta'] ) && is_array( $candidate['meta'] ) ? $candidate['meta'] : array();
			$meta['llm']          = array(
				'title'            => $item['title'],
				'order'            => $item['order'],
				'arrival_minute'   => $item['arrival_minute'],
				'duration_minutes' => $item['duration_minutes'],
				'notes'            => $item['notes'],
			);
			$candidate['meta']    = $meta;
			$sources              = isset( $candidate['sources'] ) && is_array( $candidate['sources'] ) ? $candidate['sources'] : array();
			$sources[]            = 'llm';
			$candidate['sources'] = array_values( array_unique( array_merge( $sources, $item['sources'] ?? array() ) ) );

			$ordered[] = $candidate;
			$used[]    = $slug;
		}

		foreach ( $candidates as $candidate ) {
			$slug = $this->normalize_slug( $candidate['slug'] ?? null );
			if ( '' !== $slug && in_array( $slug, $used, true ) ) {
				continue;
			}
			$ordered[] = $candidate;
		}

		return $ordered;
	}

	private function build_interest_prompt( array $data ): string {
		$interests = array_map( 'sanitize_text_field', (array) $data['interests'] );
		$budget    = sanitize_text_field( (string) ( $data['budget'] ?? 'medium' ) );
		$time      = isset( $data['time_window'] ) ? (int) $data['time_window'] : 240;

		$segments = array(
			empty( $interests ) ? 'general brooklyn interests' : implode( ', ', $interests ),
			sprintf( 'budget:%s', $budget ),
			sprintf( 'duration_minutes:%d', $time ),
		);

		return implode( ' | ', array_filter( $segments ) );
	}

	/**
	 * @return array<int, float>|WP_Error
	 */
	private function get_interest_embedding( string $prompt ) {
		$cache_key = array(
			'type'   => 'interest_embedding',
			'prompt' => $prompt,
		);

		$cached = $this->cache->get( 'embedding', $cache_key );
		if ( $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$payload = array(
			'content' => array(
				'parts' => array(
					array(
						'text' => $prompt,
					),
				),
			),
		);

		$response = $this->gemini->embed_content( $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$values = $this->parse_embedding_response( $response );
		if ( is_wp_error( $values ) ) {
			return $values;
		}

		$this->cache->set( 'embedding', $cache_key, $values, Cache_Service::TTL_PIPELINE );

		return $values;
	}

	/**
	 * @return array<int, float>|WP_Error
	 */
	private function parse_embedding_response( array $response ) {
		if ( isset( $response['embedding']['values'] ) && is_array( $response['embedding']['values'] ) ) {
			return array_map( 'floatval', $response['embedding']['values'] );
		}

		if ( isset( $response['embeddings'][0]['values'] ) && is_array( $response['embeddings'][0]['values'] ) ) {
			return array_map( 'floatval', $response['embeddings'][0]['values'] );
		}

		return new WP_Error( 'batp_gemini_embedding_missing', __( 'Gemini did not return embedding values.', 'brooklyn-ai-planner' ) );
	}

	/**
	 * @param array<int, string> $slugs
	 * @return array<string, array<string, mixed>>|WP_Error
	 */
	private function load_venues_by_slugs( array $slugs ) {
		$normalized = array();
		foreach ( $slugs as $slug ) {
			$clean = $this->normalize_slug( $slug );
			if ( '' !== $clean ) {
				$normalized[] = $clean;
			}
		}

		$unique = array_values( array_unique( $normalized ) );
		if ( empty( $unique ) ) {
			return array();
		}

		$response = $this->supabase->select_in(
			'venues',
			'slug',
			$unique,
			array(
				'select' => self::VENUE_SELECT_FIELDS,
				'limit'  => 200,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$records = array();
		foreach ( $response as $record ) {
			if ( isset( $record['slug'] ) ) {
				$records[ (string) $record['slug'] ] = $record;
			}
		}

		return $records;
	}

	private function cache_venue_record( string $slug, array $record ): void {
		$this->venue_cache[ $slug ] = array_merge( array( 'slug' => $slug ), $record );
	}

	private function normalize_slug( $value ): string {
		return $value ? sanitize_title( (string) $value ) : '';
	}

	private function log_stage_error( string $stage, WP_Error $error ): void {
		$this->analytics->log(
			'engine_error',
			array(
				'metadata' => array(
					'stage'   => $stage,
					'message' => $error->get_error_message(),
					'code'    => $error->get_error_code(),
				),
			)
		);
	}

	private function log_stage_success( string $stage, array $meta = array() ): void {
		$this->analytics->log(
			'engine_stage_complete',
			array(
				'metadata' => array_merge( array( 'stage' => $stage ), $meta ),
			)
		);
	}
}
