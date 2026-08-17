<?php
/**
 * OpenAI embeddings provider.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Providers;

use Agentyllo\AI\Contracts\EmbeddingProvider;
use Agentyllo\Infra\Crypto\KeyVault;
use Agentyllo\Infra\Http\StreamingClient;
use Agentyllo\Registry\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * POST {base_url}/embeddings. Batches are capped at 64 inputs / ~6k chars
 * each; failures return an empty array (callers keep the BM25 floor).
 */
final class OpenAIEmbeddings implements EmbeddingProvider {

	public const ID = 'openai_embeddings';

	private const MAX_BATCH = 64;
	private const MAX_CHARS = 6000;

	/**
	 * Resolver returning the current 'models' settings array.
	 *
	 * @var callable
	 */
	private $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param Manifest        $manifest          Registry manifest.
	 * @param KeyVault        $vault             Secret vault.
	 * @param StreamingClient $http              HTTP client.
	 * @param callable        $settings_resolver Returns the 'models' settings tab.
	 */
	public function __construct(
		private readonly Manifest $manifest,
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
		return '' !== $this->api_key() && null !== $this->model();
	}

	/**
	 * {@inheritDoc}
	 */
	public function dimensions(): int {
		return (int) ( $this->model()['dimensions'] ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_multilingual(): bool {
		return (bool) ( $this->model()['multilingual'] ?? true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function embed( array $texts ): array {
		$key   = $this->api_key();
		$model = $this->model();
		if ( '' === $key || null === $model || ! $texts ) {
			return array();
		}
		$base = rtrim( (string) ( $this->manifest->provider( OpenAIProvider::ID )['base_url'] ?? 'https://api.openai.com/v1' ), '/' );

		$out = array();
		foreach ( array_chunk( array_values( $texts ), self::MAX_BATCH ) as $batch ) {
			$input = array_map( static fn ( $t ): string => mb_substr( (string) $t, 0, self::MAX_CHARS ), $batch );

			$http = $this->http->post_json(
				$base . '/embeddings',
				array( 'Authorization: Bearer ' . $key ),
				array(
					'model' => (string) $model['id'],
					'input' => $input,
				),
				30.0
			);
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
				if ( ! is_array( $vector ) ) {
					return array();
				}
				$out[] = array_map( 'floatval', $vector );
			}
		}

		return $out;
	}

	/**
	 * Effective embedding model definition.
	 *
	 * @return array<string, mixed>|null
	 */
	private function model(): ?array {
		$settings   = ( $this->settings_resolver )();
		$configured = is_array( $settings ) ? (string) ( $settings['openai_embedding_model'] ?? '' ) : '';
		if ( '' !== $configured ) {
			$model = $this->manifest->embedding_model( OpenAIProvider::ID, $configured );
			if ( null !== $model ) {
				return $model;
			}
		}
		$default = $this->manifest->default_embedding_model( OpenAIProvider::ID );

		return '' === $default ? null : $this->manifest->embedding_model( OpenAIProvider::ID, $default );
	}

	/**
	 * Decrypted OpenAI key.
	 */
	private function api_key(): string {
		$settings = ( $this->settings_resolver )();
		$sealed   = is_array( $settings ) ? (string) ( $settings['openai_api_key'] ?? '' ) : '';

		if ( '' !== $sealed ) {
			return (string) ( $this->vault->open( $sealed ) ?? '' );
		}

		return CloudProvider::environment_key( OpenAIProvider::ID );
	}
}
