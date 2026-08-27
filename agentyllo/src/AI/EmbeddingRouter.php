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
		// Setting values are short ids ('openai'); provider ids may carry a suffix.
		foreach ( $this->providers() as $id => $provider ) {
			if ( ( $id === $choice || str_starts_with( $id, $choice . '_' ) || str_starts_with( $id, $choice ) ) && $provider->is_available() ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Stable model key for the active provider (vector rows are tagged with it).
	 */
	public function model_key(): string {
		$provider = $this->active();
		if ( null === $provider ) {
			return '';
		}
		$settings = ( $this->models_resolver )();
		$field    = str_starts_with( $provider->id(), 'local' ) ? 'local_embedding_model' : 'openai_embedding_model';
		$model    = is_array( $settings ) ? (string) ( $settings[ $field ] ?? '' ) : '';

		$dims = $provider->dimensions();
		if ( 0 === $dims ) {
			// Local endpoints learn their dimensionality on the first call —
			// probe once so vectors are never tagged with an unknown size.
			$probe = $provider->embed( array( 'agentyllo' ) );
			$dims  = isset( $probe[0] ) && is_array( $probe[0] ) ? count( $probe[0] ) : 0;
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
