import { test, expect, Page } from '@playwright/test';

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

test.describe( 'Euromail settings page', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'a valid API key verifies successfully', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=euromail' );

		await page.fill( '#euromail_api_key', 'em_live_e2etest' );
		await page.click( 'input[name="euromail_settings_submit"]' );
		await page.waitForLoadState( 'networkidle' );

		await page.click( '#euromail-verify-key' );
		await expect( page.locator( '#euromail-verify-result' ) ).toHaveText( 'Connection verified.', { timeout: 10000 } );
	} );

	test( 'an invalid API key fails verification', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=euromail' );

		await page.fill( '#euromail_api_key', 'em_live_wrongkey' );
		await page.click( 'input[name="euromail_settings_submit"]' );
		await page.waitForLoadState( 'networkidle' );

		await page.click( '#euromail-verify-key' );
		await expect( page.locator( '#euromail-verify-result' ) ).toHaveText( 'Invalid API key', { timeout: 10000 } );
	} );
} );
