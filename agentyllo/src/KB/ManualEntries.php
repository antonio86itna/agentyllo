<?php
/**
 * Manual KB entries (notes, FAQ, uploaded files) — create/update/delete.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB;

use Agentyllo\KB\Indexer\Chunker;
use Agentyllo\KB\Source\DocumentDraft;
use Agentyllo\KB\Source\NormalizedBlock;

defined( 'ABSPATH' ) || exit;

/**
 * The write side of the 'manual' source (ManualAdapter is read-only by
 * contract). Text is normalized into paragraph/heading blocks, chunked with
 * the shared Chunker and stored through Store::upsert — the same path every
 * crawled document takes, so retrieval, health, purge and stats treat manual
 * entries like any other document. Deletion is soft: the row is trashed for
 * 30 days (status 'trashed', restorable) before the janitor purges it.
 */
final class ManualEntries {

	public const SOURCE = 'manual';

	/**
	 * Constructor.
	 *
	 * @param Store   $store   KB store.
	 * @param Chunker $chunker Chunker.
	 */
	public function __construct(
		private readonly Store $store,
		private readonly Chunker $chunker,
	) {
	}

	/**
	 * Create an entry. Returns the document id (0 on failure).
	 *
	 * @param string $title   Title.
	 * @param string $content Plain text / light markdown.
	 * @param string $subtype 'note' | 'faq' | 'file'.
	 * @param string $lang    Locale ('' = site).
	 * @param string $url     Optional related URL.
	 */
	public function create( string $title, string $content, string $subtype = 'note', string $lang = '', string $url = '' ): int {
		$external_id = wp_generate_uuid4();

		return $this->write( $external_id, $title, $content, $subtype, $lang, $url );
	}

	/**
	 * Update an entry by document id (title/content). Returns the id or 0.
	 *
	 * @param int         $document_id Document id.
	 * @param string|null $title       New title (null = keep).
	 * @param string|null $content     New content (null = keep).
	 */
	public function update( int $document_id, ?string $title, ?string $content ): int {
		$row = $this->row( $document_id );
		if ( null === $row ) {
			return 0;
		}
		$current = $this->content_of( $document_id );

		return $this->write(
			(string) $row['external_id'],
			null !== $title ? $title : (string) $row['title'],
			null !== $content ? $content : $current,
			(string) $row['subtype'],
			(string) $row['lang'],
			(string) $row['permalink']
		);
	}

	/**
	 * Soft-delete (trash) an entry. Retrieval only reads status 'active', so
	 * it disappears immediately; the row stays restorable for 30 days.
	 *
	 * @param int $document_id Document id.
	 */
	public function trash( int $document_id ): bool {
		global $wpdb;

		$row = $this->row( $document_id );
		if ( null === $row ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$wpdb->prefix . 'agy_kb_documents',
			array(
				'status'     => 'trashed',
				'indexed_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $document_id )
		);
		$this->store->bump_kb_version();

		return false !== $ok;
	}

	/**
	 * Restore a trashed entry.
	 *
	 * @param int $document_id Document id.
	 */
	public function restore( int $document_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$wpdb->prefix . 'agy_kb_documents',
			array(
				'status'     => 'active',
				'indexed_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $document_id,
				'source' => self::SOURCE,
				'status' => 'trashed',
			)
		);
		$this->store->bump_kb_version();

		return false !== $ok && $ok > 0;
	}

	/**
	 * Permanently delete trashed manual entries older than $days (janitor).
	 *
	 * @param int $days Days.
	 */
	public function purge_trashed( int $days = 30 ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT external_id FROM ' . $wpdb->prefix . 'agy_kb_documents WHERE source = %s AND status = %s AND indexed_at < %s LIMIT 200',
				self::SOURCE,
				'trashed',
				gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		$n = 0;
		foreach ( (array) $rows as $row ) {
			$this->store->delete( self::SOURCE, (string) $row['external_id'] );
			++$n;
		}

		return $n;
	}

	/**
	 * Manual document row (any status) by id.
	 *
	 * @param int $document_id Document id.
	 * @return array<string, mixed>|null
	 */
	public function row( int $document_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, external_id, subtype, title, permalink, lang, status FROM ' . $wpdb->prefix . 'agy_kb_documents WHERE id = %d AND source = %s', $document_id, self::SOURCE ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Recent manual entries (for copilot listings).
	 *
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 10 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, subtype, title, status, indexed_at AS updated_at FROM ' . $wpdb->prefix . 'agy_kb_documents WHERE source = %s ORDER BY indexed_at DESC LIMIT %d', self::SOURCE, max( 1, min( 100, $limit ) ) ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Reconstructed plain content of a document (its chunks in order).
	 *
	 * @param int $document_id Document id.
	 */
	public function content_of( int $document_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$parts = $wpdb->get_col( $wpdb->prepare( 'SELECT content FROM ' . $wpdb->prefix . 'agy_kb_chunks WHERE document_id = %d ORDER BY seq ASC', $document_id ) );

		return implode( "\n\n", array_map( 'strval', (array) $parts ) );
	}

	/**
	 * Build blocks and upsert.
	 *
	 * @param string $external_id UUID.
	 * @param string $title       Title.
	 * @param string $content     Content.
	 * @param string $subtype     Subtype.
	 * @param string $lang        Locale.
	 * @param string $url         URL.
	 */
	private function write( string $external_id, string $title, string $content, string $subtype, string $lang, string $url ): int {
		$title   = trim( wp_strip_all_tags( $title ) );
		$content = trim( wp_strip_all_tags( $content ) );
		if ( '' === $title || '' === $content ) {
			return 0;
		}
		$subtype = in_array( $subtype, array( 'note', 'faq', 'file' ), true ) ? $subtype : 'note';
		$lang    = '' !== $lang ? $lang : (string) get_locale();
		$url     = '' !== $url ? esc_url_raw( $url ) : '';

		$blocks = array();
		foreach ( preg_split( '/\R{2,}/u', $content ) ?: array( $content ) as $para ) {
			$para = trim( $para );
			if ( '' === $para ) {
				continue;
			}
			if ( preg_match( '/^#{1,6}\s+(.+)$/u', $para, $m ) ) {
				$blocks[] = new NormalizedBlock( NormalizedBlock::KIND_HEADING, trim( $m[1] ), 2 );
				continue;
			}
			if ( 'faq' === $subtype && preg_match( '/^(Q:|D:|Question:|Domanda:)/iu', $para ) ) {
				$blocks[] = new NormalizedBlock( NormalizedBlock::KIND_FAQ, $para );
				continue;
			}
			$blocks[] = new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, $para );
		}
		if ( ! $blocks ) {
			return 0;
		}

		$draft = new DocumentDraft(
			self::SOURCE,
			$external_id,
			$subtype,
			mb_substr( $title, 0, 255 ),
			$url,
			$lang,
			$blocks,
			array(),
			array(),
			null,
			70, // Owner-authored content ranks slightly above crawled pages.
			gmdate( 'Y-m-d H:i:s' )
		);

		// A trashed row with the same external id must come back to life.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'agy_kb_documents', array( 'status' => 'active' ), array( 'source' => self::SOURCE, 'external_id' => $external_id, 'status' => 'trashed' ) );

		$chunks = $this->chunker->chunk( $draft );

		return $this->store->upsert( $draft, $chunks );
	}
}
