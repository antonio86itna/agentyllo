<?php
/**
 * KB curator: owns indexing and purging of the knowledge base.
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
use Agentyllo\KB\AdapterRegistry;
use Agentyllo\KB\Indexer\IndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Thin roster surface over the IndexManager pipeline: kicks off full crawls
 * and indexes single items on demand. The heavy lifting (budgeting, batch
 * chaining, purge/reconcile jobs) lives in KB\Indexer\IndexManager; pipeline
 * failures are journaled under this agent's id.
 */
final class KbCuratorAgent implements Agent {

	public const ID = 'kb_curator';

	/**
	 * Constructor.
	 *
	 * @param IndexManager    $index_manager Indexing pipeline.
	 * @param AdapterRegistry $adapters      Source adapters.
	 */
	public function __construct(
		private readonly IndexManager $index_manager,
		private readonly AdapterRegistry $adapters,
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
		return array( 'index', 'purge' );
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
		switch ( $task->type ) {
			case 'kb.crawl_all':
				$only = (string) ( $task->payload['source'] ?? '' );
				$this->index_manager->start_full_crawl( '' !== $only ? $only : null );

				return AgentResult::ok(
					array(
						'started' => true,
						'source'  => '' !== $only ? $only : null,
					)
				);

			case 'kb.index_item':
				$source      = (string) ( $task->payload['source'] ?? '' );
				$external_id = (string) ( $task->payload['external_id'] ?? '' );
				if ( '' === $source || '' === $external_id ) {
					return AgentResult::failed( 'missing source/external_id' );
				}

				$outcome = $this->index_manager->index_item( $source, $external_id );

				return ! empty( $outcome['ok'] )
					? AgentResult::ok( $outcome )
					: AgentResult::failed( (string) ( $outcome['reason'] ?? 'index_failed' ), $outcome );

			default:
				return AgentResult::refused( 'unsupported task type' );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		global $wpdb;

		$report = new HealthReport();

		foreach ( array( 'agyl_kb_documents', 'agyl_kb_chunks', 'agyl_kb_terms', 'agyl_kb_links' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) );
			$report->add( $table . '_exists', ! empty( $found ), '', true );
		}

		$adapters = $this->adapters->all();
		$report->add(
			'adapters_available',
			array() !== $adapters,
			$adapters ? implode( ', ', array_keys( $adapters ) ) : 'no source adapters registered'
		);

		return $report;
	}
}
