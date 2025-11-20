<?php

use BrooklynAI\Admin\Settings_Page;

class SettingsPageTest extends \PHPUnit\Framework\TestCase {
	public function test_sanitize_settings_updates_non_empty_fields(): void {
		$page  = new Settings_Page();
		$input = array(
			'gemini_api_key'   => '  NEWKEY  ',
			'pinecone_api_key' => '', // should be ignored
		);

		$result = $page->sanitize_settings( $input );

		$this->assertSame( 'NEWKEY', $result['gemini_api_key'] );
		$this->assertArrayNotHasKey( 'pinecone_api_key', $result );
	}
}
