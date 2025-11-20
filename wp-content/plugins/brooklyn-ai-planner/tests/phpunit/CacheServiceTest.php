<?php

use BrooklynAI\Cache_Service;

class CacheServiceTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		batp_test_reset_transients();
	}

	public function test_set_and_get_round_trip(): void {
		$cache = new Cache_Service();

		$payload = array( 'prompt' => 'test' );
		$cache->set( 'gemini', $payload, 'cached-response', Cache_Service::TTL_GEMINI );

		$this->assertSame( 'cached-response', $cache->get( 'gemini', $payload ) );
	}

	public function test_delete_removes_value(): void {
		$cache   = new Cache_Service();
		$payload = array( 'prompt' => 'delete-me' );

		$cache->set( 'pipeline', $payload, 'value', Cache_Service::TTL_PIPELINE );
		$cache->delete( 'pipeline', $payload );

		$this->assertFalse( $cache->get( 'pipeline', $payload ) );
	}
}
