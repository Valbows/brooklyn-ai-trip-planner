<?php
/**
 * WP-CLI command for managing MBA (Market Basket Analysis).
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\CLI;

use BrooklynAI\Plugin;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Batp_Mba_Command {

	/**
	 * Runs the MBA Association Rules mining job.
	 *
	 * ## OPTIONS
	 *
	 * [--min-support=<val>]
	 * : Minimum support threshold (default: 0.005).
	 *
	 * [--min-confidence=<val>]
	 * : Minimum confidence threshold (default: 0.1).
	 *
	 * [--min-lift=<val>]
	 * : Minimum lift threshold (default: 1.2).
	 *
	 * ## EXAMPLES
	 *
	 *     wp batp mba run --min-support=0.01
	 *
	 * @when after_wp_load
	 */
	public function run( $args, $assoc_args ) {
		$min_support    = (float) ( $assoc_args['min-support'] ?? 0.005 );
		$min_confidence = (float) ( $assoc_args['min-confidence'] ?? 0.1 );
		$min_lift       = (float) ( $assoc_args['min-lift'] ?? 1.2 );

		WP_CLI::log( "Starting MBA job (Support: $min_support, Confidence: $min_confidence, Lift: $min_lift)..." );

		$supabase = Plugin::instance()->get_supabase_client();
		if ( ! $supabase ) {
			WP_CLI::error( 'Supabase client not initialized. Check configuration.' );
		}

		$result = $supabase->run_mba_job( $min_support, $min_confidence, $min_lift );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'MBA Job Failed: ' . $result->get_error_message() );
		}

		if ( isset( $result['status'] ) && 'error' === $result['status'] ) {
			WP_CLI::error( 'RPC Error: ' . ( $result['message'] ?? 'Unknown error' ) );
		}

		$count = $result['rules_generated'] ?? 0;
		$tx    = $result['total_transactions'] ?? 0;

		WP_CLI::success( "MBA Job Complete. Processed $tx transactions. Generated $count rules." );
	}

	/**
	 * Checks the status of the association rules table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp batp mba status
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$supabase = Plugin::instance()->get_supabase_client();

		// Query rule count
		$result = $supabase->select(
			'association_rules',
			array(
				'select'  => 'count',
				'limit'   => 1,
				'headers' => array( 'Range' => '0-1' ), // Hint for exact count if Supabase supports it in HEAD, but GET works usually
			)
		);

		// Supabase REST returns array of objects. For count, we might need to parse differently depending on exact REST usage.
		// Simplified: Just select latest generated_at

		$latest = $supabase->select(
			'association_rules',
			array(
				'select' => 'generated_at,cohort',
				'order'  => 'generated_at.desc',
				'limit'  => 1,
			)
		);

		if ( is_wp_error( $latest ) ) {
			WP_CLI::error( 'Could not fetch status: ' . $latest->get_error_message() );
		}

		if ( empty( $latest ) ) {
			WP_CLI::log( 'No rules found in `association_rules`.' );
			return;
		}

		$rule = $latest[0];
		WP_CLI::success( sprintf( 'Last generated: %s (Cohort: %s)', $rule['generated_at'], $rule['cohort'] ) );
	}
}
