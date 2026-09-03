/**
 * Dashboard: tier badge, hosting capability report, background status.
 */
import { api as apiFetch } from '../api';
import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Loading from '../components/Loading';
import Sparkline from '../components/Sparkline';

const TIER_LABELS: Record< string, string > = {
	t1a: __( 'Classic — instant, reliable, non-generative', 'agentyllo' ),
	t1b: __( 'Classic + semantic matching (ONNX embeddings)', 'agentyllo' ),
	t2: __( 'AI-assisted classic (bounded local generation)', 'agentyllo' ),
	t3: __( 'Full local AI chat possible (llama.cpp class)', 'agentyllo' ),
};

const bytes = ( n: number | null | undefined ): string => {
	if ( null === n || undefined === n ) {
		return '—';
	}
	if ( n >= 1073741824 ) {
		return ( n / 1073741824 ).toFixed( 1 ) + ' GB';
	}
	return Math.round( n / 1048576 ) + ' MB';
};

const yesNo = ( v: unknown ): string =>
	null === v || undefined === v
		? __( 'unknown', 'agentyllo' )
		: v
		? __( 'yes', 'agentyllo' )
		: __( 'no', 'agentyllo' );

export default function Dashboard() {
	const [ dashboard, setDashboard ] = useState< any >( null );
	const [ capabilities, setCapabilities ] = useState< any >( null );
	const [ scanning, setScanning ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const load = useCallback( async () => {
		try {
			const [ dash, caps ] = await Promise.all( [
				apiFetch( { path: '/dashboard' } ),
				apiFetch( { path: '/capabilities' } ),
			] );
			setDashboard( dash );
			setCapabilities( caps );
		} catch ( e: any ) {
			setError( e?.message || __( 'Failed to load dashboard data.', 'agentyllo' ) );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const rescan = async () => {
		setScanning( true );
		setError( null );
		try {
			const caps = await apiFetch( { path: '/capabilities/rescan', method: 'POST' } );
			setCapabilities( caps );
		} catch ( e: any ) {
			setError( e?.message || __( 'Re-scan failed.', 'agentyllo' ) );
		} finally {
			setScanning( false );
		}
	};

	// Initial-load failure: nothing to show yet → notice + retry.
	if ( ! dashboard || ! capabilities ) {
		if ( error ) {
			return (
				<Notice status="error" isDismissible={ false }>
					{ error }{ ' ' }
					<Button variant="link" onClick={ () => { setError( null ); load(); } }>
						{ __( 'Retry', 'agentyllo' ) }
					</Button>
				</Notice>
			);
		}
		return <Loading />;
	}

	const p = capabilities.probes || {};
	const deepPending = !! p.deep_pending;
	const tiers = capabilities.tiers || {};
	const best: string = tiers.best_free_tier || 't1a';

	const pendingNote = deepPending ? (
		<p className="agy-muted">
			{ __( 'A full hosting scan (including the network self-test) is running in the background — this report will refine itself shortly.', 'agentyllo' ) }
		</p>
	) : null;

	const probeRows: Array< [ string, string ] > = [
		[ __( 'PHP', 'agentyllo' ), `${ p.php_version || '—' } (${ p.sapi || '?' })` ],
		[ __( 'Server', 'agentyllo' ), p.server_software || '—' ],
		[ __( 'Database', 'agentyllo' ), p.db_server_info || '—' ],
		[ __( 'Memory limit', 'agentyllo' ), bytes( p.memory_limit_bytes ) ],
		[
			__( 'Max execution time', 'agentyllo' ),
			`${ p.max_execution_time ?? '—' }s` +
				( p.exec_time_extendable ? ' ' + __( '(extendable)', 'agentyllo' ) : '' ),
		],
		[ __( 'Process execution (proc_open)', 'agentyllo' ), yesNo( p.proc_open_works ) ],
		[ __( 'FFI extension', 'agentyllo' ), yesNo( p.ffi_enabled ) ],
		[
			__( 'CPU', 'agentyllo' ),
			sprintf(
				/* translators: 1: core count, 2: benchmark score */
				__( '%1$s cores — score %2$s/100', 'agentyllo' ),
				p.cpu_cores ?? '?',
				p.cpu_score ?? '?'
			),
		],
		[ __( 'Free disk space', 'agentyllo' ), bytes( p.disk_free_bytes ) ],
		[ __( 'cURL', 'agentyllo' ), yesNo( p.curl ) ],
		[ __( 'Loopback HTTP', 'agentyllo' ), yesNo( p.loopback_ok ) ],
		[
			__( 'Page cache detected', 'agentyllo' ),
			( p.page_cache_detected || [] ).join( ', ' ) || __( 'none', 'agentyllo' ),
		],
		[ __( 'Uploads dir writable', 'agentyllo' ), yesNo( p.uploads_writable ) ],
	];

	return (
		<>
			{ error && (
				// Transient failure (e.g. a re-scan): keep the dashboard, show the error inline.
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
		<div className="agy-admin__grid">
			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Active tier', 'agentyllo' ) }</h2>
				</CardHeader>
				<CardBody>
					<div className="agy-tier-badge" data-tier={ best }>
						{ TIER_LABELS[ best ] || best }
					</div>
					<p className="agy-muted">
						{ sprintf(
							/* translators: %s: operating mode id */
							__( 'Operating mode: %s', 'agentyllo' ),
							dashboard.settings?.operating_mode || 'classic'
						) }
					</p>
					<p className="agy-muted">
						{ __( 'Cloud AI ready (needs an API key):', 'agentyllo' ) } { yesNo( tiers.t5 ) }
					</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Hosting capabilities', 'agentyllo' ) }</h2>
					<Button variant="secondary" onClick={ rescan } isBusy={ scanning } disabled={ scanning }>
						{ scanning ? __( 'Scanning…', 'agentyllo' ) : __( 'Re-scan', 'agentyllo' ) }
					</Button>
				</CardHeader>
				<CardBody>
					{ pendingNote }
					<table className="agy-probe-table">
						<tbody>
							{ probeRows.map( ( [ label, value ] ) => (
								<tr key={ label }>
									<th scope="row">{ label }</th>
									<td>{ value }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Background processing', 'agentyllo' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __( 'Scheduler:', 'agentyllo' ) }{ ' ' }
						{ dashboard.background?.scheduler === 'action-scheduler'
							? __( 'Action Scheduler (active)', 'agentyllo' )
							: __( 'unavailable', 'agentyllo' ) }
					</p>
					<p>
						{ __( 'Pending jobs:', 'agentyllo' ) }{ ' ' }
						{ dashboard.background?.pending ?? '—' }
					</p>
					<p className="agy-muted">
						{ sprintf(
							/* translators: %s: date/time of the last scan */
							__( 'Last hosting scan: %s', 'agentyllo' ),
							capabilities.detected_at
								? new Date( capabilities.detected_at * 1000 ).toLocaleString()
								: '—'
						) }
					</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Knowledge base', 'agentyllo' ) }</h2>
					<Button variant="link" href="admin.php?page=agentyllo-kb">{ __( 'Open', 'agentyllo' ) }</Button>
				</CardHeader>
				<CardBody>
					<div className="agy-kpi">{ dashboard.kb?.documents ?? 0 }</div>
					<p className="agy-muted">
						{ sprintf(
							/* translators: 1: chunk count, 2: purging count, 3: error count */
							__( '%1$d chunks · %2$d purging · %3$d errors', 'agentyllo' ),
							dashboard.kb?.chunks ?? 0,
							dashboard.kb?.purging ?? 0,
							dashboard.kb?.errors ?? 0
						) }
					</p>
					<p className="agy-muted">
						{ __( 'Last indexed:', 'agentyllo' ) } { dashboard.kb?.last_indexed || __( 'never', 'agentyllo' ) }
					</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Agents', 'agentyllo' ) }</h2>
					<Button variant="link" href="admin.php?page=agentyllo-agents">{ __( 'Open', 'agentyllo' ) }</Button>
				</CardHeader>
				<CardBody>
					<div className="agy-kpi">{ dashboard.agents?.total ?? 0 }</div>
					<p className="agy-muted">
						{ dashboard.agents?.quarantined > 0 || dashboard.agents?.unhealthy > 0 ? (
							<span className="agy-badge agy-badge--warn">
								{ sprintf(
									/* translators: 1: quarantined count, 2: unhealthy count */
									__( '%1$d quarantined · %2$d unhealthy', 'agentyllo' ),
									dashboard.agents?.quarantined ?? 0,
									dashboard.agents?.unhealthy ?? 0
								) }
							</span>
						) : (
							<span className="agy-badge agy-badge--ok">{ __( 'All healthy', 'agentyllo' ) }</span>
						) }
					</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Last 7 days', 'agentyllo' ) }</h2>
					<Button variant="link" href="admin.php?page=agentyllo-stats">{ __( 'Statistics', 'agentyllo' ) }</Button>
				</CardHeader>
				<CardBody>
					<div className="agy-kpi">{ dashboard.stats?.totals?.conversations ?? 0 }</div>
					<p className="agy-muted">
						{ sprintf(
							/* translators: 1: messages, 2: deflection percentage */
							__( '%1$d messages · deflection %2$s', 'agentyllo' ),
							dashboard.stats?.totals?.messages ?? 0,
							null == dashboard.stats?.totals?.deflection_rate ? '—' : Math.round( dashboard.stats.totals.deflection_rate * 100 ) + '%'
						) }
					</p>
					<Sparkline
						label={ __( 'Conversations per day', 'agentyllo' ) }
						color="#0a2a4e"
						values={ ( () => {
							const byDay: Record< string, number > = {};
							for ( const row of dashboard.stats?.daily || [] ) {
								byDay[ row.stat_date ] = ( byDay[ row.stat_date ] || 0 ) + Number( row.conversations || 0 );
							}
							return Object.keys( byDay ).sort().map( ( d ) => byDay[ d ] );
						} )() }
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Suggestions', 'agentyllo' ) }</h2>
				</CardHeader>
				<CardBody>
					{ ! dashboard.unanswered?.length ? (
						<p className="agy-muted">{ __( 'No open gaps — the assistant answered everything it was asked.', 'agentyllo' ) }</p>
					) : (
						<ul className="agy-suggestions">
							{ dashboard.unanswered.map( ( row: any ) => (
								<li key={ row.id }>
									<strong>×{ row.hits }</strong> { row.question_sample }
								</li>
							) ) }
						</ul>
					) }
					<p className="agy-muted">{ __( 'Visitors asked these and the knowledge base had no answer. Add the missing content and mark them resolved in Statistics.', 'agentyllo' ) }</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2 className="agy-card-title">{ __( 'Compliance', 'agentyllo' ) }</h2>
					<Button variant="link" href="admin.php?page=agentyllo-privacy">{ __( 'Open', 'agentyllo' ) }</Button>
				</CardHeader>
				<CardBody>
					<table className="agy-probe-table">
						<tbody>
							<tr><th scope="row">{ __( 'Registration gate', 'agentyllo' ) }</th><td>{ 'off' === dashboard.compliance?.gate ? __( 'off', 'agentyllo' ) : __( 'on', 'agentyllo' ) }</td></tr>
							<tr><th scope="row">{ __( 'Retention', 'agentyllo' ) }</th><td>{ 0 === dashboard.compliance?.retention_days ? <span className="agy-badge agy-badge--warn">{ __( 'forever', 'agentyllo' ) }</span> : sprintf( /* translators: %d: days */ __( '%d days', 'agentyllo' ), dashboard.compliance?.retention_days ) }</td></tr>
							<tr><th scope="row">{ __( 'AI disclosure', 'agentyllo' ) }</th><td>{ dashboard.compliance?.ai_disclosure ? '✓' : '✗' }</td></tr>
							<tr><th scope="row">{ __( 'Transparency page', 'agentyllo' ) }</th><td>{ 'publish' === dashboard.compliance?.transparency_page ? <span className="agy-badge agy-badge--ok">{ __( 'published', 'agentyllo' ) }</span> : dashboard.compliance?.transparency_page || __( 'none', 'agentyllo' ) }</td></tr>
						</tbody>
					</table>
				</CardBody>
			</Card>
		</div>
		</>
	);
}
