import { defineConfig } from '@playwright/test';
import path from 'path';

/**
 * One-off config for e2e/screenshots.capture.ts, which playwright.config.ts's
 * `testMatch: '*.spec.ts'` deliberately excludes from the normal test run.
 * Run with:
 *
 *   npx playwright test --config=e2e/playwright.screenshots.config.ts
 */
export default defineConfig( {
	testDir: __dirname,
	testMatch: 'screenshots.capture.ts',
	timeout: 60_000,
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: 'list',
	globalSetup: path.join( __dirname, 'global-setup.ts' ),
	globalTeardown: path.join( __dirname, 'global-teardown.ts' ),
	use: {
		baseURL: 'http://localhost:8888',
		trace: 'retain-on-failure',
	},
} );
