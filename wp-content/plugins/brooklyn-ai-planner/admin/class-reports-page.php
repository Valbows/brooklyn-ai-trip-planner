<?php
/**
 * Analytics & Reporting Dashboard.
 *
 * @package BrooklynAI\Admin
 */

namespace BrooklynAI\Admin;

use BrooklynAI\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports_Page {

	/**
	 * Get event counts directly from analytics_logs table.
	 *
	 * @param \BrooklynAI\Clients\Supabase_Client $supabase Supabase client.
	 * @param string                              $start_date Start date in ISO format.
	 * @return array|\WP_Error
	 */
	private function get_event_counts( $supabase, string $start_date ) {
		$data = array(
			'itineraries' => 0,
			'clicks'      => array(
				'website'    => 0,
				'phone'      => 0,
				'directions' => 0,
			),
		);

		// Query all relevant events from last 30 days using select_in
		$action_types = array( 'itinerary_generated', 'website_click', 'phone_click', 'directions_click' );
		$response     = $supabase->select_in( 'analytics_logs', 'action_type', $action_types, array( 'select' => 'action_type' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Count events by type
		foreach ( $response as $row ) {
			switch ( $row['action_type'] ?? '' ) {
				case 'itinerary_generated':
					++$data['itineraries'];
					break;
				case 'website_click':
					++$data['clicks']['website'];
					break;
				case 'phone_click':
					++$data['clicks']['phone'];
					break;
				case 'directions_click':
					++$data['clicks']['directions'];
					break;
			}
		}

		return $data;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'brooklyn-ai-planner',
			__( 'Analytics & Reports', 'brooklyn-ai-planner' ),
			__( 'Reports', 'brooklyn-ai-planner' ),
			'manage_options',
			'batp-reports',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		$supabase = Plugin::instance()->supabase();

		// Calculate date range (last 30 days)
		$end_date   = gmdate( 'Y-m-d\TH:i:s\Z' );
		$start_date = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-30 days' ) );

		$data = array(
			'itineraries' => 0,
			'clicks'      => array(
				'website'    => 0,
				'phone'      => 0,
				'directions' => 0,
			),
		);

		$error = null;

		// Direct count queries for accurate stats
		$counts = $this->get_event_counts( $supabase, $start_date );
		if ( is_wp_error( $counts ) ) {
			$error = $counts->get_error_message();
		} else {
			$data = $counts;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Brooklyn AI Reports', 'brooklyn-ai-planner' ); ?></h1>
			
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<div class="batp-reports-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
				
				<!-- Card: Itineraries -->
				<div class="card" style="padding: 20px; text-align: center;">
					<h2 style="margin-top: 0; font-size: 1.2em; color: #64748b;">Itineraries Generated</h2>
					<p style="font-size: 3em; margin: 10px 0; font-weight: bold; color: #1649FF;">
						<?php echo esc_html( number_format_i18n( $data['itineraries'] ?? 0 ) ); ?>
					</p>
					<p class="description">Last 30 Days</p>
				</div>

				<!-- Card: Website Clicks -->
				<div class="card" style="padding: 20px; text-align: center;">
					<h2 style="margin-top: 0; font-size: 1.2em; color: #64748b;">Website Clicks</h2>
					<p style="font-size: 3em; margin: 10px 0; font-weight: bold; color: #F2AE01;">
						<?php echo esc_html( number_format_i18n( $data['clicks']['website'] ?? 0 ) ); ?>
					</p>
				</div>

				<!-- Card: Phone Clicks -->
				<div class="card" style="padding: 20px; text-align: center;">
					<h2 style="margin-top: 0; font-size: 1.2em; color: #64748b;">Phone Calls</h2>
					<p style="font-size: 3em; margin: 10px 0; font-weight: bold; color: #10b981;">
						<?php echo esc_html( number_format_i18n( $data['clicks']['phone'] ?? 0 ) ); ?>
					</p>
				</div>

				<!-- Card: Directions -->
				<div class="card" style="padding: 20px; text-align: center;">
					<h2 style="margin-top: 0; font-size: 1.2em; color: #64748b;">Directions Requests</h2>
					<p style="font-size: 3em; margin: 10px 0; font-weight: bold; color: #6366f1;">
						<?php echo esc_html( number_format_i18n( $data['clicks']['directions'] ?? 0 ) ); ?>
					</p>
				</div>
			</div>

			<hr style="margin: 30px 0;">

			<h2>Actions</h2>
			<p>
				<button class="button button-primary" onclick="window.print()">Download PDF / Print</button>
				<a href="mailto:?subject=Brooklyn AI Report&body=Here is the monthly report." class="button button-secondary">Email Report</a>
			</p>

			<style>
				@media print {
					#adminmenumain, #wpadminbar, .notice, .submit, hr { display: none; }
					.wrap { margin: 0; padding: 20px; }
					body { background: #fff; }
				}
			</style>
		</div>
		<?php
	}
}
