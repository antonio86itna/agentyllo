/**
 * Widget entry point (compiled standalone as an IIFE — no @wordpress
 * imports, zero runtime dependencies).
 *
 * Defines <agentyllo-chat> (guarded against double registration when two
 * copies of the bundle end up on a page) and auto-boots on DOMContentLoaded:
 * if the server did not print the tag, one is created from the global
 * window.agyWidget = { rest: '<rest_url( "agentyllo/v1" )>' } handle.
 */
import { AgentylloChat } from './element';

const TAG = 'agentyllo-chat';

if ( 'undefined' !== typeof window && 'customElements' in window ) {
	try {
		if ( ! window.customElements.get( TAG ) ) {
			window.customElements.define( TAG, AgentylloChat );
		}
	} catch ( e ) {
		// A concurrent definition raced us — the first one wins, fine.
	}
}

function autoBoot(): void {
	if ( document.querySelector( TAG ) ) {
		return; // Server-rendered tag present; upgrade handles the rest.
	}
	const handle = ( window as any ).agyWidget;
	if ( ! handle || 'string' !== typeof handle.rest || '' === handle.rest ) {
		return;
	}
	const el = document.createElement( TAG );
	el.setAttribute( 'data-rest', handle.rest );
	document.body.appendChild( el );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', autoBoot );
} else {
	autoBoot();
}
