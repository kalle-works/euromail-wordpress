import type { FullConfig } from '@playwright/test';

// eslint-disable-next-line @typescript-eslint/no-var-requires
const mockServer = require( './mock-api/server.js' );

const PORT = process.env.EUROMAIL_MOCK_PORT ? parseInt( process.env.EUROMAIL_MOCK_PORT, 10 ) : 8825;

export default async function globalSetup( config: FullConfig ) {
	await new Promise<void>( ( resolve ) => {
		mockServer.listen( PORT, resolve );
	} );

	( globalThis as any ).__EUROMAIL_MOCK_SERVER__ = mockServer;
}
