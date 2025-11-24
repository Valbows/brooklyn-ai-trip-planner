import { test, expect } from '@playwright/test';

test.describe( 'Brooklyn AI Planner Block Frontend', () => {
	test.skip( 'Should render form inputs correctly', async ( { page } ) => {
		// 1. Setup: Login and create page with block
		await page.goto( '/wp-login.php' );
		if ( await page.locator( '#user_login' ).isVisible() ) {
			const username = process.env.WP_USERNAME || 'admin';
			const password = process.env.WP_PASSWORD || 'password';
			
			await page.fill( '#user_login', username );
			await page.fill( '#user_pass', password );
			await page.click( '#wp-submit' );

			// Check for login error
			const error = page.locator( '#login_error' );
			if ( await error.isVisible() ) {
				if ( password === 'password' ) {
					await page.fill( '#user_pass', 'admin' );
					await page.click( '#wp-submit' );
				}
			}
		}
		
		// Create new page
		await page.goto( '/wp-admin/post-new.php?post_type=page' );
		const welcomeGuide = page.locator( 'button[aria-label="Close Welcome Guide"]' );
		if ( await welcomeGuide.isVisible() ) {
			await welcomeGuide.click();
		}

		// Insert block
		await page.click( 'button[aria-label="Toggle block inserter"]' );
		await page.fill( '.block-editor-inserter__search-input', 'Brooklyn AI' );
		await page.click( '.block-editor-block-types-list__item-title:has-text("Brooklyn AI Trip Planner")' );

		// Publish page
		await page.click( 'button[aria-label="Publish"]' ); // Open panel
		await page.click( '.editor-post-publish-panel__header .components-button' ); // Confirm publish
		
		// Wait for publish notification
		const snackbar = page.locator( '.components-snackbar' );
		await expect( snackbar ).toContainText( 'Page published' );

		// 2. Visit Frontend
		const viewPageLink = page.locator( '.components-snackbar a:has-text("View Page")' );
		const pageUrl = await viewPageLink.getAttribute( 'href' );
		
		if ( ! pageUrl ) throw new Error( 'Could not find page URL' );
		await page.goto( pageUrl );

		// 3. Verify Elements
		const form = page.locator( '.batp-itinerary-form' );
		await expect( form ).toBeVisible();

		// Check inputs
		await expect( form.locator( 'input[name="neighborhood"]' ) ).toBeVisible();
		await expect( form.locator( 'select[name="budget"]' ) ).toBeVisible();
		await expect( form.locator( 'input[type="range"]' ) ).toBeVisible();
		
		// Check chips
		const chips = form.locator( '.batp-itinerary-chip' );
		await expect( chips ).toHaveCount( 5 ); // 5 interests

		// Check Geolocation button
		await expect( form.locator( '[data-batp-geo-trigger]' ) ).toBeVisible();
	} );

	// Note: We cannot easily test successful API submission in E2E without mocking the backend or having valid keys.
	// However, we can verify that the form submits and handles the loading state.

	test.skip( 'Should handle form submission and show loading state', async ( { page } ) => {
		// Reuse login/setup logic or assume previous test state if running in sequence (Playwright isolates by default)
		// For brevity, assume we are on the frontend page from previous step (in real suite we'd use beforeEach)
		// ... Re-implement login/setup for isolation ...
		await page.goto( '/wp-login.php' );
		if ( await page.locator( '#user_login' ).isVisible() ) {
			const username = process.env.WP_USERNAME || 'admin';
			const password = process.env.WP_PASSWORD || 'password';
			
			await page.fill( '#user_login', username );
			await page.fill( '#user_pass', password );
			await page.click( '#wp-submit' );

			const error = page.locator( '#login_error' );
			if ( await error.isVisible() ) {
				if ( password === 'password' ) {
					await page.fill( '#user_pass', 'admin' );
					await page.click( '#wp-submit' );
				}
			}
		}
		await page.goto( '/wp-admin/post-new.php?post_type=page' );
		const welcomeGuide = page.locator( 'button[aria-label="Close Welcome Guide"]' );
		if ( await welcomeGuide.isVisible() ) await welcomeGuide.click();
		await page.click( 'button[aria-label="Toggle block inserter"]' );
		await page.fill( '.block-editor-inserter__search-input', 'Brooklyn AI' );
		await page.click( '.block-editor-block-types-list__item-title:has-text("Brooklyn AI Trip Planner")' );
		await page.click( 'button[aria-label="Publish"]' );
		await page.click( '.editor-post-publish-panel__header .components-button' );
		const viewPageLink = page.locator( '.components-snackbar a:has-text("View Page")' );
		const pageUrl = await viewPageLink.getAttribute( 'href' );
		await page.goto( pageUrl || '' );

		// Fill Form
		await page.fill( 'input[name="neighborhood"]', 'DUMBO' );
		await page.click( 'input[value="food"]' ); // Select Food interest
		
		// Submit
		await page.click( 'button[type="submit"]' );

		// Check Loading State
		const form = page.locator( '.batp-itinerary-form' );
		await expect( form ).toHaveAttribute( 'data-state', 'loading' );
		
		const mapPlaceholder = page.locator( '.batp-itinerary-map__placeholder' );
		await expect( mapPlaceholder ).toContainText( 'GENERATING' );
	} );
} );
