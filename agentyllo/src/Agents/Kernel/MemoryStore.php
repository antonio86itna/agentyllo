<?php
/**
 * Agent memory backed by wp_agy_agent_memory.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Kernel;

use Agentyllo\Agents\Contracts\MemoryStoreInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Upsert-by-(agent_id, mem_key) storage with TTL expiry, hit counting and
 * weekly lesson decay (fixed problems stop steering behavior).
 */
final class MemoryStore implements MemoryStoreInterface {

	private const KINDS = array( 'fact', 'state', 'task', 'lesson', 'msg' );

	/**
	 * Fully-prefixed table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'agy_agent_memory';
	}

	/**
	 * {@inheritDoc}
	 */
	public function remember( string $agent_id, string $key, array $content, string $kind = 'fact', int $importance = 50, ?int $ttl = null ): void {
		global $wpdb;

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$kind = 'fact';
		}

		$json = (string) wp_json_encode( $content );
		$now  = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table() . ' (agent_id, mem_key, kind, content, content_hash, importance, hits, expires_at, created_at, updated_at)
				 VALUES (%s, %s, %s, %s, %s, %d, 0, ' . ( null === $ttl ? 'NULL' : '%s' ) . ', %s, %s)
				 ON DUPLICATE KEY UPDATE kind = VALUES(kind), content = VALUES(content), content_hash = VALUES(content_hash),
					importance = VALUES(importance), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)',
				...array_merge(
					array( $agent_id, substr( $key, 0, 125 ), $kind, $json, sha1( $json ), max( 0, min( 100, $importance ) ) ),
					null === $ttl ? array() : array( gmdate( 'Y-m-d H:i:s', time() + $ttl ) ),
					array( $now, $now )
				)
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function recall( string $agent_id, string $key ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, content FROM ' . $this->table() . '
				 WHERE agent_id = %s AND mem_key = %s AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
				$agent_id,
				substr( $key, 0, 125 )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->table() . ' SET hits = hits + 1 WHERE id = %d', (int) $row['id'] ) );

		$decoded = json_decode( (string) $row['content'], true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function forget( string $agent_id, string $key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$this->table(),
			array(
				'agent_id' => $agent_id,
				'mem_key'  => substr( $key, 0, 125 ),
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function by_kind( string $agent_id, string $kind, int $limit = 50 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT mem_key, content FROM ' . $this->table() . '
				 WHERE agent_id = %s AND kind = %s AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
				 ORDER BY importance DESC, updated_at DESC LIMIT %d',
				$agent_id,
				$kind,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$decoded = json_decode( (string) $row['content'], true );
			if ( is_array( $decoded ) ) {
				$out[ (string) $row['mem_key'] ] = $decoded;
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function prune(): int {
		global $wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = (int) $wpdb->query( "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()" );

		// Lesson decay: -5 importance per week without new hits; dead lessons are removed.
		$wpdb->query(
			"UPDATE {$table} SET importance = GREATEST(0, importance - 5)
			 WHERE kind = 'lesson' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
		);
		$removed += (int) $wpdb->query( "DELETE FROM {$table} WHERE kind = 'lesson' AND importance = 0" );
		// phpcs:enable

		return $removed;
	}
}
