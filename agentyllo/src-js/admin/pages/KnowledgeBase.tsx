/**
 * Knowledge Base page: content sources, indexed entries, index health.
 *
 * Three tabs. Sources reuses the schema-driven form of the `sources`
 * settings tab plus per-source item pickers; Entries lists indexed
 * documents with filters and per-row exclusion; Health shows coverage
 * bars, index counters, and the rebuild trigger.
 */
import { api as apiFetch } from '../api';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SearchControl,
	SelectControl,
	TabPanel,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Loading from '../components/Loading';
import SchemaForm from '../components/SchemaForm';
import ItemPicker from './kb/ItemPicker';

const PER_PAGE = 20;

type PickerTarget = { source: string; subtype: string; title: string };

const PICKER_BUTTONS: Array< PickerTarget & { field: string; label: string } > = [
	{
		field: 'posts_enabled',
		source: 'post',
		subtype: 'post',
		title: __( 'Posts', 'agentyllo' ),
		label: __( 'Choose individual posts…', 'agentyllo' ),
	},
	{
		field: 'pages_enabled',
		source: 'post',
		subtype: 'page',
		title: __( 'Pages', 'agentyllo' ),
		label: __( 'Choose individual pages…', 'agentyllo' ),
	},
	{
		field: 'woocommerce_enabled',
		source: 'product',
		subtype: '',
		title: __( 'Products', 'agentyllo' ),
		label: __( 'Choose individual products…', 'agentyllo' ),
	},
	{
		field: 'taxonomies_enabled',
		source: 'taxonomy',
		subtype: '',
		title: __( 'Taxonomy terms', 'agentyllo' ),
		label: __( 'Choose individual terms…', 'agentyllo' ),
	},
];

function SourcesTab() {
	const [ schema, setSchema ] = useState< any >( null );
	const [ values, setValues ] = useState< Record< string, any > >( {} );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ loadError, setLoadError ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< { status: string; text: string } | null >( null );
	const [ picker, setPicker ] = useState< PickerTarget | null >( null );

	const load = useCallback( () => {
		let active = true;
		setLoadError( null );

		apiFetch( { path: '/settings/sources' } )
			.then( ( res: any ) => {
				if ( ! active ) {
					return;
				}
				setSchema( res.schema );
				setValues( res.values );
			} )
			.catch( ( e: any ) => {
				if ( active ) {
					setLoadError( e?.message || __( 'Failed to load sources.', 'agentyllo' ) );
				}
			} );

		return () => {
			active = false;
		};
	}, [] );

	useEffect( () => load(), [ load ] );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const res: any = await apiFetch( {
				path: '/settings/sources',
				method: 'PUT',
				data: { values },
			} );
			setValues( res.values );
			setDirty( false );
			setNotice( {
				status: 'success',
				text: __(
					'Saved. The knowledge base is updating in the background.',
					'agentyllo'
				),
			} );
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

	const pickers = PICKER_BUTTONS.filter( ( p ) => p.field in schema );

	return (
		<div className="agy-settings-tab">
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Disabling a source immediately removes its content from the knowledge base.',
					'agentyllo'
				) }
			</Notice>
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
					setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
					setDirty( true );
				} }
			/>
			{ pickers.length > 0 && (
				<div style={ { marginTop: 16 } }>
					<h3 className="agy-card-title">{ __( 'Per-item exclusions', 'agentyllo' ) }</h3>
					<div
						style={ {
							display: 'flex',
							flexWrap: 'wrap',
							gap: 8,
							marginTop: 8,
						} }
					>
						{ pickers.map( ( p ) => (
							<Button
								key={ p.field }
								variant="secondary"
								onClick={ () =>
									setPicker( {
										source: p.source,
										subtype: p.subtype,
										title: p.title,
									} )
								}
							>
								{ p.label }
							</Button>
						) ) }
					</div>
				</div>
			) }
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
			{ picker && (
				<ItemPicker
					source={ picker.source }
					subtype={ picker.subtype }
					title={ picker.title }
					onClose={ () => setPicker( null ) }
				/>
			) }
		</div>
	);
}

type EntryRow = {
	id: number;
	source: string;
	external_id: string;
	subtype: string;
	status: string;
	title: string;
	permalink: string;
	chunk_count: number;
	lang: string;
	indexed_at: string;
};

const STATUS_BADGE: Record< string, string > = {
	active: ' agy-badge--ok',
	excluded: '',
	purging: ' agy-badge--warn',
	error: ' agy-badge--error',
};

const SOURCE_OPTIONS = [
	{ value: '', label: __( 'All sources', 'agentyllo' ) },
	{ value: 'post', label: __( 'Posts & pages', 'agentyllo' ) },
	{ value: 'product', label: __( 'Products', 'agentyllo' ) },
	{ value: 'taxonomy', label: __( 'Taxonomies', 'agentyllo' ) },
	{ value: 'menu', label: __( 'Menus', 'agentyllo' ) },
	{ value: 'site', label: __( 'Site identity', 'agentyllo' ) },
	{ value: 'manual', label: __( 'Manual entries', 'agentyllo' ) },
];

const STATUS_OPTIONS = [
	{ value: '', label: __( 'All statuses', 'agentyllo' ) },
	{ value: 'active', label: __( 'Active', 'agentyllo' ) },
	{ value: 'excluded', label: __( 'Excluded', 'agentyllo' ) },
	{ value: 'purging', label: __( 'Purging', 'agentyllo' ) },
	{ value: 'error', label: __( 'Error', 'agentyllo' ) },
];

function EntriesTab() {
	const [ data, setData ] = useState< { items: EntryRow[]; total: number } | null >( null );
	const [ search, setSearch ] = useState( '' );
	const [ source, setSource ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ error, setError ] = useState< string | null >( null );
	// Bumped after mutations (exclude/delete) to refetch the current view.
	const [ reloadTick, setReloadTick ] = useState( 0 );

	const load = useCallback( () => setReloadTick( ( t ) => t + 1 ), [] );

	// Debounced (300ms) + stale-guarded fetch; clamps the page downward when
	// the result set shrinks below the current page (e.g. last row excluded).
	useEffect( () => {
		let active = true;
		const timer = window.setTimeout( async () => {
			try {
				const res: any = await apiFetch( {
					path: `/kb/entries?search=${ encodeURIComponent( search ) }&source=${ source }&status=${ status }&page=${ page }&per_page=${ PER_PAGE }`,
				} );
				if ( ! active ) {
					return;
				}
				const maxPage = Math.max( 1, Math.ceil( ( res.total || 0 ) / PER_PAGE ) );
				if ( page > maxPage ) {
					setPage( maxPage ); // Triggers one more fetch on a valid page.
					return;
				}
				setData( { items: res.items, total: res.total } );
			} catch ( e: any ) {
				if ( active ) {
					setError( e?.message || __( 'Failed to load entries.', 'agentyllo' ) );
				}
			}
		}, 300 );

		return () => {
			active = false;
			window.clearTimeout( timer );
		};
	}, [ search, source, status, page, reloadTick ] );

	const exclude = async ( row: EntryRow ) => {
		try {
			await apiFetch( {
				path: '/kb/items/toggle',
				method: 'POST',
				data: {
					source: row.source,
					external_id: row.external_id,
					subtype: row.subtype,
					include: false,
				},
			} );
			load();
		} catch ( e: any ) {
			setError( e?.message || __( 'Could not exclude the entry.', 'agentyllo' ) );
		}
	};

	const removeManual = async ( row: EntryRow ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Delete this manual entry permanently?', 'agentyllo' ) ) ) {
			return;
		}
		try {
			await apiFetch( { path: `/kb/entries/${ row.id }`, method: 'DELETE' } );
			load();
		} catch ( e: any ) {
			setError( e?.message || __( 'Could not delete the entry.', 'agentyllo' ) );
		}
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }{ ' ' }
				<Button
					variant="link"
					onClick={ () => {
						setError( null );
						load();
					} }
				>
					{ __( 'Retry', 'agentyllo' ) }
				</Button>
			</Notice>
		);
	}
	if ( ! data ) {
		return <Loading />;
	}

	const totalPages = Math.max( 1, Math.ceil( data.total / PER_PAGE ) );

	return (
		<Card>
			<CardHeader>
				<h2 className="agy-card-title">{ __( 'Indexed entries', 'agentyllo' ) }</h2>
			</CardHeader>
			<CardBody>
				<div
					style={ {
						display: 'flex',
						flexWrap: 'wrap',
						gap: 12,
						alignItems: 'flex-end',
						marginBottom: 16,
					} }
				>
					<div style={ { flex: '1 1 240px' } }>
						<SearchControl
							__nextHasNoMarginBottom
							label={ __( 'Search entries', 'agentyllo' ) }
							value={ search }
							onChange={ ( value: string ) => {
								setSearch( value );
								setPage( 1 );
							} }
						/>
					</div>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Source', 'agentyllo' ) }
						value={ source }
						options={ SOURCE_OPTIONS }
						onChange={ ( value: string ) => {
							setSource( value );
							setPage( 1 );
						} }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Status', 'agentyllo' ) }
						value={ status }
						options={ STATUS_OPTIONS }
						onChange={ ( value: string ) => {
							setStatus( value );
							setPage( 1 );
						} }
					/>
				</div>
				{ 0 === data.items.length ? (
					<p className="agy-muted">{ __( 'No entries found.', 'agentyllo' ) }</p>
				) : (
					<table className="agy-probe-table">
						<thead>
							<tr>
								<th>{ __( 'Title', 'agentyllo' ) }</th>
								<th>{ __( 'Source', 'agentyllo' ) }</th>
								<th>{ __( 'Status', 'agentyllo' ) }</th>
								<th>{ __( 'Chunks', 'agentyllo' ) }</th>
								<th>{ __( 'Language', 'agentyllo' ) }</th>
								<th>{ __( 'Indexed', 'agentyllo' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( row ) => (
								<tr key={ row.id }>
									<td>
										{ row.permalink ? (
											<a
												href={ row.permalink }
												target="_blank"
												rel="noreferrer"
											>
												{ row.title || row.external_id }
											</a>
										) : (
											<strong>{ row.title || row.external_id }</strong>
										) }
									</td>
									<td>
										{ row.source }
										{ row.subtype && (
											<span className="agy-muted"> / { row.subtype }</span>
										) }
									</td>
									<td>
										<span
											className={
												'agy-badge' + ( STATUS_BADGE[ row.status ] || '' )
											}
										>
											{ row.status }
										</span>
									</td>
									<td>{ row.chunk_count }</td>
									<td>{ row.lang || '—' }</td>
									<td className="agy-muted">{ row.indexed_at }</td>
									<td>
										{ 'manual' === row.source ? (
											<Button
												variant="link"
												isDestructive
												onClick={ () => removeManual( row ) }
											>
												{ __( 'Delete', 'agentyllo' ) }
											</Button>
										) : 'active' === row.status ? (
											<Button
												variant="link"
												onClick={ () => exclude( row ) }
											>
												{ __( 'Exclude', 'agentyllo' ) }
											</Button>
										) : null }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'space-between',
						marginTop: 16,
					} }
				>
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
					>
						{ __( 'Previous', 'agentyllo' ) }
					</Button>
					<span className="agy-muted">
						{ sprintf(
							/* translators: 1: current page, 2: total pages, 3: total entries */
							__( 'Page %1$d of %2$d (%3$d entries)', 'agentyllo' ),
							page,
							totalPages,
							data.total
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= totalPages }
						onClick={ () => setPage( ( p ) => p + 1 ) }
					>
						{ __( 'Next', 'agentyllo' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

type CoverageCell = {
	active_docs: number;
	chunks: number;
	excluded: number;
	purging: number;
	error: number;
};

const HEALTH_COUNTERS: Array< [ string, string ] > = [
	[ 'stale', __( 'Stale documents', 'agentyllo' ) ],
	[ 'errors', __( 'Errors', 'agentyllo' ) ],
	[ 'broken_links', __( 'Broken links', 'agentyllo' ) ],
	[ 'orphans', __( 'Orphan pages', 'agentyllo' ) ],
	[ 'duplicate_clusters', __( 'Duplicate clusters', 'agentyllo' ) ],
];

function ProgressBar( { pct }: { pct: number } ) {
	return (
		<div
			style={ {
				background: '#eef1f5',
				borderRadius: 4,
				height: 8,
				width: '100%',
				maxWidth: 240,
			} }
		>
			<div
				style={ {
					width: `${ Math.max( 0, Math.min( 100, pct ) ) }%`,
					background: '#1a6b39',
					height: 8,
					borderRadius: 4,
				} }
			/>
		</div>
	);
}

function HealthTab() {
	const [ overview, setOverview ] = useState< any >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ rebuilding, setRebuilding ] = useState( false );
	const [ notice, setNotice ] = useState< { status: string; text: string } | null >( null );

	const load = useCallback( async () => {
		try {
			const res: any = await apiFetch( { path: '/kb/overview' } );
			setOverview( res );
		} catch ( e: any ) {
			setError( e?.message || __( 'Failed to load KB health.', 'agentyllo' ) );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const rebuild = async () => {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				__(
					'Rebuild the entire knowledge base index? This runs in the background.',
					'agentyllo'
				)
			)
		) {
			return;
		}
		setRebuilding( true );
		setNotice( null );
		try {
			await apiFetch( { path: '/kb/reindex', method: 'POST', data: {} } );
			setNotice( {
				status: 'success',
				text: __( 'Reindex started. It runs in the background.', 'agentyllo' ),
			} );
		} catch ( e: any ) {
			setNotice( {
				status: 'error',
				text: e?.message || __( 'Could not start the reindex.', 'agentyllo' ),
			} );
		} finally {
			setRebuilding( false );
		}
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }{ ' ' }
				<Button
					variant="link"
					onClick={ () => {
						setError( null );
						load();
					} }
				>
					{ __( 'Retry', 'agentyllo' ) }
				</Button>
			</Notice>
		);
	}
	if ( ! overview ) {
		return <Loading />;
	}

	const coverage: Record< string, Record< string, CoverageCell > > = overview.coverage || {};
	const health: Record< string, any > = overview.health || {};
	const rows: Array< { key: string; label: string; cell: CoverageCell } > = [];
	Object.entries( coverage ).forEach( ( [ src, subtypes ] ) => {
		Object.entries( subtypes ).forEach( ( [ sub, cell ] ) => {
			rows.push( {
				key: `${ src }/${ sub }`,
				label: sub ? `${ src } / ${ sub }` : src,
				cell,
			} );
		} );
	} );

	return (
		<div style={ { display: 'flex', flexDirection: 'column', gap: 16 } }>
			{ notice && (
				<Notice
					status={ notice.status as any }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Coverage', 'agentyllo' ) }</h2>
				</CardHeader>
				<CardBody>
					{ 0 === rows.length ? (
						<p className="agy-muted">
							{ __( 'Nothing indexed yet. Run a rebuild to get started.', 'agentyllo' ) }
						</p>
					) : (
						<table className="agy-probe-table">
							<tbody>
								{ rows.map( ( row ) => {
									const known =
										row.cell.active_docs +
										row.cell.excluded +
										row.cell.purging +
										row.cell.error;
									const pct = known > 0 ? ( row.cell.active_docs / known ) * 100 : 0;
									return (
										<tr key={ row.key }>
											<th>{ row.label }</th>
											<td>
												<ProgressBar pct={ pct } />
											</td>
											<td>
												{ sprintf(
													/* translators: 1: active documents, 2: chunk count */
													__( '%1$d active · %2$d chunks', 'agentyllo' ),
													row.cell.active_docs,
													row.cell.chunks
												) }
											</td>
											<td>
												{ row.cell.excluded > 0 && (
													<span className="agy-badge">
														{ sprintf(
															/* translators: %d: excluded count */
															__( '%d excluded', 'agentyllo' ),
															row.cell.excluded
														) }
													</span>
												) }{ ' ' }
												{ row.cell.purging > 0 && (
													<span className="agy-badge agy-badge--warn">
														{ sprintf(
															/* translators: %d: purging count */
															__( '%d purging', 'agentyllo' ),
															row.cell.purging
														) }
													</span>
												) }{ ' ' }
												{ row.cell.error > 0 && (
													<span className="agy-badge agy-badge--error">
														{ sprintf(
															/* translators: %d: error count */
															__( '%d errors', 'agentyllo' ),
															row.cell.error
														) }
													</span>
												) }
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Index health', 'agentyllo' ) }</h2>
				</CardHeader>
				<CardBody>
					<table className="agy-probe-table">
						<tbody>
							{ HEALTH_COUNTERS.map( ( [ key, label ] ) => {
								const count = Number( health[ key ] ?? 0 );
								const cls =
									count > 0
										? 'errors' === key
											? ' agy-badge--error'
											: ' agy-badge--warn'
										: ' agy-badge--ok';
								return (
									<tr key={ key }>
										<th>{ label }</th>
										<td>
											<span className={ 'agy-badge' + cls }>{ count }</span>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
					<p className="agy-muted">
						{ sprintf(
							/* translators: 1: index version number, 2: last crawl date */
							__( 'Index version %1$d · last crawl: %2$s', 'agentyllo' ),
							Number( overview.kb_version || 0 ),
							overview.last_crawl
								? new Date( Number( overview.last_crawl ) * 1000 ).toLocaleString()
								: __( 'never', 'agentyllo' )
						) }
					</p>
					<Button
						variant="secondary"
						onClick={ rebuild }
						isBusy={ rebuilding }
						disabled={ rebuilding }
					>
						{ __( 'Rebuild index', 'agentyllo' ) }
					</Button>
				</CardBody>
			</Card>
		</div>
	);
}

export default function KnowledgeBase() {
	return (
		<TabPanel
			className="agy-kb"
			tabs={ [
				{ name: 'sources', title: __( 'Sources', 'agentyllo' ) },
				{ name: 'entries', title: __( 'Entries', 'agentyllo' ) },
				{ name: 'health', title: __( 'Health', 'agentyllo' ) },
			] }
		>
			{ ( tab ) => {
				switch ( tab.name ) {
					case 'entries':
						return <EntriesTab />;
					case 'health':
						return <HealthTab />;
					default:
						return <SourcesTab />;
				}
			} }
		</TabPanel>
	);
}
