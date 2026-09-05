/**
 * Agentyllo Cloud proxy — HTTP API consumed by the plugin's CloudClient.
 *
 *   POST /v1/register {domain, site_url, plugin_version} -> {site_token}
 *   POST /v1/chat     (Bearer token) {messages,system,max_tokens,...} ->
 *                     {ok, text, model, usage:{tokens_in,tokens_out,remaining,limit,resets_at,period}}
 *   GET  /v1/usage    (Bearer token) -> {used,limit,remaining,resets_at,period}
 *   GET  /healthz     -> {ok:true}
 *   GET  /admin/stats (Bearer ADMIN_TOKEN) -> counters
 *
 * Zero runtime dependencies (built-in http + fetch). Deploy behind Plesk /
 * Passenger or run standalone. See README.md.
 */
import http from 'node:http';
import { config } from './config.js';
import * as store from './store.js';
import { chat, refreshFreeModels } from './openrouter.js';

store.load();

/* ── tiny sliding-window rate limiter (in-memory) ─────────────────────── */
const hits = new Map(); // key -> number[] (timestamps)
function allow( key, limit, windowMs ) {
	const now = Date.now();
	const arr = ( hits.get( key ) || [] ).filter( ( t ) => now - t < windowMs );
	if ( arr.length >= limit ) { hits.set( key, arr ); return false; }
	arr.push( now );
	hits.set( key, arr );
	return true;
}
setInterval( () => {
	const now = Date.now();
	for ( const [ k, arr ] of hits ) {
		const keep = arr.filter( ( t ) => now - t < 3600_000 );
		if ( keep.length ) hits.set( k, keep ); else hits.delete( k );
	}
}, 600_000 ).unref();

/* ── helpers ──────────────────────────────────────────────────────────── */
function send( res, status, obj ) {
	const body = JSON.stringify( obj );
	res.writeHead( status, {
		'Content-Type': 'application/json; charset=utf-8',
		'Cache-Control': 'no-store',
		'Content-Length': Buffer.byteLength( body ),
	} );
	res.end( body );
}

function clientIp( req ) {
	const xf = req.headers[ 'x-forwarded-for' ];
	if ( typeof xf === 'string' && xf ) return xf.split( ',' )[ 0 ].trim();
	return req.socket?.remoteAddress || '0.0.0.0';
}

function bearer( req ) {
	const h = req.headers.authorization || '';
	return h.startsWith( 'Bearer ' ) ? h.slice( 7 ).trim() : '';
}

function readBody( req, cap = 256 * 1024 ) {
	return new Promise( ( resolve, reject ) => {
		let size = 0;
		const chunks = [];
		req.on( 'data', ( c ) => {
			size += c.length;
			if ( size > cap ) { reject( new Error( 'too_large' ) ); req.destroy(); return; }
			chunks.push( c );
		} );
		req.on( 'end', () => {
			try { resolve( chunks.length ? JSON.parse( Buffer.concat( chunks ).toString( 'utf8' ) ) : {} ); }
			catch { reject( new Error( 'bad_json' ) ); }
		} );
		req.on( 'error', reject );
	} );
}

function normalizeDomain( input, siteUrl ) {
	let d = ( input || '' ).toString().trim().toLowerCase();
	if ( ! d && siteUrl ) {
		try { d = new URL( siteUrl ).host.toLowerCase(); } catch { /* ignore */ }
	}
	d = d.replace( /^www\./, '' ).replace( /[^a-z0-9.\-:]/g, '' );
	return d;
}

/* ── routes ───────────────────────────────────────────────────────────── */
async function handleRegister( req, res ) {
	const ip = clientIp( req );
	if ( ! allow( 'reg:' + ip, config.registerPerHour, 3600_000 ) ) {
		return send( res, 429, { ok: false, message: 'Too many registrations, try later.' } );
	}
	const body = await readBody( req );
	const domain = normalizeDomain( body.domain, body.site_url );
	if ( ! domain || domain.length < 3 || ! domain.includes( '.' ) ) {
		return send( res, 400, { ok: false, message: 'Invalid domain.' } );
	}
	const token = store.registerDomain( domain );
	return send( res, 200, { ok: true, site_token: token, domain } );
}

async function handleChat( req, res ) {
	const token = bearer( req );
	const domain = store.domainForToken( token );
	if ( ! domain ) return send( res, 401, { ok: false, error: 'auth' } );

	if ( ! allow( 'min:' + domain, config.perMinute, 60_000 ) ) {
		return send( res, 429, { ok: false, error: 'rate', usage: store.usageFor( domain ) } );
	}
	const usage = store.usageFor( domain );
	if ( usage.remaining <= 0 ) {
		return send( res, 429, { ok: false, error: 'quota', usage } );
	}

	const body = await readBody( req );
	const result = await chat( {
		messages: Array.isArray( body.messages ) ? body.messages : [],
		system: typeof body.system === 'string' ? body.system : '',
		max_tokens: body.max_tokens,
		temperature: body.temperature,
		json_schema: body.json_schema || null,
		budget_s: body.budget_s,
	} );

	if ( ! result.ok ) {
		// Do NOT charge the domain for our own upstream failures.
		return send( res, 200, { ok: false, error: result.error, usage: store.usageFor( domain ) } );
	}

	const after = store.incrementUsage( domain );
	return send( res, 200, {
		ok: true,
		text: result.text,
		model: result.model,
		usage: {
			tokens_in: result.tokens_in,
			tokens_out: result.tokens_out,
			used: after.used,
			limit: after.limit,
			remaining: after.remaining,
			resets_at: after.resets_at,
			period: after.period,
		},
	} );
}

function handleUsage( req, res ) {
	const domain = store.domainForToken( bearer( req ) );
	if ( ! domain ) return send( res, 401, { ok: false, error: 'auth' } );
	return send( res, 200, store.usageFor( domain ) );
}

async function handleTelemetry( req, res ) {
	// Anonymous, unauthenticated technical telemetry (opt-in on the plugin
	// side). Rate-limited per IP; appended to a JSONL log for the dashboard.
	const ip = clientIp( req );
	if ( ! allow( 'tel:' + ip, 30, 3600_000 ) ) return send( res, 429, { ok: false } );
	const body = await readBody( req, 64 * 1024 );
	store.appendTelemetry( {
		at: Math.floor( Date.now() / 1000 ),
		domain: String( body.domain || '' ).slice( 0, 120 ),
		plugin: String( body.plugin_version || '' ).slice( 0, 20 ),
		wp: String( body.wp || '' ).slice( 0, 20 ),
		php: String( body.php || '' ).slice( 0, 20 ),
		locale: String( body.locale || '' ).slice( 0, 12 ),
		events: Array.isArray( body.events ) ? body.events.slice( 0, 50 ).map( ( e ) => ( {
			fp: String( e.fp || '' ).slice( 0, 16 ),
			code: String( e.code || '' ).slice( 0, 40 ),
			count: Math.max( 1, Math.min( 100000, Number( e.count ) || 1 ) ),
		} ) ) : [],
	} );
	return send( res, 200, { ok: true } );
}

/* ── server ───────────────────────────────────────────────────────────── */
const server = http.createServer( async ( req, res ) => {
	try {
		const url = new URL( req.url, 'http://localhost' );
		const path = url.pathname.replace( /\/+$/, '' ) || '/';

		if ( 'GET' === req.method && ( '/healthz' === path || '/' === path ) ) {
			return send( res, 200, { ok: true, service: 'agentyllo-cloud', mock: config.mock } );
		}
		if ( 'POST' === req.method && '/v1/register' === path ) return await handleRegister( req, res );
		if ( 'POST' === req.method && '/v1/chat' === path ) return await handleChat( req, res );
		if ( 'GET' === req.method && '/v1/usage' === path ) return handleUsage( req, res );
		if ( 'POST' === req.method && '/v1/telemetry' === path ) return await handleTelemetry( req, res );
		if ( 'GET' === req.method && '/admin/stats' === path ) {
			if ( ! config.adminToken || bearer( req ) !== config.adminToken ) return send( res, 401, { ok: false } );
			return send( res, 200, { ok: true, ...store.stats() } );
		}
		return send( res, 404, { ok: false, error: 'not_found' } );
	} catch ( e ) {
		const code = 'too_large' === e.message ? 413 : ( 'bad_json' === e.message ? 400 : 500 );
		return send( res, code, { ok: false, error: e.message } );
	}
} );

for ( const sig of [ 'SIGINT', 'SIGTERM' ] ) {
	process.on( sig, () => { store.flush(); server.close( () => process.exit( 0 ) ); } );
}

server.listen( config.port, () => {
	console.log( `Agentyllo Cloud proxy on :${ config.port } (mock=${ config.mock }, keys=${ config.openrouterKeys.length })` );
	if ( ! config.mock ) refreshFreeModels( true ).then( ( m ) => console.log( `free models: ${ m.length }` ) );
} );
