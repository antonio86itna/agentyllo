/**
 * Minimal widget store: messages, status, session.
 *
 * Persistence model:
 * - Session token in sessionStorage 'agyl_session' as {token, expires}
 *   (Unix seconds; matches SessionManager token payload).
 * - Transcript cache in localStorage 'agyl_transcript' with a TTL equal to
 *   the session expiry, so a visitor who navigates away and reopens the
 *   widget within the session window sees the conversation again.
 *   Privacy note: the cache holds only what the visitor typed plus the
 *   public site content the assistant replied with — no identifiers, no
 *   IP, no cookies; no PII beyond what the visitor chose to type.
 *
 * All storage access is guarded: private-mode/quota failures degrade to an
 * in-memory session (the widget still works, it just forgets on reload).
 */
import type { AssistantMessage, StatusState } from '../../shared/blocks';

export type SessionInfo = {
	token: string;
	/** Unix seconds. */
	expires: number;
	/** True once the pre-chat gate (consent) was completed for this session. */
	gated?: boolean;
};

const SESSION_KEY = 'agyl_session';
const TRANSCRIPT_KEY = 'agyl_transcript';
const MAX_CACHED_MESSAGES = 40;

export class WidgetState {
	public messages: AssistantMessage[] = [];

	public status: StatusState | null = null;

	public session: SessionInfo | null = null;

	constructor() {
		this.session = this.readSession();
		if ( null !== this.session && ! this.isLive( this.session ) ) {
			this.clearSession();
		}
		this.restoreTranscript();
	}

	public hasLiveSession(): boolean {
		return null !== this.session && this.isLive( this.session );
	}

	public setSession( session: SessionInfo ): void {
		this.session = session;
		try {
			window.sessionStorage.setItem( SESSION_KEY, JSON.stringify( session ) );
		} catch ( e ) {
			// Storage unavailable — keep the in-memory copy only.
		}
	}

	public clearSession(): void {
		this.session = null;
		try {
			window.sessionStorage.removeItem( SESSION_KEY );
		} catch ( e ) {
			// Ignore.
		}
	}

	/**
	 * Append a message and refresh the cached transcript.
	 */
	public addMessage( message: AssistantMessage ): void {
		this.messages.push( message );
		this.persistTranscript();
	}

	/**
	 * Write the transcript cache. Only done while a live session exists so
	 * the TTL is always the session expiry.
	 */
	public persistTranscript(): void {
		if ( ! this.hasLiveSession() || null === this.session ) {
			return;
		}
		try {
			window.localStorage.setItem(
				TRANSCRIPT_KEY,
				JSON.stringify( {
					expires: this.session.expires,
					messages: this.messages.slice( -MAX_CACHED_MESSAGES ),
				} )
			);
		} catch ( e ) {
			// Quota/private mode — transcript simply not cached.
		}
	}

	private readSession(): SessionInfo | null {
		try {
			const raw = window.sessionStorage.getItem( SESSION_KEY );
			if ( ! raw ) {
				return null;
			}
			const parsed = JSON.parse( raw );
			if ( parsed && 'string' === typeof parsed.token && 'number' === typeof parsed.expires ) {
				return { token: parsed.token, expires: parsed.expires };
			}
		} catch ( e ) {
			// Corrupt or unavailable — start fresh.
		}
		return null;
	}

	private restoreTranscript(): void {
		try {
			const raw = window.localStorage.getItem( TRANSCRIPT_KEY );
			if ( ! raw ) {
				return;
			}
			const parsed = JSON.parse( raw );
			const expired = ! parsed || 'number' !== typeof parsed.expires || parsed.expires * 1000 <= Date.now();
			if ( expired || ! Array.isArray( parsed.messages ) ) {
				window.localStorage.removeItem( TRANSCRIPT_KEY );
				return;
			}
			this.messages = parsed.messages.filter(
				( m: any ) => m && 'string' === typeof m.id && Array.isArray( m.blocks )
			);
		} catch ( e ) {
			// Corrupt cache — drop it silently.
			try {
				window.localStorage.removeItem( TRANSCRIPT_KEY );
			} catch ( e2 ) {
				// Ignore.
			}
		}
	}

	private isLive( session: SessionInfo ): boolean {
		return session.expires * 1000 > Date.now() + 5000;
	}
}
