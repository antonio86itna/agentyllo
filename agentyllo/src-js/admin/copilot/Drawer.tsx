/**
 * Copilot drawer: persistent right-hand panel on every Agentyllo admin page
 * (Ctrl+Shift+K). Renders text/links/action_proposal/action_result blocks;
 * proposals execute only on the human "Confirm" click that returns the
 * server's single-use token. Also hosts TXT/MD/CSV ingestion with a
 * reviewable preview before anything reaches the knowledge base.
 */
import { api as apiFetch } from '../api';
import { Button, CheckboxControl, TextControl, TextareaControl } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

type Block = Record< string, any > & { type: string };
type Turn = { role: 'user' | 'assistant'; blocks: Block[]; id: string };
type PreviewRow = { title: string; content: string; type: string; include: boolean };

const STORAGE_KEY = 'agyl_copilot_open';

function inlineMd( text: string ): ( string | JSX.Element )[] {
	// Minimal: `code` and **bold**; everything else literal.
	const out: ( string | JSX.Element )[] = [];
	const re = /(`[^`]+`|\*\*[^*]+\*\*)/g;
	let last = 0;
	let m: RegExpExecArray | null;
	let k = 0;
	while ( ( m = re.exec( text ) ) ) {
		if ( m.index > last ) {
			out.push( text.slice( last, m.index ) );
		}
		const tok = m[ 0 ];
		out.push( tok.startsWith( '`' ) ? <code key={ k++ }>{ tok.slice( 1, -1 ) }</code> : <strong key={ k++ }>{ tok.slice( 2, -2 ) }</strong> );
		last = m.index + tok.length;
	}
	if ( last < text.length ) {
		out.push( text.slice( last ) );
	}
	return out;
}

function TextBlockView( { md }: { md: string } ) {
	return (
		<div className="agy-cp-text">
			{ md.split( '\n' ).map( ( line, i ) => (
				<div key={ i } className={ line.startsWith( '• ' ) ? 'agy-cp-li' : undefined }>
					{ inlineMd( line ) || ' ' }
				</div>
			) ) }
		</div>
	);
}

export default function Drawer() {
	const [ open, setOpen ] = useState( () => '1' === window.localStorage.getItem( STORAGE_KEY ) );
	const [ turns, setTurns ] = useState< Turn[] >( [] );
	const [ input, setInput ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ preview, setPreview ] = useState< { filename: string; rows: PreviewRow[] } | null >( null );
	const [ suggestions, setSuggestions ] = useState< any[] >( [] );
	const logRef = useRef< HTMLDivElement | null >( null );
	const fileRef = useRef< HTMLInputElement | null >( null );
	const loadedSuggestions = useRef( false );

	useEffect( () => {
		window.localStorage.setItem( STORAGE_KEY, open ? '1' : '0' );
		document.body.classList.toggle( 'agy-cp-open', open );
	}, [ open ] );

	useEffect( () => {
		const onPrefill = ( e: Event ) => {
			const text = ( e as CustomEvent ).detail?.text;
			if ( 'string' === typeof text ) {
				setInput( text );
				setOpen( true );
				window.setTimeout( () => document.querySelector< HTMLTextAreaElement >( '#agy-copilot textarea' )?.focus(), 50 );
			}
		};
		document.addEventListener( 'agyl:copilot-prefill', onPrefill );
		return () => document.removeEventListener( 'agyl:copilot-prefill', onPrefill );
	}, [] );

	useEffect( () => {
		const onKey = ( e: KeyboardEvent ) => {
			if ( e.ctrlKey && e.shiftKey && 'K' === e.key.toUpperCase() ) {
				e.preventDefault();
				setOpen( ( v ) => ! v );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [] );

	useEffect( () => {
		if ( logRef.current ) {
			logRef.current.scrollTop = logRef.current.scrollHeight;
		}
	}, [ turns, preview ] );

	const push = useCallback( ( turn: Turn ) => setTurns( ( t ) => [ ...t.slice( -60 ), turn ] ), [] );

	// Proactive suggestions (top unanswered visitor questions) loaded once when
	// the drawer is first opened.
	useEffect( () => {
		if ( ! open || loadedSuggestions.current ) {
			return;
		}
		loadedSuggestions.current = true;
		apiFetch( { path: '/copilot/suggestions' } )
			.then( ( res: any ) => setSuggestions( Array.isArray( res.suggestions ) ? res.suggestions : [] ) )
			.catch( () => {} );
	}, [ open ] );

	// Compact history (last turns as {role, text}) sent for conversational memory.
	const toHistory = ( list: Turn[] ) =>
		list.slice( -6 ).map( ( turn ) => ( {
			role: turn.role,
			text: turn.blocks
				.map( ( b ) => ( b.type === 'text' ? b.md : b.type === 'action_result' ? b.message : b.type === 'action_proposal' ? b.summary : '' ) )
				.filter( Boolean )
				.join( ' ' )
				.slice( 0, 1500 ),
		} ) ).filter( ( t ) => t.text );

	const send = async ( override?: string ) => {
		const text = ( override ?? input ).trim();
		if ( ! text || busy ) {
			return;
		}
		const history = toHistory( turns );
		setInput( '' );
		setSuggestions( [] );
		push( { role: 'user', id: 'u' + Date.now(), blocks: [ { type: 'text', md: text } ] } );
		setBusy( true );
		try {
			const res: any = await apiFetch( { path: '/copilot/message', method: 'POST', data: { text, history } } );
			push( { role: 'assistant', id: 'a' + Date.now(), blocks: Array.isArray( res.blocks ) ? res.blocks : [] } );
		} catch ( e: any ) {
			push( { role: 'assistant', id: 'e' + Date.now(), blocks: [ { type: 'text', md: e?.message || __( 'Request failed.', 'agentyllo' ) } ] } );
		} finally {
			setBusy( false );
		}
	};

	const confirm = async ( turnId: string, block: Block ) => {
		setBusy( true );
		try {
			const res: any = await apiFetch( { path: '/copilot/confirm', method: 'POST', data: { action: block.action, args: block.args, token: block.token } } );
			// Replace the proposal card with the result in place.
			setTurns( ( t ) => t.map( ( turn ) => turn.id !== turnId ? turn : { ...turn, blocks: turn.blocks.map( ( b ) => b === block ? ( res.blocks?.[ 0 ] || { type: 'text', md: __( 'Done.', 'agentyllo' ) } ) : b ) } ) );
		} catch ( e: any ) {
			push( { role: 'assistant', id: 'e' + Date.now(), blocks: [ { type: 'text', md: e?.message || __( 'Request failed.', 'agentyllo' ) } ] } );
		} finally {
			setBusy( false );
		}
	};

	const dismiss = ( turnId: string, block: Block ) => {
		setTurns( ( t ) => t.map( ( turn ) => turn.id !== turnId ? turn : { ...turn, blocks: turn.blocks.map( ( b ) => b === block ? { type: 'text', md: __( 'Cancelled.', 'agentyllo' ) } : b ) } ) );
	};

	const upload = async ( file: File ) => {
		setBusy( true );
		const form = new FormData();
		form.append( 'file', file );
		try {
			const res: any = await apiFetch( { path: '/copilot/upload', method: 'POST', body: form } );
			setPreview( { filename: res.filename, rows: ( res.rows || [] ).map( ( r: any ) => ( { ...r, include: true } ) ) } );
		} catch ( e: any ) {
			push( { role: 'assistant', id: 'e' + Date.now(), blocks: [ { type: 'text', md: e?.message || __( 'Upload failed.', 'agentyllo' ) } ] } );
		} finally {
			setBusy( false );
			if ( fileRef.current ) {
				fileRef.current.value = '';
			}
		}
	};

	const commitPreview = async () => {
		if ( ! preview ) {
			return;
		}
		setBusy( true );
		try {
			const res: any = await apiFetch( { path: '/copilot/ingest', method: 'POST', data: { rows: preview.rows } } );
			push( {
				role: 'assistant',
				id: 'i' + Date.now(),
				blocks: [ { type: 'text', md: sprintf( /* translators: 1: created, 2: skipped, 3: file */ __( 'Imported %1$d entries from %3$s (%2$d skipped). They are searchable right away.', 'agentyllo' ), res.created, res.skipped, preview.filename ) } ],
			} );
			setPreview( null );
		} catch ( e: any ) {
			push( { role: 'assistant', id: 'e' + Date.now(), blocks: [ { type: 'text', md: e?.message || __( 'Import failed.', 'agentyllo' ) } ] } );
		} finally {
			setBusy( false );
		}
	};

	const renderBlock = ( turn: Turn, block: Block, i: number ) => {
		switch ( block.type ) {
			case 'text':
				return <TextBlockView key={ i } md={ String( block.md || '' ) } />;
			case 'links':
				return (
					<ul key={ i } className="agy-cp-links">
						{ ( block.items || [] ).map( ( item: any, j: number ) => (
							<li key={ j }><a href={ item.url } target="_blank" rel="noreferrer">{ item.title }</a></li>
						) ) }
					</ul>
				);
			case 'action_proposal':
				return (
					<div key={ i } className={ 'agy-cp-card ' + ( block.destructive ? 'is-destructive' : '' ) }>
						<div className="agy-cp-card__title">{ block.summary }</div>
						{ block.details && Object.keys( block.details ).length > 0 && (
							<table className="agy-cp-args">
								<tbody>
									{ Object.entries( block.details ).map( ( [ k, v ] ) => (
										<tr key={ k }><th>{ k }</th><td>{ 'object' === typeof v ? JSON.stringify( v ) : String( v ) }</td></tr>
									) ) }
								</tbody>
							</table>
						) }
						<div className="agy-cp-card__actions">
							<Button variant="primary" isDestructive={ !! block.destructive } disabled={ busy } onClick={ () => confirm( turn.id, block ) }>
								{ block.destructive ? __( 'Confirm', 'agentyllo' ) : __( 'Apply', 'agentyllo' ) }
							</Button>
							<Button variant="tertiary" disabled={ busy } onClick={ () => dismiss( turn.id, block ) }>{ __( 'Cancel', 'agentyllo' ) }</Button>
						</div>
					</div>
				);
			case 'action_result':
				return (
					<div key={ i } className={ 'agy-cp-result ' + ( block.ok ? 'is-ok' : 'is-error' ) }>
						<strong>{ block.ok ? '✓' : '✕' }</strong> { block.message }
						{ block.data?.entries && (
							<ul className="agy-cp-links">
								{ block.data.entries.map( ( e: any ) => <li key={ e.id }>#{ e.id } — { e.title } <span className="agy-muted">({ e.status })</span></li> ) }
							</ul>
						) }
						{ block.data?.facts && (
							<ul className="agy-cp-links">
								{ block.data.facts.map( ( f: any ) => <li key={ f.key }>{ f.text }</li> ) }
							</ul>
						) }
						{ block.data?.top_unanswered && block.data.top_unanswered.length > 0 && (
							<ul className="agy-cp-links">
								{ block.data.top_unanswered.map( ( u: any ) => <li key={ u.id }>{ u.question_sample } <span className="agy-muted">×{ u.hits }</span></li> ) }
							</ul>
						) }
					</div>
				);
			default:
				return null;
		}
	};

	return (
		<>
			<button type="button" className="agy-cp-toggle" onClick={ () => setOpen( ( v ) => ! v ) } aria-expanded={ open } aria-controls="agy-copilot" title="Ctrl+Shift+K">
				{ open ? __( 'Close copilot', 'agentyllo' ) : __( 'Copilot', 'agentyllo' ) }
			</button>
			<aside id="agy-copilot" className={ 'agy-cp' + ( open ? ' is-open' : '' ) } aria-label={ __( 'Agentyllo copilot', 'agentyllo' ) }>
				<header className="agy-cp__header">
					<strong>{ __( 'Copilot', 'agentyllo' ) }</strong>
					<span className="agy-muted">{ __( 'Ask in plain language or type /help', 'agentyllo' ) }</span>
					<button type="button" className="agy-cp__close" onClick={ () => setOpen( false ) } aria-label={ __( 'Close copilot', 'agentyllo' ) } title="Ctrl+Shift+K">
						✕
					</button>
				</header>
				<div className="agy-cp__log" ref={ logRef } role="log" aria-live="polite">
					{ 0 === turns.length && (
						<>
							<TextBlockView md={ __( 'Hi! Ask me anything about your site, or tell me what to do in plain language — I can add or edit knowledge-base entries, change settings, remember facts, summarize statistics and import files. Every change is proposed first — nothing runs until you confirm.', 'agentyllo' ) } />
							{ suggestions.length > 0 && (
								<div className="agy-cp-suggest">
									<div className="agy-cp-suggest__label">{ __( 'Suggestions for you', 'agentyllo' ) }</div>
									{ suggestions.map( ( s ) => (
										<button
											key={ s.id }
											type="button"
											className="agy-cp-suggest__item"
											disabled={ busy }
											onClick={ () => send( s.prefill ) }
										>
											<span className="agy-cp-suggest__title">{ s.title }</span>
											<span className="agy-cp-suggest__text">{ s.text }</span>
										</button>
									) ) }
								</div>
							) }
						</>
					) }
					{ turns.map( ( turn ) => (
						<div key={ turn.id } className={ 'agy-cp-turn is-' + turn.role }>
							{ turn.blocks.map( ( b, i ) => renderBlock( turn, b, i ) ) }
						</div>
					) ) }
					{ preview && (
						<div className="agy-cp-card">
							<div className="agy-cp-card__title">{ sprintf( /* translators: 1: rows, 2: file */ __( 'Preview: %1$d entries from %2$s — untick or edit before importing', 'agentyllo' ), preview.rows.length, preview.filename ) }</div>
							<div className="agy-cp-preview">
								{ preview.rows.slice( 0, 50 ).map( ( row, i ) => (
									<div key={ i } className="agy-cp-preview__row">
										<CheckboxControl __nextHasNoMarginBottom checked={ row.include } label={ '' } onChange={ ( v: boolean ) => setPreview( { ...preview, rows: preview.rows.map( ( r, j ) => j === i ? { ...r, include: v } : r ) } ) } />
										<div className="agy-cp-preview__fields">
											<TextControl __nextHasNoMarginBottom value={ row.title } onChange={ ( v: string ) => setPreview( { ...preview, rows: preview.rows.map( ( r, j ) => j === i ? { ...r, title: v } : r ) } ) } />
											<TextareaControl __nextHasNoMarginBottom rows={ 2 } value={ row.content } onChange={ ( v: string ) => setPreview( { ...preview, rows: preview.rows.map( ( r, j ) => j === i ? { ...r, content: v } : r ) } ) } />
										</div>
									</div>
								) ) }
								{ preview.rows.length > 50 && <p className="agy-muted">{ sprintf( /* translators: %d: hidden rows */ __( '…and %d more rows (imported as parsed).', 'agentyllo' ), preview.rows.length - 50 ) }</p> }
							</div>
							<div className="agy-cp-card__actions">
								<Button variant="primary" disabled={ busy } onClick={ commitPreview }>{ __( 'Import selected', 'agentyllo' ) }</Button>
								<Button variant="tertiary" disabled={ busy } onClick={ () => setPreview( null ) }>{ __( 'Discard', 'agentyllo' ) }</Button>
							</div>
						</div>
					) }
				</div>
				<form
					className="agy-cp__composer"
					onSubmit={ ( e ) => {
						e.preventDefault();
						send();
					} }
				>
					<textarea
						value={ input }
						rows={ 2 }
						placeholder={ __( 'Ask or type a /command…', 'agentyllo' ) }
						disabled={ busy }
						onChange={ ( e ) => setInput( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( 'Enter' === e.key && ! e.shiftKey ) {
								e.preventDefault();
								send();
							}
						} }
					/>
					<div className="agy-cp__composer-actions">
						<label className="agy-cp__upload">
							<input ref={ fileRef } type="file" accept=".txt,.md,.csv" hidden onChange={ ( e ) => e.target.files?.[ 0 ] && upload( e.target.files[ 0 ] ) } />
							<span className="button">{ __( 'Import file (txt/md/csv)', 'agentyllo' ) }</span>
						</label>
						<Button variant="primary" type="submit" isBusy={ busy } disabled={ busy || ! input.trim() }>{ __( 'Send', 'agentyllo' ) }</Button>
					</div>
				</form>
			</aside>
		</>
	);
}
