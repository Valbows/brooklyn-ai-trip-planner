<?php
/**
 * Tests for Reports_Page class.
 *
 * @package BrooklynAI\Tests
 */

namespace BrooklynAI\Tests;

use BrooklynAI\Admin\Reports_Page;
use PHPUnit\Framework\TestCase;

class ReportsPageTest extends TestCase {

	/**
	 * Test that date range options are defined correctly.
	 */
	public function test_date_ranges_constant_exists(): void {
		$reflection = new \ReflectionClass( Reports_Page::class );
		$this->assertTrue( $reflection->hasConstant( 'DATE_RANGES' ) );

		$constant = $reflection->getConstant( 'DATE_RANGES' );
		$this->assertArrayHasKey( 'week', $constant );
		$this->assertArrayHasKey( 'month', $constant );
		$this->assertArrayHasKey( 'quarter', $constant );
		$this->assertArrayHasKey( 'year', $constant );
		$this->assertArrayHasKey( 'all', $constant );
	}

	/**
	 * Test get_start_date returns correct dates for each range.
	 */
	public function test_get_start_date_returns_correct_values(): void {
		$page = new Reports_Page();

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_start_date' );
		$method->setAccessible( true );

		// Week should be 7 days ago
		$week_start = $method->invoke( $page, 'week' );
		$this->assertNotNull( $week_start );
		$expected_week = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$this->assertStringContainsString( $expected_week, $week_start );

		// Month should be 30 days ago
		$month_start    = $method->invoke( $page, 'month' );
		$expected_month = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$this->assertStringContainsString( $expected_month, $month_start );

		// All time should return null
		$all_start = $method->invoke( $page, 'all' );
		$this->assertNull( $all_start );
	}

	/**
	 * Test get_range_label returns human-readable labels.
	 */
	public function test_get_range_label_returns_correct_labels(): void {
		$page = new Reports_Page();

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_range_label' );
		$method->setAccessible( true );

		$this->assertEquals( 'Last 7 Days', $method->invoke( $page, 'week' ) );
		$this->assertEquals( 'Last 30 Days', $method->invoke( $page, 'month' ) );
		$this->assertEquals( 'Last 90 Days', $method->invoke( $page, 'quarter' ) );
		$this->assertEquals( 'Last 365 Days', $method->invoke( $page, 'year' ) );
		$this->assertEquals( 'All Time', $method->invoke( $page, 'all' ) );
	}

	/**
	 * Test invalid range defaults to month label.
	 */
	public function test_get_range_label_defaults_for_invalid(): void {
		$page = new Reports_Page();

		$reflection = new \ReflectionClass( $page );
		$method     = $reflection->getMethod( 'get_range_label' );
		$method->setAccessible( true );

		$this->assertEquals( 'Last 30 Days', $method->invoke( $page, 'invalid_range' ) );
	}
}
