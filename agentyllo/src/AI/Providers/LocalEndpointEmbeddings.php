<?php
/**
 * Embeddings from the BYO local endpoint (/v1/embeddings).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Providers;

use Agentyllo\AI\Contracts\EmbeddingProvider;
use Agentyllo\Infra\Crypto\KeyVault;
use Agentyllo\Infra\Http\StreamingClient;

defined( 'ABSPATH' ) || exit;

/**
 * llama.cpp `llama-server --embeddings` (nomic-embed, bge-m3, e5 GGUF),
 * Ollama (`/v1/embeddings` with an embedding model) and LM Studio all speak
 * the OpenAI embeddings shape. This is the free dense-retrieval path that
 * needs no ONNX runtime inside WordPress: the model runs in the server the
 * owner (or the Local AI companion) already operates. Dimensions are
 * discovered on first call and cached.
 */
final class LocalEndpointEmbeddings implements EmbeddingProvider {

	public const ID = 'local_embeddings';

	private const MAX_BATCH = 32;
	private const MAX_CHARS = 4000;

	/**
	 * Resolver returning the 'models' settings array.
	 *
	 * @var callable
	 */
	private $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param LocalEndpointProvider $endpoint          Shares URL/key with the chat endpoint.
	 * @param KeyVault              $vault             Secret vault.
	 * @param StreamingClient       $http              HTTP client.
	 * @param callable              $settings_resolver Returns the 'models' settings tab.
	 */
	public function __construct(
		private readonly LocalEndpointProvider $endpoint,
		private readonly KeyVault $vault,
		private readonly StreamingClient $http,
		callable $settings_resolver
	) {
		$this->settings_resolver = $settings_resolver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return '' !== $this->endpoint->base_url();
	}

	/**
	 * {@inheritDoc}
	 */
	public function dimensions(): int {
		$cached = get_option( 'agy_local_embed_dims' );

		return is_array( $cached ) && ( $cached['model'] ?? '' ) === $this->model_id() ? (int) ( $cached['dims'] ?? 0 ) : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_multilingual(): bool {
		return true; // Owner-chosen model; multilingual embeddings are the recommended default.
	}

	/**
	 * {@inheritDoc}
	 */
	public function embed( array $texts ): array {
		$base = $this->endpoint->base_url();
		if ( '' === $base || ! $texts ) {
			return array();
		}
		$headers = array();
		$sealed  = (string) ( $this->settings()['local_api_key'] ?? '' );
		$key     = '' === $sealed ? '' : (string) ( $this->vault->open( $sealed ) ?? '' );
		if ( '' !== $key ) {
			$headers[] = 'Authorization: Bearer ' . $key;
		}

		$out = array();
		foreach ( array_chunk( array_values( $texts ), self::MAX_BATCH ) as $batch ) {
			$body = array( 'input' => array_map( static fn ( $t ): string => mb_substr( (string) $t, 0, self::MAX_CHARS ), $batch ) );
			if ( '' !== $this->model_id() ) {
				$body['model'] = $this->model_id();
			}
			$http = $this->http->post_json( $base . '/v1/embeddings', $headers, $body, 60.0 );
			if ( null !== $http['error'] || 200 !== (int) $http['status'] ) {
				return array();
			}
			$decoded = json_decode( (string) $http['body'], true );
			$rows    = is_array( $decoded ) ? (array) ( $decoded['data'] ?? array() ) : array();
			if ( count( $rows ) !== count( $batch ) ) {
				return array();
			}
			usort( $rows, static fn ( array $a, array $b ): int => (int) ( $a['index'] ?? 0 ) <=> (int) ( $b['index'] ?? 0 ) );
			foreach ( $rows as $row ) {
				$vector = $row['embedding'] ?? null;
				if ( ! is_array( $vector ) || ! $vector ) {
					return array();
				}
				$out[] = array_map( 'floatval', $vector );
			}
		}

		if ( 0 === $this->dimensions() ) {
			update_option(
				'agy_local_embed_dims',
				array(
					'model' => $this->model_id(),
					'dims'  => count( $out[0] ),
				),
				false
			);
		}

		return $out;
	}

	/**
	 * Configured embedding model id ('' = server default).
	 */
	private function model_id(): string {
		return trim( (string) ( $this->settings()['local_embedding_model'] ?? '' ) );
	}

	/**
	 * Current 'models' settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$settings = ( $this->settings_resolver )();

		return is_array( $settings ) ? $settings : array();
	}
}
