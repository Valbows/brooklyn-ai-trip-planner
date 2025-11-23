<?php
/**
 * Tests for Venue_Ingestion_Manager.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Tests\Ingestion;

use BrooklynAI\Ingestion\Venue_Ingestion_Manager;
use BrooklynAI\Clients\Supabase_Client;
use BrooklynAI\Clients\Pinecone_Client;
use BrooklynAI\Ingestion\Venue_Enrichment_Service;
use BrooklynAI\Analytics_Logger;
use PHPUnit\Framework\TestCase;
use WP_Error;

class Test_Class_Venue_Ingestion_Manager extends TestCase {
	private $supabase_mock;
	private $pinecone_mock;
	private $enrichment_mock;
	private $analytics_mock;
	private $manager;
	private $csv_path;

	protected function setUp(): void {
		parent::setUp();
		$this->supabase_mock   = $this->createMock( Supabase_Client::class );
		$this->pinecone_mock   = $this->createMock( Pinecone_Client::class );
		$this->enrichment_mock = $this->createMock( Venue_Enrichment_Service::class );
		$this->analytics_mock  = $this->createMock( Analytics_Logger::class );

		$this->manager = new Venue_Ingestion_Manager(
			$this->supabase_mock,
			$this->pinecone_mock,
			$this->enrichment_mock,
			$this->analytics_mock
		);

		// Create a dummy CSV for testing
		$this->csv_path = sys_get_temp_dir() . '/test_venues.csv';
		file_put_contents( $this->csv_path, "name,borough,categories,latitude,longitude\nTest Venue,Brooklyn,\"Art,Food\",40.6,-73.9" );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->csv_path ) ) {
			unlink( $this->csv_path );
		}
		parent::tearDown();
	}

	public function test_ingest_dry_run_valid_row() {
		$this->enrichment_mock->method( 'enrich' )->willReturn(
			array(
				'supabase' => array( 'slug' => 'test-venue' ),
				'pinecone' => array( 'id' => 'test-venue' ),
			)
		);

		// Dry run should NOT call upsert
		$this->supabase_mock->expects( $this->never() )->method( 'upsert' );
		$this->pinecone_mock->expects( $this->never() )->method( 'upsert' );

		$result = $this->manager->ingest(
			$this->csv_path,
			array(
				'dry_run'          => true,
				'required_columns' => array( 'name' ), // Simplify for test
			)
		);

		$this->assertEquals( 1, $result->enriched_count );
		$this->assertEquals( 1, $result->supabase_upserts ); // Dry run counts them as if they happened
	}

	public function test_ingest_real_run_upserts_data() {
		$this->enrichment_mock->method( 'enrich' )->willReturn(
			array(
				'supabase' => array( 'slug' => 'test-venue' ),
				'pinecone' => array( 'id' => 'test-venue' ),
			)
		);

		$this->supabase_mock->expects( $this->once() )
			->method( 'upsert' )
			->willReturn( array( 'status' => 201 ) );

		$this->pinecone_mock->expects( $this->once() )
			->method( 'upsert' )
			->willReturn( array( 'upsertedCount' => 1 ) );

		$result = $this->manager->ingest(
			$this->csv_path,
			array(
				'dry_run'          => false,
				'pinecone_index'   => 'test-index',
				'required_columns' => array( 'name' ),
				'checkpoint_file'  => sys_get_temp_dir() . '/.batp_state.json',
			)
		);

		$this->assertEquals( 1, $result->supabase_upserts );
		$this->assertEquals( 1, $result->pinecone_upserts );
	}

	public function test_ingest_handles_validation_error() {
		// Simulate enrichment failure
		$this->enrichment_mock->method( 'enrich' )
			->willReturn( new WP_Error( 'enrich_fail', 'Failed' ) );

		$result = $this->manager->ingest(
			$this->csv_path,
			array(
				'dry_run'          => true,
				'required_columns' => array( 'name' ),
			)
		);

		$this->assertEquals( 0, $result->enriched_count );
		$this->assertCount( 1, $result->errors );
	}
}
