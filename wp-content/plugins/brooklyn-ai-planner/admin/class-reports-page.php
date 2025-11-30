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
	 * Date range options.
	 */
	private const DATE_RANGES = array(
		'week'    => '7 days',
		'month'   => '30 days',
		'quarter' => '90 days',
		'year'    => '365 days',
		'all'     => 'all time',
	);

	/**
	 * Get the start date based on range selection.
	 *
	 * @param string $range Range key (week, month, quarter, year, all).
	 * @return string|null Start date in ISO format, or null for all time.
	 */
	private function get_start_date( string $range ): ?string {
		$days_map = array(
			'week'    => 7,
			'month'   => 30,
			'quarter' => 90,
			'year'    => 365,
		);

		if ( 'all' === $range || ! isset( $days_map[ $range ] ) ) {
			return null;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( "-{$days_map[ $range ]} days" ) );
	}

	/**
	 * Get human-readable label for date range.
	 *
	 * @param string $range Range key.
	 * @return string
	 */
	private function get_range_label( string $range ): string {
		$labels = array(
			'week'    => 'Last 7 Days',
			'month'   => 'Last 30 Days',
			'quarter' => 'Last 90 Days',
			'year'    => 'Last 365 Days',
			'all'     => 'All Time',
		);
		return $labels[ $range ] ?? 'Last 30 Days';
	}

	/**
	 * Get event counts directly from analytics_logs table.
	 *
	 * @param \BrooklynAI\Clients\Supabase_Client $supabase Supabase client.
	 * @param string|null                         $start_date Start date in ISO format, or null for all time.
	 * @return array|\WP_Error
	 */
	private function get_event_counts( $supabase, ?string $start_date ) {
		$data = array(
			'itineraries' => 0,
			'clicks'      => array(
				'website'    => 0,
				'phone'      => 0,
				'directions' => 0,
			),
			'shares'      => 0,
		);

		// Query all relevant events using select_in
		$action_types = array(
			'itinerary_generated',
			'website_click',
			'phone_click',
			'directions_click',
			'share_copy_link',
			'share_download_pdf',
			'share_add_calendar',
			'share_email',
			'share_sms',
			'share_social',
		);

		$options = array( 'select' => 'action_type,created_at' );

		$response = $supabase->select_in( 'analytics_logs', 'action_type', $action_types, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Count events by type (filtering by date if specified)
		foreach ( $response as $row ) {
			// Filter by date if start_date is set
			if ( null !== $start_date && isset( $row['created_at'] ) ) {
				if ( $row['created_at'] < $start_date ) {
					continue;
				}
			}

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
				case 'share_copy_link':
				case 'share_download_pdf':
				case 'share_add_calendar':
				case 'share_email':
				case 'share_sms':
				case 'share_social':
					++$data['shares'];
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

		// Get selected date range from query param
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page
		$selected_range = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : 'month';
		if ( ! array_key_exists( $selected_range, self::DATE_RANGES ) ) {
			$selected_range = 'month';
		}

		$start_date  = $this->get_start_date( $selected_range );
		$range_label = $this->get_range_label( $selected_range );

		$data = array(
			'itineraries' => 0,
			'clicks'      => array(
				'website'    => 0,
				'phone'      => 0,
				'directions' => 0,
			),
			'shares'      => 0,
		);

		$error = null;

		// Direct count queries for accurate stats
		$counts = $this->get_event_counts( $supabase, $start_date );
		if ( is_wp_error( $counts ) ) {
			$error = $counts->get_error_message();
		} else {
			$data = $counts;
		}

		$base_url = admin_url( 'admin.php?page=batp-reports' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Brooklyn AI Reports', 'brooklyn-ai-planner' ); ?></h1>
			
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<!-- Date Range Selector -->
			<div class="batp-date-range" style="margin: 20px 0; display: flex; gap: 8px; flex-wrap: wrap;">
				<?php
				$ranges = array(
					'week'    => __( 'Week', 'brooklyn-ai-planner' ),
					'month'   => __( 'Month', 'brooklyn-ai-planner' ),
					'quarter' => __( 'Quarter', 'brooklyn-ai-planner' ),
					'year'    => __( 'Year', 'brooklyn-ai-planner' ),
					'all'     => __( 'All Time', 'brooklyn-ai-planner' ),
				);
				foreach ( $ranges as $key => $label ) :
					$is_active = $selected_range === $key;
					$btn_class = $is_active ? 'button button-primary' : 'button';
					?>
					<a href="<?php echo esc_url( add_query_arg( 'range', $key, $base_url ) ); ?>" 
						class="<?php echo esc_attr( $btn_class ); ?>"
						style="<?php echo $is_active ? '' : 'background: #fff;'; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</div>

			<p class="description" style="margin-bottom: 20px;">
				<?php
				/* translators: %s: selected date range label */
				printf( esc_html__( 'Showing data for: %s', 'brooklyn-ai-planner' ), '<strong>' . esc_html( $range_label ) . '</strong>' );
				?>
			</p>

			<div class="batp-reports-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
				
				<!-- Card: Itineraries -->
				<div class="card" style="padding: 20px; text-align: center;">
					<h2 style="margin-top: 0; font-size: 1.1em; color: #64748b;">Itineraries Generated</h2>
					<p style="font-size: 2.5em; margin: 10px 0; font-weight: bold; color: #1649FF;">
						<?php echo esc_html( number_format_i18n( $data['itineraries'] ?? 0 ) ); ?>
					</p>
				</div>

				<!-- Card: Website Clicks -->
				<div class="card" style="padding: 20px; text-align: center;">
					<h2 style="margin-top: 0; font-size: 1.1em; color: #64748b;">Website Clicks</h2>
					<p style="font-size: 2.5em; margin: 10px 0; font-weight: bold; color: #F2AE01;">
						<?php echo esc_html( number_format_i18n( $data['clicks']['website'] ?? 0 ) ); ?>
					</p>
				</div>

				<!-- Card: Phone Clicks -->
				<div class="card" style="padding: 20px; text-align: center;">
					<h2 style="margin-top: 0; font-size: 1.1em; color: #64748b;">Phone Calls</h2>
					<p style="font-size: 2.5em; margin: 10px 0; font-weight: bold; color: #10b981;">
						<?php echo esc_html( number_format_i18n( $data['clicks']['phone'] ?? 0 ) ); ?>
					</p>
				</div>

				<!-- Card: Directions -->
				<div class="card" style="padding: 20px; text-align: center;">
					<h2 style="margin-top: 0; font-size: 1.1em; color: #64748b;">Directions Requests</h2>
					<p style="font-size: 2.5em; margin: 10px 0; font-weight: bold; color: #6366f1;">
						<?php echo esc_html( number_format_i18n( $data['clicks']['directions'] ?? 0 ) ); ?>
					</p>
				</div>

				<!-- Card: Shares -->
				<div class="card" style="padding: 20px; text-align: center;">
					<h2 style="margin-top: 0; font-size: 1.1em; color: #64748b;">Shares & Exports</h2>
					<p style="font-size: 2.5em; margin: 10px 0; font-weight: bold; color: #ec4899;">
						<?php echo esc_html( number_format_i18n( $data['shares'] ?? 0 ) ); ?>
					</p>
				</div>
			</div>

			<hr style="margin: 30px 0;">

			<h2><?php esc_html_e( 'Actions', 'brooklyn-ai-planner' ); ?></h2>
			<p>
				<button class="button button-primary" onclick="window.print()">
					<?php esc_html_e( 'Download PDF / Print', 'brooklyn-ai-planner' ); ?>
				</button>
				<a href="mailto:?subject=<?php echo esc_attr( rawurlencode( 'Brooklyn AI Report - ' . $range_label ) ); ?>&body=<?php echo esc_attr( rawurlencode( "Brooklyn AI Trip Planner Report ($range_label)\n\nItineraries: {$data['itineraries']}\nWebsite Clicks: {$data['clicks']['website']}\nPhone Calls: {$data['clicks']['phone']}\nDirections: {$data['clicks']['directions']}\nShares: {$data['shares']}" ) ); ?>" 
					class="button button-secondary">
					<?php esc_html_e( 'Email Report', 'brooklyn-ai-planner' ); ?>
				</a>
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
