<?php
/**
 * Plugin Name:       Agentyllo Local AI
 * Plugin URI:        https://www.agentyllo.com/local-ai
 * Description:       Free companion for Agentyllo: installs verified llama.cpp engines and open-license GGUF models on your own server, supervises a local llama-server daemon and plugs it into Agentyllo's free AI modes. Distributed by agentyllo.com (not on WordPress.org: it downloads and runs binaries — with your consent and checksum verification).
 * Version:           0.1.1
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Requires Plugins:  agentyllo
 * Author:            Agentyllo
 * Author URI:        https://www.agentyllo.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentyllo-local-ai
 *
 * @package AgentylloLocalAI
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'AGYL_LAI_VERSION', '0.1.1' );
define( 'AGYL_LAI_FILE', __FILE__ );
define( 'AGYL_LAI_DIR', plugin_dir_path( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Agentyllo Local AI requires PHP 8.2 or newer.', 'agentyllo-local-ai' ) . '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AgentylloLocalAI\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$file = AGYL_LAI_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

// Boot after Agentyllo (plugins_loaded prio 5) has wired its container.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( \Agentyllo\Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'Agentyllo Local AI needs the Agentyllo plugin to be installed and active.', 'agentyllo-local-ai' ) . '</p></div>';
				}
			);
			return;
		}
		\AgentylloLocalAI\Plugin::boot();
	},
	10
);
