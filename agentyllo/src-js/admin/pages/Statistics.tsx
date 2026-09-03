/**
 * Statistics page: KPI tiles, daily trend, per-tier latency, top intents,
 * unanswered questions queue (→ KB suggestions).
 */
import { api as apiFetch } from '../api';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Loading from '../components/Loading';
import Sparkline from '../components/Sparkline';

const pct = ( v: number | null | undefined ): string =>
	null === v || undefined === v ? '—' : Math.round( v * 100 ) + '%';

const ms = ( v: number | null | undefined ): string =>
	null === v || undefined === v ? '—' : v < 1000 ? `${ v } ms` : `${ ( v / 1000 ).toFixed( 1 ) } s`;

export default function Statistics() {
	const [ days, setDays ] = useState( 30 );
	const [ data, setData ] = useState< any >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		let active = true;
		setError( null );
		apiFetch( { path: `/stats/overview?days=${ days }` } )
			.then( ( res ) => active && setData( res ) )
			.catch( ( e: any ) => active && setError( e?.message || __( 'Failed to load statistics.', 'agentyllo' ) ) );
		return () => {
			active = false;
		};
	}, [ days ] );

	useEffect( () => load(), [ load ] );

	const rollup = async () => {
		setBusy( true );
		try {
			await apiFetch( { path: '/stats/rollup', method: 'POST' } );
			load();
		} catch ( e: any ) {
			setError( e?.message || __( 'Rollup failed.', 'agentyllo' ) );
		} finally {
			setBusy( false );
		}
	};

	const setUnanswered = async ( id: number, status: string ) => {
		try {
			const res: any = await apiFetch( { path: `/stats/unanswered/${ id }`, method: 'POST', data: { status } } );
			setData( ( prev: any ) => ( prev ? { ...prev, unanswered: res.unanswered } : prev ) );
		} catch ( e: any ) {
			setError( e?.message || __( 'Could not update the item.', 'agentyllo' ) );
		}
	};

	if ( error && ! data ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }{ ' ' }
				<Button variant="link" onClick={ () => load() }>{ __( 'Retry', 'agentyllo' ) }</Button>
			</Notice>
		);
	}
	if ( ! data ) {
		return <Loading />;
	}

	const t = data.totals || {};
	const daily: any[] = data.daily || [];
	const series = ( key: string ) => {
		const byDay: Record< string, number > = {};
		for ( const row of daily ) {
			byDay[ row.stat_date ] = ( byDay[ row.stat_date ] || 0 ) + Number( row[ key ] || 0 );
		}
		return Object.keys( byDay ).sort().map( ( d ) => byDay[ d ] );
	};

	const tiles: Array< [ string, string, number[] | null ] > = [
		[ __( 'Conversations', 'agentyllo' ), String( t.conversations ?? 0 ), series( 'conversations' ) ],
		[ __( 'Messages', 'agentyllo' ), String( t.messages ?? 0 ), series( 'messages' ) ],
		[ __( 'Deflection rate', 'agentyllo' ), pct( t.deflection_rate ), null ],
		[ __( 'KB coverage', 'agentyllo' ), pct( t.kb_coverage ), null ],
		[ __( 'Fallback replies', 'agentyllo' ), String( t.unanswered ?? 0 ), series( 'unanswered' ) ],
		[ __( 'Open knowledge gaps', 'agentyllo' ), String( ( data.unanswered || [] ).length ), null ],
		[ __( 'Out-of-scope refusals', 'agentyllo' ), String( t.oos_refusals ?? 0 ), series( 'oos_refusals' ) ],
		[ __( 'Handoffs', 'agentyllo' ), String( t.handoffs ?? 0 ), null ],
		[ __( 'Avg latency', 'agentyllo' ), ms( t.avg_latency_ms ), null ],
	];

	return (
		<div className="agy-agents">
			{ error && <Notice status="error" isDismissible onRemove={ () => setError( null ) }>{ error }</Notice> }
			<div style={ { display: 'flex', gap: 12, alignItems: 'flex-end', justifyContent: 'space-between' } }>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Range', 'agentyllo' ) }
					value={ String( days ) }
					options={ [
						{ value: '7', label: __( 'Last 7 days', 'agentyllo' ) },
						{ value: '30', label: __( 'Last 30 days', 'agentyllo' ) },
						{ value: '90', label: __( 'Last 90 days', 'agentyllo' ) },
					] }
					onChange={ ( v: string ) => setDays( parseInt( v, 10 ) ) }
				/>
				<Button variant="secondary" onClick={ rollup } isBusy={ busy } disabled={ busy }>
					{ __( 'Refresh rollups', 'agentyllo' ) }
				</Button>
			</div>

			<div className="agy-admin__grid">
				{ tiles.map( ( [ label, value, spark ] ) => (
					<Card key={ label }>
						<CardBody>
							<div className="agy-muted">{ label }</div>
							<div className="agy-kpi">{ value }</div>
							{ spark && spark.length > 1 && <Sparkline values={ spark } label={ label } color="#0a2a4e" /> }
						</CardBody>
					</Card>
				) ) }
			</div>

			<Card>
				<CardHeader><h2 className="agy-card-title">{ __( 'Latency per tier', 'agentyllo' ) }</h2></CardHeader>
				<CardBody>
					{ Object.keys( t.by_tier || {} ).length === 0 ? (
						<p className="agy-muted">{ __( 'No data yet.', 'agentyllo' ) }</p>
					) : (
						<table className="agy-probe-table">
							<thead><tr><th>{ __( 'Tier', 'agentyllo' ) }</th><th>{ __( 'Messages', 'agentyllo' ) }</th><th>{ __( 'Avg', 'agentyllo' ) }</th><th>{ __( 'p95', 'agentyllo' ) }</th></tr></thead>
							<tbody>
								{ Object.entries( t.by_tier ).map( ( [ tier, v ]: [ string, any ] ) => (
									<tr key={ tier }><td>{ tier }</td><td>{ v.messages }</td><td>{ ms( v.avg_latency_ms ) }</td><td>{ ms( v.p95_latency_ms ) }</td></tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader><h2 className="agy-card-title">{ __( 'Top intents', 'agentyllo' ) }</h2></CardHeader>
				<CardBody>
					{ ! data.intents?.length ? (
						<p className="agy-muted">{ __( 'No data yet.', 'agentyllo' ) }</p>
					) : (
						<table className="agy-probe-table">
							<thead><tr><th>{ __( 'Intent', 'agentyllo' ) }</th><th>{ __( 'Hits', 'agentyllo' ) }</th><th>{ __( 'Answered', 'agentyllo' ) }</th></tr></thead>
							<tbody>
								{ data.intents.map( ( row: any ) => (
									<tr key={ row.intent }><td>{ row.intent }</td><td>{ row.hits }</td><td>{ pct( row.hits > 0 ? row.answered / row.hits : null ) }</td></tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader><h2 className="agy-card-title">{ __( 'Unanswered questions', 'agentyllo' ) }</h2></CardHeader>
				<CardBody>
					<p className="agy-muted">{ __( 'Questions the assistant could not answer from the knowledge base. Add the missing information (Knowledge Base → Entries, or ask the copilot) and mark them resolved.', 'agentyllo' ) }</p>
					{ ! data.unanswered?.length ? (
						<p className="agy-muted">{ __( 'Nothing open — great!', 'agentyllo' ) }</p>
					) : (
						<table className="agy-probe-table">
							<thead><tr><th>{ __( 'Question', 'agentyllo' ) }</th><th>{ __( 'Hits', 'agentyllo' ) }</th><th>{ __( 'Last seen', 'agentyllo' ) }</th><th></th></tr></thead>
							<tbody>
								{ data.unanswered.map( ( row: any ) => (
									<tr key={ row.id }>
										<td>{ row.question_sample }<div className="agy-muted">{ row.lang } · { row.intent || '—' }</div></td>
										<td>{ row.hits }</td>
										<td className="agy-muted">{ row.last_seen }</td>
										<td>
											<Button
												variant="link"
												onClick={ () =>
													document.dispatchEvent(
														new CustomEvent( 'agyl:copilot-prefill', {
															detail: { text: `/kb add title:"${ String( row.question_sample ).replace( /"/g, "'" ).slice( 0, 120 ) }" type:faq content:"Q: ${ String( row.question_sample ).replace( /"/g, "'" ) }
A: "` },
														} )
													)
												}
											>
												{ __( 'Add to KB via copilot', 'agentyllo' ) }
											</Button>
											{ ' ' }
											<Button variant="link" onClick={ () => setUnanswered( row.id, 'resolved' ) }>{ __( 'Resolved', 'agentyllo' ) }</Button>
											{ ' ' }
											<Button variant="link" isDestructive onClick={ () => setUnanswered( row.id, 'dismissed' ) }>{ __( 'Dismiss', 'agentyllo' ) }</Button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>
			<p className="agy-muted">{ sprintf( /* translators: %d: days */ __( 'Rollups are PII-free daily counters kept for 24 months; raw transcripts follow the retention setting. Showing %d days.', 'agentyllo' ), data.days ) }</p>
		</div>
	);
}
