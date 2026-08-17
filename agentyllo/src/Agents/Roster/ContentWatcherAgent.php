<?php
/**
 * Content watcher: roster surface of KB delta synchronisation.
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
 * The hook mechanics live in KB\Indexer\IndexManager::register(): it attaches
 * every adapter's delta_hooks() on init, debounces upserts through a unique
 * 30-second Action Scheduler action, and applies deletes to the store
 * immediately. This agent takes no tasks — it exists so delta sync is visible
 * in the roster and covered by the sentinel's daily health sweep.
 */
final class ContentWatcherAgent implements Agent {

	public const ID = 'content_watcher';

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
		return array( 'watch' );
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
		return AgentResult::refused( 'content_watcher takes no tasks; delta hooks live in IndexManager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		$registered = IndexManager::did_register();

		return ( new HealthReport() )->add(
			'delta_hooks_registered',
			$registered,
			$registered ? '' : 'IndexManager::register() has not run on init',
			true
		);
	}
}
