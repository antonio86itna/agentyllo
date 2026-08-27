<?php
/**
 * Knowledge-base repository: documents, chunks, terms, links.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB;

use Agentyllo\KB\Retrieval\Tokenizer;
use Agentyllo\KB\Source\DocumentDraft;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * All writes go through here. Purge-on-disable semantics: `mark_purging()`
 * flips document status synchronously (retrieval filters status='active', so
 * data vanishes from answers in the same request), then `purge_batch()`
 * deletes rows asynchronously. Per-item exclusions are tombstone rows
 * (status='excluded', data deleted, row kept so reconciliation cannot
 * re-add the item).
 */
final class Store {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_EXCLUDED = 'excluded';
	public const STATUS_PURGING  = 'purging';
	public const STATUS_ERROR    = 'error';

	/**
	 * Constructor.
	 *
	 * @param Tokenizer $tokenizer Shared tokenizer.
	 */
	public function __construct( private readonly Tokenizer $tokenizer ) {
	}

	/**
	 * Upsert a document with its chunks. Returns the document id, or 0 on
	 * failure. Unchanged content (same hash, active row) only touches
	 * indexed_at.
	 *
	 * @param DocumentDraft $draft  Normalized document.
	 * @param array         $chunks Chunk rows from the Chunker.
	 */
	public function upsert( DocumentDraft $draft, array $chunks ): int {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$hash = $draft->content_hash();
		$row  = $this->document_row( $draft->source, $draft->external_id );

		if ( $row && self::STATUS_EXCLUDED === $row['status'] ) {
			return 0; // Tombstoned by the site owner — never re-index.
		}

		if ( $row && $row['content_hash'] === $hash && self::STATUS_ACTIVE === $row['status'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'agyl_kb_documents',
				array(
					'indexed_at'          => $now,
					'source_modified_gmt' => $draft->source_modified_gmt,
				),
				array( 'id' => (int) $row['id'] )
			);

			return (int) $row['id'];
		}

		$data = array(
			'source'              => $draft->source,
			'external_id'         => substr( $draft->external_id, 0, 64 ),
			'subtype'             => substr( $draft->subtype, 0, 32 ),
			'status'              => self::STATUS_ACTIVE,
			'title'               => $draft->title,
			'permalink'           => self::normalize_url( $draft->permalink ),
			'lang'                => substr( $draft->lang, 0, 10 ),
			'thumbnail_id'        => $draft->thumbnail_id,
			'structured'          => empty( $draft->structured ) ? null : (string) wp_json_encode( $draft->structured ),
			'content_hash'        => $hash,
			'weight'              => max( 0, min( 100, $draft->weight ) ),
			'chunk_count'         => count( $chunks ),
			'source_modified_gmt' => $draft->source_modified_gmt,
			'indexed_at'          => $now,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $row ) {
			$doc_id = (int) $row['id'];
			$wpdb->update( $wpdb->prefix . 'agyl_kb_documents', $data, array( 'id' => $doc_id ) );
			$this->delete_document_data( $doc_id );
		} else {
			$data['created_at'] = $now;
			if ( false === $wpdb->insert( $wpdb->prefix . 'agyl_kb_documents', $data ) ) {
				return 0;
			}
			$doc_id = (int) $wpdb->insert_id;
		}

		$this->insert_chunks( $doc_id, $chunks );
		$this->insert_links( $doc_id, $draft );
		// phpcs:enable

		$this->bump_kb_version();

		return $doc_id;
	}

	/**
	 * One document row (any status).
	 *
	 * @param string $source      Adapter id.
	 * @param string $external_id External id.
	 */
	public function document_row( string $source, string $external_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'agyl_kb_documents WHERE source = %s AND external_id = %s',
				$source,
				substr( $external_id, 0, 64 )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Delete a document entirely (source item gone).
	 *
	 * @param string $source      Adapter id.
	 * @param string $external_id External id.
	 */
	public function delete( string $source, string $external_id ): void {
		global $wpdb;

		$row = $this->document_row( $source, $external_id );
		if ( ! $row ) {
			return;
		}

		$this->delete_document_data( (int) $row['id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'agyl_kb_documents', array( 'id' => (int) $row['id'] ) );

		$this->bump_kb_version();
	}

	/**
	 * Per-item exclusion tombstone: data deleted now, row kept so the
	 * reconciler never re-adds the item.
	 *
	 * @param string $source      Adapter id.
	 * @param string $external_id External id.
	 * @param string $subtype     Subtype (used when the row must be created).
	 */
	public function tombstone( string $source, string $external_id, string $subtype = '' ): void {
		global $wpdb;

		$source = substr( $source, 0, 32 );

		/*
		 * Manual documents' rows ARE the content (no upstream to re-extract
		 * from): tombstoning would destroy them irreversibly. They are only
		 * removed through Store::delete() via the dedicated endpoint.
		 */
		if ( 'manual' === $source ) {
			return;
		}

		$row = $this->document_row( $source, $external_id );
		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $row ) {
			$this->delete_document_data( (int) $row['id'] );
			$wpdb->update(
				$wpdb->prefix . 'agyl_kb_documents',
				array(
					'status'      => self::STATUS_EXCLUDED,
					'chunk_count' => 0,
					'structured'  => null,
					'indexed_at'  => $now,
				),
				array( 'id' => (int) $row['id'] )
			);
		} else {
			// Tombstone for a never-indexed item: blocks future indexing too.
			$wpdb->insert(
				$wpdb->prefix . 'agyl_kb_documents',
				array(
					'source'       => $source,
					'external_id'  => substr( $external_id, 0, 64 ),
					'subtype'      => substr( $subtype, 0, 32 ),
					'status'       => self::STATUS_EXCLUDED,
					'title'        => '',
					'content_hash' => '',
					'indexed_at'   => $now,
					'created_at'   => $now,
				)
			);
		}
		// phpcs:enable

		$this->bump_kb_version();
	}

	/**
	 * Remove a tombstone (item re-included; caller re-indexes it).
	 *
	 * @param string $source      Adapter id.
	 * @param string $external_id External id.
	 */
	public function remove_tombstone( string $source, string $external_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'agyl_kb_documents',
			array(
				'source'      => substr( $source, 0, 32 ),
				'external_id' => substr( $external_id, 0, 64 ),
				'status'      => self::STATUS_EXCLUDED,
			)
		);
	}

	/**
	 * Synchronous invisibility for a disabled source/subtype: flips rows to
	 * 'purging' (retrieval filters them out immediately). Row data is removed
	 * later by purge_batch(). Excluded tombstones are purged too — disabling
	 * the whole source supersedes per-item state.
	 *
	 * @param string      $source  Adapter id.
	 * @param string|null $subtype Limit to one subtype, null = whole source.
	 * @return int Rows flipped.
	 */
	public function mark_purging( string $source, ?string $subtype = null ): int {
		global $wpdb;

		$sql  = 'UPDATE ' . $wpdb->prefix . 'agyl_kb_documents SET status = %s WHERE source = %s';
		$args = array( self::STATUS_PURGING, $source );
		if ( null !== $subtype ) {
			$sql   .= ' AND subtype = %s';
			$args[] = $subtype;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$flipped = (int) $wpdb->query( $wpdb->prepare( $sql, ...$args ) );

		$this->bump_kb_version();

		return $flipped;
	}

	/**
	 * Delete data for 'purging' documents in batches.
	 *
	 * @param int $limit Documents per call.
	 * @return int Purging documents remaining after this batch.
	 */
	public function purge_batch( int $limit = 100 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'agyl_kb_documents';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s LIMIT %d", self::STATUS_PURGING, max( 1, $limit ) )
		);

		foreach ( $ids as $id ) {
			$this->delete_document_data( (int) $id );
		}

		if ( $ids ) {
			$in = implode( ',', array_map( 'absint', $ids ) );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PURGING )
		);
		// phpcs:enable
	}

	/**
	 * Coverage counts grouped by source/subtype/status.
	 *
	 * @return array<int, array{source: string, subtype: string, status: string, docs: int, chunks: int}>
	 */
	public function counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			'SELECT source, subtype, status, COUNT(*) AS docs, COALESCE(SUM(chunk_count),0) AS chunks
			 FROM ' . $wpdb->prefix . 'agyl_kb_documents GROUP BY source, subtype, status',
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ): array => array(
				'source'  => (string) $row['source'],
				'subtype' => (string) $row['subtype'],
				'status'  => (string) $row['status'],
				'docs'    => (int) $row['docs'],
				'chunks'  => (int) $row['chunks'],
			),
			(array) $rows
		);
	}

	/**
	 * Monotonic KB version — cache keys embed it, so any KB change implicitly
	 * invalidates response caches.
	 */
	public function bump_kb_version(): void {
		$version = (int) get_option( 'agyl_kb_version', 0 );
		update_option( 'agyl_kb_version', $version + 1, false );

		/**
		 * Fires after any KB write that changes the active set (documents,
		 * chunks, purges). Consumers: the vector indexer (embeds new chunks).
		 *
		 * @param int $version New KB version.
		 */
		do_action( 'agyl_kb_changed', $version + 1 );
	}

	/**
	 * Delete chunks, terms, and outgoing links of a document.
	 *
	 * @param int $doc_id Document id.
	 */
	private function delete_document_data( int $doc_id ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$chunk_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}agyl_kb_chunks WHERE document_id = %d", $doc_id ) );
		if ( $chunk_ids ) {
			$in = implode( ',', array_map( 'absint', $chunk_ids ) );
			$wpdb->query( "DELETE FROM {$p}agyl_kb_terms WHERE chunk_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$p}agyl_kb_chunks WHERE id IN ({$in})" );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}agyl_kb_links WHERE from_document_id = %d", $doc_id ) );
		// phpcs:enable
	}

	/**
	 * Insert chunk rows + their term postings.
	 *
	 * @param int   $doc_id Document id.
	 * @param array $chunks Chunk rows.
	 */
	private function insert_chunks( int $doc_id, array $chunks ): void {
		global $wpdb;

		foreach ( $chunks as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'agyl_kb_chunks',
				array(
					'document_id'  => $doc_id,
					'seq'          => (int) $chunk['seq'],
					'kind'         => (string) $chunk['kind'],
					'heading_path' => (string) $chunk['heading_path'],
					'content'      => (string) $chunk['content'],
					'token_est'    => (int) $chunk['token_est'],
					'lang'         => (string) $chunk['lang'],
					'chunk_hash'   => (string) $chunk['chunk_hash'],
					'simhash'      => (string) ( $chunk['simhash'] ?? '' ),
				)
			);

			if ( false === $inserted ) {
				continue;
			}

			$this->insert_terms( (int) $wpdb->insert_id, (string) $chunk['content'], (string) $chunk['lang'] );
		}
	}

	/**
	 * Insert term postings for one chunk (multi-VALUES batches).
	 *
	 * @param int    $chunk_id Chunk id.
	 * @param string $content  Chunk text.
	 * @param string $lang     Language.
	 */
	private function insert_terms( int $chunk_id, string $content, string $lang ): void {
		global $wpdb;

		$terms = $this->tokenizer->terms( $content, $lang );
		if ( ! $terms ) {
			return;
		}

		$lang = substr( $lang ?: '', 0, 10 );
		$rows = array();
		foreach ( $terms as $term => $tf ) {
			$rows[] = $wpdb->prepare( '(%s, %d, %s, %d)', substr( (string) $term, 0, 48 ), $chunk_id, $lang, min( 65535, (int) $tf ) );
		}

		foreach ( array_chunk( $rows, 500 ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'INSERT IGNORE INTO ' . $wpdb->prefix . 'agyl_kb_terms (term, chunk_id, lang, tf) VALUES ' . implode( ',', $batch ) );
		}
	}

	/**
	 * Insert outgoing link edges.
	 *
	 * @param int           $doc_id Document id.
	 * @param DocumentDraft $draft  Draft carrying harvested links.
	 */
	private function insert_links( int $doc_id, DocumentDraft $draft ): void {
		global $wpdb;

		$home = untrailingslashit( home_url() );
		$rel  = 'menu' === $draft->source ? 'menu' : ( 'manual' === $draft->source ? 'manual' : 'content' );
		$seen = array();

		foreach ( $draft->links as $link ) {
			$url = self::normalize_url( (string) ( $link['url'] ?? '' ) );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'agyl_kb_links',
				array(
					'from_document_id' => $doc_id,
					'to_document_id'   => null,
					'to_url'           => $url,
					'to_url_hash'      => sha1( $url ),
					'anchor'           => mb_substr( (string) ( $link['anchor'] ?? '' ), 0, 255 ),
					'rel'              => $rel,
					'is_internal'      => str_starts_with( $url, $home ) ? 1 : 0,
				)
			);
		}
	}

	/**
	 * Canonical URL form: absolute, no fragment, single trailing slash policy
	 * (kept as-is except fragment/whitespace) — both link edges and document
	 * permalinks go through here so joins match.
	 *
	 * @param string $url Raw URL.
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}

		/*
		 * Canonicalize scheme + host (case-insensitive by RFC) and fold the
		 * site's own host onto its canonical scheme, so an http:// content
		 * link resolves against an https:// permalink. Path case is kept.
		 */
		$parts = wp_parse_url( $url );
		if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
			$scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
			$host   = strtolower( (string) $parts['host'] );

			$home = wp_parse_url( home_url( '/' ) );
			if ( is_array( $home ) && ! empty( $home['host'] ) && strtolower( (string) $home['host'] ) === $host ) {
				$scheme = strtolower( (string) ( $home['scheme'] ?? $scheme ) );
			}

			$rebuilt = $scheme . '://' . $host;
			if ( ! empty( $parts['port'] ) ) {
				$rebuilt .= ':' . (int) $parts['port'];
			}
			$rebuilt .= (string) ( $parts['path'] ?? '' );
			if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
				$rebuilt .= '?' . $parts['query'];
			}
			$url = $rebuilt;
		}

		return substr( $url, 0, 2048 );
	}
}
