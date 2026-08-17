<?php
/**
 * Durable inter-agent task dispatch over Action Scheduler.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Kernel;

use Agentyllo\Agents\Contracts\Task;

defined( 'ABSPATH' ) || exit;

/**
 * Tasks ride Action Scheduler hook `agy_task` with args {agent_id, task}.
 * AS 4.x `unique` dedupes on hook+args; on sites where an older 3.x copy wins
 * the version election we replicate that with as_has_scheduled_action.
 */
final class AsyncBus {

	public const HOOK = 'agy_task';

	public const GROUP_DEFAULT = 'agentyllo';

	/**
	 * Enqueue a task for async handling. Returns false when Action Scheduler
	 * is unavailable or the same task is already queued.
	 *
	 * @param string $agent_id Target agent id.
	 * @param Task   $task     Task.
	 * @param string $group    AS group (agentyllo | agentyllo-kb | agentyllo-ai).
	 * @param int    $delay    Seconds to delay execution. 0 = as soon as possible.
	 */
	public function dispatch( string $agent_id, Task $task, string $group = self::GROUP_DEFAULT, int $delay = 0 ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		$args = array(
			'agent_id' => $agent_id,
			'task'     => $task->to_array(),
		);

		if ( ! $this->supports_unique_args() && as_has_scheduled_action( self::HOOK, $args, $group ) ) {
			return false;
		}

		if ( $delay > 0 ) {
			return 0 !== as_schedule_single_action( time() + $delay, self::HOOK, $args, $group, true );
		}

		return 0 !== as_enqueue_async_action( self::HOOK, $args, $group, true );
	}

	/**
	 * Whether the elected Action Scheduler honors arg-inclusive uniqueness
	 * (4.0+). Older 3.x copies dedupe on hook+group only.
	 */
	private function supports_unique_args(): bool {
		if ( ! class_exists( '\ActionScheduler_Versions' ) ) {
			return false;
		}

		try {
			$version = (string) \ActionScheduler_Versions::instance()->latest_version();

			return version_compare( $version, '4.0.0', '>=' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
