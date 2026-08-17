<?php
/**
 * OpenAI provider (Responses API).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Providers;

use Agentyllo\AI\Contracts\ChatRequest;
use Agentyllo\AI\Contracts\ChatResult;

defined( 'ABSPATH' ) || exit;

/**
 * POST {base_url}/responses with `store: false` (nothing retained on the
 * vendor side — GDPR-friendly default). Streaming consumes the SSE events
 * response.output_text.delta / response.completed / response.failed /
 * response.incomplete / error. Structured output goes through
 * text.format = json_schema (strict). Sampling params and reasoning effort
 * are only sent when the registry flags the model as accepting them.
 */
final class OpenAIProvider extends CloudProvider {

	public const ID = 'openai';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key_setting(): string {
		return 'openai_api_key';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function model_setting(): string {
		return 'openai_chat_model';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( ChatRequest $request, ?callable $on_delta ): ChatResult {
		$key   = $this->api_key();
		$model = $this->model();
		if ( '' === $key ) {
			return ChatResult::failed( 'no_key', self::ID );
		}
		if ( null === $model ) {
			return ChatResult::failed( 'no_model', self::ID );
		}
		$model_id = (string) $model['id'];
		$base     = rtrim( (string) ( $this->registry()['base_url'] ?? 'https://api.openai.com/v1' ), '/' );

		$input = array();
		foreach ( $request->messages as $turn ) {
			$role = ( $turn['role'] ?? 'user' ) === 'assistant' ? 'assistant' : 'user';
			$input[] = array(
				'role'    => $role,
				'content' => (string) ( $turn['content'] ?? '' ),
			);
		}

		$body = array(
			'model'             => $model_id,
			'input'             => $input,
			'max_output_tokens' => max( 16, min( $request->max_tokens, (int) ( $model['max_output'] ?? 4096 ) ) ),
			'store'             => false,
			'stream'            => null !== $on_delta,
		);
		if ( '' !== $request->system ) {
			$body['instructions'] = $request->system;
		}
		if ( ! empty( $model['sampling'] ) && null !== $request->temperature ) {
			$body['temperature'] = max( 0.0, min( 2.0, $request->temperature ) );
		}
		if ( ! empty( $model['reasoning_effort'] ) ) {
			$body['reasoning'] = array( 'effort' => (string) $model['reasoning_effort'] );
		}
		if ( null !== $request->json_schema && ! empty( $model['json_schema'] ) ) {
			$body['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'agentyllo_output',
					'schema' => $request->json_schema,
					'strict' => true,
				),
			);
		}

		$headers = array( 'Authorization: Bearer ' . $key );

		$text       = '';
		$tokens_in  = 0;
		$tokens_out = 0;
		$finish     = 'stop';
		$stream_err = null;
		$start      = microtime( true );

		$sink = null;
		if ( null !== $on_delta ) {
			$sink = static function ( string $event, string $data ) use ( &$text, &$tokens_in, &$tokens_out, &$finish, &$stream_err, $on_delta ): bool {
				$payload = json_decode( $data, true );
				if ( ! is_array( $payload ) ) {
					return true;
				}
				$type = (string) ( $payload['type'] ?? $event );

				switch ( $type ) {
					case 'response.output_text.delta':
						$delta = (string) ( $payload['delta'] ?? '' );
						if ( '' !== $delta ) {
							$text .= $delta;

							return false !== $on_delta( $delta );
						}
						break;

					case 'response.completed':
					case 'response.incomplete':
					case 'response.failed':
						$response   = (array) ( $payload['response'] ?? array() );
						$tokens_in  = (int) ( $response['usage']['input_tokens'] ?? $tokens_in );
						$tokens_out = (int) ( $response['usage']['output_tokens'] ?? $tokens_out );
						if ( 'response.incomplete' === $type ) {
							$finish = 'length';
						} elseif ( 'response.failed' === $type ) {
							$stream_err = 'error: ' . (string) ( $response['error']['message'] ?? 'failed' );
						}
						break;

					case 'error':
						$stream_err = 'error: ' . (string) ( $payload['message'] ?? ( $payload['error']['message'] ?? 'stream error' ) );

						return false;
				}

				return true;
			};
		}

		$http    = $this->http->post_json( $base . '/responses', $headers, $body, $request->budget_s, $sink );
		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( null !== $http['error'] || $http['status'] < 200 || $http['status'] >= 300 ) {
			$code   = self::classify( (int) $http['status'], $http['error'] );
			$detail = self::vendor_message( (string) $http['body'] );

			return ChatResult::failed( '' === $detail ? $code : $code . ': ' . $detail, self::ID, $model_id, $latency );
		}

		if ( null !== $on_delta ) {
			if ( null !== $stream_err && '' === $text ) {
				return ChatResult::failed( $stream_err, self::ID, $model_id, $latency );
			}
			if ( $http['aborted'] && '' === $text ) {
				return ChatResult::failed( 'aborted', self::ID, $model_id, $latency );
			}

			return new ChatResult( true, $text, self::ID, $model_id, $tokens_in, $tokens_out, $latency, null, $http['aborted'] ? 'aborted' : $finish );
		}

		return $this->parse_blocking( (string) $http['body'], $model_id, $latency );
	}

	/**
	 * Parse a non-streaming Responses API body.
	 *
	 * @param string $body     Raw JSON.
	 * @param string $model_id Model id.
	 * @param int    $latency  Latency ms.
	 */
	private function parse_blocking( string $body, string $model_id, int $latency ): ChatResult {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return ChatResult::failed( 'parse', self::ID, $model_id, $latency );
		}

		if ( ! empty( $decoded['error'] ) ) {
			return ChatResult::failed( 'error: ' . (string) ( $decoded['error']['message'] ?? 'error' ), self::ID, $model_id, $latency );
		}

		$text    = '';
		$refused = false;
		foreach ( (array) ( $decoded['output'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || 'message' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				if ( 'output_text' === ( $part['type'] ?? '' ) ) {
					$text .= (string) ( $part['text'] ?? '' );
				} elseif ( 'refusal' === ( $part['type'] ?? '' ) ) {
					$refused = true;
				}
			}
		}

		$tokens_in  = (int) ( $decoded['usage']['input_tokens'] ?? 0 );
		$tokens_out = (int) ( $decoded['usage']['output_tokens'] ?? 0 );
		$status     = (string) ( $decoded['status'] ?? 'completed' );

		if ( $refused && '' === $text ) {
			return new ChatResult( false, '', self::ID, $model_id, $tokens_in, $tokens_out, $latency, 'refusal', 'refusal' );
		}
		if ( 'failed' === $status ) {
			return ChatResult::failed( 'error: ' . (string) ( $decoded['error']['message'] ?? 'failed' ), self::ID, $model_id, $latency );
		}

		$finish = 'incomplete' === $status ? 'length' : 'stop';

		return new ChatResult( '' !== $text, $text, self::ID, $model_id, $tokens_in, $tokens_out, $latency, '' === $text ? 'empty' : null, $finish );
	}
}
