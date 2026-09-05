/**
 * Configuration — from environment (Plesk custom env vars or a .env file).
 * A tiny .env loader keeps the app dependency-free.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname( fileURLToPath( import.meta.url ) );
const ROOT = resolve( __dirname, '..' );

// Load .env (if present) without a dependency.
try {
	const raw = readFileSync( resolve( ROOT, '.env' ), 'utf8' );
	for ( const line of raw.split( '\n' ) ) {
		const m = line.match( /^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/ );
		if ( m && process.env[ m[ 1 ] ] === undefined ) {
			let v = m[ 2 ].trim();
			if ( ( v.startsWith( '"' ) && v.endsWith( '"' ) ) || ( v.startsWith( "'" ) && v.endsWith( "'" ) ) ) {
				v = v.slice( 1, -1 );
			}
			process.env[ m[ 1 ] ] = v;
		}
	}
} catch {
	// No .env file — rely on real environment variables.
}

const list = ( s ) => ( s || '' ).split( ',' ).map( ( x ) => x.trim() ).filter( Boolean );

export const config = {
	root: ROOT,
	port: Number( process.env.PORT || 8787 ),
	openrouterKeys: list( process.env.OPENROUTER_KEYS ),
	monthlyLimit: Number( process.env.MONTHLY_LIMIT_PER_DOMAIN || 300 ),
	perMinute: Number( process.env.PER_MINUTE_PER_DOMAIN || 8 ),
	registerPerHour: Number( process.env.REGISTER_PER_HOUR_PER_IP || 5 ),
	dataFile: resolve( ROOT, process.env.DATA_FILE || './data/state.json' ),
	preferred: list( process.env.PREFERRED_MODELS ),
	fallbackCount: Math.max( 1, Number( process.env.MODEL_FALLBACK_COUNT || 4 ) ),
	referer: process.env.SITE_REFERER || 'https://www.agentyllo.com',
	title: process.env.SITE_TITLE || 'Agentyllo Cloud',
	adminToken: process.env.ADMIN_TOKEN || '',
	mock: '1' === String( process.env.MOCK || '0' ),
};
