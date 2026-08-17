<?php
/**
 * Link graph service over agy_kb_links.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB;

use Agentyllo\KB\Retrieval\HybridRetriever;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves internal link edges to document ids, answers "where should I send
 * the visitor for X", surfaces related content by co-citation, and audits the
 * graph (broken links, orphans, periodic HTTP checks).
 */
final class LinkGraph {

	private const CHECK_MAX_AGE_DAYS = 7;

	/**
	 * Resolve internal, not-yet-attempted link targets against document
	 * permalinks (both exact and trailing-slash-variant matches; the
	 * permalink prefix index makes the IN lookup cheap).
	 *
	 * to_document_id encodes the attempt state: NULL = never attempted,
	 * 0 = attempted with no match (sentinel, treated as unresolved-external
	 * everywhere else), >0 = resolved document. The sentinel plus the
	 * ORDER BY id makes the batch cursor advance deterministically instead
	 * of re-selecting the same unmatched rows forever.
	 *
	 * @param int $batch Links examined per call.
	 * @return int Internal links still unattempted after this batch.
	 */
	public function resolve_targets( int $batch = 200 ): int {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, to_url FROM {$p}agy_kb_links WHERE is_internal = 1 AND to_document_id IS NULL AND to_url <> '' ORDER BY id ASC LIMIT %d",
				max( 1, $batch )
			),
			ARRAY_A
		);

		if ( $links ) {
			$candidates = array();
			foreach ( $links as $link ) {
				$url                = (string) $link['to_url'];
				$candidates[ $url ] = true;
				$alt                = str_ends_with( $url, '/' ) ? rtrim( $url, '/' ) : $url . '/';
				$candidates[ $alt ] = true;
			}
			$candidates = array_keys( $candidates );

			$placeholders = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$docs = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, permalink FROM {$p}agy_kb_documents WHERE permalink IN ({$placeholders})", ...$candidates ),
				ARRAY_A
			);

			$by_permalink = array();
			foreach ( (array) $docs as $doc ) {
				$by_permalink[ (string) $doc['permalink'] ] = (int) $doc['id'];
			}

			$assignments = array();
			$unmatched   = array();
			foreach ( $links as $link ) {
				$url    = (string) $link['to_url'];
				$alt    = str_ends_with( $url, '/' ) ? rtrim( $url, '/' ) : $url . '/';
				$doc_id = $by_permalink[ $url ] ?? $by_permalink[ $alt ] ?? 0;
				if ( $doc_id > 0 ) {
					$assignments[ $doc_id ][] = (int) $link['id'];
				} else {
					$unmatched[] = (int) $link['id'];
				}
			}

			foreach ( $assignments as $doc_id => $link_ids ) {
				$in = implode( ',', array_map( 'absint', $link_ids ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}agy_kb_links SET to_document_id = %d WHERE id IN ({$in})", $doc_id ) );
			}

			if ( $unmatched ) {
				$in = implode( ',', array_map( 'absint', $unmatched ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( "UPDATE {$p}agy_kb_links SET to_document_id = 0 WHERE id IN ({$in})" );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}agy_kb_links WHERE is_internal = 1 AND to_document_id IS NULL AND to_url <> ''"
		);
	}

	/**
	 * Best destination URLs for a topic: retrieval results with a +0.3 score
	 * bonus for documents a menu links to (menu placement = owner-curated
	 * importance signal).
	 *
	 * @param string          $topic     Topic / user intent.
	 * @param HybridRetriever $retriever Retrieval engine.
	 * @param int             $limit     Max URLs.
	 * @return array<int, array{title: string, url: string, score: float}>
	 */
	public function best_url_for( string $topic, HybridRetriever $retriever, int $limit = 3 ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$limit   = max( 1, $limit );
		$results = $retriever->search( $topic, array( 'limit' => max( 5, $limit * 2 ) ) );
		if ( ! $results ) {
			return array();
		}

		$doc_ids = array_values( array_unique( array_map( static fn ( array $r ): int => (int) $r['document_id'], $results ) ) );
		$in      = implode( ',', array_map( 'absint', $doc_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$menu_targets = $wpdb->get_col( "SELECT DISTINCT to_document_id FROM {$p}agy_kb_links WHERE rel = 'menu' AND to_document_id IN ({$in})" );
		$in_menu      = array_flip( array_map( 'intval', (array) $menu_targets ) );

		$best = array();
		foreach ( $results as $result ) {
			$url = (string) $result['permalink'];
			if ( '' === $url ) {
				continue;
			}
			$doc_id = (int) $result['document_id'];
			$score  = (float) $result['score'] + ( isset( $in_menu[ $doc_id ] ) ? 0.3 : 0.0 );
			if ( ! isset( $best[ $url ] ) || $score > $best[ $url ]['score'] ) {
				$best[ $url ] = array(
					'title' => (string) $result['title'],
					'url'   => $url,
					'score' => round( $score, 4 ),
				);
			}
		}

		$best = array_values( $best );
		usort( $best, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return array_slice( $best, 0, $limit );
	}

	/**
	 * Related documents for one document: co-citation first (documents linked
	 * from the same sources that link here), padded with same-subtype recent
	 * documents when co-citation is thin.
	 *
	 * @param int $doc_id Document id.
	 * @param int $limit  Max related documents.
	 * @return array<int, array{document_id: int, title: string, url: string, via: string}>
	 */
	public function related_for_document( int $doc_id, int $limit = 5 ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$limit = max( 1, $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cocited = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l2.to_document_id AS related_id, COUNT(*) AS shared
				 FROM {$p}agy_kb_links l1
				 INNER JOIN {$p}agy_kb_links l2 ON l2.from_document_id = l1.from_document_id
				 WHERE l1.to_document_id = %d AND l2.to_document_id > 0 AND l2.to_document_id <> %d
				 GROUP BY l2.to_document_id
				 ORDER BY shared DESC
				 LIMIT %d",
				$doc_id,
				$doc_id,
				$limit * 2
			),
			ARRAY_A
		);

		$out  = array();
		$seen = array( $doc_id => true );

		$related_ids = array_map( static fn ( array $r ): int => (int) $r['related_id'], (array) $cocited );
		foreach ( $this->hydrate_documents( $related_ids ) as $doc ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$seen[ $doc['document_id'] ] = true;
			$doc['via']                  = 'cocitation';
			$out[]                       = $doc;
		}

		if ( count( $out ) < $limit ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$self = $wpdb->get_row(
				$wpdb->prepare( "SELECT source, subtype FROM {$p}agy_kb_documents WHERE id = %d", $doc_id ),
				ARRAY_A
			);

			if ( $self ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$siblings = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id AS document_id, title, permalink FROM {$p}agy_kb_documents
						 WHERE source = %s AND subtype = %s AND status = %s AND id <> %d
						 ORDER BY indexed_at DESC LIMIT %d",
						(string) $self['source'],
						(string) $self['subtype'],
						Store::STATUS_ACTIVE,
						$doc_id,
						$limit * 2
					),
					ARRAY_A
				);

				foreach ( (array) $siblings as $sibling ) {
					if ( count( $out ) >= $limit ) {
						break;
					}
					$sid = (int) $sibling['document_id'];
					if ( isset( $seen[ $sid ] ) ) {
						continue;
					}
					$seen[ $sid ] = true;
					$out[]        = array(
						'document_id' => $sid,
						'title'       => (string) $sibling['title'],
						'url'         => (string) $sibling['permalink'],
						'via'         => 'sibling',
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Graph audit for the dashboard/report task.
	 *
	 * @return array{broken: array<int, array>, orphans: array<int, array>}
	 */
	public function broken_and_orphans(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$broken = $wpdb->get_results(
			"SELECT l.id AS link_id, l.to_url, l.anchor, l.http_status, l.checked_at,
				l.from_document_id, COALESCE(d.title, '') AS from_title
			 FROM {$p}agy_kb_links l
			 LEFT JOIN {$p}agy_kb_documents d ON d.id = l.from_document_id
			 WHERE l.http_status >= 400
			 ORDER BY l.http_status DESC, l.id ASC
			 LIMIT 500",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$orphans = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.id AS document_id, d.source, d.subtype, d.title, d.permalink
				 FROM {$p}agy_kb_documents d
				 WHERE d.status = %s AND d.permalink <> ''
				 AND NOT EXISTS (
					SELECT 1 FROM {$p}agy_kb_links l
					WHERE l.to_document_id = d.id AND l.is_internal = 1
				 )
				 ORDER BY d.id ASC
				 LIMIT 500",
				Store::STATUS_ACTIVE
			),
			ARRAY_A
		);

		$format_broken = static fn ( array $row ): array => array(
			'link_id'          => (int) $row['link_id'],
			'to_url'           => (string) $row['to_url'],
			'anchor'           => (string) $row['anchor'],
			'http_status'      => (int) $row['http_status'],
			'checked_at'       => null !== $row['checked_at'] ? (string) $row['checked_at'] : null,
			'from_document_id' => null !== $row['from_document_id'] ? (int) $row['from_document_id'] : null,
			'from_title'       => (string) $row['from_title'],
		);

		$format_orphan = static fn ( array $row ): array => array(
			'document_id' => (int) $row['document_id'],
			'source'      => (string) $row['source'],
			'subtype'     => (string) $row['subtype'],
			'title'       => (string) $row['title'],
			'permalink'   => (string) $row['permalink'],
		);

		return array(
			'broken'  => array_map( $format_broken, (array) $broken ),
			'orphans' => array_map( $format_orphan, (array) $orphans ),
		);
	}

	/**
	 * HTTP-check a batch of internal resolved targets, oldest-checked first
	 * (never-checked first of all). One HEAD per distinct URL; the result is
	 * written to every link edge sharing that URL. Transport failures are
	 * recorded as 599 so they surface in the broken report.
	 *
	 * @param int $batch Distinct URLs per call.
	 * @return int Distinct URLs still unchecked or older than 7 days.
	 */
	public function check_links_batch( int $batch = 10 ): int {
		global $wpdb;
		$p = $wpdb->prefix;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::CHECK_MAX_AGE_DAYS * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$targets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT to_url_hash, to_url
				 FROM {$p}agy_kb_links
				 WHERE is_internal = 1 AND to_document_id > 0
				 AND ( checked_at IS NULL OR checked_at < %s )
				 GROUP BY to_url_hash, to_url
				 ORDER BY ( MIN(checked_at) IS NULL ) DESC, MIN(checked_at) ASC
				 LIMIT %d",
				$cutoff,
				max( 1, $batch )
			),
			ARRAY_A
		);

		$now = gmdate( 'Y-m-d H:i:s' );

		foreach ( (array) $targets as $target ) {
			$response = wp_remote_head(
				(string) $target['to_url'],
				array(
					'timeout'     => 5,
					'redirection' => 3,
				)
			);

			$status = is_wp_error( $response ) ? 599 : (int) wp_remote_retrieve_response_code( $response );
			if ( 0 === $status ) {
				$status = 599;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}agy_kb_links SET http_status = %d, checked_at = %s WHERE to_url_hash = %s AND is_internal = 1",
					$status,
					$now,
					(string) $target['to_url_hash']
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT to_url_hash) FROM {$p}agy_kb_links
				 WHERE is_internal = 1 AND to_document_id > 0
				 AND ( checked_at IS NULL OR checked_at < %s )",
				$cutoff
			)
		);
	}

	/**
	 * Hydrate active documents preserving the given id order.
	 *
	 * @param int[] $doc_ids Document ids.
	 * @return array<int, array{document_id: int, title: string, url: string}>
	 */
	private function hydrate_documents( array $doc_ids ): array {
		if ( ! $doc_ids ) {
			return array();
		}

		global $wpdb;
		$p  = $wpdb->prefix;
		$in = implode( ',', array_map( 'absint', $doc_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, title, permalink FROM {$p}agy_kb_documents WHERE id IN ({$in}) AND status = %s", Store::STATUS_ACTIVE ),
			ARRAY_A
		);

		$by_id = array();
		foreach ( (array) $rows as $row ) {
			$by_id[ (int) $row['id'] ] = array(
				'document_id' => (int) $row['id'],
				'title'       => (string) $row['title'],
				'url'         => (string) $row['permalink'],
			);
		}

		$out = array();
		foreach ( $doc_ids as $id ) {
			if ( isset( $by_id[ (int) $id ] ) ) {
				$out[] = $by_id[ (int) $id ];
			}
		}

		return $out;
	}
}
