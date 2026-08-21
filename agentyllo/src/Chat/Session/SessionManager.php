<?php
/**
 * Cookieless visitor sessions.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Session;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Agentyllo's own custom tables: names are $wpdb->prefix plus literal constants; every value goes through $wpdb->prepare().

/**
 * No WP nonces, no cookies for visitors — fully cache-proof and cookie-
 * banner-neutral. The client holds an HMAC token "id.expires.signature"
 * (X-Agy-Session header); the signature is recomputable server-side, so
 * tokens are never stored. IPs are hashed at write time with a rotating salt.
 */
final class SessionManager {

	private const TTL = 24 * HOUR_IN_SECONDS;

	/**
	 * Create a session and mint its token.
	 *
	 * @param string|null $ip Remote IP (hashed, never stored raw).
	 * @return array{id: int, token: string, expires: int}|null Null on DB failure.
	 */
	public function create( ?string $ip ): ?array {
		global $wpdb;

		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = time() + self::TTL;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'agy_sessions',
			array(
				'ip_hash'      => $ip ? self::hash_ip( $ip ) : null,
				'created_at'   => $now,
				'last_seen_at' => $now,
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $expires ),
			)
		);

		if ( false === $inserted ) {
			return null;
		}

		$id = (int) $wpdb->insert_id;

		return array(
			'id'      => $id,
			'token'   => self::sign( $id, $expires ),
			'expires' => $expires,
		);
	}

	/**
	 * Validate a token and return the live session row (null when invalid,
	 * expired, or deleted).
	 *
	 * @param string $token Client-supplied token.
	 */
	public function validate( string $token ): ?array {
		global $wpdb;

		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return null;
		}

		[ $id, $expires, $signature ] = $parts;
		$id      = (int) $id;
		$expires = (int) $expires;

		if ( $id <= 0 || $expires < time() ) {
			return null;
		}
		if ( ! hash_equals( self::sign( $id, $expires ), $token ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'agy_sessions WHERE id = %d AND expires_at > UTC_TIMESTAMP()',
				$id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Record activity on a session.
	 *
	 * @param int  $id                Session id.
	 * @param bool $count_message     Whether to increment the message counter.
	 * @param string|null $lang       Sticky visitor language to persist.
	 */
	public function touch( int $id, bool $count_message = false, ?string $lang = null ): void {
		global $wpdb;

		$sets = 'last_seen_at = UTC_TIMESTAMP()';
		$args = array();
		if ( $count_message ) {
			$sets .= ', message_count = message_count + 1';
		}
		if ( null !== $lang && '' !== $lang ) {
			$sets  .= ', lang = %s';
			$args[] = substr( $lang, 0, 10 );
		}
		$args[] = $id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'agy_sessions SET ' . $sets . ' WHERE id = %d', ...$args ) );
	}

	/**
	 * Delete expired sessions (janitor). Returns rows removed.
	 */
	public function prune(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed  = (int) $wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'agy_sessions WHERE expires_at <= UTC_TIMESTAMP()' );
		$removed += (int) $wpdb->query(
			'DELETE FROM ' . $wpdb->prefix . "agy_rate_events WHERE event_time < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)"
		);
		// phpcs:enable

		return $removed;
	}

	/**
	 * Token signature: HMAC over id|expires with the auth salt.
	 *
	 * @param int $id      Session id.
	 * @param int $expires Unix expiry.
	 */
	private static function sign( int $id, int $expires ): string {
		return $id . '.' . $expires . '.' . hash_hmac( 'sha256', $id . '|' . $expires, wp_salt( 'auth' ) );
	}

	/**
	 * GDPR-safe IP hashing with a rotating salt (rotated monthly by the
	 * janitor from M5 on; created on first use).
	 *
	 * @param string $ip Raw IP.
	 */
	public static function hash_ip( string $ip ): string {
		$salt = get_option( 'agy_ip_salt' );
		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = wp_generate_password( 32, false, false );
			add_option( 'agy_ip_salt', $salt, '', false );
		}

		return hash( 'sha256', $ip . '|' . $salt );
	}
}
