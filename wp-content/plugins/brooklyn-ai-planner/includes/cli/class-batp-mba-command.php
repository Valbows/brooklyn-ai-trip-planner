<?php
/**
 * WP-CLI command for MBA generation.
 *
 * @package BrooklynAI\CLI
 */

namespace BrooklynAI\CLI;

use BrooklynAI\Plugin;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Batp_Mba_Command {

	/**
	 * Generates Market Basket Analysis association rules from analytics data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp batp generate-rules
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		WP_CLI::line( 'Starting MBA rule generation...' );

		$plugin    = Plugin::instance();
		$generator = $plugin->mba_generator();

		$result = $generator->generate_rules();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( sprintf( 'MBA Generation Failed: %s', $result->get_error_message() ) );
		}

		WP_CLI::success( sprintf( 'Successfully generated and stored %d association rules.', (int) $result ) );
	}
}
