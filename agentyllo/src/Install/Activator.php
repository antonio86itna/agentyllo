<?php
/**
 * Plugin activation.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Install;

use Agentyllo\Infra\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on register_activation_hook. Recurring jobs are NOT scheduled here —
 * Infra\Jobs ensures them idempotently on `init`/`action_scheduler_init`,
 * which is the Action Scheduler-safe place.
 */
final class Activator {

	/**
	 * Activation entry point.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function run( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}

		self::activate_site();
	}

	/**
	 * Per-site activation work.
	 */
	private static function activate_site(): void {
		Schema::install();
		Uploads::ensure();

		( new \Agentyllo\Admin\Settings\SettingsStore(
			new \Agentyllo\Admin\Settings\SettingsSchema(),
			new \Agentyllo\Infra\Options()
		) )->seed();

		if ( false === get_option( 'agy_installed_at', false ) ) {
			add_option( 'agy_installed_at', time(), '', false );
		}

		// One-time onboarding redirect flag, consumed by the admin shell.
		set_transient( 'agy_activation_redirect', 1, 60 );
	}
}
