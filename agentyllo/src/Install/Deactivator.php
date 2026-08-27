<?php
/**
 * Plugin deactivation.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Unschedules background work; user data is always kept on deactivation.
 */
final class Deactivator {

	/**
	 * Action Scheduler groups owned by Agentyllo.
	 */
	private const GROUPS = array( 'agentyllo', 'agentyllo-kb', 'agentyllo-ai' );

	/**
	 * Deactivation entry point.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function run( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Per-site deactivation work.
	 */
	private static function deactivate_site(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( self::GROUPS as $group ) {
				as_unschedule_all_actions( '', array(), $group );
			}
		}

		delete_transient( 'agyl_activation_redirect' );
	}
}
