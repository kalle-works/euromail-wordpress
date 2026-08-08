import { defineConfig } from '@playwright/test';
import path from 'path';

export default defineConfig( {
	testDir: __dirname,
	testMatch: '*.spec.ts',
	timeout: 30_000,
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
