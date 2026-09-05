/**
 * OpenRouter engine: discover FREE models, rank them, and complete a chat with
 * API-key rotation + in-request model fallback. Paid models can never be used:
 * every id we ever send ends in ":free" and pricing is verified $0. Failed
 * requests count against a key's daily free quota, so we honour Retry-After
 * and rotate instead of hammering.
 */
import { config } from './config.js';

const BASE = 'https://openrouter.ai/api/v1';

// Free-model list cache.
let freeModels = [];
let freeModelsAt = 0;
const MODELS_TTL_MS = 60 * 60 * 1000; // 1h

// Per-key cooldown (epoch ms until which a key is rate-limited).
const keyCooldownUntil = new Map();
let rr = 0; // round-robin cursor

function isFree( m ) {
	const p = m?.pricing || {};
	const zero = ( v ) => v === 0 || v === '0' || v === '0.0' || parseFloat( v ) === 0;
	return zero( p.prompt ) && zero( p.completion ) && zero( p.request ?? 0 );
}

function rank( models ) {
	const pref = config.preferred;
	const score = ( m ) => {
		const id = ( m.id || '' ).toLowerCase();
		let s = 0;
		for ( let i = 0; i < pref.length; i++ ) {
			if ( id.includes( pref[ i ].toLowerCase() ) ) { s += ( pref.length - i ) * 100; break; }
		}
		s += Math.min( 50, Math.round( ( m.context_length || 0 ) / 8000 ) ); // longer ctx = slight boost
		if ( id.includes( 'instruct' ) || id.includes( 'chat' ) ) s += 10;
		if ( id.includes( 'vision' ) || id.includes( 'coder' ) ) s -= 15; // prefer general chat
		return s;
	};
	return [ ...models ].sort( ( a, b ) => score( b ) - score( a ) );
}

export async function refreshFreeModels( force = false ) {
	if ( ! force && freeModels.length && Date.now() - freeModelsAt < MODELS_TTL_MS ) {
		return freeModels;
	}
	const key = pickKey();
	try {
		const res = await fetch( `${ BASE }/models`, {
			headers: key ? { Authorization: `Bearer ${ key }` } : {},
		} );
		if ( ! res.ok ) throw new Error( 'models ' + res.status );
		const body = await res.json();
		const all = Array.isArray( body?.data ) ? body.data : [];
		const free = all.filter( ( m ) => typeof m.id === 'string' && m.id.endsWith( ':free' ) && isFree( m ) );
		if ( free.length ) {
			freeModels = rank( free ).map( ( m ) => m.id );
			freeModelsAt = Date.now();
		}
	} catch ( e ) {
		console.error( 'refreshFreeModels:', e.message );
	}
	return freeModels;
}

function availableKeys() {
	const now = Date.now();
	return config.openrouterKeys.filter( ( k ) => ( keyCooldownUntil.get( k ) || 0 ) <= now );
}

function pickKey() {
	const keys = availableKeys();
	if ( ! keys.length ) return config.openrouterKeys[ 0 ] || '';
	rr = ( rr + 1 ) % keys.length;
	return keys[ rr ];
}

function coolDown( key, retryAfterSec ) {
	const ms = Math.max( 5, Number( retryAfterSec ) || 60 ) * 1000;
	keyCooldownUntil.set( key, Date.now() + ms );
}

/**
 * Complete a chat. Returns { ok, text, model, tokens_in, tokens_out } or
 * { ok:false, error }.
 */
export async function chat( { messages, system, max_tokens, temperature, json_schema, budget_s } ) {
	if ( config.mock ) {
		return { ok: true, text: '[mock] ' + ( messages?.[ messages.length - 1 ]?.content || '' ).slice( 0, 80 ), model: 'mock/free', tokens_in: 10, tokens_out: 8 };
	}

	const models = await refreshFreeModels();
	if ( ! models.length ) return { ok: false, error: 'no_free_models' };

	const primary = models[ 0 ];
	const fallbacks = models.slice( 0, config.fallbackCount ); // all :free

	const msgs = [];
	if ( system ) msgs.push( { role: 'system', content: system } );
	for ( const m of messages || [] ) {
		if ( m && m.role && typeof m.content === 'string' ) {
			msgs.push( { role: 'assistant' === m.role ? 'assistant' : 'user', content: m.content } );
		}
	}

	const body = {
		model: primary,
		models: fallbacks,          // OpenRouter tries these in order — all free
		messages: msgs,
		max_tokens: Math.max( 16, Math.min( 2000, Number( max_tokens ) || 600 ) ),
	};
	if ( typeof temperature === 'number' ) body.temperature = temperature;
	if ( json_schema ) {
		body.response_format = { type: 'json_object' };
	}

	const budgetMs = Math.max( 8000, Math.min( 60000, ( Number( budget_s ) || 25 ) * 1000 ) );
	const deadline = Date.now() + budgetMs;
	const triedKeys = new Set();

	// Try each currently-available key once (rotating), honouring cooldowns.
	for ( let attempt = 0; attempt < config.openrouterKeys.length; attempt++ ) {
		if ( Date.now() > deadline ) break;
		const key = pickKey();
		if ( ! key || triedKeys.has( key ) ) continue;
		triedKeys.add( key );

		const controller = new AbortController();
		const timer = setTimeout( () => controller.abort(), Math.max( 3000, deadline - Date.now() ) );
		try {
			const res = await fetch( `${ BASE }/chat/completions`, {
				method: 'POST',
				signal: controller.signal,
				headers: {
					Authorization: `Bearer ${ key }`,
					'Content-Type': 'application/json',
					'HTTP-Referer': config.referer,
					'X-Title': config.title,
				},
				body: JSON.stringify( body ),
			} );

			if ( 429 === res.status ) {
				coolDown( key, res.headers.get( 'retry-after' ) );
				continue; // rotate to another key
			}
			if ( 402 === res.status ) {
				// Should never happen with :free ids, but never spend: bail.
				return { ok: false, error: 'paid_blocked' };
			}
			if ( ! res.ok ) {
				continue; // transient — try next key
			}

			const data = await res.json();
			const text = data?.choices?.[ 0 ]?.message?.content || '';
			if ( ! text ) continue;
			return {
				ok: true,
				text,
				model: data?.model || primary,
				tokens_in: data?.usage?.prompt_tokens || 0,
				tokens_out: data?.usage?.completion_tokens || 0,
			};
		} catch ( e ) {
			// timeout/network — try next key
		} finally {
			clearTimeout( timer );
		}
	}

	return { ok: false, error: 'exhausted' };
}
