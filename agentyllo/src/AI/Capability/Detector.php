<?php
/**
 * Hosting capability detector — the single source of truth for what this
 * server can run. Consumed by the inference tier ladder, the budget manager,
 * the chat transport probe, and the admin "Why this tier?" report.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Capability;

use Agentyllo\Infra\Options;
use Agentyllo\Infra\Uploads;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Runs cheap, guarded probes and computes the inference tier flags.
 * Cached in option `agy_capabilities`; refreshed weekly (Action Scheduler),
 * on demand from the AI Models page, and whenever the cache is stale.
 */
final class Detector {

	private const SCHEMA_VERSION = 1;
	private const CACHE_TTL      = 12 * HOUR_IN_SECONDS;

	private const MB = 1048576;
	private const GB = 1073741824;

	/**
	 * Constructor.
	 *
	 * @param Options $options Options wrapper.
	 */
	public function __construct( private readonly Options $options ) {
	}

	/**
	 * Return the cached report, detecting only when stale or missing.
	 */
	public function report(): array {
		$cached = $this->options->get( 'capabilities' );
		if (
			is_array( $cached )
			&& ( $cached['schema'] ?? 0 ) === self::SCHEMA_VERSION
			&& ( time() - (int) ( $cached['detected_at'] ?? 0 ) ) < self::CACHE_TTL
		) {
			return $cached;
		}

		// Cold cache on a request path: build and persist a SHALLOW report
		// (every cheap probe, no network self-request) so the page renders
		// instantly, and leave the deep pass to the background sweep.
		$report = $this->detect( true, false );
		$this->schedule_deep_scan();

		return $report;
	}

	/**
	 * Queue one background deep scan (loopback included). Deduped; falls back
	 * to WP-Cron when Action Scheduler is unavailable.
	 */
	public function schedule_deep_scan(): void {
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( 'agy_capabilities_rescan', array(), 'agentyllo' ) ) {
				as_enqueue_async_action( 'agy_capabilities_rescan', array(), 'agentyllo', true );
			}

			return;
		}
		if ( ! wp_next_scheduled( 'agy_capabilities_rescan' ) ) {
			wp_schedule_single_event( time() + 30, 'agy_capabilities_rescan' );
		}
	}

	/**
	 * Run detection (or return fresh cache).
	 *
	 * @param bool $force Ignore the cache.
	 */
	public function detect( bool $force = false, bool $deep = true ): array {
		$cached = $this->options->get( 'capabilities' );

		if (
			! $force
			&& is_array( $cached )
			&& ( $cached['schema'] ?? 0 ) === self::SCHEMA_VERSION
			&& ( time() - (int) ( $cached['detected_at'] ?? 0 ) ) < self::CACHE_TTL
		) {
			return $cached;
		}

		$probes = $this->probe( $deep );
		$report = array(
			'schema'      => self::SCHEMA_VERSION,
			'detected_at' => time(),
			'probes'      => $probes,
			'tiers'       => self::compute_tiers( $probes ),
		);

		/**
		 * Filter the capability report before it is stored.
		 *
		 * @param array $report Capability report (schema, detected_at, probes, tiers).
		 */
		$report = (array) apply_filters( 'agy_capability_profile', $report );

		/*
		 * WP-CLI probes see different memory/time limits than the web SAPI the
		 * chatbot actually runs under, so wp-cli-context reports are returned
		 * but never persisted. The check is the WP_CLI constant, NOT PHP_SAPI:
		 * WordPress Playground / Studio (wp-now) serve real web requests with
		 * SAPI "cli", and refusing to cache there forced a full re-probe on
		 * every request.
		 */
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $report;
		}

		$this->options->set( 'capabilities', $report );

		/**
		 * Fires after a hosting capability scan completes.
		 *
		 * @param array $report The stored capability report.
		 */
		do_action( 'agy_capabilities_updated', $report );

		return $report;
	}

	/**
	 * Pure tier computation from a probe array. Unit-testable without WordPress.
	 *
	 * Tier flags (multiple can be active at once):
	 *  t1a  – TF-IDF/BM25 retrieval (pure PHP)            – always
	 *  t1b  – dense ONNX embeddings via FFI               – ffi + 256MB + 400MB disk
	 *  t2   – small bounded ONNX generation               – t1b + 512MB + cpu_score≥40 + extendable/≥60s time
	 *  t3   – llama.cpp statically possible               – live proc_open + ≥2 cores + ≥2GB disk
	 *  t3s  – persistent llama-server daemon              – decided by the M8 cross-request probe (null here)
	 *  t4   – browser-side inference offerable            – always (visitor device gates at runtime)
	 *  t5   – cloud providers usable once a key exists    – curl present
	 *
	 * @param array $p Probe values.
	 */
	public static function compute_tiers( array $p ): array {
		$mem  = (int) ( $p['memory_limit_bytes'] ?? 0 );
		$disk = $p['disk_free_bytes'] ?? null;
		$time = (int) ( $p['max_execution_time'] ?? 30 );

		$disk_at_least = static fn ( int $needed ): bool => null === $disk || (int) $disk >= $needed;
		$time_ok       = ( 0 === $time ) || $time >= 60 || ! empty( $p['exec_time_extendable'] );

		$t1b = ! empty( $p['ffi_enabled'] ) && $mem >= 256 * self::MB && $disk_at_least( 400 * self::MB );
		$t2  = $t1b && $mem >= 512 * self::MB && (int) ( $p['cpu_score'] ?? 0 ) >= 40 && $time_ok;
		$t3  = ! empty( $p['proc_open_works'] ) && (int) ( $p['cpu_cores'] ?? 1 ) >= 2 && $disk_at_least( 2 * self::GB );

		$best_free = 't1a';
		if ( $t1b ) {
			$best_free = 't1b';
		}
		if ( $t2 ) {
			$best_free = 't2';
		}
		if ( $t3 ) {
			$best_free = 't3';
		}

		return array(
			't0'             => true,
			't1a'            => true,
			't1b'            => $t1b,
			't2'             => $t2,
			't3'             => $t3,
			't3s'            => null,
			't4'             => true,
			't5'             => ! empty( $p['curl'] ),
			'best_free_tier' => $best_free,
		);
	}

	/**
	 * Run every probe. Each is wrapped so one failing host feature can never
	 * take detection down.
	 */
	private function probe( bool $deep = true ): array {
		$probes = array();

		$probes['php_version']     = PHP_VERSION;
		$probes['sapi']            = php_sapi_name();
		$probes['server_software'] = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$probes['is_litespeed']    = false !== stripos( $probes['server_software'], 'litespeed' );
		$probes['os']              = PHP_OS_FAMILY;
		$probes['arch']            = php_uname( 'm' );

		$probes['memory_limit_bytes'] = $this->probe_memory_limit();

		[ $probes['max_execution_time'], $probes['exec_time_extendable'] ] = $this->probe_execution_time();

		$disabled                     = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
		$probes['disabled_functions'] = array_values( $disabled );
		$probes['exec_available']     = self::function_usable( 'exec', $disabled );
		$probes['proc_open_available'] = self::function_usable( 'proc_open', $disabled );
		$probes['proc_open_works']    = $probes['proc_open_available'] ? $this->probe_proc_open() : false;

		$ffi                   = extension_loaded( 'ffi' ) ? strtolower( (string) ini_get( 'ffi.enable' ) ) : '';
		$probes['ffi_enabled'] = in_array( $ffi, array( '1', 'true', 'on' ), true );

		$probes['open_basedir'] = (string) ini_get( 'open_basedir' );

		$probes['uploads_dir']      = Uploads::base_dir();
		$probes['uploads_writable'] = Uploads::ensure();

		$probes['disk_free_bytes'] = $this->probe_disk_free();

		$probes['cpu_cores'] = $this->probe_cpu_cores();
		$probes['cpu_avx2']  = $this->probe_avx2();
		$probes['cpu_score'] = $this->probe_cpu_score();

		$probes['opcache'] = function_exists( 'opcache_get_status' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );
		$probes['curl']    = function_exists( 'curl_init' );

		$probes['object_cache']         = (bool) wp_using_ext_object_cache();
		$probes['page_cache_detected']  = $this->probe_page_cache_plugins();

		/*
		 * The loopback self-request is DEEP-ONLY: on single-worker hosts
		 * (Playground, Studio/wp-now, minimal PHP-FPM pools) an inline
		 * self-request can block until timeout — or deadlock outright — so it
		 * only runs from the background rescan / the explicit re-scan button,
		 * never on the request path that renders a page.
		 */
		if ( $deep ) {
			[ $probes['loopback_ok'], $probes['loopback_ms'] ] = $this->probe_loopback();
		} else {
			$probes['loopback_ok'] = null;
			$probes['loopback_ms'] = null;
			$probes['deep_pending'] = true;
		}

		global $wpdb;
		$probes['db_server_info'] = (string) $wpdb->db_server_info();
		$probes['db_version']     = (string) $wpdb->db_version();

		return $probes;
	}

	/**
	 * memory_limit in bytes; -1 (unlimited) is capped at 2GB for planning.
	 */
	private function probe_memory_limit(): int {
		$raw   = (string) ini_get( 'memory_limit' );
		$bytes = wp_convert_hr_to_bytes( $raw );

		if ( $bytes <= 0 ) {
			return 2 * self::GB;
		}

		return (int) $bytes;
	}

	/**
	 * Declared max_execution_time plus a live extendability probe — many
	 * hosts silently ignore set_time_limit().
	 *
	 * @return array{0:int, 1:bool}
	 */
	private function probe_execution_time(): array {
		$declared = (int) ini_get( 'max_execution_time' );

		if ( 'cli' === PHP_SAPI || 0 === $declared ) {
			return array( $declared, true );
		}

		$extendable = false;
		try {
			$probe_target = max( 120, $declared + 30 );
			if ( function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
				@set_time_limit( $probe_target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- capability probe by design; value restored below.
				$extendable = (int) ini_get( 'max_execution_time' ) >= $probe_target;
				@set_time_limit( $declared ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- restore the declared limit.
			}
		} catch ( Throwable $e ) {
			$extendable = false;
		}

		return array( $declared, $extendable );
	}

	/**
	 * Whether a function exists, is not disabled, and is callable.
	 *
	 * @param string   $fn       Function name.
	 * @param string[] $disabled disable_functions entries.
	 */
	private static function function_usable( string $fn, array $disabled ): bool {
		return function_exists( $fn ) && ! in_array( $fn, $disabled, true );
	}

	/**
	 * proc_open availability (declared, not disabled). Nothing is executed
	 * here — the marketplace build must never spawn processes; the Local AI
	 * companion re-verifies real spawn capability when it starts its daemon.
	 */
	private function probe_proc_open(): bool {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return self::function_usable( 'proc_open', $disabled ) && self::function_usable( 'proc_close', $disabled );
	}

	/**
	 * Free disk space at WP_CONTENT_DIR, or null when the host blocks the call.
	 */
	private function probe_disk_free(): ?int {
		try {
			if ( ! function_exists( 'disk_free_space' ) ) {
				return null;
			}
			$free = @disk_free_space( WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return false === $free ? null : (int) $free;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * CPU core count: /proc/cpuinfo, then Windows env, else 1.
	 */
	private function probe_cpu_cores(): int {
		try {
			if ( is_readable( '/proc/cpuinfo' ) ) {
				$info = (string) file_get_contents( '/proc/cpuinfo' );
				$n    = preg_match_all( '/^processor\s*:/m', $info );
				if ( $n >= 1 ) {
					return $n;
				}
			}
			$env = getenv( 'NUMBER_OF_PROCESSORS' );
			if ( false !== $env && (int) $env >= 1 ) {
				return (int) $env;
			}
		} catch ( Throwable $e ) {
			// fall through.
		}

		return 1;
	}

	/**
	 * AVX2 support (llama.cpp build selection). Null = unknown.
	 */
	private function probe_avx2(): ?bool {
		try {
			if ( is_readable( '/proc/cpuinfo' ) ) {
				$info = (string) file_get_contents( '/proc/cpuinfo' );

				return str_contains( $info, ' avx2' ) || str_contains( $info, "\tavx2" ) || str_contains( $info, 'avx2 ' );
			}
		} catch ( Throwable $e ) {
			// fall through.
		}

		return null;
	}

	/**
	 * Timed pure-PHP benchmark (float loop + small matmul), normalized 0–100.
	 * Runs in a few tens of ms; feeds T2 gating and throttling seeds.
	 */
	private function probe_cpu_score(): int {
		try {
			$start = microtime( true );

			$x = 1.0001;
			for ( $i = 0; $i < 150000; $i++ ) {
				$x = $x * 1.0000003 + 0.0000001;
			}

			$n = 32;
			$a = array();
			$b = array();
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = 0; $j < $n; $j++ ) {
					$a[ $i ][ $j ] = ( $i + $j + $x ) * 0.001;
					$b[ $i ][ $j ] = ( $i - $j ) * 0.002;
				}
			}
			$sum = 0.0;
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = 0; $j < $n; $j++ ) {
					$acc = 0.0;
					for ( $k = 0; $k < $n; $k++ ) {
						$acc += $a[ $i ][ $k ] * $b[ $k ][ $j ];
					}
					$sum += $acc;
				}
			}
			unset( $sum );

			$ms = ( microtime( true ) - $start ) * 1000;

			// ~15ms on a fast CPU → ~100; ~150ms on a weak shared vCPU → ~25.
			return (int) min( 100, max( 1, round( 4000 / ( $ms + 25 ) ) ) );
		} catch ( Throwable $e ) {
			return 1;
		}
	}

	/**
	 * Known full-page cache plugins/drop-ins present on this site.
	 *
	 * @return string[]
	 */
	private function probe_page_cache_plugins(): array {
		$found = array();

		$signals = array(
			'wp-rocket'      => defined( 'WP_ROCKET_VERSION' ),
			'litespeed'      => defined( 'LSCWP_V' ),
			'w3-total-cache' => defined( 'W3TC' ),
			'wp-super-cache' => defined( 'WPCACHEHOME' ),
			'wp-fastest-cache' => class_exists( 'WpFastestCache' ),
			'batcache'       => function_exists( 'batcache_cancel' ),
			'advanced-cache' => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
		);

		foreach ( $signals as $slug => $present ) {
			if ( $present ) {
				$found[] = $slug;
			}
		}

		return $found;
	}

	/**
	 * Loopback HTTP probe: can this server reach itself? (Feeds transport +
	 * Action Scheduler runner expectations.)
	 *
	 * @return array{0:?bool, 1:?int}
	 */
	private function probe_loopback(): array {
		try {
			$start    = microtime( true );
			$response = wp_remote_get(
				home_url( '/' ),
				array(
					'timeout'   => 3,
					'sslverify' => false,
					'headers'   => array( 'X-Agy-Probe' => '1' ),
				)
			);
			$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $response ) ) {
				return array( false, $ms );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			return array( $code >= 200 && $code < 500, $ms );
		} catch ( Throwable $e ) {
			return array( null, null );
		}
	}
}
