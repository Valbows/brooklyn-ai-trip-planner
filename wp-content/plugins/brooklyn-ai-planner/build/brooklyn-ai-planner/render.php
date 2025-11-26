<?php
/**
 * Server render for the Brooklyn AI itinerary request block.
 * New UI Layout - Horizontal Design
 *
 * @var array   $attributes
 * @var string  $content
 * @var WP_Block $block
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$defaults = array(
	'heading'        => 'Brooklyn AI Trip Planner',
	'subheading'     => 'Discover Brooklyn\'s best spots with AI-powered recommendations',
	'ctaLabel'       => 'Plan My Trip',
	'highlightColor' => '#FF5F3D',
);

$attributes = wp_parse_args( $attributes, $defaults );
$heading        = sanitize_text_field( $attributes['heading'] );
$subheading     = sanitize_text_field( $attributes['subheading'] );
$cta            = sanitize_text_field( $attributes['ctaLabel'] );
$highlight_color = sanitize_hex_color( $attributes['highlightColor'] ) ?: $defaults['highlightColor'];

// Icons for chips (using emoji as fallback for simplicity in PHP render, or SVG)
$interests = array(
	'art'           => '🎨 Art',
	'food'          => '🍕 Food',
	'parks'         => '🌳 Parks',
	'shopping'      => '🛍️ Shopping',
	'nightlife'     => '🍸 Nightlife',
	'home_hobby'    => '🏠 Home & Hobby',
	'services'      => '💼 Services',
	'coffee'        => '☕ Coffee Shops',
	'drinks'        => '🍹 Drinks',
	'entertainment' => '🎵 Entertainment',
);

$nonce       = wp_create_nonce( 'batp_generate_itinerary' );
$rest_nonce  = wp_create_nonce( 'wp_rest' );
$api_url = rest_url( 'brooklyn-ai/v1/itinerary' );
$maps_key = \BrooklynAI\Plugin::instance()->get_maps_api_key();

$wrapper_attrs = get_block_wrapper_attributes( array(
	'class' => 'batp-container',
	'style' => sprintf( '--batp-highlight-color: %1$s;', esc_attr( $highlight_color ) ),
) );
?>

<div <?php echo $wrapper_attrs; ?>>
	
	<!-- SEARCH PANEL -->
	<div class="batp-search-panel">
		<div class="batp-search-panel__header">
			<h1 class="batp-search-panel__title"><?php echo esc_html( $heading ); ?></h1>
			<p class="batp-search-panel__subtitle"><?php echo esc_html( $subheading ); ?></p>
		</div>
		
		<div class="batp-search-panel__body">
			<form 
				class="batp-form" 
				data-batp-itinerary-form 
				data-state="idle" 
				data-nonce="<?php echo esc_attr( $nonce ); ?>" 
				data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
				data-api-url="<?php echo esc_url( $api_url ); ?>"
				data-google-maps-key="<?php echo esc_attr( $maps_key ); ?>"
			>
				<!-- Interests -->
				<div class="batp-form__section">
					<span class="batp-form__section-label">What interests you?</span>
					<div class="batp-form__chips">
						<?php foreach ( $interests as $slug => $label ) : ?>
						<label class="batp-form__chip <?php echo 'drinks' === $slug ? 'is-selected' : ''; // Demo state ?>">
							<input type="checkbox" name="interests[]" value="<?php echo esc_attr( $slug ); ?>" <?php echo 'drinks' === $slug ? 'checked' : ''; ?> />
							<?php echo esc_html( $label ); ?>
						</label>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Inputs Row -->
				<div class="batp-form__row">
					<!-- Location -->
					<div class="batp-form__input-group batp-form__input-group--location">
						<span class="batp-form__section-label">Your Location</span>
						<input type="text" name="neighborhood" placeholder="e.g., Brooklyn Heights, or use current location" value="Brooklyn Heights" required />
						<input type="hidden" name="latitude" />
						<input type="hidden" name="longitude" />
						<!-- Geolocation trigger could be added back here as an icon action -->
					</div>

					<!-- Time -->
					<div class="batp-form__input-group batp-form__input-group--time">
						<span class="batp-form__section-label">Available Time</span>
						<select name="duration">
							<option value="2">2 hours</option>
							<option value="3" selected>3 hours</option>
							<option value="4">4 hours</option>
							<option value="5">5 hours</option>
							<option value="6">6 hours</option>
							<option value="8">Full Day</option>
						</select>
					</div>
				</div>

				<!-- Submit -->
				<button type="submit" class="batp-form__submit">
					<span class="dashicons dashicons-search" style="font-size:1.2em; width:auto; height:auto;"></span> 
					<?php echo esc_html( $cta ); ?>
				</button>
				
				<!-- Hidden Fields for Default Logic -->
				<input type="hidden" name="budget" value="medium" /> 
			</form>
		</div>
	</div>

	<!-- RESULTS AREA -->
	<div class="batp-results" id="batp-results-area">
		<div class="batp-results__header">
			<div class="batp-results__title-group">
				<h2>Your Personalized Itinerary</h2>
				<div class="batp-results__meta" data-batp-results-meta>3 venues found • 3 hours available</div>
			</div>
			
			<div class="batp-results__actions">
				<button class="batp-results__btn batp-results__btn--primary">
					<span class="dashicons dashicons-share"></span> Share & Export
				</button>
				<button class="batp-results__btn">
					<span class="dashicons dashicons-filter"></span> Filters
				</button>
				<button class="batp-results__btn" onclick="document.querySelector('.batp-form').scrollIntoView({behavior:'smooth'})">
					New Search
				</button>
			</div>
		</div>

		<!-- TABS -->
		<div class="batp-tabs">
			<button class="batp-tabs__btn is-active" data-tab="list">
				<span class="dashicons dashicons-list-view"></span> List View
			</button>
			<button class="batp-tabs__btn" data-tab="map">
				<span class="dashicons dashicons-location"></span> Map View
			</button>
		</div>

		<!-- LIST CONTENT -->
		<div class="batp-view-content is-active" id="batp-view-list">
			<div class="batp-scroll-container">
				<div class="batp-list-grid" id="batp-list-output">
					<!-- Items injected via JS -->
				</div>
			</div>
		</div>

		<!-- MAP CONTENT -->
		<div class="batp-view-content" id="batp-view-map">
			<div id="batp-map-root"></div>
		</div>
	</div>

	<!-- SHARE MODAL -->
	<div class="batp-modal" id="batp-share-modal" aria-hidden="true">
		<div class="batp-modal__overlay" data-modal-close></div>
		<div class="batp-modal__content">
			<div class="batp-modal__header">
				<h3>Share & Export Your Itinerary</h3>
				<button class="batp-modal__close" data-modal-close>&times;</button>
			</div>
			<div class="batp-modal__body">
				<p class="batp-modal__subtitle">Save your Brooklyn adventure or share it with friends</p>
				
				<h4>Download</h4>
				<div class="batp-share-grid">
					<button class="batp-share-btn">
						<span class="dashicons dashicons-pdf"></span>
						<div class="batp-share-btn__text">
							<strong>Download PDF</strong>
							<span>Save as a portable document</span>
						</div>
					</button>
					<button class="batp-share-btn">
						<span class="dashicons dashicons-calendar"></span>
						<div class="batp-share-btn__text">
							<strong>Add to Calendar</strong>
							<span>Export events to iCal</span>
						</div>
					</button>
				</div>

				<h4>Share Link</h4>
				<p>Copy this link to share your itinerary</p>
				<div class="batp-copy-row">
					<input type="text" readonly value="Click 'Copy Link' to generate" class="batp-copy-input" id="batp-share-link-input">
					<button class="batp-btn-copy" id="batp-btn-copy-link">Copy Link</button>
				</div>
				
				<div class="batp-share-actions">
					<button class="batp-share-link"><span class="dashicons dashicons-email"></span> Share via Email</button>
					<button class="batp-share-link"><span class="dashicons dashicons-smartphone"></span> Share via SMS</button>
				</div>

				<div class="batp-itinerary-summary-box">
					<h4>Itinerary Summary</h4>
					<ul class="batp-summary-list" id="batp-summary-list">
						<!-- Populated via JS -->
					</ul>
				</div>
			</div>
		</div>
	</div>

	<!-- FILTER MODAL -->
	<div class="batp-modal" id="batp-filter-modal" aria-hidden="true">
		<div class="batp-modal__overlay" data-modal-close></div>
		<div class="batp-modal__content batp-modal__content--sm">
			<div class="batp-modal__header">
				<h3>Filter Results</h3>
				<button class="batp-modal__close" data-modal-close>&times;</button>
			</div>
			<div class="batp-modal__body">
				<h4>Accessibility</h4>
				<div class="batp-filter-group">
					<label class="batp-checkbox-row">
						<input type="checkbox" name="access_wheelchair"> 
						<span>Wheelchair Accessible</span>
					</label>
					<label class="batp-checkbox-row">
						<input type="checkbox" name="access_sensory"> 
						<span>Sensory Friendly</span>
					</label>
					<label class="batp-checkbox-row">
						<input type="checkbox" name="access_seating"> 
						<span>Seating Available</span>
					</label>
				</div>
				
				<div class="batp-modal__footer">
					<button class="batp-btn-primary batp-btn-full" id="batp-apply-filters">Apply Filters</button>
				</div>
			</div>
		</div>
	</div>

</div>
