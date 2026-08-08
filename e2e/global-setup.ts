import type { FullConfig } from '@playwright/test';
import { execSync } from 'child_process';

// eslint-disable-next-line @typescript-eslint/no-var-requires
const mockServer = require( './mock-api/server.js' );

const MOCK_API_PORT = process.env.EUROMAIL_MOCK_PORT ? parseInt( process.env.EUROMAIL_MOCK_PORT, 10 ) : 8825;
const MAILPIT_CONTAINER_NAME = 'euromail-e2e-mailpit';
const MAILPIT_HTTP_URL = 'http://localhost:8025';

export default async function globalSetup( config: FullConfig ) {
	await new Promise<void>( ( resolve ) => {
		mockServer.listen( MOCK_API_PORT, resolve );
	} );

	( globalThis as any ).__EUROMAIL_MOCK_SERVER__ = mockServer;

	startMailpit();
	await waitForMailpit();
}

/**
 * Start a disposable MailPit container for the SMTP/fallback specs: SMTP on
 * 1025 (no auth/encryption, matching the specs' settings), the HTTP API on
 * 8025 for assertions. Reachable from the wp-env WordPress container via
 * host.docker.internal, the same way the mock API server is.
 */
function startMailpit() {
	try {
		execSync( `docker rm -f ${ MAILPIT_CONTAINER_NAME }`, { stdio: 'ignore' } );
	} catch ( error ) {
		// No previous container to remove — fine.
	}

	execSync(
		`docker run -d --rm --name ${ MAILPIT_CONTAINER_NAME } -p 1025:1025 -p 8025:8025 axllent/mailpit:latest`,
		{ stdio: 'ignore' }
	);
}

async function waitForMailpit() {
	const deadline = Date.now() + 15_000;

	while ( Date.now() < deadline ) {
		try {
			const response = await fetch( `${ MAILPIT_HTTP_URL }/api/v1/messages` );
			if ( response.ok ) {
				return;
			}
		} catch ( error ) {
			// Not up yet.
		}

		await new Promise( ( resolve ) => setTimeout( resolve, 300 ) );
	}

	throw new Error( 'MailPit did not become ready in time.' );
}
