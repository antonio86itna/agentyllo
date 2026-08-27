/**
 * Addons page: catalog of optional Agentyllo extensions. The free plugin is
 * complete on its own — every addon is a separate plugin that only ADDS
 * capabilities on top.
 */
import { api } from '../api';
import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Loading from '../components/Loading';

type Addon = {
	id: string;
	name: string;
	tagline: string;
	features: string[];
	status: 'coming_soon' | 'available' | 'installed' | 'active';
	url: string;
};

const STATUS_LABELS: Record< string, string > = {
	coming_soon: __( 'Coming soon', 'agentyllo' ),
	available: __( 'Available', 'agentyllo' ),
	installed: __( 'Installed', 'agentyllo' ),
	active: __( 'Active', 'agentyllo' ),
};

export default function Addons() {
	const [ addons, setAddons ] = useState< Addon[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let live = true;
		api( { path: '/addons' } )
			.then( ( res: any ) => live && setAddons( Array.isArray( res.addons ) ? res.addons : [] ) )
			.catch( () => live && setError( __( 'Failed to load the addon catalog.', 'agentyllo' ) ) );
		return () => {
			live = false;
		};
	}, [] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( null === addons ) {
		return <Loading />;
	}

	return (
		<div className="agy-addons">
			<p className="agy-muted agy-addons__intro">
				{ __( 'Agentyllo is complete as it is — nothing on this site is locked. Addons are separate, optional plugins that add extra capabilities on top; each one requires Agentyllo to be installed and active.', 'agentyllo' ) }
			</p>
			<div className="agy-admin__grid">
				{ addons.map( ( addon ) => (
					<Card key={ addon.id } className="agy-addon-card">
						<CardHeader>
							<h2 className="agy-card-title">{ addon.name }</h2>
							<span className={ 'agy-badge ' + ( 'coming_soon' === addon.status ? 'agy-badge--muted' : 'agy-badge--ok' ) }>
								{ STATUS_LABELS[ addon.status ] || addon.status }
							</span>
						</CardHeader>
						<CardBody>
							<p className="agy-addon-card__tagline">{ addon.tagline }</p>
							<ul className="agy-suggestions">
								{ addon.features.map( ( feature, i ) => (
									<li key={ i }>{ feature }</li>
								) ) }
							</ul>
							{ 'available' === addon.status && addon.url && (
								<Button variant="primary" href={ addon.url }>
									{ __( 'Get this addon', 'agentyllo' ) }
								</Button>
							) }
							{ 'coming_soon' === addon.status && (
								<p className="agy-muted">{ __( 'In development — it will appear here when ready.', 'agentyllo' ) }</p>
							) }
						</CardBody>
					</Card>
				) ) }
			</div>
		</div>
	);
}
