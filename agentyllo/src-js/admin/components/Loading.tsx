/**
 * Accessible loading indicator: Spinner is presentation-only, so screen
 * readers need an explicit polite status region.
 */
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Loading() {
	return (
		<div className="agy-admin__loading" role="status" aria-live="polite">
			<Spinner />
			<span className="screen-reader-text">{ __( 'Loading…', 'agentyllo' ) }</span>
		</div>
	);
}
