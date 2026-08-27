/**
 * Agentyllo admin SPA entry.
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

declare global {
	interface Window {
		agylAdmin: { restRoot: string; nonce: string; version: string };
	}
}

apiFetch.use( apiFetch.createRootURLMiddleware( window.agylAdmin.restRoot ) );
apiFetch.use( apiFetch.createNonceMiddleware( window.agylAdmin.nonce ) );

const mount = document.getElementById( 'agentyllo-admin' );
if ( mount ) {
	createRoot( mount ).render( <App page={ mount.dataset.page || 'dashboard' } /> );
}
