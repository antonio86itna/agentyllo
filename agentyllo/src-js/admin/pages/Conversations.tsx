/**
 * Conversations: paginated list + transcript viewer.
 */
import { api as apiFetch } from '../api';
import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Loading from '../components/Loading';

const PER_PAGE = 20;

function Transcript( { id, onBack }: { id: number; onBack: () => void } ) {
	const [ conv, setConv ] = useState< any >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let active = true;
		apiFetch( { path: `/conversations/${ id }` } )
			.then( ( res ) => active && setConv( res ) )
			.catch( ( e: any ) => active && setError( e?.message || __( 'Failed to load the transcript.', 'agentyllo' ) ) );
		return () => {
			active = false;
		};
	}, [ id ] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! conv ) {
		return <Loading />;
	}

	return (
		<Card>
			<CardHeader>
				<h2 className="agy-card-title">
					{ sprintf( /* translators: %s: conversation uuid */ __( 'Conversation %s', 'agentyllo' ), conv.uuid.slice( 0, 8 ) ) }
				</h2>
				<Button variant="secondary" onClick={ onBack }>{ __( 'Back to list', 'agentyllo' ) }</Button>
			</CardHeader>
			<CardBody>
				<p className="agy-muted">
					{ conv.visitor_name || conv.visitor_email ? `${ conv.visitor_name || '' } ${ conv.visitor_email ? '<' + conv.visitor_email + '>' : '' } · ` : '' }
					{ conv.lang } · { conv.tier } · { conv.started_at }
				</p>
				<div className="agy-transcript">
					{ conv.messages.map( ( m: any ) => (
						<div key={ m.id } className={ 'agy-transcript__msg agy-transcript__msg--' + m.role }>
							<div className="agy-transcript__meta agy-muted">
								{ m.role } · { m.created_at }
								{ m.intent ? ` · ${ m.intent }` : '' }
								{ m.latency_ms ? ` · ${ m.latency_ms } ms` : '' }
								{ Number( m.flagged_unanswered ) ? ` · ${ __( 'unanswered', 'agentyllo' ) }` : '' }
							</div>
							<div className="agy-transcript__content">{ m.content }</div>
						</div>
					) ) }
				</div>
			</CardBody>
		</Card>
	);
}

export default function Conversations() {
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState< any >( null );
	const [ open, setOpen ] = useState< number | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let active = true;
		apiFetch( { path: `/conversations?page=${ page }&per_page=${ PER_PAGE }` } )
			.then( ( res ) => active && setData( res ) )
			.catch( ( e: any ) => active && setError( e?.message || __( 'Failed to load conversations.', 'agentyllo' ) ) );
		return () => {
			active = false;
		};
	}, [ page ] );

	if ( null !== open ) {
		return <Transcript id={ open } onBack={ () => setOpen( null ) } />;
	}
	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Loading />;
	}

	const totalPages = Math.max( 1, Math.ceil( data.total / PER_PAGE ) );

	return (
		<Card>
			<CardHeader><h2 className="agy-card-title">{ __( 'Conversations', 'agentyllo' ) }</h2></CardHeader>
			<CardBody>
				{ 0 === data.items.length ? (
					<p className="agy-muted">{ __( 'No conversations yet.', 'agentyllo' ) }</p>
				) : (
					<table className="agy-probe-table">
						<thead>
							<tr>
								<th>{ __( 'Started', 'agentyllo' ) }</th>
								<th>{ __( 'Visitor', 'agentyllo' ) }</th>
								<th>{ __( 'Lang', 'agentyllo' ) }</th>
								<th>{ __( 'Tier', 'agentyllo' ) }</th>
								<th>{ __( 'Messages', 'agentyllo' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( c: any ) => (
								<tr key={ c.id }>
									<td>{ c.started_at }</td>
									<td>{ c.visitor_name || c.visitor_email || <span className="agy-muted">{ __( 'anonymous', 'agentyllo' ) }</span> }</td>
									<td>{ c.lang }</td>
									<td>{ c.tier }</td>
									<td>{ c.message_count }</td>
									<td><Button variant="link" onClick={ () => setOpen( c.id ) }>{ __( 'View', 'agentyllo' ) }</Button></td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 16 } }>
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>{ __( 'Previous', 'agentyllo' ) }</Button>
					<span className="agy-muted">{ sprintf( /* translators: 1: page, 2: total pages, 3: total rows */ __( 'Page %1$d of %2$d (%3$d conversations)', 'agentyllo' ), page, totalPages, data.total ) }</span>
					<Button variant="secondary" disabled={ page >= totalPages } onClick={ () => setPage( ( p ) => p + 1 ) }>{ __( 'Next', 'agentyllo' ) }</Button>
				</div>
			</CardBody>
		</Card>
	);
}
