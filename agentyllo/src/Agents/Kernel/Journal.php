<?php
/**
 * Agent journal backed by wp_agy_agent_journal.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Kernel;

use Agentyllo\Agents\Contracts\JournalInterface;
use Agentyllo\Agents\Contracts\Task;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Error entries are deduplicated by a normalized fingerprint; the nightly
 * learner promotes recurring fingerprints into lessons.
 */
final class Journal implements JournalInterface {

	private const LEVELS = array( 'debug', 'info', 'warn', 'error' );

	/**
	 * Fully-prefixed table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'agy_agent_journal';
	}

	/**
	 * {@inheritDoc}
	 */
	public function log( string $agent_id, string $level, string $event, string $message = '', array $context = array(), ?string $task_ref = null ): void {
		global $wpdb;

		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table(),
			array(
				'agent_id'    => substr( $agent_id, 0, 64 ),
				'task_ref'    => $task_ref ? substr( $task_ref, 0, 36 ) : null,
				'level'       => $level,
				'event'       => substr( $event, 0, 64 ),
				'message'     => $message,
				'context'     => empty( $context ) ? null : (string) wp_json_encode( $context ),
				'fingerprint' => null,
				'occurrences' => 1,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function error( string $agent_id, Throwable $e, ?Task $task = null, array $context = array() ): void {
		global $wpdb;

		$fingerprint = self::fingerprint( $agent_id, $e, $task );

		// Duplicate signature → bump occurrences on the newest matching row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET occurrences = occurrences + 1, created_at = %s
				 WHERE fingerprint = %s ORDER BY id DESC LIMIT 1',
				gmdate( 'Y-m-d H:i:s' ),
				$fingerprint
			)
		);

		if ( $updated ) {
			return;
		}

		$context = array_merge(
			$context,
			array(
				'exception' => get_class( $e ),
				'message'   => $e->getMessage(),
				'file'      => basename( (string) $e->getFile() ) . ':' . $e->getLine(),
				'task_type' => $task?->type,
				'payload'   => $task?->payload,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table(),
			array(
				'agent_id'    => substr( $agent_id, 0, 64 ),
				'task_ref'    => $task?->ref,
				'level'       => 'error',
				'event'       => 'error',
				'message'     => substr( $e->getMessage(), 0, 5000 ),
				'context'     => (string) wp_json_encode( $context ),
				'fingerprint' => $fingerprint,
				'occurrences' => 1,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function recurring_errors( int $min = 3, int $days = 30 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT fingerprint, agent_id, event, occurrences, context FROM ' . $this->table() . '
				 WHERE level = %s AND fingerprint IS NOT NULL AND occurrences >= %d
					AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 ORDER BY occurrences DESC LIMIT 100',
				'error',
				max( 1, $min ),
				max( 1, $days )
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$context = json_decode( (string) ( $row['context'] ?? '' ), true );

				return array(
					'fingerprint' => (string) $row['fingerprint'],
					'agent_id'    => (string) $row['agent_id'],
					'event'       => (string) $row['event'],
					'occurrences' => (int) $row['occurrences'],
					'context'     => is_array( $context ) ? $context : array(),
				);
			},
			(array) $rows
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function rotate( int $days_default = 30, int $days_errors = 90 ): int {
		global $wpdb;
		$table = $this->table();

		/**
		 * Filter journal retention windows (days).
		 *
		 * @param array $retention {default: int, errors: int}.
		 */
		$retention = (array) apply_filters(
			'agy_journal_retention',
			array(
				'default' => $days_default,
				'errors'  => $days_errors,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed  = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE level != 'error' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				max( 1, (int) ( $retention['default'] ?? $days_default ) )
			)
		);
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE level = 'error' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				max( 1, (int) ( $retention['errors'] ?? $days_errors ) )
			)
		);
		// phpcs:enable

		return $removed;
	}

	/**
	 * Normalized error signature: exception class + agent + task type + salient
	 * message with volatile parts (digits, hashes, paths) stripped.
	 *
	 * @param string     $agent_id Agent id.
	 * @param Throwable  $e        The failure.
	 * @param Task|null  $task     Task being handled.
	 */
	public static function fingerprint( string $agent_id, Throwable $e, ?Task $task = null ): string {
		$salient = strtolower( (string) preg_replace( array( '/[0-9]+/', '#/[^\s]+#', '/\s+/' ), array( 'N', '/PATH', ' ' ), $e->getMessage() ) );

		return sha1( implode( '|', array( get_class( $e ), $agent_id, $task->type ?? '-', substr( $salient, 0, 160 ) ) ) );
	}
}
