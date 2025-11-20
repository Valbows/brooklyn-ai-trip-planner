<?php
/**
 * Admin settings page for configuring API keys.
 *
 * @package BrooklynAI\Admin
 */

namespace BrooklynAI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Page {
	private const OPTION_KEY = 'batp_settings';
	private const PAGE_SLUG  = 'batp-settings';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Brooklyn AI Planner', 'brooklyn-ai-planner' ),
			__( 'Brooklyn AI Planner', 'brooklyn-ai-planner' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		add_action( 'load-settings_page_' . self::PAGE_SLUG, array( $this, 'add_help_tab' ) );
	}

	public function register_settings(): void {
		register_setting(
			'batp_settings_group',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);

		add_settings_section(
			'batp_api_section',
			__( 'API Configuration', 'brooklyn-ai-planner' ),
			function () {
				printf( '<p>%s</p>', esc_html__( 'Store credentials securely. Values are masked in the UI.', 'brooklyn-ai-planner' ) );
			},
			self::PAGE_SLUG
		);

		foreach ( $this->fields() as $field ) {
			add_settings_field(
				$field['id'],
				$field['label'],
				array( $this, 'render_field' ),
				self::PAGE_SLUG,
				'batp_api_section',
				$field
			);
		}
	}

	/**
	 * @param array<string, string> $input
	 * @return array<string, string>
	 */
	public function sanitize_settings( array $input ): array {
		$output = $this->get_settings();

		foreach ( $this->fields() as $field ) {
			$key = $field['id'];
			if ( isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) {
				$output[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}

		return $output;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Brooklyn AI Planner', 'brooklyn-ai-planner' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'batp_settings_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array{id:string,label:string,description?:string} $field
	 */
	public function render_field( array $field ): void {
		$settings = $this->get_settings();
		$value    = $settings[ $field['id'] ] ?? '';
		$masked   = $value ? $this->mask_value( $value ) : '';

		printf(
			'<input type="password" id="%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text" autocomplete="off" />',
			esc_attr( $field['id'] ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $masked )
		);

		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
		}
	}

	public function add_help_tab(): void {
		$screen = get_current_screen();
		$screen->add_help_tab(
			array(
				'id'      => 'batp_help',
				'title'   => __( 'Environment Guidance', 'brooklyn-ai-planner' ),
				'content' => $this->help_content(),
			)
		);
	}

	private function help_content(): string {
		ob_start();
		require BATP_PLUGIN_PATH . 'admin/views/section-help.php';
		return ob_get_clean();
	}

	/**
	 * @return array<string, string>
	 */
	private function get_settings(): array {
		$value = get_option( self::OPTION_KEY, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function fields(): array {
		return array(
			array(
				'id'          => 'gemini_api_key',
				'label'       => __( 'Gemini API Key', 'brooklyn-ai-planner' ),
				'description' => __( 'Used for itinerary ordering and content generation.', 'brooklyn-ai-planner' ),
			),
			array(
				'id'          => 'pinecone_api_key',
				'label'       => __( 'Pinecone API Key', 'brooklyn-ai-planner' ),
				'description' => __( 'Used for K-Means and semantic search lookups.', 'brooklyn-ai-planner' ),
			),
			array(
				'id'          => 'supabase_service_key',
				'label'       => __( 'Supabase Service Key', 'brooklyn-ai-planner' ),
				'description' => __( 'Used for ingestion, analytics logging, and MBA rules.', 'brooklyn-ai-planner' ),
			),
			array(
				'id'          => 'maps_api_key',
				'label'       => __( 'Google Maps API Key', 'brooklyn-ai-planner' ),
				'description' => __( 'Used for maps rendering and distance calculations.', 'brooklyn-ai-planner' ),
			),
		);
	}

	private function mask_value( string $value ): string {
		if ( strlen( $value ) <= 4 ) {
			return str_repeat( '*', strlen( $value ) );
		}

		return str_repeat( '*', strlen( $value ) - 4 ) . substr( $value, -4 );
	}
}
