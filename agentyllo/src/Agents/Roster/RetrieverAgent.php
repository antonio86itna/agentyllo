<?php
/**
 * Retriever: KB search on behalf of other agents and pipelines.
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
use Agentyllo\KB\Retrieval\HybridRetriever;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Thin agent façade over the HybridRetriever so retrieval is addressable on
 * the task bus (kb.search) with confidence scoring: weak/no results trigger
 * the orchestrator's verification pass.
 */
final class RetrieverAgent implements Agent {

	public const ID = 'retriever';

	/**
	 * Constructor.
	 *
	 * @param HybridRetriever $retriever Retrieval engine.
	 */
	public function __construct( private readonly HybridRetriever $retriever ) {
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
		return array( 'retrieve', 'search' );
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
		if ( 'kb.search' !== $task->type ) {
			return AgentResult::refused( 'unsupported task type' );
		}

		$query = trim( (string) ( $task->payload['query'] ?? '' ) );
		if ( '' === $query ) {
			return AgentResult::failed( 'empty query' );
		}

		$lang  = (string) ( $task->payload['lang'] ?? '' );
		$limit = max( 1, min( 20, (int) ( $task->payload['limit'] ?? 8 ) ) );

		try {
			$results = $this->retriever->search(
				$query,
				array(
					'lang'  => $lang,
					'limit' => $limit,
				)
			);
		} catch ( Throwable $e ) {
			$context->journal()->error( self::ID, $e, $task );

			return AgentResult::failed( 'search error: ' . $e->getMessage() );
		}

		$top_score  = $results ? (float) $results[0]['score'] : 0.0;
		$confidence = min( 1.0, $top_score / 8 );
		$needs      = empty( $results ) || $top_score < 1;

		return new AgentResult(
			AgentResult::STATUS_OK,
			array(
				'count'     => count( $results ),
				'top_score' => $top_score,
				'results'   => $results,
			),
			$confidence,
			array(),
			$needs
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		global $wpdb;

		$report = new HealthReport();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$terms_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'agy_kb_terms' ) );
		$report->add( 'terms_table_exists', ! empty( $terms_table ), '', true );

		return $report;
	}
}
