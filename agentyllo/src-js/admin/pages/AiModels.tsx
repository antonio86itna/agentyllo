/**
 * AI Models page: providers + keys, model choice from the signed registry,
 * budget/cost cap, live provider health, registry sync.
 *
 * Keys are write-only: the server returns a masked preview and never the
 * key. Saving with an empty key field keeps the stored key; "Remove key"
 * sends the __clear__ sentinel.
 */
import { api as apiFetch } from '../api';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Loading from '../components/Loading';

type ModelDef = {
	id: string;
	label?: string;
	hint?: string;
	default?: boolean;
	price_in?: number;
	price_out?: number;
	context?: number;
	quality?: string;
};

type ProviderInfo = {
	label: string;
	keys_url: string;
	key_prefix: string;
	key_masked: string;
	has_key: boolean;
	key_source?: string;
	key_corrupt: boolean;
	available: boolean;
	chat_models: ModelDef[];
	embedding_models: ModelDef[];
	default_model: string;
	circuit: { open: boolean; fails: number; open_until: number; last_error: string };
	stats: { calls: number; errors: number; avg_latency_ms: number; avg_tok_per_s: number; cost_usd: number } | null;
};

type Overview = {
	status: { mode: string; ai_enabled: boolean; active: string | null; reason: string; cap_reached: boolean };
	settings: Record< string, any >;
	providers: Record< string, ProviderInfo >;
	usage: { month: string; cost_usd: number; tokens_in: number; tokens_out: number; calls: number; errors: number; cap_usd: number };
	recent: Array< Record< string, any > >;
	registry: {
		origin: string;
		sequence: number;
		generated_at: string;
		synced_at: number;
		last_sync: { ok: boolean; message: string; sequence: number; at: number };
		url: string;
	};
	transport: { streaming_capable: boolean; curl: boolean };
	local: {
		url: string;
		model: string;
		available: boolean;
		has_key: boolean;
		key_masked: string;
		ema_tps: number;
		min_tps: number;
		circuit: { open: boolean; fails: number; open_until: number; last_error: string };
		stats: { calls: number; errors: number; avg_latency_ms: number; avg_tok_per_s: number; cost_usd: number } | null;
	};
	vectors: { provider: string; model_key: string; count: number; remaining: number | null; ran_at: number };
};

const REASONS: Record< string, string > = {
	classic_mode: __( 'Classic mode: no AI model is used. Switch the operating mode in Settings → General to enable an AI tier.', 'agentyllo' ),
	no_provider: __( 'No chat provider selected below.', 'agentyllo' ),
	no_key: __( 'The selected provider has no valid API key.', 'agentyllo' ),
	circuit_open: __( 'The provider is temporarily paused after repeated failures (circuit breaker).', 'agentyllo' ),
	cap_reached: __( 'The monthly cost cap is reached — the classic agents answer until next month or a higher cap.', 'agentyllo' ),
	no_local_engine: __( 'No local engine is available yet (free AI tiers arrive with the Local AI companion).', 'agentyllo' ),
	unavailable: __( 'The provider is not usable right now.', 'agentyllo' ),
};

function money( n: number ): string {
	return '$' + ( Math.round( n * 100 ) / 100 ).toFixed( 2 );
}

function modelOptions( models: ModelDef[] ): Array< { value: string; label: string } > {
	return [
		{ value: '', label: __( 'Registry default', 'agentyllo' ) },
		...models.map( ( m ) => ( {
			value: m.id,
			label:
				( m.label || m.id ) +
				( 'number' === typeof m.price_in
					? ` — $${ m.price_in }/$${ m.price_out } per 1M tokens`
					: '' ),
		} ) ),
	];
}

function ProviderCard( {
	id,
	info,
	settings,
	onSaved,
}: {
	id: 'openai' | 'anthropic';
	info: ProviderInfo;
	settings: Record< string, any >;
	onSaved: () => void;
} ) {
	const [ key, setKey ] = useState( '' );
	const [ model, setModel ] = useState< string >( String( settings[ id + '_chat_model' ] || '' ) );
	const [ busy, setBusy ] = useState< '' | 'save' | 'test' | 'clear' | 'reset' >( '' );
	const [ notice, setNotice ] = useState< { status: 'success' | 'error' | 'info'; text: string } | null >( null );

	useEffect( () => {
		setModel( String( settings[ id + '_chat_model' ] || '' ) );
	}, [ settings, id ] );

	const save = async ( values: Record< string, any > ) => {
		await apiFetch( { path: '/settings/models', method: 'PUT', data: { values } } );
	};

	const onSave = async () => {
		setBusy( 'save' );
		setNotice( null );
		try {
			const values: Record< string, any > = { [ id + '_chat_model' ]: model };
			if ( key.trim() ) {
				values[ id + '_api_key' ] = key.trim();
			}
			await save( values );
			setKey( '' );
			setNotice( { status: 'success', text: __( 'Saved.', 'agentyllo' ) } );
			onSaved();
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Could not save.', 'agentyllo' ) } );
		} finally {
			setBusy( '' );
		}
	};

	const onClear = async () => {
		if ( ! window.confirm( __( 'Remove the stored API key for this provider?', 'agentyllo' ) ) ) {
			return;
		}
		setBusy( 'clear' );
		try {
			await save( { [ id + '_api_key' ]: '__clear__' } );
			setKey( '' );
			setNotice( { status: 'info', text: __( 'Key removed.', 'agentyllo' ) } );
			onSaved();
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Could not remove the key.', 'agentyllo' ) } );
		} finally {
			setBusy( '' );
		}
	};

	const onTest = async () => {
		setBusy( 'test' );
		setNotice( null );
		try {
			if ( key.trim() ) {
				await save( { [ id + '_api_key' ]: key.trim(), [ id + '_chat_model' ]: model } );
				setKey( '' );
			}
			const res: any = await apiFetch( { path: '/models/test', method: 'POST', data: { provider: id } } );
			setNotice( {
				status: res.ok ? 'success' : 'error',
				text: res.ok ? sprintf( /* translators: 1: message, 2: latency ms */ __( '%1$s (%2$d ms)', 'agentyllo' ), res.message, res.latency_ms ) : res.message,
			} );
			onSaved();
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Test failed.', 'agentyllo' ) } );
		} finally {
			setBusy( '' );
		}
	};

	const onResetCircuit = async () => {
		setBusy( 'reset' );
		try {
			await apiFetch( { path: '/models/circuit-reset', method: 'POST', data: { provider: id } } );
			onSaved();
		} finally {
			setBusy( '' );
		}
	};

	const status = info.key_corrupt
		? __( 'Stored key cannot be decrypted — please enter it again.', 'agentyllo' )
		: info.has_key
			? sprintf( /* translators: %s: masked key */ __( 'Key saved: %s', 'agentyllo' ), info.key_masked )
			: 'environment' === info.key_source
				? __( 'Using the key defined in wp-config / environment (WordPress AI Client convention).', 'agentyllo' )
				: __( 'No key saved.', 'agentyllo' );

	return (
		<Card className="agy-provider-card">
			<CardHeader>
				<strong>{ info.label }</strong>
				<span className={ 'agy-badge ' + ( info.available ? 'agy-badge--ok' : 'agy-badge--muted' ) }>
					{ info.available ? __( 'Ready', 'agentyllo' ) : __( 'Not configured', 'agentyllo' ) }
				</span>
			</CardHeader>
			<CardBody>
				{ notice && (
					<Notice status={ notice.status } isDismissible={ false }>
						{ notice.text }
					</Notice>
				) }
				<p className="agy-muted">
					{ status }{ ' ' }
					{ info.keys_url && <ExternalLink href={ info.keys_url }>{ __( 'Get an API key', 'agentyllo' ) }</ExternalLink> }
				</p>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="password"
					autoComplete="new-password"
					label={ __( 'API key', 'agentyllo' ) }
					placeholder={ info.has_key ? __( 'Leave empty to keep the saved key', 'agentyllo' ) : info.key_prefix + '…' }
					value={ key }
					onChange={ setKey }
					help={ __( 'Stored encrypted with a dedicated key on this site; sent only to this provider.', 'agentyllo' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Chat model', 'agentyllo' ) }
					value={ model }
					options={ modelOptions( info.chat_models ) }
					onChange={ setModel }
					help={
						( info.chat_models.find( ( m ) => m.id === ( model || info.default_model ) ) || {} ).hint ||
						__( 'Model ids and prices come from the signed Agentyllo registry.', 'agentyllo' )
					}
				/>
				<div className="agy-settings-tab__actions">
					<Button variant="primary" isBusy={ 'save' === busy } disabled={ '' !== busy } onClick={ onSave }>
						{ __( 'Save', 'agentyllo' ) }
					</Button>
					<Button variant="secondary" isBusy={ 'test' === busy } disabled={ '' !== busy || ( ! info.has_key && ! key.trim() ) } onClick={ onTest }>
						{ __( 'Test connection', 'agentyllo' ) }
					</Button>
					{ info.has_key && (
						<Button variant="tertiary" isDestructive isBusy={ 'clear' === busy } disabled={ '' !== busy } onClick={ onClear }>
							{ __( 'Remove key', 'agentyllo' ) }
						</Button>
					) }
				</div>
				{ info.circuit.open && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: last error */
							__( 'Paused after repeated failures (%s).', 'agentyllo' ),
							info.circuit.last_error || '—'
						) }{ ' ' }
						<Button variant="link" isBusy={ 'reset' === busy } onClick={ onResetCircuit }>
							{ __( 'Retry now', 'agentyllo' ) }
						</Button>
					</Notice>
				) }
				{ info.stats && info.stats.calls > 0 && (
					<p className="agy-muted">
						{ sprintf(
							/* translators: 1: calls, 2: errors, 3: latency, 4: tok/s, 5: cost */
							__( 'Last 7 days: %1$d calls, %2$d errors, avg %3$d ms, %4$s tok/s, %5$s', 'agentyllo' ),
							info.stats.calls,
							info.stats.errors,
							info.stats.avg_latency_ms,
							String( info.stats.avg_tok_per_s ),
							money( info.stats.cost_usd )
						) }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

function LocalCard( { local, settings, onSaved }: { local: Overview[ 'local' ]; settings: Record< string, any >; onSaved: () => void } ) {
	const [ url, setUrl ] = useState( String( settings.local_endpoint_url || '' ) );
	const [ model, setModel ] = useState( String( settings.local_model || '' ) );
	const [ key, setKey ] = useState( '' );
	const [ minTps, setMinTps ] = useState( String( settings.local_min_tok_s ?? 8 ) );
	const [ busy, setBusy ] = useState< '' | 'save' | 'test' >( '' );
	const [ notice, setNotice ] = useState< { status: 'success' | 'error' | 'info'; text: string } | null >( null );

	useEffect( () => {
		setUrl( String( settings.local_endpoint_url || '' ) );
		setModel( String( settings.local_model || '' ) );
		setMinTps( String( settings.local_min_tok_s ?? 8 ) );
	}, [ settings ] );

	const persist = async () => {
		const values: Record< string, any > = {
			local_endpoint_url: url.trim(),
			local_model: model.trim(),
			local_min_tok_s: parseInt( minTps, 10 ) || 8,
		};
		if ( key.trim() ) {
			values.local_api_key = key.trim();
		}
		await apiFetch( { path: '/settings/models', method: 'PUT', data: { values } } );
		setKey( '' );
	};

	const onSave = async () => {
		setBusy( 'save' );
		setNotice( null );
		try {
			await persist();
			setNotice( { status: 'success', text: __( 'Saved.', 'agentyllo' ) } );
			onSaved();
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Could not save.', 'agentyllo' ) } );
		} finally {
			setBusy( '' );
		}
	};

	const onTest = async () => {
		setBusy( 'test' );
		setNotice( null );
		try {
			await persist();
			const res: any = await apiFetch( { path: '/models/test', method: 'POST', data: { provider: 'local_endpoint' } } );
			setNotice( { status: res.ok ? 'success' : 'error', text: res.message } );
			onSaved();
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Test failed.', 'agentyllo' ) } );
		} finally {
			setBusy( '' );
		}
	};

	const gate = local.ema_tps > 0
		? sprintf(
			/* translators: 1: measured tok/s, 2: minimum */
			__( 'Measured speed: %1$s tok/s (chat needs ≥ %2$d; slower engines only run bounded tasks such as query rewriting).', 'agentyllo' ),
			String( local.ema_tps ),
			local.min_tps
		)
		: __( 'Speed not measured yet — run a test or send a chat message.', 'agentyllo' );

	return (
		<Card className="agy-provider-card">
			<CardHeader>
				<strong>{ __( 'Local engine (free AI)', 'agentyllo' ) }</strong>
				<span className={ 'agy-badge ' + ( local.available ? 'agy-badge--ok' : 'agy-badge--muted' ) }>
					{ local.available ? __( 'Configured', 'agentyllo' ) : __( 'Not configured', 'agentyllo' ) }
				</span>
			</CardHeader>
			<CardBody>
				{ notice && (
					<Notice status={ notice.status } isDismissible={ false }>
						{ notice.text }
					</Notice>
				) }
				<p className="agy-muted">
					{ __( 'Point Agentyllo at an OpenAI-compatible server you already run: llama.cpp llama-server, Ollama, LM Studio, vLLM — or the daemon installed by the free "Agentyllo Local AI" companion. Used by the free AI operating modes.', 'agentyllo' ) }
				</p>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Endpoint URL', 'agentyllo' ) }
					placeholder="http://127.0.0.1:11434"
					value={ url }
					onChange={ setUrl }
					help={ __( 'Base URL without /v1 (e.g. http://127.0.0.1:8080 for llama-server, http://127.0.0.1:11434 for Ollama).', 'agentyllo' ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Model id (optional)', 'agentyllo' ) }
					placeholder="qwen2.5:3b-instruct"
					value={ model }
					onChange={ setModel }
					help={ __( 'Leave empty to use the model the server has loaded.', 'agentyllo' ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="password"
					autoComplete="new-password"
					label={ __( 'Bearer key (optional)', 'agentyllo' ) }
					placeholder={ local.has_key ? __( 'Leave empty to keep the saved key', 'agentyllo' ) : '' }
					value={ key }
					onChange={ setKey }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min={ 1 }
					max={ 200 }
					label={ __( 'Minimum speed for chat (tokens/s)', 'agentyllo' ) }
					value={ minTps }
					onChange={ setMinTps }
					help={ gate }
				/>
				<div className="agy-settings-tab__actions">
					<Button variant="primary" isBusy={ 'save' === busy } disabled={ '' !== busy } onClick={ onSave }>
						{ __( 'Save', 'agentyllo' ) }
					</Button>
					<Button variant="secondary" isBusy={ 'test' === busy } disabled={ '' !== busy || ! url.trim() } onClick={ onTest }>
						{ __( 'Test & measure speed', 'agentyllo' ) }
					</Button>
				</div>
				{ local.circuit.open && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf( /* translators: %s: last error */ __( 'Paused after repeated failures (%s).', 'agentyllo' ), local.circuit.last_error || '—' ) }
					</Notice>
				) }
			</CardBody>
		</Card>
	);
}

export default function AiModels() {
	const [ data, setData ] = useState< Overview | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ form, setForm ] = useState< Record< string, any > >( {} );
	const [ saving, setSaving ] = useState( false );
	const [ syncing, setSyncing ] = useState( false );
	const [ notice, setNotice ] = useState< { status: 'success' | 'error' | 'info'; text: string } | null >( null );

	const load = useCallback( () => {
		setError( null );
		return apiFetch< Overview >( { path: '/models' } )
			.then( ( res ) => {
				setData( res );
				setForm( {
					chat_provider: res.settings.chat_provider || 'none',
					embedding_provider: res.settings.embedding_provider || 'none',
					openai_embedding_model: res.settings.openai_embedding_model || '',
					local_embedding_model: res.settings.local_embedding_model || '',
					monthly_cost_cap_usd: res.settings.monthly_cost_cap_usd ?? 20,
					max_output_tokens: res.settings.max_output_tokens ?? 600,
					request_timeout_s: res.settings.request_timeout_s ?? 25,
					registry_auto_sync: false !== res.settings.registry_auto_sync,
					browser_ai_enabled: !! res.settings.browser_ai_enabled,
				} );
			} )
			.catch( ( e: any ) => setError( e?.message || __( 'Could not load AI models.', 'agentyllo' ) ) );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const saveForm = async () => {
		setSaving( true );
		setNotice( null );
		try {
			await apiFetch( { path: '/settings/models', method: 'PUT', data: { values: form } } );
			setNotice( { status: 'success', text: __( 'Settings saved.', 'agentyllo' ) } );
			await load();
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Could not save.', 'agentyllo' ) } );
		} finally {
			setSaving( false );
		}
	};

	const [ embedding, setEmbedding ] = useState( false );
	const embedNow = async () => {
		setEmbedding( true );
		setNotice( null );
		try {
			const res: any = await apiFetch( { path: '/models/embed-now', method: 'POST' } );
			setNotice( {
				status: 'success',
				text: sprintf(
					/* translators: 1: embedded now, 2: total, 3: remaining */
					__( 'Embedded %1$d chunks (total %2$d, %3$d remaining).', 'agentyllo' ),
					res.embedded,
					res.count,
					res.remaining
				),
			} );
			await load();
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Embedding failed.', 'agentyllo' ) } );
		} finally {
			setEmbedding( false );
		}
	};

	const syncRegistry = async () => {
		setSyncing( true );
		setNotice( null );
		try {
			const res: any = await apiFetch( { path: '/models/registry-sync', method: 'POST' } );
			setNotice( { status: res.ok ? 'success' : 'error', text: res.message } );
			await load();
		} catch ( e: any ) {
			setNotice( { status: 'error', text: e?.message || __( 'Sync failed.', 'agentyllo' ) } );
		} finally {
			setSyncing( false );
		}
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }{ ' ' }
				<Button variant="link" onClick={ load }>{ __( 'Retry', 'agentyllo' ) }</Button>
			</Notice>
		);
	}
	if ( ! data ) {
		return <Loading />;
	}

	const { status, usage, registry, providers, transport } = data;
	const capPct = usage.cap_usd > 0 ? Math.min( 100, Math.round( ( usage.cost_usd / usage.cap_usd ) * 100 ) ) : 0;

	return (
		<div className="agy-ai-models">
			<h2>{ __( 'AI Models', 'agentyllo' ) }</h2>

			<Card>
				<CardHeader>
					<strong>{ __( 'Status', 'agentyllo' ) }</strong>
					<span className={ 'agy-badge ' + ( status.active ? 'agy-badge--ok' : 'agy-badge--muted' ) }>
						{ status.active
							? sprintf( /* translators: %s: provider id */ __( 'AI active: %s', 'agentyllo' ), providers[ status.active ]?.label || ( 'local_endpoint' === status.active ? __( 'Local engine', 'agentyllo' ) : status.active ) )
							: __( 'Classic agents answering', 'agentyllo' ) }
					</span>
				</CardHeader>
				<CardBody>
					<p>
						{ sprintf( /* translators: %s: operating mode */ __( 'Operating mode: %s.', 'agentyllo' ), status.mode.replace( /_/g, ' ' ) ) }{ ' ' }
						{ status.reason && ! status.active && ( REASONS[ status.reason ] || status.reason ) }
					</p>
					{ status.cap_reached && (
						<Notice status="warning" isDismissible={ false }>
							{ REASONS.cap_reached }
						</Notice>
					) }
					<p className="agy-muted">
						{ transport.streaming_capable
							? __( 'Streaming: available (token-by-token answers in the widget).', 'agentyllo' )
							: __( 'Streaming: this server cannot stream from providers (no cURL) — answers are delivered when complete.', 'agentyllo' ) }
					</p>
				</CardBody>
			</Card>

			<div className="agy-provider-grid">
				<ProviderCard id="openai" info={ providers.openai } settings={ data.settings } onSaved={ load } />
				<ProviderCard id="anthropic" info={ providers.anthropic } settings={ data.settings } onSaved={ load } />
				<LocalCard local={ data.local } settings={ data.settings } onSaved={ load } />
			</div>

			<Card>
				<CardHeader>
					<strong>{ __( 'Routing & budget', 'agentyllo' ) }</strong>
				</CardHeader>
				<CardBody>
					{ notice && (
						<Notice status={ notice.status } isDismissible={ false }>
							{ notice.text }
						</Notice>
					) }
					<div className="agy-schema-form">
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Chat provider (paid AI modes)', 'agentyllo' ) }
							value={ form.chat_provider }
							options={ [
								{ value: 'none', label: __( 'None', 'agentyllo' ) },
								{ value: 'openai', label: providers.openai.label },
								{ value: 'anthropic', label: providers.anthropic.label },
							] }
							onChange={ ( v: string ) => setForm( { ...form, chat_provider: v } ) }
							help={ __( 'Used when the operating mode is "paid AI" or "classic + paid AI". Only one provider bills at a time. Free AI modes use the local engine below.', 'agentyllo' ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Embeddings provider', 'agentyllo' ) }
							value={ form.embedding_provider }
							options={ [
								{ value: 'none', label: __( 'None (keyword search only)', 'agentyllo' ) },
								{ value: 'openai', label: providers.openai.label },
								{ value: 'local', label: __( 'Local engine (/v1/embeddings)', 'agentyllo' ) },
							] }
							onChange={ ( v: string ) => setForm( { ...form, embedding_provider: v } ) }
							help={ __( 'Dense vectors improve retrieval for paraphrased questions. Local ONNX embeddings arrive with the Local AI companion.', 'agentyllo' ) }
						/>
						{ 'local' === form.embedding_provider && (
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Local embedding model (optional)', 'agentyllo' ) }
								placeholder="nomic-embed-text"
								value={ String( form.local_embedding_model || '' ) }
								onChange={ ( v: string ) => setForm( { ...form, local_embedding_model: v } ) }
								help={ __( 'Model id served by the local engine for /v1/embeddings (Ollama: nomic-embed-text, bge-m3; llama-server: run with --embeddings).', 'agentyllo' ) }
							/>
						) }
						{ 'openai' === form.embedding_provider && (
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Embedding model', 'agentyllo' ) }
								value={ form.openai_embedding_model }
								options={ modelOptions( providers.openai.embedding_models ) }
								onChange={ ( v: string ) => setForm( { ...form, openai_embedding_model: v } ) }
							/>
						) }
						{ 'none' !== form.embedding_provider && (
							<p className="agy-muted">
								{ data.vectors.model_key
									? sprintf(
										/* translators: 1: vectors stored, 2: remaining, 3: model key */
										__( 'Vectors: %1$d stored, %2$s remaining (%3$s).', 'agentyllo' ),
										data.vectors.count,
										null === data.vectors.remaining ? '?' : String( data.vectors.remaining ),
										data.vectors.model_key
									)
									: __( 'The embedding provider is not usable yet (missing key?).', 'agentyllo' ) }{ ' ' }
								<Button variant="link" isBusy={ embedding } disabled={ embedding || ! data.vectors.model_key } onClick={ embedNow }>
									{ __( 'Embed now', 'agentyllo' ) }
								</Button>
							</p>
						) }
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Browser AI (experimental)', 'agentyllo' ) }
							checked={ !! form.browser_ai_enabled }
							onChange={ ( v: boolean ) => setForm( { ...form, browser_ai_enabled: v } ) }
							help={ __( 'Lets the widget probe WebGPU and offer an in-browser model when the Local AI companion provides one. Visitors must opt in explicitly; nothing downloads without consent.', 'agentyllo' ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							min={ 0 }
							step={ 1 }
							label={ __( 'Monthly cost cap (USD, 0 = no cap)', 'agentyllo' ) }
							value={ String( form.monthly_cost_cap_usd ) }
							onChange={ ( v: string ) => setForm( { ...form, monthly_cost_cap_usd: v } ) }
							help={ __( 'Estimated from registry prices. When reached, the classic agents answer until next month.', 'agentyllo' ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							min={ 100 }
							max={ 4000 }
							label={ __( 'Max answer tokens', 'agentyllo' ) }
							value={ String( form.max_output_tokens ) }
							onChange={ ( v: string ) => setForm( { ...form, max_output_tokens: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							min={ 8 }
							max={ 90 }
							label={ __( 'Time budget per answer (seconds)', 'agentyllo' ) }
							value={ String( form.request_timeout_s ) }
							onChange={ ( v: string ) => setForm( { ...form, request_timeout_s: v } ) }
							help={ __( 'A slow provider is cut off here and the classic agents answer instead.', 'agentyllo' ) }
						/>
					</div>
					<div className="agy-settings-tab__actions">
						<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ saveForm }>
							{ __( 'Save', 'agentyllo' ) }
						</Button>
					</div>
					<h4>{ sprintf( /* translators: %s: month */ __( 'Usage this month (%s)', 'agentyllo' ), usage.month ) }</h4>
					<div className="agy-usage-bar" aria-hidden="true">
						<div className="agy-usage-bar__fill" style={ { width: capPct + '%' } } />
					</div>
					<p className="agy-muted">
						{ sprintf(
							/* translators: 1: cost, 2: cap, 3: calls, 4: tokens in, 5: tokens out */
							__( '%1$s spent%2$s · %3$d calls · %4$s tokens in · %5$s tokens out', 'agentyllo' ),
							money( usage.cost_usd ),
							usage.cap_usd > 0 ? ' / ' + money( usage.cap_usd ) : '',
							usage.calls,
							usage.tokens_in.toLocaleString(),
							usage.tokens_out.toLocaleString()
						) }
					</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<strong>{ __( 'Model registry', 'agentyllo' ) }</strong>
				</CardHeader>
				<CardBody>
					<p className="agy-muted">
						{ sprintf(
							/* translators: 1: origin, 2: sequence, 3: generated date */
							__( 'Source: %1$s · sequence %2$d · generated %3$s', 'agentyllo' ),
							'remote' === registry.origin ? __( 'signed remote manifest', 'agentyllo' ) : __( 'bundled snapshot', 'agentyllo' ),
							registry.sequence,
							registry.generated_at || '—'
						) }
						<br />
						{ registry.last_sync.at > 0
							? sprintf(
								/* translators: 1: date, 2: message */
								__( 'Last sync %1$s — %2$s', 'agentyllo' ),
								new Date( registry.last_sync.at * 1000 ).toLocaleString(),
								registry.last_sync.message
							)
							: __( 'Not synced yet (weekly automatic sync).', 'agentyllo' ) }
					</p>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Sync automatically every week', 'agentyllo' ) }
						checked={ !! form.registry_auto_sync }
						onChange={ ( v: boolean ) => setForm( { ...form, registry_auto_sync: v } ) }
						help={ __( 'Downloads model ids, prices and prompt versions (data only, Ed25519-signed) from registry.agentyllo.com.', 'agentyllo' ) }
					/>
					<div className="agy-settings-tab__actions">
						<Button variant="secondary" isBusy={ syncing } disabled={ syncing } onClick={ syncRegistry }>
							{ __( 'Sync now', 'agentyllo' ) }
						</Button>
					</div>
				</CardBody>
			</Card>

			{ data.recent.length > 0 && (
				<Card>
					<CardHeader>
						<strong>{ __( 'Recent inference calls', 'agentyllo' ) }</strong>
					</CardHeader>
					<CardBody>
						<table className="widefat striped agy-probe-table">
							<thead>
								<tr>
									<th>{ __( 'When', 'agentyllo' ) }</th>
									<th>{ __( 'Provider / model', 'agentyllo' ) }</th>
									<th>{ __( 'Result', 'agentyllo' ) }</th>
									<th>{ __( 'Tokens', 'agentyllo' ) }</th>
									<th>{ __( 'Latency', 'agentyllo' ) }</th>
									<th>{ __( 'Cost', 'agentyllo' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.recent.map( ( row, i ) => (
									<tr key={ i }>
										<td>{ row.created_at }</td>
										<td>{ row.provider } / { row.model }</td>
										<td>{ Number( row.ok ) ? ( Number( row.streamed ) ? __( 'ok (streamed)', 'agentyllo' ) : __( 'ok', 'agentyllo' ) ) : row.error || __( 'error', 'agentyllo' ) }</td>
										<td>{ row.tokens_in } / { row.tokens_out }</td>
										<td>{ row.latency_ms } ms</td>
										<td>{ money( Number( row.cost_usd ) ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</CardBody>
				</Card>
			) }
		</div>
	);
}
