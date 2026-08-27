/**
 * <agentyllo-chat> — the public site chat widget.
 *
 * Vanilla web component, zero runtime dependencies, open shadow root.
 * Boot: read data-rest → GET /config (cookieless) → if disabled remove
 * self, else render the launcher immediately; the panel (role=dialog,
 * aria-modal, focus-trapped) opens on click. Sessions are created lazily
 * on the first user message. Classic-mode "thinking" UX replays the
 * pipeline's buffered status events with a minimum of 400ms per state;
 * prefers-reduced-motion collapses that to a static "Working…" line.
 *
 * Art. 50 disclosure surfaces ship from day one: header badge, footer
 * disclosure line and the (filter-removable) Powered by Agentyllo link —
 * all sourced from the /config payload.
 */
import type { AssistantMessage, StatusEvent } from '../shared/blocks';
import { AgyApi, ApiError } from './core/api';
import type { WidgetConfig } from './core/api';
import { WidgetState } from './core/state';
import { blocksToText, renderBlocks } from './ui/renderer';
import type { RenderOptions } from './ui/renderer';
import { adoptStyles } from './ui/styles';

const MAX_LEN = 1000;
const COUNTER_AT = 900;
const MIN_STATE_MS = 400;
const MAX_STATE_MS = 1200;

/**
 * English fallbacks; the /config i18n map (server-side __() strings)
 * overrides every key, which is how the widget chrome gets translated
 * without any @wordpress runtime.
 */
const DEFAULT_STRINGS: Record< string, string > = {
	assistant: 'Assistant',
	badge: 'Automated assistant',
	footer_note: 'AI responses may contain mistakes — verify important information.',
	open: 'Open chat',
	close: 'Close chat',
	send: 'Send',
	input_label: 'Type your message',
	placeholder: 'Ask a question…',
	suggestions: 'Suggested questions',
	replying: 'Assistant is replying',
	working: 'Working…',
	queued: 'Queued…',
	understanding: 'Understanding your question…',
	searching: 'Searching this site…',
	checking_products: 'Checking products…',
	linking: 'Finding related pages…',
	verifying: 'Verifying details…',
	generating: 'Preparing an answer…',
	formatting: 'Formatting the answer…',
	error: 'Something went wrong. Please try again.',
	rate_limited: 'A short pause — you can send another message in %ds.',
	rate_limited_over: 'You can send messages again.',
	in_stock: 'In stock',
	low_stock: 'Low stock',
	out_of_stock: 'Out of stock',
	add_to_cart: 'Add to cart',
	gate_intro: 'Before we start, please tell us who you are.',
	gate_name: 'Your name',
	gate_email: 'Your email',
	gate_accept: 'I have read and accept the privacy policy.',
	gate_policy: 'Privacy policy',
	gate_start: 'Start chatting',
	gate_error: 'Please fill in the required fields.',
	privacy: 'Privacy',
	transparency: 'About this assistant',
};

const SVG_NS = 'http://www.w3.org/2000/svg';

const ICON_CHAT = 'M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z';
const ICON_CLOSE = 'M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12 19 6.4 17.6 5 12 10.6 6.4 5z';
const ICON_SEND = 'M2 21l21-9L2 3v7l15 2-15 2v7z';

function svgIcon( d: string, size = 24 ): SVGSVGElement {
	const svg = document.createElementNS( SVG_NS, 'svg' );
	svg.setAttribute( 'viewBox', '0 0 24 24' );
	svg.setAttribute( 'width', String( size ) );
	svg.setAttribute( 'height', String( size ) );
	svg.setAttribute( 'aria-hidden', 'true' );
	svg.setAttribute( 'focusable', 'false' );
	const path = document.createElementNS( SVG_NS, 'path' );
	path.setAttribute( 'd', d );
	path.setAttribute( 'fill', 'currentColor' );
	svg.appendChild( path );
	return svg;
}

function delay( ms: number ): Promise< void > {
	return new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );
}

export class AgentylloChat extends HTMLElement {
	private root: ShadowRoot;

	private api!: AgyApi;

	private state!: WidgetState;

	private config!: WidgetConfig;

	private strings: Record< string, string > = DEFAULT_STRINGS;

	private renderOpts: RenderOptions = {};

	private launcher!: HTMLButtonElement;

	private teaser: HTMLDivElement | null = null;

	private panel!: HTMLDivElement;

	private log!: HTMLDivElement;

	private statusEl!: HTMLDivElement;

	private chipsEl!: HTMLDivElement;

	private textarea!: HTMLTextAreaElement;

	private counter!: HTMLSpanElement;

	private sendBtn!: HTMLButtonElement;

	private announcer!: HTMLDivElement;

	private composerEl!: HTMLFormElement;

	private gateEl: HTMLFormElement | null = null;

	private reducedMotion = false;

	private busy = false;

	private cooldownTimer = 0;

	private booted = false;

	private liveQueue: string[] = [];

	private liveBusy = false;

	constructor() {
		super();
		this.root = this.attachShadow( { mode: 'open' } );
	}

	public connectedCallback(): void {
		if ( this.booted ) {
			return;
		}
		this.booted = true;
		void this.boot();
	}

	public disconnectedCallback(): void {
		if ( 0 !== this.cooldownTimer ) {
			window.clearInterval( this.cooldownTimer );
			this.cooldownTimer = 0;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	private async boot(): Promise< void > {
		const rest = this.getAttribute( 'data-rest' );
		if ( ! rest ) {
			// eslint-disable-next-line no-console
			console.warn( 'agentyllo-chat: missing data-rest attribute.' );
			return;
		}

		this.state = new WidgetState();
		this.api = new AgyApi( rest, {
			get: () => this.state.session,
			set: ( s ) => this.state.setSession( s ),
			clear: () => this.state.clearSession(),
		} );

		let config: WidgetConfig;
		try {
			config = await this.api.config();
		} catch ( e ) {
			// Config unreachable — the widget quietly stands down.
			this.remove();
			return;
		}

		if ( ! config || ! config.enabled ) {
			this.remove();
			return;
		}

		this.config = config;
		this.strings = { ...DEFAULT_STRINGS, ...( config.i18n || {} ) };
		this.renderOpts = {
			showThumbnails: false !== config.show_thumbnails,
			text: {
				in_stock: this.t( 'in_stock' ),
				low_stock: this.t( 'low_stock' ),
				out_of_stock: this.t( 'out_of_stock' ),
				add_to_cart: this.t( 'add_to_cart' ),
			},
		};
		this.reducedMotion =
			'function' === typeof window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		this.applyConfig();
		this.probeBrowserAi();
		adoptStyles( this.root );
		this.buildLauncher();
		this.buildPanel();
		this.restoreMessages();
	}

	/**
	 * Browser AI (T4) plumbing: when the owner enabled it, expose the WebGPU
	 * capability on the host and notify companion scripts (the in-browser
	 * engine ships with the Local AI companion; core only probes and never
	 * downloads anything without an explicit visitor opt-in there).
	 */
	private probeBrowserAi(): void {
		if ( ! this.config.browser_ai ) {
			return;
		}
		const webgpu = 'gpu' in navigator;
		this.setAttribute( 'data-agyl-webgpu', webgpu ? '1' : '0' );
		document.dispatchEvent(
			new CustomEvent( 'agyl:browser-ai', { detail: { webgpu, element: this } } )
		);
	}

	private applyConfig(): void {
		const c = this.config;
		if ( c.primary_color ) {
			this.style.setProperty( '--agy-primary', c.primary_color );
		}
		// Server-derived, WCAG-checked foreground for the primary color. The
		// light/dark surface palettes stay in the stylesheet (inline host
		// styles would defeat the [data-theme] / prefers-color-scheme rules).
		const primaryFg = c.tokens?.light?.primary_fg || c.tokens?.dark?.primary_fg;
		if ( primaryFg ) {
			this.style.setProperty( '--agy-primary-fg', primaryFg );
		}
		if ( c.lang ) {
			this.setAttribute( 'lang', c.lang );
		}
		if ( c.z_index ) {
			this.style.setProperty( '--agy-z', String( c.z_index ) );
		}
		this.setAttribute( 'data-theme', c.theme || 'auto' );
		this.setAttribute( 'data-position', 'bottom_left' === c.position ? 'bottom_left' : 'bottom_right' );
		if ( false === c.animations ) {
			this.setAttribute( 'data-animations', '0' );
		}
		if ( c.lang ) {
			this.setAttribute( 'lang', c.lang );
		}
	}

	private t( key: string ): string {
		return this.strings[ key ] || DEFAULT_STRINGS[ key ] || key;
	}

	/* ------------------------------------------------------------------ */
	/* DOM construction                                                   */
	/* ------------------------------------------------------------------ */

	private buildLauncher(): void {
		const launcher = document.createElement( 'button' );
		launcher.type = 'button';
		launcher.className = 'agy-launcher';
		launcher.setAttribute( 'aria-label', this.t( 'open' ) );
		launcher.setAttribute( 'aria-expanded', 'false' );
		launcher.setAttribute( 'aria-controls', 'agy-panel' );
		launcher.setAttribute( 'aria-haspopup', 'dialog' );
		launcher.appendChild( svgIcon( ICON_CHAT, 26 ) );
		launcher.addEventListener( 'click', () => this.toggle() );
		this.launcher = launcher;
		this.root.appendChild( launcher );

		if ( this.config.launcher_teaser && 0 === this.state.messages.length ) {
			const teaser = document.createElement( 'div' );
			teaser.className = 'agy-teaser';
			// Decorative duplicate of the launcher's purpose — hidden from AT.
			teaser.setAttribute( 'aria-hidden', 'true' );
			teaser.textContent = this.config.launcher_teaser;
			teaser.addEventListener( 'click', () => this.open() );
			this.teaser = teaser;
			this.root.appendChild( teaser );
		}
	}

	private buildPanel(): void {
		const panel = document.createElement( 'div' );
		panel.className = 'agy-panel';
		panel.id = 'agy-panel';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );
		panel.setAttribute( 'aria-label', this.config.assistant_name || this.t( 'assistant' ) );
		panel.hidden = true;
		panel.addEventListener( 'keydown', ( e ) => this.onPanelKeydown( e ) );

		panel.appendChild( this.buildHeader() );

		const log = document.createElement( 'div' );
		log.className = 'agy-log';
		log.setAttribute( 'role', 'log' );
		// The visually-hidden announcer owns speech; keep the log silent to
		// avoid double announcements.
		log.setAttribute( 'aria-live', 'off' );
		this.log = log;
		panel.appendChild( log );

		const status = document.createElement( 'div' );
		status.className = 'agy-status';
		status.setAttribute( 'role', 'status' );
		this.statusEl = status;
		panel.appendChild( status );

		panel.appendChild( this.buildChips() );
		if ( this.config.gate && this.config.gate.enabled ) {
			this.gateEl = this.buildGate( this.config.gate );
			panel.appendChild( this.gateEl );
		}
		this.composerEl = this.buildComposer();
		panel.appendChild( this.composerEl );
		panel.appendChild( this.buildFooter() );
		this.refreshGate();

		const announcer = document.createElement( 'div' );
		announcer.className = 'agy-vh';
		announcer.setAttribute( 'aria-live', 'polite' );
		announcer.setAttribute( 'aria-atomic', 'true' );
		this.announcer = announcer;
		panel.appendChild( announcer );

		this.panel = panel;
		this.root.appendChild( panel );
	}

	private buildHeader(): HTMLElement {
		const header = document.createElement( 'header' );
		header.className = 'agy-header';

		const id = document.createElement( 'div' );
		id.className = 'agy-header-id';
		const title = document.createElement( 'span' );
		title.className = 'agy-title';
		title.textContent = this.config.assistant_name || this.t( 'assistant' );
		id.appendChild( title );

		// Art. 50 disclosure badge ('Automated assistant' in classic mode).
		const badge = document.createElement( 'span' );
		badge.className = 'agy-badge';
		badge.textContent = this.config.badge || this.t( 'badge' );
		id.appendChild( badge );
		header.appendChild( id );

		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'agy-close';
		close.setAttribute( 'aria-label', this.t( 'close' ) );
		close.appendChild( svgIcon( ICON_CLOSE, 20 ) );
		close.addEventListener( 'click', () => this.close() );
		header.appendChild( close );

		return header;
	}

	private buildChips(): HTMLElement {
		const chips = document.createElement( 'div' );
		chips.className = 'agy-chips';
		chips.setAttribute( 'role', 'group' );
		chips.setAttribute( 'aria-label', this.t( 'suggestions' ) );
		this.chipsEl = chips;

		const items = ( this.config.suggestions || [] ).filter( ( s ) => '' !== s );
		if ( 0 === items.length || this.state.messages.length > 0 ) {
			chips.hidden = true;
		}

		items.forEach( ( label, index ) => {
			const chip = document.createElement( 'button' );
			chip.type = 'button';
			chip.className = 'agy-chip';
			chip.textContent = label;
			// Roving tabindex: one tab stop for the whole group.
			chip.tabIndex = 0 === index ? 0 : -1;
			chip.addEventListener( 'click', () => {
				this.chipsEl.hidden = true;
				void this.handleSend( label );
			} );
			chips.appendChild( chip );
		} );

		chips.addEventListener( 'keydown', ( e ) => this.onChipsKeydown( e ) );

		return chips;
	}

	private onChipsKeydown( e: KeyboardEvent ): void {
		const keys = [ 'ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Home', 'End' ];
		if ( -1 === keys.indexOf( e.key ) ) {
			return;
		}
		const chips = Array.from( this.chipsEl.querySelectorAll< HTMLButtonElement >( '.agy-chip' ) );
		if ( 0 === chips.length ) {
			return;
		}
		const active = this.root.activeElement as HTMLButtonElement | null;
		let index = chips.indexOf( active as HTMLButtonElement );
		if ( -1 === index ) {
			index = 0;
		}
		if ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) {
			index = ( index + 1 ) % chips.length;
		} else if ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) {
			index = ( index - 1 + chips.length ) % chips.length;
		} else if ( 'Home' === e.key ) {
			index = 0;
		} else {
			index = chips.length - 1;
		}
		e.preventDefault();
		chips.forEach( ( c, i ) => {
			c.tabIndex = i === index ? 0 : -1;
		} );
		chips[ index ].focus();
	}

	/**
	 * Pre-chat gate (GDPR): name/email + privacy checkbox. Field values are
	 * kept in sessionStorage-free memory only until submit; the server
	 * records the consent with the exact wording shown (text hash).
	 * WCAG: visible labels, error text via aria-describedby, no cognitive
	 * test, redundant-entry avoided by restoring typed values on re-render.
	 */
	private buildGate( gate: import( './core/api' ).GateConfig ): HTMLFormElement {
		const form = document.createElement( 'form' );
		form.className = 'agy-gate';
		form.setAttribute( 'aria-label', this.t( 'gate_intro' ) );
		form.noValidate = true;

		const intro = document.createElement( 'p' );
		intro.className = 'agy-gate-intro';
		intro.textContent = gate.intro || this.t( 'gate_intro' );
		form.appendChild( intro );

		const error = document.createElement( 'p' );
		error.className = 'agy-gate-error';
		error.id = 'agy-gate-error';
		error.setAttribute( 'role', 'alert' );
		error.hidden = true;

		const inputs: Record< string, HTMLInputElement > = {};
		for ( const field of gate.fields ) {
			const wrap = document.createElement( 'div' );
			wrap.className = 'agy-gate-field';
			const label = document.createElement( 'label' );
			label.htmlFor = 'agy-gate-' + field;
			label.textContent = 'email' === field ? this.t( 'gate_email' ) : this.t( 'gate_name' );
			const input = document.createElement( 'input' );
			input.id = 'agy-gate-' + field;
			input.name = field;
			input.type = 'email' === field ? 'email' : 'text';
			input.autocomplete = 'email' === field ? 'email' : 'name';
			input.required = true;
			input.maxLength = 190;
			input.setAttribute( 'aria-describedby', error.id );
			inputs[ field ] = input;
			wrap.appendChild( label );
			wrap.appendChild( input );
			form.appendChild( wrap );
		}

		let checkbox: HTMLInputElement | null = null;
		if ( gate.privacy_checkbox ) {
			const wrap = document.createElement( 'div' );
			wrap.className = 'agy-gate-consent';
			checkbox = document.createElement( 'input' );
			checkbox.type = 'checkbox';
			checkbox.id = 'agy-gate-accept';
			checkbox.required = true;
			checkbox.setAttribute( 'aria-describedby', error.id );
			const label = document.createElement( 'label' );
			label.htmlFor = checkbox.id;
			label.textContent = gate.checkbox_label || this.t( 'gate_accept' );
			wrap.appendChild( checkbox );
			wrap.appendChild( label );
			if ( gate.policy_url ) {
				const link = document.createElement( 'a' );
				link.href = gate.policy_url;
				link.target = '_blank';
				link.rel = 'noopener';
				link.textContent = this.t( 'gate_policy' );
				wrap.appendChild( document.createTextNode( ' ' ) );
				wrap.appendChild( link );
			}
			form.appendChild( wrap );
		}

		form.appendChild( error );

		const submit = document.createElement( 'button' );
		submit.type = 'submit';
		submit.className = 'agy-gate-submit';
		submit.textContent = this.t( 'gate_start' );
		form.appendChild( submit );

		form.addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			error.hidden = true;
			const name = inputs.name ? inputs.name.value.trim() : '';
			const email = inputs.email ? inputs.email.value.trim() : '';
			const accepted = checkbox ? checkbox.checked : true;
			const emailOk = ! inputs.email || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( email );
			if ( ( inputs.name && '' === name ) || ! emailOk || ! accepted ) {
				error.textContent = this.t( 'gate_error' );
				error.hidden = false;
				( inputs.name && '' === name ? inputs.name : ! emailOk && inputs.email ? inputs.email : checkbox )?.focus();
				return;
			}
			submit.disabled = true;
			try {
				await this.api.consent( name, email, accepted );
				this.refreshGate();
				this.textarea.focus();
			} catch ( err ) {
				error.textContent = err instanceof Error && err.message ? err.message : this.t( 'error' );
				error.hidden = false;
			} finally {
				submit.disabled = false;
			}
		} );

		return form;
	}

	/**
	 * Show the gate (and hide composer/chips) until the session is gated.
	 */
	private refreshGate(): void {
		const needsGate = !! ( this.config.gate && this.config.gate.enabled ) && ! this.api.isGated();
		if ( this.gateEl ) {
			this.gateEl.hidden = ! needsGate;
		}
		this.composerEl.hidden = needsGate;
		if ( needsGate ) {
			this.chipsEl.hidden = true;
		}
	}

	private buildComposer(): HTMLFormElement {
		const form = document.createElement( 'form' );
		form.className = 'agy-composer';

		const textarea = document.createElement( 'textarea' );
		textarea.className = 'agy-input';
		textarea.rows = 1;
		textarea.maxLength = MAX_LEN;
		textarea.placeholder = this.t( 'placeholder' );
		textarea.setAttribute( 'aria-label', this.t( 'input_label' ) );
		textarea.addEventListener( 'keydown', ( e ) => {
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				void this.handleSend( textarea.value );
			}
		} );
		textarea.addEventListener( 'input', () => {
			textarea.style.height = 'auto';
			textarea.style.height = Math.min( textarea.scrollHeight, 140 ) + 'px';
			const len = textarea.value.length;
			this.counter.hidden = len <= COUNTER_AT;
			this.counter.textContent = len + '/' + MAX_LEN;
		} );
		this.textarea = textarea;
		form.appendChild( textarea );

		const counter = document.createElement( 'span' );
		counter.className = 'agy-counter';
		counter.hidden = true;
		this.counter = counter;
		form.appendChild( counter );

		const send = document.createElement( 'button' );
		send.type = 'submit';
		send.className = 'agy-send';
		send.setAttribute( 'aria-label', this.t( 'send' ) );
		send.appendChild( svgIcon( ICON_SEND, 20 ) );
		this.sendBtn = send;
		form.appendChild( send );

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			void this.handleSend( textarea.value );
		} );

		return form;
	}

	private buildFooter(): HTMLElement {
		const footer = document.createElement( 'footer' );
		footer.className = 'agy-footer';

		// Art. 50 disclosure line (empty string = disclosure switched off in
		// classic-only mode by the owner; still never hidden with AI on).
		const noteText = undefined === this.config.footer_note ? this.t( 'footer_note' ) : this.config.footer_note;
		if ( noteText ) {
			const note = document.createElement( 'span' );
			note.className = 'agy-footer-note';
			note.textContent = noteText;
			footer.appendChild( note );
		}

		// Legal links: privacy policy + published transparency page.
		const links: Array< [ string, string ] > = [];
		if ( this.config.privacy_url ) {
			links.push( [ this.t( 'privacy' ), this.config.privacy_url ] );
		}
		if ( this.config.transparency_url ) {
			links.push( [ this.t( 'transparency' ), this.config.transparency_url ] );
		}
		for ( const [ label, url ] of links ) {
			const a = document.createElement( 'a' );
			a.className = 'agy-footer-link';
			a.href = url;
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = label;
			footer.appendChild( a );
		}

		// Powered-by link: absent when removed server-side (agyl_powered_by).
		const powered = this.config.powered_by;
		if ( powered && powered.url ) {
			const a = document.createElement( 'a' );
			a.className = 'agy-powered';
			a.href = powered.url;
			a.target = '_blank';
			a.rel = 'noopener nofollow';
			a.textContent = powered.label || 'Powered by Agentyllo';
			footer.appendChild( a );
		}

		return footer;
	}

	/* ------------------------------------------------------------------ */
	/* Open/close + focus management                                      */
	/* ------------------------------------------------------------------ */

	private toggle(): void {
		if ( this.panel.hidden ) {
			this.open();
		} else {
			this.close();
		}
	}

	private open(): void {
		if ( this.teaser ) {
			this.teaser.remove();
			this.teaser = null;
		}
		this.panel.hidden = false;
		this.setAttribute( 'data-open', '' );
		this.launcher.setAttribute( 'aria-expanded', 'true' );
		this.log.scrollTop = this.log.scrollHeight;
		const focusables = this.focusables();
		if ( focusables.length > 0 ) {
			focusables[ 0 ].focus();
		}
	}

	private close(): void {
		this.panel.hidden = true;
		this.removeAttribute( 'data-open' );
		this.launcher.setAttribute( 'aria-expanded', 'false' );
		this.launcher.focus();
	}

	private focusables(): HTMLElement[] {
		const selector = 'button, a[href], textarea, input, select, [tabindex]';
		return Array.from( this.panel.querySelectorAll< HTMLElement >( selector ) ).filter(
			( el ) =>
				! el.hasAttribute( 'disabled' ) &&
				'-1' !== el.getAttribute( 'tabindex' ) &&
				null !== el.offsetParent
		);
	}

	private onPanelKeydown( e: KeyboardEvent ): void {
		if ( 'Escape' === e.key ) {
			e.preventDefault();
			this.close();
			return;
		}
		if ( 'Tab' !== e.key ) {
			return;
		}
		const focusables = this.focusables();
		if ( 0 === focusables.length ) {
			return;
		}
		const first = focusables[ 0 ];
		const last = focusables[ focusables.length - 1 ];
		const active = this.root.activeElement;
		if ( e.shiftKey && active === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && active === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Messages                                                           */
	/* ------------------------------------------------------------------ */

	private restoreMessages(): void {
		for ( const message of this.state.messages ) {
			this.appendMessageNode( message );
		}
		if ( 0 === this.state.messages.length && this.config.welcome_message ) {
			// Local-only welcome bubble (never cached, never sent).
			this.appendMessageNode( {
				id: 'welcome',
				role: 'assistant',
				blocks: [ { type: 'text', md: this.config.welcome_message } ],
			} );
		}
	}

	private appendMessageNode( message: AssistantMessage ): void {
		const row = document.createElement( 'div' );
		row.className = 'agy-row agy-row-' + ( 'user' === message.role ? 'user' : 'bot' );

		const bubble = document.createElement( 'div' );
		bubble.className = 'agy-bubble agy-bubble-' + ( 'user' === message.role ? 'user' : 'bot' );

		if ( 'user' === message.role ) {
			// Visitor text is displayed literally — no markdown parsing.
			bubble.textContent = message.blocks
				.map( ( b ) => ( 'text' === b.type ? b.md : '' ) )
				.join( '\n' );
		} else {
			bubble.appendChild( renderBlocks( message.blocks, this.renderOpts ) );
			if ( message.meta && message.meta.disclaimer ) {
				const disclaimer = document.createElement( 'div' );
				disclaimer.className = 'agy-disclaimer';
				disclaimer.textContent = message.meta.disclaimer;
				bubble.appendChild( disclaimer );
			}
		}

		row.appendChild( bubble );
		this.log.appendChild( row );
		this.log.scrollTop = this.log.scrollHeight;
	}

	/**
	 * Transient assistant-side notice (errors, rate limits) — not persisted.
	 */
	private appendLocalNotice( text: string ): HTMLDivElement {
		const row = document.createElement( 'div' );
		row.className = 'agy-row agy-row-bot';
		const bubble = document.createElement( 'div' );
		bubble.className = 'agy-bubble agy-bubble-bot';
		const notice = document.createElement( 'div' );
		notice.className = 'agy-notice agy-notice-warn';
		notice.textContent = text;
		bubble.appendChild( notice );
		row.appendChild( bubble );
		this.log.appendChild( row );
		this.log.scrollTop = this.log.scrollHeight;
		return notice;
	}

	/* ------------------------------------------------------------------ */
	/* Sending + thinking UX                                              */
	/* ------------------------------------------------------------------ */

	private async handleSend( raw: string ): Promise< void > {
		const text = raw.trim();
		if ( '' === text || this.busy || 0 !== this.cooldownTimer ) {
			return;
		}

		this.busy = true;
		this.sendBtn.disabled = true;
		this.chipsEl.hidden = true;
		this.textarea.value = '';
		this.textarea.style.height = 'auto';
		this.counter.hidden = true;

		const userMessage: AssistantMessage = {
			id: 'u' + Date.now(),
			role: 'user',
			blocks: [ { type: 'text', md: text } ],
		};
		this.state.addMessage( userMessage );
		this.appendMessageNode( userMessage );

		this.announce( this.t( 'replying' ) );
		const started = Date.now();
		this.setStatus( this.reducedMotion ? this.t( 'working' ) : this.t( 'queued' ) );

		// Streaming transport state: live status pacing + preview bubble.
		let streamed = false;
		let preview: HTMLDivElement | null = null;
		let previewText = '';
		this.liveQueue = [];
		const hooks = {
			onStatus: ( state: string ) => {
				streamed = true;
				if ( 'queued' === state && Date.now() - started < MIN_STATE_MS ) {
					return;
				}
				this.pushLiveStatus( state );
			},
			onDelta: ( delta: string ) => {
				streamed = true;
				this.liveQueue = [];
				this.setStatus( '' );
				if ( ! preview ) {
					preview = this.appendPreviewNode();
				}
				previewText += delta;
				preview.textContent = previewText;
				this.log.scrollTop = this.log.scrollHeight;
			},
			onReset: () => {
				previewText = '';
				if ( preview ) {
					preview.textContent = '';
					this.setStatus( this.t( 'formatting' ) );
				}
			},
		};

		try {
			const message = await this.api.sendMessage( text, hooks, this.config.transport || 'stream' );
			if ( streamed ) {
				await this.drainLiveStatus();
			} else if ( ! this.reducedMotion ) {
				await this.replayEvents( ( message.meta && message.meta.events ) || [], started );
			}
			this.setStatus( '' );
			this.state.addMessage( message );
			if ( preview ) {
				// The final message is authoritative: replace the streamed preview.
				this.replacePreviewNode( preview, message );
			} else {
				this.appendMessageNode( message );
			}
			this.announce( blocksToText( message.blocks ) );
		} catch ( e ) {
			this.setStatus( '' );
			this.liveQueue = [];
			if ( preview ) {
				const row = preview.closest( '.agy-row' );
				if ( row && row.parentNode ) {
					row.parentNode.removeChild( row );
				}
			}
			if ( e instanceof ApiError && 429 === e.status ) {
				this.startCooldown( e.retryAfter && e.retryAfter > 0 ? e.retryAfter : 30 );
			} else if ( e instanceof ApiError && 'agyl_gate_required' === e.code ) {
				// Server insists on the gate (setting turned on mid-session):
				// drop the local flag and show the form.
				this.api.ungate();
				this.refreshGate();
			} else {
				this.appendLocalNotice( this.t( 'error' ) );
				this.announce( this.t( 'error' ) );
			}
		} finally {
			this.busy = false;
			if ( 0 === this.cooldownTimer ) {
				this.sendBtn.disabled = false;
				this.textarea.focus();
			}
		}
	}

	/**
	 * Streaming preview bubble: plain text appended as deltas arrive, then
	 * replaced by the rendered blocks of the authoritative message.
	 */
	private appendPreviewNode(): HTMLDivElement {
		const row = document.createElement( 'div' );
		row.className = 'agy-row agy-row-bot';
		const bubble = document.createElement( 'div' );
		bubble.className = 'agy-bubble agy-bubble-bot agy-bubble-preview';
		const text = document.createElement( 'div' );
		text.className = 'agy-preview-text';
		bubble.appendChild( text );
		row.appendChild( bubble );
		this.log.appendChild( row );
		this.log.scrollTop = this.log.scrollHeight;
		return text;
	}

	private replacePreviewNode( preview: HTMLDivElement, message: AssistantMessage ): void {
		const bubble = preview.parentElement;
		if ( ! bubble ) {
			this.appendMessageNode( message );
			return;
		}
		bubble.classList.remove( 'agy-bubble-preview' );
		while ( bubble.firstChild ) {
			bubble.removeChild( bubble.firstChild );
		}
		bubble.appendChild( renderBlocks( message.blocks, this.renderOpts ) );
		if ( message.meta && message.meta.disclaimer ) {
			const disclaimer = document.createElement( 'div' );
			disclaimer.className = 'agy-disclaimer';
			disclaimer.textContent = message.meta.disclaimer;
			bubble.appendChild( disclaimer );
		}
		this.log.scrollTop = this.log.scrollHeight;
	}

	/**
	 * Live-transport status pacing: states arrive at real speed; each is
	 * shown for at least MIN_STATE_MS so fast stages do not flicker.
	 */
	private pushLiveStatus( state: string ): void {
		if ( this.reducedMotion ) {
			this.setStatus( this.t( 'working' ) );
			return;
		}
		this.liveQueue.push( state );
		this.pumpLiveStatus();
	}

	private pumpLiveStatus(): void {
		if ( this.liveBusy ) {
			return;
		}
		const next = this.liveQueue.shift();
		if ( undefined === next ) {
			return;
		}
		this.liveBusy = true;
		this.setStatus( this.t( next ) );
		window.setTimeout( () => {
			this.liveBusy = false;
			this.pumpLiveStatus();
		}, MIN_STATE_MS );
	}

	private async drainLiveStatus(): Promise< void > {
		while ( this.liveBusy || this.liveQueue.length > 0 ) {
			await delay( 50 );
		}
	}

	/**
	 * Buffered-transport pacing: walk meta.events (the canonical states the
	 * pipeline actually emitted) with a minimum dwell of 400ms per state,
	 * capping long gaps, then skip to done. The initial 'queued' state is
	 * skipped when the network wait already displayed it long enough.
	 */
	private async replayEvents( events: StatusEvent[], started: number ): Promise< void > {
		const visible = events.filter(
			( e ) => 'done' !== e.state && 'refused' !== e.state && 'error' !== e.state
		);
		let index = 0;
		if (
			visible.length > 0 &&
			'queued' === visible[ 0 ].state &&
			Date.now() - started >= MIN_STATE_MS
		) {
			index = 1;
		}
		for ( ; index < visible.length; index++ ) {
			this.setStatus( this.t( visible[ index ].state ) );
			const gap =
				index + 1 < visible.length ? visible[ index + 1 ].ts - visible[ index ].ts : 0;
			await delay( Math.min( MAX_STATE_MS, Math.max( MIN_STATE_MS, gap ) ) );
		}
	}

	private setStatus( label: string ): void {
		this.statusEl.textContent = label;
	}

	/**
	 * 429 UX: a polite notice counting down from Retry-After, composer
	 * disabled until it reaches zero.
	 */
	private startCooldown( seconds: number ): void {
		this.textarea.disabled = true;
		this.sendBtn.disabled = true;
		let remaining = Math.ceil( seconds );
		const notice = this.appendLocalNotice(
			this.t( 'rate_limited' ).replace( '%d', String( remaining ) )
		);
		this.announce( this.t( 'rate_limited' ).replace( '%d', String( remaining ) ) );

		this.cooldownTimer = window.setInterval( () => {
			remaining -= 1;
			if ( remaining > 0 ) {
				notice.textContent = this.t( 'rate_limited' ).replace( '%d', String( remaining ) );
				return;
			}
			window.clearInterval( this.cooldownTimer );
			this.cooldownTimer = 0;
			notice.textContent = this.t( 'rate_limited_over' );
			this.textarea.disabled = false;
			this.sendBtn.disabled = false;
			this.textarea.focus();
		}, 1000 );
	}

	/**
	 * Screen-reader announcer: cleared then repopulated so repeated
	 * identical texts still announce. Used once per reply ('Assistant is
	 * replying'), then with the full response text at done — never
	 * per-token, never per-status.
	 */
	private announce( text: string ): void {
		this.announcer.textContent = '';
		window.setTimeout( () => {
			this.announcer.textContent = text;
		}, 50 );
	}
}
