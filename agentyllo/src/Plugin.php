<?php
/**
 * Plugin bootstrap: builds the container, wires services, fires agyl_init.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo;

defined( 'ABSPATH' ) || exit;

use Agentyllo\AI\Budget\Manager as BudgetManager;
use Agentyllo\AI\Budget\ResponseCache;
use Agentyllo\AI\Capability\Detector;
use Agentyllo\AI\Prompt\ChatPromptBuilder;
use Agentyllo\AI\ProviderRouter;
use Agentyllo\AI\EmbeddingRouter;
use Agentyllo\AI\Providers\AnthropicProvider;
use Agentyllo\AI\Providers\LocalEndpointEmbeddings;
use Agentyllo\AI\Providers\LocalEndpointProvider;
use Agentyllo\AI\Tasks\QueryRewriter;
use Agentyllo\AI\Providers\OpenAIEmbeddings;
use Agentyllo\AI\Providers\OpenAIProvider;
use Agentyllo\Admin\Assets;
use Agentyllo\Admin\Menu;
use Agentyllo\Admin\Settings\SettingsSchema;
use Agentyllo\Admin\Settings\SettingsStore;
use Agentyllo\Agents\Kernel\AsyncBus;
use Agentyllo\Agents\Kernel\EventBus;
use Agentyllo\Agents\Kernel\Journal;
use Agentyllo\Agents\Kernel\MemoryStore;
use Agentyllo\Agents\Kernel\Orchestrator;
use Agentyllo\Agents\Kernel\Quarantine;
use Agentyllo\Agents\Kernel\Registry;
use Agentyllo\Agents\Roster\JanitorAgent;
use Agentyllo\Agents\Roster\LearnerAgent;
use Agentyllo\Agents\Roster\SentinelAgent;
use Agentyllo\Agents\Roster\ComposerAgent;
use Agentyllo\Agents\Roster\ContentWatcherAgent;
use Agentyllo\Agents\Roster\IntentRouterAgent;
use Agentyllo\Agents\Roster\KbCuratorAgent;
use Agentyllo\Agents\Roster\LangBrokerAgent;
use Agentyllo\Agents\Roster\LinkGrapherAgent;
use Agentyllo\Agents\Roster\ReconcilerAgent;
use Agentyllo\Agents\Roster\RetrieverAgent;
use Agentyllo\Agents\Roster\ScopeGuardAgent;
use Agentyllo\Agents\Roster\SiteProfilerAgent;
use Agentyllo\Chat\ConversationLog;
use Agentyllo\Chat\EntityExtractor;
use Agentyllo\Chat\IntentClassifier;
use Agentyllo\Chat\LanguageDetector;
use Agentyllo\Chat\Pipeline\Pipeline;
use Agentyllo\Chat\RateLimiter;
use Agentyllo\Chat\Session\SessionManager;
use Agentyllo\Chat\Stages\AiComposeStage;
use Agentyllo\Chat\Stages\ComposeStage;
use Agentyllo\Chat\Stages\IntentClassifyStage;
use Agentyllo\Chat\Stages\LanguageDetectStage;
use Agentyllo\Chat\Stages\NormalizeStage;
use Agentyllo\Chat\Stages\PostProcessStage;
use Agentyllo\Chat\Stages\RetrieveStage;
use Agentyllo\Chat\Stages\RouteStage;
use Agentyllo\Chat\Stages\ScopeGuardStage;
use Agentyllo\Chat\StarterSuggestions;
use Agentyllo\Chat\Templates;
use Agentyllo\Compliance\AiAct;
use Agentyllo\Copilot\ActionRegistry;
use Agentyllo\Copilot\Copilot;
use Agentyllo\Copilot\CopilotBrain;
use Agentyllo\Copilot\CoreActions;
use Agentyllo\Copilot\FileIngest;
use Agentyllo\Compliance\Consent;
use Agentyllo\Compliance\Dsar;
use Agentyllo\Compliance\Retention;
use Agentyllo\Frontend\WidgetLoader;
use Agentyllo\Admin\AddonCatalog;
use Agentyllo\Rest\AddonsController;
use Agentyllo\Rest\ChatController;
use Agentyllo\Rest\ConfigController;
use Agentyllo\Rest\CopilotController;
use Agentyllo\Rest\PrivacyController;
use Agentyllo\Rest\StatsController;
use Agentyllo\Stats\Stats;
use Agentyllo\Infra\Crypto\Ed25519Verifier;
use Agentyllo\Infra\Crypto\KeyVault;
use Agentyllo\Infra\Http\StreamingClient;
use Agentyllo\Infra\Jobs;
use Agentyllo\Infra\Options;
use Agentyllo\Install\Migrator;
use Agentyllo\KB\AdapterRegistry;
use Agentyllo\KB\Health;
use Agentyllo\KB\Indexer\Chunker;
use Agentyllo\KB\Indexer\IndexManager;
use Agentyllo\KB\Indexer\Normalizer;
use Agentyllo\KB\Indexer\VectorIndexer;
use Agentyllo\KB\LinkGraph;
use Agentyllo\KB\ManualEntries;
use Agentyllo\KB\Retrieval\HybridRetriever;
use Agentyllo\KB\Retrieval\Tokenizer;
use Agentyllo\KB\Retrieval\VectorStore;
use Agentyllo\KB\Source\ElementorAdapter;
use Agentyllo\KB\Source\ManualAdapter;
use Agentyllo\KB\Source\MenuAdapter;
use Agentyllo\KB\Source\PostTypeAdapter;
use Agentyllo\KB\Source\SiteIdentityAdapter;
use Agentyllo\KB\Source\TaxonomyAdapter;
use Agentyllo\KB\Source\WooProductAdapter;
use Agentyllo\KB\Store;
use Agentyllo\Rest\AgentsController;
use Agentyllo\Rest\CapabilitiesController;
use Agentyllo\Rest\DashboardController;
use Agentyllo\Rest\KbController;
use Agentyllo\Rest\ModelsController;
use Agentyllo\Rest\ProbeController;
use Agentyllo\Rest\SearchController;
use Agentyllo\Rest\SettingsController;
use Agentyllo\Registry\Manifest;
use Agentyllo\Registry\RemoteSync;

/**
 * Singleton entry point, hooked on `plugins_loaded` priority 5.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 */
	private Container $container;

	/**
	 * Boot the plugin (idempotent).
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->run();
		}
	}

	/**
	 * Access the booted instance. Null before `plugins_loaded`.
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Access the container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Whether a premium feature flag is unlocked.
	 *
	 * Free core always returns false; the Freemius layer (or an addon) unlocks
	 * flags through this filter. Known flags so far: `remove_branding`.
	 *
	 * @param string $flag Feature flag id.
	 */
	public static function feature_enabled( string $flag ): bool {
		/**
		 * Filter premium feature availability.
		 *
		 * @param bool   $enabled Whether the feature is unlocked. Default false.
		 * @param string $flag    Feature flag id.
		 */
		return (bool) apply_filters( 'agyl_feature_enabled', false, $flag );
	}

	/**
	 * Build the container and attach WordPress hooks.
	 */
	private function run(): void {
		$this->container = $this->build_container();

		/**
		 * Filter the service container right after construction.
		 *
		 * Addons may override or add bindings here.
		 *
		 * @param Container $container The Agentyllo container.
		 */
		$this->container = apply_filters( 'agyl_container', $this->container );

		$container = $this->container;

		add_action(
			'init',
			static function () use ( $container ): void {
				// Cheap version check on EVERY request type (front, REST, cron,
				// admin): a background auto-update must never leave new code
				// running against an old schema until a human opens wp-admin.
				$container->get( Migrator::class )->maybe_upgrade();
				$container->get( Jobs::class )->register();
			}
		);

		// Priority 20: after CPT/taxonomy registration so adapter subtypes()
		// and delta hooks see every registered type.
		add_action(
			'init',
			static function () use ( $container ): void {
				$container->get( IndexManager::class )->register();
				$container->get( VectorIndexer::class )->register();
			},
			20
		);

		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( SettingsController::class )->register_routes();
				$container->get( CapabilitiesController::class )->register_routes();
				$container->get( DashboardController::class )->register_routes();
				$container->get( ProbeController::class )->register_routes();
				$container->get( AgentsController::class )->register_routes();
				$container->get( KbController::class )->register_routes();
				$container->get( SearchController::class )->register_routes();
				$container->get( ConfigController::class )->register_routes();
				$container->get( ChatController::class )->register_routes();
				$container->get( PrivacyController::class )->register_routes();
				$container->get( StatsController::class )->register_routes();
				$container->get( AddonsController::class )->register_routes();
				$container->get( ModelsController::class )->register_routes();
				$container->get( CopilotController::class )->register_routes();
			}
		);

		// AI settings changed (key/model/provider): stale cached answers and
		// tripped circuits must not outlive the change.
		add_action(
			'agyl_settings_updated',
			static function ( string $tab ) use ( $container ): void {
				if ( 'models' !== $tab ) {
					return;
				}
				$container->get( ResponseCache::class )->flush();
				foreach ( array( OpenAIProvider::ID, AnthropicProvider::ID, LocalEndpointProvider::ID ) as $provider ) {
					$container->get( BudgetManager::class )->reset_circuit( $provider );
				}
				// Embedding provider/model change: drop foreign-model vectors and
				// (re)start embedding in the background.
				$container->get( VectorStore::class )->gc( $container->get( EmbeddingRouter::class )->model_key() );
				$container->get( VectorIndexer::class )->schedule();
			}
		);

		// Manual KB entries: trash is purged after 30 days (hourly maintenance).
		add_action(
			'agyl_maintenance',
			static function () use ( $container ): void {
				$container->get( ManualEntries::class )->purge_trashed( 30 );
			}
		);

		// Frontend widget (self-guards against admin/feed/login at render time).
		$container->get( WidgetLoader::class )->register();

		// Compliance: WP core privacy exporter/eraser + transparency shortcode.
		$container->get( Dsar::class )->register();
		$container->get( AiAct::class )->register();

		// Core roster registration (low priority so addons see the core set).
		add_filter(
			'agyl_register_agents',
			static function ( array $agents ) use ( $container ): array {
				$agents[] = $container->get( SentinelAgent::class );
				$agents[] = $container->get( JanitorAgent::class );
				$agents[] = $container->get( LearnerAgent::class );
				$agents[] = $container->get( KbCuratorAgent::class );
				$agents[] = $container->get( ContentWatcherAgent::class );
				$agents[] = $container->get( ReconcilerAgent::class );
				$agents[] = $container->get( RetrieverAgent::class );
				$agents[] = $container->get( LinkGrapherAgent::class );
				$agents[] = $container->get( SiteProfilerAgent::class );
				$agents[] = $container->get( IntentRouterAgent::class );
				$agents[] = $container->get( ComposerAgent::class );
				$agents[] = $container->get( ScopeGuardAgent::class );
				$agents[] = $container->get( LangBrokerAgent::class );

				return $agents;
			},
			5
		);

		if ( is_admin() ) {
			add_action(
				'admin_menu',
				static function () use ( $container ): void {
					$container->get( Menu::class )->register();
				}
			);
			add_action(
				'admin_enqueue_scripts',
				static function ( string $hook ) use ( $container ): void {
					$container->get( Assets::class )->maybe_enqueue( $hook );
				}
			);

			// Quick access from the Plugins list: Settings + Set up AI.
			add_filter(
				'plugin_action_links_' . plugin_basename( AGYL_FILE ),
				static function ( array $links ): array {
					$cap = (string) apply_filters( 'agyl_capability_map', 'manage_options', 'agyl_manage' );
					if ( ! current_user_can( $cap ) ) {
						return $links;
					}
					$own = array(
						'settings' => sprintf(
							'<a href="%s">%s</a>',
							esc_url( admin_url( 'admin.php?page=agentyllo-settings' ) ),
							esc_html__( 'Settings', 'agentyllo' )
						),
						'ai'       => sprintf(
							'<a href="%s" style="color:#4f46e5;font-weight:600">%s</a>',
							esc_url( admin_url( 'admin.php?page=agentyllo-models' ) ),
							esc_html__( 'Set up AI', 'agentyllo' )
						),
					);
					return array_merge( $own, $links );
				}
			);

			// First-run: land the user on the dashboard with a welcome flag
			// instead of nowhere. Fires once, never during bulk/network activation.
			add_action(
				'admin_init',
				static function (): void {
					if ( ! get_transient( 'agyl_activation_redirect' ) ) {
						return;
					}
					delete_transient( 'agyl_activation_redirect' );
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || is_network_admin() ) {
						return;
					}
					$cap = (string) apply_filters( 'agyl_capability_map', 'manage_options', 'agyl_manage' );
					if ( current_user_can( $cap ) ) {
						wp_safe_redirect( admin_url( 'admin.php?page=agentyllo&welcome=1' ) );
						exit;
					}
				}
			);
		}

		/**
		 * Fires once Agentyllo core is wired. Addons register agents,
		 * providers, and settings tabs from this hook.
		 *
		 * @param Container $container The Agentyllo container.
		 */
		do_action( 'agyl_init', $this->container );
	}

	/**
	 * Default service bindings.
	 */
	private function build_container(): Container {
		$c = new Container();

		$c->singleton( Options::class, static fn (): Options => new Options() );
		$c->singleton( SettingsSchema::class, static fn (): SettingsSchema => new SettingsSchema() );
		$c->singleton(
			SettingsStore::class,
			static fn ( Container $c ): SettingsStore => new SettingsStore( $c->get( SettingsSchema::class ), $c->get( Options::class ) )
		);
		$c->singleton(
			Detector::class,
			static fn ( Container $c ): Detector => new Detector( $c->get( Options::class ) )
		);

		// Agent kernel.
		$c->singleton( MemoryStore::class, static fn (): MemoryStore => new MemoryStore() );
		$c->singleton( Journal::class, static fn (): Journal => new Journal() );
		$c->singleton(
			EventBus::class,
			static fn ( Container $c ): EventBus => new EventBus( $c->get( Journal::class ) )
		);
		$c->singleton(
			Registry::class,
			static fn ( Container $c ): Registry => new Registry( $c->get( Options::class ) )
		);
		$c->singleton(
			Quarantine::class,
			static fn ( Container $c ): Quarantine => new Quarantine( $c->get( Registry::class ), $c->get( Journal::class ), $c->get( EventBus::class ) )
		);
		$c->singleton( AsyncBus::class, static fn (): AsyncBus => new AsyncBus() );
		$c->singleton(
			Orchestrator::class,
			static fn ( Container $c ): Orchestrator => new Orchestrator(
				$c->get( Registry::class ),
				$c->get( MemoryStore::class ),
				$c->get( Journal::class ),
				$c->get( EventBus::class ),
				$c->get( AsyncBus::class ),
				$c->get( Detector::class ),
				$c
			)
		);

		// Core roster.
		$c->singleton(
			SentinelAgent::class,
			static fn ( Container $c ): SentinelAgent => new SentinelAgent( $c->get( Registry::class ), $c->get( Quarantine::class ) )
		);
		$c->singleton( JanitorAgent::class, static fn (): JanitorAgent => new JanitorAgent() );
		$c->singleton( LearnerAgent::class, static fn (): LearnerAgent => new LearnerAgent() );

		// Knowledge base engine.
		$c->singleton( Tokenizer::class, static fn (): Tokenizer => new Tokenizer() );
		$c->singleton( Normalizer::class, static fn (): Normalizer => new Normalizer() );
		$c->singleton(
			Chunker::class,
			static fn ( Container $c ): Chunker => new Chunker( $c->get( Tokenizer::class ) )
		);
		$c->singleton(
			Store::class,
			static fn ( Container $c ): Store => new Store( $c->get( Tokenizer::class ) )
		);
		$c->singleton(
			ElementorAdapter::class,
			static fn ( Container $c ): ElementorAdapter => new ElementorAdapter( $c->get( Normalizer::class ) )
		);
		$c->singleton(
			PostTypeAdapter::class,
			static fn ( Container $c ): PostTypeAdapter => new PostTypeAdapter(
				$c->get( Normalizer::class ),
				$c->get( ElementorAdapter::class ),
				static fn ( string $key ): mixed => $c->get( SettingsStore::class )->value( 'sources', $key )
			)
		);
		$c->singleton(
			TaxonomyAdapter::class,
			static fn ( Container $c ): TaxonomyAdapter => new TaxonomyAdapter( $c->get( Normalizer::class ) )
		);
		$c->singleton( MenuAdapter::class, static fn (): MenuAdapter => new MenuAdapter() );
		$c->singleton( SiteIdentityAdapter::class, static fn (): SiteIdentityAdapter => new SiteIdentityAdapter() );
		$c->singleton( ManualAdapter::class, static fn (): ManualAdapter => new ManualAdapter() );
		$c->singleton(
			WooProductAdapter::class,
			static fn ( Container $c ): WooProductAdapter => new WooProductAdapter(
				$c->get( Normalizer::class ),
				static fn (): array => $c->get( SettingsStore::class )->get( 'sources' )
			)
		);
		$c->singleton(
			AdapterRegistry::class,
			static fn ( Container $c ): AdapterRegistry => new AdapterRegistry(
				array(
					$c->get( SiteIdentityAdapter::class ),
					$c->get( MenuAdapter::class ),
					$c->get( PostTypeAdapter::class ),
					$c->get( WooProductAdapter::class ),
					$c->get( TaxonomyAdapter::class ),
					$c->get( ManualAdapter::class ),
				)
			)
		);
		$c->singleton(
			IndexManager::class,
			static fn ( Container $c ): IndexManager => new IndexManager(
				$c->get( AdapterRegistry::class ),
				$c->get( Store::class ),
				$c->get( Chunker::class ),
				$c->get( Detector::class ),
				$c->get( Journal::class ),
				static fn (): array => $c->get( SettingsStore::class )->get( 'sources' )
			)
		);
		$c->singleton(
			HybridRetriever::class,
			static fn ( Container $c ): HybridRetriever => new HybridRetriever( $c->get( Tokenizer::class ) )
		);
		$c->singleton( LinkGraph::class, static fn (): LinkGraph => new LinkGraph() );
		$c->singleton(
			Health::class,
			static function ( Container $c ): Health {
				$store    = $c->get( SettingsStore::class );
				$resolver = static function ( string $source, string $subtype ) use ( $store ): bool {
					return match ( $source ) {
						'post'     => match ( $subtype ) {
							'post'  => (bool) $store->value( 'sources', 'posts_enabled' ),
							'page'  => (bool) $store->value( 'sources', 'pages_enabled' ),
							default => (bool) $store->value( 'sources', 'cpt_' . $subtype . '_enabled' ),
						},
						'menu'     => (bool) $store->value( 'sources', 'menus_enabled' ),
						'site'     => (bool) $store->value( 'sources', 'site_identity_enabled' ),
						'taxonomy' => (bool) $store->value( 'sources', 'taxonomies_enabled' ),
						'product'  => (bool) $store->value( 'sources', 'woocommerce_enabled' ),
						default    => true,
					};
				};

				return new Health( $c->get( AdapterRegistry::class ), $resolver, $c->get( Store::class ) );
			}
		);

		// KB roster agents.
		$c->singleton(
			KbCuratorAgent::class,
			static fn ( Container $c ): KbCuratorAgent => new KbCuratorAgent( $c->get( IndexManager::class ), $c->get( AdapterRegistry::class ) )
		);
		$c->singleton( ContentWatcherAgent::class, static fn (): ContentWatcherAgent => new ContentWatcherAgent() );
		$c->singleton(
			ReconcilerAgent::class,
			static fn ( Container $c ): ReconcilerAgent => new ReconcilerAgent( $c->get( IndexManager::class ) )
		);
		$c->singleton(
			RetrieverAgent::class,
			static fn ( Container $c ): RetrieverAgent => new RetrieverAgent( $c->get( HybridRetriever::class ) )
		);
		$c->singleton(
			LinkGrapherAgent::class,
			static fn ( Container $c ): LinkGrapherAgent => new LinkGrapherAgent( $c->get( LinkGraph::class ) )
		);

		// KB REST.
		$c->singleton(
			KbController::class,
			static fn ( Container $c ): KbController => new KbController( $c->get( Store::class ), $c->get( IndexManager::class ) )
		);
		$c->singleton(
			SearchController::class,
			static fn ( Container $c ): SearchController => new SearchController( $c->get( HybridRetriever::class ) )
		);

		// Chat: understanding services + stages.
		$c->singleton( LanguageDetector::class, static fn (): LanguageDetector => new LanguageDetector() );
		$c->singleton( EntityExtractor::class, static fn (): EntityExtractor => new EntityExtractor() );
		$c->singleton( IntentClassifier::class, static fn (): IntentClassifier => new IntentClassifier() );
		$c->singleton( Templates::class, static fn (): Templates => new Templates() );
		$c->singleton( StarterSuggestions::class, static fn (): StarterSuggestions => new StarterSuggestions() );
		$c->singleton( NormalizeStage::class, static fn (): NormalizeStage => new NormalizeStage() );
		$c->singleton(
			LanguageDetectStage::class,
			static fn ( Container $c ): LanguageDetectStage => new LanguageDetectStage( $c->get( LanguageDetector::class ) )
		);
		$c->singleton(
			IntentClassifyStage::class,
			static fn ( Container $c ): IntentClassifyStage => new IntentClassifyStage( $c->get( EntityExtractor::class ), $c->get( IntentClassifier::class ) )
		);
		$c->singleton(
			RouteStage::class,
			static fn ( Container $c ): RouteStage => new RouteStage( $c->get( Store::class ) )
		);
		$c->singleton(
			RetrieveStage::class,
			static fn ( Container $c ): RetrieveStage => new RetrieveStage(
				$c->get( HybridRetriever::class ),
				$c->get( EmbeddingRouter::class ),
				$c->get( VectorStore::class ),
				$c->get( QueryRewriter::class )
			)
		);
		$c->singleton(
			ScopeGuardStage::class,
			static fn ( Container $c ): ScopeGuardStage => new ScopeGuardStage(
				$c->get( HybridRetriever::class ),
				static fn (): array => $c->get( SettingsStore::class )->get( 'general' )
			)
		);
		$c->singleton(
			ComposeStage::class,
			static fn ( Container $c ): ComposeStage => new ComposeStage(
				$c->get( Templates::class ),
				$c->get( HybridRetriever::class ),
				$c->get( Tokenizer::class ),
				static fn (): array => $c->get( SettingsStore::class )->get( 'general' )
			)
		);
		$c->singleton(
			PostProcessStage::class,
			static fn ( Container $c ): PostProcessStage => new PostProcessStage(
				static fn (): array => $c->get( SettingsStore::class )->get( 'widget' )
			)
		);

		// AI layer (M7): signed registry, vault, providers, budget, router.
		$c->singleton( Manifest::class, static fn (): Manifest => new Manifest() );
		$c->singleton( Ed25519Verifier::class, static fn (): Ed25519Verifier => new Ed25519Verifier() );
		$c->singleton(
			RemoteSync::class,
			static fn ( Container $c ): RemoteSync => new RemoteSync( $c->get( Manifest::class ), $c->get( Ed25519Verifier::class ) )
		);
		$c->singleton( KeyVault::class, static fn (): KeyVault => new KeyVault() );
		$c->singleton( StreamingClient::class, static fn (): StreamingClient => new StreamingClient() );
		$models_settings = static fn ( Container $c ): callable => static fn (): array => $c->get( SettingsStore::class )->get( 'models' );
		$c->singleton(
			OpenAIProvider::class,
			static fn ( Container $c ): OpenAIProvider => new OpenAIProvider( $c->get( Manifest::class ), $c->get( KeyVault::class ), $c->get( StreamingClient::class ), $models_settings( $c ) )
		);
		$c->singleton(
			AnthropicProvider::class,
			static fn ( Container $c ): AnthropicProvider => new AnthropicProvider( $c->get( Manifest::class ), $c->get( KeyVault::class ), $c->get( StreamingClient::class ), $models_settings( $c ) )
		);
		$c->singleton(
			OpenAIEmbeddings::class,
			static fn ( Container $c ): OpenAIEmbeddings => new OpenAIEmbeddings( $c->get( Manifest::class ), $c->get( KeyVault::class ), $c->get( StreamingClient::class ), $models_settings( $c ) )
		);
		$c->singleton(
			BudgetManager::class,
			static fn ( Container $c ): BudgetManager => new BudgetManager( $c->get( Manifest::class ), $models_settings( $c ) )
		);
		$c->singleton( ResponseCache::class, static fn (): ResponseCache => new ResponseCache() );
		$c->singleton(
			LocalEndpointProvider::class,
			static fn ( Container $c ): LocalEndpointProvider => new LocalEndpointProvider( $c->get( KeyVault::class ), $c->get( StreamingClient::class ), $models_settings( $c ) )
		);
		$c->singleton(
			ProviderRouter::class,
			static fn ( Container $c ): ProviderRouter => new ProviderRouter(
				$c->get( BudgetManager::class ),
				array( $c->get( OpenAIProvider::class ), $c->get( AnthropicProvider::class ), $c->get( LocalEndpointProvider::class ) ),
				static fn (): array => $c->get( SettingsStore::class )->get( 'general' ),
				$models_settings( $c )
			)
		);
		// Dense retrieval (M8): embedding router + vector store + background indexer.
		$c->singleton(
			LocalEndpointEmbeddings::class,
			static fn ( Container $c ): LocalEndpointEmbeddings => new LocalEndpointEmbeddings( $c->get( LocalEndpointProvider::class ), $c->get( KeyVault::class ), $c->get( StreamingClient::class ), $models_settings( $c ) )
		);
		$c->singleton(
			EmbeddingRouter::class,
			static fn ( Container $c ): EmbeddingRouter => new EmbeddingRouter( array( $c->get( OpenAIEmbeddings::class ), $c->get( LocalEndpointEmbeddings::class ) ), $models_settings( $c ) )
		);
		$c->singleton( VectorStore::class, static fn (): VectorStore => new VectorStore() );
		$c->singleton(
			VectorIndexer::class,
			static fn ( Container $c ): VectorIndexer => new VectorIndexer( $c->get( EmbeddingRouter::class ), $c->get( VectorStore::class ) )
		);
		$c->singleton(
			QueryRewriter::class,
			static fn ( Container $c ): QueryRewriter => new QueryRewriter( $c->get( ProviderRouter::class ), $c->get( BudgetManager::class ) )
		);
		$c->singleton(
			ChatPromptBuilder::class,
			static fn ( Container $c ): ChatPromptBuilder => new ChatPromptBuilder( $c->get( Manifest::class ) )
		);
		$c->singleton(
			AiComposeStage::class,
			static fn ( Container $c ): AiComposeStage => new AiComposeStage(
				$c->get( ProviderRouter::class ),
				$c->get( BudgetManager::class ),
				$c->get( ResponseCache::class ),
				$c->get( ChatPromptBuilder::class ),
				static fn (): array => $c->get( SettingsStore::class )->get( 'general' ),
				static fn (): array => $c->get( SettingsStore::class )->get( 'language' ),
				static fn (): array => $c->get( SettingsStore::class )->get( 'privacy' ),
				$models_settings( $c )
			)
		);
		$c->singleton(
			ModelsController::class,
			static fn ( Container $c ): ModelsController => new ModelsController(
				$c->get( ProviderRouter::class ),
				$c->get( BudgetManager::class ),
				$c->get( ResponseCache::class ),
				$c->get( Manifest::class ),
				$c->get( RemoteSync::class ),
				$c->get( SettingsStore::class ),
				$c->get( SettingsSchema::class ),
				$c->get( StreamingClient::class ),
				$c->get( EmbeddingRouter::class ),
				$c->get( VectorStore::class ),
				$c->get( VectorIndexer::class )
			)
		);

		// Pipeline. Order matters: scope_guard reads the retrieval score; the
		// AI composer runs after the guard and before the classic composer,
		// which skips when an AI tier already (verifiably) answered.
		$c->singleton(
			Pipeline::class,
			static fn ( Container $c ): Pipeline => new Pipeline(
				array(
					$c->get( NormalizeStage::class ),
					$c->get( LanguageDetectStage::class ),
					$c->get( IntentClassifyStage::class ),
					$c->get( RouteStage::class ),
					$c->get( RetrieveStage::class ),
					$c->get( ScopeGuardStage::class ),
					$c->get( AiComposeStage::class ),
					$c->get( ComposeStage::class ),
					$c->get( PostProcessStage::class ),
				),
				$c->get( Journal::class )
			)
		);

		// Chat: sessions, limits, log, REST, widget.
		$c->singleton( SessionManager::class, static fn (): SessionManager => new SessionManager() );
		$c->singleton( RateLimiter::class, static fn (): RateLimiter => new RateLimiter() );
		$c->singleton( ConversationLog::class, static fn (): ConversationLog => new ConversationLog() );
		$c->singleton(
			ConfigController::class,
			static fn ( Container $c ): ConfigController => new ConfigController( $c->get( SettingsStore::class ) )
		);
		$c->singleton( Stats::class, static fn (): Stats => new Stats() );
		$c->singleton(
			StatsController::class,
			static fn ( Container $c ): StatsController => new StatsController( $c->get( Stats::class ) )
		);
		$c->singleton(
			ChatController::class,
			static fn ( Container $c ): ChatController => new ChatController(
				$c->get( SessionManager::class ),
				$c->get( RateLimiter::class ),
				$c->get( ConversationLog::class ),
				$c->get( Pipeline::class ),
				$c->get( SettingsStore::class ),
				$c->get( Stats::class )
			)
		);
		$c->singleton(
			WidgetLoader::class,
			static fn ( Container $c ): WidgetLoader => new WidgetLoader(
				static fn (): array => $c->get( SettingsStore::class )->get( 'widget' )
			)
		);

		// Compliance.
		$c->singleton( Consent::class, static fn (): Consent => new Consent() );
		$c->singleton( Dsar::class, static fn (): Dsar => new Dsar() );
		$c->singleton(
			AiAct::class,
			static fn ( Container $c ): AiAct => new AiAct( $c->get( SettingsStore::class ) )
		);
		$c->singleton(
			Retention::class,
			static fn ( Container $c ): Retention => new Retention(
				static fn (): array => $c->get( SettingsStore::class )->get( 'privacy' )
			)
		);
		$c->singleton(
			PrivacyController::class,
			static fn ( Container $c ): PrivacyController => new PrivacyController(
				$c->get( SessionManager::class ),
				$c->get( Consent::class ),
				$c->get( Dsar::class ),
				$c->get( AiAct::class ),
				$c->get( SettingsStore::class )
			)
		);

		// Chat roster agents.
		$c->singleton(
			SiteProfilerAgent::class,
			static fn ( Container $c ): SiteProfilerAgent => new SiteProfilerAgent( $c->get( SettingsStore::class ) )
		);
		$c->singleton( IntentRouterAgent::class, static fn (): IntentRouterAgent => new IntentRouterAgent() );
		$c->singleton( ComposerAgent::class, static fn (): ComposerAgent => new ComposerAgent() );
		$c->singleton( ScopeGuardAgent::class, static fn (): ScopeGuardAgent => new ScopeGuardAgent() );
		$c->singleton( LangBrokerAgent::class, static fn (): LangBrokerAgent => new LangBrokerAgent() );

		$c->singleton(
			Jobs::class,
			static fn ( Container $c ): Jobs => new Jobs(
				$c->get( Detector::class ),
				$c->get( Orchestrator::class ),
				$c->get( Health::class ),
				$c->get( Retention::class ),
				$c->get( Stats::class ),
				$c->get( RemoteSync::class ),
				$c->get( ResponseCache::class ),
				$c->get( BudgetManager::class ),
				$models_settings( $c )
			)
		);
		$c->singleton( Migrator::class, static fn (): Migrator => new Migrator() );

		$c->singleton(
			SettingsController::class,
			static fn ( Container $c ): SettingsController => new SettingsController( $c->get( SettingsStore::class ), $c->get( SettingsSchema::class ) )
		);
		$c->singleton(
			CapabilitiesController::class,
			static fn ( Container $c ): CapabilitiesController => new CapabilitiesController( $c->get( Detector::class ) )
		);
		$c->singleton(
			DashboardController::class,
			static fn ( Container $c ): DashboardController => new DashboardController(
				$c->get( Detector::class ),
				$c->get( SettingsStore::class ),
				$c->get( Stats::class ),
				$c->get( Registry::class ),
				$c->get( MemoryStore::class )
			)
		);
		$c->singleton( ProbeController::class, static fn (): ProbeController => new ProbeController() );
		$c->singleton( AddonCatalog::class, static fn (): AddonCatalog => new AddonCatalog() );
		$c->singleton(
			AddonsController::class,
			static fn ( Container $c ): AddonsController => new AddonsController( $c->get( AddonCatalog::class ) )
		);
		$c->singleton(
			AgentsController::class,
			static fn ( Container $c ): AgentsController => new AgentsController(
				$c->get( Registry::class ),
				$c->get( Quarantine::class ),
				$c->get( MemoryStore::class )
			)
		);

		// Copilot (M9): manual entries, action registry, orchestrator, ingestion.
		$c->singleton(
			ManualEntries::class,
			static fn ( Container $c ): ManualEntries => new ManualEntries( $c->get( Store::class ), $c->get( Chunker::class ) )
		);
		$c->singleton(
			ActionRegistry::class,
			static function ( Container $c ): ActionRegistry {
				$registry = new ActionRegistry();
				( new CoreActions(
					$c->get( ManualEntries::class ),
					$c->get( IndexManager::class ),
					$c->get( SettingsStore::class ),
					$c->get( SettingsSchema::class ),
					$c->get( MemoryStore::class ),
					$c->get( Stats::class )
				) )->register( $registry );

				return $registry;
			}
		);
		$c->singleton(
			CopilotBrain::class,
			static fn ( Container $c ): CopilotBrain => new CopilotBrain(
				$c->get( ProviderRouter::class ),
				$c->get( HybridRetriever::class ),
				$c->get( ActionRegistry::class ),
				static fn (): array => (array) $c->get( SettingsStore::class )->get( 'general' )
			)
		);
		$c->singleton(
			Copilot::class,
			static fn ( Container $c ): Copilot => new Copilot(
				$c->get( ActionRegistry::class ),
				$c->get( HybridRetriever::class ),
				$c->get( CopilotBrain::class )
			)
		);
		$c->singleton(
			FileIngest::class,
			static fn ( Container $c ): FileIngest => new FileIngest( $c->get( ManualEntries::class ) )
		);
		$c->singleton(
			CopilotController::class,
			static fn ( Container $c ): CopilotController => new CopilotController( $c->get( Copilot::class ), $c->get( FileIngest::class ), $c->get( Stats::class ) )
		);

		$c->singleton( Menu::class, static fn (): Menu => new Menu() );
		$c->singleton( Assets::class, static fn (): Assets => new Assets() );

		return $c;
	}
}
