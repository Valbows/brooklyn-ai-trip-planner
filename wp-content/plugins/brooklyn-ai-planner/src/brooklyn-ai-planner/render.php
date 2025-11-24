<?php
/**
 * Server render for the Brooklyn AI itinerary request block.
 *
 * @var array   $attributes
 * @var string  $content
 * @var WP_Block $block
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$defaults = array(
	'heading'        => 'Plan your perfect Brooklyn day',
	'subheading'     => 'Tell us what you love and we will craft a Gemini-powered itinerary.',
	'ctaLabel'       => 'Generate itinerary',
	'highlightColor' => '#ff4f5e',
);

$attributes = wp_parse_args( $attributes, $defaults );
$heading        = sanitize_text_field( $attributes['heading'] );
$subheading     = sanitize_text_field( $attributes['subheading'] );
$cta            = sanitize_text_field( $attributes['ctaLabel'] );
$highlight_color = sanitize_hex_color( $attributes['highlightColor'] ) ?: $defaults['highlightColor'];

$interests = array(
	'food'      => __( 'Foodie vibes', 'brooklyn-ai-planner' ),
	'art'       => __( 'Art & culture', 'brooklyn-ai-planner' ),
	'parks'     => __( 'Parks & outdoors', 'brooklyn-ai-planner' ),
	'nightlife' => __( 'Nightlife', 'brooklyn-ai-planner' ),
	'family'    => __( 'Family-friendly', 'brooklyn-ai-planner' ),
);

$accessibility_options = array(
	'wheelchair' => __( 'Wheelchair accessible', 'brooklyn-ai-planner' ),
	'sensory'    => __( 'Sensory-friendly', 'brooklyn-ai-planner' ),
	'seating'    => __( 'Ample seating', 'brooklyn-ai-planner' ),
);

$nonce   = wp_create_nonce( 'batp_generate_itinerary' );
$api_url = rest_url( 'brooklyn-ai/v1/itinerary' );
$maps_key = \BrooklynAI\Plugin::instance()->get_maps_api_key();

$wrapper_attrs = get_block_wrapper_attributes( array(
	'class' => 'batp-itinerary-block',
	'style' => sprintf( '--batp-highlight-color: %1$s;', esc_attr( $highlight_color ) ),
) );
?>

<section <?php echo $wrapper_attrs; ?>>
	<header class="batp-itinerary-block__header">
		<h2 class="batp-itinerary-block__heading"><?php echo esc_html( $heading ); ?></h2>
		<p class="batp-itinerary-block__subheading"><?php echo esc_html( $subheading ); ?></p>
	</header>
	<div class="batp-itinerary-layout">
		<form 
			class="batp-itinerary-form" 
			data-batp-itinerary-form 
			data-state="idle" 
			data-nonce="<?php echo esc_attr( $nonce ); ?>" 
			data-api-url="<?php echo esc_url( $api_url ); ?>"
			data-google-maps-key="<?php echo esc_attr( $maps_key ); ?>"
		>
			<label class="batp-itinerary-form__field">
				<span class="batp-itinerary-form__label"><?php esc_html_e( 'Neighborhood or starting point', 'brooklyn-ai-planner' ); ?></span>
				<div style="display: flex; gap: 0.5rem;">
					<input type="text" name="neighborhood" placeholder="e.g., Williamsburg" required style="flex:1;" />
					<button type="button" data-batp-geo-trigger title="<?php esc_attr_e( 'Use my location', 'brooklyn-ai-planner' ); ?>" style="padding: 0 1rem; border: 1px solid #E0DCD5; border-radius: 12px; background: #fff; cursor: pointer;">
						<span class="dashicons dashicons-location" style="color: var(--batp-highlight-color);"></span>
					</button>
				</div>
				<input type="hidden" name="latitude" />
				<input type="hidden" name="longitude" />
			</label>
			
			<div class="batp-itinerary-form__field">
				<span class="batp-itinerary-form__label"><?php esc_html_e( 'What vibes are you craving?', 'brooklyn-ai-planner' ); ?></span>
				<div class="batp-itinerary-chips">
					<?php foreach ( $interests as $slug => $label ) : ?>
					<label class="batp-itinerary-chip">
						<input type="checkbox" name="interests[]" value="<?php echo esc_attr( $slug ); ?>" />
						<span><?php echo esc_html( $label ); ?></span>
					</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
				<label class="batp-itinerary-form__field">
					<span class="batp-itinerary-form__label"><?php esc_html_e( 'Budget', 'brooklyn-ai-planner' ); ?></span>
					<select name="budget" style="width: 100%; padding: 0.85rem; border: 1px solid #E0DCD5; border-radius: 12px; background: #fff;">
						<option value="low"><?php esc_html_e( '$ - Budget friendly', 'brooklyn-ai-planner' ); ?></option>
						<option value="medium" selected><?php esc_html_e( '$$ - Moderate', 'brooklyn-ai-planner' ); ?></option>
						<option value="high"><?php esc_html_e( '$$$ - Treat yourself', 'brooklyn-ai-planner' ); ?></option>
					</select>
				</label>

				<label class="batp-itinerary-form__field">
					<span class="batp-itinerary-form__label"><?php esc_html_e( 'Duration', 'brooklyn-ai-planner' ); ?></span>
					<div style="display: flex; align-items: center; gap: 1rem;">
						<input type="range" name="duration" min="2" max="8" step="1" value="4" data-batp-duration style="flex:1;" />
						<span class="batp-itinerary-form__range-value" data-batp-duration-output>4h</span>
					</div>
				</label>
			</div>

			<div class="batp-itinerary-form__field">
				<details style="border: 1px solid #E0DCD5; border-radius: 12px; padding: 0.5rem 1rem; background: #fff;">
					<summary style="cursor: pointer; font-weight: 600; color: #555; font-size: 0.9rem;"><?php esc_html_e( 'Accessibility & preferences', 'brooklyn-ai-planner' ); ?></summary>
					<div style="margin-top: 1rem; display: flex; flex-direction: column; gap: 0.5rem;">
						<?php foreach ( $accessibility_options as $slug => $label ) : ?>
						<label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;">
							<input type="checkbox" name="accessibility_preferences[]" value="<?php echo esc_attr( $slug ); ?>" />
							<?php echo esc_html( $label ); ?>
						</label>
						<?php endforeach; ?>
					</div>
				</details>
			</div>

			<div class="batp-itinerary-form__footer">
				<button type="submit" class="batp-itinerary-block__cta">
					<?php echo esc_html( $cta ); ?>
				</button>
				<p class="batp-itinerary-form__meta"><?php esc_html_e( 'Powered by Gemini · No PII stored', 'brooklyn-ai-planner' ); ?></p>
			</div>
		</form>
		<div class="batp-itinerary-map" data-batp-map-placeholder>
			<div class="batp-itinerary-map__placeholder">
				<p class="batp-itinerary-map__eyebrow"><?php esc_html_e( 'Map preview', 'brooklyn-ai-planner' ); ?></p>
				<p class="batp-itinerary-map__copy"><?php esc_html_e( 'After you generate an itinerary, we will highlight your stops across Brooklyn here.', 'brooklyn-ai-planner' ); ?></p>
			</div>
		</div>
	</div>
</section>
