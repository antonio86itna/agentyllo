<?php
/**
 * Provider-agnostic chat result.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Text + usage + provenance. `ok=false` carries an error code the router
 * uses for circuit-breaking (auth, rate_limit, timeout, network, refusal).
 */
final class ChatResult {

	/**
	 * Constructor.
	 *
	 * @param bool        $ok          Success flag.
	 * @param string      $text        Generated text ('' on failure).
	 * @param string      $provider    Provider id.
	 * @param string      $model       Model id.
	 * @param int         $tokens_in   Prompt tokens (0 when unknown).
	 * @param int         $tokens_out  Completion tokens.
	 * @param int         $latency_ms  Wall-clock latency.
	 * @param string|null $error       Error code when !ok.
	 * @param string      $finish      Finish reason (stop|length|refusal|error).
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly string $text = '',
		public readonly string $provider = '',
		public readonly string $model = '',
		public readonly int $tokens_in = 0,
		public readonly int $tokens_out = 0,
		public readonly int $latency_ms = 0,
		public readonly ?string $error = null,
		public readonly string $finish = 'stop',
	) {
	}

	/**
	 * Failure factory.
	 *
	 * @param string $error      Error code.
	 * @param string $provider   Provider id.
	 * @param string $model      Model id.
	 * @param int    $latency_ms Latency.
	 */
	public static function failed( string $error, string $provider = '', string $model = '', int $latency_ms = 0 ): self {
		return new self( false, '', $provider, $model, 0, 0, $latency_ms, $error, 'error' );
	}
}
