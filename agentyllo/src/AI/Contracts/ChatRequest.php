<?php
/**
 * Provider-agnostic chat request.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Value object consumed by every LLMProvider.
 */
final class ChatRequest {

	public const TASK_CHAT      = 'chat';
	public const TASK_CLASSIFY  = 'classify';
	public const TASK_REWRITE   = 'rewrite';
	public const TASK_SUMMARIZE = 'summarize';

	/**
	 * Constructor.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Conversation turns (user/assistant).
	 * @param string      $system      System prompt.
	 * @param int         $max_tokens  Output cap.
	 * @param float|null  $temperature Sampling temperature (null = provider default).
	 * @param string      $task        TASK_* hint (routing / budget).
	 * @param string      $lang        Desired reply language (two-letter) or ''.
	 * @param array|null  $json_schema Structured-output schema when supported.
	 * @param float       $budget_s    Wall-clock budget in seconds.
	 * @param array       $meta        Free-form metadata (session id, prompt version…).
	 */
	public function __construct(
		public readonly array $messages,
		public readonly string $system = '',
		public readonly int $max_tokens = 512,
		public readonly ?float $temperature = null,
		public readonly string $task = self::TASK_CHAT,
		public readonly string $lang = '',
		public readonly ?array $json_schema = null,
		public readonly float $budget_s = 25.0,
		public readonly array $meta = array(),
	) {
	}
}
