<?php
/**
 * Reconciler: periodic KB drift correction.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Roster;

use Agentyllo\Agents\Contracts\Agent;
use Agentyllo\Agents\Contracts\AgentContext;
use Agentyllo\Agents\Contracts\AgentResult;
use Agentyllo\Agents\Contracts\HealthReport;
use Agentyllo\Agents\Contracts\Task;
use Agentyllo\KB\Indexer\IndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * On 'kb.reconcile' it enqueues an agyl_kb_reconcile(source, 0) job for every
 * enabled source; the paged comparison, re-enqueueing of changed items and
 * cleanup of vanished ones happen inside IndexManager's job handler. Meant to
 * be dispatched daily by the recurring agyl_kb_reconcile_all hook (via the
 * Orchestrator, like Jobs::run_health_sweep).
 */
final class ReconcilerAgent implements Agent {

	public const ID = 'reconciler';

	/**
	 * Constructor.
	 *
	 * @param IndexManager $index_manager Indexing pipeline.
	 */
	public function __construct( private readonly IndexManager $index_manager ) {
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
	public function version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array( 'reconcile' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function subscribed_events(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle( Task $task, AgentContext $context ): AgentResult {
		if ( 'kb.reconcile' !== $task->type ) {
			return AgentResult::refused( 'unsupported task type' );
		}

		$sources = $this->index_manager->start_reconcile();

		return AgentResult::ok(
			array(
				'sources'  => $sources,
				'enqueued' => count( $sources ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		return ( new HealthReport() )->add(
			'scheduler_available',
			function_exists( 'as_enqueue_async_action' ),
			'',
			false
		);
	}
}
