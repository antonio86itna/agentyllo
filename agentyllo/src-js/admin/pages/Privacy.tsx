/**
 * Privacy page: settings (schema-driven) + DSAR tools + AI Act transparency
 * page generator. Erasure requires a typed confirmation.
 */
import { api as apiFetch } from '../api';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Loading from '../components/Loading';
import SchemaForm from '../components/SchemaForm';

type Summary = {
	email: string;
	conversations: number;
	messages: number;
	consents: number;
	first_seen: string | null;
	last_seen: string | null;
};

function PrivacySettings() {
	const [ schema, setSchema ] = useState< any >( null );
	const [ values, setValues ] = useState< Record< string, any > >( {} );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< { status: string; text: string } | null >( null );

	useEffect( () => {
		let active = true;
		apiFetch( { path: '/settings/privacy' } )
			.then( ( res: any ) => {
				if ( active ) {
					setSchema( res.schema );
					setValues( res.values );
				}
			} )
			.catch( () => active && setNotice( { status: 'error', text: __( 'Failed to load privacy settings.', 'agentyllo' ) } ) );
		return () => {
			active = false;
		};
	}, [] );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const res: any = await apiFetch( { path: '/settings/privacy', method: 'PUT', data: { values } } );
			setValues( res.values );
			setDirty( false );
			setNotice( { status: 'success', text: __( 'Privacy settings saved.', 'agentyllo' ) } );
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Saving failed.', 'agentyllo' ) } );
		} finally {
			setSaving( false );
		}
	};

	if ( ! schema ) {
		return <Loading />;
	}

	const retentionOff = 0 === Number( values.retention_days );

	return (
		<Card>
			<CardHeader>
				<h2 className="agy-card-title">{ __( 'Privacy & legal settings', 'agentyllo' ) }</h2>
			</CardHeader>
			<CardBody>
				{ notice && (
					<Notice status={ notice.status as any } isDismissible onRemove={ () => setNotice( null ) }>
						{ notice.text }
					</Notice>
				) }
				{ retentionOff && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'Retention is set to 0: conversations are kept forever. Under GDPR you should define a retention period.', 'agentyllo' ) }
					</Notice>
				) }
				<SchemaForm
					schema={ schema }
					values={ values }
					onChange={ ( key, value ) => {
						setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
						setDirty( true );
					} }
				/>
				<div className="agy-settings-tab__actions">
					<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving || ! dirty }>
						{ saving ? __( 'Saving…', 'agentyllo' ) : __( 'Save changes', 'agentyllo' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

function DsarTools() {
	const [ email, setEmail ] = useState( '' );
	const [ summary, setSummary ] = useState< Summary | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ confirmText, setConfirmText ] = useState( '' );
	const [ notice, setNotice ] = useState< { status: string; text: string } | null >( null );

	const search = useCallback( async () => {
		setBusy( true );
		setNotice( null );
		setSummary( null );
		try {
			const res: any = await apiFetch( { path: `/privacy/search?email=${ encodeURIComponent( email ) }` } );
			setSummary( res );
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Search failed.', 'agentyllo' ) } );
		} finally {
			setBusy( false );
		}
	}, [ email ] );

	const exportData = async () => {
		setBusy( true );
		try {
			const res: any = await apiFetch( { path: '/privacy/export', method: 'POST', data: { email } } );
			const blob = new Blob( [ JSON.stringify( res, null, 2 ) ], { type: 'application/json' } );
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = `agentyllo-export-${ email.replace( /[^a-z0-9]+/gi, '_' ) }.json`;
			a.click();
			URL.revokeObjectURL( url );
			setNotice( { status: 'success', text: __( 'Export downloaded (a copy is kept in the protected uploads folder for 72 hours).', 'agentyllo' ) } );
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Export failed.', 'agentyllo' ) } );
		} finally {
			setBusy( false );
		}
	};

	const erase = async () => {
		setBusy( true );
		try {
			const res: any = await apiFetch( { path: '/privacy/erase', method: 'POST', data: { email, confirm: true } } );
			setNotice( {
				status: 'success',
				text: sprintf(
					/* translators: 1: conversations count, 2: messages count */
					__( 'Erased: %1$d conversations, %2$d messages redacted. Consent records were anonymized.', 'agentyllo' ),
					res.conversations,
					res.messages
				),
			} );
			setConfirmText( '' );
			await search();
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Erasure failed.', 'agentyllo' ) } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Card>
			<CardHeader>
				<h2 className="agy-card-title">{ __( 'Data subject requests (GDPR)', 'agentyllo' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="agy-muted">
					{ __( 'Look up everything the assistant holds for an email address, export it as JSON, or erase it. These tools are also available through WordPress → Tools → Export/Erase Personal Data.', 'agentyllo' ) }
				</p>
				{ notice && (
					<Notice status={ notice.status as any } isDismissible onRemove={ () => setNotice( null ) }>
						{ notice.text }
					</Notice>
				) }
				<div style={ { display: 'flex', gap: 8, alignItems: 'flex-end', maxWidth: 560 } }>
					<div style={ { flex: 1 } }>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="email"
							label={ __( 'Visitor email', 'agentyllo' ) }
							value={ email }
							onChange={ setEmail }
						/>
					</div>
					<Button variant="secondary" onClick={ search } isBusy={ busy } disabled={ busy || ! email }>
						{ __( 'Search', 'agentyllo' ) }
					</Button>
				</div>

				{ summary && (
					<div style={ { marginTop: 16 } }>
						<table className="agy-probe-table" style={ { maxWidth: 560 } }>
							<tbody>
								<tr><th scope="row">{ __( 'Conversations', 'agentyllo' ) }</th><td>{ summary.conversations }</td></tr>
								<tr><th scope="row">{ __( 'Messages', 'agentyllo' ) }</th><td>{ summary.messages }</td></tr>
								<tr><th scope="row">{ __( 'Consent records', 'agentyllo' ) }</th><td>{ summary.consents }</td></tr>
								<tr><th scope="row">{ __( 'First seen', 'agentyllo' ) }</th><td>{ summary.first_seen || '—' }</td></tr>
								<tr><th scope="row">{ __( 'Last seen', 'agentyllo' ) }</th><td>{ summary.last_seen || '—' }</td></tr>
							</tbody>
						</table>
						<div style={ { display: 'flex', gap: 8, marginTop: 12, alignItems: 'flex-end', flexWrap: 'wrap' } }>
							<Button variant="secondary" onClick={ exportData } disabled={ busy }>
								{ __( 'Export JSON', 'agentyllo' ) }
							</Button>
							<div>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Type ERASE to confirm', 'agentyllo' ) }
									value={ confirmText }
									onChange={ setConfirmText }
								/>
							</div>
							<Button variant="primary" isDestructive onClick={ erase } disabled={ busy || 'ERASE' !== confirmText }>
								{ __( 'Erase data', 'agentyllo' ) }
							</Button>
						</div>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

function TransparencyTool() {
	const [ result, setResult ] = useState< any >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const generate = async () => {
		setBusy( true );
		setError( null );
		try {
			setResult( await apiFetch( { path: '/privacy/transparency-page', method: 'POST' } ) );
		} catch ( e: any ) {
			setError( e?.message || __( 'Could not create the page.', 'agentyllo' ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Card>
			<CardHeader>
				<h2 className="agy-card-title">{ __( 'AI Act transparency page', 'agentyllo' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="agy-muted">
					{ __( 'EU AI Act Art. 50 requires telling people they are interacting with an AI system. The widget already shows a badge and footer disclosure; this creates a draft page (shortcode [agentyllo_transparency]) explaining who operates the assistant, how it works, what data it processes and how to reach a human. Publish it and its link appears in the widget footer.', 'agentyllo' ) }
				</p>
				{ error && <Notice status="error" isDismissible onRemove={ () => setError( null ) }>{ error }</Notice> }
				<Button variant="secondary" onClick={ generate } isBusy={ busy } disabled={ busy }>
					{ __( 'Generate transparency page', 'agentyllo' ) }
				</Button>
				{ result && (
					<p style={ { marginTop: 12 } }>
						{ sprintf( /* translators: %s: page status */ __( 'Page ready (status: %s).', 'agentyllo' ), result.status ) }{ ' ' }
						<a href={ result.edit_url }>{ __( 'Edit & publish', 'agentyllo' ) }</a>
						{ ' · ' }
						<a href={ result.view_url } target="_blank" rel="noopener noreferrer">{ __( 'Preview', 'agentyllo' ) }</a>
					</p>
				) }
			</CardBody>
		</Card>
	);
}

export default function Privacy() {
	return (
		<div className="agy-agents">
			<PrivacySettings />
			<DsarTools />
			<TransparencyTool />
		</div>
	);
}
