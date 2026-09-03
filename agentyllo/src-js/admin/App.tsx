/**
 * Admin app shell: header + page routing.
 */
import { __ } from '@wordpress/i18n';

import Drawer from './copilot/Drawer';
import Addons from './pages/Addons';
import Agents from './pages/Agents';
import AiModels from './pages/AiModels';
import Conversations from './pages/Conversations';
import Dashboard from './pages/Dashboard';
import Help from './pages/Help';
import KnowledgeBase from './pages/KnowledgeBase';
import Privacy from './pages/Privacy';
import Settings from './pages/Settings';
import Statistics from './pages/Statistics';

const PAGES: Record< string, () => JSX.Element > = {
	dashboard: Dashboard,
	kb: KnowledgeBase,
	conversations: Conversations,
	agents: Agents,
	addons: Addons,
	models: AiModels,
	help: Help,
	stats: Statistics,
	privacy: Privacy,
	settings: Settings,
};

export default function App( { page }: { page: string } ) {
	return (
		<div className="agy-admin">
			<header className="agy-admin__header">
				<h1 className="agy-wordmark">
					<span className="agy-wm-a">Agent</span><span className="agy-wm-b">yllo</span>
					<span className="agy-admin__version">v{ window.agylAdmin.version }</span>
				</h1>
			</header>
			<main className="agy-admin__main">
				{ ( () => {
					const Page = PAGES[ page ] || Dashboard;
					return <Page />;
				} )() }
			</main>
			<Drawer />
		</div>
	);
}
