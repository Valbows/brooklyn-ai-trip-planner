import { test, expect } from '@playwright/test';

test( 'Block should insert and render in editor', async ( { page } ) => {
	// 1. Login
	await page.goto( '/wp-login.php' );

	// Check if we are already logged in (redirected to admin) or need to log in
	if ( await page.locator( '#user_login' ).isVisible() ) {
		const username = process.env.WP_USERNAME || 'admin';
		const password = process.env.WP_PASSWORD || 'password';

		await page.fill( '#user_login', username );
		await page.fill( '#user_pass', password );
		await page.click( '#wp-submit' );

		// Check for login error
		const error = page.locator( '#login_error' );
		if ( await error.isVisible() ) {
			// Retry with common fallback "admin" / "admin" if default failed
			if ( password === 'password' ) {
				await page.fill( '#user_pass', 'admin' );
				await page.click( '#wp-submit' );
			}
		}
	}

	await expect( page ).toHaveURL( /wp-admin/ );

	// 2. Create new post
	await page.goto( '/wp-admin/post-new.php' );

	// Dismiss welcome guide if present
	const welcomeGuide = page.locator(
		'button[aria-label="Close Welcome Guide"]'
	);
	if ( await welcomeGuide.isVisible() ) {
		await welcomeGuide.click();
	}

	// 3. Insert Block
	await page.click( 'button[aria-label="Toggle block inserter"]' );
	await page.fill( '.block-editor-inserter__search-input', 'Brooklyn AI' );

	const blockButton = page.locator(
		'.block-editor-block-types-list__item-title:has-text("Brooklyn AI Trip Planner")'
	);
	await blockButton.click();

	// 4. Verify Block Rendered in Editor
	const blockWrapper = page.locator( '.batp-itinerary-block' );
	await expect( blockWrapper ).toBeVisible();

	// Verify default text
	await expect( blockWrapper.locator( 'h2' ) ).toHaveText( '' ); // Initially empty or placeholder

	// 5. Edit Block Attributes
	await blockWrapper.locator( 'h2' ).fill( 'My Brooklyn Trip' );
	await blockWrapper
		.locator( '.batp-itinerary-block__subheading' )
		.fill( 'Exploring the best pizza.' );

	// 6. Publish (Optional smoke test for saving)
	// Note: Saving usually requires handling pre-publish checks.
	// For now, we verify the block content updated in the DOM.
	await expect( blockWrapper.locator( 'h2' ) ).toHaveText(
		'My Brooklyn Trip'
	);
} );
