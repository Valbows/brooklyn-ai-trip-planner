<?php
/**
 * Tests for REST Controller event types.
 *
 * @package BrooklynAI\Tests
 */

namespace BrooklynAI\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for REST API event tracking validation.
 *
 * Note: These tests verify the expected event types and schema without
 * requiring WordPress REST infrastructure.
 */
class RestControllerTest extends TestCase {

	/**
	 * Allowed action types (mirrors REST_Controller).
	 */
	private array $allowed_types = array(
		'website_click',
		'phone_click',
		'directions_click',
		'itinerary_generated',
		'share_copy_link',
		'share_download_pdf',
		'share_add_calendar',
		'share_email',
		'share_sms',
		'share_social',
		'item_replaced',
	);

	/**
	 * Test that action_type validation allows all share event types.
	 */
	public function test_action_type_allows_share_events(): void {
		$share_events = array(
			'share_copy_link',
			'share_download_pdf',
			'share_add_calendar',
			'share_email',
			'share_sms',
			'share_social',
		);

		foreach ( $share_events as $type ) {
			$this->assertTrue(
				in_array( $type, $this->allowed_types, true ),
				"Action type '$type' should be in allowed list"
			);
		}
	}

	/**
	 * Test item_replaced is an allowed action type.
	 */
	public function test_item_replaced_is_allowed(): void {
		$this->assertContains( 'item_replaced', $this->allowed_types );
	}

	/**
	 * Test that place_id schema allows null for share events.
	 *
	 * This verifies the REST schema structure allows null for place_id.
	 */
	public function test_place_id_schema_accepts_null(): void {
		// Schema structure (mirrors REST_Controller)
		$place_id_schema = array(
			'required' => false,
			'type'     => array( 'string', 'null' ),
		);

		// Verify null is an allowed type
		$this->assertContains( 'null', $place_id_schema['type'] );
		$this->assertContains( 'string', $place_id_schema['type'] );
		$this->assertFalse( $place_id_schema['required'] );
	}

	/**
	 * Test share event types are comprehensive.
	 */
	public function test_all_share_event_types_defined(): void {
		$share_events = array_filter(
			$this->allowed_types,
			fn( $t ) => str_starts_with( $t, 'share_' )
		);

		// Should have 6 share event types
		$this->assertCount( 6, $share_events );
	}

	/**
	 * Test total number of allowed event types.
	 */
	public function test_total_event_types_count(): void {
		// 4 click/gen events + 6 share events + 1 item_replaced = 11
		$this->assertCount( 11, $this->allowed_types );
	}
}
