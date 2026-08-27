/**
 * Help page: auto-generated from the copilot action registry (never out of
 * sync with what the assistant can actually do) + quick-start notes.
 */
import { api as apiFetch } from '../api';
import { Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Loading from '../components/Loading';

type ActionDesc = {
	id: string;
	group: string;
	description: string;
	usage: string;
	destructive: boolean;
	args: Record< string, { type: string; required?: boolean; values?: string[] } >;
};

const GROUP_LABELS: Record< string, string > = {
	kb: __( 'Knowledge base', 'agentyllo' ),
	settings: __( 'Settings', 'agentyllo' ),
	memory: __( 'Memory', 'agentyllo' ),
	stats: __( 'Statistics', 'agentyllo' ),
	general: __( 'General', 'agentyllo' ),
};

export default function Help() {
	const [ actions, setActions ] = useState< ActionDesc[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		apiFetch< { actions: ActionDesc[] } >( { path: '/copilot/actions' } )
			.then( ( res ) => setActions( res.actions || [] ) )
			.catch( ( e: any ) => setError( e?.message || __( 'Could not load the command list.', 'agentyllo' ) ) );
	}, [] );

	const groups: Record< string, ActionDesc[] > = {};
	( actions || [] ).forEach( ( a ) => {
		( groups[ a.group ] = groups[ a.group ] || [] ).push( a );
	} );

	return (
		<div className="agy-help">
			<h2>{ __( 'Help', 'agentyllo' ) }</h2>
			<Card>
				<CardHeader><strong>{ __( 'How Agentyllo works', 'agentyllo' ) }</strong></CardHeader>
				<CardBody>
					<p>{ __( 'Agentyllo builds a knowledge base from your pages, posts, products, menus and site settings, keeps it fresh automatically, and answers visitors from it. Classic agents answer without any AI model; optional AI tiers (a local engine you run, or OpenAI/Anthropic with your own key) add natural prose — hard facts such as prices and contact details are always taken verbatim from your content, never generated.', 'agentyllo' ) }</p>
					<ol>
						<li>{ __( 'Knowledge Base → choose which content sources are indexed. Disabling a source removes it from answers immediately.', 'agentyllo' ) }</li>
						<li>{ __( 'Settings → General → operating mode, assistant name, tone, custom instructions.', 'agentyllo' ) }</li>
						<li>{ __( 'AI Models → optional: cloud keys, a local engine (llama-server/Ollama), embeddings, budget.', 'agentyllo' ) }</li>
						<li>{ __( 'Privacy & Legal → pre-chat gate, retention, PII redaction, AI transparency page (EU AI Act Art. 50).', 'agentyllo' ) }</li>
						<li>{ __( 'Copilot (Ctrl+Shift+K on any Agentyllo page) → manage everything by command; every change is proposed first and applied only when you confirm.', 'agentyllo' ) }</li>
					</ol>
				</CardBody>
			</Card>

			<Card>
				<CardHeader><strong>{ __( 'Copilot commands', 'agentyllo' ) }</strong></CardHeader>
				<CardBody>
					{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
					{ ! actions && ! error && <Loading /> }
					{ actions && Object.entries( groups ).map( ( [ group, list ] ) => (
						<div key={ group } className="agy-help__group">
							<h3>{ GROUP_LABELS[ group ] || group }</h3>
							<table className="widefat striped">
								<thead>
									<tr>
										<th>{ __( 'Command', 'agentyllo' ) }</th>
										<th>{ __( 'What it does', 'agentyllo' ) }</th>
										<th>{ __( 'Confirmation', 'agentyllo' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ list.map( ( a ) => (
										<tr key={ a.id }>
											<td><code>{ a.usage }</code></td>
											<td>{ a.description }</td>
											<td>{ a.destructive ? __( 'Required', 'agentyllo' ) : __( 'Proposal → Apply', 'agentyllo' ) }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) ) }
					<p className="agy-muted">{ __( 'Anything that is not a /command is answered from your site content. Files: TXT, Markdown and CSV can be imported from the copilot with a reviewable preview.', 'agentyllo' ) }</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader><strong>{ __( 'Shortcode & filters', 'agentyllo' ) }</strong></CardHeader>
				<CardBody>
					<ul>
						<li><code>[agentyllo_transparency]</code> — { __( 'AI transparency page content (generated from your configuration).', 'agentyllo' ) }</li>
						<li><code>agyl_addons</code>, <code>agyl_powered_by</code>, <code>agyl_feature_enabled</code>, <code>agyl_llm_providers</code>, <code>agyl_embedding_providers</code>, <code>agyl_copilot_actions</code>, <code>agyl_settings_tabs</code>, <code>agyl_registry_url</code> — { __( 'extension points for addons and the Local AI companion.', 'agentyllo' ) }</li>
					</ul>
				</CardBody>
			</Card>
		</div>
	);
}
