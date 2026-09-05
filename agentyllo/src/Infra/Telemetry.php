<?php
/**
 * Anonymous, opt-in technical telemetry.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default. When the owner enables it (Settings → Advanced), the plugin
 * buffers error FINGERPRINTS (an event code + a hash of the message + a count)
 * and, at most once a day, sends a tiny report to help find and fix bugs:
 *
 *   { domain, plugin_version, wp, php, mysql, locale, events:[{fp,code,count}] }
 *
 * It NEVER sends visitor data, message content, knowledge-base content, API
 * keys or any personal data. The endpoint is the Agentyllo Cloud service
 * (filterable / self-hostable). Failures are silent — telemetry must never
 * affect the site.
 */
final class Telemetry {

	private const BUFFER_OPTION = 'agyl_telemetry_buf';
	private const SENT_TRANSIENT = 'agyl_telemetry_sent';
	private const MAX_KEYS       = 50;

	/**
	 * Resolver returning the 'advanced' settings array.
	 *
	 * @var callable
	 */
	private $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param callable $settings_resolver Returns the 'advanced' settings tab.
	 */
	public function __construct( callable $settings_resolver ) {
		$this->settings_resolver = $settings_resolver;
	}

	/**
	 * Enabled by the owner?
	 */
	public function enabled(): bool {
		$adv = ( $this->settings_resolver )();

		return is_array( $adv ) && (bool) ( $adv['telemetry_enabled'] ?? false );
	}

	/**
	 * Register hooks (called from boot).
	 */
	public function register(): void {
		add_action( 'agyl_error_logged', array( $this, 'record' ), 10, 2 );
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'maybe_flush' ) );
		}
	}

	/**
	 * Buffer one error fingerprint (no message text stored — only a hash).
	 *
	 * @param string $event   Error event/code.
	 * @param string $message Error message (hashed, never stored raw).
	 */
	public function record( string $event, string $message = '' ): void {
		if ( ! $this->enabled() ) {
			return;
		}
		$code = preg_replace( '/[^a-z0-9_.\-]/i', '', substr( $event, 0, 40 ) ) ?: 'error';
		$norm = strtolower( preg_replace( '/\d+/', '#', preg_replace( '/\s+/', ' ', $message ) ?? '' ) ?? '' );
		$fp   = substr( sha1( $code . '|' . $norm ), 0, 12 );

		$buf = get_option( self::BUFFER_OPTION );
		$buf = is_array( $buf ) ? $buf : array();
		if ( ! isset( $buf[ $fp ] ) ) {
			if ( count( $buf ) >= self::MAX_KEYS ) {
				return; // cap — don't grow unbounded between flushes
			}
			$buf[ $fp ] = array( 'code' => $code, 'count' => 0 );
		}
		$buf[ $fp ]['count']++;
		update_option( self::BUFFER_OPTION, $buf, false );
	}

	/**
	 * Send the buffer at most once a day (cheap check on admin_init).
	 */
	public function maybe_flush(): void {
		if ( ! $this->enabled() || get_transient( self::SENT_TRANSIENT ) ) {
			return;
		}
		$buf = get_option( self::BUFFER_OPTION );
		if ( ! is_array( $buf ) || ! $buf ) {
			set_transient( self::SENT_TRANSIENT, 1, DAY_IN_SECONDS );
			return;
		}

		$events = array();
		foreach ( $buf as $fp => $row ) {
			$events[] = array( 'fp' => (string) $fp, 'code' => (string) $row['code'], 'count' => (int) $row['count'] );
		}

		global $wp_version;
		$payload = array(
			'domain'         => wp_parse_url( home_url(), PHP_URL_HOST ),
			'plugin_version' => AGYL_VERSION,
			'wp'             => (string) $wp_version,
			'php'            => PHP_VERSION,
			'locale'         => get_locale(),
			'events'         => $events,
		);

		/**
		 * Filter the telemetry endpoint (defaults to the Agentyllo Cloud host).
		 *
		 * @param string $url Endpoint URL.
		 */
		$endpoint = (string) apply_filters( 'agyl_telemetry_endpoint', 'https://api.agentyllo.com/v1/telemetry' );

		$res = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'  => 10,
				'blocking' => true,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => (string) wp_json_encode( $payload ),
			)
		);

		// On success, clear the buffer; either way, don't retry for a day.
		if ( ! is_wp_error( $res ) && (int) wp_remote_retrieve_response_code( $res ) < 300 ) {
			delete_option( self::BUFFER_OPTION );
		}
		set_transient( self::SENT_TRANSIENT, 1, DAY_IN_SECONDS );
	}
}
