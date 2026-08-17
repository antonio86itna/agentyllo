<?php
/**
 * Shared plumbing for BYO-key cloud LLM providers.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Providers;

use Agentyllo\AI\Contracts\ChatRequest;
use Agentyllo\AI\Contracts\ChatResult;
use Agentyllo\AI\Contracts\LLMProvider;
use Agentyllo\Infra\Crypto\KeyVault;
use Agentyllo\Infra\Http\StreamingClient;
use Agentyllo\Registry\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Keys live sealed in the `models` settings tab (KeyVault); model ids and
 * capability flags come from the signed registry; transport is the
 * StreamingClient. Subclasses only translate ChatRequest ↔ vendor wire
 * format. Error codes are normalized (auth, rate_limit, overloaded, server,
 * bad_request, timeout, network, refusal, parse, no_key, no_model) so the
 * router/circuit breaker never sees vendor specifics.
 */
abstract class CloudProvider implements LLMProvider {

	/**
	 * Resolver returning the current 'models' settings array.
	 *
	 * @var callable
	 */
	protected $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param Manifest        $manifest          Registry manifest.
	 * @param KeyVault        $vault             Secret vault.
	 * @param StreamingClient $http              HTTP client.
	 * @param callable        $settings_resolver Returns the 'models' settings tab.
	 */
	public function __construct(
		protected readonly Manifest $manifest,
		protected readonly KeyVault $vault,
		protected readonly StreamingClient $http,
		callable $settings_resolver
	) {
		$this->settings_resolver = $settings_resolver;
	}

	/**
	 * Settings key holding this provider's sealed API key.
	 */
	abstract protected function key_setting(): string;

	/**
	 * Settings key holding this provider's configured chat model id.
	 */
	abstract protected function model_setting(): string;

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return '' !== $this->api_key() && null !== $this->model();
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		$model = $this->model() ?? array();

		return array(
			'streaming' => (bool) ( $model['streaming'] ?? true ) && $this->http->supports_streaming(),
			'json_mode' => (bool) ( $model['json_schema'] ?? false ),
			'vision'    => false,
			'context'   => (int) ( $model['context'] ?? 128000 ),
			'tier'      => 'cloud',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( ChatRequest $request ): ChatResult {
		return $this->run( $request, null );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stream( ChatRequest $request, callable $on_delta ): ChatResult {
		if ( ! $this->capabilities()['streaming'] ) {
			$result = $this->run( $request, null );
			if ( $result->ok && '' !== $result->text ) {
				$on_delta( $result->text );
			}

			return $result;
		}

		return $this->run( $request, $on_delta );
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): array {
		if ( '' === $this->api_key() ) {
			return array(
				'ok'         => false,
				'message'    => __( 'No API key saved.', 'agentyllo' ),
				'latency_ms' => 0,
			);
		}
		if ( null === $this->model() ) {
			return array(
				'ok'         => false,
				'message'    => __( 'No model available in the registry for this provider.', 'agentyllo' ),
				'latency_ms' => 0,
			);
		}

		$result = $this->complete(
			new ChatRequest(
				array(
					array(
						'role'    => 'user',
						'content' => 'Reply with the single word OK.',
					),
				),
				'You are a connectivity probe.',
				8,
				null,
				ChatRequest::TASK_CLASSIFY,
				'',
				null,
				20.0
			)
		);

		return array(
			'ok'         => $result->ok,
			'message'    => $result->ok
				? sprintf(
					/* translators: %s: model id. */
					__( 'Connected — %s answered.', 'agentyllo' ),
					$result->model
				)
				: $this->human_error( (string) $result->error ),
			'latency_ms' => $result->latency_ms,
		);
	}

	/**
	 * Effective model definition from the registry (configured or default).
	 *
	 * @return array<string, mixed>|null
	 */
	public function model(): ?array {
		$configured = (string) ( $this->settings()[ $this->model_setting() ] ?? '' );

		return $this->manifest->resolve_chat_model( $this->id(), $configured );
	}

	/**
	 * Decrypted API key ('' when missing or undecryptable). Falls back to
	 * the WordPress AI Client convention (constant or env var
	 * OPENAI_API_KEY / ANTHROPIC_API_KEY) so a key defined once for core AI
	 * features is honoured here without re-entry.
	 */
	protected function api_key(): string {
		$sealed = (string) ( $this->settings()[ $this->key_setting() ] ?? '' );
		if ( '' !== $sealed ) {
			return (string) ( $this->vault->open( $sealed ) ?? '' );
		}

		return self::environment_key( $this->id() );
	}

	/**
	 * Where the effective key comes from: 'vault' | 'environment' | ''.
	 */
	public function key_source(): string {
		$sealed = (string) ( $this->settings()[ $this->key_setting() ] ?? '' );
		if ( '' !== $sealed ) {
			return 'vault';
		}

		return '' !== self::environment_key( $this->id() ) ? 'environment' : '';
	}

	/**
	 * Key from a constant/env var named {PROVIDER}_API_KEY (WP AI Client
	 * convention), '' when absent.
	 *
	 * @param string $provider Provider id.
	 */
	public static function environment_key( string $provider ): string {
		$name = strtoupper( preg_replace( '/[^a-z0-9]/i', '_', $provider ) ) . '_API_KEY';
		if ( defined( $name ) && is_scalar( constant( $name ) ) ) {
			return trim( (string) constant( $name ) );
		}
		$env = getenv( $name );

		return is_string( $env ) ? trim( $env ) : '';
	}

	/**
	 * Provider block from the registry (base_url etc.).
	 *
	 * @return array<string, mixed>
	 */
	protected function registry(): array {
		return $this->manifest->provider( $this->id() );
	}

	/**
	 * Current 'models' settings.
	 *
	 * @return array<string, mixed>
	 */
	protected function settings(): array {
		$settings = ( $this->settings_resolver )();

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Vendor call. Implemented by subclasses; $on_delta null = blocking.
	 *
	 * @param ChatRequest   $request  Request.
	 * @param callable|null $on_delta Delta sink.
	 */
	abstract protected function run( ChatRequest $request, ?callable $on_delta ): ChatResult;

	/**
	 * Map an HTTP status to a normalized error code.
	 *
	 * @param int         $status HTTP status.
	 * @param string|null $error  Transport error.
	 */
	protected static function classify( int $status, ?string $error ): string {
		if ( null !== $error ) {
			return 'timeout' === $error ? 'timeout' : 'network';
		}

		return match ( true ) {
			401 === $status, 403 === $status => 'auth',
			429 === $status                  => 'rate_limit',
			529 === $status, 503 === $status => 'overloaded',
			$status >= 500                   => 'server',
			$status >= 400                   => 'bad_request',
			0 === $status                    => 'network',
			default                          => 'error',
		};
	}

	/**
	 * Vendor error message from a JSON error body (best effort, truncated).
	 *
	 * @param string $body Raw body.
	 */
	public static function vendor_message( string $body ): string {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			$msg = $decoded['error']['message'] ?? ( $decoded['message'] ?? '' );
			if ( is_string( $msg ) && '' !== $msg ) {
				return mb_substr( $msg, 0, 300 );
			}
		}

		return '';
	}

	/**
	 * Human-readable text for a normalized error code (admin test button).
	 *
	 * @param string $code Error code (optionally "code: vendor message").
	 */
	protected function human_error( string $code ): string {
		[ $key, $detail ] = array_pad( explode( ':', $code, 2 ), 2, '' );
		$base             = match ( trim( $key ) ) {
			'auth'        => __( 'Authentication failed — check the API key.', 'agentyllo' ),
			'rate_limit'  => __( 'Rate limited by the provider — try again in a minute.', 'agentyllo' ),
			'overloaded'  => __( 'Provider overloaded — try again shortly.', 'agentyllo' ),
			'server'      => __( 'Provider server error.', 'agentyllo' ),
			'bad_request' => __( 'The provider rejected the request.', 'agentyllo' ),
			'timeout'     => __( 'The provider did not answer in time.', 'agentyllo' ),
			'network'     => __( 'Could not reach the provider (network/SSL).', 'agentyllo' ),
			'refusal'     => __( 'The model declined to answer the probe.', 'agentyllo' ),
			'no_key'      => __( 'No API key saved.', 'agentyllo' ),
			'no_model'    => __( 'No model configured.', 'agentyllo' ),
			default       => __( 'Unexpected provider response.', 'agentyllo' ),
		};

		return '' === trim( $detail ) ? $base : $base . ' (' . trim( $detail ) . ')';
	}
}
