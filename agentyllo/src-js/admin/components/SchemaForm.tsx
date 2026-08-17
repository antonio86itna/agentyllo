/**
 * Generic settings form renderer driven by the server-provided field schema.
 */
import {
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

type Field = {
	type: 'string' | 'text' | 'bool' | 'int' | 'float' | 'enum' | 'secret';
	values?: string[];
	default?: unknown;
	min?: number;
	max?: number;
	maxlen?: number;
	label?: string;
};

const FIELD_LABELS: Record< string, string > = {
	operating_mode: __( 'Operating mode', 'agentyllo' ),
	assistant_name: __( 'Assistant name', 'agentyllo' ),
	site_type_hint: __( 'Site type', 'agentyllo' ),
	tone: __( 'Tone', 'agentyllo' ),
	custom_instructions: __( 'Custom instructions', 'agentyllo' ),
	out_of_scope_guard: __( 'Out-of-scope guard', 'agentyllo' ),
	oos_refusal_message: __( 'Custom refusal message', 'agentyllo' ),
	uninstall_mode: __( 'On uninstall', 'agentyllo' ),
	debug_log: __( 'Debug log', 'agentyllo' ),
	// Widget
	widget_enabled: __( 'Show the chat widget', 'agentyllo' ),
	position: __( 'Position', 'agentyllo' ),
	theme: __( 'Theme', 'agentyllo' ),
	primary_color: __( 'Primary color (hex)', 'agentyllo' ),
	welcome_message: __( 'Welcome message', 'agentyllo' ),
	launcher_teaser: __( 'Launcher teaser', 'agentyllo' ),
	show_thumbnails: __( 'Show thumbnails in answers', 'agentyllo' ),
	show_internal_links: __( 'Show related links', 'agentyllo' ),
	animations: __( 'Animations', 'agentyllo' ),
	z_index: __( 'Z-index', 'agentyllo' ),
	// Language
	reply_language_mode: __( 'Reply language', 'agentyllo' ),
	fixed_locale: __( 'Fixed locale', 'agentyllo' ),
	// Privacy
	registration_gate: __( 'Registration before chat', 'agentyllo' ),
	privacy_checkbox_required: __( 'Require privacy checkbox', 'agentyllo' ),
	privacy_policy_url: __( 'Privacy policy URL', 'agentyllo' ),
	gate_intro_text: __( 'Gate intro text', 'agentyllo' ),
	privacy_checkbox_label: __( 'Privacy checkbox label', 'agentyllo' ),
	legal_disclaimer_text: __( 'Footer disclaimer text', 'agentyllo' ),
	transparency_text: __( 'Additional transparency text', 'agentyllo' ),
	policy_version: __( 'Policy version', 'agentyllo' ),
	retention_days: __( 'Retention (days, 0 = forever)', 'agentyllo' ),
	ip_mode: __( 'IP address handling', 'agentyllo' ),
	consent_logging: __( 'Log consents', 'agentyllo' ),
	pii_redaction: __( 'PII redaction', 'agentyllo' ),
	ai_disclosure: __( 'AI disclosure in widget', 'agentyllo' ),
	// Performance
	transport: __( 'Answer transport', 'agentyllo' ),
	rate_limit_session_per_min: __( 'Messages per minute (per session)', 'agentyllo' ),
	rate_limit_ip_per_hour: __( 'Messages per hour (per IP)', 'agentyllo' ),
	rate_limit_ip_per_day: __( 'Messages per day (per IP)', 'agentyllo' ),
};

const FIELD_HELP: Record< string, string > = {
	operating_mode: __(
		'AI modes become selectable once a local engine or an API key is configured.',
		'agentyllo'
	),
	assistant_name: __( 'Shown in the chat header. Empty = your site name.', 'agentyllo' ),
	custom_instructions: __(
		'Site-owner guidance for the assistant. It can never disable safety or transparency.',
		'agentyllo'
	),
	out_of_scope_guard: __(
		'Keeps the assistant focused on your site. Disabling this is discouraged.',
		'agentyllo'
	),
	uninstall_mode: __(
		'"Remove everything" drops all Agentyllo tables, options, and uploaded files.',
		'agentyllo'
	),
	reply_language_mode: __(
		'"Visitor language" needs an AI model; classic agents always answer in the site language.',
		'agentyllo'
	),
	registration_gate: __( 'Ask for name and email before the first message.', 'agentyllo' ),
	privacy_policy_url: __( 'Empty = the WordPress privacy page.', 'agentyllo' ),
	policy_version: __( 'Bump this when your privacy text changes; consents record the version shown.', 'agentyllo' ),
	retention_days: __( 'Conversations older than this are deleted daily. 0 keeps them forever (not recommended).', 'agentyllo' ),
	ip_mode: __( '"hash" stores a salted one-way hash (rotated monthly); "none" stores nothing.', 'agentyllo' ),
	pii_redaction: __( '"logs" masks emails/phones/IBAN/cards in stored transcripts; "before_ai" also masks before any external AI call.', 'agentyllo' ),
	ai_disclosure: __( 'Locked ON whenever an AI mode is active (EU AI Act Art. 50).', 'agentyllo' ),
	legal_disclaimer_text: __( 'Empty = default "AI responses may contain mistakes" line.', 'agentyllo' ),
	transport: __( '"auto" streams status and AI text live (SSE); "buffered" sends complete answers only — use it if a proxy or firewall breaks streaming.', 'agentyllo' ),
};

/**
 * Server-masked secret preview ("sk-••••••••7890", "••••••••", "!corrupt") —
 * shown as a placeholder, never echoed back as a value.
 */
const isMasked = ( value: unknown ): boolean =>
	'string' === typeof value && ( value.indexOf( '•' ) >= 0 || '!corrupt' === value );

const humanize = ( key: string ): string =>
	key.replace( /_/g, ' ' ).replace( /^\w/, ( c ) => c.toUpperCase() );

/**
 * Int input that tolerates intermediate states ("", "-") while typing:
 * the raw string lives locally and a parsed, clamped value is committed on
 * blur (or on change when already a valid number).
 */
function IntControl( {
	label,
	help,
	field,
	value,
	onCommit,
}: {
	label: string;
	help?: string;
	field: Field;
	value: unknown;
	onCommit: ( value: number ) => void;
} ) {
	const [ raw, setRaw ] = useState< string | null >( null );

	const clamp = ( n: number ): number =>
		Math.min( field.max ?? Infinity, Math.max( field.min ?? -Infinity, n ) );

	const parse = ( text: string ): number =>
		'float' === field.type ? parseFloat( text ) : parseInt( text, 10 );

	const commit = ( text: string ) => {
		const n = parse( text );
		onCommit( clamp( Number.isNaN( n ) ? Number( field.default ?? 0 ) : n ) );
		setRaw( null );
	};

	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type="number"
			label={ label }
			help={ help }
			min={ field.min }
			max={ field.max }
			value={ raw ?? String( value ?? '' ) }
			step={ 'float' === field.type ? 'any' : 1 }
			onChange={ ( v: string ) => {
				setRaw( v );
				const n = parse( v );
				if ( ! Number.isNaN( n ) && String( n ) === v.trim() ) {
					onCommit( clamp( n ) );
				}
			} }
			onBlur={ () => {
				if ( null !== raw ) {
					commit( raw );
				}
			} }
		/>
	);
}

export default function SchemaForm( {
	schema,
	values,
	onChange,
}: {
	schema: Record< string, Field >;
	values: Record< string, any >;
	onChange: ( key: string, value: unknown ) => void;
} ) {
	return (
		<div className="agy-schema-form">
			{ Object.entries( schema ).map( ( [ key, field ] ) => {
				const label = field.label || FIELD_LABELS[ key ] || humanize( key );
				const help = FIELD_HELP[ key ];
				const value = values[ key ];

				switch ( field.type ) {
					case 'bool':
						return (
							<ToggleControl
								key={ key }
								__nextHasNoMarginBottom
								label={ label }
								help={ help }
								checked={ !! value }
								onChange={ ( v: boolean ) => onChange( key, v ) }
							/>
						);
					case 'enum':
						return (
							<SelectControl
								key={ key }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ label }
								help={ help }
								value={ String( value ?? field.default ?? '' ) }
								options={ ( field.values || [] ).map( ( v ) => ( {
									value: v,
									label: humanize( v ),
								} ) ) }
								onChange={ ( v: string ) => onChange( key, v ) }
							/>
						);
					case 'int':
					case 'float':
						return (
							<IntControl
								key={ key }
								label={ label }
								help={ help }
								field={ field }
								value={ value }
								onCommit={ ( v: number ) => onChange( key, v ) }
							/>
						);
					case 'secret':
						return (
							<TextControl
								key={ key }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								type="password"
								autoComplete="new-password"
								label={ label }
								help={ help || __( 'Stored encrypted. Leave empty to keep the saved value; type __clear__ to remove it.', 'agentyllo' ) }
								placeholder={ isMasked( value ) ? String( value ) : '' }
								value={ isMasked( value ) ? '' : String( value ?? '' ) }
								onChange={ ( v: string ) => onChange( key, v ) }
							/>
						);
					case 'text':
						return (
							<TextareaControl
								key={ key }
								__nextHasNoMarginBottom
								label={ label }
								help={ help }
								rows={ 4 }
								maxLength={ field.maxlen }
								value={ String( value ?? '' ) }
								onChange={ ( v: string ) => onChange( key, v ) }
							/>
						);
					default:
						return (
							<TextControl
								key={ key }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ label }
								help={ help }
								maxLength={ field.maxlen }
								value={ String( value ?? '' ) }
								onChange={ ( v: string ) => onChange( key, v ) }
							/>
						);
				}
			} ) }
		</div>
	);
}
