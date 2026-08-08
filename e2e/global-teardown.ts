import { execSync } from 'child_process';

const MAILPIT_CONTAINER_NAME = 'euromail-e2e-mailpit';

export default async function globalTeardown() {
	const mockServer = ( globalThis as any ).__EUROMAIL_MOCK_SERVER__;

	if ( mockServer ) {
		await new Promise<void>( ( resolve ) => mockServer.close( () => resolve() ) );
	}

	try {
		execSync( `docker stop ${ MAILPIT_CONTAINER_NAME }`, { stdio: 'ignore' } );
	} catch ( error ) {
		// Already stopped/removed — fine.
	}
}
