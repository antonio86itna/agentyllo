<?php
/**
 * Admin REST: AI models, providers, registry, budget.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Admin\Settings\SettingsSchema;
use Agentyllo\Admin\Settings\SettingsStore;
use Agentyllo\AI\Budget\Manager;
use Agentyllo\AI\Budget\ResponseCache;
use Agentyllo\AI\EmbeddingRouter;
use Agentyllo\AI\ProviderRouter;
use Agentyllo\AI\Providers\CloudProvider;
use Agentyllo\AI\Providers\LocalEndpointProvider;
use Agentyllo\KB\Indexer\VectorIndexer;
use Agentyllo\KB\Retrieval\VectorStore;
use Agentyllo\Infra\Http\StreamingClient;
use Agentyllo\Registry\Manifest;
use Agentyllo\Registry\RemoteSync;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Backs the AI Models page: registry models per provider, key status
 * (masked — never the key), live circuit/usage telemetry, connection tests,
 * registry sync. Settings themselves are written through /settings/models.
 */
final class ModelsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param ProviderRouter  $router   Provider router.
	 * @param Manager         $budget   Budget manager.
	 * @param ResponseCache   $cache    Response cache.
	 * @param Manifest        $manifest Registry manifest.
	 * @param RemoteSync      $sync     Registry sync.
	 * @param SettingsStore   $settings Settings store.
	 * @param SettingsSchema  $schema   Settings schema (redaction).
	 * @param StreamingClient $http     HTTP client (capability flag).
	 */
	public function __construct(
		private readonly ProviderRouter $router,
		private readonly Manager $budget,
		private readonly ResponseCache $cache,
		private readonly Manifest $manifest,
		private readonly RemoteSync $sync,
		private readonly SettingsStore $settings,
		private readonly SettingsSchema $schema,
		private readonly StreamingClient $http,
		private readonly EmbeddingRouter $embeddings,
		private readonly VectorStore $vectors,
		private readonly VectorIndexer $vector_indexer,
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/models',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_overview' ),
				'permission_callback' => $this->require_cap( 'agy_manage_settings' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/models/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_test' ),
				'permission_callback' => $this->require_cap( 'agy_manage_settings' ),
				'args'                => array(
					'provider' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'openai', 'anthropic', 'local_endpoint' ),
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/models/registry-sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_registry_sync' ),
				'permission_callback' => $this->require_cap( 'agy_manage_settings' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/models/circuit-reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_circuit_reset' ),
				'permission_callback' => $this->require_cap( 'agy_manage_settings' ),
				'args'                => array(
					'provider' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/models/embed-now',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_embed_now' ),
				'permission_callback' => $this->require_cap( 'agy_manage_settings' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/models/cache-flush',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_cache_flush' ),
				'permission_callback' => $this->require_cap( 'agy_manage_settings' ),
			)
		);
	}

	/**
	 * GET /models.
	 */
	public function get_overview(): WP_REST_Response {
		$values = $this->settings->get( 'models' );
		$masked = $this->schema->redact( 'models', $values );
		$stats  = $this->budget->provider_stats();

		$providers = array();
		foreach ( array( 'openai', 'anthropic' ) as $id ) {
			$block    = $this->manifest->provider( $id );
			$provider = $this->router->provider( $id );

			$providers[ $id ] = array(
				'label'            => (string) ( $block['label'] ?? ucfirst( $id ) ),
				'keys_url'         => (string) ( $block['keys_url'] ?? '' ),
				'key_prefix'       => (string) ( $block['key_prefix'] ?? '' ),
				'key_masked'       => (string) ( $masked[ $id . '_api_key' ] ?? '' ),
				'has_key'          => '' !== (string) ( $values[ $id . '_api_key' ] ?? '' ),
				'key_corrupt'      => '!corrupt' === (string) ( $masked[ $id . '_api_key' ] ?? '' ),
				'available'        => null !== $provider && $provider->is_available(),
				'key_source'       => $provider instanceof CloudProvider ? $provider->key_source() : '',
				'chat_models'      => $this->manifest->chat_models( $id ),
				'embedding_models' => $this->manifest->embedding_models( $id ),
				'default_model'    => $this->manifest->default_chat_model( $id ),
				'circuit'          => $this->budget->circuit_state( $id ),
				'stats'            => $stats[ $id ] ?? null,
			);
		}

		$registry_stored = get_option( Manifest::OPTION );
		$manifest        = $this->manifest->data();

		$local     = $this->router->provider( LocalEndpointProvider::ID );
		$local_url = $local instanceof LocalEndpointProvider ? $local->base_url() : '';
		$vec_state = get_option( 'agy_kb_vectors_status' );
		$vec_model = $this->embeddings->model_key();

		return $this->respond(
			array(
				'status'    => $this->router->status(),
				'settings'  => $masked,
				'schema'    => $this->schema->describe( 'models' ),
				'providers' => $providers,
				'usage'     => $this->budget->month_usage() + array( 'cap_usd' => (float) ( $values['monthly_cost_cap_usd'] ?? 0 ) ),
				'recent'    => $this->budget->recent( 15 ),
				'registry'  => array(
					'origin'       => $this->manifest->origin(),
					'sequence'     => $this->manifest->sequence(),
					'generated_at' => (string) ( $manifest['generated_at'] ?? '' ),
					'synced_at'    => is_array( $registry_stored ) ? (int) ( $registry_stored['synced_at'] ?? 0 ) : 0,
					'last_sync'    => $this->sync->status(),
					'url'          => (string) apply_filters( 'agy_registry_url', RemoteSync::DEFAULT_URL ),
				),
				'transport' => array(
					'streaming_capable' => $this->http->supports_streaming(),
					'curl'              => function_exists( 'curl_init' ),
				),
				'local'     => array(
					'url'        => $local_url,
					'model'      => $local instanceof LocalEndpointProvider ? $local->model_id() : '',
					'available'  => null !== $local && $local->is_available(),
					'has_key'    => '' !== (string) ( $values['local_api_key'] ?? '' ),
					'key_masked' => (string) ( $masked['local_api_key'] ?? '' ),
					'ema_tps'    => $this->budget->ema_tps( LocalEndpointProvider::ID ),
					'min_tps'    => (int) ( $values['local_min_tok_s'] ?? 8 ),
					'circuit'    => $this->budget->circuit_state( LocalEndpointProvider::ID ),
					'stats'      => $stats[ LocalEndpointProvider::ID ] ?? null,
				),
				'vectors'   => array(
					'provider'  => $this->embeddings->active()?->id() ?? '',
					'model_key' => $vec_model,
					'count'     => '' !== $vec_model ? $this->vectors->count( $vec_model ) : 0,
					'remaining' => is_array( $vec_state ) && ( $vec_state['model'] ?? '' ) === $vec_model ? (int) ( $vec_state['remaining'] ?? 0 ) : null,
					'ran_at'    => is_array( $vec_state ) ? (int) ( $vec_state['ran_at'] ?? 0 ) : 0,
				),
			)
		);
	}

	/**
	 * POST /models/test — minimal completion with the saved key.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id       = (string) $request['provider'];
		$provider = $this->router->provider( $id );
		if ( null === $provider ) {
			return new WP_Error( 'agy_unknown_provider', __( 'Unknown provider.', 'agentyllo' ), array( 'status' => 404 ) );
		}

		$result = $provider->test_connection();
		if ( $result['ok'] ) {
			$this->budget->reset_circuit( $id );
		}

		return $this->respond( $result );
	}

	/**
	 * POST /models/registry-sync.
	 */
	public function post_registry_sync(): WP_REST_Response {
		return $this->respond( $this->sync->sync() );
	}

	/**
	 * POST /models/circuit-reset.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_circuit_reset( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_key( (string) $request['provider'] );
		$this->budget->reset_circuit( $id );

		return $this->respond( array( 'ok' => true, 'circuit' => $this->budget->circuit_state( $id ) ) );
	}

	/**
	 * POST /models/embed-now — run one embedding pass synchronously (admin
	 * click) and report progress.
	 */
	public function post_embed_now(): WP_REST_Response|WP_Error {
		if ( null === $this->embeddings->active() ) {
			return new WP_Error( 'agy_no_embeddings', __( 'No embedding provider is configured.', 'agentyllo' ), array( 'status' => 400 ) );
		}
		$result = $this->vector_indexer->run();
		if ( $result['remaining'] > 0 ) {
			$this->vector_indexer->schedule();
		}

		return $this->respond( $result + array( 'count' => $this->vectors->count( $this->embeddings->model_key() ) ) );
	}

	/**
	 * POST /models/cache-flush.
	 */
	public function post_cache_flush(): WP_REST_Response {
		$this->cache->flush();

		return $this->respond( array( 'ok' => true ) );
	}
}
