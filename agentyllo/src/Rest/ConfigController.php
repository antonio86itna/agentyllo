<?php
/**
 * Public widget config REST endpoint.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Admin\Settings\SettingsSchema;
use Agentyllo\Admin\Settings\SettingsStore;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET agentyllo/v1/config — the ONE cacheable route (public, max-age=300):
 * the payload carries zero personal data, only site-owner settings, derived
 * color tokens, starter chips, and the Art. 50 disclosure surfaces (badge,
 * footer note, powered-by). The config_hash lets the widget detect config
 * drift without diffing the payload.
 */
final class ConfigController extends Controller {

	private const CACHE_MAX_AGE = 300;
	private const STARTERS_MAX  = 3;
	private const TITLE_MAX     = 60;

	/**
	 * Constructor.
	 *
	 * @param SettingsStore $settings Settings store.
	 */
	public function __construct( private readonly SettingsStore $settings ) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_config' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /config.
	 */
	public function get_config(): WP_REST_Response {
		$widget  = $this->settings->get( 'widget' );
		$general = $this->settings->get( 'general' );

		$enabled = (bool) $widget['widget_enabled']
			&& in_array( (string) $general['operating_mode'], SettingsSchema::OPERATING_MODES, true )
			&& false === get_option( 'agy_schema_error', false );

		$assistant_name = trim( (string) $general['assistant_name'] );
		if ( '' === $assistant_name ) {
			$assistant_name = (string) get_bloginfo( 'name' );
		}

		$welcome = trim( (string) $widget['welcome_message'] );
		if ( '' === $welcome ) {
			$welcome = __( 'Hi! Ask me anything about this site.', 'agentyllo' );
		}

		$payload = array(
			'enabled'         => $enabled,
			'assistant_name'  => $assistant_name,
			'position'        => (string) $widget['position'],
			'theme'           => (string) $widget['theme'],
			'tokens'          => $this->color_tokens( (string) $widget['primary_color'] ),
			'welcome_message' => $welcome,
			'launcher_teaser' => (string) $widget['launcher_teaser'],
			'starters'        => $this->starters(),
			'disclosure'      => $this->disclosure(),
			'gate'            => $this->gate(),
			'show_thumbnails' => (bool) $widget['show_thumbnails'],
			'show_internal_links' => (bool) $widget['show_internal_links'],
			'animations'      => (bool) $widget['animations'],
			'z_index'         => (int) $widget['z_index'],
			'lang'            => str_replace( '_', '-', (string) get_locale() ),
			'ai_mode'         => 'classic' !== (string) $general['operating_mode'],
			'browser_ai'      => (bool) ( $this->settings->value( 'models', 'browser_ai_enabled' ) ?? false ),
			'transport'       => 'buffered' === (string) ( $this->settings->value( 'performance', 'transport' ) ?? 'auto' ) ? 'buffered' : 'stream',
			'i18n'            => $this->widget_strings(),
			'api'             => array( 'root' => esc_url_raw( rest_url( self::REST_NAMESPACE ) ) ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- deterministic fingerprint over a value-only array.
		$payload['config_hash'] = substr( md5( serialize( $payload ) ), 0, 10 );

		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'Cache-Control', 'public, max-age=' . self::CACHE_MAX_AGE );

		return $response;
	}

	/**
	 * Art. 50 transparency surfaces. The powered-by entry is removable via
	 * the agy_powered_by filter (return null/empty to drop it) — the premium
	 * `remove_branding` flag hooks that filter, keeping the decision
	 * server-side so the widget cannot be trivially de-branded client-side.
	 *
	 * @return array{badge: string, footer_note: string, powered_by: ?array{label: string, url: string}}
	 */
	/**
	 * Translated widget chrome strings. The widget bundle carries no
	 * @wordpress/i18n (zero dependencies), so its UI strings ride the
	 * config payload; keys mirror DEFAULT_STRINGS in src-js/widget/element.ts.
	 *
	 * @return array<string, string>
	 */
	private function widget_strings(): array {
		return array(
			'assistant'         => __( 'Assistant', 'agentyllo' ),
			'open'              => __( 'Open chat', 'agentyllo' ),
			'close'             => __( 'Close chat', 'agentyllo' ),
			'send'              => __( 'Send', 'agentyllo' ),
			'input_label'       => __( 'Type your message', 'agentyllo' ),
			'placeholder'       => __( 'Ask a question…', 'agentyllo' ),
			'suggestions'       => __( 'Suggested questions', 'agentyllo' ),
			'replying'          => __( 'Assistant is replying', 'agentyllo' ),
			'working'           => __( 'Working…', 'agentyllo' ),
			'queued'            => __( 'Queued…', 'agentyllo' ),
			'understanding'     => __( 'Understanding your question…', 'agentyllo' ),
			'searching'         => __( 'Searching this site…', 'agentyllo' ),
			'checking_products' => __( 'Checking products…', 'agentyllo' ),
			'linking'           => __( 'Finding related pages…', 'agentyllo' ),
			'verifying'         => __( 'Verifying details…', 'agentyllo' ),
			'generating'        => __( 'Preparing an answer…', 'agentyllo' ),
			'formatting'        => __( 'Formatting the answer…', 'agentyllo' ),
			'error'             => __( 'Something went wrong. Please try again.', 'agentyllo' ),
			/* translators: %d: seconds to wait before the next message. */
			'rate_limited'      => __( 'A short pause — you can send another message in %ds.', 'agentyllo' ),
			'rate_limited_over' => __( 'You can send messages again.', 'agentyllo' ),
			'in_stock'          => __( 'In stock', 'agentyllo' ),
			'low_stock'         => __( 'Low stock', 'agentyllo' ),
			'out_of_stock'      => __( 'Out of stock', 'agentyllo' ),
			'add_to_cart'       => __( 'Add to cart', 'agentyllo' ),
		);
	}

	/**
	 * Pre-chat gate configuration (GDPR): whether registration is required,
	 * which fields, the intro + checkbox label, and the policy link.
	 */
	private function gate(): array {
		$privacy = $this->settings->get( 'privacy' );
		$mode    = (string) $privacy['registration_gate'];

		$policy_url = trim( (string) $privacy['privacy_policy_url'] );
		if ( '' === $policy_url ) {
			$policy_url = (string) get_privacy_policy_url();
		}

		$label = trim( (string) $privacy['privacy_checkbox_label'] );
		if ( '' === $label ) {
			$label = __( 'I have read and accept the privacy policy.', 'agentyllo' );
		}

		$intro = trim( (string) $privacy['gate_intro_text'] );
		if ( '' === $intro && 'off' !== $mode ) {
			$intro = __( 'Before we start, please tell us who you are.', 'agentyllo' );
		}

		return array(
			'enabled'          => 'off' !== $mode,
			'fields'           => 'name_email' === $mode ? array( 'name', 'email' ) : array(),
			'privacy_checkbox' => (bool) $privacy['privacy_checkbox_required'],
			'checkbox_label'   => $label,
			'policy_url'       => esc_url_raw( $policy_url ),
			'intro'            => $intro,
			'policy_version'   => (string) $privacy['policy_version'],
		);
	}

	private function disclosure(): array {
		/**
		 * Filter the powered-by attribution. Return null or an empty value to
		 * remove it (premium `remove_branding`).
		 *
		 * @param array{label: string, url: string}|null $powered_by Attribution link.
		 */
		$powered = apply_filters(
			'agy_powered_by',
			array(
				'label' => __( 'Powered by Agentyllo', 'agentyllo' ),
				'url'   => 'https://www.agentyllo.com',
			)
		);

		$valid = is_array( $powered ) && ! empty( $powered['label'] ) && ! empty( $powered['url'] );

		$privacy = $this->settings->get( 'privacy' );
		$general = $this->settings->get( 'general' );

		// Art. 50 badge: "AI Assistant" whenever an AI tier can answer, the
		// honest "Automated assistant" in classic-only mode. Owner-customized
		// footer disclaimer wins over the default wording; the AI-disclosure
		// setting can only be switched off in classic-only mode.
		$ai_active   = 'classic' !== (string) $general['operating_mode'];
		$disclose    = $ai_active || ! empty( $privacy['ai_disclosure'] );
		$footer_note = trim( (string) $privacy['legal_disclaimer_text'] );
		if ( '' === $footer_note ) {
			$footer_note = __( 'AI responses may contain mistakes — verify important information.', 'agentyllo' );
		}

		$transparency_id  = (int) get_option( 'agy_transparency_page_id', 0 );
		$transparency_url = $transparency_id > 0 && 'publish' === get_post_status( $transparency_id ) ? (string) get_permalink( $transparency_id ) : '';

		return array(
			'badge'            => $disclose ? ( $ai_active ? __( 'AI Assistant', 'agentyllo' ) : __( 'Automated assistant', 'agentyllo' ) ) : '',
			'footer_note'      => $disclose ? $footer_note : '',
			'transparency_url' => $transparency_url,
			'privacy_url'      => esc_url_raw( trim( (string) $privacy['privacy_policy_url'] ) ?: (string) get_privacy_policy_url() ),
			'powered_by'       => $valid
				? array(
					'label' => (string) $powered['label'],
					'url'   => esc_url_raw( (string) $powered['url'] ),
				)
				: null,
		);
	}

	/**
	 * Up to 3 starter chips from the top-weight active KB documents. Menu and
	 * site-identity docs are skipped — their titles ("Footer menu", the site
	 * name) make unnatural questions.
	 *
	 * @return string[]
	 */
	private function starters(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$titles = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT title FROM ' . $wpdb->prefix . "agy_kb_documents WHERE status = %s AND title <> '' AND source NOT IN ('menu', 'site') ORDER BY weight DESC, id ASC LIMIT %d",
				'active',
				self::STARTERS_MAX
			)
		);

		$starters = array();
		foreach ( (array) $titles as $title ) {
			// Widget renders via textContent: entities must be decoded here.
			$title = trim( wp_specialchars_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES ) );
			if ( '' === $title ) {
				continue;
			}
			if ( mb_strlen( $title ) > self::TITLE_MAX ) {
				$title = rtrim( mb_substr( $title, 0, self::TITLE_MAX ) ) . '…';
			}
			$starters[] = sprintf(
				/* translators: %s: title of a page/product from the knowledge base. */
				__( 'Tell me about %s', 'agentyllo' ),
				$title
			);
		}

		return $starters;
	}

	/**
	 * Server-derived color tokens: the widget never computes contrast. The
	 * primary foreground follows WCAG relative luminance of the primary color
	 * (light primaries get near-black text, dark ones get white); the
	 * surface/text/muted/border sets are static sensible values per scheme.
	 *
	 * @param string $primary Primary color hex from settings.
	 * @return array{light: array<string, string>, dark: array<string, string>}
	 */
	private function color_tokens( string $primary ): array {
		$primary = sanitize_hex_color( $primary ) ?: '#3858e9';

		$primary_fg = self::relative_luminance( $primary ) > 0.5 ? '#111' : '#fff';

		return array(
			'light' => array(
				'primary'    => $primary,
				'primary_fg' => $primary_fg,
				'surface'    => '#ffffff',
				'surface_2'  => '#f6f7f9',
				'text'       => '#1d2327',
				'muted'      => '#646970',
				'border'     => '#e2e4e7',
			),
			'dark'  => array(
				'primary'    => $primary,
				'primary_fg' => $primary_fg,
				'surface'    => '#1d2327',
				'surface_2'  => '#2c3338',
				'text'       => '#f0f0f1',
				'muted'      => '#a7aaad',
				'border'     => '#3c434a',
			),
		);
	}

	/**
	 * WCAG 2.x relative luminance of a hex color (0 = black, 1 = white).
	 *
	 * @param string $hex Hex color, '#rgb' or '#rrggbb'.
	 */
	private static function relative_luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return 0.0;
		}

		$channels = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$c          = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}
}
