<?php
/**
 * Market Basket Analysis (MBA) Generator.
 *
 * Responsible for mining association rules from analytics data
 * and updating the knowledge base.
 *
 * @package BrooklynAI\MBA
 */

namespace BrooklynAI\MBA;

use BrooklynAI\Clients\Supabase_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MBA_Generator {
	private Supabase_Client $supabase;
	
	// Configuration
	private float $min_support    = 0.005; // Minimum 0.5% of sessions
	private float $min_confidence = 0.1;   // Minimum 10% confidence
	private float $min_lift       = 1.2;   // Minimum 1.2x lift (positive correlation)
	private int $max_rules        = 1000;  // Safety cap

	public function __construct( Supabase_Client $supabase ) {
		$this->supabase = $supabase;
	}

	/**
	 * Execute the mining process and update rules.
	 *
	 * @return int|WP_Error Number of rules generated or error.
	 */
	public function generate_rules() {
		// 1. Fetch transaction data
		$transactions = $this->fetch_transactions();
		if ( is_wp_error( $transactions ) ) {
			return $transactions;
		}

		if ( empty( $transactions ) ) {
			return new WP_Error( 'batp_mba_no_data', 'No transaction data available for analysis.' );
		}

		// 2. Calculate Item Support (Frequency)
		$item_counts = array();
		$total_sessions = count( $transactions );

		foreach ( $transactions as $session_items ) {
			$unique_items = array_unique( $session_items );
			foreach ( $unique_items as $item ) {
				if ( ! isset( $item_counts[ $item ] ) ) {
					$item_counts[ $item ] = 0;
				}
				$item_counts[ $item ]++;
			}
		}

		// Filter frequent items
		$frequent_items = array();
		foreach ( $item_counts as $item => $count ) {
			$support = $count / $total_sessions;
			if ( $support >= $this->min_support ) {
				$frequent_items[ $item ] = $support;
			}
		}

		// 3. Calculate Pair Support (Co-occurrence)
		$pair_counts = array();
		
		foreach ( $transactions as $session_items ) {
			$unique_items = array_values( array_unique( $session_items ) );
			$count = count( $unique_items );
			if ( $count < 2 ) {
				continue;
			}

			sort( $unique_items ); // Ensure consistent pair ordering

			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! isset( $frequent_items[ $unique_items[ $i ] ] ) ) {
					continue;
				}

				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( ! isset( $frequent_items[ $unique_items[ $j ] ] ) ) {
						continue;
					}

					$pair_key = $unique_items[ $i ] . '|' . $unique_items[ $j ];
					if ( ! isset( $pair_counts[ $pair_key ] ) ) {
						$pair_counts[ $pair_key ] = 0;
					}
					$pair_counts[ $pair_key ]++;
				}
			}
		}

		// 4. Generate Rules
		$rules = array();

		foreach ( $pair_counts as $pair_key => $count ) {
			$pair_support = $count / $total_sessions;
			list( $item_a, $item_b ) = explode( '|', $pair_key );

			// Rule A -> B
			$this->evaluate_rule( $item_a, $item_b, $pair_support, $frequent_items, $rules );
			
			// Rule B -> A
			$this->evaluate_rule( $item_b, $item_a, $pair_support, $frequent_items, $rules );
		}

		// 5. Persist Rules
		if ( empty( $rules ) ) {
			return 0;
		}

		// Sort by Lift desc and cap
		usort( $rules, function( $a, $b ) {
			return $b['lift'] <=> $a['lift'];
		} );

		$rules = array_slice( $rules, 0, $this->max_rules );

		return $this->persist_rules( $rules );
	}

	private function evaluate_rule( $antecedent, $consequent, $pair_support, $frequent_items, &$rules ) {
		$antecedent_support = $frequent_items[ $antecedent ];
		$consequent_support = $frequent_items[ $consequent ];

		$confidence = $pair_support / $antecedent_support;
		$lift       = $confidence / $consequent_support;

		if ( $confidence >= $this->min_confidence && $lift >= $this->min_lift ) {
			$rules[] = array(
				'seed_slug'           => $antecedent,
				'recommendation_slug' => $consequent,
				'lift'                => round( $lift, 3 ),
				'confidence'          => round( $confidence, 3 ),
				'support'             => round( $pair_support, 4 ),
				'generated_at'        => gmdate( 'Y-m-d H:i:s' ),
			);
		}
	}

	private function fetch_transactions() {
		// In a real scenario, we would fetch raw logs.
		// For now, we'll fetch from a 'analytics_logs' table or view in Supabase.
		// We need rows: session_id, venue_slug
		// Since we might have millions, this should ideally be a stored procedure or aggregated view.
		// Here we simulate fetching recent high-quality sessions.
		
		// Supabase select with limit for prototype
		$response = $this->supabase->rpc( 'fetch_training_transactions', array( 'limit_count' => 5000 ) );
		
		if ( is_wp_error( $response ) ) {
			// Fallback: Select from raw table if RPC missing (during dev)
			// Assuming we log 'view_venue' events with metadata->slug
			// This is getting complicated for a PHP script.
			// Let's assume we have a view `training_dataset` with `session_id` and `venue_slug`.
			return $this->supabase->select( 'training_dataset', array(
				'select' => 'session_id,venue_slug',
				'limit'  => 5000
			) );
		}

		return $this->group_transactions( $response );
	}

	private function group_transactions( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$sessions = array();
		foreach ( $rows as $row ) {
			$sid  = $row['session_id'] ?? null;
			$slug = $row['venue_slug'] ?? null;
			
			if ( $sid && $slug ) {
				if ( ! isset( $sessions[ $sid ] ) ) {
					$sessions[ $sid ] = array();
				}
				$sessions[ $sid ][] = $slug;
			}
		}

		return array_values( $sessions );
	}

	private function persist_rules( $rules ) {
		// We should truncate or replace old rules.
		// Supabase upsert might be slow for batch.
		// Better: DELETE all from association_rules, then INSERT.
		
		// 1. Clear old rules (or use a version tag)
		// For simplicity in prototype: Delete all.
		$delete = $this->supabase->delete( 'association_rules', array( 'id' => 'neq.0' ) ); // Hack to delete all? Or use RPC.
		
		// Actually, best practice: use RPC `replace_association_rules`.
		$result = $this->supabase->rpc( 'replace_association_rules', array( 'rules' => $rules ) );
		
		if ( is_wp_error( $result ) ) {
			// Fallback to batch insert if RPC fails (e.g. not defined yet)
			// Chunking to avoid payload limits
			$chunks = array_chunk( $rules, 100 );
			$count = 0;
			foreach ( $chunks as $chunk ) {
				$insert = $this->supabase->insert( 'association_rules', $chunk );
				if ( ! is_wp_error( $insert ) ) {
					$count += count( $chunk );
				}
			}
			return $count;
		}

		return count( $rules );
	}
}
