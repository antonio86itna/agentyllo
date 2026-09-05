/**
 * Zero-dependency persistent store: in-memory state, debounced atomic JSON
 * writes. Fine for a free tier (per-domain monthly counters, small volume).
 *
 * Shape:
 *   domains:   { [domain]: { token, createdAt } }
 *   tokens:    { [token]:  domain }
 *   usage:     { [`${domain}|${period}`]: count }   // period = YYYY-MM (UTC)
 */
import { mkdirSync, readFileSync, writeFileSync, renameSync } from 'node:fs';
import { dirname } from 'node:path';
import { randomBytes } from 'node:crypto';
import { config } from './config.js';

const state = { domains: {}, tokens: {}, usage: {} };
let dirty = false;
let flushTimer = null;

export function load() {
	try {
		const raw = readFileSync( config.dataFile, 'utf8' );
		const parsed = JSON.parse( raw );
		Object.assign( state, { domains: {}, tokens: {}, usage: {} }, parsed );
	} catch {
		// First run — empty state.
	}
	mkdirSync( dirname( config.dataFile ), { recursive: true } );
}

function scheduleFlush() {
	dirty = true;
	if ( flushTimer ) return;
	flushTimer = setTimeout( flush, 1500 );
}

export function flush() {
	if ( flushTimer ) { clearTimeout( flushTimer ); flushTimer = null; }
	if ( ! dirty ) return;
	dirty = false;
	try {
		mkdirSync( dirname( config.dataFile ), { recursive: true } );
		const tmp = config.dataFile + '.tmp';
		writeFileSync( tmp, JSON.stringify( state ), 'utf8' );
		renameSync( tmp, config.dataFile ); // atomic on the same filesystem
	} catch ( e ) {
		dirty = true; // retry next tick
		console.error( 'state flush failed:', e.message );
	}
}

export function period( d = new Date() ) {
	return d.getUTCFullYear() + '-' + String( d.getUTCMonth() + 1 ).padStart( 2, '0' );
}

export function periodResetAt( d = new Date() ) {
	return Math.floor( Date.UTC( d.getUTCFullYear(), d.getUTCMonth() + 1, 1 ) / 1000 );
}

/** Register (or return the existing token for) a domain. */
export function registerDomain( domain ) {
	if ( state.domains[ domain ] ) {
		return state.domains[ domain ].token;
	}
	const token = 'agyc_' + randomBytes( 24 ).toString( 'hex' );
	state.domains[ domain ] = { token, createdAt: Date.now() };
	state.tokens[ token ] = domain;
	scheduleFlush();
	return token;
}

export function domainForToken( token ) {
	return state.tokens[ token ] || null;
}

export function usageFor( domain ) {
	const key = `${ domain }|${ period() }`;
	const used = state.usage[ key ] || 0;
	return {
		used,
		limit: config.monthlyLimit,
		remaining: Math.max( 0, config.monthlyLimit - used ),
		resets_at: periodResetAt(),
		period: period(),
	};
}

export function incrementUsage( domain ) {
	const key = `${ domain }|${ period() }`;
	state.usage[ key ] = ( state.usage[ key ] || 0 ) + 1;
	scheduleFlush();
	return usageFor( domain );
}

export function stats() {
	return {
		domains: Object.keys( state.domains ).length,
		period: period(),
		usageEntries: Object.keys( state.usage ).length,
	};
}
