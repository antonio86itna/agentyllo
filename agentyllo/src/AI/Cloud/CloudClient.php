<?php
/**
 * Agentyllo Cloud client — talks to the centralized free-AI service.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Cloud;

defined( 'ABSPATH' ) || exit;

/**
 * Thin HTTP client for the Agentyllo Cloud free-AI service (a first-party
 * proxy that rotates OpenRouter keys, auto-selects the best free model and
 * enforces a per-domain monthly quota — see cloud-proxy/). The plugin never
 * sees an OpenRouter key: it registers the site once for a per-site token,
 * then calls /v1/chat and /v1/usage with it.
 *
 * The endpoint is filterable so the service can move or be self-hosted. When
 * the service is unreachable (e.g. not yet deployed) every method fails
 * gracefully and the plugin falls back to classic — nothing breaks.
 */
final class CloudClient {

	public const DEFAULT_ENDPOINT = 'https://api.agentyllo.com';
	private const TOKEN_OPTION     = 'agyl_cloud_token';
	private const USAGE_TRANSIENT  = 'agyl_cloud_usage';

	/**
	 * Effective service base URL (filterable / self-hostable).
	 */
	public function endpoint(): string {
		/**
		 * Filter the Agentyllo Cloud service base URL.
		 *
		 * @param string $endpoint Base URL, no trailing slash.
		 */
		return rtrim( (string) apply_filters( 'agyl_cloud_endpoint', self::DEFAULT_ENDPOINT ), '/' );
	}

	/**
	 * The stored per-site token ('' when not registered yet).
	 */
	public function token(): string {
		return (string) get_option( self::TOKEN_OPTION, '' );
	}

	/**
	 * Whether the site has a Cloud token.
	 */
	public function registered(): bool {
		return '' !== $this->token();
	}

	/**
	 * Register this site once and store the returned token.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function register(): array {
		if ( $this->registered() ) {
			return array( 'ok' => true, 'message' => __( 'Already connected.', 'agentyllo' ) );
		}

		$res = wp_safe_remote_post(
			$this->endpoint() . '/v1/register',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'domain'         => wp_parse_url( home_url(), PHP_URL_HOST ),
						'site_url'       => home_url(),
						'plugin_version' => AGYL_VERSION,
					)
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'message' => $this->unreachable_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) || '' === (string) ( $body['site_token'] ?? '' ) ) {
			$msg = is_array( $body ) ? (string) ( $body['message'] ?? '' ) : '';
			return array( 'ok' => false, 'message' => '' !== $msg ? $msg : $this->unreachable_message() );
		}

		update_option( self::TOKEN_OPTION, (string) $body['site_token'], false );
		delete_transient( self::USAGE_TRANSIENT );

		return array( 'ok' => true, 'message' => __( 'Connected to Agentyllo Cloud.', 'agentyllo' ) );
	}

	/**
	 * Forget the token (disconnect).
	 */
	public function disconnect(): void {
		delete_option( self::TOKEN_OPTION );
		delete_transient( self::USAGE_TRANSIENT );
	}

	/**
	 * Blocking chat completion. Returns the decoded JSON body (OpenAI-ish:
	 * {ok, text, model, usage:{remaining,limit,resets_at}}) or a WP_Error-like
	 * failure array {ok:false, error}.
	 *
	 * @param array<string, mixed> $payload {messages, system, max_tokens, temperature, json_schema, budget_s}.
	 * @return array<string, mixed>
	 */
	public function chat( array $payload ): array {
		if ( ! $this->registered() ) {
			return array( 'ok' => false, 'error' => 'not_registered' );
		}

		$res = wp_safe_remote_post(
			$this->endpoint() . '/v1/chat',
			array(
				'timeout' => (int) max( 8, min( 60, (int) ( $payload['budget_s'] ?? 25 ) ) ),
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $this->token(),
				),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'error' => 'network' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 429 === $code ) {
			return array( 'ok' => false, 'error' => 'quota' );
		}
		if ( 401 === $code || 403 === $code ) {
			// Token rejected — drop it so the owner can reconnect.
			$this->disconnect();
			return array( 'ok' => false, 'error' => 'auth' );
		}
		if ( 200 !== $code || ! is_array( $body ) ) {
			return array( 'ok' => false, 'error' => 'bad_response' );
		}
		if ( isset( $body['usage'] ) && is_array( $body['usage'] ) ) {
			set_transient( self::USAGE_TRANSIENT, $body['usage'], HOUR_IN_SECONDS );
		}

		return $body;
	}

	/**
	 * Current per-domain usage {used, limit, remaining, resets_at, period}.
	 * Cached briefly; refreshable.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array<string, mixed>|null
	 */
	public function usage( bool $force = false ): ?array {
		if ( ! $this->registered() ) {
			return null;
		}
		if ( ! $force ) {
			$cached = get_transient( self::USAGE_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$res = wp_safe_remote_get(
			$this->endpoint() . '/v1/usage',
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}
		set_transient( self::USAGE_TRANSIENT, $body, HOUR_IN_SECONDS );

		return $body;
	}

	/**
	 * Human message when the service cannot be reached.
	 */
	private function unreachable_message(): string {
		return __( 'Agentyllo Cloud is not reachable right now. Please try again shortly.', 'agentyllo' );
	}
}
