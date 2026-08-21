<?php
/**
 * Data retention: scheduled deletion of conversations, consents, DSAR
 * exports, plus IP-salt rotation.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Compliance;

use Agentyllo\Infra\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Runs daily (Action Scheduler hook agy_retention_daily). retention_days = 0
 * keeps conversations forever (the settings UI warns about it).
 */
final class Retention {

	private const EXPORT_TTL   = 72 * HOUR_IN_SECONDS;
	private const SALT_ROTATE  = 30 * DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param \Closure $privacy_settings Resolver returning the 'privacy' settings tab.
	 */
	public function __construct( private readonly \Closure $privacy_settings ) {
	}

	/**
	 * Run every retention task. Returns counters for the journal/dashboard.
	 *
	 * @return array{conversations: int, messages: int, consents: int, exports: int, salt_rotated: bool}
	 */
	public function run(): array {
		global $wpdb;

		$settings = ( $this->privacy_settings )();
		$days     = max( 0, (int) ( $settings['retention_days'] ?? 90 ) );
		$p        = $wpdb->prefix;

		$out = array(
			'conversations' => 0,
			'messages'      => 0,
			'consents'      => 0,
			'exports'       => 0,
			'salt_rotated'  => false,
		);

		if ( $days > 0 ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$p}agy_conversations WHERE last_activity_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT 2000",
					$days
				)
			);
			if ( $ids ) {
				$in                  = implode( ',', array_map( 'absint', $ids ) );
				$out['messages']     = (int) $wpdb->query( "DELETE FROM {$p}agy_messages WHERE conversation_id IN ({$in})" );
				$out['conversations'] = (int) $wpdb->query( "DELETE FROM {$p}agy_conversations WHERE id IN ({$in})" );
			}
			// Consents outlive conversations by the same window (evidence), then go too.
			$out['consents'] = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$p}agy_consents WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
					$days * 2
				)
			);
			// phpcs:enable
		}

		$out['exports']      = $this->purge_exports();
		$out['salt_rotated'] = $this->maybe_rotate_ip_salt();

		/**
		 * Fires after the daily retention run.
		 *
		 * @param array $out Counters.
		 */
		do_action( 'agy_retention_ran', $out );

		return $out;
	}

	/**
	 * Delete DSAR export files older than 72h from uploads/agentyllo/private.
	 */
	private function purge_exports(): int {
		$dir = Uploads::dir( 'private' );
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$removed = 0;
		$cutoff  = time() - self::EXPORT_TTL;
		foreach ( (array) glob( $dir . '/dsar-*.json' ) as $file ) {
			if ( is_file( $file ) && (int) filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Rotate the IP-hash salt monthly. Old hashes stop being linkable to
	 * new traffic — by design.
	 */
	private function maybe_rotate_ip_salt(): bool {
		$rotated_at = (int) get_option( 'agy_ip_salt_rotated_at', 0 );
		if ( $rotated_at > 0 && ( time() - $rotated_at ) < self::SALT_ROTATE ) {
			return false;
		}
		update_option( 'agy_ip_salt', wp_generate_password( 32, false, false ), false );
		update_option( 'agy_ip_salt_rotated_at', time(), false );

		return true;
	}
}
