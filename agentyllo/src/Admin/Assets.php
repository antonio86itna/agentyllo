<?php
/**
 * Admin asset loading.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the built admin app on Agentyllo screens only.
 */
final class Assets {

	private const HANDLE = 'agentyllo-admin';

	/**
	 * Conditionally enqueue. Hooked on admin_enqueue_scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function maybe_enqueue( string $hook ): void {
		if ( ! Menu::is_agentyllo_screen( $hook ) ) {
			return;
		}

		$asset_file = AGY_DIR . 'assets/build/admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-warning"><p>';
					echo esc_html__( 'Agentyllo admin assets are not built. Run "npm install && npm run build" in the plugin repository.', 'agentyllo' );
					echo '</p></div>';
				}
			);
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			AGY_URL . 'assets/build/admin.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? AGY_VERSION,
			true
		);

		wp_enqueue_style( 'wp-components' );
		$style = is_rtl() ? 'style-admin-rtl.css' : 'style-admin.css';
		if ( file_exists( AGY_DIR . 'assets/build/' . $style ) ) {
			wp_enqueue_style(
				self::HANDLE,
				AGY_URL . 'assets/build/' . $style,
				array( 'wp-components' ),
				$asset['version'] ?? AGY_VERSION
			);
		}

		wp_set_script_translations( self::HANDLE, 'agentyllo', AGY_DIR . 'languages' );

		wp_add_inline_script(
			self::HANDLE,
			'window.agyAdmin = ' . wp_json_encode(
				array(
					// Site REST root (works with plain and pretty permalinks);
					// the JS layer prefixes paths with /agentyllo/v1.
					'restRoot' => esc_url_raw( rest_url() ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'version'  => AGY_VERSION,
				)
			) . ';',
			'before'
		);
	}
}
