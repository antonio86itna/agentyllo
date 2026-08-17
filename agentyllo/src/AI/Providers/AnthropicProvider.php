<?php
/**
 * Anthropic provider (Messages API).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Providers;

use Agentyllo\AI\Contracts\ChatRequest;
use Agentyllo\AI\Contracts\ChatResult;

defined( 'ABSPATH' ) || exit;

/**
 * POST {base_url}/messages with x-api-key + anthropic-version headers.
 * Streaming consumes message_start (input tokens), content_block_delta
 * (text_delta), message_delta (stop_reason + output tokens) and error
 * events. `stop_reason: refusal` (safety classifiers on Claude 5 models)
 * surfaces as a normalized 'refusal' — the router degrades to the classic
 * composer, so a refused turn is answered by the site's own content rather
 * than re-routed to another paid model. Structured output uses
 * output_config.format json_schema; effort (Claude 5) is registry-gated and
 * kept low for chat latency; the `thinking` parameter is never sent (models
 * default sensibly and some reject explicit values); temperature is sent
 * only where the registry says sampling is accepted.
 */
final class AnthropicProvider extends CloudProvider {

	public const ID = 'anthropic';

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
		return 'anthropic_api_key';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function model_setting(): string {
		return 'anthropic_chat_model';
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
		$registry = $this->registry();
		$base     = rtrim( (string) ( $registry['base_url'] ?? 'https://api.anthropic.com/v1' ), '/' );
		$version  = (string) ( $registry['api_version'] ?? '2023-06-01' );

		// Messages must alternate and start with a user turn.
		$messages = array();
		foreach ( $request->messages as $turn ) {
			$role    = ( $turn['role'] ?? 'user' ) === 'assistant' ? 'assistant' : 'user';
			$content = (string) ( $turn['content'] ?? '' );
			if ( '' === trim( $content ) ) {
				continue;
			}
			if ( $messages && $messages[ count( $messages ) - 1 ]['role'] === $role ) {
				$messages[ count( $messages ) - 1 ]['content'] .= "\n\n" . $content;
				continue;
			}
			$messages[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}
		if ( ! $messages || 'user' !== $messages[0]['role'] ) {
			array_unshift(
				$messages,
				array(
					'role'    => 'user',
					'content' => '(conversation start)',
				)
			);
		}
		if ( 'assistant' === $messages[ count( $messages ) - 1 ]['role'] ) {
			// Prefill is unsupported on current models: never end on assistant.
			array_pop( $messages );
		}

		$body = array(
			'model'      => $model_id,
			'max_tokens' => max( 16, min( $request->max_tokens, (int) ( $model['max_output'] ?? 4096 ) ) ),
			'messages'   => $messages,
			'stream'     => null !== $on_delta,
		);
		if ( '' !== $request->system ) {
			$body['system'] = $request->system;
		}
		if ( ! empty( $model['sampling'] ) && null !== $request->temperature ) {
			$body['temperature'] = max( 0.0, min( 1.0, $request->temperature ) );
		}
		$output_config = array();
		if ( ! empty( $model['effort'] ) ) {
			$output_config['effort'] = (string) ( $model['effort_level'] ?? 'low' );
		}
		if ( null !== $request->json_schema && ! empty( $model['json_schema'] ) ) {
			$output_config['format'] = array(
				'type'   => 'json_schema',
				'schema' => $request->json_schema,
			);
		}
		if ( $output_config ) {
			$body['output_config'] = $output_config;
		}

		$headers = array(
			'x-api-key: ' . $key,
			'anthropic-version: ' . $version,
		);

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
					case 'message_start':
						$tokens_in = (int) ( $payload['message']['usage']['input_tokens'] ?? 0 );
						break;

					case 'content_block_delta':
						if ( 'text_delta' === ( $payload['delta']['type'] ?? '' ) ) {
							$delta = (string) ( $payload['delta']['text'] ?? '' );
							if ( '' !== $delta ) {
								$text .= $delta;

								return false !== $on_delta( $delta );
							}
						}
						break;

					case 'message_delta':
						$tokens_out = (int) ( $payload['usage']['output_tokens'] ?? $tokens_out );
						$finish     = self::finish_reason( (string) ( $payload['delta']['stop_reason'] ?? '' ) );
						break;

					case 'error':
						$stream_err = 'error: ' . (string) ( $payload['error']['message'] ?? 'stream error' );

						return false;
				}

				return true;
			};
		}

		$http    = $this->http->post_json( $base . '/messages', $headers, $body, $request->budget_s, $sink );
		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( null !== $http['error'] || $http['status'] < 200 || $http['status'] >= 300 ) {
			$code   = self::classify( (int) $http['status'], $http['error'] );
			$detail = self::vendor_message( (string) $http['body'] );

			return ChatResult::failed( '' === $detail ? $code : $code . ': ' . $detail, self::ID, $model_id, $latency );
		}

		if ( null !== $on_delta ) {
			if ( 'refusal' === $finish && '' === $text ) {
				return new ChatResult( false, '', self::ID, $model_id, $tokens_in, $tokens_out, $latency, 'refusal', 'refusal' );
			}
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
	 * Parse a non-streaming Messages API body.
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
		if ( 'error' === ( $decoded['type'] ?? '' ) ) {
			return ChatResult::failed( 'error: ' . (string) ( $decoded['error']['message'] ?? 'error' ), self::ID, $model_id, $latency );
		}

		$tokens_in  = (int) ( $decoded['usage']['input_tokens'] ?? 0 );
		$tokens_out = (int) ( $decoded['usage']['output_tokens'] ?? 0 );
		$finish     = self::finish_reason( (string) ( $decoded['stop_reason'] ?? '' ) );

		// Always branch on stop_reason before reading content.
		if ( 'refusal' === $finish ) {
			return new ChatResult( false, '', self::ID, $model_id, $tokens_in, $tokens_out, $latency, 'refusal', 'refusal' );
		}

		$text = '';
		foreach ( (array) ( $decoded['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}

		return new ChatResult( '' !== $text, $text, self::ID, $model_id, $tokens_in, $tokens_out, $latency, '' === $text ? 'empty' : null, $finish );
	}

	/**
	 * Normalize Anthropic stop reasons.
	 *
	 * @param string $reason Vendor stop_reason.
	 */
	private static function finish_reason( string $reason ): string {
		return match ( $reason ) {
			'max_tokens', 'model_context_window_exceeded' => 'length',
			'refusal' => 'refusal',
			default   => 'stop',
		};
	}
}
