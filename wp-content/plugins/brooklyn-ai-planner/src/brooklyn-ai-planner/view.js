/**
 * Frontend interactions for the Brooklyn AI Itinerary Block.
 */
import { Loader } from '@googlemaps/js-api-loader';

const initItineraryForm = () => {
	const form = document.querySelector( '[data-batp-itinerary-form]' );
	if ( ! form ) {
		return;
	}

	const durationInput = form.querySelector( '[data-batp-duration]' );
	const durationOutput = form.querySelector( '[data-batp-duration-output]' );
	const mapContainer = document.querySelector( '[data-batp-map-placeholder]' );
	const geoTrigger = form.querySelector( '[data-batp-geo-trigger]' );
	const neighborhoodInput = form.querySelector( 'input[name="neighborhood"]' );
	const latInput = form.querySelector( 'input[name="latitude"]' );
	const lngInput = form.querySelector( 'input[name="longitude"]' );
	const chips = Array.from(
		form.querySelectorAll( '.batp-itinerary-chip input[type="checkbox"]' )
	);

	// 1. Input Interactions
	if ( durationInput && durationOutput ) {
		const syncDuration = () => {
			durationOutput.textContent = `${ durationInput.value }h`;
		};
		syncDuration();
		durationInput.addEventListener( 'input', syncDuration );
	}

	// Geolocation
	if ( geoTrigger && navigator.geolocation ) {
		geoTrigger.addEventListener( 'click', () => {
			geoTrigger.disabled = true;
			geoTrigger.innerHTML = '<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span>';
			
			navigator.geolocation.getCurrentPosition( 
				( position ) => {
					const lat = position.coords.latitude;
					const lng = position.coords.longitude;
					
					if ( latInput ) latInput.value = lat;
					if ( lngInput ) lngInput.value = lng;
					if ( neighborhoodInput ) neighborhoodInput.value = 'Current Location';
					
					geoTrigger.innerHTML = '<span class="dashicons dashicons-yes" style="color: green;"></span>';
					setTimeout( () => {
						geoTrigger.disabled = false;
						geoTrigger.innerHTML = '<span class="dashicons dashicons-location" style="color: var(--batp-highlight-color);"></span>';
					}, 2000 );
				},
				( error ) => {
					console.warn( 'Geolocation error:', error );
					geoTrigger.disabled = false;
					geoTrigger.innerHTML = '<span class="dashicons dashicons-warning" style="color: orange;"></span>';
					alert( 'Could not detect location. Please enter it manually.' );
				}
			);
		} );
	}

	chips.forEach( ( input ) => {
		input.addEventListener( 'change', () => {
			input
				.closest( '.batp-itinerary-chip' )
				.classList.toggle( 'is-selected', input.checked );
		} );
	} );

	// 2. Map & Render Helpers
	let googleMap = null;
	let mapLoader = null;

	const initMap = async ( apiKey, locations = [] ) => {
		if ( ! mapContainer ) return;

		// Clear previous content (placeholders/errors)
		mapContainer.innerHTML = '<div style="height:100%; width:100%; border-radius: 16px;"></div>';
		const mapElement = mapContainer.firstElementChild;

		try {
			if ( ! mapLoader ) {
				mapLoader = new Loader( {
					apiKey: apiKey,
					version: 'weekly',
					libraries: [ 'places', 'geometry' ],
				} );
			}

			const google = await mapLoader.load();
			const { Map } = await google.maps.importLibrary( 'maps' );
			const { AdvancedMarkerElement } = await google.maps.importLibrary( 'marker' );

			const center = locations.length > 0 
				? { lat: locations[0].lat, lng: locations[0].lng }
				: { lat: 40.6782, lng: -73.9442 }; // Default: Brooklyn

			googleMap = new Map( mapElement, {
				center: center,
				zoom: 13,
				mapId: 'DEMO_MAP_ID', // Use a real Map ID in prod for Advanced Markers
				disableDefaultUI: true,
				zoomControl: true,
			} );

			if ( locations.length > 0 ) {
				const bounds = new google.maps.LatLngBounds();
				
				locations.forEach( ( loc, index ) => {
					const position = { lat: loc.lat, lng: loc.lng };
					
					// Create marker
					const markerContent = document.createElement( 'div' );
					markerContent.className = 'batp-map-marker';
					markerContent.innerHTML = `<span style="background:#ff4f5e; color:#fff; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-weight:bold; box-shadow:0 2px 4px rgba(0,0,0,0.2);">${ index + 1 }</span>`;

					new AdvancedMarkerElement( {
						map: googleMap,
						position: position,
						content: markerContent,
						title: loc.title,
					} );

					bounds.extend( position );
				} );

				// Draw polyline if > 1 point
				if ( locations.length > 1 ) {
					const path = locations.map( l => ( { lat: l.lat, lng: l.lng } ) );
					new google.maps.Polyline( {
						path: path,
						geodesic: true,
						strokeColor: '#ff4f5e',
						strokeOpacity: 1.0,
						strokeWeight: 3,
						map: googleMap,
					} );
				}

				googleMap.fitBounds( bounds );
			}

		} catch ( error ) {
			console.error( 'Map init error:', error );
			mapContainer.innerHTML = `<div class="batp-itinerary-map__error"><p>Unable to load map.</p></div>`;
		}
	};

	const renderError = ( message ) => {
		if ( ! mapContainer ) return;
		mapContainer.innerHTML = `
			<div class="batp-itinerary-map__error" style="color: #d00; padding: 1rem;">
				<p><strong>Error:</strong> ${ message }</p>
			</div>
		`;
	};

	const renderSuccess = ( data, apiKey ) => {
		const items = data.candidates || [];
		// We use candidates for map coordinates because LLM response might not have coords yet
		// Actually Engine response structure: 
		// candidates: array of { slug, data: { latitude, longitude, name ... } }
		// itinerary: { items: [ { slug, title ... } ] }
		// We should map itinerary items back to candidate coordinates.

		const itineraryItems = data.itinerary?.items || [];
		if ( itineraryItems.length === 0 ) {
			if ( mapContainer ) mapContainer.innerHTML = '<div style="padding:1rem;">No itinerary items found.</div>';
			return;
		}

		// Build location list for map
		const locations = [];
		const candidateMap = new Map( data.candidates.map( c => [ c.slug, c ] ) );

		itineraryItems.forEach( item => {
			const candidate = candidateMap.get( item.slug );
			if ( candidate && candidate.data?.latitude && candidate.data?.longitude ) {
				locations.push( {
					lat: parseFloat( candidate.data.latitude ),
					lng: parseFloat( candidate.data.longitude ),
					title: item.title
				} );
			}
		} );

		if ( apiKey && locations.length > 0 ) {
			initMap( apiKey, locations );
		} else {
			// Fallback to list if no map key or coords
			if ( mapContainer ) {
				const listHtml = itineraryItems.map( ( item, index ) => `
					<div style="margin-bottom: 0.5rem; text-align: left;">
						<strong>${ index + 1 }. ${ item.title }</strong>
						<div style="font-size: 0.9em; color: #666;">${ item.notes || '' }</div>
					</div>
				` ).join( '' );
				mapContainer.innerHTML = `
					<div style="padding: 1.5rem; width: 100%; overflow-y: auto; max-height: 320px;">
						<h3 style="margin-top: 0;">Your Itinerary</h3>
						${ listHtml }
					</div>
				`;
			}
		}
	};

	// 3. Form Submission
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		
		const nonce = form.dataset.nonce;
		const apiUrl = form.dataset.apiUrl;
		const apiKey = form.dataset.googleMapsKey;

		if ( ! nonce || ! apiUrl ) {
			console.error( 'BATP: Missing API configuration.' );
			return;
		}

		const formData = new FormData( form );
		const payload = {
			neighborhood: formData.get( 'neighborhood' ),
			interests: formData.getAll( 'interests[]' ),
			budget: formData.get( 'budget' ),
			accessibility_preferences: formData.getAll( 'accessibility_preferences[]' ),
			latitude: formData.get( 'latitude' ) || null,
			longitude: formData.get( 'longitude' ) || null,
			duration: Number( formData.get( 'duration' ) ) * 60, // Convert hours to minutes for API
			nonce: nonce // Explicitly include nonce in body for Engine check
		};

		// UI State: Loading
		form.dataset.state = 'loading';
		if ( mapContainer ) {
			mapContainer.innerHTML = '<div class="batp-itinerary-map__placeholder"><p class="batp-itinerary-map__eyebrow">GENERATING...</p></div>';
		}

		try {
			const response = await fetch( apiUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( payload ),
			} );

			const result = await response.json();

			if ( ! response.ok ) {
				throw new Error( result.message || result.code || 'An unknown error occurred.' );
			}

			// UI State: Success
			form.dataset.state = 'idle'; // Re-enable form
			renderSuccess( result, apiKey );

			// Dispatch event for other components
			form.dispatchEvent(
				new CustomEvent( 'batp-itinerary-success', { detail: result } )
			);

		} catch ( error ) {
			console.error( 'BATP Error:', error );
			form.dataset.state = 'idle';
			renderError( error.message );
		}
	} );
};

if ( document.readyState !== 'loading' ) {
	initItineraryForm();
} else {
	document.addEventListener( 'DOMContentLoaded', initItineraryForm );
}
