import { test, expect, Page } from '@playwright/test';

const MOCK_API_URL = 'http://localhost:8825';

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
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

test( 'the "Refresh status" action ends on a clean ?action=view URL', async ( { page, request } ) => {
	await login( page );

	// Configure the API backend so the row is API-sent and eligible for
	// "Refresh status".
	await page.goto( '/wp-admin/admin.php?page=euromail' );
	await page.selectOption( '#euromail_backend', 'api' );
	await page.uncheck( '#euromail_force_from_enabled' );
	await page.fill( '#euromail_api_key', 'em_live_e2etest' );
	await page.click( 'input[name="euromail_settings_submit"]' );
	await page.waitForLoadState( 'networkidle' );

	await request.post( `${ MOCK_API_URL }/_reset` );

	await page.goto( '/wp-admin/admin.php?page=euromail-test' );
	await page.fill( '#euromail_test_to', 'refresh-target@example.com' );
	await page.click( 'input[name="euromail_send_test_submit"]' );
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Test email sent. Log entry #' );

	await page.goto( '/wp-admin/admin.php?page=euromail-log' );
	await goToFirstRowDetail( page );

	// This click follows the "Refresh status" button's own nonce'd
	// ?action=refresh_status URL, which redirects (via a `load-{hook}`
	// handler that runs before any admin page output, not the page's own
	// render callback) back to a clean ?action=view URL. Before that fix,
	// the redirect ran too late for wp_safe_redirect() to take effect —
	// which would either leave the URL stuck on ?action=refresh_status, or
	// surface as a PHP "headers already sent" warning in the page.
	await page.click( 'a.button:has-text("Refresh status")' );
	await page.waitForLoadState( 'networkidle' );

	const url = new URL( page.url() );
	expect( url.searchParams.get( 'action' ) ).toBe( 'view' );
	expect( url.searchParams.get( 'action' ) ).not.toBe( 'refresh_status' );

	// A successful refresh reached the mock API's GET /v1/emails/:id and
	// applied its result — confirms this exercised the real code path
	// rather than failing open before ever redirecting.
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Status refreshed from Euromail.' );
	await expect( page.locator( 'body' ) ).not.toContainText( 'headers already sent' );
} );
