import { test, expect } from '@playwright/test';
import { login } from './helpers';

const MOCK_API_URL = 'http://localhost:8825';
const MAILPIT_URL = 'http://localhost:8025';

test.describe( 'API-to-SMTP fallback (scenario 4)', () => {
	test.beforeEach( async ( { page, request } ) => {
		await login( page );

		// Primary: the euromail.dev API (pointed at the mock server), with
		// SMTP configured as the fallback.
		await page.goto( '/wp-admin/admin.php?page=euromail' );
		await page.selectOption( '#euromail_backend', 'api' );
		await page.check( '#euromail_fallback_enabled' );
		await page.fill( '#euromail_api_key', 'em_live_e2etest' );
		await page.check( '#euromail_force_from_enabled' );
		await page.fill( '#euromail_force_from_email', 'sender@example.com' );
		await page.fill( '#euromail_force_from_name', 'Euromail Test' );
		await page.fill( '#euromail_smtp_host', 'host.docker.internal' );
		await page.fill( '#euromail_smtp_port', '1025' );
		await page.selectOption( '#euromail_smtp_encryption', 'none' );
		await page.uncheck( '#euromail_smtp_auth' );
		await page.click( 'input[name="euromail_settings_submit"]' );
		await page.waitForLoadState( 'networkidle' );

		await request.post( `${ MOCK_API_URL }/_reset` );
		await request.delete( `${ MAILPIT_URL }/api/v1/messages` );
	} );

	test.afterEach( async ( { request } ) => {
		// Leave the mock API healthy for any test that runs after this one.
		await request.post( `${ MOCK_API_URL }/_mode`, { data: { status: 200 } } );
	} );

	test( 'a failing API send falls back to SMTP and is delivered', async ( { page, request } ) => {
		await request.post( `${ MOCK_API_URL }/_mode`, { data: { status: 500 } } );

		await page.goto( '/wp-admin/admin.php?page=euromail-test' );
		await page.fill( '#euromail_test_to', 'recipient@example.com' );
		await page.click( 'input[name="euromail_send_test_submit"]' );

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Test email sent. Log entry #' );

		// The API was actually tried (and failed) first...
		const apiRequests = await ( await request.get( `${ MOCK_API_URL }/_requests` ) ).json();
		expect( apiRequests.requests.length ).toBeGreaterThan( 0 );

		// ...but the email still arrived, via the SMTP fallback.
		const mailpitMessages = await ( await request.get( `${ MAILPIT_URL }/api/v1/messages` ) ).json();
		expect( mailpitMessages.messages.length ).toBeGreaterThan( 0 );
		expect( mailpitMessages.messages[ 0 ].To[ 0 ].Address ).toBe( 'recipient@example.com' );

		await page.goto( '/wp-admin/admin.php?page=euromail-log' );
		const firstRow = page.locator( '.wp-list-table tbody tr' ).first();
		await expect( firstRow ).toContainText( 'Sent' );
		await expect( firstRow ).toContainText( 'smtp' );
	} );
} );
