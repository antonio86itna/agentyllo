<?php
/**
 * Statistics: nightly PII-free rollups + report queries + unanswered queue.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Stats;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * Raw conversations/messages are purged by retention; rollups
 * (agyl_stats_daily / agyl_stats_intents) hold only counters and survive
 * 24 months. The unanswered queue feeds the copilot suggestions.
 */
final class Stats {

	private const ROLLUP_RETENTION_MONTHS = 24;

	/**
	 * Record an unanswered/refused visitor question (called by the chat
	 * controller). Deduped by normalized-question hash; PII already masked
	 * upstream by the redactor.
	 *
	 * @param string      $question Visitor text (redacted).
	 * @param string      $lang     Language.
	 * @param string|null $intent   Classified intent.
	 */
	public function record_unanswered( string $question, string $lang, ?string $intent ): void {
		global $wpdb;

		$norm = mb_strtolower( trim( (string) preg_replace( '/[\s\p{P}]+/u', ' ', $question ) ) );
		if ( mb_strlen( $norm ) < 3 ) {
			return;
		}
		$hash = sha1( $norm );
		$now  = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . 'agyl_stats_unanswered (question_hash, question_sample, lang, intent, hits, first_seen, last_seen, status)
				 VALUES (%s, %s, %s, %s, 1, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), question_sample = VALUES(question_sample)',
				$hash,
				mb_substr( $question, 0, 500 ),
				substr( $lang, 0, 12 ),
				$intent ? substr( $intent, 0, 100 ) : null,
				$now,
				$now,
				'open'
			)
		);
	}

	/**
	 * Nightly rollup of yesterday (and today so far, so the dashboard is
	 * fresh) into agyl_stats_daily / agyl_stats_intents. Idempotent (upsert).
	 *
	 * @param int $days_back How many days back to (re)compute. Default 2.
	 */
	public function rollup( int $days_back = 2 ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		for ( $i = 0; $i < $days_back; $i++ ) {
			$day = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.tier,
						COUNT(DISTINCT m.conversation_id) AS conversations,
						COUNT(*) AS messages,
						SUM(m.role = 'assistant' AND m.flagged_unanswered = 1) AS unanswered,
						SUM(m.role = 'assistant' AND m.intent = 'out_of_scope') AS oos_refusals,
						SUM(m.role = 'assistant' AND m.intent = 'handoff') AS handoffs,
						SUM(m.role = 'assistant' AND m.kb_sources IS NOT NULL AND m.kb_sources <> '' AND m.kb_sources <> '[]') AS kb_hit_answers,
						AVG(CASE WHEN m.role = 'assistant' THEN m.latency_ms END) AS avg_latency,
						SUM(COALESCE(m.tokens_in, 0)) AS tokens_in,
						SUM(COALESCE(m.tokens_out, 0)) AS tokens_out,
						SUM(COALESCE(m.cost_usd, 0)) AS cost_usd
					 FROM {$p}agyl_messages m
					 WHERE DATE(m.created_at) = %s
					 GROUP BY m.tier",
					$day
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $r ) {
				$p95      = $this->p95_latency( $day, (string) $r['tier'] );
				$resolved = $this->resolved_count( $day, (string) $r['tier'] );

				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$p}agyl_stats_daily (stat_date, tier, conversations, messages, resolved, handoffs, oos_refusals, unanswered, kb_hit_answers, avg_latency_ms, p95_latency_ms, tokens_in, tokens_out, cost_usd)
						 VALUES (%s, %s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %f)
						 ON DUPLICATE KEY UPDATE conversations = VALUES(conversations), messages = VALUES(messages), resolved = VALUES(resolved), handoffs = VALUES(handoffs),
							oos_refusals = VALUES(oos_refusals), unanswered = VALUES(unanswered), kb_hit_answers = VALUES(kb_hit_answers), avg_latency_ms = VALUES(avg_latency_ms),
							p95_latency_ms = VALUES(p95_latency_ms), tokens_in = VALUES(tokens_in), tokens_out = VALUES(tokens_out), cost_usd = VALUES(cost_usd)",
						$day,
						(string) $r['tier'],
						(int) $r['conversations'],
						(int) $r['messages'],
						$resolved,
						(int) $r['handoffs'],
						(int) $r['oos_refusals'],
						(int) $r['unanswered'],
						(int) $r['kb_hit_answers'],
						(int) round( (float) $r['avg_latency'] ),
						$p95,
						(int) $r['tokens_in'],
						(int) $r['tokens_out'],
						(float) $r['cost_usd']
					)
				);
			}

			$intents = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT intent, COUNT(*) hits, SUM(flagged_unanswered = 0) answered
					 FROM {$p}agyl_messages WHERE role = 'assistant' AND intent IS NOT NULL AND DATE(created_at) = %s GROUP BY intent",
					$day
				),
				ARRAY_A
			);
			foreach ( (array) $intents as $r ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$p}agyl_stats_intents (stat_date, intent, hits, answered) VALUES (%s, %s, %d, %d)
						 ON DUPLICATE KEY UPDATE hits = VALUES(hits), answered = VALUES(answered)",
						$day,
						(string) $r['intent'],
						(int) $r['hits'],
						(int) $r['answered']
					)
				);
			}
			// phpcs:enable
		}

		$this->prune_rollups();
	}

	/**
	 * Report: daily series for a range.
	 *
	 * @param int $days Range length (7|30|90).
	 */
	public function daily( int $days ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT stat_date, tier, conversations, messages, resolved, handoffs, oos_refusals, unanswered, kb_hit_answers, avg_latency_ms, p95_latency_ms, tokens_in, tokens_out, cost_usd
				 FROM ' . $wpdb->prefix . 'agyl_stats_daily WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) ORDER BY stat_date ASC',
				max( 1, $days )
			),
			ARRAY_A
		);
	}

	/**
	 * Report: totals + derived rates for a range.
	 *
	 * @param int $days Range.
	 */
	public function totals( int $days ): array {
		$sum = array(
			'conversations'  => 0,
			'messages'       => 0,
			'resolved'       => 0,
			'handoffs'       => 0,
			'oos_refusals'   => 0,
			'unanswered'     => 0,
			'kb_hit_answers' => 0,
			'tokens_in'      => 0,
			'tokens_out'     => 0,
			'cost_usd'       => 0.0,
		);
		$latencies = array();
		$by_tier   = array();

		foreach ( $this->daily( $days ) as $row ) {
			foreach ( array_keys( $sum ) as $k ) {
				$sum[ $k ] += 'cost_usd' === $k ? (float) $row[ $k ] : (int) $row[ $k ];
			}
			if ( null !== $row['avg_latency_ms'] ) {
				$latencies[] = (int) $row['avg_latency_ms'];
			}
			$tier                     = (string) $row['tier'];
			$by_tier[ $tier ]         = $by_tier[ $tier ] ?? array( 'messages' => 0, 'avg_latency_ms' => array(), 'p95_latency_ms' => array() );
			$by_tier[ $tier ]['messages'] += (int) $row['messages'];
			if ( null !== $row['avg_latency_ms'] ) {
				$by_tier[ $tier ]['avg_latency_ms'][] = (int) $row['avg_latency_ms'];
			}
			if ( null !== $row['p95_latency_ms'] ) {
				$by_tier[ $tier ]['p95_latency_ms'][] = (int) $row['p95_latency_ms'];
			}
		}

		foreach ( $by_tier as &$t ) {
			$t['avg_latency_ms'] = $t['avg_latency_ms'] ? (int) round( array_sum( $t['avg_latency_ms'] ) / count( $t['avg_latency_ms'] ) ) : null;
			$t['p95_latency_ms'] = $t['p95_latency_ms'] ? max( $t['p95_latency_ms'] ) : null;
		}
		unset( $t );

		$assistant_msgs = max( 1, (int) floor( $sum['messages'] / 2 ) );

		return $sum + array(
			'deflection_rate' => $sum['conversations'] > 0 ? round( $sum['resolved'] / $sum['conversations'], 3 ) : null,
			'kb_coverage'     => round( $sum['kb_hit_answers'] / $assistant_msgs, 3 ),
			'avg_latency_ms'  => $latencies ? (int) round( array_sum( $latencies ) / count( $latencies ) ) : null,
			'by_tier'         => $by_tier,
		);
	}

	/**
	 * Report: top intents in range.
	 *
	 * @param int $days  Range.
	 * @param int $limit Rows.
	 */
	public function top_intents( int $days, int $limit = 10 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT intent, SUM(hits) hits, SUM(answered) answered FROM ' . $wpdb->prefix . 'agyl_stats_intents
				 WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) GROUP BY intent ORDER BY hits DESC LIMIT %d',
				max( 1, $days ),
				max( 1, min( 50, $limit ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Report: open unanswered questions, most frequent first.
	 *
	 * @param int $limit Rows.
	 */
	public function unanswered( int $limit = 20 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, question_sample, lang, intent, hits, first_seen, last_seen, status FROM ' . $wpdb->prefix . 'agyl_stats_unanswered
				 WHERE status = %s ORDER BY hits DESC, last_seen DESC LIMIT %d',
				'open',
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Mark an unanswered row (dismissed | resolved).
	 *
	 * @param int    $id     Row id.
	 * @param string $status New status.
	 */
	public function set_unanswered_status( int $id, string $status ): bool {
		global $wpdb;

		if ( ! in_array( $status, array( 'open', 'dismissed', 'resolved' ), true ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( $wpdb->prefix . 'agyl_stats_unanswered', array( 'status' => $status ), array( 'id' => $id ) );
	}

	/**
	 * Recent conversations (admin list, paginated).
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Rows per page.
	 */
	public function conversations( int $page, int $per_page ): array {
		global $wpdb;
		$p        = $wpdb->prefix;
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, uuid, visitor_name, visitor_email, lang, tier, message_count, resolved, handoff, started_at, last_activity_at
				 FROM {$p}agyl_conversations ORDER BY last_activity_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agyl_conversations" );
		// phpcs:enable

		return array(
			'items'    => (array) $rows,
			'total'    => $total,
			'page'     => max( 1, $page ),
			'per_page' => $per_page,
		);
	}

	/**
	 * Full transcript of one conversation.
	 *
	 * @param int $id Conversation id.
	 */
	public function transcript( int $id ): ?array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}agyl_conversations WHERE id = %d", $id ), ARRAY_A );
		if ( ! $conv ) {
			return null;
		}
		$conv['messages'] = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, role, content, blocks, tier, intent, confidence, latency_ms, flagged_unanswered, created_at FROM {$p}agyl_messages WHERE conversation_id = %d ORDER BY id", $id ),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $conv['messages'] as &$m ) {
			$decoded     = json_decode( (string) ( $m['blocks'] ?? '' ), true );
			$m['blocks'] = is_array( $decoded ) ? $decoded : array();
		}
		unset( $m );

		return $conv;
	}

	/**
	 * p95 latency of assistant messages for a day/tier (PHP-side percentile —
	 * portable across MySQL/MariaDB versions).
	 *
	 * @param string $day  Y-m-d.
	 * @param string $tier Tier.
	 */
	private function p95_latency( string $day, string $tier ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$values = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT latency_ms FROM ' . $wpdb->prefix . "agyl_messages WHERE role = 'assistant' AND latency_ms IS NOT NULL AND tier = %s AND DATE(created_at) = %s ORDER BY latency_ms ASC",
				$tier,
				$day
			)
		);
		if ( ! $values ) {
			return null;
		}
		$idx = (int) floor( 0.95 * ( count( $values ) - 1 ) );

		return (int) $values[ $idx ];
	}

	/**
	 * Resolved = conversation ended without a handoff and without any
	 * unanswered flag on that day.
	 *
	 * @param string $day  Y-m-d.
	 * @param string $tier Tier.
	 */
	private function resolved_count( string $day, string $tier ): int {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}agyl_conversations c
				 WHERE DATE(c.last_activity_at) = %s AND c.tier = %s AND c.handoff = 0
				   AND NOT EXISTS (SELECT 1 FROM {$p}agyl_messages m WHERE m.conversation_id = c.id AND m.flagged_unanswered = 1)",
				$day,
				$tier
			)
		);
	}

	/**
	 * Drop rollups older than 24 months.
	 */
	private function prune_rollups(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}agyl_stats_daily WHERE stat_date < DATE_SUB(CURDATE(), INTERVAL %d MONTH)", self::ROLLUP_RETENTION_MONTHS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}agyl_stats_intents WHERE stat_date < DATE_SUB(CURDATE(), INTERVAL %d MONTH)", self::ROLLUP_RETENTION_MONTHS ) );
		// phpcs:enable
	}
}
