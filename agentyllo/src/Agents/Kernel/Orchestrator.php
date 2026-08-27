<?php
/**
 * Async-plane orchestrator: delivers tasks to agents, applies lessons,
 * journals outcomes, retries failures, dispatches follow-ups.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Kernel;

use Agentyllo\AI\Capability\Detector;
use Agentyllo\Agents\Contracts\AgentResult;
use Agentyllo\Agents\Contracts\Task;
use Agentyllo\Container;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Handler behind the `agyl_task` Action Scheduler hook.
 */
final class Orchestrator {

	private const MAX_ATTEMPTS = 3;

	/**
	 * Constructor.
	 *
	 * @param Registry    $registry Registry.
	 * @param MemoryStore $memory   Memory store.
	 * @param Journal     $journal  Journal.
	 * @param EventBus    $bus      Event bus.
	 * @param AsyncBus    $async    Async dispatch.
	 * @param Detector    $detector Capability detector (time budget source).
	 * @param Container   $services Container.
	 */
	public function __construct(
		private readonly Registry $registry,
		private readonly MemoryStore $memory,
		private readonly Journal $journal,
		private readonly EventBus $bus,
		private readonly AsyncBus $async,
		private readonly Detector $detector,
		private readonly Container $services,
	) {
	}

	/**
	 * Deliver one task to one agent. Hooked on `agyl_task` (Action Scheduler).
	 *
	 * @param string $agent_id  Target agent id.
	 * @param array  $task_data Serialized task (Task::to_array()).
	 */
	public function handle_async( string $agent_id, array $task_data ): void {
		$task  = Task::from_array( $task_data );
		$agent = $this->registry->get( $agent_id );

		if ( ! $agent ) {
			$this->journal->log( 'orchestrator', 'warn', 'task.unroutable', sprintf( 'unknown agent "%s" for task %s', $agent_id, $task->type ), $task->to_array() );
			return;
		}

		if ( ! $this->registry->is_active( $agent_id ) ) {
			$this->journal->log( 'orchestrator', 'info', 'task.skipped', sprintf( 'agent "%s" disabled/quarantined; task %s dropped', $agent_id, $task->type ), array(), $task->ref );
			return;
		}

		$lessons = $this->match_lessons( $agent_id, $task );

		// Kernel-applied lessons: skip means "this exact task signature keeps failing — don't run it".
		foreach ( $lessons as $lesson ) {
			if ( 'skip' === ( $lesson['action'] ?? '' ) ) {
				$this->journal->log( $agent_id, 'info', 'task.lesson_skip', (string) ( $lesson['note'] ?? '' ), array( 'task_type' => $task->type ), $task->ref );
				return;
			}
		}

		$context = new KernelContext(
			$this->memory,
			$this->journal,
			$this->bus,
			$this->services,
			microtime( true ) + $this->time_budget_seconds(),
			$lessons,
			$this->site_profile(),
		);

		try {
			$result = $agent->handle( $task, $context );
		} catch ( Throwable $e ) {
			$this->journal->error( $agent_id, $e, $task );
			$this->maybe_retry( $agent_id, $task );
			return;
		}

		$this->journal->log(
			$agent_id,
			AgentResult::STATUS_FAILED === $result->status ? 'warn' : 'debug',
			'task.' . $result->status,
			$task->type,
			array( 'confidence' => $result->confidence ),
			$task->ref
		);

		$this->bus->emit(
			'agent.task_done',
			array(
				'agent_id' => $agent_id,
				'task'     => $task->to_array(),
				'status'   => $result->status,
			)
		);

		foreach ( $result->follow_ups as $follow_up ) {
			if ( $follow_up instanceof Task ) {
				$this->async->dispatch( (string) ( $follow_up->payload['_agent'] ?? $agent_id ), $follow_up );
			}
		}

		if ( AgentResult::STATUS_FAILED === $result->status ) {
			$this->maybe_retry( $agent_id, $task );
			return;
		}

		if ( $result->requires_verification() ) {
			// Cross-verification: surfaced as an event so a peer agent
			// (registered for 'agent.needs_verification') can re-derive the
			// result by an alternate path. Verifier agents arrive with the
			// KB milestone; the event contract is stable from here on.
			$this->bus->emit(
				'agent.needs_verification',
				array(
					'agent_id'   => $agent_id,
					'task'       => $task->to_array(),
					'confidence' => $result->confidence,
					'payload'    => $result->payload,
				)
			);
		}
	}

	/**
	 * Re-dispatch a failed task with linear backoff, up to MAX_ATTEMPTS.
	 *
	 * @param string $agent_id Agent id.
	 * @param Task   $task     Failed task.
	 */
	private function maybe_retry( string $agent_id, Task $task ): void {
		if ( $task->attempt >= self::MAX_ATTEMPTS ) {
			$this->journal->log( $agent_id, 'warn', 'task.gave_up', sprintf( '%s failed %d attempts', $task->type, $task->attempt ), array(), $task->ref );
			return;
		}

		$this->async->dispatch( $agent_id, $task->retry(), AsyncBus::GROUP_DEFAULT, 60 * $task->attempt );
	}

	/**
	 * Lessons of this agent matching the task signature.
	 *
	 * @param string $agent_id Agent id.
	 * @param Task   $task     Task.
	 */
	private function match_lessons( string $agent_id, Task $task ): array {
		$matched = array();
		foreach ( $this->memory->by_kind( $agent_id, 'lesson', 100 ) as $lesson ) {
			$match = is_array( $lesson['match'] ?? null ) ? $lesson['match'] : array();
			if ( isset( $match['task_type'] ) && $match['task_type'] !== $task->type ) {
				continue;
			}
			$matched[] = $lesson;
		}

		return $matched;
	}

	/**
	 * Per-task time budget derived from the capability profile (70% of the
	 * declared limit, capped to a sane window; unlimited CLI gets 5 minutes).
	 */
	private function time_budget_seconds(): float {
		$report = $this->detector->report();
		$limit  = (int) ( $report['probes']['max_execution_time'] ?? 30 );

		if ( $limit <= 0 ) {
			return 300.0;
		}

		return max( 5.0, min( 300.0, $limit * 0.7 ) );
	}

	/**
	 * Site profile snapshot from the site_profiler agent's memory.
	 */
	private function site_profile(): array {
		return $this->memory->recall( 'site_profiler', 'profile' ) ?? array();
	}
}
