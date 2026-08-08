import { test, expect, Page } from '@playwright/test';
import * as crypto from 'crypto';

const MOCK_API_URL = 'http://localhost:8825';
const WEBHOOK_URL = 'http://localhost:8888/wp-json/euromail/v1/webhook';
const WEBHOOK_SECRET = 'e2e-webhook-secret';

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

/**
 * Sign a payload exactly the way EuroMail\Webhooks\WebhookSignature::verify()
 * expects: hash_hmac('sha256', "{ts}.{body}", secret), formatted as
 * "t={ts},v1={hex}".
 */
function sign( body: string, secret: string, timestamp = Math.floor( Date.now() / 1000 ) ): string {
	const signature = crypto.createHmac( 'sha256', secret ).update( `${ timestamp }.${ body }` ).digest( 'hex' );
	return `t=${ timestamp },v1=${ signature }`;
}

/**
 * Navigate to the first log row's detail page via the "View" row action's
 * own href, rather than clicking it — WP_List_Table row actions are
 * positioned off-screen until the row is hovered/focused, which makes a
 * direct Playwright click flaky.
 */
async function goToFirstRowDetail( page: Page ) {
	const href = await page.locator( '.wp-list-table tbody tr' ).first().getByRole( 'link', { name: 'View' } ).getAttribute( 'href' );
	if ( ! href ) {
		throw new Error( 'Could not find a "View" link on the first log row.' );
	}
	await page.goto( href );
}

test( 'a correctly signed delivered webhook updates the log status and events timeline (scenario 5)', async ( { page, request } ) => {
	await login( page );

	// Configure the API backend and the webhook secret via the UI.
	await page.goto( '/wp-admin/admin.php?page=euromail' );
	await page.selectOption( '#euromail_backend', 'api' );
	await page.uncheck( '#euromail_force_from_enabled' );
	await page.fill( '#euromail_api_key', 'em_live_e2etest' );
	await page.fill( '#euromail_webhook_secret', WEBHOOK_SECRET );
	await page.click( 'input[name="euromail_settings_submit"]' );
	await page.waitForLoadState( 'networkidle' );

	// The webhook URL shown in Settings must be the real REST route.
	await expect( page.locator( '#euromail-webhook-url' ) ).toHaveText( WEBHOOK_URL );

	await request.post( `${ MOCK_API_URL }/_reset` );

	// Send a test email through the (mocked) API so there's a real log row
	// with a real api_id to target.
	await page.goto( '/wp-admin/admin.php?page=euromail-test' );
	await page.fill( '#euromail_test_to', 'recipient@example.com' );
	await page.click( 'input[name="euromail_send_test_submit"]' );

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Test email sent. Log entry #' );

	// Read the api_id back off the log row's own detail page.
	await page.goto( '/wp-admin/admin.php?page=euromail-log' );
	await goToFirstRowDetail( page );

	const apiId = await page.locator( 'table.form-table tr', { hasText: 'API ID' } ).locator( 'td' ).innerText();
	expect( apiId ).not.toBe( '—' );

	// POST a correctly signed "delivered" event for that email, shaped the
	// way euromail.dev's own webhook worker actually builds it: "event" for
	// the event type, "email_id" for the email identifier.
	const body = JSON.stringify( {
		event: 'delivered',
		email_id: apiId,
		timestamp: '2026-01-01T00:00:00Z',
	} );

	const webhookResponse = await request.post( WEBHOOK_URL, {
		headers: {
			'Content-Type': 'application/json',
			'X-Euromail-Signature': sign( body, WEBHOOK_SECRET ),
			'X-Euromail-Event': 'delivered',
		},
		data: body,
	} );

	expect( webhookResponse.status() ).toBe( 200 );

	// The log list must show the promoted status...
	await page.goto( '/wp-admin/admin.php?page=euromail-log' );
	await expect( page.locator( '.wp-list-table tbody tr' ).first() ).toContainText( 'delivered' );

	// ...and the detail view's events timeline must show the event.
	await goToFirstRowDetail( page );
	await expect( page.locator( 'table.form-table tr', { hasText: 'Status' } ).locator( 'td' ) ).toHaveText( 'delivered' );

	const eventsSection = page.locator( 'table.form-table tr', { hasText: 'Events' } );
	await expect( eventsSection ).toContainText( 'delivered' );
	await expect( eventsSection ).toContainText( '2026-01-01T00:00:00Z' );
} );

test( 'a badly signed webhook is rejected and never changes the log', async ( { page, request } ) => {
	await login( page );

	await page.goto( '/wp-admin/admin.php?page=euromail' );
	await page.selectOption( '#euromail_backend', 'api' );
	await page.uncheck( '#euromail_force_from_enabled' );
	await page.fill( '#euromail_api_key', 'em_live_e2etest' );
	await page.fill( '#euromail_webhook_secret', WEBHOOK_SECRET );
	await page.click( 'input[name="euromail_settings_submit"]' );
	await page.waitForLoadState( 'networkidle' );

	await request.post( `${ MOCK_API_URL }/_reset` );

	await page.goto( '/wp-admin/admin.php?page=euromail-test' );
	await page.fill( '#euromail_test_to', 'recipient2@example.com' );
	await page.click( 'input[name="euromail_send_test_submit"]' );
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Test email sent. Log entry #' );

	await page.goto( '/wp-admin/admin.php?page=euromail-log' );
	await expect( page.locator( '.wp-list-table tbody tr' ).first() ).toContainText( 'sent' );

	const body = JSON.stringify( { id: 'irrelevant-since-signature-is-wrong' } );

	const webhookResponse = await request.post( WEBHOOK_URL, {
		headers: {
			'Content-Type': 'application/json',
			'X-Euromail-Signature': sign( body, 'the-wrong-secret' ),
			'X-Euromail-Event': 'delivered',
		},
		data: body,
	} );

	expect( webhookResponse.status() ).toBe( 401 );

	await page.goto( '/wp-admin/admin.php?page=euromail-log' );
	await expect( page.locator( '.wp-list-table tbody tr' ).first() ).toContainText( 'sent' );
} );
