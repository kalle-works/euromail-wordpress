import { test, expect } from '@playwright/test';
import { login } from './helpers';

test.describe( '"Use Euromail SMTP relay" preset button (scenario 7)', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/admin.php?page=euromail' );

		// Start from a known-blank SMTP fieldset so the assertions below can
		// only be explained by the preset button, not leftover state from a
		// previous test.
		await page.fill( '#euromail_smtp_host', '' );
		await page.fill( '#euromail_smtp_port', '' );
		await page.selectOption( '#euromail_smtp_encryption', 'ssl' );
		await page.uncheck( '#euromail_smtp_auth' );
		await page.fill( '#euromail_smtp_username', '' );
		await page.fill( '#euromail_smtp_password', '' );
	} );

	test( 'fills the SMTP fields and copies the API key into the SMTP password field', async ( { page } ) => {
		await page.fill( '#euromail_api_key', 'em_live_relaypresettest' );

		await page.click( '#euromail-smtp-relay-preset' );

		await expect( page.locator( '#euromail_smtp_host' ) ).toHaveValue( 'smtp.euromail.dev' );
		await expect( page.locator( '#euromail_smtp_port' ) ).toHaveValue( '587' );
		await expect( page.locator( '#euromail_smtp_encryption' ) ).toHaveValue( 'tls' );
		await expect( page.locator( '#euromail_smtp_auth' ) ).toBeChecked();
		await expect( page.locator( '#euromail_smtp_username' ) ).toHaveValue( 'apikey' );
		await expect( page.locator( '#euromail_smtp_password' ) ).toHaveValue( 'em_live_relaypresettest' );
	} );

	test( 'leaves the SMTP password empty when no API key has been typed', async ( { page } ) => {
		await page.fill( '#euromail_api_key', '' );

		await page.click( '#euromail-smtp-relay-preset' );

		await expect( page.locator( '#euromail_smtp_host' ) ).toHaveValue( 'smtp.euromail.dev' );
		await expect( page.locator( '#euromail_smtp_password' ) ).toHaveValue( '' );
	} );
} );
