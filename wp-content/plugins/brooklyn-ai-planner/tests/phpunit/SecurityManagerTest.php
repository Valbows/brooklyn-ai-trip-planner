<?php

use BrooklynAI\Security_Manager;

class SecurityManagerTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		batp_test_reset_transients();
	}

	public function test_rate_limit_blocks_after_five_requests() {
		$manager = new Security_Manager();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $manager->enforce_rate_limit( '1.1.1.1' ) );
		}

		$result = $manager->enforce_rate_limit( '1.1.1.1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'batp_rate_limited', $result->get_error_code() );
	}

	public function test_sanitize_coordinates_returns_wp_error_on_invalid() {
		$manager = new Security_Manager();
		$result  = $manager->sanitize_coordinates(
			array(
				'lat' => 999,
				'lng' => 0,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'batp_invalid_lat', $result->get_error_code() );
	}

	public function test_sanitize_coordinates_returns_clean_array() {
		$manager = new Security_Manager();
		$result  = $manager->sanitize_coordinates(
			array(
				'lat' => '40.7128',
				'lng' => '-73.935242',
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 40.7128, $result['lat'] );
	}
}
