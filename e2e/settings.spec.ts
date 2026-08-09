import { test, expect } from '@playwright/test';
import { login } from './helpers';

test.describe( 'Euromail settings page', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'a valid API key verifies successfully', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=euromail' );
		await page.selectOption( '#euromail_backend', 'api' );

		await page.fill( '#euromail_api_key', 'em_live_e2etest' );
		await page.click( 'input[name="euromail_settings_submit"]' );
		await page.waitForLoadState( 'networkidle' );

		await page.click( '#euromail-verify-key' );
		await expect( page.locator( '#euromail-verify-result' ) ).toHaveText( 'Connection verified.', { timeout: 10000 } );
	} );

	test( 'an invalid API key fails verification', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=euromail' );
		await page.selectOption( '#euromail_backend', 'api' );

		await page.fill( '#euromail_api_key', 'em_live_wrongkey' );
		await page.click( 'input[name="euromail_settings_submit"]' );
		await page.waitForLoadState( 'networkidle' );

		await page.click( '#euromail-verify-key' );
		await expect( page.locator( '#euromail-verify-result' ) ).toHaveText( 'Invalid API key', { timeout: 10000 } );
	} );

	test( 'typing a new valid key and clicking Verify without saving verifies the typed key', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=euromail' );
		await page.selectOption( '#euromail_backend', 'api' );

		// Establish a known "saved" state that is deliberately wrong, so a
		// successful verification below can only be explained by the typed
		// value being used, not the saved one.
		await page.fill( '#euromail_api_key', 'em_live_wrongkey' );
		await page.click( 'input[name="euromail_settings_submit"]' );
		await page.waitForLoadState( 'networkidle' );

		// Type a valid key WITHOUT saving, and verify.
		await page.fill( '#euromail_api_key', 'em_live_e2etest' );
		await page.click( '#euromail-verify-key' );

		await expect( page.locator( '#euromail-verify-result' ) ).toHaveText( 'Connection verified.', { timeout: 10000 } );

		// Reload and confirm the saved key is still the wrong one: verifying
		// must never have saved the typed value.
		await page.reload();
		await expect( page.locator( '#euromail_api_key' ) ).toHaveValue( 'em_live_wrongkey' );
	} );
} );
