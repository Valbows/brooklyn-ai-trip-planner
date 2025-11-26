<?php
/**
 * Tests for Engine.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Tests;

use BrooklynAI\Engine;
use BrooklynAI\Security_Manager;
use BrooklynAI\Cache_Service;
use BrooklynAI\Analytics_Logger;
use BrooklynAI\Clients\Pinecone_Client;
use BrooklynAI\Clients\Supabase_Client;
use BrooklynAI\Clients\GoogleMaps_Client;
use BrooklynAI\Clients\Gemini_Client;
use PHPUnit\Framework\TestCase;
use WP_Error;

class EngineTest extends TestCase {
	private $security;
	private $cache;
	private $pinecone;
	private $supabase;
	private $maps;
	private $gemini;
	private $analytics;
	private $engine;
	private $gemini_response;

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->security        = $this->createMock( Security_Manager::class );
		$this->cache           = $this->createMock( Cache_Service::class );
		$this->pinecone        = $this->createMock( Pinecone_Client::class );
		$this->supabase        = $this->createMock( Supabase_Client::class );
		$this->maps            = $this->createMock( GoogleMaps_Client::class );
		$this->gemini          = $this->createMock( Gemini_Client::class );
		$this->analytics       = $this->createMock( Analytics_Logger::class );
		$this->gemini_response = $this->mock_llm_payload();
		$this->gemini->method( 'generate_content' )
			->willReturnCallback(
				function () {
					return $this->gemini_response;
				}
			);

		$this->engine = new Engine(
			$this->security,
			$this->cache,
			$this->pinecone,
			$this->supabase,
			$this->maps,
			$this->gemini,
			$this->analytics
		);
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_guardrails_invalid_nonce() {
		// Mock WP nonce check failure
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )
			->with( 'bad_token', 'batp_generate_itinerary' )
			->andReturn( false );

		$result = $this->engine->generate_itinerary( array( 'nonce' => 'bad_token' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_invalid_nonce', $result->get_error_code() );
	}

	public function test_guardrails_rate_limit_exceeded() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );

		$this->security->method( 'enforce_rate_limit' )->willReturn( new WP_Error( 'batp_rate_limited', 'Limit exceeded' ) );

		$result = $this->engine->generate_itinerary( array( 'nonce' => 'good_token' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_rate_limited', $result->get_error_code() );
	}

	public function test_kmeans_lookup_success() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		\Brain\Monkey\Functions\expect( 'wp_json_encode' )->andReturn( '{"hash":"123"}' );

		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false ); // No cache hit

		// Mock Pinecone returning centroid/candidates
		$this->pinecone->method( 'query' )->willReturn(
			array(
				'matches' => array(
					array(
						'id'    => 'venue-1',
						'score' => 0.9,
					),
					array(
						'id'    => 'venue-2',
						'score' => 0.8,
					),
				),
			)
		);

		$this->supabase->expects( $this->exactly( 2 ) )
			->method( 'select_in' )
			->willReturnCallback(
				function ( $table, $column, $values ) {
					if ( 'venues' === $table ) {
						return array(
							array(
								'slug' => 'venue-1',
								'name' => 'Venue One',
							),
							array(
								'slug' => 'venue-2',
								'name' => 'Venue Two',
							),
						);
					}

					return array();
				}
			);

		$result = $this->engine->generate_itinerary(
			array(
				'nonce'       => 'good_token',
				'interests'   => array(), // Skip Stage 2 for deterministic test
				'time_window' => 120,
			)
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'partial', $result['status'] );
		$this->assertCount( 2, $result['candidates'] );
		$this->assertEquals( 'venue-1', $result['candidates'][0]['slug'] );
		$this->assertEquals( 'Venue One', $result['candidates'][0]['data']['name'] );
	}

	public function test_kmeans_lookup_supabase_error() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false );

		$this->pinecone->method( 'query' )->willReturn(
			array(
				'matches' => array(
					array(
						'id'    => 'venue-1',
						'score' => 0.9,
					),
				),
			)
		);

		$this->supabase->method( 'select_in' )
			->willReturnCallback(
				function ( $table ) {
					if ( 'venues' === $table ) {
						return new WP_Error( 'batp_supabase_http_error', 'Supabase down' );
					}

					return array();
				}
			);

		$result = $this->engine->generate_itinerary(
			array(
				'nonce' => 'good_token',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_supabase_http_error', $result->get_error_code() );
	}

	public function test_filters_budget_enforced() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false );

		$this->pinecone->method( 'query' )->willReturn(
			array(
				'matches' => array(
					array(
						'id'    => 'venue-1',
						'score' => 0.9,
					),
					array(
						'id'    => 'venue-2',
						'score' => 0.8,
					),
				),
			)
		);

		$this->supabase->method( 'select_in' )
			->willReturnCallback(
				function ( $table ) {
					if ( 'venues' === $table ) {
						return array(
							array(
								'slug'   => 'venue-1',
								'name'   => 'Venue One',
								'budget' => 'low',
							),
							array(
								'slug'   => 'venue-2',
								'name'   => 'Venue Two',
								'budget' => 'high',
							),
						);
					}

					if ( 'association_rules' === $table ) {
						return array();
					}

					return array();
				}
			);

		$result = $this->engine->generate_itinerary(
			array(
				'nonce'     => 'good_token',
				'interests' => array(),
				'budget'    => 'low',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['candidates'] );
		$this->assertEquals( 'venue-1', $result['candidates'][0]['slug'] );
	}

	public function test_filters_sbrn_boost_applied() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false );

		$this->pinecone->method( 'query' )->willReturn(
			array(
				'matches' => array(
					array(
						'id'    => 'venue-1',
						'score' => 0.9,
					),
					array(
						'id'    => 'venue-2',
						'score' => 0.8,
					),
				),
			)
		);

		$this->supabase->method( 'select_in' )
			->willReturnCallback(
				function ( $table ) {
					if ( 'venues' === $table ) {
						return array(
							array(
								'slug'           => 'venue-1',
								'name'           => 'Venue One',
								'is_sbrn_member' => false,
							),
							array(
								'slug'           => 'venue-2',
								'name'           => 'Venue Two',
								'is_sbrn_member' => true,
							),
						);
					}

					if ( 'association_rules' === $table ) {
						return array();
					}

					return array();
				}
			);

		$result = $this->engine->generate_itinerary(
			array(
				'nonce'     => 'good_token',
				'interests' => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['candidates'] );
		$this->assertGreaterThan( 0.8, $result['candidates'][1]['score'] );
		$this->assertContains( 'sbrn', $result['candidates'][1]['sources'] );
	}

	public function test_semantic_embedding_cache_hit_skips_gemini_call() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )
			->willReturnOnConsecutiveCalls( false, array( 0.1, 0.2 ), false );

		$this->pinecone->method( 'query' )->willReturn(
			array(
				'matches' => array(
					array(
						'id'       => 'venue-1',
						'score'    => 0.99,
						'metadata' => array(
							'slug' => 'venue-1',
							'name' => 'Venue One',
						),
					),
				),
			)
		);

		$this->supabase->method( 'select_in' )
			->willReturnCallback(
				function ( $table ) {
					if ( 'venues' === $table ) {
						return array(
							array(
								'slug' => 'venue-1',
								'name' => 'Venue One',
							),
						);
					}

					if ( 'association_rules' === $table ) {
						return array();
					}

					return array();
				}
			);

		// Gemini embed_content should not be called because cache supplies embedding
		$this->gemini->expects( $this->never() )->method( 'embed_content' );

		$result = $this->engine->generate_itinerary(
			array(
				'nonce'     => 'good_token',
				'interests' => array( 'Food' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'venue-1', $result['candidates'][0]['slug'] );
		$this->assertContains( 'semantic', $result['candidates'][0]['sources'] );
	}

	public function test_semantic_embedding_failure_bubbles_error() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false );

		$this->pinecone->method( 'query' )->willReturn( array( 'matches' => array() ) );
		$this->supabase->method( 'select_in' )
			->willReturnCallback(
				function ( $table ) {
					if ( 'venues' === $table ) {
						return array( array( 'slug' => 'venue-1' ) );
					}

					if ( 'association_rules' === $table ) {
						return array();
					}

					return array();
				}
			);

		$this->gemini->method( 'embed_content' )
			->willReturn( new WP_Error( 'batp_gemini_error', 'Embedding failed' ) );

		$result = $this->engine->generate_itinerary(
			array(
				'nonce'     => 'good_token',
				'interests' => array( 'Food' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_gemini_error', $result->get_error_code() );
	}

	public function test_mba_boost_applied() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false );

		$this->pinecone->method( 'query' )->willReturn(
			array(
				'matches' => array(
					array(
						'id'    => 'venue-1',
						'score' => 0.9,
					),
					array(
						'id'    => 'venue-2',
						'score' => 0.8,
					),
				),
			)
		);

		$this->supabase->method( 'select_in' )
			->willReturnCallback(
				function ( $table ) {
					if ( 'venues' === $table ) {
						return array(
							array(
								'slug' => 'venue-1',
								'name' => 'Venue One',
							),
							array(
								'slug' => 'venue-2',
								'name' => 'Venue Two',
							),
						);
					}

					if ( 'association_rules' === $table ) {
						return array(
							array(
								'seed_slug'           => 'venue-1',
								'recommendation_slug' => 'venue-2',
								'lift'                => 1.5,
								'confidence'          => 0.7,
							),
						);
					}

					return array();
				}
			);

		$result = $this->engine->generate_itinerary(
			array(
				'nonce'       => 'good_token',
				'interests'   => array(),
				'time_window' => 120,
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['candidates'] );
		$this->assertEqualsWithDelta( 1.2, $result['candidates'][1]['score'], 0.001 );
		$this->assertContains( 'mba', $result['candidates'][1]['sources'] );
		$this->assertArrayHasKey( 'mba', $result['candidates'][1]['meta'] );
	}

	public function test_mba_supabase_error_is_non_fatal() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false );

		$this->pinecone->method( 'query' )->willReturn(
			array(
				'matches' => array(
					array(
						'id'    => 'venue-1',
						'score' => 0.9,
					),
				),
			)
		);

		$this->supabase->method( 'select_in' )
			->willReturnCallback(
				function ( $table ) {
					if ( 'venues' === $table ) {
						return array(
							array(
								'slug' => 'venue-1',
								'name' => 'Venue One',
							),
						);
					}

					if ( 'association_rules' === $table ) {
						return new WP_Error( 'batp_supabase_http_error', 'Rules down' );
					}

					return array();
				}
			);

		$result = $this->engine->generate_itinerary(
			array(
				'nonce'     => 'good_token',
				'interests' => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'itinerary', $result );
	}

	public function test_llm_ordering_successfully_reorders_candidates() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false );

		$this->pinecone->method( 'query' )->willReturn(
			array(
				'matches' => array(
					array(
						'id'    => 'venue-1',
						'score' => 0.9,
					),
					array(
						'id'    => 'venue-2',
						'score' => 0.8,
					),
				),
			)
		);

		$this->supabase->method( 'select_in' )
			->willReturnCallback(
				function ( $table ) {
					if ( 'venues' === $table ) {
						return array(
							array(
								'slug'         => 'venue-1',
								'name'         => 'Venue One',
								'borough'      => 'Williamsburg',
								'budget'       => 'low',
								'vibe_summary' => 'Coffee + art',
							),
							array(
								'slug'         => 'venue-2',
								'name'         => 'Venue Two',
								'borough'      => 'Brooklyn Heights',
								'budget'       => 'medium',
								'vibe_summary' => 'Dinner + jazz',
							),
						);
					}

					if ( 'association_rules' === $table ) {
						return array();
					}

					return array();
				}
			);

		$llm_payload = $this->mock_llm_payload(
			array(
				array(
					'slug'             => 'venue-2',
					'title'            => 'Dinner first',
					'order'            => 1,
					'arrival_minute'   => 0,
					'duration_minutes' => 90,
					'notes'            => 'Start with dinner',
				),
				array(
					'slug'             => 'venue-1',
					'title'            => 'Coffee after',
					'order'            => 2,
					'arrival_minute'   => 90,
					'duration_minutes' => 60,
					'notes'            => 'Wrap up with espresso',
				),
			)
		);

		$this->set_gemini_response( $llm_payload );

		$result = $this->engine->generate_itinerary(
			array(
				'nonce'     => 'good_token',
				'interests' => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['itinerary']['items'] );
		$this->assertEquals( 'complete', $result['status'] );
		$this->assertEquals( 'venue-2', $result['candidates'][0]['slug'] );
		$this->assertArrayHasKey( 'llm', $result['candidates'][0]['meta'] );
		$this->assertEquals( 'Dinner first', $result['candidates'][0]['meta']['llm']['title'] );
		$this->assertTrue( in_array( 'llm', $result['candidates'][0]['sources'], true ) );
	}

	public function test_llm_ordering_gemini_error_bubbles_up() {
		\Brain\Monkey\Functions\expect( 'wp_verify_nonce' )->andReturn( true );
		$this->security->method( 'enforce_rate_limit' )->willReturn( true );
		$this->cache->method( 'get' )->willReturn( false );

		$this->pinecone->method( 'query' )->willReturn(
			array(
				'matches' => array(
					array(
						'id'    => 'venue-1',
						'score' => 0.9,
					),
				),
			)
		);

		$this->supabase->method( 'select_in' )
			->willReturnCallback(
				function ( $table ) {
					if ( 'venues' === $table ) {
						return array(
							array(
								'slug'   => 'venue-1',
								'name'   => 'Venue One',
								'budget' => 'low',
							),
						);
					}

					if ( 'association_rules' === $table ) {
						return array();
					}

					return array();
				}
			);

		$this->set_gemini_response( new WP_Error( 'batp_gemini_error', 'LLM down' ) );

		$result = $this->engine->generate_itinerary(
			array(
				'nonce'     => 'good_token',
				'interests' => array(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'batp_gemini_error', $result->get_error_code() );
	}

	private function mock_llm_payload( array $items = array() ): array {
		$payload = array(
			'meta'  => array( 'summary' => 'Mock itinerary' ),
			'items' => $items,
		);

		return array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array(
							array(
								'text' => json_encode( $payload ),
							),
						),
					),
				),
			),
		);
	}

	private function set_gemini_response( $response ): void {
		$this->gemini_response = $response;
	}
}
