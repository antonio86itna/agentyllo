<?php
/**
 * Frontend chat widget loader.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Frontend;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the <agentyllo-chat> custom element in wp_footer and enqueues the
 * widget bundle (deferred, zero WP script deps — the widget is a standalone
 * web component). The [agentyllo_chat] shortcode renders the element inline
 * instead; when it does, the floating footer instance is skipped so only one
 * component mounts per page.
 */
final class WidgetLoader {

	private const HANDLE = 'agentyllo-widget';

	/**
	 * Whether the shortcode already rendered an inline widget on this page.
	 */
	private bool $inline_rendered = false;

	/**
	 * Constructor.
	 *
	 * @param Closure $settings Resolver returning the effective widget
	 *                          settings tab values (fn (): array).
	 */
	public function __construct( private readonly Closure $settings ) {
	}

	/**
	 * Attach hooks. Called once at boot.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'print_element' ) );
		add_shortcode( 'agentyllo_chat', array( $this, 'shortcode' ) );
	}

	/**
	 * Enqueue the widget bundle (defer strategy, no dependencies).
	 */
	public function enqueue(): void {
		if ( ! $this->should_render() || ! file_exists( AGYL_DIR . 'assets/build/widget.js' ) ) {
			return;
		}

		$version    = AGYL_VERSION;
		$asset_file = AGYL_DIR . 'assets/build/widget.asset.php';
		if ( file_exists( $asset_file ) ) {
			$asset = require $asset_file;
			if ( is_array( $asset ) && ! empty( $asset['version'] ) ) {
				$version = (string) $asset['version'];
			}
		}

		wp_enqueue_script(
			self::HANDLE,
			AGYL_URL . 'assets/build/widget.js',
			array(),
			$version,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Print the floating widget element in the footer. Skipped when the
	 * shortcode already mounted an inline instance, or when the bundle was
	 * not enqueued (missing build must not leave a dead element behind).
	 */
	public function print_element(): void {
		if ( $this->inline_rendered || ! $this->should_render() || ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		echo $this->element(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- element() escapes internally.
	}

	/**
	 * [agentyllo_chat] shortcode: the widget element inline in content.
	 */
	public function shortcode(): string {
		if ( ! $this->should_render() || ! wp_script_is( self::HANDLE, 'registered' ) ) {
			return '';
		}

		wp_enqueue_script( self::HANDLE );
		$this->inline_rendered = true;

		return $this->element( true );
	}

	/**
	 * The custom-element markup. The REST root travels as a data attribute so
	 * the component works on subdirectory installs and plain permalinks alike.
	 *
	 * @param bool $inline Whether this is the inline (shortcode) instance.
	 */
	private function element( bool $inline = false ): string {
		$attrs = ' data-rest="' . esc_attr( esc_url_raw( rest_url( 'agentyllo/v1' ) ) ) . '"';
		if ( $inline ) {
			$attrs .= ' data-inline="1"';
		}

		return '<agentyllo-chat' . $attrs . '></agentyllo-chat>';
	}

	/**
	 * Render conditions: widget enabled, real frontend request, and the
	 * agyl_widget_should_render filter agrees.
	 */
	private function should_render(): bool {
		if ( is_admin() || is_feed() ) {
			return false;
		}
		if ( function_exists( 'is_login' ) && is_login() ) {
			return false;
		}

		$widget = ( $this->settings )();
		if ( empty( $widget['widget_enabled'] ) ) {
			return false;
		}

		/**
		 * Filter whether the chat widget renders on the current request.
		 * Site owners can exclude pages, user roles, or campaigns here.
		 *
		 * @param bool $should_render Default true.
		 */
		return (bool) apply_filters( 'agyl_widget_should_render', true );
	}
}
