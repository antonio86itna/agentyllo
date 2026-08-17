<?php
/**
 * LLM provider contract.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Cloud (OpenAI, Anthropic), local (BYO llama-server, ONNX) and browser
 * providers all implement this. The classic pipeline never talks to a
 * vendor SDK — only to this interface through the ProviderRouter.
 */
interface LLMProvider {

	/**
	 * Stable id: 'openai' | 'anthropic' | 'llamacpp' | 'onnx' | …
	 */
	public function id(): string;

	/**
	 * Configured + reachable in principle (key present, endpoint set…).
	 * Cheap; no network.
	 */
	public function is_available(): bool;

	/**
	 * Capability flags: streaming, json_mode, vision, context, tier ('cloud'|'local'|'browser').
	 *
	 * @return array{streaming: bool, json_mode: bool, vision: bool, context: int, tier: string}
	 */
	public function capabilities(): array;

	/**
	 * Blocking completion.
	 *
	 * @param ChatRequest $request Request.
	 */
	public function complete( ChatRequest $request ): ChatResult;

	/**
	 * Streaming completion: $on_delta(string $text_delta) is invoked per
	 * chunk; the returned result carries the full text + usage.
	 * Providers without streaming fall back to complete() then emit once.
	 *
	 * @param ChatRequest $request  Request.
	 * @param callable    $on_delta fn(string $delta): void.
	 */
	public function stream( ChatRequest $request, callable $on_delta ): ChatResult;

	/**
	 * Verify credentials/reachability with a minimal call. Returns
	 * {ok: bool, message: string, latency_ms: int}.
	 */
	public function test_connection(): array;
}
