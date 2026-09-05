<?php
/**
 * Detached Ed25519 signature verification for the remote registry.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * The registry publishes stable.json + stable.json.sig (base64 detached
 * signature over the exact payload bytes). Only DATA is ever accepted from
 * the registry — model ids, prices, prompt versions, widget maps — never
 * code (WP.org guideline 8). The public key is pinned here; the filter lets
 * a self-hosted registry (enterprise/airgapped) pin its own.
 */
final class Ed25519Verifier {

	/**
	 * Base64 Ed25519 public key of registry.agentyllo.com.
	 */
	public const PUBLIC_KEY = 'Eq4wXSR3uRhLsdV+9zJP1HJ28ELXyLUTbN2OSp3gHss=';

	/**
	 * Verify a detached signature.
	 *
	 * @param string $payload      Raw payload bytes.
	 * @param string $signature_b64 Base64 signature.
	 */
	public function verify( string $payload, string $signature_b64 ): bool {
		/**
		 * Filter the registry signing public key (base64). Self-hosted
		 * registries pin their own key here.
		 *
		 * @param string $public_key Base64 Ed25519 public key.
		 */
		$key_b64 = (string) apply_filters( 'agyl_registry_public_key', self::PUBLIC_KEY );

		$key = base64_decode( $key_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$sig = base64_decode( trim( $signature_b64 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $key || false === $sig ) {
			return false;
		}
		if ( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $sig, $payload, $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
