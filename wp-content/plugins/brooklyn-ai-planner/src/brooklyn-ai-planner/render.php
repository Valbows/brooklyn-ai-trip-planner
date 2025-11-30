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
						<div class="batp-location-wrapper">
							<select name="neighborhood" id="batp-neighborhood-select" required>
								<option value="">Select a neighborhood...</option>
								<option value="current_location">📍 Use My Current Location</option>
								<optgroup label="Brooklyn Neighborhoods">
									<option value="Williamsburg" data-lat="40.7081" data-lng="-73.9571">Williamsburg</option>
									<option value="DUMBO" data-lat="40.7033" data-lng="-73.9881">DUMBO</option>
									<option value="Park Slope" data-lat="40.6710" data-lng="-73.9814">Park Slope</option>
									<option value="Brooklyn Heights" data-lat="40.6960" data-lng="-73.9936" selected>Brooklyn Heights</option>
									<option value="Bedford-Stuyvesant" data-lat="40.6872" data-lng="-73.9418">Bedford-Stuyvesant</option>
									<option value="Crown Heights" data-lat="40.6694" data-lng="-73.9422">Crown Heights</option>
									<option value="Bushwick" data-lat="40.6944" data-lng="-73.9213">Bushwick</option>
									<option value="Greenpoint" data-lat="40.7304" data-lng="-73.9514">Greenpoint</option>
									<option value="Fort Greene" data-lat="40.6920" data-lng="-73.9740">Fort Greene</option>
									<option value="Cobble Hill" data-lat="40.6860" data-lng="-73.9969">Cobble Hill</option>
									<option value="Carroll Gardens" data-lat="40.6795" data-lng="-73.9991">Carroll Gardens</option>
									<option value="Red Hook" data-lat="40.6734" data-lng="-74.0086">Red Hook</option>
									<option value="Prospect Heights" data-lat="40.6775" data-lng="-73.9692">Prospect Heights</option>
									<option value="Clinton Hill" data-lat="40.6890" data-lng="-73.9660">Clinton Hill</option>
									<option value="Boerum Hill" data-lat="40.6850" data-lng="-73.9840">Boerum Hill</option>
									<option value="Flatbush" data-lat="40.6501" data-lng="-73.9496">Flatbush</option>
									<option value="Bay Ridge" data-lat="40.6340" data-lng="-74.0287">Bay Ridge</option>
									<option value="Sunset Park" data-lat="40.6453" data-lng="-74.0128">Sunset Park</option>
								</optgroup>
							</select>
							<span class="batp-location-status" id="batp-location-status"></span>
						</div>
						<input type="hidden" name="latitude" id="batp-lat-input" />
						<input type="hidden" name="longitude" id="batp-lng-input" />
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
					<button class="batp-share-btn" id="batp-btn-download-pdf">
						<span class="dashicons dashicons-pdf"></span>
						<div class="batp-share-btn__text">
							<strong>Download PDF</strong>
							<span>Save as a portable document</span>
						</div>
					</button>
					<button class="batp-share-btn" id="batp-btn-add-calendar">
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
					<button class="batp-share-link" id="batp-btn-share-email"><span class="dashicons dashicons-email"></span> Share via Email</button>
					<button class="batp-share-link" id="batp-btn-share-sms"><span class="dashicons dashicons-smartphone"></span> Share via SMS</button>
				</div>

				<h4>Share on Social Media</h4>
				<div class="batp-social-share">
					<button class="batp-social-btn batp-social-btn--facebook" id="batp-btn-share-facebook" title="Share on Facebook">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
					</button>
					<button class="batp-social-btn batp-social-btn--x" id="batp-btn-share-x" title="Share on X">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
					</button>
					<button class="batp-social-btn batp-social-btn--whatsapp" id="batp-btn-share-whatsapp" title="Share on WhatsApp">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
					</button>
					<button class="batp-social-btn batp-social-btn--linkedin" id="batp-btn-share-linkedin" title="Share on LinkedIn">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
					</button>
					<button class="batp-social-btn batp-social-btn--instagram" id="batp-btn-share-instagram" title="Share on Instagram">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
					</button>
					<button class="batp-social-btn batp-social-btn--tiktok" id="batp-btn-share-tiktok" title="Share on TikTok">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>
					</button>
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

	<!-- REPLACE ITEM MODAL -->
	<div class="batp-modal" id="batp-replace-modal" aria-hidden="true">
		<div class="batp-modal__overlay" data-modal-close></div>
		<div class="batp-modal__content batp-modal__content--sm">
			<div class="batp-modal__header">
				<h3>Replace Venue</h3>
				<button class="batp-modal__close" data-modal-close>&times;</button>
			</div>
			<div class="batp-modal__body">
				<p class="batp-modal__subtitle">Select a different venue to replace <strong id="batp-replace-current-name"></strong></p>
				
				<div class="batp-replace-list" id="batp-replace-list">
					<!-- Populated via JS -->
				</div>
				
				<div class="batp-modal__footer">
					<button class="button" data-modal-close>Cancel</button>
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
