export default async function globalTeardown() {
	const mockServer = ( globalThis as any ).__EUROMAIL_MOCK_SERVER__;

	if ( mockServer ) {
		await new Promise<void>( ( resolve ) => mockServer.close( () => resolve() ) );
	}
}
