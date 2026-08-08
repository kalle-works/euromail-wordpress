'use strict';

/**
 * Minimal stand-in for the euromail.dev API, used by the plugin's e2e
 * tests. No dependencies beyond Node's own http/crypto modules.
 *
 * Endpoints:
 *   GET  /v1/account  -> 200 with account info when Authorization matches
 *                        the test key, 401 with an error envelope otherwise.
 *   POST /v1/emails    -> 202 with a queued send, unless a failure mode was
 *                        armed via POST /_mode.
 *   GET  /v1/emails/:id -> 200 with a canned "delivered" EmailDetails
 *                        payload, for the "Refresh status" e2e scenario.
 *   GET  /_requests    -> every POST /v1/emails body received so far, for
 *                        test assertions.
 *   POST /_mode         -> { "status": 200 | 429 | 500 } arms the next
 *                        POST /v1/emails responses to fail that way.
 */

const http = require( 'http' );
const crypto = require( 'crypto' );

const PORT = process.env.EUROMAIL_MOCK_PORT || 8825;
const VALID_KEY = 'em_live_e2etest';

const state = {
	mode: 200,
	requests: [],
};

function readBody( req ) {
	return new Promise( ( resolve, reject ) => {
		let raw = '';
		req.on( 'data', ( chunk ) => {
			raw += chunk;
		} );
		req.on( 'end', () => {
			if ( '' === raw ) {
				resolve( {} );
				return;
			}
			try {
				resolve( JSON.parse( raw ) );
			} catch ( error ) {
				reject( error );
			}
		} );
		req.on( 'error', reject );
	} );
}

function sendJson( res, statusCode, payload, extraHeaders ) {
	const headers = Object.assign( { 'Content-Type': 'application/json' }, extraHeaders || {} );
	res.writeHead( statusCode, headers );
	res.end( JSON.stringify( payload ) );
}

function errorEnvelope( code, message ) {
	return { error: { code: code, message: message } };
}

function handleAccount( req, res ) {
	const authHeader = req.headers.authorization || '';
	const matches = authHeader === `Bearer ${ VALID_KEY }`;

	if ( ! matches ) {
		sendJson( res, 401, errorEnvelope( 'unauthorized', 'Invalid API key' ) );
		return;
	}

	sendJson( res, 200, { data: { email: 'test@example.com', plan: 'test' } } );
}

async function handleSendEmail( req, res ) {
	let body;
	try {
		body = await readBody( req );
	} catch ( error ) {
		sendJson( res, 400, errorEnvelope( 'invalid_json', 'Request body was not valid JSON' ) );
		return;
	}

	state.requests.push( {
		headers: req.headers,
		body: body,
		receivedAt: new Date().toISOString(),
	} );

	if ( 429 === state.mode ) {
		sendJson( res, 429, errorEnvelope( 'rate_limited', 'Too many requests' ), { 'retry-after': '2' } );
		return;
	}

	if ( 500 === state.mode ) {
		sendJson( res, 500, errorEnvelope( 'server_error', 'Internal server error' ) );
		return;
	}

	sendJson( res, 202, {
		data: {
			id: crypto.randomUUID(),
			message_id: `mock-${ crypto.randomUUID() }`,
			status: 'queued',
		},
	} );
}

function handleGetRequests( req, res ) {
	sendJson( res, 200, { requests: state.requests } );
}

function handleGetEmail( req, res, id ) {
	sendJson( res, 200, {
		data: {
			id: id,
			message_id: `mock-${ id }`,
			status: 'delivered',
			events: [
				{ type: 'delivered', timestamp: '2026-01-01T00:00:00Z' },
			],
		},
	} );
}

async function handleSetMode( req, res ) {
	let body;
	try {
		body = await readBody( req );
	} catch ( error ) {
		sendJson( res, 400, errorEnvelope( 'invalid_json', 'Request body was not valid JSON' ) );
		return;
	}

	const status = parseInt( body.status, 10 );

	if ( ! [ 200, 429, 500 ].includes( status ) ) {
		sendJson( res, 400, errorEnvelope( 'invalid_mode', 'status must be 200, 429 or 500' ) );
		return;
	}

	state.mode = status;
	sendJson( res, 200, { mode: state.mode } );
}

const server = http.createServer( ( req, res ) => {
	const url = new URL( req.url, `http://${ req.headers.host }` );

	if ( 'GET' === req.method && '/v1/account' === url.pathname ) {
		handleAccount( req, res );
		return;
	}

	if ( 'POST' === req.method && '/v1/emails' === url.pathname ) {
		handleSendEmail( req, res );
		return;
	}

	if ( 'GET' === req.method && url.pathname.startsWith( '/v1/emails/' ) ) {
		handleGetEmail( req, res, decodeURIComponent( url.pathname.slice( '/v1/emails/'.length ) ) );
		return;
	}

	if ( 'GET' === req.method && '/_requests' === url.pathname ) {
		handleGetRequests( req, res );
		return;
	}

	if ( 'POST' === req.method && '/_mode' === url.pathname ) {
		handleSetMode( req, res );
		return;
	}

	if ( 'POST' === req.method && '/_reset' === url.pathname ) {
		state.mode = 200;
		state.requests = [];
		sendJson( res, 200, { reset: true } );
		return;
	}

	sendJson( res, 404, errorEnvelope( 'not_found', 'No such route' ) );
} );

if ( require.main === module ) {
	server.listen( PORT, () => {
		// eslint-disable-next-line no-console
		console.log( `Euromail mock API listening on port ${ PORT }` );
	} );
}

module.exports = server;
