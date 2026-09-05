<?php
/**
 * Selects the active embedding provider.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI;

use Agentyllo\AI\Contracts\EmbeddingProvider;

defined( 'ABSPATH' ) || exit;

/**
 * The `embedding_provider` setting names the provider (openai | onnx | …);
 * core ships OpenAI, local ONNX providers register through
 * `agyl_embedding_providers` (Local AI companion). Query embeddings are
 * memoized per request; the query-embedding cache (transient, 1h) keeps
 * repeated visitor questions from paying twice.
 */
final class EmbeddingRouter {

	/**
	 * Providers keyed by id.
	 *
	 * @var array<string, EmbeddingProvider>
	 */
	private array $providers = array();

	/**
	 * Whether the filter has run.
	 */
	private bool $filtered = false;

	/**
	 * Resolver returning the 'models' settings array.
	 *
	 * @var callable
	 */
	private $models_resolver;

	/**
	 * Constructor.
	 *
	 * @param EmbeddingProvider[] $core            Core providers.
	 * @param callable            $models_resolver Returns 'models' settings.
	 */
	public function __construct( array $core, callable $models_resolver ) {
		foreach ( $core as $provider ) {
			if ( $provider instanceof EmbeddingProvider ) {
				$this->providers[ $provider->id() ] = $provider;
			}
		}
		$this->models_resolver = $models_resolver;
	}

	/**
	 * All registered providers (after the filter).
	 *
	 * @return array<string, EmbeddingProvider>
	 */
	public function providers(): array {
		if ( ! $this->filtered ) {
			$this->filtered = true;
			/**
			 * Filter the registered embedding providers.
			 *
			 * @param EmbeddingProvider[] $providers Providers keyed by id.
			 */
			foreach ( (array) apply_filters( 'agyl_embedding_providers', $this->providers ) as $provider ) {
				if ( $provider instanceof EmbeddingProvider ) {
					$this->providers[ $provider->id() ] = $provider;
				}
			}
		}

		return $this->providers;
	}

	/**
	 * The configured, available provider — or null (lexical retrieval only).
	 */
	public function active(): ?EmbeddingProvider {
		$settings = ( $this->models_resolver )();
		$choice   = is_array( $settings ) ? (string) ( $settings['embedding_provider'] ?? 'none' ) : 'none';
		if ( 'none' === $choice || '' === $choice ) {
			return null;
		}
		// Setting values are short ids ('openai'); provider ids may carry a
		// suffix separated by '_' (e.g. 'local_endpoint'). Match the exact id
		// or an id in the '<choice>_*' family — never a bare prefix, which
		// could collide with unrelated future provider ids.
		foreach ( $this->providers() as $id => $provider ) {
			if ( ( $id === $choice || str_starts_with( $id, $choice . '_' ) ) && $provider->is_available() ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Stable model key for the active provider (vector rows are tagged with it).
	 *
	 * @param bool $allow_probe When the dimensionality is unknown, whether to
	 *                          make a one-off network probe to learn it. The
	 *                          admin overview passes false so opening AI Models
	 *                          never blocks on a slow/hung local endpoint; the
	 *                          indexer passes true. Once learned, the size is
	 *                          cached so the probe happens at most once.
	 */
	public function model_key( bool $allow_probe = true ): string {
		$provider = $this->active();
		if ( null === $provider ) {
			return '';
		}
		$settings = ( $this->models_resolver )();
		$field    = str_starts_with( $provider->id(), 'local' ) ? 'local_embedding_model' : 'openai_embedding_model';
		$model    = is_array( $settings ) ? (string) ( $settings[ $field ] ?? '' ) : '';

		$cache_key = 'agyl_embed_dims_' . $provider->id() . '_' . md5( $model );
		$dims = $provider->dimensions();
		if ( 0 === $dims ) {
			$dims = (int) get_option( $cache_key, 0 );
		}
		if ( 0 === $dims && $allow_probe ) {
			// Local endpoints learn their dimensionality on the first call —
			// probe once, then cache so it never blocks again.
			$probe = $provider->embed( array( 'agentyllo' ) );
			$dims  = isset( $probe[0] ) && is_array( $probe[0] ) ? count( $probe[0] ) : 0;
			if ( $dims > 0 ) {
				update_option( $cache_key, $dims, false );
			}
		}

		return $provider->id() . ':' . ( '' !== $model ? $model : 'default' ) . ':' . $dims;
	}

	/**
	 * Embed one query (transient-cached 1h). Empty array on failure.
	 *
	 * @param string $text Query text.
	 * @return float[]
	 */
	public function embed_query( string $text ): array {
		$provider = $this->active();
		if ( null === $provider ) {
			return array();
		}
		$key    = 'agyl_qv_' . md5( $this->model_key() . '|' . mb_strtolower( trim( $text ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
		$vectors = $provider->embed( array( $text ) );
		$vector  = $vectors[0] ?? array();
		if ( $vector ) {
			set_transient( $key, $vector, HOUR_IN_SECONDS );
		}

		return $vector;
	}
}
