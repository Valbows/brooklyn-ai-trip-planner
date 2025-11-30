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

	// 0. Location Selection (Neighborhood + Geolocation)
	const neighborhoodSelect = document.getElementById(
		'batp-neighborhood-select'
	);
	const latInput = document.getElementById( 'batp-lat-input' );
	const lngInput = document.getElementById( 'batp-lng-input' );
	const locationStatus = document.getElementById( 'batp-location-status' );

	// Set initial coords from selected option
	const setLocationFromOption = ( option ) => {
		if ( option && option.dataset.lat && option.dataset.lng ) {
			latInput.value = option.dataset.lat;
			lngInput.value = option.dataset.lng;
			if ( locationStatus ) {
				locationStatus.textContent = '';
			}
		}
	};

	// Initialize with default selection
	if ( neighborhoodSelect ) {
		const selectedOption =
			neighborhoodSelect.options[ neighborhoodSelect.selectedIndex ];
		setLocationFromOption( selectedOption );

		neighborhoodSelect.addEventListener( 'change', ( e ) => {
			const option = e.target.options[ e.target.selectedIndex ];

			if ( option.value === 'current_location' ) {
				// Trigger geolocation
				if ( locationStatus ) {
					locationStatus.textContent = '📡 Getting location...';
				}

				if ( navigator.geolocation ) {
					navigator.geolocation.getCurrentPosition(
						( position ) => {
							latInput.value = position.coords.latitude;
							lngInput.value = position.coords.longitude;
							if ( locationStatus ) {
								locationStatus.textContent =
									'✅ Location found!';
							}
							console.log(
								'BATP Geolocation:',
								position.coords.latitude,
								position.coords.longitude
							);
						},
						( error ) => {
							console.error( 'Geolocation error:', error );
							if ( locationStatus ) {
								locationStatus.textContent =
									'❌ Location failed';
							}
							alert(
								'Could not get your location. Please select a neighborhood.'
							);
							neighborhoodSelect.value = 'Brooklyn Heights';
							setLocationFromOption(
								neighborhoodSelect.options[
									neighborhoodSelect.selectedIndex
								]
							);
						},
						{ enableHighAccuracy: true, timeout: 10000 }
					);
				} else {
					alert( 'Geolocation is not supported by your browser.' );
					neighborhoodSelect.value = 'Brooklyn Heights';
					setLocationFromOption(
						neighborhoodSelect.options[
							neighborhoodSelect.selectedIndex
						]
					);
				}
			} else {
				setLocationFromOption( option );
			}
		} );
	}

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

		// Build Map Locations and Candidate Map
		const locations = [];
		const candidateMap = new Map(
			( data.candidates || [] ).map( ( c ) => [ c.slug, c ] )
		);

		// Store directions URL from API response
		const multiStopDirectionsUrl = data.directions?.overview_url || '#';

		itineraryItems.forEach( ( item, idx ) => {
			const candidate = candidateMap.get( item.slug );
			const lat = candidate?.data?.latitude;
			const lng = candidate?.data?.longitude;

			// Debug: Log each item's location data
			console.log( `BATP Map: Item ${ idx + 1 } (${ item.slug }):`, {
				lat,
				lng,
				title: item.title,
			} );

			if ( lat && lng && lat !== 0 && lng !== 0 ) {
				locations.push( {
					lat: parseFloat( lat ),
					lng: parseFloat( lng ),
					title: item.title,
					placeId: item.slug, // For analytics
				} );
			} else {
				console.warn(
					`BATP Map: Missing coordinates for item ${ idx + 1 }: ${
						item.title
					}`
				);
			}
		} );

		console.log( 'BATP Map: Total locations for map:', locations.length );

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

		// Build multi-stop directions URL
		const buildMultiStopUrl = ( locs ) => {
			if ( locs.length === 0 ) {
				return '#';
			}
			const origin = `${ locs[ 0 ].lat },${ locs[ 0 ].lng }`;
			const destination = `${ locs[ locs.length - 1 ].lat },${
				locs[ locs.length - 1 ].lng
			}`;
			const waypoints = locs
				.slice( 1, -1 )
				.map( ( l ) => `${ l.lat },${ l.lng }` )
				.join( '|' );
			let url = `https://www.google.com/maps/dir/?api=1&origin=${ origin }&destination=${ destination }&travelmode=walking`;
			if ( waypoints ) {
				url += `&waypoints=${ waypoints }`;
			}
			return url;
		};

		// Use API-provided URL or build our own
		const fullRouteUrl =
			multiStopDirectionsUrl !== '#'
				? multiStopDirectionsUrl
				: buildMultiStopUrl( locations );

		// Helper: Generate description from venue data
		const generateDescription = ( details, itemDesc ) => {
			if ( itemDesc && itemDesc.length > 10 ) {
				return itemDesc;
			}
			if ( details.vibe_summary ) {
				return details.vibe_summary;
			}

			// Build from available data
			const parts = [];
			const rating = details.rating ? `${ details.rating }★` : '';
			const priceLevel = details.price_level
				? '$'.repeat( details.price_level )
				: '';
			const types =
				details.types
					?.slice( 0, 2 )
					.map( ( t ) => t.replace( /_/g, ' ' ) )
					.join( ', ' ) || '';

			if ( rating ) {
				parts.push( `Rated ${ rating }` );
			}
			if ( priceLevel ) {
				parts.push( priceLevel );
			}
			if ( types ) {
				parts.push( types );
			}

			return parts.length > 0
				? parts.join( ' • ' )
				: 'A local Brooklyn favorite.';
		};

		// State for editable itinerary
		let currentItems = [ ...itineraryItems ];

		// Render List View (Cards)
		const renderList = ( items ) => {
			if ( ! listOutput ) {
				return;
			}

			// Add multi-stop directions header
			const headerHtml = `
				<div class="batp-route-header">
					<a href="${ fullRouteUrl }" target="_blank" class="batp-route-btn" data-event-action="directions_click" data-place-id="full_route">
						<span class="dashicons dashicons-location-alt"></span> 
						<strong>Get Full Route Directions</strong>
						<span class="batp-route-btn__subtitle">Open all ${ items.length } stops in Google Maps</span>
					</a>
				</div>
			`;

			listOutput.innerHTML =
				headerHtml +
				items
					.map( ( item, index ) => {
						const candidate = candidateMap.get( item.slug );
						const details = candidate?.data || {};
						const website = details.website || '#';
						const phone =
							details.phone_number || details.phone || '';
						const address = details.address || '';
						const description = generateDescription(
							details,
							item.description
						);
						let hours = 'See hours on website';
						if ( details.hours ) {
							hours = getHoursString( details.hours );
						} else if ( details.opening_hours?.open_now ) {
							hours = 'Open now';
						}

						// Single venue directions URL (fallback)
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

						// Place ID for analytics (Google Places ID)
						const placeId = candidate?.place_id || item.slug || '';

						return `
				<div class="batp-card" data-slug="${
					item.slug
				}" data-access='${ JSON.stringify(
					details.accessibility || []
				) }'>
					<button class="batp-card__remove" data-remove-slug="${
						item.slug
					}" title="Remove from itinerary">×</button>
					<div class="batp-card__header">
						<div>
							<span class="batp-card__status">${
								details.opening_hours?.open_now !== false
									? 'Open Now'
									: 'Check Hours'
							}</span>
							<h3 class="batp-card__title">${ index + 1 }. ${ item.title }</h3>
							${
								details.rating
									? `<span class="batp-card__rating">⭐ ${ details.rating }</span>`
									: ''
							}
						</div>
					</div>
					
					<p class="batp-card__description">${ description }</p>
					
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
								  ) }" class="batp-card__link-row" data-event-action="phone_click" data-place-id="${ placeId }"><span class="dashicons dashicons-phone"></span> ${ phone }</a>`
								: ''
						}
					</div>

					<div class="batp-card__footer">
						<a href="${ dirUrl }" target="_blank" class="batp-card__btn-directions" data-event-action="directions_click" data-place-id="${ placeId }">
							<span class="dashicons dashicons-location-alt"></span> Directions
						</a>
						<div class="batp-card__links">
							${
								website !== '#'
									? `<a href="${ website }" target="_blank" data-event-action="website_click" data-place-id="${ placeId }" data-event-meta='${ JSON.stringify(
											{
												url: website,
											}
									  ) }'><span class="dashicons dashicons-admin-site"></span> Website</a>`
									: ''
							}
						</div>
					</div>
				</div>
				`;
					} )
					.join( '' );

			// Handle remove button clicks
			listOutput
				.querySelectorAll( '.batp-card__remove' )
				.forEach( ( btn ) => {
					btn.addEventListener( 'click', ( e ) => {
						e.preventDefault();
						const slugToRemove = btn.dataset.removeSlug;
						currentItems = currentItems.filter(
							( i ) => i.slug !== slugToRemove
						);
						renderList( currentItems );
						// Update meta text
						if ( metaText ) {
							const duration = form.querySelector(
								'select[name="duration"]'
							).value;
							metaText.textContent = `${ currentItems.length } venues found • ${ duration } hours available`;
						}
					} );
				} );
		};

		// Initial Render
		renderList( currentItems );

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
				trackEvent( 'share_copy_link', null, { method: 'copy' } );
			};
		}

		// Helper: Build itinerary text for sharing
		const buildShareText = () => {
			const title = 'My Brooklyn Adventure';
			const venueList = itineraryItems
				.map( ( item, i ) => `${ i + 1 }. ${ item.title }` )
				.join( '\n' );
			return `${ title }\n\n${ venueList }\n\nPlan yours at: ${ window.location.href }`;
		};

		// Download PDF - uses browser print dialog
		const pdfBtn = document.getElementById( 'batp-btn-download-pdf' );
		if ( pdfBtn ) {
			pdfBtn.onclick = () => {
				// Create printable content
				const printContent = `
					<!DOCTYPE html>
					<html>
					<head>
						<title>Brooklyn Itinerary</title>
						<style>
							body { font-family: 'Inter', -apple-system, sans-serif; padding: 40px; max-width: 800px; margin: 0 auto; }
							h1 { color: #8B4513; border-bottom: 2px solid #8B4513; padding-bottom: 10px; }
							.venue { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 8px; }
							.venue h2 { margin: 0 0 8px 0; color: #333; font-size: 18px; }
							.venue p { margin: 4px 0; color: #666; font-size: 14px; }
							.footer { margin-top: 40px; text-align: center; color: #999; font-size: 12px; }
						</style>
					</head>
					<body>
						<h1>My Brooklyn Adventure</h1>
						<p>Generated on ${ new Date().toLocaleDateString() }</p>
						${ itineraryItems
							.map(
								( item, i ) => `
							<div class="venue">
								<h2>${ i + 1 }. ${ item.title }</h2>
								<p>${ item.address || '' }</p>
								${ item.phone ? `<p>Phone: ${ item.phone }</p>` : '' }
								${ item.website ? `<p>Website: ${ item.website }</p>` : '' }
							</div>
						`
							)
							.join( '' ) }
						<div class="footer">
							<p>Created with Brooklyn AI Trip Planner</p>
							<p>${ window.location.origin }</p>
						</div>
					</body>
					</html>
				`;

				const printWindow = window.open( '', '_blank' );
				printWindow.document.write( printContent );
				printWindow.document.close();
				printWindow.print();
				trackEvent( 'share_download_pdf', null, {
					venues: itineraryItems.length,
				} );
			};
		}

		// Add to Calendar (ICS)
		const calBtn = document.getElementById( 'batp-btn-add-calendar' );
		if ( calBtn ) {
			calBtn.onclick = () => {
				const now = new Date();
				const tomorrow = new Date( now );
				tomorrow.setDate( tomorrow.getDate() + 1 );
				tomorrow.setHours( 10, 0, 0, 0 );

				const formatDate = ( d ) =>
					d.toISOString().replace( /[-:]/g, '' ).split( '.' )[ 0 ] +
					'Z';

				const venues = itineraryItems
					.map( ( v ) => v.title )
					.join( ', ' );
				const description = `Brooklyn Adventure:\\n\\n${ itineraryItems
					.map(
						( v, i ) =>
							`${ i + 1 }. ${ v.title }${
								v.address ? ' - ' + v.address : ''
							}`
					)
					.join( '\\n' ) }`;

				const icsContent = [
					'BEGIN:VCALENDAR',
					'VERSION:2.0',
					'PRODID:-//Brooklyn AI Trip Planner//EN',
					'BEGIN:VEVENT',
					`UID:${ Date.now() }@visitbrooklynnyc`,
					`DTSTAMP:${ formatDate( now ) }`,
					`DTSTART:${ formatDate( tomorrow ) }`,
					`DTEND:${ formatDate(
						new Date( tomorrow.getTime() + 4 * 60 * 60 * 1000 )
					) }`,
					`SUMMARY:Brooklyn Adventure`,
					`DESCRIPTION:${ description }`,
					`LOCATION:${ venues }`,
					'END:VEVENT',
					'END:VCALENDAR',
				].join( '\r\n' );

				const blob = new Blob( [ icsContent ], {
					type: 'text/calendar;charset=utf-8',
				} );
				const link = document.createElement( 'a' );
				link.href = URL.createObjectURL( blob );
				link.download = 'brooklyn-itinerary.ics';
				link.click();
				URL.revokeObjectURL( link.href );
				trackEvent( 'share_add_calendar', null, {
					venues: itineraryItems.length,
				} );
			};
		}

		// Share via Email
		const emailBtn = document.getElementById( 'batp-btn-share-email' );
		if ( emailBtn ) {
			emailBtn.onclick = () => {
				const subject = encodeURIComponent(
					'Check out my Brooklyn itinerary!'
				);
				const body = encodeURIComponent( buildShareText() );
				window.open(
					`mailto:?subject=${ subject }&body=${ body }`,
					'_self'
				);
				trackEvent( 'share_email', null, {
					venues: itineraryItems.length,
				} );
			};
		}

		// Share via SMS
		const smsBtn = document.getElementById( 'batp-btn-share-sms' );
		if ( smsBtn ) {
			smsBtn.onclick = () => {
				const text = encodeURIComponent( buildShareText() );
				// Use sms: protocol (works on mobile)
				window.open( `sms:?body=${ text }`, '_self' );
				trackEvent( 'share_sms', null, {
					venues: itineraryItems.length,
				} );
			};
		}

		// Social Media Sharing
		const shareUrl = encodeURIComponent( window.location.href );
		const shareText = encodeURIComponent(
			`I just planned a Brooklyn adventure with ${ itineraryItems.length } stops!`
		);

		// Facebook
		const fbBtn = document.getElementById( 'batp-btn-share-facebook' );
		if ( fbBtn ) {
			fbBtn.onclick = () => {
				window.open(
					`https://www.facebook.com/sharer/sharer.php?u=${ shareUrl }`,
					'_blank',
					'width=600,height=400'
				);
				trackEvent( 'share_social', null, { platform: 'facebook' } );
			};
		}

		// X (Twitter)
		const xBtn = document.getElementById( 'batp-btn-share-x' );
		if ( xBtn ) {
			xBtn.onclick = () => {
				window.open(
					`https://twitter.com/intent/tweet?url=${ shareUrl }&text=${ shareText }`,
					'_blank',
					'width=600,height=400'
				);
				trackEvent( 'share_social', null, { platform: 'x' } );
			};
		}

		// WhatsApp
		const waBtn = document.getElementById( 'batp-btn-share-whatsapp' );
		if ( waBtn ) {
			waBtn.onclick = () => {
				window.open(
					`https://wa.me/?text=${ shareText }%20${ shareUrl }`,
					'_blank'
				);
				trackEvent( 'share_social', null, { platform: 'whatsapp' } );
			};
		}

		// LinkedIn
		const liBtn = document.getElementById( 'batp-btn-share-linkedin' );
		if ( liBtn ) {
			liBtn.onclick = () => {
				window.open(
					`https://www.linkedin.com/sharing/share-offsite/?url=${ shareUrl }`,
					'_blank',
					'width=600,height=400'
				);
				trackEvent( 'share_social', null, { platform: 'linkedin' } );
			};
		}

		// Instagram - Opens Instagram app/web (no direct share API, so we copy to clipboard and open)
		const igBtn = document.getElementById( 'batp-btn-share-instagram' );
		if ( igBtn ) {
			igBtn.onclick = () => {
				const text = buildShareText();
				navigator.clipboard.writeText( text ).then( () => {
					alert(
						'Itinerary copied to clipboard! Opening Instagram - paste in your story or post.'
					);
					window.open( 'https://www.instagram.com/', '_blank' );
				} );
				trackEvent( 'share_social', null, { platform: 'instagram' } );
			};
		}

		// TikTok - Similar to Instagram, no direct share API
		const ttBtn = document.getElementById( 'batp-btn-share-tiktok' );
		if ( ttBtn ) {
			ttBtn.onclick = () => {
				const text = buildShareText();
				navigator.clipboard.writeText( text ).then( () => {
					alert(
						'Itinerary copied to clipboard! Opening TikTok - paste in your video description.'
					);
					window.open( 'https://www.tiktok.com/', '_blank' );
				} );
				trackEvent( 'share_social', null, { platform: 'tiktok' } );
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
	const trackEvent = async ( action, placeId, metadata = {} ) => {
		const nonce = form.dataset.nonce;
		const restNonce = form.dataset.restNonce;
		const baseApiUrl = form.dataset.apiUrl; // .../v1/itinerary
		const eventsUrl = baseApiUrl.replace( '/itinerary', '/events' );

		console.log( 'BATP trackEvent:', action, placeId, metadata );

		if ( ! nonce || ! eventsUrl ) {
			console.warn( 'BATP trackEvent: Missing nonce or eventsUrl' );
			return;
		}

		// Default metadata to avoid empty object issues
		const finalMeta = { ...metadata, source: 'web_client' };

		try {
			const response = await fetch( eventsUrl, {
				method: 'POST',
				keepalive: true,
				headers: {
					'Content-Type': 'application/json',
					...( restNonce ? { 'X-WP-Nonce': restNonce } : {} ),
				},
				body: JSON.stringify( {
					action_type: action,
					place_id: placeId, // Use place_id for Google Places
					metadata: finalMeta,
					nonce,
				} ),
			} );
			console.log(
				'BATP trackEvent response:',
				response.status,
				response.ok
			);
		} catch ( err ) {
			console.warn( 'BATP Analytics fail:', err );
		}
	};

	// Event Delegation - Track clicks on website/directions
	if ( listOutput ) {
		listOutput.addEventListener( 'click', ( e ) => {
			const target = e.target.closest( '[data-event-action]' );
			if ( target ) {
				const action = target.dataset.eventAction;
				const placeId = target.dataset.placeId || ''; // Use place_id for Google Places
				const meta = target.dataset.eventMeta
					? JSON.parse( target.dataset.eventMeta )
					: {};

				console.log( 'BATP Analytics:', action, placeId, meta );
				trackEvent( action, placeId, meta );
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

		// Get lat/lng from hidden inputs (populated by neighborhood selection)
		const lat = parseFloat( formData.get( 'latitude' ) ) || 40.6782;
		const lng = parseFloat( formData.get( 'longitude' ) ) || -73.9442;
		const selectedNeighborhood = formData.get( 'neighborhood' );

		console.log( 'BATP Form: Submitting with location:', {
			neighborhood: selectedNeighborhood,
			lat,
			lng,
		} );

		const payload = {
			neighborhood: selectedNeighborhood,
			lat,
			lng,
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

			// Note: itinerary_generated is logged by backend Engine, not frontend
			// to avoid double-counting

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
