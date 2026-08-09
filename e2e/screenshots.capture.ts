import { test, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import * as crypto from 'crypto';
import { execSync } from 'child_process';

/**
 * Captures the four wordpress.org listing screenshots into .wordpress-org/,
 * deployed as SVN assets by release.yml (ASSETS_DIR). Not part of the test
 * suite — deliberately named so it doesn't match playwright.config.ts's
 * `*.spec.ts` testMatch glob; run explicitly:
 *
 *   npx playwright test e2e/screenshots.capture.ts --config=e2e/playwright.config.ts
 */

const MOCK_API_URL = 'http://localhost:8825';
const WEBHOOK_URL = 'http://localhost:8888/wp-json/euromail/v1/webhook';
const WEBHOOK_SECRET = 'screenshot-capture-secret';
const OUTPUT_DIR = path.join( __dirname, '..', '.wordpress-org' );

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

function sign( body: string, secret: string, timestamp = Math.floor( Date.now() / 1000 ) ): string {
	const signature = crypto.createHmac( 'sha256', secret ).update( `${ timestamp }.${ body }` ).digest( 'hex' );
	return `t=${ timestamp },v1=${ signature }`;
}

async function apiIdFor( page: Page, recipientHint: string ): Promise<string> {
	await page.goto( '/wp-admin/admin.php?page=euromail-log' );
	const row = page.locator( '.wp-list-table tbody tr', { hasText: recipientHint } );
	const href = await row.getByRole( 'link', { name: 'View' } ).getAttribute( 'href' );
	if ( ! href ) {
		throw new Error( `Could not find a log row for ${ recipientHint }` );
	}
	await page.goto( href );
	return page.locator( 'table.form-table tr', { hasText: 'API ID' } ).locator( 'td' ).innerText();
}

async function postWebhook( request: any, event: string, apiId: string ) {
	const body = JSON.stringify( { event, email_id: apiId, timestamp: new Date().toISOString() } );
	const response = await request.post( WEBHOOK_URL, {
		headers: { 'Content-Type': 'application/json', 'X-Euromail-Signature': sign( body, WEBHOOK_SECRET ) },
		data: body,
	} );
	if ( response.status() !== 200 ) {
		throw new Error( `Webhook POST for "${ event }" failed with status ${ response.status() }` );
	}
}

test( 'capture wordpress.org listing screenshots', async ( { page, request } ) => {
	fs.mkdirSync( OUTPUT_DIR, { recursive: true } );
	await page.setViewportSize( { width: 1400, height: 900 } );

	// This dev wp-env database accumulates rows from every e2e run against
	// it; start from an empty log so the listing screenshots show only the
	// rows this script itself creates, not weeks of leftover test data.
	execSync(
		'npx wp-env run cli wp eval \'global $wpdb; $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}euromail_log" );\'',
		{ cwd: path.join( __dirname, '..' ), stdio: 'inherit' }
	);

	await login( page );
	await request.post( `${ MOCK_API_URL }/_reset` );

	// --- Screenshot 1: Settings, fully configured. ---
	await page.goto( '/wp-admin/admin.php?page=euromail' );
	await page.selectOption( '#euromail_backend', 'api' );
	await page.uncheck( '#euromail_force_from_enabled' );
	await page.fill( '#euromail_api_key', 'em_live_e2etest' );
	await page.check( '#euromail_fallback_enabled' );
	await page.fill( '#euromail_webhook_secret', WEBHOOK_SECRET );
	await page.click( 'input[name="euromail_settings_submit"]' );
	await page.waitForLoadState( 'networkidle' );
	await page.waitForTimeout( 300 );
	await page.screenshot( { path: path.join( OUTPUT_DIR, 'screenshot-1.png' ), fullPage: true } );

	// --- Row 1: sent, then promoted to delivered via two webhook events (for a fuller timeline). ---
	await page.goto( '/wp-admin/admin.php?page=euromail-test' );
	await page.fill( '#euromail_test_to', 'amelia@example.com' );
	await page.click( 'input[name="euromail_send_test_submit"]' );
	await page.waitForSelector( '.notice-success' );

	const apiId1 = await apiIdFor( page, 'amelia@example.com' );
	await postWebhook( request, 'sent', apiId1 );
	await postWebhook( request, 'delivered', apiId1 );

	// --- Row 2: queued, via a simulated rate limit. ---
	await request.post( `${ MOCK_API_URL }/_mode`, { data: { status: 429 } } );
	await page.goto( '/wp-admin/admin.php?page=euromail-test' );
	await page.fill( '#euromail_test_to', 'ben@example.com' );
	await page.click( 'input[name="euromail_send_test_submit"]' );
	await page.waitForSelector( '.notice-error' );
	await request.post( `${ MOCK_API_URL }/_mode`, { data: { status: 200 } } );

	// --- Row 3: a plain successful send. ---
	await page.goto( '/wp-admin/admin.php?page=euromail-test' );
	await page.fill( '#euromail_test_to', 'chidi@example.com' );
	await page.click( 'input[name="euromail_send_test_submit"]' );
	await page.waitForSelector( '.notice-success' );

	// --- Screenshot 2: Log list, mixed statuses (Sent / Queued / Delivered). ---
	await page.goto( '/wp-admin/admin.php?page=euromail-log' );
	await page.waitForLoadState( 'networkidle' );
	await page.waitForTimeout( 300 );
	await page.screenshot( { path: path.join( OUTPUT_DIR, 'screenshot-2.png' ), fullPage: true } );

	// --- Screenshot 3: Log detail, events timeline (row 1 — has two webhook events). ---
	const row1 = page.locator( '.wp-list-table tbody tr', { hasText: 'amelia@example.com' } );
	const row1Href = await row1.getByRole( 'link', { name: 'View' } ).getAttribute( 'href' );
	if ( ! row1Href ) {
		throw new Error( 'Could not find the amelia@example.com log row.' );
	}
	await page.goto( row1Href );
	await page.waitForLoadState( 'networkidle' );
	await page.waitForTimeout( 300 );
	await page.screenshot( { path: path.join( OUTPUT_DIR, 'screenshot-3.png' ), fullPage: true } );

	// --- Screenshot 4: Send Test page, showing a successful send. ---
	await page.goto( '/wp-admin/admin.php?page=euromail-test' );
	await page.fill( '#euromail_test_to', 'dan@example.com' );
	await page.click( 'input[name="euromail_send_test_submit"]' );
	await page.waitForSelector( '.notice-success' );
	await page.waitForLoadState( 'networkidle' );
	await page.waitForTimeout( 300 ); // Let the post-reload layout fully settle before capturing.
	await page.screenshot( { path: path.join( OUTPUT_DIR, 'screenshot-4.png' ), fullPage: true } );

	// The "ben@example.com" row is left 'queued' with a real next_attempt_at
	// — left in place, the shared dev environment's own retry cron would
	// pick it up and fire an unexpected extra request against the mock API
	// the next time any other e2e spec runs (this bit a real run of
	// retry-queue.spec.ts while building this script). Leave the log empty
	// again, the same way it started.
	execSync(
		'npx wp-env run cli wp eval \'global $wpdb; $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}euromail_log" );\'',
		{ cwd: path.join( __dirname, '..' ), stdio: 'inherit' }
	);

	// eslint-disable-next-line no-console
	console.log( `Screenshots written to ${ OUTPUT_DIR }` );
} );
