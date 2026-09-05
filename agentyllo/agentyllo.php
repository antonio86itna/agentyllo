<?php
/**
 * Plugin Name:       Agentyllo
 * Plugin URI:        https://www.agentyllo.com
 * Description:       Intelligent AI assistant for your site: classic agents, an automatic knowledge base, free local AI, and optional OpenAI/Anthropic models.
 * Version:           0.4.1
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Agentyllo
 * Author URI:        https://github.com/antonio86itna
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentyllo
 * Domain Path:       /languages
 *
 * @package Agentyllo
 */

defined( 'ABSPATH' ) || exit;

/*
 * This file must stay parseable by very old PHP versions so the guards below
 * can render an admin notice instead of a fatal error. No PHP 8 syntax here.
 */

define( 'AGYL_VERSION', '0.4.1' );
define( 'AGYL_DB_VERSION', 9 );
define( 'AGYL_API_VERSION', 1 );
define( 'AGYL_FILE', __FILE__ );
define( 'AGYL_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGYL_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: current PHP version. */
					__( 'Agentyllo requires PHP 8.2 or newer. This server runs PHP %s, so the plugin stays inactive.', 'agentyllo' ),
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, '6.8', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			global $wp_version;
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: current WordPress version. */
					__( 'Agentyllo requires WordPress 6.8 or newer. This site runs WordPress %s, so the plugin stays inactive.', 'agentyllo' ),
					$wp_version
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

/*
 * Action Scheduler must load before `plugins_loaded` so its internal version
 * registry can elect the newest copy present on the site (ours or e.g.
 * WooCommerce's). Never scope or rename this library.
 */
if ( file_exists( __DIR__ . '/lib/action-scheduler/action-scheduler.php' ) ) {
	require_once __DIR__ . '/lib/action-scheduler/action-scheduler.php';
}

/*
 * ── Freemius SDK slot ────────────────────────────────────────────────────────
 * When enabling Freemius, require lib/freemius/start.php and paste the
 * fs_dynamic_init() snippet from the Freemius dashboard RIGHT HERE — after the
 * guards above, before any other Agentyllo code, so it runs ahead of
 * `plugins_loaded` as the SDK requires. Until then, feature gating goes through
 * the `agyl_feature_enabled` filter (see Agentyllo\Plugin::feature_enabled()).
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, array( 'Agentyllo\\Install\\Activator', 'run' ) );
register_deactivation_hook( __FILE__, array( 'Agentyllo\\Install\\Deactivator', 'run' ) );

add_action( 'plugins_loaded', array( 'Agentyllo\\Plugin', 'boot' ), 5 );
