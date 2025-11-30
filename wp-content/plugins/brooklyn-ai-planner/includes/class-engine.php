<?php
/**
 * Recommendation Engine Core (Google Places API Version).
 *
 * Orchestrates the itinerary generation pipeline:
 * 0. Guardrails (Rate limit, Validation)
 * 1. Query Google Places API (by interest type)
 * 2. Fetch Details for top candidates
 * 3. Hard Filters (hours, distance, accessibility, budget)
 * 4. (Optional) LLM Ordering (Gemini)
 * 5. Get Directions (Google Directions API)
 * 6. Build Response + Cache + Log
 *
 * @package BrooklynAI
 */

namespace BrooklynAI;

use BrooklynAI\Clients\Gemini_Client;
use BrooklynAI\Clients\GoogleMaps_Client;
use BrooklynAI\Clients\Google_Places_Client;
use BrooklynAI\Clients\Google_Directions_Client;
use BrooklynAI\Clients\Supabase_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Engine {
	private const LLM_MAX_CANDIDATES  = 12;
	private const LLM_PROMPT_VERSION  = 'v2';
	private const PLACES_RADIUS       = 3000; // 3km
	private const MAX_DETAILS_FETCH   = 15;
	private const MIN_VENUES_REQUIRED = 1;

	/**
	 * Interest to Google Places type mapping.
	 *
	 * @var array<string, string>
	 */
	private const INTEREST_TYPE_MAP = array(
		'food'          => 'restaurant',
		'restaurants'   => 'restaurant',
		'dining'        => 'restaurant',
		'art'           => 'museum',
		'museums'       => 'museum',
		'culture'       => 'museum',
		'parks'         => 'park',
		'outdoors'      => 'park',
		'nature'        => 'park',
		'shopping'      => 'shopping_mall',
		'fitness'       => 'gym',
		'coffee'        => 'cafe',
		'cafes'         => 'cafe',
		'entertainment' => 'movie_theater',
		'movies'        => 'movie_theater',
		'drinks'        => 'bar',
		'bars'          => 'bar',
		'nightlife'     => 'night_club',
		'clubs'         => 'night_club',
		'music'         => 'night_club',
		'history'       => 'museum',
		'attractions'   => 'tourist_attraction',
	);

	private Security_Manager $security;
	private Cache_Service $cache;
	private Supabase_Client $supabase;
	private ?Google_Places_Client $places;
	private ?Google_Directions_Client $directions;
	private ?GoogleMaps_Client $maps;
	private ?Gemini_Client $gemini;
	private Analytics_Logger $analytics;

	public function __construct(
		Security_Manager $security,
		Cache_Service $cache,
		Supabase_Client $supabase,
		?Google_Places_Client $places,
		?Google_Directions_Client $directions,
		?GoogleMaps_Client $maps,
		?Gemini_Client $gemini,
		Analytics_Logger $analytics
	) {
		$this->security   = $security;
		$this->cache      = $cache;
		$this->supabase   = $supabase;
		$this->places     = $places;
		$this->directions = $directions;
		$this->maps       = $maps;
		$this->gemini     = $gemini;
		$this->analytics  = $analytics;
	}

	/**
	 * Generates a personalized itinerary using Google Places API.
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
			error_log( 'BATP: Guardrails failed: ' . $validated->get_error_message() );
			return $validated;
		}

		// Check Cache
		$cached = $this->cache->get( 'itinerary', $validated );
		if ( $cached ) {
			error_log( 'BATP: Cache hit - returning cached itinerary.' );
			// Still log analytics for cache hits (each request counts)
			$this->log_itinerary( $validated, $cached );
			return $cached;
		}

		// Check if Google Places is configured
		if ( null === $this->places ) {
			return new WP_Error( 'batp_places_not_configured', __( 'Google Places API is not configured.', 'brooklyn-ai-planner' ) );
		}

		// Stage 1: Query Google Places API for each interest
		$all_venues = $this->stage_places_search( $validated );
		if ( is_wp_error( $all_venues ) ) {
			error_log( 'BATP: Places search failed: ' . $all_venues->get_error_message() );
			return $all_venues;
		}

		if ( empty( $all_venues ) ) {
			return new WP_Error( 'batp_no_venues', __( 'No venues found matching your criteria. Try different interests or location.', 'brooklyn-ai-planner' ) );
		}

		error_log( 'BATP: Places search returned ' . count( $all_venues ) . ' candidates.' );

		// Stage 2: Fetch details for top candidates
		$detailed_venues = $this->stage_fetch_details( $all_venues );
		if ( is_wp_error( $detailed_venues ) ) {
			error_log( 'BATP: Details fetch failed: ' . $detailed_venues->get_error_message() );
			return $detailed_venues;
		}

		error_log( 'BATP: Fetched details for ' . count( $detailed_venues ) . ' venues.' );

		// Stage 3: Apply hard filters
		$filtered = $this->stage_apply_filters( $validated, $detailed_venues );
		if ( is_wp_error( $filtered ) ) {
			error_log( 'BATP: Filtering failed: ' . $filtered->get_error_message() );
			return $filtered;
		}

		if ( count( $filtered ) < self::MIN_VENUES_REQUIRED ) {
			return new WP_Error( 'batp_no_venues_after_filter', __( 'No venues match your filters. Try relaxing your constraints.', 'brooklyn-ai-planner' ) );
		}

		error_log( 'BATP: After filtering: ' . count( $filtered ) . ' venues.' );

		// Stage 4: LLM ordering (optional, for >3 venues)
		$ordered = $this->stage_llm_ordering( $validated, $filtered );
		if ( is_wp_error( $ordered ) ) {
			// Non-fatal - use simple proximity sort
			error_log( 'BATP: LLM ordering failed (using proximity sort): ' . $ordered->get_error_message() );
			$ordered = $this->simple_sort_by_rating( $filtered );
		}

		error_log( 'BATP: Ordered ' . count( $ordered ) . ' venues.' );

		// Stage 5: Get directions
		$directions_data = $this->stage_get_directions( $validated, $ordered );
		if ( is_wp_error( $directions_data ) ) {
			// Non-fatal - continue without polyline
			error_log( 'BATP: Directions failed (continuing without route): ' . $directions_data->get_error_message() );
			$directions_data = array(
				'polyline'     => '',
				'legs'         => array(),
				'total_text'   => '',
				'overview_url' => '#',
			);
		}

		// Build final response
		$normalized = $this->normalize_venues_for_response( $ordered );
		$itinerary  = $this->build_itinerary( $normalized, $directions_data );
		$meta       = array(
			'duration'     => microtime( true ) - $start_time,
			'venue_count'  => count( $normalized ),
			'pipeline'     => 'google_places_v2',
			'total_travel' => $directions_data['total_text'] ?? '',
		);

		$response = array(
			'candidates' => $normalized,
			'itinerary'  => $itinerary,
			'directions' => $directions_data,
			'meta'       => $meta,
			'status'     => 'complete',
		);

		// Cache the response
		$this->cache->set( 'itinerary', $validated, $response, HOUR_IN_SECONDS * 24 );

		// Log analytics
		$this->log_itinerary( $validated, $response );

		error_log( 'BATP: Itinerary generation complete. Venues: ' . count( $normalized ) );

		return $response;
	}

	/**
	 * Stage 0: Guardrails - Rate limit and input validation.
	 *
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>|WP_Error
	 */
	private function stage_guardrails( array $request ) {
		// Rate limit check
		$rate_check = $this->security->enforce_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Validate required fields
		$lat = isset( $request['lat'] ) ? (float) $request['lat'] : 0;
		$lng = isset( $request['lng'] ) ? (float) $request['lng'] : 0;

		if ( 0 === $lat || 0 === $lng ) {
			// Default to central Brooklyn
			$lat = 40.6782;
			$lng = -73.9442;
		}

		// Validate coordinates are in NYC area
		if ( $lat < 40.4 || $lat > 41.0 || $lng < -74.3 || $lng > -73.5 ) {
			return new WP_Error( 'batp_invalid_location', __( 'Location must be within the New York City area.', 'brooklyn-ai-planner' ) );
		}

		$interests = isset( $request['interests'] ) && is_array( $request['interests'] )
			? array_map( 'sanitize_text_field', $request['interests'] )
			: array( 'food', 'art' );

		if ( empty( $interests ) ) {
			$interests = array( 'food', 'art' );
		}

		$time_window = isset( $request['time_window'] ) ? absint( $request['time_window'] ) : 240;
		$time_window = max( 60, min( 480, $time_window ) ); // 1-8 hours

		$budget = isset( $request['budget'] ) ? sanitize_text_field( $request['budget'] ) : 'medium';
		$mode   = isset( $request['mode'] ) ? sanitize_text_field( $request['mode'] ) : 'walking';

		$accessibility = isset( $request['accessibility_preferences'] ) && is_array( $request['accessibility_preferences'] )
			? array_map( 'sanitize_text_field', $request['accessibility_preferences'] )
			: array();

		return array(
			'lat'                       => $lat,
			'lng'                       => $lng,
			'interests'                 => $interests,
			'time_window'               => $time_window,
			'budget'                    => $budget,
			'mode'                      => $mode,
			'accessibility_preferences' => $accessibility,
		);
	}

	/**
	 * Stage 1: Query Google Places API for each interest.
	 *
	 * @param array<string, mixed> $validated
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function stage_places_search( array $validated ) {
		$all_venues = array();
		$seen_ids   = array();

		foreach ( $validated['interests'] as $interest ) {
			$places_type = $this->map_interest_to_places_type( $interest );

			error_log( "BATP: Searching Places for interest='{$interest}' → type='{$places_type}'" );

			$results = $this->places->nearby_search(
				$validated['lat'],
				$validated['lng'],
				$places_type,
				self::PLACES_RADIUS,
				true // open_now
			);

			if ( is_wp_error( $results ) ) {
				error_log( 'BATP: Places search error for ' . $interest . ': ' . $results->get_error_message() );
				continue; // Skip this interest, try others
			}

			// Dedupe and add to results
			foreach ( $results as $venue ) {
				$place_id = $venue['place_id'] ?? '';
				if ( '' === $place_id || isset( $seen_ids[ $place_id ] ) ) {
					continue;
				}

				$seen_ids[ $place_id ] = true;
				$venue['interest']     = $interest;
				$all_venues[]          = $venue;
			}
		}

		return $all_venues;
	}

	/**
	 * Stage 2: Fetch detailed information for top candidates.
	 *
	 * @param array<int, array<string, mixed>> $venues
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function stage_fetch_details( array $venues ) {
		// Sort by rating to prioritize better venues
		usort(
			$venues,
			function ( $a, $b ) {
				$rating_a = $a['rating'] ?? 0;
				$rating_b = $b['rating'] ?? 0;
				return $rating_b <=> $rating_a;
			}
		);

		// Limit to top candidates
		$top_venues = array_slice( $venues, 0, self::MAX_DETAILS_FETCH );
		$detailed   = array();

		foreach ( $top_venues as $venue ) {
			$place_id = $venue['place_id'] ?? '';
			if ( '' === $place_id ) {
				continue;
			}

			$details = $this->places->get_details( $place_id );
			if ( is_wp_error( $details ) ) {
				error_log( 'BATP: Details fetch failed for ' . $place_id );
				continue;
			}

			// Merge basic info with details
			$details['place_id'] = $place_id;
			$details['interest'] = $venue['interest'] ?? '';
			$detailed[]          = $details;
		}

		return $detailed;
	}

	/**
	 * Stage 3: Apply hard filters (hours, budget, accessibility).
	 *
	 * @param array<string, mixed>             $validated
	 * @param array<int, array<string, mixed>> $venues
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function stage_apply_filters( array $validated, array $venues ) {
		$filtered = array();

		foreach ( $venues as $venue ) {
			// Filter: Business must be operational
			$status = $venue['business_status'] ?? 'OPERATIONAL';
			if ( 'OPERATIONAL' !== $status ) {
				continue;
			}

			// Filter: Must be currently open (or no hours data)
			$is_open = $venue['opening_hours']['open_now'] ?? true;
			if ( ! $is_open ) {
				continue;
			}

			// Filter: Budget check via price_level
			if ( ! $this->passes_budget_filter( $venue, $validated['budget'] ) ) {
				continue;
			}

			// Filter: Accessibility (if wheelchair access required)
			if ( in_array( 'wheelchair', $validated['accessibility_preferences'], true ) ) {
				$wheelchair = $venue['wheelchair_accessible_entrance'] ?? null;
				if ( false === $wheelchair ) {
					continue; // Explicitly marked as not accessible
				}
			}

			$filtered[] = $venue;
		}

		// Distance filtering via Distance Matrix (if configured)
		if ( null !== $this->directions && count( $filtered ) > 0 ) {
			$filtered = $this->filter_by_travel_time( $validated, $filtered );
		}

		return $filtered;
	}

	/**
	 * Check if venue passes budget filter.
	 *
	 * @param array<string, mixed> $venue
	 * @param string               $budget
	 * @return bool
	 */
	private function passes_budget_filter( array $venue, string $budget ): bool {
		$price_level = $venue['price_level'] ?? 2; // Default to medium

		// Google price_level: 0=Free, 1=Inexpensive, 2=Moderate, 3=Expensive, 4=Very Expensive
		$budget_max = array(
			'low'    => 1,
			'medium' => 2,
			'high'   => 4,
		);

		$max_allowed = $budget_max[ $budget ] ?? 3;
		return $price_level <= $max_allowed;
	}

	/**
	 * Filter venues by travel time from user location.
	 *
	 * @param array<string, mixed>             $validated
	 * @param array<int, array<string, mixed>> $venues
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_by_travel_time( array $validated, array $venues ): array {
		// Max travel time per venue (leave time for visiting)
		$max_travel_minutes = min( 30, $validated['time_window'] / 4 );

		$user_location = array( $validated['lat'], $validated['lng'] );
		$destinations  = array();

		foreach ( $venues as $venue ) {
			$lat = $venue['geometry']['location']['lat'] ?? null;
			$lng = $venue['geometry']['location']['lng'] ?? null;
			if ( $lat && $lng ) {
				$destinations[] = array( $lat, $lng );
			}
		}

		if ( empty( $destinations ) ) {
			return $venues;
		}

		$matrix = $this->directions->distance_matrix(
			array( $user_location ),
			$destinations,
			$validated['mode']
		);

		if ( is_wp_error( $matrix ) ) {
			return $venues; // Can't filter, return all
		}

		$filtered = array();
		$elements = $matrix[0]['elements'] ?? array();

		foreach ( $venues as $index => $venue ) {
			$element = $elements[ $index ] ?? null;
			if ( ! $element || 'OK' !== ( $element['status'] ?? '' ) ) {
				$filtered[] = $venue; // Can't determine, include it
				continue;
			}

			$duration_minutes = ( $element['duration']['value'] ?? 0 ) / 60;
			if ( $duration_minutes <= $max_travel_minutes ) {
				$venue['travel_minutes'] = round( $duration_minutes );
				$venue['travel_text']    = $element['duration']['text'] ?? '';
				$filtered[]              = $venue;
			}
		}

		return $filtered;
	}

	/**
	 * Stage 4: LLM ordering via Gemini (optional).
	 *
	 * @param array<string, mixed>             $validated
	 * @param array<int, array<string, mixed>> $venues
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function stage_llm_ordering( array $validated, array $venues ) {
		// Skip LLM if not configured or few venues
		if ( null === $this->gemini || count( $venues ) <= 3 ) {
			return $this->simple_sort_by_rating( $venues );
		}

		// Limit candidates for LLM
		$candidates = array_slice( $venues, 0, self::LLM_MAX_CANDIDATES );

		// Build LLM prompt
		$prompt_text = $this->build_ordering_prompt( $validated, $candidates );

		// Format for Gemini API
		$payload = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt_text ),
					),
				),
			),
		);

		$response = $this->gemini->generate_content( $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse LLM response and reorder
		$ordered = $this->parse_llm_ordering( $response, $candidates );

		return $ordered;
	}

	/**
	 * Build LLM prompt for venue ordering.
	 *
	 * @param array<string, mixed>             $validated
	 * @param array<int, array<string, mixed>> $candidates
	 * @return string
	 */
	private function build_ordering_prompt( array $validated, array $candidates ): string {
		$venue_list = '';
		foreach ( $candidates as $i => $venue ) {
			$name    = $venue['name'] ?? 'Unknown';
			$types   = implode( ', ', array_slice( $venue['types'] ?? array(), 0, 3 ) );
			$rating  = $venue['rating'] ?? 'N/A';
			$address = $venue['formatted_address'] ?? '';

			$venue_list .= sprintf( "%d. %s (%s) - Rating: %s - %s\n", $i + 1, $name, $types, $rating, $address );
		}

		$interests_str = implode( ', ', $validated['interests'] );
		$time_str      = $validated['time_window'] . ' minutes';

		return <<<PROMPT
You are a Brooklyn trip planner. Given these venues, select the best 3-5 for a {$time_str} itinerary focused on: {$interests_str}.

Order them by optimal visiting sequence (consider variety, flow, and proximity).

Venues:
{$venue_list}

Respond with ONLY a JSON array of venue numbers in optimal order, e.g.: [3, 1, 5, 2]
PROMPT;
	}

	/**
	 * Parse LLM ordering response.
	 *
	 * @param array<string, mixed>             $response
	 * @param array<int, array<string, mixed>> $candidates
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_llm_ordering( array $response, array $candidates ): array {
		$text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

		// Extract JSON array from response
		if ( preg_match( '/\[[\d,\s]+\]/', $text, $matches ) ) {
			$order = json_decode( $matches[0], true );
			if ( is_array( $order ) ) {
				$ordered = array();
				foreach ( $order as $index ) {
					$idx = (int) $index - 1; // Convert to 0-indexed
					if ( isset( $candidates[ $idx ] ) ) {
						$ordered[] = $candidates[ $idx ];
					}
				}
				if ( ! empty( $ordered ) ) {
					return $ordered;
				}
			}
		}

		// Fallback to rating sort
		return $this->simple_sort_by_rating( $candidates );
	}

	/**
	 * Simple sort by rating (fallback).
	 *
	 * @param array<int, array<string, mixed>> $venues
	 * @return array<int, array<string, mixed>>
	 */
	private function simple_sort_by_rating( array $venues ): array {
		usort(
			$venues,
			function ( $a, $b ) {
				$rating_a = $a['rating'] ?? 0;
				$rating_b = $b['rating'] ?? 0;
				return $rating_b <=> $rating_a;
			}
		);
		return array_slice( $venues, 0, 5 ); // Max 5 venues
	}

	/**
	 * Stage 5: Get multi-stop directions.
	 *
	 * @param array<string, mixed>             $validated
	 * @param array<int, array<string, mixed>> $venues
	 * @return array<string, mixed>|WP_Error
	 */
	private function stage_get_directions( array $validated, array $venues ) {
		if ( null === $this->directions || empty( $venues ) ) {
			return new WP_Error( 'batp_no_directions', __( 'Directions not available.', 'brooklyn-ai-planner' ) );
		}

		$origin    = array( $validated['lat'], $validated['lng'] );
		$waypoints = array();

		foreach ( $venues as $venue ) {
			$lat = $venue['geometry']['location']['lat'] ?? null;
			$lng = $venue['geometry']['location']['lng'] ?? null;
			if ( $lat && $lng ) {
				$waypoints[] = array( $lat, $lng );
			}
		}

		if ( empty( $waypoints ) ) {
			return new WP_Error( 'batp_no_waypoints', __( 'No valid venue coordinates.', 'brooklyn-ai-planner' ) );
		}

		return $this->directions->get_multi_stop_route( $origin, $waypoints, $validated['mode'] );
	}

	/**
	 * Normalize venues for frontend response.
	 *
	 * @param array<int, array<string, mixed>> $venues
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_venues_for_response( array $venues ): array {
		$normalized = array();

		foreach ( $venues as $index => $venue ) {
			$photos = array();
			if ( isset( $venue['photos'] ) && is_array( $venue['photos'] ) ) {
				foreach ( array_slice( $venue['photos'], 0, 3 ) as $photo ) {
					$ref = $photo['photo_reference'] ?? '';
					if ( '' !== $ref ) {
						$photos[] = $this->places->get_photo_url( $ref, 400 );
					}
				}
			}

			$lat = $venue['geometry']['location']['lat'] ?? 0;
			$lng = $venue['geometry']['location']['lng'] ?? 0;

			$normalized[] = array(
				'slug'     => $venue['place_id'] ?? '',
				'place_id' => $venue['place_id'] ?? '',
				'data'     => array(
					'id'           => $venue['place_id'] ?? '',
					'name'         => $venue['name'] ?? '',
					'address'      => $venue['formatted_address'] ?? '',
					'latitude'     => $lat,
					'longitude'    => $lng,
					'phone'        => $venue['formatted_phone_number'] ?? '',
					'website'      => $venue['website'] ?? '',
					'hours'        => $this->format_hours( $venue['opening_hours'] ?? array() ),
					'rating'       => $venue['rating'] ?? 0,
					'price_level'  => $venue['price_level'] ?? 2,
					'types'        => $venue['types'] ?? array(),
					'photos'       => $photos,
					'vibe_summary' => $this->generate_vibe_summary( $venue ),
				),
				'score'    => $venue['rating'] ?? 0,
				'sources'  => array( 'google_places' ),
				'order'    => $index + 1,
			);
		}

		return $normalized;
	}

	/**
	 * Format opening hours for display.
	 *
	 * @param array<string, mixed> $hours_data
	 * @return array<string, string>|null
	 */
	private function format_hours( array $hours_data ): ?array {
		if ( empty( $hours_data['weekday_text'] ) ) {
			return null;
		}

		$formatted = array();
		foreach ( $hours_data['weekday_text'] as $day_text ) {
			// Parse "Monday: 9:00 AM – 5:00 PM"
			if ( preg_match( '/^(\w+):\s*(.+)$/', $day_text, $matches ) ) {
				$formatted[ $matches[1] ] = $matches[2];
			}
		}

		return $formatted;
	}

	/**
	 * Generate a vibe summary from venue types.
	 *
	 * @param array<string, mixed> $venue
	 * @return string
	 */
	private function generate_vibe_summary( array $venue ): string {
		$name  = $venue['name'] ?? '';
		$types = $venue['types'] ?? array();

		// Map types to descriptive phrases
		$type_phrases = array(
			'restaurant'         => 'dining spot',
			'cafe'               => 'cozy café',
			'bar'                => 'local bar',
			'night_club'         => 'nightlife venue',
			'museum'             => 'cultural attraction',
			'art_gallery'        => 'art space',
			'park'               => 'outdoor space',
			'tourist_attraction' => 'must-see attraction',
		);

		$phrase = 'local spot';
		foreach ( $types as $type ) {
			if ( isset( $type_phrases[ $type ] ) ) {
				$phrase = $type_phrases[ $type ];
				break;
			}
		}

		$rating      = $venue['rating'] ?? 0;
		$rating_text = $rating >= 4.5 ? 'highly-rated' : ( $rating >= 4.0 ? 'popular' : '' );

		return trim( sprintf( '%s %s in Brooklyn.', $rating_text, $phrase ) );
	}

	/**
	 * Build itinerary structure.
	 *
	 * @param array<int, array<string, mixed>> $venues
	 * @param array<string, mixed>             $directions
	 * @return array<string, mixed>
	 */
	private function build_itinerary( array $venues, array $directions ): array {
		$items = array();

		foreach ( $venues as $index => $venue ) {
			$items[] = array(
				'slug'             => $venue['slug'],
				'title'            => $venue['data']['name'] ?? '',
				'order'            => $index + 1,
				'arrival_minute'   => 0, // Could calculate from directions
				'duration_minutes' => 45, // Default visit time
				'notes'            => $venue['data']['vibe_summary'] ?? '',
			);
		}

		return array(
			'items' => $items,
			'meta'  => array(
				'venue_count'  => count( $items ),
				'maps_url'     => $directions['overview_url'] ?? '#',
				'total_travel' => $directions['total_text'] ?? '',
			),
		);
	}

	/**
	 * Map user interest to Google Places type.
	 *
	 * @param string $interest
	 * @return string
	 */
	private function map_interest_to_places_type( string $interest ): string {
		$key = strtolower( trim( $interest ) );
		return self::INTEREST_TYPE_MAP[ $key ] ?? 'point_of_interest';
	}

	/**
	 * Log itinerary to analytics.
	 *
	 * @param array<string, mixed> $request
	 * @param array<string, mixed> $response
	 */
	private function log_itinerary( array $request, array $response ): void {
		$this->analytics->log(
			'itinerary_generated',
			array(
				'metadata' => array(
					'interests'   => $request['interests'] ?? array(),
					'venue_count' => count( $response['candidates'] ?? array() ),
					'lat'         => $request['lat'] ?? 0,
					'lng'         => $request['lng'] ?? 0,
					'pipeline'    => 'google_places_v2',
				),
			)
		);
	}
}
