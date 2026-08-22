<?php
/**
 * Knowledge-base health metrics + nightly corpus statistics refresh.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB;

use Agentyllo\KB\AdapterRegistry;
use Closure;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * Computes coverage/staleness/quality metrics for the dashboard and refreshes
 * the corpus statistics retrieval depends on (agy_kb_avg_len) and the dynamic
 * stopword list (agy_kb_dynamic_stopwords).
 *
 * Stopword changes apply EVENTUALLY — by design. A refreshed list only
 * affects future tokenization (new indexing runs and new queries); already
 * indexed postings keep the terms they were built with until re-indexed.
 * The BM25 idf math makes near-ubiquitous leftover terms nearly weightless,
 * so the drift is cosmetic, not correctness-relevant.
 */
final class Health {

	private const SIMHASH_SAMPLE      = 20000;
	private const SIMHASH_BUCKET_CAP  = 50;
	private const SIMHASH_MAX_HAMMING = 3;
	private const STOPWORD_MIN_CHUNKS = 500;
	private const STOPWORD_DF_RATIO   = 0.2;
	private const STOPWORD_CAP        = 200;

	/**
	 * Resolver ( string $source, string $subtype ): bool — whether a
	 * source/subtype is enabled in the Content Sources settings.
	 *
	 * @var Closure
	 */
	private readonly Closure $enabled_resolver;

	/**
	 * Constructor.
	 *
	 * @param AdapterRegistry $adapters         Source adapter registry.
	 * @param callable        $enabled_resolver fn( string $source, string $subtype ): bool.
	 * @param Store           $store            KB repository.
	 */
	public function __construct(
		private readonly AdapterRegistry $adapters,
		callable $enabled_resolver,
		private readonly Store $store,
	) {
		$this->enabled_resolver = Closure::fromCallable( $enabled_resolver );
	}

	/**
	 * Compute all health metrics, persist them to option agy_kb_health
	 * ({computed_at, data}) and refresh corpus statistics.
	 *
	 * @return array Health data (the 'data' part of the persisted option).
	 */
	public function compute(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$counts        = $this->store->counts();
		$active        = array();
		$status_totals = array(
			Store::STATUS_EXCLUDED => 0,
			Store::STATUS_PURGING  => 0,
			Store::STATUS_ERROR    => 0,
		);

		foreach ( $counts as $row ) {
			if ( Store::STATUS_ACTIVE === $row['status'] ) {
				$active[ $row['source'] ][ $row['subtype'] ] = $row['docs'];
			} elseif ( isset( $status_totals[ $row['status'] ] ) ) {
				$status_totals[ $row['status'] ] += $row['docs'];
			}
		}

		// Per-source coverage: indexable (adapter counts on enabled subtypes)
		// vs indexed active documents.
		$sources = array();
		foreach ( $this->adapters->all() as $id => $adapter ) {
			if ( ! $adapter->is_available() ) {
				$sources[ $id ] = array(
					'available' => false,
					'subtypes'  => array(),
				);
				continue;
			}

			$subtypes = array();
			foreach ( $adapter->subtypes() as $key => $label ) {
				$subtype   = is_string( $key ) ? $key : (string) $label;
				$enabled   = (bool) ( $this->enabled_resolver )( (string) $id, $subtype );
				$indexed   = (int) ( $active[ $id ][ $subtype ] ?? 0 );
				$indexable = $enabled ? max( 0, $adapter->count_items( $subtype ) ) : 0;

				if ( $indexable > 0 ) {
					$coverage = round( min( 100.0, $indexed / $indexable * 100 ), 1 );
				} else {
					$coverage = $enabled && $indexed > 0 ? 100.0 : 0.0;
				}

				$subtypes[ $subtype ] = array(
					'enabled'      => $enabled,
					'indexable'    => $indexable,
					'indexed'      => $indexed,
					'coverage_pct' => $coverage,
				);
			}

			$sources[ $id ] = array(
				'available' => true,
				'subtypes'  => $subtypes,
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$stale = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}agy_kb_documents
				 WHERE status = %s AND source_modified_gmt IS NOT NULL AND source_modified_gmt > indexed_at",
				Store::STATUS_ACTIVE
			)
		);

		$broken_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_links WHERE http_status >= 400" );

		$orphans = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}agy_kb_documents d
				 WHERE d.status = %s AND d.permalink <> ''
				 AND NOT EXISTS (
					SELECT 1 FROM {$p}agy_kb_links l
					WHERE l.to_document_id = d.id AND l.is_internal = 1
				 )",
				Store::STATUS_ACTIVE
			)
		);

		$chunks_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_chunks" );

		// Terms cardinality estimate: postings count (PK is term+chunk_id, so
		// this is the sum of per-term chunk-dfs — cheap, no COUNT(DISTINCT)).
		$terms_postings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_terms" );

		$avg_chunks_per_doc = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(AVG(chunk_count), 0) FROM {$p}agy_kb_documents WHERE status = %s", Store::STATUS_ACTIVE )
		);
		// phpcs:enable

		$data = array(
			'sources'            => $sources,
			'stale'              => $stale,
			'excluded'           => $status_totals[ Store::STATUS_EXCLUDED ],
			'purging'            => $status_totals[ Store::STATUS_PURGING ],
			'errors'             => $status_totals[ Store::STATUS_ERROR ],
			'broken_links'       => $broken_links,
			'orphans'            => $orphans,
			'duplicate_clusters' => $this->duplicate_clusters(),
			'chunks'             => $chunks_total,
			'terms_postings'     => $terms_postings,
			'avg_chunks_per_doc' => round( $avg_chunks_per_doc, 2 ),
		);

		update_option(
			'agy_kb_health',
			array(
				'computed_at' => time(),
				'data'        => $data,
			),
			false
		);

		$this->refresh_corpus_stats( $chunks_total );

		return $data;
	}

	/**
	 * Near-duplicate cluster count over chunk simhashes (CHAR(16) hex).
	 * Bucketing by the first 4 hex chars keeps comparisons local; distance is
	 * Hamming over the two 32-bit halves. Same-document pairs are skipped —
	 * overlap between consecutive chunks of one document is by design.
	 */
	private function duplicate_clusters(): int {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, document_id, simhash FROM {$p}agy_kb_chunks WHERE simhash IS NOT NULL AND simhash <> '' LIMIT %d",
				self::SIMHASH_SAMPLE
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return 0;
		}

		$buckets = array();
		foreach ( $rows as $row ) {
			$hex = (string) $row['simhash'];
			if ( 16 !== strlen( $hex ) ) {
				continue;
			}
			$buckets[ substr( $hex, 0, 4 ) ][] = array(
				'id'  => (int) $row['id'],
				'doc' => (int) $row['document_id'],
				'hi'  => (int) hexdec( substr( $hex, 0, 8 ) ),
				'lo'  => (int) hexdec( substr( $hex, 8, 8 ) ),
			);
		}

		/** @var array<int, int> $parent */
		$parent = array();

		$find = static function ( int $x ) use ( &$parent ): int {
			while ( isset( $parent[ $x ] ) && $parent[ $x ] !== $x ) {
				$parent[ $x ] = $parent[ $parent[ $x ] ] ?? $parent[ $x ];
				$x            = $parent[ $x ];
			}

			return $x;
		};

		$union = static function ( int $a, int $b ) use ( &$parent, $find ): void {
			$parent[ $a ] = $parent[ $a ] ?? $a;
			$parent[ $b ] = $parent[ $b ] ?? $b;
			$ra           = $find( $a );
			$rb           = $find( $b );
			if ( $ra !== $rb ) {
				$parent[ $rb ] = $ra;
			}
		};

		foreach ( $buckets as $bucket ) {
			$n = min( count( $bucket ), self::SIMHASH_BUCKET_CAP );
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					if ( $bucket[ $i ]['doc'] === $bucket[ $j ]['doc'] ) {
						continue;
					}
					$distance = substr_count( decbin( $bucket[ $i ]['hi'] ^ $bucket[ $j ]['hi'] ), '1' )
						+ substr_count( decbin( $bucket[ $i ]['lo'] ^ $bucket[ $j ]['lo'] ), '1' );
					if ( $distance <= self::SIMHASH_MAX_HAMMING ) {
						$union( $bucket[ $i ]['id'], $bucket[ $j ]['id'] );
					}
				}
			}
		}

		$members = array();
		foreach ( array_keys( $parent ) as $id ) {
			$root             = $find( $id );
			$members[ $root ] = ( $members[ $root ] ?? 0 ) + 1;
		}

		return count( array_filter( $members, static fn ( int $size ): bool => $size >= 2 ) );
	}

	/**
	 * Refresh corpus statistics: average chunk length (BM25 normalization)
	 * and the dynamic stopword list (terms present in >20% of chunks —
	 * meaningful only past 500 chunks; below that the list is cleared).
	 *
	 * @param int $chunks_total Total chunk count (already measured).
	 */
	private function refresh_corpus_stats( int $chunks_total ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$avg_len = (float) $wpdb->get_var( "SELECT COALESCE(AVG(token_est), 0) FROM {$p}agy_kb_chunks" );
		update_option( 'agy_kb_avg_len', $avg_len > 0 ? (int) round( $avg_len ) : 200, false );

		if ( $chunks_total > self::STOPWORD_MIN_CHUNKS ) {
			$threshold = (int) floor( $chunks_total * self::STOPWORD_DF_RATIO );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stopwords = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT term FROM {$p}agy_kb_terms GROUP BY term HAVING COUNT(*) > %d ORDER BY COUNT(*) DESC LIMIT %d",
					$threshold,
					self::STOPWORD_CAP
				)
			);

			update_option( 'agy_kb_dynamic_stopwords', array_map( 'strval', (array) $stopwords ), false );
		} else {
			update_option( 'agy_kb_dynamic_stopwords', array(), false );
		}
	}
}
