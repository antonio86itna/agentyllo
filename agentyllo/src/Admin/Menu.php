<?php
/**
 * Admin menu registration.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Admin;

use Agentyllo\Infra\Caps;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level Agentyllo menu. Every page mounts the same React SPA; the page
 * slug is passed to the app via a data attribute. New pages are added here
 * milestone by milestone.
 */
final class Menu {

	public const SLUG = 'agentyllo';

	/**
	 * Octopus mascot menu icon (currentColor so admin color schemes apply):
	 * a domed head with two eyes and four curling tentacles.
	 */
	private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3 13a9 9 0 0 1 18 0c0 1.7-1.4 2.5-2.7 2.5-1.3 0-1.3-1.5-2.6-1.5s-1.3 1.7-2.6 1.7-1.3 0-1.3-1.5-2.6-1.5-1.3 0-1.3 1.7-2.6 1.7-1.3 0-1.3-1.5-2.6-1.5C4.4 15.6 3 14.7 3 13z"/><circle cx="9.4" cy="11" r="1.4"/><circle cx="14.6" cy="11" r="1.4"/></svg>';

	/**
	 * Admin pages: slug suffix => [menu label, page id passed to the SPA].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private function pages(): array {
		return array(
			''          => array( __( 'Dashboard', 'agentyllo' ), 'dashboard' ),
			'-kb'            => array( __( 'Knowledge Base', 'agentyllo' ), 'kb' ),
			'-conversations' => array( __( 'Conversations', 'agentyllo' ), 'conversations' ),
			'-agents'        => array( __( 'Agents', 'agentyllo' ), 'agents' ),
			'-models'        => array( __( 'AI Models', 'agentyllo' ), 'models' ),
			'-stats'         => array( __( 'Statistics', 'agentyllo' ), 'stats' ),
			'-privacy'       => array( __( 'Privacy & Legal', 'agentyllo' ), 'privacy' ),
			'-addons'        => array( __( 'Addons', 'agentyllo' ), 'addons' ),
			'-settings'      => array( __( 'Settings', 'agentyllo' ), 'settings' ),
			'-help'          => array( __( 'Help', 'agentyllo' ), 'help' ),
		);
	}

	/**
	 * Register menu + submenus. Hooked on admin_menu.
	 */
	public function register(): void {
		if ( ! Caps::can( 'agyl_manage' ) ) {
			return;
		}

		/**
		 * Filter the real capability required for the Agentyllo admin menu.
		 *
		 * @param string $capability Default 'manage_options'.
		 * @param string $permission Agentyllo permission id being mapped.
		 */
		$menu_cap = (string) apply_filters( 'agyl_capability_map', 'manage_options', 'agyl_manage' );

		add_menu_page(
			__( 'Agentyllo', 'agentyllo' ),
			__( 'Agentyllo', 'agentyllo' ),
			$menu_cap,
			self::SLUG,
			array( $this, 'render' ),
			'data:image/svg+xml;base64,' . base64_encode( self::ICON_SVG ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			58
		);

		foreach ( $this->pages() as $suffix => [ $label ] ) {
			add_submenu_page(
				self::SLUG,
				$label . ' — Agentyllo',
				$label,
				$menu_cap,
				self::SLUG . $suffix,
				array( $this, 'render' )
			);
		}
	}

	/**
	 * SPA mount point. The React app reads data-page to route.
	 */
	public function render(): void {
		$page = 'dashboard';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only, no state change.
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : self::SLUG;
		foreach ( $this->pages() as $suffix => [ , $page_id ] ) {
			if ( self::SLUG . $suffix === $slug ) {
				$page = $page_id;
				break;
			}
		}

		printf(
			'<div id="agentyllo-admin" data-page="%s"></div>',
			esc_attr( $page )
		);
	}

	/**
	 * Whether the given admin page hook belongs to Agentyllo.
	 *
	 * @param string $hook Hook suffix from admin_enqueue_scripts.
	 */
	public static function is_agentyllo_screen( string $hook ): bool {
		return str_contains( $hook, 'agentyllo' );
	}
}
