<?php
/**
 * Consent records (agy_consents).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Compliance;

use Agentyllo\Chat\Session\SessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Stores what the visitor agreed to, with the exact wording proven by
 * text_hash + text_version — the evidence GDPR consent needs.
 */
final class Consent {

	public const TYPE_PRIVACY      = 'privacy_policy';
	public const TYPE_REGISTRATION = 'registration';

	/**
	 * Record a consent and mark the session as gated. Returns the consent id.
	 *
	 * @param int         $session_id   Session row id.
	 * @param string      $name         Visitor name (may be '').
	 * @param string      $email        Visitor email (validated by caller).
	 * @param string      $type         Consent type.
	 * @param string      $text_version Policy/label version shown.
	 * @param string      $text         Exact consent text shown (hashed).
	 * @param string|null $ip           Raw IP (hashed here) or null.
	 * @param string|null $user_agent   Raw UA (hashed here) or null.
	 * @param bool        $log_consent  Whether consent logging is enabled.
	 */
	public function record(
		int $session_id,
		string $name,
		string $email,
		string $type,
		string $text_version,
		string $text,
		?string $ip,
		?string $user_agent,
		bool $log_consent = true
	): ?int {
		global $wpdb;

		$consent_id = null;

		if ( $log_consent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->insert(
				$wpdb->prefix . 'agy_consents',
				array(
					'session_id'   => $session_id,
					'email'        => '' !== $email ? substr( $email, 0, 190 ) : null,
					'visitor_name' => '' !== $name ? substr( $name, 0, 190 ) : null,
					'consent_type' => substr( $type, 0, 40 ),
					'text_version' => substr( $text_version, 0, 20 ),
					'text_hash'    => hash( 'sha256', $text ),
					'granted'      => 1,
					'ip_hash'      => $ip ? SessionManager::hash_ip( $ip ) : null,
					'ua_hash'      => $user_agent ? hash( 'sha256', $user_agent ) : null,
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$consent_id = false === $ok ? null : (int) $wpdb->insert_id;
		}

		// Gate the session; stash identity + consent id in session meta so
		// the conversation row inherits them on first message.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT meta FROM ' . $wpdb->prefix . 'agy_sessions WHERE id = %d', $session_id ), ARRAY_A );
		$meta = $row && is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : array();
		$meta = is_array( $meta ) ? $meta : array();

		$meta['visitor_name']  = $name;
		$meta['visitor_email'] = $email;
		$meta['consent_id']    = $consent_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agy_sessions',
			array(
				'gated' => 1,
				'meta'  => (string) wp_json_encode( $meta ),
			),
			array( 'id' => $session_id )
		);

		/**
		 * Fires after a visitor consent is recorded.
		 *
		 * @param int|null $consent_id Consent row id (null when logging is off).
		 * @param int      $session_id Session id.
		 * @param string   $type       Consent type.
		 */
		do_action( 'agy_consent_recorded', $consent_id, $session_id, $type );

		return $consent_id;
	}
}
