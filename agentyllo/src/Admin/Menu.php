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
	 * Chat-bubble menu icon (currentColor so admin color schemes apply).
	 */
	private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3C6.9 3 3 6.5 3 10.8c0 2.3 1.1 4.4 3 5.8-.1.9-.5 2.2-1.7 3.4-.2.2 0 .6.3.6 2.2-.2 3.8-1 4.8-1.7.8.2 1.7.3 2.6.3 5.1 0 9-3.5 9-7.8S17.1 3 12 3zm-4 9.3c-.8 0-1.5-.7-1.5-1.5S7.2 9.3 8 9.3s1.5.7 1.5 1.5-.7 1.5-1.5 1.5zm4 0c-.8 0-1.5-.7-1.5-1.5s.7-1.5 1.5-1.5 1.5.7 1.5 1.5-.7 1.5-1.5 1.5zm4 0c-.8 0-1.5-.7-1.5-1.5s.7-1.5 1.5-1.5 1.5.7 1.5 1.5-.7 1.5-1.5 1.5z"/></svg>';

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
			'-settings'      => array( __( 'Settings', 'agentyllo' ), 'settings' ),
			'-help'          => array( __( 'Help', 'agentyllo' ), 'help' ),
		);
	}

	/**
	 * Register menu + submenus. Hooked on admin_menu.
	 */
	public function register(): void {
		if ( ! Caps::can( 'agy_manage' ) ) {
			return;
		}

		/**
		 * Filter the real capability required for the Agentyllo admin menu.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$menu_cap = (string) apply_filters( 'agy_capability_map', 'manage_options', 'agy_manage' );

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
