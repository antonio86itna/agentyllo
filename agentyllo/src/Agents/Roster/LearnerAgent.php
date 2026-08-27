<?php
/**
 * Learner: mines the journal for recurring failures and promotes them into
 * lessons other agents consult before acting.
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

defined( 'ABSPATH' ) || exit;

/**
 * Nightly. Learning artifacts are data, never code: a lesson is a bounded
 * avoidance rule ({match, action, note}) with decaying importance, applied by
 * the kernel before handle(). Conservative policy:
 *   ≥3 occurrences → 'note' (informational, surfaced in admin)
 *   ≥5 occurrences → 'skip' (kernel drops that exact task signature)
 */
final class LearnerAgent implements Agent {

	public const ID = 'learner';

	private const NOTE_THRESHOLD = 3;
	private const SKIP_THRESHOLD = 5;

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
		return array( 'learning' );
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
		if ( 'learn.mine_journal' !== $task->type ) {
			return AgentResult::refused( 'unsupported task type' );
		}

		$promoted = 0;

		foreach ( $context->journal()->recurring_errors( self::NOTE_THRESHOLD, 30 ) as $recurring ) {
			$agent_id  = $recurring['agent_id'];
			$task_type = (string) ( $recurring['context']['task_type'] ?? '' );

			if ( '' === $task_type || self::ID === $agent_id ) {
				continue;
			}

			$action = $recurring['occurrences'] >= self::SKIP_THRESHOLD ? 'skip' : 'note';

			$context->memory()->remember(
				$agent_id,
				'lesson:' . $recurring['fingerprint'],
				array(
					'match'       => array( 'task_type' => $task_type ),
					'action'      => $action,
					'note'        => sprintf(
						'%s failed %d times: %s',
						$task_type,
						$recurring['occurrences'],
						(string) ( $recurring['context']['message'] ?? $recurring['event'] )
					),
					'fingerprint' => $recurring['fingerprint'],
					'promoted_at' => time(),
				),
				'lesson',
				min( 90, 40 + 10 * $recurring['occurrences'] ),
			);

			++$promoted;

			$context->bus()->emit(
				'lesson.learned',
				array(
					'agent_id'    => $agent_id,
					'task_type'   => $task_type,
					'action'      => $action,
					'occurrences' => $recurring['occurrences'],
				)
			);
		}

		return AgentResult::ok( array( 'lessons_promoted' => $promoted ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$journal_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'agyl_agent_journal' ) );

		return ( new HealthReport() )
			->add( 'journal_table_exists', ! empty( $journal_table ), '', true );
	}
}
