<?php
/**
 * Encrypted secret storage for provider API keys.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * libsodium secretbox with a DEDICATED random key stored in its own option
 * (agy_vault_key). Never derived from WP salts: hosts and security plugins
 * rotate salts, which would silently brick every stored key. A decrypt
 * failure returns null (caller prompts re-entry) — never a fatal.
 * sodium_compat (bundled with WP core) covers hosts without ext/sodium.
 */
final class KeyVault {

	private const KEY_OPTION = 'agy_vault_key';
	public const PREFIX      = 'agyv1:';

	/**
	 * Encrypt a secret for storage. Returns '' for empty input.
	 *
	 * @param string $plain Secret.
	 */
	public function seal( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$key   = $this->key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, $key );

		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret. Null when missing, tampered, or the vault key
	 * changed (caller shows "re-enter your key").
	 *
	 * @param string $sealed Stored value.
	 */
	public function open( string $sealed ): ?string {
		if ( '' === $sealed ) {
			return null;
		}
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plain = sodium_crypto_secretbox_open( $box, $nonce, $this->key() );
		} catch ( \Throwable $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}

	/**
	 * Masked preview for the UI (never the full key).
	 *
	 * @param string $sealed Stored value.
	 */
	public function mask( string $sealed ): string {
		$plain = $this->open( $sealed );
		if ( null === $plain ) {
			return '' === $sealed ? '' : '••••••••';
		}
		$len = strlen( $plain );

		return $len <= 8 ? str_repeat( '•', $len ) : substr( $plain, 0, 3 ) . str_repeat( '•', 8 ) . substr( $plain, -4 );
	}

	/**
	 * Whether a stored value exists but can no longer be decrypted.
	 *
	 * @param string $sealed Stored value.
	 */
	public function is_corrupt( string $sealed ): bool {
		return '' !== $sealed && null === $this->open( $sealed );
	}

	/**
	 * Vault key, created on first use.
	 */
	private function key(): string {
		$stored = get_option( self::KEY_OPTION );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false !== $decoded && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $decoded ) ) {
				return $decoded;
			}
		}
		$key = sodium_crypto_secretbox_keygen();
		add_option( self::KEY_OPTION, base64_encode( $key ), '', false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return $key;
	}
}
