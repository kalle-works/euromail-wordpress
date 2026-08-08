import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

const MOCK_API_URL = 'http://localhost:8825';

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

function runCronEvent() {
	execSync( 'npx wp-env run cli wp cron event run euromail_process_queue', { stdio: 'pipe' } );
}

/**
 * The initial retryable failure schedules next_attempt_at ~60s in the
 * future (the first backoff step), so running the cron event immediately
 * afterwards would find nothing due yet. Rather than sleeping 60+ real
 * seconds in the test, backdate the row directly so the cron run's own
 * due-time query picks it up — the claim guard, backend retry, and
 * idempotency-key reuse are all still exercised for real.
 */
function makeRowImmediatelyDue( mailTo: string ) {
	execSync(
		`npx wp-env run cli wp db query "UPDATE wp_euromail_log SET next_attempt_at = UTC_TIMESTAMP() WHERE mail_to LIKE '%${ mailTo }%' AND status = 'retrying' ORDER BY id DESC LIMIT 1"`,
		{ stdio: 'pipe' }
	);
}

test.describe( 'Retry queue via cron (scenario 6)', () => {
	test.beforeEach( async ( { page, request } ) => {
		await login( page );

		// Primary: API only, fallback OFF, so a 429 has nowhere to go but
		// the retry queue.
		await page.goto( '/wp-admin/admin.php?page=euromail' );
		await page.selectOption( '#euromail_backend', 'api' );
		await page.uncheck( '#euromail_fallback_enabled' );
		await page.fill( '#euromail_api_key', 'em_live_e2etest' );
		await page.uncheck( '#euromail_force_from_enabled' );
		await page.click( 'input[name="euromail_settings_submit"]' );
		await page.waitForLoadState( 'networkidle' );

		await request.post( `${ MOCK_API_URL }/_reset` );
	} );

	test.afterEach( async ( { request } ) => {
		// Leave the mock API healthy for any test that runs after this one.
		await request.post( `${ MOCK_API_URL }/_mode`, { data: { status: 200 } } );
	} );

	test( 'a 429 queues the row for retry, and the cron-driven retry reuses the same idempotency key', async ( { page, request } ) => {
		await request.post( `${ MOCK_API_URL }/_mode`, { data: { status: 429 } } );

		await page.goto( '/wp-admin/admin.php?page=euromail-test' );
		await page.fill( '#euromail_test_to', 'recipient@example.com' );
		await page.click( 'input[name="euromail_send_test_submit"]' );

		// The initial attempt genuinely failed (429) — it is only queued
		// for a later retry, not delivered — so the Send Test page reports
		// an error, not success.
		await expect( page.locator( '.notice-error' ) ).toBeVisible();

		await page.goto( '/wp-admin/admin.php?page=euromail-log' );
		const firstRow = page.locator( '.wp-list-table tbody tr' ).first();
		await expect( firstRow ).toContainText( 'retrying' );

		const firstAttempt = await ( await request.get( `${ MOCK_API_URL }/_requests` ) ).json();
		expect( firstAttempt.requests.length ).toBe( 1 );
		const idempotencyKey = firstAttempt.requests[ 0 ].body.idempotency_key;
		expect( typeof idempotencyKey ).toBe( 'string' );

		// The backend recovers, then the cron-driven retry picks the row
		// back up.
		await request.post( `${ MOCK_API_URL }/_mode`, { data: { status: 200 } } );
		makeRowImmediatelyDue( 'recipient@example.com' );
		runCronEvent();

		await page.goto( '/wp-admin/admin.php?page=euromail-log' );
		const rowAfterRetry = page.locator( '.wp-list-table tbody tr' ).first();
		await expect( rowAfterRetry ).toContainText( 'sent' );

		const afterRetry = await ( await request.get( `${ MOCK_API_URL }/_requests` ) ).json();
		expect( afterRetry.requests.length ).toBe( 2 );
		expect( afterRetry.requests[ 1 ].body.idempotency_key ).toBe( idempotencyKey );
	} );
} );
