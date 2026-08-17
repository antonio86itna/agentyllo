/**
 * Agentyllo admin SPA entry.
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

declare global {
	interface Window {
		agyAdmin: { restRoot: string; nonce: string; version: string };
	}
}

apiFetch.use( apiFetch.createRootURLMiddleware( window.agyAdmin.restRoot ) );
apiFetch.use( apiFetch.createNonceMiddleware( window.agyAdmin.nonce ) );

const mount = document.getElementById( 'agentyllo-admin' );
if ( mount ) {
	createRoot( mount ).render( <App page={ mount.dataset.page || 'dashboard' } /> );
}
