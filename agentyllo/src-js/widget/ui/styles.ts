/**
 * Widget stylesheet. Injected as a constructable CSSStyleSheet
 * (adoptedStyleSheets) with a <style> element fallback for older engines.
 *
 * Theming: every color is a --agy-* custom property on :host; the element
 * sets --agy-primary and --agy-z inline from /config. Dark tokens apply
 * under [data-theme='dark'] and, for [data-theme='auto'], under
 * prefers-color-scheme: dark. All animations are <= 300ms and fully
 * disabled under prefers-reduced-motion or [data-animations='0'].
 */
export const WIDGET_CSS = `
:host {
	--agy-primary: #4f46e5;
	--agy-primary-fg: #ffffff;
	--agy-accent: #818cf8;
	--agy-bg: #ffffff;
	--agy-fg: #1e1e1e;
	--agy-muted: #646970;
	--agy-border: #dcdcde;
	--agy-surface: #f0f0f1;
	--agy-bubble-user: var(--agy-primary);
	--agy-bubble-user-fg: var(--agy-primary-fg);
	--agy-bubble-bot: var(--agy-surface);
	--agy-bubble-bot-fg: var(--agy-fg);
	--agy-info-bg: #eef2ff;
	--agy-info-fg: #3730a3;
	--agy-warn-bg: #fcf0e4;
	--agy-warn-fg: #8a4d00;
	--agy-ring: #4f46e5;
	--agy-radius: 16px;
	--agy-shadow: 0 8px 30px rgba(0, 0, 0, 0.18);
	--agy-z: 99990;
	--agy-font: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans,
		Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;
}

:host([data-theme='dark']) {
	--agy-accent: #818cf8;
	--agy-bg: #1f2023;
	--agy-fg: #e4e4e7;
	--agy-muted: #a7aaad;
	--agy-border: #3c434a;
	--agy-surface: #2c2d33;
	--agy-info-bg: #26294a;
	--agy-info-fg: #c7d2fe;
	--agy-warn-bg: #3d2e17;
	--agy-warn-fg: #f0c37e;
	--agy-ring: var(--agy-accent);
	--agy-shadow: 0 8px 30px rgba(0, 0, 0, 0.55);
}

@media (prefers-color-scheme: dark) {
	:host([data-theme='auto']) {
		--agy-accent: #818cf8;
		--agy-bg: #1f2023;
		--agy-fg: #e4e4e7;
		--agy-muted: #a7aaad;
		--agy-border: #3c434a;
		--agy-surface: #2c2d33;
		--agy-info-bg: #26294a;
		--agy-info-fg: #c7d2fe;
		--agy-warn-bg: #3d2e17;
		--agy-warn-fg: #f0c37e;
		--agy-ring: var(--agy-accent);
		--agy-shadow: 0 8px 30px rgba(0, 0, 0, 0.55);
	}
}

*,
*::before,
*::after {
	box-sizing: border-box;
}

/* Screen-reader-only utility (announcer). */
.agy-vh {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0 0 0 0);
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

/* --------------------------------------------------------------------- */
/* Launcher                                                              */
/* --------------------------------------------------------------------- */

.agy-launcher {
	position: fixed;
	right: 20px;
	bottom: 20px;
	z-index: var(--agy-z);
	display: flex;
	align-items: center;
	justify-content: center;
	width: 56px;
	height: 56px;
	padding: 0;
	border: 0;
	border-radius: 50%;
	background: var(--agy-primary);
	color: var(--agy-primary-fg);
	cursor: pointer;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.24);
	font-family: var(--agy-font);
	transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.agy-launcher:hover {
	transform: scale(1.06);
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
}

:host([data-position='bottom_left']) .agy-launcher {
	right: auto;
	left: 20px;
}

.agy-teaser {
	position: fixed;
	right: 88px;
	bottom: 32px;
	z-index: var(--agy-z);
	max-width: 220px;
	padding: 10px 14px;
	border-radius: 12px;
	background: var(--agy-bg);
	color: var(--agy-fg);
	border: 1px solid var(--agy-border);
	box-shadow: var(--agy-shadow);
	font: 13px/1.4 var(--agy-font);
	animation: agy-fade 0.25s ease;
}

:host([data-position='bottom_left']) .agy-teaser {
	right: auto;
	left: 88px;
}

/* --------------------------------------------------------------------- */
/* Panel                                                                 */
/* --------------------------------------------------------------------- */

.agy-panel {
	position: fixed;
	right: 20px;
	bottom: 88px;
	z-index: var(--agy-z);
	display: flex;
	flex-direction: column;
	width: 400px;
	height: min(704px, calc(100dvh - 96px));
	overflow: hidden;
	border-radius: var(--agy-radius);
	background: var(--agy-bg);
	color: var(--agy-fg);
	box-shadow: var(--agy-shadow);
	font: 14px/1.5 var(--agy-font);
	animation: agy-pop 0.2s ease;
}

.agy-panel[hidden] {
	display: none;
}

:host([data-position='bottom_left']) .agy-panel {
	right: auto;
	left: 20px;
}

@media (max-width: 640px) {
	.agy-panel {
		inset: 0;
		width: 100%;
		height: 100dvh;
		border-radius: 0;
	}

	:host([data-position='bottom_left']) .agy-panel {
		left: 0;
	}

	.agy-header {
		padding-top: calc(12px + env(safe-area-inset-top));
	}

	.agy-footer {
		padding-bottom: calc(8px + env(safe-area-inset-bottom));
	}

	:host([data-open]) .agy-launcher,
	:host([data-open]) .agy-teaser {
		display: none;
	}
}

/* --------------------------------------------------------------------- */
/* Header                                                                */
/* --------------------------------------------------------------------- */

.agy-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 12px 16px;
	background: var(--agy-primary);
	color: var(--agy-primary-fg);
}

.agy-avatar {
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	border-radius: 50%;
	background: rgba(255, 255, 255, 0.12);
	color: var(--agy-primary-fg);
}

.agy-header-id {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1 1 auto;
}

.agy-title {
	font-weight: 600;
	font-size: 15px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.agy-badge {
	align-self: flex-start;
	padding: 1px 8px;
	border-radius: 99px;
	background: rgba(255, 255, 255, 0.22);
	font-size: 11px;
	letter-spacing: 0.02em;
}

.agy-close {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	padding: 0;
	border: 0;
	border-radius: 8px;
	background: transparent;
	color: inherit;
	cursor: pointer;
}

.agy-close:hover {
	background: rgba(255, 255, 255, 0.18);
}

/* --------------------------------------------------------------------- */
/* Message log                                                           */
/* --------------------------------------------------------------------- */

.agy-log {
	flex: 1 1 auto;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 16px;
	overscroll-behavior: contain;
}

.agy-row {
	display: flex;
	animation: agy-fade 0.15s ease;
}

.agy-row-user {
	justify-content: flex-end;
}

.agy-bubble {
	max-width: 85%;
	padding: 9px 13px;
	border-radius: 14px;
	overflow-wrap: break-word;
}

.agy-bubble-user {
	background: var(--agy-bubble-user);
	color: var(--agy-bubble-user-fg);
	border-bottom-right-radius: 4px;
	white-space: pre-wrap;
}

.agy-bubble-bot {
	background: var(--agy-bubble-bot);
	color: var(--agy-bubble-bot-fg);
	border-bottom-left-radius: 4px;
}

.agy-bubble p {
	margin: 0 0 8px;
}

.agy-bubble p:last-child {
	margin-bottom: 0;
}

.agy-bubble ul {
	margin: 0 0 8px;
	padding-left: 20px;
}

.agy-bubble ul:last-child {
	margin-bottom: 0;
}

.agy-bubble code {
	padding: 1px 5px;
	border-radius: 4px;
	background: rgba(0, 0, 0, 0.08);
	font-size: 0.92em;
	font-family: Menlo, Consolas, monaco, monospace;
}

:host([data-theme='dark']) .agy-bubble code {
	background: rgba(255, 255, 255, 0.12);
}

.agy-link {
	color: inherit;
	text-decoration: underline;
	text-underline-offset: 2px;
}

.agy-bubble-bot .agy-link {
	color: var(--agy-primary);
}

:host([data-theme='dark']) .agy-bubble-bot .agy-link {
	color: var(--agy-ring);
}

.agy-ext {
	margin-left: 2px;
	font-size: 0.85em;
}

.agy-preview-text { white-space: pre-wrap; word-break: break-word; }
.agy-bubble-preview .agy-preview-text::after { content: '▍'; opacity: .6; animation: agy-caret 1s steps(2) infinite; }
@keyframes agy-caret { 50% { opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .agy-bubble-preview .agy-preview-text::after { animation: none; } }
.agy-disclaimer {
	margin-top: 4px;
	font-size: 11px;
	color: var(--agy-muted);
}

/* Cards (links block) */

.agy-cards {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.agy-card {
	display: flex;
	gap: 10px;
	padding: 8px;
	border: 1px solid var(--agy-border);
	border-radius: 10px;
	background: var(--agy-bg);
	color: var(--agy-fg);
	text-decoration: none;
	transition: border-color 0.15s ease;
}

.agy-card:hover {
	border-color: var(--agy-primary);
}

.agy-media {
	flex: 0 0 56px;
	width: 56px;
	height: 56px;
	overflow: hidden;
	border-radius: 8px;
	background: var(--agy-surface);
}

.agy-media img {
	display: block;
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.agy-card-body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.agy-card-title {
	font-weight: 600;
	font-size: 13px;
}

.agy-card-excerpt {
	font-size: 12px;
	color: var(--agy-muted);
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

/* Products */

.agy-products {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.agy-product {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px;
	border: 1px solid var(--agy-border);
	border-radius: 10px;
	background: var(--agy-bg);
}

.agy-product-link {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--agy-fg);
	text-decoration: none;
}

.agy-product-title {
	font-weight: 600;
	font-size: 13px;
}

.agy-product-price {
	font-size: 13px;
}

.agy-product-price del {
	color: var(--agy-muted);
}

.agy-stock {
	align-self: flex-start;
	padding: 1px 8px;
	border-radius: 99px;
	font-size: 11px;
	background: var(--agy-info-bg);
	color: var(--agy-info-fg);
}

.agy-stock-out {
	background: var(--agy-warn-bg);
	color: var(--agy-warn-fg);
}

.agy-cart-link {
	align-self: flex-start;
	font-size: 12px;
	font-weight: 600;
	color: var(--agy-primary);
	text-decoration: underline;
	text-underline-offset: 2px;
}

/* CTA + notices */

.agy-cta {
	display: inline-block;
	margin-top: 8px;
	padding: 8px 16px;
	border-radius: 8px;
	background: var(--agy-primary);
	color: var(--agy-primary-fg);
	font-weight: 600;
	text-decoration: none;
}

.agy-notice {
	margin-top: 8px;
	padding: 8px 12px;
	border-radius: 8px;
	font-size: 13px;
}

.agy-notice:first-child {
	margin-top: 0;
}

.agy-notice-info {
	background: var(--agy-info-bg);
	color: var(--agy-info-fg);
}

.agy-notice-warn {
	background: var(--agy-warn-bg);
	color: var(--agy-warn-fg);
}

/* --------------------------------------------------------------------- */
/* Status line                                                           */
/* --------------------------------------------------------------------- */

.agy-status {
	min-height: 0;
	padding: 0 16px;
	font-size: 12px;
	color: var(--agy-muted);
}

.agy-status:not(:empty) {
	padding-bottom: 6px;
	animation: agy-fade 0.15s ease;
}

/* --------------------------------------------------------------------- */
/* Suggestion chips                                                      */
/* --------------------------------------------------------------------- */

.agy-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 0 16px 8px;
}

.agy-chips[hidden] {
	display: none;
}

.agy-chip {
	padding: 6px 12px;
	border: 1px solid var(--agy-border);
	border-radius: 99px;
	background: var(--agy-bg);
	color: var(--agy-fg);
	font: 12px/1.3 var(--agy-font);
	cursor: pointer;
	transition: border-color 0.15s ease, background 0.15s ease;
}

.agy-chip:hover {
	border-color: var(--agy-primary);
	background: var(--agy-surface);
}

/* --------------------------------------------------------------------- */
/* Composer                                                              */
/* --------------------------------------------------------------------- */

.agy-composer {
	position: relative;
	display: flex;
	align-items: flex-end;
	gap: 8px;
	padding: 8px 12px;
	border-top: 1px solid var(--agy-border);
}

.agy-input {
	flex: 1 1 auto;
	max-height: 140px;
	padding: 9px 12px;
	border: 1px solid var(--agy-border);
	border-radius: 12px;
	background: var(--agy-bg);
	color: var(--agy-fg);
	font: 14px/1.4 var(--agy-font);
	resize: none;
	overflow-y: auto;
}

.agy-input:disabled {
	opacity: 0.6;
}

.agy-counter {
	position: absolute;
	right: 60px;
	top: -18px;
	font-size: 11px;
	color: var(--agy-muted);
	background: var(--agy-bg);
	padding: 0 4px;
	border-radius: 4px;
}

.agy-send {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	padding: 0;
	border: 0;
	border-radius: 10px;
	background: var(--agy-primary);
	color: var(--agy-primary-fg);
	cursor: pointer;
}

.agy-send:disabled {
	opacity: 0.5;
	cursor: default;
}

/* --------------------------------------------------------------------- */
/* Footer (Art. 50 disclosure surfaces)                                  */
/* --------------------------------------------------------------------- */

.agy-footer {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px 16px 8px;
	text-align: center;
	font-size: 11px;
	color: var(--agy-muted);
}

.agy-footer-link {
	color: var(--agy-muted);
	text-decoration: underline;
}

/* Pre-chat gate (GDPR registration + consent) */
.agy-gate {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 12px 16px;
	border-top: 1px solid var(--agy-border);
	font-size: 14px;
	color: var(--agy-fg);
}

.agy-gate[hidden],
.agy-composer[hidden] {
	display: none;
}

.agy-gate-intro {
	margin: 0;
	color: var(--agy-muted);
	font-size: 13px;
}

.agy-gate-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.agy-gate-field label,
.agy-gate-consent label {
	font-size: 13px;
}

.agy-gate-field input {
	font: inherit;
	padding: 8px 10px;
	border: 1px solid var(--agy-border);
	border-radius: 8px;
	background: var(--agy-bg);
	color: var(--agy-fg);
}

.agy-gate-field input:focus-visible,
.agy-gate-consent input:focus-visible,
.agy-gate-submit:focus-visible {
	outline: 3px solid var(--agy-ring);
	outline-offset: 2px;
}

.agy-gate-consent {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	font-size: 13px;
}

.agy-gate-consent input {
	margin-top: 3px;
	flex: none;
	width: 16px;
	height: 16px;
}

.agy-gate-consent a {
	color: var(--agy-primary);
	text-decoration: underline;
}

.agy-gate-error {
	margin: 0;
	color: #b32d2e;
	font-size: 13px;
}

.agy-gate-submit {
	align-self: flex-start;
	font: inherit;
	font-weight: 600;
	padding: 9px 16px;
	border: 0;
	border-radius: 999px;
	background: var(--agy-primary);
	color: var(--agy-primary-fg);
	cursor: pointer;
}

.agy-gate-submit:disabled {
	opacity: 0.6;
	cursor: default;
}

.agy-powered {
	color: var(--agy-muted);
	text-decoration: underline;
	text-underline-offset: 2px;
}

/* --------------------------------------------------------------------- */
/* Motion + focus                                                        */
/* --------------------------------------------------------------------- */

@keyframes agy-pop {
	from {
		opacity: 0;
		transform: translateY(12px) scale(0.98);
	}
	to {
		opacity: 1;
		transform: none;
	}
}

@keyframes agy-fade {
	from {
		opacity: 0;
	}
	to {
		opacity: 1;
	}
}

:host([data-animations='0']) *,
:host([data-animations='0']) *::before,
:host([data-animations='0']) *::after {
	animation: none !important;
	transition: none !important;
}

@media (prefers-reduced-motion: reduce) {
	*,
	*::before,
	*::after {
		animation: none !important;
		transition: none !important;
	}
}

/* >= 3:1 contrast rings in both themes (deep blue on light, pale blue on dark). */
:focus-visible {
	outline: 3px solid var(--agy-ring);
	outline-offset: 2px;
}

.agy-header :focus-visible {
	outline-color: var(--agy-primary-fg);
}
`;

/**
 * Attach the stylesheet to a shadow root, preferring constructable sheets.
 */
export function adoptStyles( root: ShadowRoot ): void {
	try {
		const sheet = new CSSStyleSheet();
		sheet.replaceSync( WIDGET_CSS );
		root.adoptedStyleSheets = [ ...root.adoptedStyleSheets, sheet ];
		return;
	} catch ( e ) {
		// Constructable sheets unsupported — fall back to a <style> element.
	}
	const style = document.createElement( 'style' );
	style.textContent = WIDGET_CSS;
	root.appendChild( style );
}
