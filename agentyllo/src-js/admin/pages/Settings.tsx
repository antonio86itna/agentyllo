/**
 * Settings page: tabbed, schema-driven forms.
 *
 * Per-tab drafts survive tab switches; a beforeunload guard protects unsaved
 * edits; stale fetches are ignored; load errors render with a retry.
 */
import { api as apiFetch } from '../api';
import { Button, Notice, TabPanel } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Loading from '../components/Loading';
import SchemaForm from '../components/SchemaForm';

const TAB_TITLES: Record< string, string > = {
	general: __( 'General', 'agentyllo' ),
	sources: __( 'Content Sources', 'agentyllo' ),
	widget: __( 'Widget', 'agentyllo' ),
	language: __( 'Language', 'agentyllo' ),
	privacy: __( 'Privacy & Legal', 'agentyllo' ),
	performance: __( 'Performance', 'agentyllo' ),
	advanced: __( 'Advanced', 'agentyllo' ),
};

// Tabs with a dedicated page elsewhere are hidden from the generic Settings
// screen (sources → Knowledge Base, privacy → Privacy & Legal).
const HIDDEN_TABS = [ 'sources', 'privacy', 'models' ];

type Drafts = Record< string, Record< string, any > >;

function TabContent( {
	tab,
	draft,
	onDraft,
}: {
	tab: string;
	draft: Record< string, any > | undefined;
	onDraft: ( tab: string, values: Record< string, any > | null ) => void;
} ) {
	const [ schema, setSchema ] = useState< any >( null );
	const [ values, setValues ] = useState< Record< string, any > >( {} );
	const [ dirty, setDirty ] = useState( !! draft );
	const [ saving, setSaving ] = useState( false );
	const [ loadError, setLoadError ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< { status: string; text: string } | null >( null );

	const load = useCallback( () => {
		let active = true;
		setLoadError( null );

		apiFetch( { path: `/settings/${ tab }` } )
			.then( ( res: any ) => {
				if ( ! active ) {
					return;
				}
				setSchema( res.schema );
				// A draft from a previous visit to this tab wins over stored values.
				setValues( draft ? { ...res.values, ...draft } : res.values );
			} )
			.catch( ( e: any ) => {
				if ( active ) {
					setLoadError( e?.message || __( 'Failed to load settings.', 'agentyllo' ) );
				}
			} );

		return () => {
			active = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- draft is only the initial seed.
	}, [ tab ] );

	useEffect( () => load(), [ load ] );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const res: any = await apiFetch( {
				path: `/settings/${ tab }`,
				method: 'PUT',
				data: { values },
			} );
			setValues( res.values );
			setDirty( false );
			onDraft( tab, null );
			setNotice( { status: 'success', text: __( 'Settings saved.', 'agentyllo' ) } );
		} catch ( e: any ) {
			setNotice( {
				status: 'error',
				text: e?.message || __( 'Saving failed.', 'agentyllo' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ loadError }{ ' ' }
				<Button variant="link" onClick={ () => load() }>
					{ __( 'Retry', 'agentyllo' ) }
				</Button>
			</Notice>
		);
	}
	if ( ! schema ) {
		return <Loading />;
	}

	return (
		<div className="agy-settings-tab">
			{ notice && (
				<Notice
					status={ notice.status as any }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<SchemaForm
				schema={ schema }
				values={ values }
				onChange={ ( key, value ) => {
					setValues( ( prev ) => {
						const next = { ...prev, [ key ]: value };
						onDraft( tab, next );
						return next;
					} );
					setDirty( true );
				} }
			/>
			<div className="agy-settings-tab__actions">
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ saving }
					disabled={ saving || ! dirty }
				>
					{ saving ? __( 'Saving…', 'agentyllo' ) : __( 'Save changes', 'agentyllo' ) }
				</Button>
				{ dirty && (
					<span className="agy-muted"> { __( 'Unsaved changes', 'agentyllo' ) }</span>
				) }
			</div>
		</div>
	);
}

export default function Settings() {
	const [ tabs, setTabs ] = useState< string[] | null >( null );
	const draftsRef = useRef< Drafts >( {} );
	const [ , bump ] = useState( 0 );

	useEffect( () => {
		apiFetch( { path: '/settings' } )
			.then( ( res: any ) => setTabs( ( res.tabs || [] ).filter( ( t: string ) => ! HIDDEN_TABS.includes( t ) ) ) )
			.catch( () => setTabs( [ 'general', 'advanced' ] ) );
	}, [] );

	// Warn before the browser discards unsaved drafts.
	useEffect( () => {
		const handler = ( event: BeforeUnloadEvent ) => {
			if ( Object.keys( draftsRef.current ).length > 0 ) {
				event.preventDefault();
			}
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [] );

	if ( ! tabs ) {
		return <Loading />;
	}

	return (
		<TabPanel
			className="agy-settings"
			tabs={ tabs.map( ( name ) => ( {
				name,
				title: TAB_TITLES[ name ] || name,
			} ) ) }
		>
			{ ( tab ) => (
				<TabContent
					key={ tab.name }
					tab={ tab.name }
					draft={ draftsRef.current[ tab.name ] }
					onDraft={ ( name, values ) => {
						if ( null === values ) {
							delete draftsRef.current[ name ];
						} else {
							draftsRef.current[ name ] = values;
						}
						bump( ( n ) => n + 1 );
					} }
				/>
			) }
		</TabPanel>
	);
}
