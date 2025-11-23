/**
 * Use this file for JavaScript code that you want to run in the front-end
 * on posts/pages that contain this block.
 *
 * When this file is defined as the value of the `viewScript` property
 * in `block.json` it will be enqueued on the front end of the site.
 *
 * Example:
 *
 * ```js
 * {
 *   "viewScript": "file:./view.js"
 * }
 * ```
 *
 * If you're not making any changes to this file because your project doesn't need any
 * JavaScript running in the front-end, then you should delete this file and remove
 * the `viewScript` property from `block.json`.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/#view-script
 */

const initItineraryForm = () => {
	const form = document.querySelector( '[data-batp-itinerary-form]' );
	if ( ! form ) {
		return;
	}

	const durationInput = form.querySelector( '[data-batp-duration]' );
	const durationOutput = form.querySelector( '[data-batp-duration-output]' );
	const chips = Array.from(
		form.querySelectorAll( '.batp-itinerary-chip input[type="checkbox"]' )
	);

	if ( durationInput && durationOutput ) {
		const syncDuration = () => {
			durationOutput.textContent = `${ durationInput.value }h`;
		};
		syncDuration();
		durationInput.addEventListener( 'input', syncDuration );
	}

	chips.forEach( ( input ) => {
		input.addEventListener( 'change', () => {
			input
				.closest( '.batp-itinerary-chip' )
				.classList.toggle( 'is-selected', input.checked );
		} );
	} );

	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		const formData = new FormData( form );
		const payload = {
			neighborhood: formData.get( 'neighborhood' ),
			interests: formData.getAll( 'interests[]' ),
			duration: Number( formData.get( 'duration' ) ),
		};
		form.dataset.state = 'loading';
		form.dispatchEvent(
			new CustomEvent( 'batp-itinerary-request', { detail: payload } )
		);
		setTimeout( () => {
			form.dataset.state = 'idle';
		}, 800 );
	} );
};

if ( document.readyState !== 'loading' ) {
	initItineraryForm();
} else {
	document.addEventListener( 'DOMContentLoaded', initItineraryForm );
}
