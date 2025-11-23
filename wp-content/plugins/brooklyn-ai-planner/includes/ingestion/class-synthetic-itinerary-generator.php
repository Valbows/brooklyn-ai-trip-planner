<?php
/**
 * Synthetic itinerary generator leveraging Gemini + Supabase.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Ingestion;

use BrooklynAI\Clients\Gemini_Client;
use BrooklynAI\Clients\Supabase_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Synthetic_Itinerary_Generator {
	private const DRY_RUN_SALT = 'batp_synthetic_dry_run';

	private Supabase_Client $supabase;
	private Gemini_Client $gemini;

	public function __construct( Supabase_Client $supabase, Gemini_Client $gemini ) {
		$this->supabase = $supabase;
		$this->gemini   = $gemini;
	}

	/**
	 * Generates synthetic itineraries and stores them in Supabase.
	 *
	 * @param int                         $count  Number of itineraries to create.
	 * @param array<string, mixed>        $options
	 * @return array<string, int>|WP_Error
	 */
	public function generate( int $count, array $options = array() ) {
		$defaults = array(
			'dry_run'      => false,
			'boroughs'     => array( 'Brooklyn', 'Manhattan', 'Queens', 'Bronx', 'Staten Island' ),
			'interests'    => array( 'food', 'art', 'history', 'music', 'outdoors', 'nightlife' ),
			'party_min'    => 1,
			'party_max'    => 6,
			'duration_min' => 1,
			'duration_max' => 4,
		);
		$options  = array_merge( $defaults, $options );

		$stats = array(
			'attempted' => 0,
			'success'   => 0,
			'failed'    => 0,
		);

		for ( $i = 0; $i < $count; $i++ ) {
			++$stats['attempted'];
			$sequence = $options['dry_run'] ? $i : null;
			$context  = $this->build_context( $options, $sequence );
			$response = $options['dry_run'] ? $this->mock_itinerary( $context ) : $this->request_itinerary( $context );

			if ( is_wp_error( $response ) ) {
				++$stats['failed'];
				continue;
			}

			$payload = $this->build_payload( $context, $response );

			if ( $options['dry_run'] ) {
				++$stats['success'];
				continue;
			}

			$result = $this->supabase->insert( 'itinerary_transactions', $payload );
			if ( is_wp_error( $result ) ) {
				++$stats['failed'];
				continue;
			}

			++$stats['success'];
		}

		return $stats;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>|WP_Error
	 */
	private function request_itinerary( array $context ) {
		$prompt = sprintf(
			'You are a Brooklyn travel planner. Create a JSON itinerary for a %d-person party visiting %s focused on %s for %d days. Include per-day morning/afternoon/evening suggestions with venue names, borough, vibe, and estimated cost. Respond ONLY with JSON matching this schema: {"meta":{...},"days":[{"day":1,"segments":[{"slot":"morning","title":"...","summary":"...","borough":"...","estimated_cost":"$$"}]}]}. Do not use markdown fences.',
			$context['party_size'],
			$context['borough'],
			implode( ', ', $context['interests'] ),
			$context['duration_days']
		);

		$payload = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array(
							'text' => $prompt,
						),
					),
				),
			),
			'generationConfig' => array(
				'maxOutputTokens' => 1024,
				'temperature'     => 0.6,
			),
		);

		$response = $this->gemini->generate_content( $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->extract_text( $response );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$decoded = json_decode( $text, true );
		if ( null === $decoded || ! is_array( $decoded ) ) {
			return new WP_Error( 'batp_itinerary_invalid_json', __( 'Gemini response was not valid JSON.', 'brooklyn-ai-planner' ) );
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private function mock_itinerary( array $context ): array {
		$days = array();
		for ( $i = 1; $i <= $context['duration_days']; $i++ ) {
			$days[] = array(
				'day'      => $i,
				'segments' => array(
					array(
						'slot'           => 'morning',
						'title'          => 'Coffee + Stroll',
						'summary'        => 'Kick off with neighborhood coffee and a walk.',
						'borough'        => $context['borough'],
						'estimated_cost' => '$',
					),
					array(
						'slot'           => 'afternoon',
						'title'          => 'Museum Visit',
						'summary'        => 'Explore a local museum aligned with interests.',
						'borough'        => $context['borough'],
						'estimated_cost' => '$$',
					),
					array(
						'slot'           => 'evening',
						'title'          => 'Dinner + Live Music',
						'summary'        => 'Wrap with food and a show.',
						'borough'        => $context['borough'],
						'estimated_cost' => '$$$',
					),
				),
			);
		}

		return array(
			'meta' => array(
				'borough'       => $context['borough'],
				'interests'     => $context['interests'],
				'party_size'    => $context['party_size'],
				'duration_days' => $context['duration_days'],
			),
			'days' => $days,
		);
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $itinerary
	 * @return array<string, mixed>
	 */
	private function build_payload( array $context, array $itinerary ): array {
		$start_date = gmdate( 'Y-m-d', $context['start_timestamp'] );
		$end_date   = gmdate( 'Y-m-d', $context['start_timestamp'] + ( $context['duration_days'] - 1 ) * DAY_IN_SECONDS );

		return array(
			'session_hash'   => $this->hash_session( $context['session_seed'] ),
			'itinerary'      => wp_json_encode( $itinerary ),
			'borough'        => $context['borough'],
			'interests'      => $this->format_pg_array( $context['interests'] ),
			'party_size'     => $context['party_size'],
			'duration_days'  => $context['duration_days'],
			'start_date'     => $start_date,
			'end_date'       => $end_date,
			'budget_low'     => $context['budget_low'],
			'budget_high'    => $context['budget_high'],
			'transport_mode' => $context['transport_mode'],
			'generated_by'   => 'gemini-2.0-flash',
			'source'         => 'synthetic',
		);
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function build_context( array $options, ?int $sequence = null ): array {
		$borough      = $this->pick_pool_value( (array) $options['boroughs'], $sequence, 'borough' );
		$interests    = $this->pick_interests( (array) $options['interests'], $sequence );
		$party        = $this->pick_int( (int) $options['party_min'], (int) $options['party_max'], $sequence, 'party' );
		$duration     = $this->pick_int( (int) $options['duration_min'], (int) $options['duration_max'], $sequence, 'duration' );
		$budget_low   = $this->pick_int( 50, 200, $sequence, 'budget_low' );
		$budget_high  = $budget_low + $this->pick_int( 100, 500, $sequence, 'budget_high_offset' );
		$transport    = $this->pick_pool_value( array( 'subway', 'rideshare', 'bike', 'walking' ), $sequence, 'transport' );
		$start_base   = null === $sequence ? time() : strtotime( '2025-01-01 00:00:00' );
		$start_ts     = $start_base + $this->pick_int( 3, 45, $sequence, 'start_offset_days' ) * DAY_IN_SECONDS;
		$session_seed = null === $sequence ? uniqid( 'synthetic_', true ) : sprintf( 'synthetic_dry_run_%d', $sequence );

		return array(
			'borough'         => $borough,
			'interests'       => $interests,
			'party_size'      => $party,
			'duration_days'   => $duration,
			'budget_low'      => $budget_low,
			'budget_high'     => $budget_high,
			'transport_mode'  => $transport,
			'start_timestamp' => $start_ts,
			'session_seed'    => $session_seed,
		);
	}

	/**
	 * @param array<string, mixed> $response
	 * @return string|WP_Error
	 */
	private function extract_text( array $response ) {
		if ( empty( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'batp_gemini_missing_text', __( 'Gemini response missing text.', 'brooklyn-ai-planner' ) );
		}

		$text = (string) $response['candidates'][0]['content']['parts'][0]['text'];
		$text = trim( $text );

		if ( str_starts_with( $text, '```' ) ) {
			$text = preg_replace( '/^```(?:json)?/i', '', $text );
			$text = preg_replace( '/```$/', '', $text );
		}

		return trim( $text );
	}

	/**
	 * @param array<int, string> $values
	 */
	private function format_pg_array( array $values ): string {
		if ( empty( $values ) ) {
			return '{}';
		}

		$escaped = array_map(
			static function ( string $value ): string {
				$value = str_replace( '"', '\\"', $value );
				return '"' . $value . '"';
			},
			array_values( $values )
		);

		return '{' . implode( ',', $escaped ) . '}';
	}

	private function pick_pool_value( array $pool, ?int $sequence, string $label ): string {
		if ( empty( $pool ) ) {
			return '';
		}

		if ( null === $sequence ) {
			return (string) $pool[ array_rand( $pool ) ];
		}

		$index = $this->deterministic_index( count( $pool ), $sequence, $label );
		return (string) $pool[ $index ];
	}

	/**
	 * @param array<int, string> $pool
	 * @return array<int, string>
	 */
	private function pick_interests( array $pool, ?int $sequence ): array {
		if ( empty( $pool ) ) {
			return array();
		}

		if ( null === $sequence ) {
			shuffle( $pool );
			$take = max( 1, min( 3, count( $pool ) ) );
			return array_slice( $pool, 0, $take );
		}

		sort( $pool );
		$take    = $this->pick_int( 1, min( 3, count( $pool ) ), $sequence, 'interests_take' );
		$offset  = $this->deterministic_index( count( $pool ), $sequence, 'interests_offset' );
		$rotated = array_merge( array_slice( $pool, $offset ), array_slice( $pool, 0, $offset ) );

		return array_slice( $rotated, 0, $take );
	}

	private function pick_int( int $min, int $max, ?int $sequence, string $label ): int {
		if ( $min >= $max ) {
			return $min;
		}

		if ( null === $sequence ) {
			return wp_rand( $min, $max );
		}

		$range = $max - $min + 1;
		$hash  = $this->deterministic_hash( $sequence, $label );
		$value = hexdec( substr( $hash, 0, 8 ) ) % $range;
		return $min + $value;
	}

	private function deterministic_index( int $count, int $sequence, string $label ): int {
		if ( $count <= 1 ) {
			return 0;
		}

		$hash = $this->deterministic_hash( $sequence, $label );
		return hexdec( substr( $hash, 0, 8 ) ) % $count;
	}

	private function deterministic_hash( int $sequence, string $label ): string {
		return hash( 'sha256', self::DRY_RUN_SALT . '|' . $sequence . '|' . $label );
	}

	private function hash_session( string $seed ): string {
		return hash_hmac( 'sha256', $seed, wp_salt( 'auth' ) );
	}
}
