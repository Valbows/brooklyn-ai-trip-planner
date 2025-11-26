/* eslint-env browser */
/* eslint-disable no-console, no-alert */
/* global google */

const initItineraryForm = () => {
	const form = document.querySelector( '[data-batp-itinerary-form]' );
	if ( ! form ) {
		return;
	}

	// Selectors
	const resultsArea = document.getElementById( 'batp-results-area' );
	const listOutput = document.getElementById( 'batp-list-output' );
	const mapContainer = document.getElementById( 'batp-map-root' );
	const metaText = document.querySelector( '[data-batp-results-meta]' );
	const tabs = document.querySelectorAll( '.batp-tabs__btn' );

	const chips = Array.from(
		form.querySelectorAll( '.batp-form__chip input[type="checkbox"]' )
	);

	// 1. Chip Interactions
	chips.forEach( ( input ) => {
		input.addEventListener( 'change', () => {
			input
				.closest( '.batp-form__chip' )
				.classList.toggle( 'is-selected', input.checked );
		} );
	} );

	// 2. Tabs Interaction
	tabs.forEach( ( tab ) => {
		tab.addEventListener( 'click', () => {
			// Toggle active tab state
			tabs.forEach( ( t ) => t.classList.remove( 'is-active' ) );
			tab.classList.add( 'is-active' );

			// Toggle content view
			const targetId = tab.dataset.tab; // 'list' or 'map'
			document
				.querySelectorAll( '.batp-view-content' )
				.forEach( ( content ) => {
					content.classList.remove( 'is-active' );
				} );
			document
				.getElementById( `batp-view-${ targetId }` )
				.classList.add( 'is-active' );

			// If switching to map, resize trigger might be needed
			if ( targetId === 'map' && googleMap ) {
				setTimeout( () => {
					google.maps.event.trigger( googleMap, 'resize' );
				}, 100 );
			}
		} );
	} );

	// 3. Map Logic
	let googleMap = null;

	const waitForGoogleMaps = () => {
		return new Promise( ( resolve, reject ) => {
			let attempts = 0;
			const check = () => {
				attempts++;
				if (
					window.google &&
					window.google.maps &&
					window.google.maps.Map
				) {
					resolve();
				} else if ( attempts > 50 ) {
					// 5 seconds timeout
					reject(
						new Error( 'Timeout waiting for google.maps.Map' )
					);
				} else {
					setTimeout( check, 100 );
				}
			};
			check();
		} );
	};

	const loadGoogleMaps = ( apiKey ) => {
		return new Promise( ( resolve, reject ) => {
			if ( window.google && window.google.maps ) {
				resolve();
				return;
			}

			const existingScript = document.querySelector(
				`script[src*="maps.googleapis.com/maps/api/js"]`
			);
			if ( existingScript ) {
				resolve();
				return;
			}

			const script = document.createElement( 'script' );
			script.src = `https://maps.googleapis.com/maps/api/js?key=${ apiKey }&libraries=places,geometry,marker&v=weekly&loading=async`;
			script.async = true;
			script.defer = true;
			script.onload = () => resolve();
			script.onerror = ( err ) => reject( err );
			document.head.appendChild( script );
		} );
	};

	const initMap = async ( apiKey, locations = [] ) => {
		if ( ! mapContainer ) {
			return;
		}

		try {
			await loadGoogleMaps( apiKey );
			await waitForGoogleMaps();

			// Compatibility Layer
			let MapConstructor, AdvancedMarkerElement;
			let useLegacyMarkers = false;

			if ( typeof google.maps.importLibrary === 'function' ) {
				try {
					const { Map } = await google.maps.importLibrary( 'maps' );
					const { AdvancedMarkerElement: AME } =
						await google.maps.importLibrary( 'marker' );
					MapConstructor = Map;
					AdvancedMarkerElement = AME;
				} catch ( e ) {
					console.warn(
						'Modern map import failed, falling back to legacy',
						e
					);
					MapConstructor = google.maps.Map;
					useLegacyMarkers = true;
				}
			} else {
				MapConstructor = google.maps.Map;
				useLegacyMarkers = true;
			}

			if ( ! MapConstructor ) {
				throw new Error( 'google.maps.Map constructor is missing' );
			}

			const center =
				locations.length > 0
					? { lat: locations[ 0 ].lat, lng: locations[ 0 ].lng }
					: { lat: 40.6782, lng: -73.9442 }; // Default: Brooklyn

			googleMap = new MapConstructor( mapContainer, {
				center,
				zoom: 13,
				mapId: 'DEMO_MAP_ID',
				disableDefaultUI: false, // Allow controls for better UX in full view
				zoomControl: true,
				streetViewControl: false,
			} );

			if ( locations.length > 0 ) {
				const bounds = new google.maps.LatLngBounds();

				locations.forEach( ( loc, index ) => {
					const position = { lat: loc.lat, lng: loc.lng };

					if ( ! useLegacyMarkers && AdvancedMarkerElement ) {
						// Modern: Advanced Marker
						const markerContent = document.createElement( 'div' );
						markerContent.className = 'batp-map-marker';
						markerContent.innerHTML = `<span style="background:#FF5F3D; color:#fff; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; font-weight:bold; box-shadow:0 2px 4px rgba(0,0,0,0.2); font-size:14px;">${
							index + 1
						}</span>`;

						new AdvancedMarkerElement( {
							map: googleMap,
							position,
							content: markerContent,
							title: loc.title,
						} );
					} else {
						// Legacy: Standard Marker
						new google.maps.Marker( {
							map: googleMap,
							position,
							label: {
								text: ( index + 1 ).toString(),
								color: 'white',
								fontWeight: 'bold',
							},
							title: loc.title,
						} );
					}

					bounds.extend( position );
				} );

				googleMap.fitBounds( bounds );
			}
		} catch ( error ) {
			console.error( 'Map init error:', error );
			mapContainer.innerHTML = `<div style="padding:2rem; color:#d00;">Map failed to load.</div>`;
		}
	};

	const renderResults = ( data, apiKey ) => {
		const itineraryItems = data.itinerary?.items || [];
		if ( itineraryItems.length === 0 ) {
			alert( 'No results found. Try different filters.' );
			return;
		}

		// Reveal Results Area
		if ( resultsArea ) {
			resultsArea.classList.add( 'is-active' );
			resultsArea.scrollIntoView( { behavior: 'smooth' } );
		}

		// Update Meta Text
		if ( metaText ) {
			const duration = form.querySelector(
				'select[name="duration"]'
			).value;
			metaText.textContent = `${ itineraryItems.length } venues found • ${ duration } hours available`;
		}

		// Build Map Locations
		const locations = [];
		const candidateMap = new Map(
			( data.candidates || [] ).map( ( c ) => [ c.slug, c ] )
		);

		itineraryItems.forEach( ( item ) => {
			const candidate = candidateMap.get( item.slug );
			if (
				candidate &&
				candidate.data?.latitude &&
				candidate.data?.longitude
			) {
				locations.push( {
					lat: parseFloat( candidate.data.latitude ),
					lng: parseFloat( candidate.data.longitude ),
					title: item.title,
				} );
			}
		} );

		// Helper: Format Hours
		const getHoursString = ( hoursData ) => {
			if ( ! hoursData ) {
				return 'See website for hours';
			}
			// Simple check for today's day (0=Sun, 1=Mon...)
			const days = [
				'Sunday',
				'Monday',
				'Tuesday',
				'Wednesday',
				'Thursday',
				'Friday',
				'Saturday',
			];
			const dayName = days[ new Date().getDay() ];
			return hoursData[ dayName ] || 'Open today';
		};

		// Render List View (Cards)
		const renderList = ( items ) => {
			if ( ! listOutput ) {
				return;
			}

			listOutput.innerHTML = items
				.map( ( item, index ) => {
					const candidate = candidateMap.get( item.slug );
					const details = candidate?.data || {};
					const website = details.website || '#';
					const phone = details.phone_number || details.phone || '';
					const address = details.address || '';
					const vibe = details.vibe_summary || item.description || '';
					const hours = details.hours
						? getHoursString( details.hours )
						: 'Open today';

					// Directions URL
					let dirUrl = '#';
					if ( details.latitude && details.longitude ) {
						dirUrl = `https://www.google.com/maps/dir/?api=1&destination=${ details.latitude },${ details.longitude }`;
					} else if ( address ) {
						dirUrl = `https://www.google.com/maps/dir/?api=1&destination=${ encodeURIComponent(
							address
						) }`;
					} else {
						dirUrl = `https://www.google.com/maps/dir/?api=1&destination=${ encodeURIComponent(
							item.title + ', Brooklyn, NY'
						) }`;
					}

					return `
				<div class="batp-card" data-access='${ JSON.stringify(
					details.accessibility || []
				) }'>
					<div class="batp-card__header">
						<div>
							<span class="batp-card__status">Open Now</span>
							<h3 class="batp-card__title">${ index + 1 }. ${ item.title }</h3>
							<span class="batp-card__tag">Best Match</span>
						</div>
					</div>
					
					<p class="batp-card__description">${ vibe }</p>
					
					<div class="batp-card__details">
						${
							address
								? `<div><span class="dashicons dashicons-location"></span> ${ address }</div>`
								: ''
						}
						<div><span class="dashicons dashicons-clock"></span> ${ hours }</div>
						${
							phone
								? `<a href="tel:${ phone.replace(
										/[^0-9+]/g,
										''
								  ) }" class="batp-card__link-row" data-event-action="phone_click" data-venue-id="${
										details.id
								  }"><span class="dashicons dashicons-phone"></span> ${ phone }</a>`
								: ''
						}
					</div>

					<div class="batp-card__footer">
						<a href="${ dirUrl }" target="_blank" class="batp-card__btn-directions" data-event-action="directions_click" data-venue-id="${
							details.id
						}">
							<span class="dashicons dashicons-location-alt"></span> Directions
						</a>
						<div class="batp-card__links">
							${
								website !== '#'
									? `<a href="${ website }" target="_blank" data-event-action="website_click" data-venue-id="${
											details.id
									  }" data-event-meta='${ JSON.stringify( {
											url: website,
									  } ) }'><span class="dashicons dashicons-admin-site"></span> Website</a>`
									: ''
							}
						</div>
					</div>
				</div>
				`;
				} )
				.join( '' );
		};

		// Initial Render
		renderList( itineraryItems );

		// --- MODAL LOGIC ---
		const setupModal = ( modalId, triggerBtn ) => {
			const modal = document.getElementById( modalId );
			if ( ! modal || ! triggerBtn ) {
				return;
			}

			const open = () => {
				modal.classList.add( 'is-open' );
				modal.setAttribute( 'aria-hidden', 'false' );
			};

			const close = () => {
				modal.classList.remove( 'is-open' );
				modal.setAttribute( 'aria-hidden', 'true' );
			};

			triggerBtn.onclick = ( e ) => {
				e.preventDefault();
				open();
			};

			// Close triggers
			modal.querySelectorAll( '[data-modal-close]' ).forEach( ( el ) => {
				el.onclick = ( e ) => {
					e.preventDefault();
					close();
				};
			} );
		};

		// Share Modal
		const shareBtn = document.querySelector(
			'.batp-results__btn--primary'
		);
		setupModal( 'batp-share-modal', shareBtn );

		if ( shareBtn ) {
			// Populate Summary on click
			shareBtn.addEventListener( 'click', () => {
				const summaryList =
					document.getElementById( 'batp-summary-list' );
				if ( summaryList ) {
					summaryList.innerHTML = itineraryItems
						.map( ( item ) => `<li>${ item.title }</li>` )
						.join( '' );
				}
				// Update Link
				const linkInput = document.getElementById(
					'batp-share-link-input'
				);
				if ( linkInput ) {
					linkInput.value = window.location.href;
				} // Simple mock
			} );
		}

		// Copy Link Logic
		const copyBtn = document.getElementById( 'batp-btn-copy-link' );
		if ( copyBtn ) {
			copyBtn.onclick = () => {
				const input = document.getElementById(
					'batp-share-link-input'
				);
				input.select();
				navigator.clipboard.writeText( input.value );
				const original = copyBtn.innerText;
				copyBtn.innerText = 'Copied!';
				setTimeout( () => ( copyBtn.innerText = original ), 2000 );
			};
		}

		// Filter Modal
		const filterBtn = document.querySelector(
			'.batp-results__btn:nth-child(2)'
		);
		setupModal( 'batp-filter-modal', filterBtn );

		// Apply Filters Logic
		const applyBtn = document.getElementById( 'batp-apply-filters' );
		if ( applyBtn ) {
			applyBtn.onclick = () => {
				const modal = document.getElementById( 'batp-filter-modal' );

				// Get checked values
				const wheelchair = modal.querySelector(
					'input[name="access_wheelchair"]'
				).checked;
				const sensory = modal.querySelector(
					'input[name="access_sensory"]'
				).checked;
				const seating = modal.querySelector(
					'input[name="access_seating"]'
				).checked;

				// Filter items
				const cards = listOutput.querySelectorAll( '.batp-card' );
				cards.forEach( ( card ) => {
					const access = JSON.parse( card.dataset.access || '[]' );
					let visible = true;

					if ( wheelchair && ! access.includes( 'wheelchair' ) ) {
						visible = false;
					}
					if ( sensory && ! access.includes( 'sensory' ) ) {
						visible = false;
					}
					if ( seating && ! access.includes( 'seating' ) ) {
						visible = false;
					}

					card.style.display = visible ? 'flex' : 'none';
				} );

				// Close modal
				modal.classList.remove( 'is-open' );
			};
		}

		// Init Map
		if ( apiKey && locations.length > 0 ) {
			// Small delay to ensure container is visible/sized
			setTimeout( () => {
				initMap( apiKey, locations );
			}, 100 );
		}
	};

	// 5. Analytics Logic
	const trackEvent = async ( action, venueId, metadata = {} ) => {
		const nonce = form.dataset.nonce;
		const baseApiUrl = form.dataset.apiUrl; // .../v1/itinerary
		const eventsUrl = baseApiUrl.replace( '/itinerary', '/events' );

		if ( ! nonce || ! eventsUrl ) {
			return;
		}

		try {
			await fetch( eventsUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( {
					action_type: action,
					venue_id: venueId,
					metadata,
					nonce,
				} ),
			} );
		} catch ( err ) {
			// Silent fail
			console.warn( 'Analytics fail', err );
		}
	};

	// Event Delegation
	if ( listOutput ) {
		listOutput.addEventListener( 'click', ( e ) => {
			const target = e.target.closest( '[data-event-action]' );
			if ( target ) {
				const action = target.dataset.eventAction;
				const venueId = target.dataset.venueId;
				const meta = target.dataset.eventMeta
					? JSON.parse( target.dataset.eventMeta )
					: {};
				trackEvent( action, venueId, meta );
			}
		} );
	}

	// 4. Form Submission
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();

		const nonce = form.dataset.nonce;
		const restNonce = form.dataset.restNonce;
		const apiUrl = form.dataset.apiUrl;
		const apiKey = form.dataset.googleMapsKey;

		if ( ! nonce || ! apiUrl ) {
			return;
		}

		const formData = new FormData( form );
		const payload = {
			neighborhood: formData.get( 'neighborhood' ),
			interests: formData.getAll( 'interests[]' ),
			budget: formData.get( 'budget' ),
			duration: Number( formData.get( 'duration' ) ) * 60,
			nonce,
		};

		// UI State: Loading
		const submitBtn = form.querySelector( 'button[type="submit"]' );
		const originalBtnText = submitBtn.innerHTML;
		submitBtn.disabled = true;
		submitBtn.innerHTML = 'Generating Plan...';

		try {
			const response = await fetch( apiUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					...( restNonce ? { 'X-WP-Nonce': restNonce } : {} ),
				},
				body: JSON.stringify( payload ),
			} );

			const result = await response.json();

			if ( ! response.ok ) {
				throw new Error(
					result.message || 'Error generating itinerary'
				);
			}

			renderResults( result, apiKey );
		} catch ( error ) {
			console.error( 'BATP Error:', error );
			alert( 'Error: ' + error.message );
		} finally {
			submitBtn.disabled = false;
			submitBtn.innerHTML = originalBtnText;
		}
	} );
};

if ( document.readyState !== 'loading' ) {
	initItineraryForm();
} else {
	document.addEventListener( 'DOMContentLoaded', initItineraryForm );
}
