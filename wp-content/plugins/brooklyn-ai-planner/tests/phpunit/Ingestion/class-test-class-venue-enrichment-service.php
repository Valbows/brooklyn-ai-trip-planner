<?php
/**
 * Tests for Venue_Enrichment_Service.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Tests\Ingestion;

use BrooklynAI\Ingestion\Venue_Enrichment_Service;
use BrooklynAI\Clients\Gemini_Client;
use PHPUnit\Framework\TestCase;
use WP_Error;

class Test_Class_Venue_Enrichment_Service extends TestCase {
	private $gemini_mock;
	private $service;

	protected function setUp(): void {
		parent::setUp();
		$this->gemini_mock = $this->createMock( Gemini_Client::class );
		$this->service     = new Venue_Enrichment_Service( $this->gemini_mock );
	}

	public function test_enrich_dry_run_generates_mocks() {
		$venue = array(
			'name'       => 'Test Venue',
			'borough'    => 'Brooklyn',
			'categories' => array( 'Art' ),
			'latitude'   => '40.6',
			'longitude'  => '-73.9',
		);

		// Dry run should NOT call Gemini
		$this->gemini_mock->expects( $this->never() )->method( 'generate_content' );
		$this->gemini_mock->expects( $this->never() )->method( 'embed_content' );

		$result = $this->service->enrich( $venue, true );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'supabase', $result );
		$this->assertArrayHasKey( 'pinecone', $result );
		$this->assertStringContainsString( 'dry-run', $result['supabase']['vibe_summary'] );
		$this->assertCount( 768, json_decode( $result['supabase']['embedding'] ) );
	}

	public function test_enrich_calls_gemini_on_success() {
		$venue = array(
			'name'    => 'Real Venue',
			'borough' => 'Brooklyn',
		);

		// Mock Summary Response
		$summary_response = array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array(
							array( 'text' => 'A cool place.' ),
						),
					),
				),
			),
		);

		// Mock Embedding Response
		$embedding_response = array(
			'embedding' => array(
				'values' => array_fill( 0, 768, 0.1 ),
			),
		);

		$this->gemini_mock->expects( $this->once() )
			->method( 'generate_content' )
			->willReturn( $summary_response );

		$this->gemini_mock->expects( $this->once() )
			->method( 'embed_content' )
			->willReturn( $embedding_response );

		$result = $this->service->enrich( $venue, false );

		$this->assertEquals( 'A cool place.', $result['supabase']['vibe_summary'] );
		$this->assertEquals( array_fill( 0, 768, 0.1 ), $result['pinecone']['values'] );
	}

	public function test_enrich_handles_gemini_error() {
		$venue = array( 'name' => 'Error Venue' );

		$this->gemini_mock->expects( $this->once() )
			->method( 'generate_content' )
			->willReturn( new WP_Error( 'api_error', 'Gemini Failed' ) );

		$result = $this->service->enrich( $venue, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'api_error', $result->get_error_code() );
	}
}
