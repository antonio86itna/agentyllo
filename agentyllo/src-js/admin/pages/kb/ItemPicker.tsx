/**
 * Per-item include/exclude picker for one KB source.
 *
 * Checked = the item is included (its KB state is not 'excluded'). Toggles
 * apply optimistically and revert on error.
 */
import { api as apiFetch } from '../../api';
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SearchControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Loading from '../../components/Loading';

const PER_PAGE = 20;

type Item = {
	external_id: string;
	subtype: string;
	title: string;
	permalink: string;
	state: 'indexed' | 'excluded' | 'not_indexed';
};

export default function ItemPicker( {
	source,
	subtype,
	title,
	onClose,
}: {
	source: string;
	subtype: string;
	title: string;
	onClose: () => void;
} ) {
	const [ items, setItems ] = useState< Item[] | null >( null );
	const [ total, setTotal ] = useState( 0 );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ error, setError ] = useState< string | null >( null );
	// Per-item in-flight lock: a row cannot be double-toggled mid-request.
	const [ busy, setBusy ] = useState< Set< string > >( new Set() );
	// Bumped by Retry to re-run the fetch effect.
	const [ reloadTick, setReloadTick ] = useState( 0 );

	// Debounced (300ms) + stale-guarded: typing fires one fetch per pause,
	// and an out-of-order response never overwrites a newer one.
	useEffect( () => {
		let active = true;
		const timer = window.setTimeout( async () => {
			setError( null );
			try {
				const res: any = await apiFetch( {
					path: `/kb/items?source=${ encodeURIComponent( source ) }&subtype=${ encodeURIComponent(
						subtype
					) }&search=${ encodeURIComponent( search ) }&page=${ page }&per_page=${ PER_PAGE }`,
				} );
				if ( ! active ) {
					return;
				}
				setItems( res.items );
				setTotal( res.total );
			} catch ( e: any ) {
				if ( active ) {
					setError( e?.message || __( 'Failed to load items.', 'agentyllo' ) );
				}
			}
		}, 300 );

		return () => {
			active = false;
			window.clearTimeout( timer );
		};
	}, [ source, subtype, search, page, reloadTick ] );

	const toggle = async ( item: Item, include: boolean ) => {
		if ( busy.has( item.external_id ) ) {
			return;
		}
		const previous = item.state;
		setBusy( ( prev ) => new Set( prev ).add( item.external_id ) );
		setItems(
			( prev ) =>
				prev &&
				prev.map( ( i ) =>
					i.external_id === item.external_id
						? { ...i, state: include ? 'not_indexed' : 'excluded' }
						: i
				)
		);
		try {
			const res: any = await apiFetch( {
				path: '/kb/items/toggle',
				method: 'POST',
				data: {
					source,
					external_id: item.external_id,
					subtype: item.subtype,
					include,
				},
			} );
			// Prefer the server-confirmed state over the optimistic paint.
			if ( res?.state ) {
				setItems(
					( prev ) =>
						prev &&
						prev.map( ( i ) =>
							i.external_id === item.external_id ? { ...i, state: res.state } : i
						)
				);
			}
		} catch ( e: any ) {
			setItems(
				( prev ) =>
					prev &&
					prev.map( ( i ) =>
						i.external_id === item.external_id ? { ...i, state: previous } : i
					)
			);
			setError( e?.message || __( 'Could not update the item.', 'agentyllo' ) );
		} finally {
			setBusy( ( prev ) => {
				const next = new Set( prev );
				next.delete( item.external_id );
				return next;
			} );
		}
	};

	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: source name (e.g. Posts, Products) */
				__( 'Choose items — %s', 'agentyllo' ),
				title
			) }
			onRequestClose={ onClose }
			style={ { maxWidth: 560, width: '100%' } }
		>
			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }{ ' ' }
					<Button variant="link" onClick={ () => setReloadTick( ( t ) => t + 1 ) }>
						{ __( 'Retry', 'agentyllo' ) }
					</Button>
				</Notice>
			) }
			<SearchControl
				__nextHasNoMarginBottom
				label={ __( 'Search items', 'agentyllo' ) }
				value={ search }
				onChange={ ( value: string ) => {
					setSearch( value );
					setPage( 1 );
				} }
			/>
			{ null === items ? (
				<Loading />
			) : 0 === items.length ? (
				<p className="agy-muted">{ __( 'No items found.', 'agentyllo' ) }</p>
			) : (
				<div style={ { margin: '16px 0', display: 'flex', flexDirection: 'column', gap: 8 } }>
					{ items.map( ( item ) => (
						<CheckboxControl
							key={ item.external_id }
							__nextHasNoMarginBottom
							label={ item.title || item.external_id }
							help={ item.permalink || undefined }
							checked={ 'excluded' !== item.state }
							disabled={ busy.has( item.external_id ) }
							onChange={ ( checked: boolean ) => toggle( item, checked ) }
						/>
					) ) }
				</div>
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
						/* translators: 1: current page, 2: total pages */
						__( 'Page %1$d of %2$d', 'agentyllo' ),
						page,
						totalPages
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
		</Modal>
	);
}
