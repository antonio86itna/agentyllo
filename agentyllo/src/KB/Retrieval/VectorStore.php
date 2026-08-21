<?php
/**
 * Dense vector storage + brute-force / rerank cosine search.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Retrieval;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * agy_kb_vectors keeps one L2-normalized float32 vector per chunk per
 * embedding model (LONGBLOB, packed with pack('g*')). Search runs entirely in
 * PHP: for small/medium corpora (≤ FULL_SCAN_MAX vectors) the whole set is
 * scanned in pages — dense DISCOVERY of paraphrases the lexical channel
 * misses; above that, vectors only RERANK the lexical candidates so shared
 * hosts never read tens of MB per question. Native MariaDB VECTOR/HNSW is a
 * planned fast path (same public API).
 */
final class VectorStore {

	private const FULL_SCAN_MAX = 3000;
	private const PAGE          = 400;

	/**
	 * Upsert vectors for chunks.
	 *
	 * @param array<int, float[]> $vectors_by_chunk chunk_id => vector.
	 * @param array<int, int>     $documents        chunk_id => document_id.
	 * @param string              $model            Embedding model id.
	 */
	public function upsert( array $vectors_by_chunk, array $documents, string $model ): int {
		global $wpdb;

		$written = 0;
		$now     = gmdate( 'Y-m-d H:i:s' );
		foreach ( $vectors_by_chunk as $chunk_id => $vector ) {
			$normalized = self::normalize( $vector );
			if ( null === $normalized ) {
				continue;
			}
			$packed = pack( 'g*', ...$normalized );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'agy_kb_vectors (chunk_id, document_id, model, dims, vec, updated_at) VALUES (%d, %d, %s, %d, %s, %s)
					 ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), model = VALUES(model), dims = VALUES(dims), vec = VALUES(vec), updated_at = VALUES(updated_at)',
					(int) $chunk_id,
					(int) ( $documents[ $chunk_id ] ?? 0 ),
					substr( $model, 0, 80 ),
					count( $normalized ),
					$packed,
					$now
				)
			);
			if ( false !== $ok ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Cosine search. Returns chunk_id => similarity, best first, limited.
	 *
	 * @param float[]  $query      Query vector.
	 * @param string   $model      Embedding model id (only same-model rows).
	 * @param int      $limit      Max results.
	 * @param int[]    $candidates Optional chunk ids to restrict to (rerank mode).
	 * @return array<int, float>
	 */
	public function search( array $query, string $model, int $limit = 30, array $candidates = array() ): array {
		global $wpdb;

		$q = self::normalize( $query );
		if ( null === $q ) {
			return array();
		}
		$dims = count( $q );

		$total = $this->count( $model );
		if ( 0 === $total ) {
			return array();
		}
		if ( ! $candidates && $total > self::FULL_SCAN_MAX ) {
			return array(); // Rerank-only mode: caller passes lexical candidates.
		}

		$scores = array();
		$offset = 0;
		while ( true ) {
			if ( $candidates ) {
				$ids = implode( ',', array_map( 'absint', array_slice( $candidates, $offset, self::PAGE ) ) );
				if ( '' === $ids ) {
					break;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT chunk_id, vec FROM ' . $wpdb->prefix . "agy_kb_vectors WHERE model = %s AND dims = %d AND chunk_id IN ({$ids})", $model, $dims ), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT chunk_id, vec FROM ' . $wpdb->prefix . 'agy_kb_vectors WHERE model = %s AND dims = %d ORDER BY chunk_id LIMIT %d OFFSET %d', $model, $dims, self::PAGE, $offset ), ARRAY_A );
			}
			if ( ! is_array( $rows ) || ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$vec = unpack( 'g*', (string) $row['vec'] );
				if ( ! is_array( $vec ) || count( $vec ) !== $dims ) {
					continue;
				}
				$dot = 0.0;
				$i   = 1; // unpack() is 1-based.
				foreach ( $q as $value ) {
					$dot += $value * $vec[ $i ];
					++$i;
				}
				$scores[ (int) $row['chunk_id'] ] = $dot;
			}
			$offset += self::PAGE;
			if ( count( $rows ) < self::PAGE && ! $candidates ) {
				break;
			}
			if ( $candidates && $offset >= count( $candidates ) ) {
				break;
			}
		}

		arsort( $scores );

		return array_slice( $scores, 0, max( 1, $limit ), true );
	}

	/**
	 * Vectors stored for a model.
	 *
	 * @param string $model Model id.
	 */
	public function count( string $model ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'agy_kb_vectors WHERE model = %s', $model ) );
	}

	/**
	 * Active chunks lacking a vector for the model (oldest first).
	 *
	 * @param string $model Model id.
	 * @param int    $limit Batch size.
	 * @return array<int, array{id: int, document_id: int, content: string}>
	 */
	public function missing( string $model, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.id, c.document_id, c.content FROM ' . $wpdb->prefix . 'agy_kb_chunks c
				 INNER JOIN ' . $wpdb->prefix . 'agy_kb_documents d ON d.id = c.document_id AND d.status = %s
				 LEFT JOIN ' . $wpdb->prefix . 'agy_kb_vectors v ON v.chunk_id = c.id AND v.model = %s
				 WHERE v.chunk_id IS NULL ORDER BY c.id ASC LIMIT %d',
				'active',
				$model,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( static fn ( array $r ): array => array( 'id' => (int) $r['id'], 'document_id' => (int) $r['document_id'], 'content' => (string) $r['content'] ), $rows ) : array();
	}

	/**
	 * Drop vectors whose chunk no longer exists, plus rows of other models
	 * when $keep_model is given (model switch).
	 *
	 * @param string $keep_model Model to keep ('' = keep all models).
	 */
	public function gc( string $keep_model = '' ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$orphans = $wpdb->query( 'DELETE v FROM ' . $wpdb->prefix . 'agy_kb_vectors v LEFT JOIN ' . $wpdb->prefix . 'agy_kb_chunks c ON c.id = v.chunk_id WHERE c.id IS NULL' );
		$stale   = 0;
		if ( '' !== $keep_model ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stale = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'agy_kb_vectors WHERE model <> %s', $keep_model ) );
		}

		return (int) $orphans + (int) $stale;
	}

	/**
	 * Delete everything (provider removed).
	 */
	public function flush(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'agy_kb_vectors' );
	}

	/**
	 * L2-normalize; null for empty/zero vectors.
	 *
	 * @param float[] $vector Vector.
	 * @return float[]|null
	 */
	public static function normalize( array $vector ): ?array {
		$sum = 0.0;
		foreach ( $vector as $v ) {
			$sum += (float) $v * (float) $v;
		}
		if ( $sum <= 0.0 ) {
			return null;
		}
		$norm = sqrt( $sum );

		return array_map( static fn ( $v ): float => (float) $v / $norm, array_values( $vector ) );
	}

	/**
	 * Cosine similarity of two raw vectors (0.0 on mismatch).
	 *
	 * @param float[] $a Vector.
	 * @param float[] $b Vector.
	 */
	public static function cosine( array $a, array $b ): float {
		$na = self::normalize( $a );
		$nb = self::normalize( $b );
		if ( null === $na || null === $nb || count( $na ) !== count( $nb ) ) {
			return 0.0;
		}
		$dot = 0.0;
		foreach ( $na as $i => $v ) {
			$dot += $v * $nb[ $i ];
		}

		return $dot;
	}
}
