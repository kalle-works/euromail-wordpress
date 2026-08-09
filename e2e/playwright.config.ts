import { defineConfig } from '@playwright/test';
import path from 'path';

export default defineConfig( {
	testDir: __dirname,
	testMatch: '*.spec.ts',
	// CI runs everything (wp-env, MySQL, Docker-in-Docker, and Chromium)
	// on one shared, small runner — genuinely slower than a local dev
	// machine, not just noisier. 30s is plenty locally; give CI more room
	// rather than let normal load turn into a flaky timeout.
	timeout: process.env.CI ? 90_000 : 30_000,
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
