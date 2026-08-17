/**
 * Tiny fetch wrapper for the public widget REST surface.
 *
 * Responsibilities: JSON handling, error normalization (ApiError with HTTP
 * status, WP error code and Retry-After), X-Agy-Session header injection,
 * lazy session creation, and the retry-once-on-401 rule. All requests are
 * sent cookieless (credentials: 'omit') — visitor auth is the HMAC session
 * token only, which keeps the endpoints cache-proof and cookie-banner
 * neutral.
 *
 * The REST base comes from the element's data-rest attribute (output of
 * rest_url( 'agentyllo/v1' )); plain-permalink '?rest_route=' bases work
 * because paths are appended verbatim after stripping the trailing slash.
 */
import type { AssistantMessage } from '../../shared/blocks';
import type { SessionInfo } from './state';

/**
 * GET /config payload (server-shaped; every field the widget chrome needs,
 * all strings pre-translated server-side via __()).
 */
export type WidgetConfig = {
	enabled: boolean;
	assistant_name: string;
	position: 'bottom_right' | 'bottom_left';
	theme: 'auto' | 'light' | 'dark';
	primary_color: string;
	welcome_message: string;
	launcher_teaser: string;
	show_thumbnails: boolean;
	show_internal_links: boolean;
	animations: boolean;
	z_index: number;
	/** Site/content language tag (sets the shadow host lang attribute). */
	lang?: string;
	/** Art. 50 disclosure badge, e.g. 'Automated assistant' in classic mode. */
	badge?: string;
	/** Art. 50 footer disclosure line. */
	footer_note?: string;
	/** Removable server-side via the agy_powered_by filter (null = removed). */
	powered_by?: { label: string; url: string } | null;
	/** Optional suggestion chips. */
	suggestions?: string[];
	/** Translated widget chrome strings keyed like DEFAULT_STRINGS. */
	i18n?: Record< string, string >;
	/** Server-derived color tokens per scheme (from the primary color). */
	tokens?: {
		light?: Record< string, string >;
		dark?: Record< string, string >;
	};
	/** Cache-busting fingerprint of the settings that shaped this payload. */
	config_hash?: string;
	/** Pre-chat registration gate (GDPR). */
	gate?: GateConfig;
	/** Privacy policy URL for the footer link. */
	privacy_url?: string;
	/** Published AI transparency page URL (Art. 50), '' when none. */
	transparency_url?: string;
	/** Whether an AI tier may answer (operating mode ≠ classic). */
	ai_mode?: boolean;
	/** Preferred transport for POST /messages: 'stream' (SSE) or 'buffered'. */
	transport?: 'stream' | 'buffered';
	/** Browser AI (T4) opt-in enabled by the site owner — capability probe only in core. */
	browser_ai?: boolean;
};

/**
 * Live callbacks for the streaming transport. All optional; when the server
 * answers buffered JSON, none fire and the message resolves as before.
 */
export type StreamHooks = {
	/** A pipeline status event (queued|understanding|searching|…) arrived live. */
	onStatus?: ( state: string, ts: number ) => void;
	/** A text delta of the (preview) answer arrived. */
	onDelta?: ( text: string ) => void;
	/** The server discarded the preview: clear what was shown so far. */
	onReset?: () => void;
};

export type GateConfig = {
	enabled: boolean;
	fields: Array< 'name' | 'email' >;
	privacy_checkbox: boolean;
	checkbox_label: string;
	policy_url: string;
	intro: string;
	policy_version: string;
};

/**
 * Raw GET /config payload as the server ships it. The widget consumes the
 * flattened WidgetConfig; normalizeConfig() bridges the two so the server
 * contract (disclosure object, starters, tokens) stays canonical.
 */
type RawConfig = Partial< WidgetConfig > & {
	disclosure?: {
		badge?: string;
		footer_note?: string;
		powered_by?: { label: string; url: string } | null;
		privacy_url?: string;
		transparency_url?: string;
	};
	starters?: string[];
};

function normalizeConfig( raw: RawConfig ): WidgetConfig {
	const primary =
		raw.primary_color || raw.tokens?.light?.primary || raw.tokens?.dark?.primary || '';

	return {
		enabled: !! raw.enabled,
		assistant_name: raw.assistant_name || '',
		position: 'bottom_left' === raw.position ? 'bottom_left' : 'bottom_right',
		theme: 'light' === raw.theme || 'dark' === raw.theme ? raw.theme : 'auto',
		primary_color: primary,
		welcome_message: raw.welcome_message || '',
		launcher_teaser: raw.launcher_teaser || '',
		show_thumbnails: false !== raw.show_thumbnails,
		show_internal_links: false !== raw.show_internal_links,
		animations: false !== raw.animations,
		z_index: Number( raw.z_index ) || 99990,
		lang: raw.lang,
		badge: raw.badge ?? raw.disclosure?.badge,
		footer_note: raw.footer_note ?? raw.disclosure?.footer_note,
		powered_by:
			undefined !== raw.powered_by ? raw.powered_by : raw.disclosure?.powered_by ?? null,
		suggestions: raw.suggestions ?? raw.starters ?? [],
		i18n: raw.i18n,
		tokens: raw.tokens,
		config_hash: raw.config_hash,
		gate: raw.gate && raw.gate.enabled
			? {
					enabled: true,
					fields: Array.isArray( raw.gate.fields ) ? raw.gate.fields : [],
					privacy_checkbox: false !== raw.gate.privacy_checkbox,
					checkbox_label: raw.gate.checkbox_label || '',
					policy_url: raw.gate.policy_url || '',
					intro: raw.gate.intro || '',
					policy_version: raw.gate.policy_version || '1',
			  }
			: undefined,
		privacy_url: raw.privacy_url ?? raw.disclosure?.privacy_url ?? '',
		transparency_url: raw.transparency_url ?? raw.disclosure?.transparency_url ?? '',
		ai_mode: !! raw.ai_mode,
		transport: 'buffered' === raw.transport ? 'buffered' : 'stream',
		browser_ai: !! raw.browser_ai,
	};
}

export class ApiError extends Error {
	constructor(
		public readonly status: number,
		public readonly code: string,
		message: string,
		public readonly retryAfter?: number
	) {
		super( message );
		this.name = 'ApiError';
	}
}

/**
 * Session accessors supplied by the caller (WidgetState owns storage; the
 * wrapper only decides when to mint or drop a token).
 */
export type SessionHooks = {
	get: () => SessionInfo | null;
	set: ( session: SessionInfo ) => void;
	clear: () => void;
};

export class AgyApi {
	private readonly base: string;

	constructor(
		restBase: string,
		private readonly session: SessionHooks
	) {
		this.base = restBase.replace( /\/+$/, '' );
	}

	/**
	 * Public, cacheable-by-page but cookie-free widget configuration.
	 */
	public async config(): Promise< WidgetConfig > {
		const raw = await this.request< RawConfig >( '/config', {
			method: 'GET',
			credentials: 'omit',
		} );

		return normalizeConfig( raw || {} );
	}

	/**
	 * Send a visitor message. Creates the session lazily on first use; on a
	 * 401 (expired/invalid token) the session is recreated once and the
	 * message retried — a second 401 propagates.
	 */
	public async sendMessage(
		text: string,
		hooks: StreamHooks = {},
		transport: 'stream' | 'buffered' = 'stream'
	): Promise< AssistantMessage > {
		let token = await this.ensureSession();
		try {
			return await this.postMessage( text, token, hooks, transport );
		} catch ( e ) {
			if ( e instanceof ApiError && 401 === e.status ) {
				this.session.clear();
				token = await this.ensureSession();
				return await this.postMessage( text, token, hooks, transport );
			}
			throw e;
		}
	}

	/**
	 * POST /messages with the streaming ladder: ask for text/event-stream;
	 * if the server streams, consume SSE (status/delta/reset/message) and
	 * resolve with the authoritative `message` event; if it answers JSON
	 * (buffered transport, cache layer, older server), resolve as before.
	 */
	private async postMessage(
		text: string,
		token: string,
		hooks: StreamHooks,
		transport: 'stream' | 'buffered'
	): Promise< AssistantMessage > {
		const wantStream = 'stream' === transport && 'undefined' !== typeof ReadableStream;
		let res: Response;
		try {
			res = await fetch( this.base + '/messages', {
				method: 'POST',
				credentials: 'omit',
				headers: {
					'Content-Type': 'application/json',
					'X-Agy-Session': token,
					Accept: wantStream ? 'text/event-stream, application/json' : 'application/json',
				},
				body: JSON.stringify( { text } ),
			} );
		} catch ( e ) {
			throw new ApiError( 0, 'agy_network', 'Network error.' );
		}

		const contentType = ( res.headers.get( 'Content-Type' ) || '' ).toLowerCase();
		if ( res.ok && contentType.indexOf( 'text/event-stream' ) >= 0 && res.body ) {
			return this.readStream( res.body, hooks );
		}

		let body: any = null;
		try {
			body = await res.json();
		} catch ( e ) {
			body = null;
		}
		if ( ! res.ok ) {
			throw this.httpError( res, body );
		}
		if ( null === body ) {
			throw new ApiError( res.status, 'agy_bad_json', 'Malformed response.' );
		}
		return this.normalizeMessage( body );
	}

	/**
	 * Minimal SSE reader over a fetch body. Events: status {state, ts},
	 * delta {t}, reset {}, message {payload}, done. Resolves on `message`
	 * (or rejects when the stream ends without one).
	 */
	private async readStream(
		body: ReadableStream< Uint8Array >,
		hooks: StreamHooks
	): Promise< AssistantMessage > {
		const reader = body.getReader();
		const decoder = new TextDecoder();
		let buffer = '';
		let eventName = '';
		let dataLines: string[] = [];
		let message: AssistantMessage | null = null;

		const dispatch = () => {
			if ( 0 === dataLines.length && '' === eventName ) {
				return;
			}
			const name = eventName;
			const raw = dataLines.join( '\n' );
			eventName = '';
			dataLines = [];
			let data: any = {};
			try {
				data = raw ? JSON.parse( raw ) : {};
			} catch ( e ) {
				data = {};
			}
			if ( 'status' === name && hooks.onStatus ) {
				hooks.onStatus( String( data.state || '' ), Number( data.ts ) || 0 );
			} else if ( 'delta' === name && hooks.onDelta ) {
				hooks.onDelta( String( data.t || '' ) );
			} else if ( 'reset' === name && hooks.onReset ) {
				hooks.onReset();
			} else if ( 'message' === name ) {
				message = this.normalizeMessage( data );
			}
		};

		const handleLine = ( line: string ) => {
			if ( '' === line ) {
				dispatch();
				return;
			}
			if ( ':' === line[ 0 ] ) {
				return;
			}
			const colon = line.indexOf( ':' );
			const field = colon < 0 ? line : line.slice( 0, colon );
			let value = colon < 0 ? '' : line.slice( colon + 1 );
			if ( ' ' === value[ 0 ] ) {
				value = value.slice( 1 );
			}
			if ( 'event' === field ) {
				eventName = value;
			} else if ( 'data' === field ) {
				dataLines.push( value );
			}
		};

		try {
			// eslint-disable-next-line no-constant-condition
			while ( true ) {
				const { value, done } = await reader.read();
				if ( done ) {
					break;
				}
				buffer += decoder.decode( value, { stream: true } );
				let nl = buffer.indexOf( '\n' );
				while ( nl >= 0 ) {
					const line = buffer.slice( 0, nl ).replace( /\r$/, '' );
					buffer = buffer.slice( nl + 1 );
					handleLine( line );
					nl = buffer.indexOf( '\n' );
				}
				if ( message ) {
					break;
				}
			}
		} catch ( e ) {
			if ( ! message ) {
				throw new ApiError( 0, 'agy_stream', 'Stream interrupted.' );
			}
		}
		if ( ! message ) {
			// Trailing event without a final blank line.
			if ( buffer ) {
				handleLine( buffer.replace( /\r$/, '' ) );
			}
			dispatch();
		}
		if ( ! message ) {
			throw new ApiError( 0, 'agy_stream', 'Stream ended without a message.' );
		}
		return message;
	}

	/**
	 * Complete the pre-chat gate: POST /consent with the visitor's details.
	 * On success the session is flagged gated (persisted by the state).
	 */
	public async consent( name: string, email: string, accepted: boolean ): Promise< void > {
		let token = await this.ensureSession();
		const body = { name, email, accepted };
		try {
			await this.post( '/consent', body, token );
		} catch ( e ) {
			if ( e instanceof ApiError && 401 === e.status ) {
				this.session.clear();
				token = await this.ensureSession();
				await this.post( '/consent', body, token );
			} else {
				throw e;
			}
		}
		const live = this.session.get();
		if ( live ) {
			this.session.set( { ...live, gated: true } );
		}
	}

	/**
	 * Whether the current session already passed the gate.
	 */
	public isGated(): boolean {
		const live = this.session.get();
		return !! ( live && live.gated && live.expires * 1000 > Date.now() );
	}

	/**
	 * Drop the gated flag (server said the gate is required again).
	 */
	public ungate(): void {
		const live = this.session.get();
		if ( live && live.gated ) {
			this.session.set( { ...live, gated: false } );
		}
	}

	/**
	 * Reuse the live session token or mint one via POST /session.
	 */
	private async ensureSession(): Promise< string > {
		const live = this.session.get();
		if ( live && live.expires * 1000 > Date.now() + 5000 ) {
			return live.token;
		}

		const body = await this.request< any >( '/session', {
			method: 'POST',
			credentials: 'omit',
			headers: { 'Content-Type': 'application/json' },
			body: '{}',
		} );

		const raw = body && 'string' === typeof body.token ? body : body && body.session;
		if ( ! raw || 'string' !== typeof raw.token ) {
			throw new ApiError( 0, 'agy_bad_session', 'Could not create a chat session.' );
		}

		const session: SessionInfo = { token: raw.token, expires: Number( raw.expires ) || 0 };
		this.session.set( session );

		return session.token;
	}

	private post( path: string, data: unknown, token: string ): Promise< any > {
		return this.request< any >( path, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				'Content-Type': 'application/json',
				'X-Agy-Session': token,
			},
			body: JSON.stringify( data ),
		} );
	}

	private async request< T >( path: string, init: RequestInit ): Promise< T > {
		let res: Response;
		try {
			res = await fetch( this.base + path, init );
		} catch ( e ) {
			throw new ApiError( 0, 'agy_network', 'Network error.' );
		}

		let body: any = null;
		try {
			body = await res.json();
		} catch ( e ) {
			body = null;
		}

		if ( ! res.ok ) {
			throw this.httpError( res, body );
		}

		if ( null === body ) {
			throw new ApiError( res.status, 'agy_bad_json', 'Malformed response.' );
		}

		return body as T;
	}

	private httpError( res: Response, body: any ): ApiError {
		const header = parseInt( res.headers.get( 'Retry-After' ) || '', 10 );
		const retryAfter = Number.isFinite( header )
			? header
			: body && body.data && Number( body.data.retry_after ) > 0
				? Number( body.data.retry_after )
				: undefined;
		return new ApiError(
			res.status,
			( body && body.code ) || 'agy_http_' + res.status,
			( body && body.message ) || res.statusText || 'Request failed.',
			retryAfter
		);
	}

	/**
	 * Accept either a bare AssistantMessage or a { message: ... } envelope.
	 */
	private normalizeMessage( body: any ): AssistantMessage {
		const raw = body && Array.isArray( body.blocks ) ? body : body && body.message;
		if ( ! raw || ! Array.isArray( raw.blocks ) ) {
			throw new ApiError( 0, 'agy_bad_message', 'Malformed message payload.' );
		}
		return {
			id: 'string' === typeof raw.id ? raw.id : 'a' + Date.now(),
			role: 'assistant',
			blocks: raw.blocks,
			meta: raw.meta && 'object' === typeof raw.meta ? raw.meta : undefined,
		};
	}
}
