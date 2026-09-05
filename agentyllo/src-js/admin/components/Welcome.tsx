/**
 * First-run welcome / setup guide.
 *
 * Shown once after activation (the plugin redirects to ?welcome=1) or until the
 * owner dismisses it. It reassures that the assistant is ALREADY live and
 * offers the three highest-value next steps: turn on free AI, view the widget,
 * and open settings. Dismissal is remembered per-user via the REST-less
 * localStorage flag plus a server call so it never nags.
 */
import { api as apiFetch } from '../api';
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const DISMISS_KEY = 'agyl_welcome_dismissed';

export default function Welcome( { onGo }: { onGo: ( page: string ) => void } ) {
	const [ open, setOpen ] = useState( () => {
		try {
			return '1' !== window.localStorage.getItem( DISMISS_KEY );
		} catch {
			return true;
		}
	} );
	const [ enabling, setEnabling ] = useState( false );
	const [ msg, setMsg ] = useState< string >( '' );

	if ( ! open ) {
		return null;
	}

	const dismiss = () => {
		try {
			window.localStorage.setItem( DISMISS_KEY, '1' );
		} catch {
			/* ignore */
		}
		setOpen( false );
	};

	const turnOnFreeAi = async () => {
		setEnabling( true );
		setMsg( '' );
		try {
			const res: any = await apiFetch( { path: '/models/cloud-enable', method: 'POST' } );
			setMsg( res.message || '' );
		} catch ( e: any ) {
			setMsg( e?.message || __( 'Could not connect right now.', 'agentyllo' ) );
		} finally {
			setEnabling( false );
		}
	};

	const siteUrl = ( window as any ).agylAdmin?.siteUrl || window.location.origin || '/';

	return (
		<div className="agy-welcome">
			<button type="button" className="agy-welcome__close" onClick={ dismiss } aria-label={ __( 'Dismiss', 'agentyllo' ) }>✕</button>
			<div className="agy-welcome__head">
				<h2>{ __( 'Welcome to Agentyllo 🎉', 'agentyllo' ) }</h2>
				<p>{ __( 'Your assistant is already live on your site, answering visitors from your own content — no setup needed. Here are three quick ways to get the most out of it:', 'agentyllo' ) }</p>
			</div>

			<div className="agy-welcome__steps">
				<div className="agy-welcome__step">
					<span className="agy-welcome__num">1</span>
					<div>
						<h3>{ __( 'Turn on free AI (optional)', 'agentyllo' ) }</h3>
						<p>{ __( 'Add natural, AI-written answers on top of the classic engine — free, no API key, with a monthly quota.', 'agentyllo' ) }</p>
						<div className="agy-welcome__actions">
							<Button variant="primary" isBusy={ enabling } disabled={ enabling } onClick={ turnOnFreeAi }>
								{ __( 'Turn on free AI', 'agentyllo' ) }
							</Button>
							<Button variant="tertiary" onClick={ () => onGo( 'models' ) }>{ __( 'Or use your own key', 'agentyllo' ) }</Button>
						</div>
						{ msg && <p className="agy-welcome__msg">{ msg }</p> }
					</div>
				</div>

				<div className="agy-welcome__step">
					<span className="agy-welcome__num">2</span>
					<div>
						<h3>{ __( 'See it on your site', 'agentyllo' ) }</h3>
						<p>{ __( 'The chat launcher is already there. Open your site and say hello.', 'agentyllo' ) }</p>
						<a className="components-button is-tertiary" href={ siteUrl } target="_blank" rel="noreferrer">{ __( 'View my site', 'agentyllo' ) }</a>
					</div>
				</div>

				<div className="agy-welcome__step">
					<span className="agy-welcome__num">3</span>
					<div>
						<h3>{ __( 'Make it yours', 'agentyllo' ) }</h3>
						<p>{ __( 'Name your assistant, pick its colour and tone, add opening hours or FAQs.', 'agentyllo' ) }</p>
						<div className="agy-welcome__actions">
							<Button variant="tertiary" onClick={ () => onGo( 'settings' ) }>{ __( 'Open settings', 'agentyllo' ) }</Button>
							<Button variant="tertiary" onClick={ () => onGo( 'kb' ) }>{ __( 'Knowledge base', 'agentyllo' ) }</Button>
						</div>
					</div>
				</div>
			</div>

			<div className="agy-welcome__foot">
				<Button variant="secondary" onClick={ dismiss }>{ __( 'Got it', 'agentyllo' ) }</Button>
			</div>
		</div>
	);
}
