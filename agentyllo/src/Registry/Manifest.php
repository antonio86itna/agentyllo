<?php
/**
 * Typed read access to the (signed) registry manifest.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for cloud model ids, prices, capability flags,
 * prompt-pack versions and (M8) local engines/models. Model ids are NEVER
 * hardcoded in providers: they come from here, so a vendor rename is a
 * registry sync — not a plugin update.
 *
 * Resolution order: verified remote snapshot (option agy_registry) → bundled
 * assets/registry/stable.json → empty manifest (providers report "no
 * models"; the classic floor is unaffected).
 */
final class Manifest {

	public const OPTION = 'agy_registry';

	/**
	 * Decoded manifest.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $data = null;

	/**
	 * Load the effective manifest (memoized per request).
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		if ( null !== $this->data ) {
			return $this->data;
		}

		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && ! empty( $stored['payload'] ) && is_array( $stored['payload'] ) ) {
			$this->data = $stored['payload'];
		} else {
			$this->data = self::bundled();
		}

		/**
		 * Filter the effective registry manifest (data only). Addons and the
		 * Local AI companion append engines/models here.
		 *
		 * @param array $manifest Manifest array.
		 */
		$this->data = (array) apply_filters( 'agy_registry_manifest', $this->data );

		return $this->data;
	}

	/**
	 * Drop the memoized manifest (after a sync).
	 */
	public function reset(): void {
		$this->data = null;
	}

	/**
	 * Bundled snapshot shipped with the plugin.
	 *
	 * @return array<string, mixed>
	 */
	public static function bundled(): array {
		$file = AGY_DIR . 'assets/registry/stable.json';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Manifest sequence number (anti-rollback).
	 */
	public function sequence(): int {
		return (int) ( $this->data()['sequence'] ?? 0 );
	}

	/**
	 * Where the effective manifest came from: 'remote' | 'bundled' | 'none'.
	 */
	public function origin(): string {
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && ! empty( $stored['payload'] ) ) {
			return 'remote';
		}

		return $this->data() ? 'bundled' : 'none';
	}

	/**
	 * Provider ids present in the manifest.
	 *
	 * @return string[]
	 */
	public function providers(): array {
		return array_keys( (array) ( $this->data()['providers'] ?? array() ) );
	}

	/**
	 * Provider block.
	 *
	 * @param string $provider Provider id.
	 * @return array<string, mixed>
	 */
	public function provider( string $provider ): array {
		$block = $this->data()['providers'][ $provider ] ?? array();

		return is_array( $block ) ? $block : array();
	}

	/**
	 * Chat models for a provider (each: id, label, hint, price_in/out, flags).
	 *
	 * @param string $provider Provider id.
	 * @return array<int, array<string, mixed>>
	 */
	public function chat_models( string $provider ): array {
		return $this->models( $provider, 'chat_models' );
	}

	/**
	 * Embedding models for a provider.
	 *
	 * @param string $provider Provider id.
	 * @return array<int, array<string, mixed>>
	 */
	public function embedding_models( string $provider ): array {
		return $this->models( $provider, 'embedding_models' );
	}

	/**
	 * One chat model definition (null when unknown to the registry).
	 *
	 * @param string $provider Provider id.
	 * @param string $model_id Model id.
	 * @return array<string, mixed>|null
	 */
	public function chat_model( string $provider, string $model_id ): ?array {
		foreach ( $this->chat_models( $provider ) as $model ) {
			if ( (string) ( $model['id'] ?? '' ) === $model_id ) {
				return $model;
			}
		}

		return null;
	}

	/**
	 * One embedding model definition.
	 *
	 * @param string $provider Provider id.
	 * @param string $model_id Model id.
	 * @return array<string, mixed>|null
	 */
	public function embedding_model( string $provider, string $model_id ): ?array {
		foreach ( $this->embedding_models( $provider ) as $model ) {
			if ( (string) ( $model['id'] ?? '' ) === $model_id ) {
				return $model;
			}
		}

		return null;
	}

	/**
	 * Default chat model id for a provider ('' when none).
	 *
	 * @param string $provider Provider id.
	 */
	public function default_chat_model( string $provider ): string {
		return $this->default_of( $this->chat_models( $provider ) );
	}

	/**
	 * Default embedding model id for a provider ('' when none).
	 *
	 * @param string $provider Provider id.
	 */
	public function default_embedding_model( string $provider ): string {
		return $this->default_of( $this->embedding_models( $provider ) );
	}

	/**
	 * Resolve the effective chat model: the configured id when the registry
	 * knows it, else the provider default. Never returns an unknown id.
	 *
	 * @param string $provider   Provider id.
	 * @param string $configured Configured model id ('' = default).
	 * @return array<string, mixed>|null
	 */
	public function resolve_chat_model( string $provider, string $configured ): ?array {
		if ( '' !== $configured ) {
			$model = $this->chat_model( $provider, $configured );
			if ( null !== $model ) {
				return $model;
			}
		}
		$default = $this->default_chat_model( $provider );

		return '' === $default ? null : $this->chat_model( $provider, $default );
	}

	/**
	 * Estimated USD cost for a token pair (0.0 when pricing unknown).
	 *
	 * @param array<string, mixed> $model      Model definition.
	 * @param int                  $tokens_in  Prompt tokens.
	 * @param int                  $tokens_out Completion tokens.
	 */
	public static function cost( array $model, int $tokens_in, int $tokens_out ): float {
		$in  = isset( $model['price_in'] ) ? (float) $model['price_in'] : 0.0;
		$out = isset( $model['price_out'] ) ? (float) $model['price_out'] : 0.0;

		return round( ( $tokens_in * $in + $tokens_out * $out ) / 1_000_000, 6 );
	}

	/**
	 * Prompt pack version for a prompt id ('1' when unspecified).
	 *
	 * @param string $prompt Prompt id (e.g. 'chat_rag').
	 */
	public function prompt_version( string $prompt ): string {
		$version = $this->data()['prompts'][ $prompt ]['version'] ?? '1';

		return is_scalar( $version ) ? (string) $version : '1';
	}

	/**
	 * Models list of a kind for a provider.
	 *
	 * @param string $provider Provider id.
	 * @param string $kind     'chat_models' | 'embedding_models'.
	 * @return array<int, array<string, mixed>>
	 */
	private function models( string $provider, string $kind ): array {
		$list = $this->provider( $provider )[ $kind ] ?? array();
		if ( ! is_array( $list ) ) {
			return array();
		}

		return array_values( array_filter( $list, static fn ( $m ): bool => is_array( $m ) && '' !== (string) ( $m['id'] ?? '' ) ) );
	}

	/**
	 * Id of the model flagged default, else the first one, else ''.
	 *
	 * @param array<int, array<string, mixed>> $models Models.
	 */
	private function default_of( array $models ): string {
		foreach ( $models as $model ) {
			if ( ! empty( $model['default'] ) ) {
				return (string) $model['id'];
			}
		}

		return $models ? (string) $models[0]['id'] : '';
	}
}
