<?php
/**
 * Sliding-window rate limiting over agy_rate_events.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * INSERT-then-COUNT sliding window: every attempt is recorded first (one
 * cheap indexed row), then the in-window count decides. Recording throttled
 * attempts too is deliberate — the row is what makes the window slide, and
 * it doubles as abuse telemetry. Buckets in use: 'session:{id}:msg',
 * 'ip:{hash40}:msg_h', 'ip:{hash40}:msg_d', 'ip:{hash40}:session'.
 * Cleanup rides SessionManager::prune(), which deletes events older than
 * a day.
 */
final class RateLimiter {

	/**
	 * Record an attempt and decide whether it stays within the limit.
	 *
	 * @param string $bucket_key     Bucket id (hashed down when > 64 chars,
	 *                               the CHAR(64) column width).
	 * @param int    $limit          Max events inside the window (<= 0 = unlimited).
	 * @param int    $window_seconds Window length in seconds.
	 * @return bool True when the attempt is allowed.
	 */
	public function allow( string $bucket_key, int $limit, int $window_seconds ): bool {
		if ( $limit <= 0 ) {
			return true;
		}

		global $wpdb;

		$bucket = strlen( $bucket_key ) <= 64 ? $bucket_key : hash( 'sha256', $bucket_key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'agy_rate_events',
			array(
				'bucket'     => $bucket,
				'event_time' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'agy_rate_events WHERE bucket = %s AND event_time > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)',
				$bucket,
				max( 1, $window_seconds )
			)
		);

		return $count <= $limit;
	}

	/**
	 * Bucket id for per-session message throttling.
	 *
	 * @param int $session_id Session row id.
	 */
	public static function bucket_session_msg( int $session_id ): string {
		return 'session:' . $session_id . ':msg';
	}

	/**
	 * Bucket id for per-IP throttling. The sha256 hash is truncated to 40
	 * chars so every ip bucket fits the CHAR(64) column with prefix and
	 * suffix intact (collisions are negligible at 160 bits).
	 *
	 * @param string $ip_hash Sha256 IP hash (SessionManager::hash_ip()).
	 * @param string $suffix  One of 'msg_h', 'msg_d', 'session'.
	 */
	public static function bucket_ip( string $ip_hash, string $suffix ): string {
		return 'ip:' . substr( $ip_hash, 0, 40 ) . ':' . $suffix;
	}
}
