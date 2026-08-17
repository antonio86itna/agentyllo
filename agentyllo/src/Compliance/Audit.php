<?php
/**
 * Audit log writer (agy_audit_log).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Compliance;

use Agentyllo\Chat\Session\SessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Records privileged actions (DSAR export/erase, settings changes via
 * copilot, KB mutations) with who/what/hash-of-args. Doubles as the AI Act
 * record-keeping trail for admin-side automated actions.
 */
final class Audit {

	/**
	 * Write an audit row.
	 *
	 * @param string      $action Dot-notation action id, e.g. 'privacy.erase'.
	 * @param string|null $target Target identifier (email, entry id…).
	 * @param array       $args   Arguments (hashed, never stored raw).
	 * @param string      $result 'ok' | 'failed' | 'denied'.
	 * @param string      $detail Optional human-readable detail.
	 */
	public static function log( string $action, ?string $target = null, array $args = array(), string $result = 'ok', string $detail = '' ): void {
		global $wpdb;

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'agy_audit_log',
			array(
				'actor_id'   => get_current_user_id() ?: null,
				'actor_type' => is_user_logged_in() ? 'user' : 'system',
				'action'     => substr( $action, 0, 64 ),
				'target'     => null === $target ? null : substr( $target, 0, 190 ),
				'args_hash'  => $args ? hash( 'sha256', (string) wp_json_encode( $args ) ) : null,
				'result'     => substr( $result, 0, 20 ),
				'detail'     => '' === $detail ? null : substr( $detail, 0, 5000 ),
				'ip_hash'    => '' !== $ip ? SessionManager::hash_ip( $ip ) : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
