/**
 * Agents page: roster with health, quarantine controls, memory inspector,
 * journal tail.
 */
import { api as apiFetch } from '../api';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Loading from '../components/Loading';

type AgentRow = {
	id: string;
	version: string;
	capabilities: string[];
	enabled: boolean;
	quarantine: { reason: string; at: number } | null;
	health: { healthy: boolean } | null;
	lessons: number;
};

function MemoryInspector( { agentId }: { agentId: string } ) {
	const [ data, setData ] = useState< Record< string, any > | null >( null );

	useEffect( () => {
		Promise.all(
			[ 'fact', 'state', 'lesson' ].map( ( kind ) =>
				apiFetch( { path: `/agents/${ agentId }/memory?kind=${ kind }` } ).then(
					( r: any ) => [ kind, r.memories ] as const
				)
			)
		)
			.then( ( entries ) => setData( Object.fromEntries( entries ) ) )
			.catch( () => setData( {} ) );
	}, [ agentId ] );

	if ( ! data ) {
		return <Spinner />;
	}

	const empty = Object.values( data ).every(
		( memories ) => ! memories || 0 === Object.keys( memories ).length
	);
	if ( empty ) {
		return <p className="agy-muted">{ __( 'No memories yet.', 'agentyllo' ) }</p>;
	}

	return (
		<div className="agy-memory-inspector">
			{ Object.entries( data ).map( ( [ kind, memories ] ) =>
				memories && Object.keys( memories ).length ? (
					<details key={ kind } open={ 'lesson' === kind }>
						<summary>
							{ kind } ({ Object.keys( memories ).length })
						</summary>
						<pre>{ JSON.stringify( memories, null, 2 ) }</pre>
					</details>
				) : null
			) }
		</div>
	);
}

export default function Agents() {
	const [ roster, setRoster ] = useState< AgentRow[] | null >( null );
	const [ journal, setJournal ] = useState< any[] >( [] );
	const [ inspecting, setInspecting ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	const load = useCallback( async () => {
		try {
			const [ agents, tail ] = await Promise.all( [
				apiFetch< any >( { path: '/agents' } ),
				apiFetch< any >( { path: '/agents/journal?limit=50' } ),
			] );
			setRoster( agents.agents );
			setJournal( tail.entries );
		} catch ( e: any ) {
			setError( e?.message || __( 'Failed to load agents.', 'agentyllo' ) );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const toggle = async ( id: string, enabled: boolean ) => {
		const res: any = await apiFetch( {
			path: `/agents/${ id }/toggle`,
			method: 'POST',
			data: { enabled },
		} );
		setRoster( res.agents );
	};

	const release = async ( id: string ) => {
		const res: any = await apiFetch( { path: `/agents/${ id }/release`, method: 'POST' } );
		setRoster( res.agents );
	};

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
	if ( ! roster ) {
		return <Loading />;
	}

	return (
		<div className="agy-agents">
			<Card>
				<CardHeader><h2 className="agy-card-title">{ __( "Agent roster", "agentyllo" ) }</h2></CardHeader>
				<CardBody>
					<table className="agy-probe-table">
						<thead>
							<tr>
								<th>{ __( 'Agent', 'agentyllo' ) }</th>
								<th>{ __( 'Status', 'agentyllo' ) }</th>
								<th>{ __( 'Health', 'agentyllo' ) }</th>
								<th>{ __( 'Lessons', 'agentyllo' ) }</th>
								<th>{ __( 'Enabled', 'agentyllo' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ roster.map( ( agent ) => (
								<tr key={ agent.id }>
									<td>
										<strong>{ agent.id }</strong>
										<div className="agy-muted">
											v{ agent.version } · { agent.capabilities.join( ', ' ) }
										</div>
									</td>
									<td>
										{ agent.quarantine ? (
											<span
												className="agy-badge agy-badge--error"
												title={ agent.quarantine.reason }
											>
												{ __( 'Quarantined', 'agentyllo' ) }
											</span>
										) : agent.enabled ? (
											<span className="agy-badge agy-badge--ok">
												{ __( 'Active', 'agentyllo' ) }
											</span>
										) : (
											<span className="agy-badge">
												{ __( 'Disabled', 'agentyllo' ) }
											</span>
										) }
									</td>
									<td>
										{ null == agent.health
											? '—'
											: agent.health.healthy
											? '✓'
											: '✗' }
									</td>
									<td>{ agent.lessons }</td>
									<td>
										<ToggleControl
											__nextHasNoMarginBottom
											label=""
											checked={ agent.enabled }
											onChange={ ( v: boolean ) => toggle( agent.id, v ) }
										/>
									</td>
									<td>
										<Button
											variant="link"
											onClick={ () =>
												setInspecting(
													inspecting === agent.id ? null : agent.id
												)
											}
										>
											{ inspecting === agent.id
												? __( 'Hide memory', 'agentyllo' )
												: __( 'Memory', 'agentyllo' ) }
										</Button>
										{ agent.quarantine && (
											<Button
												variant="secondary"
												onClick={ () => release( agent.id ) }
											>
												{ __( 'Release', 'agentyllo' ) }
											</Button>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ inspecting && (
						<div className="agy-inspector-panel">
							<h3>
								{ sprintf(
									/* translators: %s: agent id */
									__( 'Memory — %s', 'agentyllo' ),
									inspecting
								) }
							</h3>
							<MemoryInspector agentId={ inspecting } />
						</div>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader><h2 className="agy-card-title">{ __( "Recent journal", "agentyllo" ) }</h2></CardHeader>
				<CardBody>
					{ 0 === journal.length ? (
						<p className="agy-muted">{ __( 'No journal entries yet.', 'agentyllo' ) }</p>
					) : (
						<table className="agy-probe-table">
							<tbody>
								{ journal.map( ( entry, i ) => (
									<tr key={ i }>
										<td className="agy-muted">{ entry.created_at }</td>
										<td>
											<strong>{ entry.agent_id }</strong>
										</td>
										<td>
											<span
												className={
													'agy-badge' +
													( 'error' === entry.level
														? ' agy-badge--error'
														: 'warn' === entry.level
														? ' agy-badge--warn'
														: '' )
												}
											>
												{ entry.event }
											</span>
										</td>
										<td>
											{ entry.message }
											{ entry.occurrences > 1 && (
												<span className="agy-muted">
													{ ' ' }
													×{ entry.occurrences }
												</span>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
