<?php
/**
 * Janitor: retention, pruning, rotation, file GC.
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
use Agentyllo\Infra\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly maintenance. GDPR conversation retention joins in milestone M5;
 * the task type is stable from day one.
 */
final class JanitorAgent implements Agent {

	public const ID = 'janitor';

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
		return array( 'maintenance' );
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
		if ( 'clean.maintenance' !== $task->type ) {
			return AgentResult::refused( 'unsupported task type' );
		}

		$memory_pruned   = $context->memory()->prune();
		$journal_rotated = $context->journal()->rotate();
		$files_removed   = $this->gc_stale_cache_files();

		// Expired visitor sessions + day-old rate-limit events.
		$sessions_pruned = 0;
		if ( $context->services()->has( \Agentyllo\Chat\Session\SessionManager::class ) ) {
			$sessions_pruned = $context->services()->get( \Agentyllo\Chat\Session\SessionManager::class )->prune();
		}

		return AgentResult::ok(
			array(
				'memory_pruned'   => $memory_pruned,
				'journal_rotated' => $journal_rotated,
				'sessions_pruned' => $sessions_pruned,
				'files_removed'   => $files_removed,
			)
		);
	}

	/**
	 * Remove cache files older than 7 days from uploads/agentyllo/cache.
	 */
	private function gc_stale_cache_files(): int {
		$dir = Uploads::dir( 'cache' );
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$removed = 0;
		$cutoff  = time() - WEEK_IN_SECONDS;
		$items   = scandir( $dir );

		foreach ( ( false === $items ? array() : $items ) as $item ) {
			$path = $dir . '/' . $item;
			if ( '.' === $item || '..' === $item || 'index.php' === $item || ! is_file( $path ) ) {
				continue;
			}
			if ( (int) filemtime( $path ) < $cutoff ) {
				wp_delete_file( $path );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		return ( new HealthReport() )
			->add( 'uploads_writable', Uploads::ensure(), Uploads::base_dir(), true );
	}
}
