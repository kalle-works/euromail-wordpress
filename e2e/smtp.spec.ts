import { test, expect, Page } from '@playwright/test';
import { login } from './helpers';

const MAILPIT_URL = 'http://localhost:8025';

async function configureSmtp( page: Page ) {
	await page.goto( '/wp-admin/admin.php?page=euromail' );

	await page.selectOption( '#euromail_backend', 'smtp' );
	await page.check( '#euromail_force_from_enabled' );
	await page.fill( '#euromail_force_from_email', 'sender@example.com' );
	await page.fill( '#euromail_force_from_name', 'Euromail Test' );
	await page.fill( '#euromail_smtp_host', 'host.docker.internal' );
	await page.fill( '#euromail_smtp_port', '1025' );
	await page.selectOption( '#euromail_smtp_encryption', 'none' );
	await page.uncheck( '#euromail_smtp_auth' );
	await page.click( 'input[name="euromail_settings_submit"]' );
	await page.waitForLoadState( 'networkidle' );
}

test.describe( 'SMTP backend (scenario 3)', () => {
	test.beforeEach( async ( { page, request } ) => {
		await login( page );
		await configureSmtp( page );
		await request.delete( `${ MAILPIT_URL }/api/v1/messages` );
	} );

	test( 'a test email sent through the SMTP backend arrives at MailPit', async ( { page, request } ) => {
		await page.goto( '/wp-admin/admin.php?page=euromail-test' );
		await page.fill( '#euromail_test_to', 'recipient@example.com' );
		await page.click( 'input[name="euromail_send_test_submit"]' );

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Test email sent. Log entry #' );

		const response = await request.get( `${ MAILPIT_URL }/api/v1/messages` );
		const { messages } = await response.json();

		expect( messages.length ).toBeGreaterThan( 0 );

		const message = messages[ 0 ];
		expect( message.From.Address ).toBe( 'sender@example.com' );
		expect( message.From.Name ).toBe( 'Euromail Test' );
		expect( message.To[ 0 ].Address ).toBe( 'recipient@example.com' );
		expect( message.Subject ).toBe( 'Euromail test email' );
	} );

	test( 'the log table shows the send as delivered via the smtp backend', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=euromail-test' );
		await page.fill( '#euromail_test_to', 'recipient@example.com' );
		await page.click( 'input[name="euromail_send_test_submit"]' );

		await page.goto( '/wp-admin/admin.php?page=euromail-log' );

		const firstRow = page.locator( '.wp-list-table tbody tr' ).first();
		await expect( firstRow ).toContainText( 'Sent' );
		await expect( firstRow ).toContainText( 'smtp' );
	} );
} );
