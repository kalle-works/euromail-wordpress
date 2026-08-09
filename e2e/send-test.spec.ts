import { test, expect } from '@playwright/test';
import { login } from './helpers';

const MOCK_API_URL = 'http://localhost:8825';

test( 'the Send Test page delivers through the mock API with the expected payload', async ( { page, request } ) => {
	await login( page );

	await page.goto( '/wp-admin/admin.php?page=euromail' );
	await page.selectOption( '#euromail_backend', 'api' );
	await page.uncheck( '#euromail_force_from_enabled' );
	await page.fill( '#euromail_api_key', 'em_live_e2etest' );
	await page.click( 'input[name="euromail_settings_submit"]' );
	await page.waitForLoadState( 'networkidle' );

	await request.post( `${ MOCK_API_URL }/_reset` );

	await page.goto( '/wp-admin/admin.php?page=euromail-test' );
	await page.fill( '#euromail_test_to', 'recipient@example.com' );
	await page.click( 'input[name="euromail_send_test_submit"]' );

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Test email sent. Log entry #' );

	const response = await request.get( `${ MOCK_API_URL }/_requests` );
	const { requests } = await response.json();

	expect( requests.length ).toBeGreaterThan( 0 );

	const last = requests[ requests.length - 1 ];

	expect( last.body.to ).toContain( 'recipient@example.com' );
	expect( last.body.subject ).toBe( 'Euromail test email' );
	expect( last.body.html_body ).toBeTruthy();
	expect( typeof last.body.idempotency_key ).toBe( 'string' );
	expect( last.headers.authorization ).toBe( 'Bearer em_live_e2etest' );
} );
