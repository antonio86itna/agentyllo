<?php
/**
 * Agent quarantine protocol.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Kernel;

use Agentyllo\Agents\Contracts\EventBusInterface;
use Agentyllo\Agents\Contracts\HealthReport;
use Agentyllo\Agents\Contracts\JournalInterface;

defined( 'ABSPATH' ) || exit;

/**
 * A critical health check failing three consecutive sweeps disables the
 * agent; the pipeline routes around it and the dashboard raises an alert.
 * Streak state lives in the registry's per-agent config.
 */
final class Quarantine {

	private const STREAK_LIMIT = 3;

	/**
	 * Constructor.
	 *
	 * @param Registry          $registry Registry (config storage).
	 * @param JournalInterface  $journal  Journal.
	 * @param EventBusInterface $bus      Event bus.
	 */
	public function __construct(
		private readonly Registry $registry,
		private readonly JournalInterface $journal,
		private readonly EventBusInterface $bus,
	) {
	}

	/**
	 * Record a sweep result and quarantine on a third consecutive critical failure.
	 *
	 * @param string       $agent_id Agent id.
	 * @param HealthReport $report   Self-check report.
	 */
	public function record_sweep( string $agent_id, HealthReport $report ): void {
		$config  = $this->registry->config( $agent_id );
		$streaks = is_array( $config['streaks'] ) ? $config['streaks'] : array();
		$failed  = $report->critical_failures();

		// Reset streaks for checks that pass now.
		foreach ( array_keys( $streaks ) as $check ) {
			if ( ! in_array( $check, $failed, true ) ) {
				unset( $streaks[ $check ] );
			}
		}

		$trip = null;
		foreach ( $failed as $check ) {
			$streaks[ $check ] = (int) ( $streaks[ $check ] ?? 0 ) + 1;
			if ( $streaks[ $check ] >= self::STREAK_LIMIT ) {
				$trip = $check;
			}
		}

		$this->registry->update_config( $agent_id, array( 'streaks' => $streaks ) );

		if ( null !== $trip && null === $config['quarantine'] ) {
			$this->disable( $agent_id, sprintf( 'critical check "%s" failed %d consecutive sweeps', $trip, self::STREAK_LIMIT ) );
		}
	}

	/**
	 * Quarantine an agent.
	 *
	 * @param string $agent_id Agent id.
	 * @param string $reason   Human-readable reason.
	 */
	public function disable( string $agent_id, string $reason ): void {
		$this->registry->update_config(
			$agent_id,
			array(
				'quarantine' => array(
					'reason' => $reason,
					'at'     => time(),
				),
			)
		);

		$this->journal->log( $agent_id, 'warn', 'agent.quarantined', $reason );
		$this->bus->emit( 'agent.quarantined', array( 'agent_id' => $agent_id, 'reason' => $reason ) );
	}

	/**
	 * Release an agent from quarantine (admin action or passing sweep).
	 *
	 * @param string $agent_id Agent id.
	 */
	public function release( string $agent_id ): void {
		$this->registry->update_config(
			$agent_id,
			array(
				'quarantine' => null,
				'streaks'    => array(),
			)
		);

		$this->journal->log( $agent_id, 'info', 'agent.released', 'released from quarantine' );
		$this->bus->emit( 'agent.released', array( 'agent_id' => $agent_id ) );
	}
}
