<?php
/**
 * Agent journal contract.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Backed by wp_agyl_agent_journal. Errors are fingerprinted: duplicate
 * signatures increment `occurrences` instead of inserting, and the nightly
 * learner promotes fingerprints with ≥3 occurrences into lessons.
 */
interface JournalInterface {

	/**
	 * Write a journal entry.
	 *
	 * @param string      $agent_id Agent id.
	 * @param string      $level    debug|info|warn|error.
	 * @param string      $event    Dot-notation event, e.g. 'task.done', 'verify.fail'.
	 * @param string      $message  Human-readable message.
	 * @param array       $context  JSON-safe context.
	 * @param string|null $task_ref Correlation uuid.
	 */
	public function log( string $agent_id, string $level, string $event, string $message = '', array $context = array(), ?string $task_ref = null ): void;

	/**
	 * Record an error with fingerprint dedup.
	 *
	 * @param string      $agent_id  Agent id.
	 * @param \Throwable  $e         The failure.
	 * @param Task|null   $task      Task being handled, if any.
	 * @param array       $context   Extra context merged into the entry.
	 */
	public function error( string $agent_id, \Throwable $e, ?Task $task = null, array $context = array() ): void;

	/**
	 * Fingerprints with at least $min occurrences at level error since $days.
	 *
	 * @param int $min  Minimum occurrences.
	 * @param int $days Look-back window.
	 * @return array<int, array{fingerprint: string, agent_id: string, event: string, occurrences: int, context: array}>
	 */
	public function recurring_errors( int $min = 3, int $days = 30 ): array;

	/**
	 * Delete entries older than the retention window. Returns rows removed.
	 *
	 * @param int $days_default Retention for non-error rows.
	 * @param int $days_errors  Retention for error rows.
	 */
	public function rotate( int $days_default = 30, int $days_errors = 90 ): int;
}
