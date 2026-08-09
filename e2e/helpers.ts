import { Page } from '@playwright/test';

/**
 * Logs in as the wp-env default admin user. Hardened for CI, where wp-env,
 * MySQL, Docker-in-Docker, and Chromium all share one small runner —
 * genuinely slower than a local dev machine, not just noisier. Gives the
 * post-login redirect its own generous timeout (independent of the overall
 * Playwright test timeout), and retries the submit once — re-navigating
 * and re-filling the form, since WP's login page occasionally eats the
 * first POST under load rather than genuinely failing to authenticate —
 * before actually failing.
 */
export async function login( page: Page ): Promise<void> {
	await submitLoginForm( page );

	try {
		await page.waitForURL( '**/wp-admin/**', { timeout: 45_000 } );
		return;
	} catch {
		// Fall through to the one retry below.
	}

	await submitLoginForm( page );
	await page.waitForURL( '**/wp-admin/**', { timeout: 45_000 } );
}

async function submitLoginForm( page: Page ): Promise<void> {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
}
