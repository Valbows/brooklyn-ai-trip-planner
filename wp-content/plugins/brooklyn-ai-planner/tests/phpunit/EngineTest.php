<?php
/**
 * Tests for Engine with Google Places API pipeline.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Tests;

use BrooklynAI\Engine;
use BrooklynAI\Security_Manager;
use BrooklynAI\Cache_Service;
use BrooklynAI\Analytics_Logger;
use BrooklynAI\Clients\Supabase_Client;
use BrooklynAI\Clients\Google_Places_Client;
use BrooklynAI\Clients\Google_Directions_Client;
use BrooklynAI\Clients\GoogleMaps_Client;
use BrooklynAI\Clients\Gemini_Client;
use PHPUnit\Framework\TestCase;
use WP_Error;

class EngineTest extends TestCase {
	private $security;
	private $cache;
	private $supabase;
	private $places;
	private $directions;
	private $maps;
	private $gemini;
	private $analytics;
	private $engine;
	private $gemini_response;

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		// Create mocks
		$this->security   = $this->createMock( Security_Manager::class );
		$this->cache      = $this->createMock( Cache_Service::class );
		$this->supabase   = $this->createMock( Supabase_Client::class );
		$this->places     = $this->createMock( Google_Places_Client::class );
		$this->directions = $this->createMock( Google_Directions_Client::class );
		$this->maps       = $this->createMock( GoogleMaps_Client::class );
		$this->gemini     = $this->createMock( Gemini_Client::class );
		$this->analytics  = $this->createMock( Analytics_Logger::class );

		// Default: security passes, no cache
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false );
		// cache->set() returns void, no need to mock return value
		$this->analytics->method( 'log' )->willReturn( true );

		// Setup default gemini response
		$this->gemini_response = $this->mock_llm_payload();
		$this->gemini->method( 'generate_content' )
			->willReturnCallback( fn() => $this->gemini_response );

		// Build engine with all dependencies
		$this->engine = new Engine(
			$this->security,
			$this->cache,
			$this->supabase,
			$this->places,
			$this->directions,
			$this->maps,
			$this->gemini,
			$this->analytics
		);
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	// =========================================================================
	// STAGE 0: GUARDRAILS TESTS
	// =========================================================================

	public function test_guardrails_rate_limit_exceeded() {
		$this->security = $this->createMock( Security_Manager::class );
		$this->security->method( 'enforce_rate_limit' )
			->willReturn( new WP_Error( 'batp_rate_limited', 'Rate limit exceeded' ) );

		$engine = new Engine(
			$this->security,
			$this->cache,
			$this->supabase,
			$this->places,
			$this->directions,
			$this->maps,
			$this->gemini,
			$this->analytics
		);

		$result = $engine->generate_itinerary( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_rate_limited', $result->get_error_code() );
	}

	public function test_guardrails_invalid_location_outside_nyc() {
		$result = $this->engine->generate_itinerary(
			array(
				'lat' => 34.0522, // Los Angeles
				'lng' => -118.2437,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_invalid_location', $result->get_error_code() );
	}

	public function test_guardrails_defaults_to_brooklyn_when_no_location() {
		// Setup places to return venues
		$this->places->method( 'nearby_search' )->willReturn( $this->mock_places_results() );
		$this->places->method( 'get_details' )->willReturn( $this->mock_place_details() );
		$this->directions->method( 'get_multi_stop_route' )->willReturn( $this->mock_directions() );
		$this->directions->method( 'distance_matrix' )->willReturn( array() );
		$this->directions->method( 'build_maps_url' )->willReturn( 'https://maps.google.com' );

		$result = $this->engine->generate_itinerary(
			array(
				'interests' => array( 'food' ),
			)
		);

		// Should not error - uses default Brooklyn location
		$this->assertIsArray( $result );
		$this->assertEquals( 'complete', $result['status'] );
	}

	// =========================================================================
	// PLACES API CONFIGURATION TESTS
	// =========================================================================

	public function test_places_not_configured_returns_error() {
		$engine = new Engine(
			$this->security,
			$this->cache,
			$this->supabase,
			null, // No places client
			$this->directions,
			$this->maps,
			$this->gemini,
			$this->analytics
		);

		$result = $engine->generate_itinerary( array( 'interests' => array( 'food' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_places_not_configured', $result->get_error_code() );
	}

	// =========================================================================
	// STAGE 1: PLACES SEARCH TESTS
	// =========================================================================

	public function test_places_search_returns_venues() {
		$this->places->method( 'nearby_search' )->willReturn( $this->mock_places_results( 5 ) );
		$this->places->method( 'get_details' )->willReturn( $this->mock_place_details() );
		$this->directions->method( 'get_multi_stop_route' )->willReturn( $this->mock_directions() );
		$this->directions->method( 'distance_matrix' )->willReturn( array() );
		$this->directions->method( 'build_maps_url' )->willReturn( 'https://maps.google.com' );

		$result = $this->engine->generate_itinerary(
			array(
				'lat'       => 40.6782,
				'lng'       => -73.9442,
				'interests' => array( 'food' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'candidates', $result );
		$this->assertGreaterThan( 0, count( $result['candidates'] ) );
	}

	public function test_places_search_no_results_returns_error() {
		$this->places->method( 'nearby_search' )->willReturn( array() );

		$result = $this->engine->generate_itinerary(
			array(
				'lat'       => 40.6782,
				'lng'       => -73.9442,
				'interests' => array( 'food' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_no_venues', $result->get_error_code() );
	}

	public function test_places_search_api_error_continues_to_next_interest() {
		// First interest errors, second succeeds
		$this->places->method( 'nearby_search' )
			->willReturnOnConsecutiveCalls(
				new WP_Error( 'api_error', 'First failed' ),
				$this->mock_places_results( 3 )
			);
		$this->places->method( 'get_details' )->willReturn( $this->mock_place_details() );
		$this->directions->method( 'get_multi_stop_route' )->willReturn( $this->mock_directions() );
		$this->directions->method( 'distance_matrix' )->willReturn( array() );
		$this->directions->method( 'build_maps_url' )->willReturn( 'https://maps.google.com' );

		$result = $this->engine->generate_itinerary(
			array(
				'interests' => array( 'food', 'drinks' ),
			)
		);

		// Should succeed with venues from second interest
		$this->assertIsArray( $result );
		$this->assertEquals( 'complete', $result['status'] );
	}

	// =========================================================================
	// STAGE 3: FILTER TESTS
	// =========================================================================

	public function test_budget_filter_low_excludes_expensive() {
		// Return venues with different price levels
		$venues = array(
			$this->create_place_result( 'cheap-place', 'Cheap Place', 1 ),
			$this->create_place_result( 'expensive-place', 'Expensive Place', 4 ),
		);
		$this->places->method( 'nearby_search' )->willReturn( $venues );
		$this->places->method( 'get_details' )
			->willReturnOnConsecutiveCalls(
				$this->mock_place_details( 'cheap-place', 1 ),
				$this->mock_place_details( 'expensive-place', 4 )
			);
		$this->directions->method( 'get_multi_stop_route' )->willReturn( $this->mock_directions() );
		$this->directions->method( 'distance_matrix' )->willReturn( array() );
		$this->directions->method( 'build_maps_url' )->willReturn( 'https://maps.google.com' );

		$result = $this->engine->generate_itinerary(
			array(
				'budget'    => 'low',
				'interests' => array( 'food' ),
			)
		);

		$this->assertIsArray( $result );
		// Only cheap venue should remain after filtering
		$this->assertCount( 1, $result['candidates'] );
	}

	public function test_filter_excludes_closed_venues() {
		$venues = array(
			$this->create_place_result( 'open-place', 'Open Place' ),
		);
		$this->places->method( 'nearby_search' )->willReturn( $venues );

		// Return details showing venue is closed
		$details                  = $this->mock_place_details( 'open-place' );
		$details['opening_hours'] = array( 'open_now' => false );
		$this->places->method( 'get_details' )->willReturn( $details );

		$result = $this->engine->generate_itinerary(
			array( 'interests' => array( 'food' ) )
		);

		// Should fail as no venues pass filter
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_no_venues_after_filter', $result->get_error_code() );
	}

	// =========================================================================
	// STAGE 4: LLM ORDERING TESTS
	// =========================================================================

	public function test_llm_ordering_skipped_for_three_or_fewer_venues() {
		$venues = $this->mock_places_results( 3 );
		$this->places->method( 'nearby_search' )->willReturn( $venues );
		$this->places->method( 'get_details' )->willReturn( $this->mock_place_details() );
		$this->directions->method( 'get_multi_stop_route' )->willReturn( $this->mock_directions() );
		$this->directions->method( 'distance_matrix' )->willReturn( array() );
		$this->directions->method( 'build_maps_url' )->willReturn( 'https://maps.google.com' );

		// LLM should never be called
		$this->gemini->expects( $this->never() )->method( 'generate_content' );

		$result = $this->engine->generate_itinerary(
			array( 'interests' => array( 'food' ) )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'complete', $result['status'] );
	}

	public function test_llm_ordering_error_is_non_fatal() {
		$venues = $this->mock_places_results( 5 );
		$this->places->method( 'nearby_search' )->willReturn( $venues );
		$this->places->method( 'get_details' )->willReturn( $this->mock_place_details() );
		$this->directions->method( 'get_multi_stop_route' )->willReturn( $this->mock_directions() );
		$this->directions->method( 'distance_matrix' )->willReturn( array() );
		$this->directions->method( 'build_maps_url' )->willReturn( 'https://maps.google.com' );

		// LLM returns error
		$this->gemini_response = new WP_Error( 'llm_error', 'LLM failed' );

		$result = $this->engine->generate_itinerary(
			array( 'interests' => array( 'food' ) )
		);

		// Should still succeed using fallback sort
		$this->assertIsArray( $result );
		$this->assertEquals( 'complete', $result['status'] );
	}

	// =========================================================================
	// STAGE 5: DIRECTIONS TESTS
	// =========================================================================

	public function test_directions_failure_is_non_fatal() {
		$venues = $this->mock_places_results( 3 );
		$this->places->method( 'nearby_search' )->willReturn( $venues );
		$this->places->method( 'get_details' )->willReturn( $this->mock_place_details() );

		// Directions fails
		$this->directions->method( 'get_multi_stop_route' )
			->willReturn( new WP_Error( 'directions_error', 'No route' ) );
		$this->directions->method( 'distance_matrix' )->willReturn( array() );
		$this->directions->method( 'build_maps_url' )->willReturn( '#' );

		$result = $this->engine->generate_itinerary(
			array( 'interests' => array( 'food' ) )
		);

		// Should still succeed without directions
		$this->assertIsArray( $result );
		$this->assertEquals( 'complete', $result['status'] );
		$this->assertEquals( '', $result['directions']['polyline'] );
	}

	// =========================================================================
	// CACHE TESTS
	// =========================================================================

	public function test_cache_hit_returns_cached_response() {
		$cached_response = array(
			'candidates' => array(),
			'itinerary'  => array( 'items' => array() ),
			'directions' => array(),
			'meta'       => array(),
			'status'     => 'complete',
		);

		$this->cache = $this->createMock( Cache_Service::class );
		$this->cache->method( 'get' )->willReturn( $cached_response );

		$engine = new Engine(
			$this->security,
			$this->cache,
			$this->supabase,
			$this->places,
			$this->directions,
			$this->maps,
			$this->gemini,
			$this->analytics
		);

		// Places should never be called
		$this->places->expects( $this->never() )->method( 'nearby_search' );

		$result = $engine->generate_itinerary( array( 'interests' => array( 'food' ) ) );

		$this->assertEquals( $cached_response, $result );
	}

	// =========================================================================
	// FULL PIPELINE TEST
	// =========================================================================

	public function test_full_pipeline_success() {
		$venues = $this->mock_places_results( 4 );
		$this->places->method( 'nearby_search' )->willReturn( $venues );
		$this->places->method( 'get_details' )->willReturn( $this->mock_place_details() );
		$this->directions->method( 'get_multi_stop_route' )->willReturn( $this->mock_directions() );
		$this->directions->method( 'distance_matrix' )->willReturn( array() );
		$this->directions->method( 'build_maps_url' )->willReturn( 'https://maps.google.com/dir/...' );

		$result = $this->engine->generate_itinerary(
			array(
				'lat'         => 40.7081,
				'lng'         => -73.9571,
				'interests'   => array( 'food', 'drinks' ),
				'budget'      => 'medium',
				'time_window' => 180,
			)
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'complete', $result['status'] );
		$this->assertArrayHasKey( 'candidates', $result );
		$this->assertArrayHasKey( 'itinerary', $result );
		$this->assertArrayHasKey( 'directions', $result );
		$this->assertArrayHasKey( 'meta', $result );
		$this->assertEquals( 'google_places_v2', $result['meta']['pipeline'] );
	}

	// =========================================================================
	// HELPER METHODS
	// =========================================================================

	private function mock_places_results( int $count = 3 ): array {
		$results = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$results[] = $this->create_place_result( "place-id-{$i}", "Venue {$i}" );
		}
		return $results;
	}

	private function create_place_result( string $place_id, string $name, int $price_level = 2 ): array {
		return array(
			'place_id'        => $place_id,
			'name'            => $name,
			'rating'          => 4.0 + ( rand( 0, 10 ) / 10 ),
			'price_level'     => $price_level,
			'vicinity'        => '123 Test St, Brooklyn, NY',
			'geometry'        => array(
				'location' => array(
					'lat' => 40.6782 + ( rand( -100, 100 ) / 10000 ),
					'lng' => -73.9442 + ( rand( -100, 100 ) / 10000 ),
				),
			),
			'opening_hours'   => array( 'open_now' => true ),
			'business_status' => 'OPERATIONAL',
		);
	}

	private function mock_place_details( string $place_id = 'test-place', int $price_level = 2 ): array {
		return array(
			'place_id'                       => $place_id,
			'name'                           => 'Test Venue',
			'formatted_address'              => '123 Test St, Brooklyn, NY 11201',
			'formatted_phone_number'         => '(555) 123-4567',
			'website'                        => 'https://example.com',
			'rating'                         => 4.5,
			'price_level'                    => $price_level,
			'opening_hours'                  => array( 'open_now' => true ),
			'business_status'                => 'OPERATIONAL',
			'geometry'                       => array(
				'location' => array(
					'lat' => 40.6782,
					'lng' => -73.9442,
				),
			),
			'wheelchair_accessible_entrance' => true,
			'types'                          => array( 'restaurant', 'food' ),
		);
	}

	private function mock_directions(): array {
		return array(
			'polyline'     => 'encodedPolylineString',
			'legs'         => array(
				array(
					'duration' => array(
						'text'  => '10 mins',
						'value' => 600,
					),
					'distance' => array(
						'text'  => '0.5 mi',
						'value' => 800,
					),
				),
			),
			'total_text'   => '10 mins',
			'overview_url' => 'https://maps.google.com',
		);
	}

	private function mock_llm_payload( array $items = array() ): array {
		$default_items = array(
			array(
				'slug'             => 'place-1',
				'title'            => 'First Stop',
				'order'            => 1,
				'arrival_minute'   => 0,
				'duration_minutes' => 60,
				'notes'            => 'Start here',
			),
		);
		$payload       = array(
			'meta'  => array( 'summary' => 'Test itinerary' ),
			'items' => ! empty( $items ) ? $items : $default_items,
		);

		return array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array(
							array( 'text' => json_encode( $payload ) ),
						),
					),
				),
			),
		);
	}
}
