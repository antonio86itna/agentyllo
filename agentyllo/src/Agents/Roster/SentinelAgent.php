<?php
/**
 * Sentinel: daily health sweeps + quarantine enforcement.
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
use Agentyllo\Agents\Kernel\Quarantine;
use Agentyllo\Agents\Kernel\Registry;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Runs self_check() on every registered agent, records streaks with the
 * quarantine protocol, and stores a sweep summary for the dashboard.
 */
final class SentinelAgent implements Agent {

	public const ID = 'sentinel';

	/**
	 * Constructor.
	 *
	 * @param Registry   $registry   Registry.
	 * @param Quarantine $quarantine Quarantine protocol.
	 */
	public function __construct(
		private readonly Registry $registry,
		private readonly Quarantine $quarantine,
	) {
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
		return array( 'health' );
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
		if ( 'sweep.health' !== $task->type ) {
			return AgentResult::refused( 'unsupported task type' );
		}

		$summary = array();

		foreach ( $this->registry->all() as $id => $agent ) {
			if ( self::ID === $id ) {
				continue;
			}
			if ( $context->remaining_ms() < 2000 ) {
				return AgentResult::partial( array( 'summary' => $summary ), 0.8 );
			}

			try {
				$report = $agent->self_check( $context );
			} catch ( Throwable $e ) {
				$context->journal()->error( $id, $e, $task );
				$report = ( new HealthReport() )->add( 'self_check_runs', false, get_class( $e ) . ': ' . $e->getMessage(), true );
			}

			$this->quarantine->record_sweep( $id, $report );

			$summary[ $id ] = array(
				'healthy'  => $report->healthy(),
				'checks'   => $report->checks(),
				'swept_at' => time(),
			);
		}

		$context->memory()->remember( self::ID, 'last_sweep', array( 'at' => time(), 'summary' => $summary ), 'state' );

		return AgentResult::ok( array( 'agents_swept' => count( $summary ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		global $wpdb;

		$report = new HealthReport();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$memory_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'agyl_agent_memory' ) );
		$report->add( 'memory_table_exists', ! empty( $memory_table ), '', true );

		$report->add( 'scheduler_available', function_exists( 'as_enqueue_async_action' ), '', true );

		return $report;
	}
}
