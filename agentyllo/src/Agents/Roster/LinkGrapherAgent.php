<?php
/**
 * Link grapher: resolves, checks, and reports on the KB link graph.
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
use Agentyllo\KB\LinkGraph;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Task façade over the LinkGraph service: links.resolve (match internal
 * targets to documents), links.check (recurring HTTP audit batches),
 * links.report (broken links + orphan documents).
 */
final class LinkGrapherAgent implements Agent {

	public const ID = 'link_grapher';

	/**
	 * Constructor.
	 *
	 * @param LinkGraph $link_graph Link graph service.
	 */
	public function __construct( private readonly LinkGraph $link_graph ) {
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
		return array( 'links' );
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
		try {
			switch ( $task->type ) {
				case 'links.resolve':
					$batch      = max( 1, min( 1000, (int) ( $task->payload['batch'] ?? 200 ) ) );
					$unresolved = $this->link_graph->resolve_targets( $batch );

					return AgentResult::ok( array( 'unresolved' => $unresolved ) );

				case 'links.check':
					$batch     = max( 1, min( 50, (int) ( $task->payload['batch'] ?? 10 ) ) );
					$remaining = $this->link_graph->check_links_batch( $batch );

					return AgentResult::ok( array( 'remaining' => $remaining ) );

				case 'links.report':
					$report = $this->link_graph->broken_and_orphans();

					return AgentResult::ok(
						array(
							'broken_count' => count( $report['broken'] ),
							'orphan_count' => count( $report['orphans'] ),
							'broken'       => $report['broken'],
							'orphans'      => $report['orphans'],
						)
					);

				default:
					return AgentResult::refused( 'unsupported task type' );
			}
		} catch ( Throwable $e ) {
			$context->journal()->error( self::ID, $e, $task );

			return AgentResult::failed( 'link graph error: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		global $wpdb;

		$report = new HealthReport();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$links_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'agyl_kb_links' ) );
		$report->add( 'links_table_exists', ! empty( $links_table ), '', true );

		return $report;
	}
}
