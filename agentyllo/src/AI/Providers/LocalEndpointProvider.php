<?php
/**
 * BYO local endpoint provider (llama-server / Ollama / LM Studio / vLLM).
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

defined( 'ABSPATH' ) || exit;

/**
 * The in-core FREE AI path allowed by WP.org rules: the site owner points
 * Agentyllo at an OpenAI-compatible server they already run (llama.cpp
 * `llama-server`, Ollama, LM Studio, vLLM, or the daemon supervised by the
 * Agentyllo Local AI companion). Wire format: POST {url}/v1/chat/completions
 * with SSE `data:` frames and a final `[DONE]`. Tier 'local': the router uses
 * it in free modes, budget serializes it (GET_LOCK, concurrency 1) and the
 * measured tok/s decides whether it may compose chat or only run bounded
 * tasks (T3 vs T2 in the ladder). Optional bearer key for reverse-proxied
 * servers. Loopback/private URLs are allowed on purpose (that is the point);
 * only http(s) schemes are accepted.
 */
final class LocalEndpointProvider implements LLMProvider {

	public const ID = 'local_endpoint';

	/**
	 * Resolver returning the 'models' settings array.
	 *
	 * @var callable
	 */
	private $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param KeyVault        $vault             Secret vault.
	 * @param StreamingClient $http              HTTP client.
	 * @param callable        $settings_resolver Returns the 'models' settings tab.
	 */
	public function __construct(
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
		return '' !== $this->base_url();
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array(
			'streaming' => $this->http->supports_streaming(),
			'json_mode' => false,
			'vision'    => false,
			'context'   => max( 2048, (int) ( $this->settings()['local_context'] ?? 8192 ) ),
			'tier'      => 'local',
		);
	}

	/**
	 * Configured base URL ('' when unset/invalid), without trailing slash.
	 */
	public function base_url(): string {
		$url = trim( (string) ( $this->settings()['local_endpoint_url'] ?? '' ) );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		/**
		 * Filter the local endpoint URL (the Local AI companion points it at
		 * its supervised daemon).
		 *
		 * @param string $url Base URL.
		 */
		$url = (string) apply_filters( 'agyl_local_endpoint_url', $url );

		return rtrim( $url, '/' );
	}

	/**
	 * Configured model id ('' = let the server pick its loaded model).
	 */
	public function model_id(): string {
		return trim( (string) ( $this->settings()['local_model'] ?? '' ) );
	}

	/**
	 * Registry-like descriptor for the router/budget (no pricing: free).
	 *
	 * @return array<string, mixed>
	 */
	public function model(): array {
		return array(
			'id'         => '' !== $this->model_id() ? $this->model_id() : 'local',
			'price_in'   => 0,
			'price_out'  => 0,
			'streaming'  => true,
			'max_output' => 2048,
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
		$base = $this->base_url();
		if ( '' === $base ) {
			return array(
				'ok'         => false,
				'message'    => __( 'No endpoint URL configured.', 'agentyllo' ),
				'latency_ms' => 0,
			);
		}

		$start    = microtime( true );
		$headers  = array( 'Accept' => 'application/json' );
		$key      = $this->api_key();
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$response = wp_remote_get(
			$base . '/v1/models',
			array(
				'timeout'     => 8,
				'headers'     => $headers,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'         => false,
				'message'    => sprintf(
					/* translators: %s: error message. */
					__( 'Endpoint unreachable: %s', 'agentyllo' ),
					$response->get_error_message()
				),
				'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'ok'         => false,
				'message'    => sprintf(
					/* translators: %d: HTTP status. */
					__( 'Endpoint answered HTTP %d on /v1/models.', 'agentyllo' ),
					$code
				),
				'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			);
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$models  = array();
		foreach ( (array) ( $decoded['data'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$models[] = (string) $row['id'];
			}
		}

		// Tiny generation to measure tokens/second on this host.
		$probe = $this->complete(
			new ChatRequest(
				array(
					array(
						'role'    => 'user',
						'content' => 'Count from one to twenty in words, comma separated.',
					),
				),
				'You are a speed probe. Answer only with the list.',
				48,
				0.0,
				ChatRequest::TASK_CLASSIFY,
				'',
				null,
				25.0
			)
		);
		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( ! $probe->ok ) {
			return array(
				'ok'         => false,
				'message'    => sprintf(
					/* translators: 1: models found, 2: error. */
					__( 'Server reachable (%1$s) but generation failed: %2$s', 'agentyllo' ),
					$models ? implode( ', ', array_slice( $models, 0, 5 ) ) : __( 'no models listed', 'agentyllo' ),
					(string) $probe->error
				),
				'latency_ms' => $latency,
			);
		}
		$tps = $probe->latency_ms > 0 && $probe->tokens_out > 0 ? round( $probe->tokens_out / ( $probe->latency_ms / 1000 ), 1 ) : 0.0;

		return array(
			'ok'         => true,
			'message'    => sprintf(
				/* translators: 1: model, 2: tokens/s, 3: models list. */
				__( 'Connected — %1$s answered at ~%2$s tok/s. Models: %3$s', 'agentyllo' ),
				$probe->model,
				(string) $tps,
				$models ? implode( ', ', array_slice( $models, 0, 5 ) ) : '—'
			),
			'latency_ms' => $latency,
			'tok_per_s'  => $tps,
			'models'     => $models,
		);
	}

	/**
	 * Vendor call.
	 *
	 * @param ChatRequest   $request  Request.
	 * @param callable|null $on_delta Delta sink.
	 */
	private function run( ChatRequest $request, ?callable $on_delta ): ChatResult {
		$base = $this->base_url();
		if ( '' === $base ) {
			return ChatResult::failed( 'no_endpoint', self::ID );
		}
		$model_id = $this->model_id();

		$messages = array();
		if ( '' !== $request->system ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $request->system,
			);
		}
		foreach ( $request->messages as $turn ) {
			$messages[] = array(
				'role'    => ( $turn['role'] ?? 'user' ) === 'assistant' ? 'assistant' : 'user',
				'content' => (string) ( $turn['content'] ?? '' ),
			);
		}

		$body = array(
			'messages'    => $messages,
			'max_tokens'  => max( 8, $request->max_tokens ),
			'temperature' => $request->temperature ?? 0.3,
			'stream'      => null !== $on_delta,
		);
		if ( '' !== $model_id ) {
			$body['model'] = $model_id;
		}
		if ( null !== $on_delta ) {
			$body['stream_options'] = array( 'include_usage' => true );
		}

		$headers = array();
		$key     = $this->api_key();
		if ( '' !== $key ) {
			$headers[] = 'Authorization: Bearer ' . $key;
		}

		$text       = '';
		$tokens_in  = 0;
		$tokens_out = 0;
		$finish     = 'stop';
		$seen_model = '';
		$start      = microtime( true );

		$sink = null;
		if ( null !== $on_delta ) {
			$sink = static function ( string $event, string $data ) use ( &$text, &$tokens_in, &$tokens_out, &$finish, &$seen_model, $on_delta ): bool {
				if ( '[DONE]' === trim( $data ) ) {
					return true;
				}
				$payload = json_decode( $data, true );
				if ( ! is_array( $payload ) ) {
					return true;
				}
				if ( isset( $payload['error'] ) ) {
					return false;
				}
				if ( '' === $seen_model && ! empty( $payload['model'] ) ) {
					$seen_model = (string) $payload['model'];
				}
				if ( isset( $payload['usage'] ) && is_array( $payload['usage'] ) ) {
					$tokens_in  = (int) ( $payload['usage']['prompt_tokens'] ?? $tokens_in );
					$tokens_out = (int) ( $payload['usage']['completion_tokens'] ?? $tokens_out );
				}
				foreach ( (array) ( $payload['choices'] ?? array() ) as $choice ) {
					$delta = (string) ( $choice['delta']['content'] ?? '' );
					if ( '' !== $delta ) {
						$text .= $delta;
						if ( false === $on_delta( $delta ) ) {
							return false;
						}
					}
					if ( 'length' === ( $choice['finish_reason'] ?? '' ) ) {
						$finish = 'length';
					}
				}

				return true;
			};
		}

		$http    = $this->http->post_json( $base . '/v1/chat/completions', $headers, $body, $request->budget_s, $sink );
		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );
		$model   = '' !== $seen_model ? $seen_model : ( '' !== $model_id ? $model_id : 'local' );

		if ( null !== $http['error'] || $http['status'] < 200 || $http['status'] >= 300 ) {
			$code = null !== $http['error'] ? ( 'timeout' === $http['error'] ? 'timeout' : 'network' ) : ( 0 === (int) $http['status'] ? 'network' : ( 401 === (int) $http['status'] || 403 === (int) $http['status'] ? 'auth' : ( (int) $http['status'] >= 500 ? 'server' : 'bad_request' ) ) );
			$detail = CloudProvider::vendor_message( (string) $http['body'] );

			return ChatResult::failed( '' === $detail ? $code : $code . ': ' . $detail, self::ID, $model, $latency );
		}

		if ( null !== $on_delta ) {
			if ( '' === $text ) {
				return ChatResult::failed( $http['aborted'] ? 'aborted' : 'empty', self::ID, $model, $latency );
			}
			if ( 0 === $tokens_out ) {
				$tokens_out = (int) ceil( strlen( $text ) / 4 ); // Servers without usage in stream.
			}

			return new ChatResult( true, $text, self::ID, $model, $tokens_in, $tokens_out, $latency, null, $http['aborted'] ? 'aborted' : $finish );
		}

		$decoded = json_decode( (string) $http['body'], true );
		if ( ! is_array( $decoded ) ) {
			return ChatResult::failed( 'parse', self::ID, $model, $latency );
		}
		if ( isset( $decoded['error'] ) ) {
			$msg = is_array( $decoded['error'] ) ? (string) ( $decoded['error']['message'] ?? 'error' ) : (string) $decoded['error'];

			return ChatResult::failed( 'error: ' . mb_substr( $msg, 0, 200 ), self::ID, $model, $latency );
		}
		$choice     = (array) ( ( $decoded['choices'] ?? array() )[0] ?? array() );
		$text       = (string) ( $choice['message']['content'] ?? '' );
		$tokens_in  = (int) ( $decoded['usage']['prompt_tokens'] ?? 0 );
		$tokens_out = (int) ( $decoded['usage']['completion_tokens'] ?? ceil( strlen( $text ) / 4 ) );
		$finish     = 'length' === ( $choice['finish_reason'] ?? '' ) ? 'length' : 'stop';
		if ( ! empty( $decoded['model'] ) ) {
			$model = (string) $decoded['model'];
		}

		return new ChatResult( '' !== $text, $text, self::ID, $model, $tokens_in, $tokens_out, $latency, '' === $text ? 'empty' : null, $finish );
	}

	/**
	 * Optional bearer key.
	 */
	private function api_key(): string {
		$sealed = (string) ( $this->settings()['local_api_key'] ?? '' );

		return '' === $sealed ? '' : (string) ( $this->vault->open( $sealed ) ?? '' );
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
