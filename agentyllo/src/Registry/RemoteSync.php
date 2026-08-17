<?php
/**
 * Weekly signed sync of the remote registry manifest.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Registry;

use Agentyllo\Infra\Crypto\Ed25519Verifier;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches stable.json + stable.json.sig from registry.agentyllo.com, verifies
 * the Ed25519 signature over the raw bytes, refuses rollbacks (sequence must
 * increase) and stores the payload in the agy_registry option. Every failure
 * is recorded (for the AI Models page) and leaves the current manifest in
 * place — a broken registry can never break a site.
 *
 * External service disclosure (readme "External Services"): the request
 * carries no site data beyond the plugin version in the User-Agent.
 */
final class RemoteSync {

	public const DEFAULT_URL   = 'https://registry.agentyllo.com/v1/stable.json';
	public const STATUS_OPTION = 'agy_registry_sync';
	private const MAX_BYTES    = 512 * 1024;
	private const TIMEOUT      = 15;

	/**
	 * Constructor.
	 *
	 * @param Manifest         $manifest Manifest reader (reset after a sync).
	 * @param Ed25519Verifier  $verifier Signature verifier.
	 */
	public function __construct(
		private readonly Manifest $manifest,
		private readonly Ed25519Verifier $verifier,
	) {
	}

	/**
	 * Run one sync. Returns {ok, message, sequence}.
	 *
	 * @return array{ok: bool, message: string, sequence: int}
	 */
	public function sync(): array {
		/**
		 * Filter the registry manifest URL. Self-hosted registries override
		 * this together with `agy_registry_public_key`.
		 *
		 * @param string $url Manifest URL.
		 */
		$url = (string) apply_filters( 'agy_registry_url', self::DEFAULT_URL );

		$payload = $this->fetch( $url );
		if ( null === $payload ) {
			return $this->finish( false, __( 'Registry unreachable.', 'agentyllo' ) );
		}

		$signature = $this->fetch( $url . '.sig' );
		if ( null === $signature ) {
			return $this->finish( false, __( 'Registry signature unavailable.', 'agentyllo' ) );
		}

		if ( ! $this->verifier->verify( $payload, $signature ) ) {
			return $this->finish( false, __( 'Registry signature verification failed — manifest rejected.', 'agentyllo' ) );
		}

		$decoded = json_decode( $payload, true );
		if ( ! is_array( $decoded ) || (int) ( $decoded['schema'] ?? 0 ) !== 1 || ! isset( $decoded['providers'] ) ) {
			return $this->finish( false, __( 'Registry manifest malformed.', 'agentyllo' ) );
		}

		$incoming = (int) ( $decoded['sequence'] ?? 0 );
		$current  = $this->manifest->sequence();
		if ( $incoming < $current ) {
			return $this->finish( false, __( 'Registry rollback rejected (older sequence).', 'agentyllo' ), $incoming );
		}

		update_option(
			Manifest::OPTION,
			array(
				'payload'   => $decoded,
				'sequence'  => $incoming,
				'synced_at' => time(),
			),
			false
		);
		$this->manifest->reset();

		return $this->finish( true, __( 'Registry synced.', 'agentyllo' ), $incoming );
	}

	/**
	 * Last sync status for the UI.
	 *
	 * @return array{ok: bool, message: string, sequence: int, at: int}
	 */
	public function status(): array {
		$stored = get_option( self::STATUS_OPTION );
		if ( ! is_array( $stored ) ) {
			return array(
				'ok'       => false,
				'message'  => '',
				'sequence' => 0,
				'at'       => 0,
			);
		}

		return array(
			'ok'       => (bool) ( $stored['ok'] ?? false ),
			'message'  => (string) ( $stored['message'] ?? '' ),
			'sequence' => (int) ( $stored['sequence'] ?? 0 ),
			'at'       => (int) ( $stored['at'] ?? 0 ),
		);
	}

	/**
	 * GET a small text resource; null on any failure.
	 *
	 * @param string $url URL.
	 */
	private function fetch( string $url ): ?string {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Agentyllo/' . AGY_VERSION . ' (+https://www.agentyllo.com)',
				'headers'             => array( 'Accept' => 'application/json, text/plain' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $response );

		return '' === $body ? null : $body;
	}

	/**
	 * Persist and return the sync outcome.
	 *
	 * @param bool   $ok       Success.
	 * @param string $message  Message.
	 * @param int    $sequence Sequence seen.
	 * @return array{ok: bool, message: string, sequence: int}
	 */
	private function finish( bool $ok, string $message, int $sequence = 0 ): array {
		update_option(
			self::STATUS_OPTION,
			array(
				'ok'       => $ok,
				'message'  => $message,
				'sequence' => $sequence,
				'at'       => time(),
			),
			false
		);

		return array(
			'ok'       => $ok,
			'message'  => $message,
			'sequence' => $sequence,
		);
	}
}
