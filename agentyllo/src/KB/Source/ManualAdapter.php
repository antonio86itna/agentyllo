<?php
/**
 * Source adapter for manual knowledge-base entries.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

use Agentyllo\KB\Store;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * Manual documents (notes, FAQs, uploads) do not live in WordPress content —
 * they are written straight into `agy_kb_documents` (source 'manual',
 * pre-chunked) by the manual-entry admin/REST surface. This adapter only
 * makes them visible to the reconciler and the coverage dashboard, and is
 * carefully inert everywhere else:
 *
 * - fingerprint() returns the STORED content_hash, so the reconciler always
 *   sees "unchanged" and never re-extracts or deletes a manual document.
 * - extract() returns null BY CONTRACT (see below).
 * - delta_hooks() is empty — there is no WordPress signal to watch.
 *
 * The 'manual' source has no settings toggle: it is always on.
 */
final class ManualAdapter implements SourceAdapter {

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'manual';
	}

	/**
	 * Always available.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Manual entry kinds.
	 *
	 * @return array<string, string>
	 */
	public function subtypes(): array {
		return array(
			'note'   => __( 'Notes', 'agentyllo' ),
			'faq'    => __( 'FAQs', 'agentyllo' ),
			'upload' => __( 'Uploads', 'agentyllo' ),
		);
	}

	/**
	 * Active manual documents.
	 *
	 * @param string $subtype Subtype ('' = all).
	 */
	public function count_items( string $subtype = '' ): int {
		global $wpdb;

		$sql  = 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'agy_kb_documents WHERE source = %s AND status = %s';
		$args = array( $this->id(), Store::STATUS_ACTIVE );
		if ( '' !== $subtype ) {
			$sql   .= ' AND subtype = %s';
			$args[] = $subtype;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
	}

	/**
	 * Stable ID cursor over active manual documents.
	 *
	 * @param string $subtype Subtype ('' = all).
	 * @param int    $offset  Cursor offset.
	 * @param int    $limit   Batch size.
	 * @return string[]
	 */
	public function enumerate_ids( string $subtype, int $offset, int $limit ): array {
		global $wpdb;

		$sql  = 'SELECT external_id FROM ' . $wpdb->prefix . 'agy_kb_documents WHERE source = %s AND status = %s';
		$args = array( $this->id(), Store::STATUS_ACTIVE );
		if ( '' !== $subtype ) {
			$sql   .= ' AND subtype = %s';
			$args[] = $subtype;
		}
		$sql   .= ' ORDER BY id ASC LIMIT %d OFFSET %d';
		$args[] = max( 1, $limit );
		$args[] = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );

		return array_map( 'strval', (array) $ids );
	}

	/**
	 * Returns the STORED content_hash of the active document. The reconciler
	 * compares fingerprints against exactly this hash, so manual documents
	 * always compare as unchanged and are never touched by reconciliation.
	 * Null only when the document is gone (or not active).
	 *
	 * @param string $external_id External id.
	 */
	public function fingerprint( string $external_id ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hash = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT content_hash FROM ' . $wpdb->prefix . 'agy_kb_documents WHERE source = %s AND external_id = %s AND status = %s',
				$this->id(),
				substr( $external_id, 0, 64 ),
				Store::STATUS_ACTIVE
			)
		);

		return ( is_string( $hash ) && '' !== $hash ) ? $hash : null;
	}

	/**
	 * Always null — BY CONTRACT, not by failure.
	 *
	 * Manual documents are authored pre-chunked and written directly to the
	 * store by the manual-entry surface; there is no upstream system to
	 * extract from. Callers MUST NOT interpret null from this adapter as
	 * "item vanished, delete it": deletion of manual entries only ever
	 * happens through the explicit manual-entry management endpoints. The
	 * fingerprint() identity guarantee above keeps the reconciler from ever
	 * reaching an extract() call for this source in the first place.
	 *
	 * @param string $external_id External id (unused).
	 */
	public function extract( string $external_id ): ?DocumentDraft {
		unset( $external_id );

		return null;
	}

	/**
	 * No WordPress change signals exist for manual entries.
	 *
	 * @return array<string, array{args: int, map: callable}>
	 */
	public function delta_hooks(): array {
		return array();
	}
}
