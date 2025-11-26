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

		// Default: Last 30 days
		$response = $supabase->rpc( 'get_analytics_stats' );

		$data = array(
			'itineraries' => 0,
			'clicks'      => array(
				'website'    => 0,
				'phone'      => 0,
				'directions' => 0,
			),
		);

		$error = null;

		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
			// Fallback for dev/demo if RPC missing
			if ( strpos( $error, 'function' ) !== false ) {
				$error .= ' (Please run migration 060_analytics_reporting.sql)';
			}
		} else {
			$data = $response;
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
