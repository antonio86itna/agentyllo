<?php
/**
 * Hybrid lexical retrieval engine: BM25 over the portable terms index,
 * optional MySQL FULLTEXT boost, Reciprocal Rank Fusion.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Retrieval;

use Agentyllo\KB\Store;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * The single authoritative retrieval engine. Every consumer (chat pipeline,
 * copilot, REST, agents) goes through search() so the invisibility rules are
 * enforced in exactly one place: hydration joins agy_kb_documents with
 * status='active', so purging/excluded/error documents can never surface.
 *
 * `extra_lists` is the M8 merge point: pre-ranked chunk-id lists (e.g. from a
 * vector index) join the same Reciprocal Rank Fusion as the lexical lists.
 * RRF contributions are scaled by k (k/(k+rank)) so a rank-1 hit in one list
 * is worth ~1.0 — the scaling preserves RRF ordering while keeping scores in
 * a range where absolute thresholds (e.g. "top score < 1 is weak") work.
 */
final class HybridRetriever {

	private const MAX_QUERY_TERMS     = 12;
	private const TERM_POSTINGS_LIMIT = 2000;
	private const BM25_CANDIDATES     = 200;
	private const FULLTEXT_LIMIT      = 50;
	private const RRF_K               = 60;
	private const BM25_K1             = 1.2;
	private const BM25_B              = 0.75;
	private const DEFAULT_AVG_LEN     = 200;

	/**
	 * Constructor.
	 *
	 * @param Tokenizer $tokenizer Shared tokenizer (same tokens as indexing).
	 */
	public function __construct( private readonly Tokenizer $tokenizer ) {
	}

	/**
	 * Search the knowledge base.
	 *
	 * @param string $query Natural-language query.
	 * @param array  $opts  Options: lang (string, ''=any), limit (int, 8),
	 *                      sources (?string[], adapter-id filter),
	 *                      extra_lists (array of pre-ranked chunk-id lists).
	 * @return array<int, array{chunk_id: int, document_id: int, source: string, subtype: string, title: string, permalink: string, thumbnail_id: ?int, score: float, kind: string, heading_path: string, content: string, structured: array, lang: string}>
	 */
	public function search( string $query, array $opts = array() ): array {
		$lang        = (string) ( $opts['lang'] ?? '' );
		$limit       = max( 1, (int) ( $opts['limit'] ?? 8 ) );
		$sources     = isset( $opts['sources'] ) && is_array( $opts['sources'] ) ? array_values( array_map( 'strval', $opts['sources'] ) ) : null;
		$extra_lists = isset( $opts['extra_lists'] ) && is_array( $opts['extra_lists'] ) ? $opts['extra_lists'] : array();

		$terms = array_slice(
			array_values( array_unique( $this->tokenizer->tokenize( $query, $lang ) ) ),
			0,
			self::MAX_QUERY_TERMS
		);

		$lists = array();
		$bm25  = array();

		if ( $terms ) {
			$bm25 = $this->bm25_list( $terms, $lang );
			if ( $bm25 ) {
				$lists[] = $bm25;
			}

			$fulltext = $this->fulltext_list( $query, $lang );
			if ( $fulltext ) {
				$lists[] = $fulltext;
			}
		}

		foreach ( $extra_lists as $list ) {
			if ( is_array( $list ) && $list ) {
				$lists[] = array_values( array_map( 'intval', $list ) );
			}
		}

		// Dense channel (M8): opts.dense_resolver(int[] $lexical_candidates)
		// returns chunk_id => cosine, best first — full-scan discovery on
		// small corpora, rerank of the lexical candidates on large ones. It
		// joins the RRF fusion as one more ranked list, and its similarity
		// feeds coverage below so a paraphrase with zero shared terms is not
		// mistaken for an off-topic question.
		$dense = array();
		if ( isset( $opts['dense_resolver'] ) && is_callable( $opts['dense_resolver'] ) ) {
			$resolved = ( $opts['dense_resolver'] )( array_values( array_map( 'intval', $bm25 ) ) );
			if ( is_array( $resolved ) && $resolved ) {
				$dense   = $resolved;
				$lists[] = array_map( 'intval', array_keys( $resolved ) );
			}
		}

		if ( ! $lists ) {
			return array();
		}

		// Reciprocal Rank Fusion (k=60), scaled by k so ranks map to ~[0..1].
		$fused = array();
		foreach ( $lists as $list ) {
			foreach ( $list as $rank => $chunk_id ) {
				$fused[ (int) $chunk_id ] = ( $fused[ (int) $chunk_id ] ?? 0.0 ) + self::RRF_K / ( self::RRF_K + $rank + 1 );
			}
		}
		arsort( $fused );

		$rows = $this->hydrate( array_slice( array_keys( $fused ), 0, max( 4 * $limit, 24 ) ), $sources );
		if ( ! $rows ) {
			return array();
		}

		/*
		 * Boosts: document weight (0-100 → ×0.8-1.2), spec chunks ×1.15, and
		 * QUERY-TERM COVERAGE. RRF is rank-based — a top hit always scores
		 * ~1.0 even for a query the KB knows nothing about — so every hit
		 * also carries `coverage` (fraction of unique query terms present in
		 * the chunk) and `matched_terms`: the relevance signal the scope guard
		 * and the extractive quality gate rely on. Coverage also scales the
		 * fused score (×0.5–1.0) so partial matches rank below full ones.
		 */
		$query_set = array_flip( $terms );
		$n_query   = max( 1, count( $terms ) );

		foreach ( $rows as &$row ) {
			$matched = 0;
			if ( $terms ) {
				$chunk_terms = $this->tokenizer->terms( (string) $row['content'], $lang );
				foreach ( $query_set as $term => $_ ) {
					if ( isset( $chunk_terms[ $term ] ) ) {
						++$matched;
					}
				}
			}
			$coverage = $matched / $n_query;

			// Dense similarity → coverage-equivalent: cosine 0.55 → 0, 0.85 → 1
			// (multilingual e5 / text-embedding-3 paraphrases sit at ~0.8+).
			$sim       = (float) ( $dense[ (int) $row['chunk_id'] ] ?? 0.0 );
			$dense_cov = max( 0.0, min( 1.0, ( $sim - 0.55 ) / 0.30 ) );
			if ( $dense_cov > $coverage ) {
				$coverage = $dense_cov;
				if ( 0 === $matched && $dense_cov >= 0.5 ) {
					$matched = 1; // Semantic match counts as a matched term for the gates.
				}
			}

			$score  = (float) ( $fused[ $row['chunk_id'] ] ?? 0.0 );
			$score *= 0.8 + ( (int) $row['weight'] ) / 250;
			$score *= 0.5 + 0.5 * $coverage;
			if ( 'spec' === $row['kind'] ) {
				$score *= 1.15;
			}
			$row['score']         = round( $score, 4 );
			$row['coverage']      = round( $coverage, 3 );
			$row['matched_terms'] = $matched;
			$row['dense']         = round( $sim, 3 );
		}
		unset( $row );

		usort( $rows, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		// Per-document dedupe: best chunk wins, the top document may keep two.
		$top_doc = (int) $rows[0]['document_id'];
		$per_doc = array();
		$out     = array();

		foreach ( $rows as $row ) {
			$doc               = (int) $row['document_id'];
			$per_doc[ $doc ]   = ( $per_doc[ $doc ] ?? 0 ) + 1;
			$allowed           = $doc === $top_doc ? 2 : 1;
			if ( $per_doc[ $doc ] > $allowed ) {
				continue;
			}
			unset( $row['weight'] );
			$out[] = $row;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * BM25-ranked chunk ids from the agy_kb_terms inverted index.
	 *
	 * df comes from a cheap index-only COUNT per query term (status-blind —
	 * an acceptable approximation: non-active documents are a small fraction
	 * of the corpus and df only feeds idf). Postings are fetched per term
	 * with a per-term cap (highest tf first) so one very common term cannot
	 * starve the others, and joined to active documents only so invisible
	 * documents never crowd out visible candidates. N is the active-doc
	 * chunk count; chunk lengths come from chunks.token_est with the corpus
	 * average cached in option agy_kb_avg_len (refreshed nightly).
	 *
	 * @param string[] $terms Distinct query terms.
	 * @param string   $lang  Language filter ('' = any).
	 * @return int[] Chunk ids, best first (max 200).
	 */
	private function bm25_list( array $terms, string $lang ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$lang_norm = '' !== $lang ? substr( strtolower( $lang ), 0, 10 ) : '';

		// Document frequency per query term, index-only (status-blind df).
		$placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
		$df_sql       = "SELECT term, COUNT(*) AS df FROM {$p}agy_kb_terms WHERE term IN ({$placeholders})";
		$df_args      = $terms;
		if ( '' !== $lang_norm ) {
			$df_sql   .= " AND ( lang = %s OR lang = '' )";
			$df_args[] = $lang_norm;
		}
		$df_sql .= ' GROUP BY term';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$df_rows = $wpdb->get_results( $wpdb->prepare( $df_sql, ...$df_args ), ARRAY_A );

		$df = array();
		foreach ( (array) $df_rows as $df_row ) {
			$df[ (string) $df_row['term'] ] = (int) $df_row['df'];
		}
		if ( ! $df ) {
			return array();
		}

		// Per-term capped postings, restricted to chunks of active documents.
		$by_chunk = array();
		foreach ( array_keys( $df ) as $term ) {
			$sql  = "SELECT t.chunk_id, t.tf
				FROM {$p}agy_kb_terms t
				INNER JOIN {$p}agy_kb_chunks c ON c.id = t.chunk_id
				INNER JOIN {$p}agy_kb_documents d ON d.id = c.document_id AND d.status = %s
				WHERE t.term = %s";
			$args = array( Store::STATUS_ACTIVE, $term );
			if ( '' !== $lang_norm ) {
				$sql   .= " AND ( t.lang = %s OR t.lang = '' )";
				$args[] = $lang_norm;
			}
			$sql   .= ' ORDER BY t.tf DESC LIMIT %d';
			$args[] = self::TERM_POSTINGS_LIMIT;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$postings = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
			foreach ( (array) $postings as $posting ) {
				$by_chunk[ (int) $posting['chunk_id'] ][ (string) $term ] = (int) $posting['tf'];
			}
		}

		if ( ! $by_chunk ) {
			return array();
		}

		// N for idf: chunks of active documents only.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n_chunks = max(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$p}agy_kb_chunks c INNER JOIN {$p}agy_kb_documents d ON d.id = c.document_id WHERE d.status = %s",
					Store::STATUS_ACTIVE
				)
			)
		);

		$avg_len = (float) get_option( 'agy_kb_avg_len', self::DEFAULT_AVG_LEN );
		if ( $avg_len <= 0 ) {
			$avg_len = (float) self::DEFAULT_AVG_LEN;
		}

		$in = implode( ',', array_map( 'absint', array_keys( $by_chunk ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$len_rows = $wpdb->get_results( "SELECT id, token_est FROM {$p}agy_kb_chunks WHERE id IN ({$in})", ARRAY_A );
		$lengths  = array();
		foreach ( (array) $len_rows as $len_row ) {
			$lengths[ (int) $len_row['id'] ] = (int) $len_row['token_est'];
		}

		$idf = array();
		foreach ( $df as $term => $d ) {
			// Clamped at 0: the status-blind df can exceed the active-only N.
			$idf[ $term ] = max( 0.0, log( ( $n_chunks - $d + 0.5 ) / ( $d + 0.5 ) + 1 ) );
		}

		$scores = array();
		foreach ( $by_chunk as $chunk_id => $tfs ) {
			$len   = $lengths[ $chunk_id ] ?? (int) $avg_len;
			$norm  = self::BM25_K1 * ( 1 - self::BM25_B + self::BM25_B * $len / $avg_len );
			$score = 0.0;
			foreach ( $tfs as $term => $tf ) {
				$score += $idf[ $term ] * ( $tf * ( self::BM25_K1 + 1 ) ) / ( $tf + $norm );
			}
			$scores[ $chunk_id ] = $score;
		}
		arsort( $scores );

		return array_slice( array_keys( $scores ), 0, self::BM25_CANDIDATES );
	}

	/**
	 * FULLTEXT boost list, only when the schema installer recorded a working
	 * FULLTEXT index (option agy_kb_caps, key 'fulltext'). BM25 is the
	 * floor, FULLTEXT is an opportunistic booster: wpdb never throws, so a
	 * failed MATCH (index dropped, table rebuilt) surfaces via last_error —
	 * the capability flag is cleared (self-healing) and the boost skipped.
	 *
	 * @param string $query Raw query.
	 * @param string $lang  Language filter ('' = any).
	 * @return int[] Chunk ids, best first (max 50).
	 */
	private function fulltext_list( string $query, string $lang ): array {
		$caps = get_option( 'agy_kb_caps', array() );
		if ( ! is_array( $caps ) || empty( $caps['fulltext'] ) ) {
			return array();
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$sql  = "SELECT c.id FROM {$p}agy_kb_chunks c
			INNER JOIN {$p}agy_kb_documents d ON d.id = c.document_id AND d.status = %s
			WHERE MATCH(c.heading_path, c.content) AGAINST (%s)";
		$args = array( Store::STATUS_ACTIVE, $query );
		if ( '' !== $lang ) {
			$sql   .= " AND ( c.lang = %s OR c.lang = '' )";
			$args[] = substr( strtolower( $lang ), 0, 10 );
		}
		$sql   .= ' LIMIT %d';
		$args[] = self::FULLTEXT_LIMIT;

		// Suppress wpdb's error printing for this one query: on a backend
		// that mis-reported FULLTEXT support the failure is expected once,
		// handled below (flag off), and must never leak HTML into a response.
		$suppressing = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );
		$wpdb->suppress_errors( $suppressing );

		if ( '' !== (string) $wpdb->last_error ) {
			$caps['fulltext'] = false;
			update_option( 'agy_kb_caps', $caps, false );

			return array();
		}

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Hydrate chunk rows joined to their documents. THE invisibility filter:
	 * only documents with status='active' come back, so purge-on-disable and
	 * tombstones take effect in the same request that flipped the status.
	 *
	 * @param int[]         $chunk_ids Fused candidate chunk ids.
	 * @param string[]|null $sources   Optional adapter-id whitelist.
	 * @return array<int, array> Rows keyed 0..n with a transient 'weight' key.
	 */
	private function hydrate( array $chunk_ids, ?array $sources ): array {
		if ( ! $chunk_ids ) {
			return array();
		}

		global $wpdb;
		$p  = $wpdb->prefix;
		$in = implode( ',', array_map( 'absint', $chunk_ids ) );

		$sql  = "SELECT c.id AS chunk_id, c.document_id, c.kind, c.heading_path, c.content, c.lang,
			d.source, d.subtype, d.title, d.permalink, d.thumbnail_id, d.structured, d.weight
			FROM {$p}agy_kb_chunks c
			INNER JOIN {$p}agy_kb_documents d ON d.id = c.document_id
			WHERE c.id IN ({$in}) AND d.status = %s";
		$args = array( Store::STATUS_ACTIVE );

		if ( $sources ) {
			$placeholders = implode( ',', array_fill( 0, count( $sources ), '%s' ) );
			$sql         .= " AND d.source IN ({$placeholders})";
			$args         = array_merge( $args, $sources );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$structured = array();
			if ( ! empty( $row['structured'] ) ) {
				$decoded = json_decode( (string) $row['structured'], true );
				if ( is_array( $decoded ) ) {
					$structured = $decoded;
				}
			}

			$out[] = array(
				'chunk_id'     => (int) $row['chunk_id'],
				'document_id'  => (int) $row['document_id'],
				'source'       => (string) $row['source'],
				'subtype'      => (string) $row['subtype'],
				'title'        => (string) $row['title'],
				'permalink'    => (string) $row['permalink'],
				'thumbnail_id' => null !== $row['thumbnail_id'] ? (int) $row['thumbnail_id'] : null,
				'score'        => 0.0,
				'kind'         => (string) $row['kind'],
				'heading_path' => (string) $row['heading_path'],
				'content'      => (string) $row['content'],
				'structured'   => $structured,
				'lang'         => (string) $row['lang'],
				'weight'       => (int) $row['weight'],
			);
		}

		return $out;
	}
}
