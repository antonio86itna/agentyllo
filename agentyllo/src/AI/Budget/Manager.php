<?php
/**
 * Inference budget manager: time budgets, cost cap, circuit breaker, telemetry.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Budget;

use Agentyllo\AI\Contracts\ChatRequest;
use Agentyllo\AI\Contracts\ChatResult;
use Agentyllo\Registry\Manifest;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * One place that decides whether an inference may run and how long it may
 * take, and that records what it cost:
 *
 * - Time budget per request: configured seconds minus a safety margin so the
 *   classic composer always has room to answer after a slow provider —
 *   capped by PHP's max_execution_time when it is not extendable.
 * - Monthly cost cap (USD, from registry prices): reached → cloud providers
 *   are skipped, the classic floor answers, the dashboard shows the alert.
 * - Circuit breaker per provider: 3 consecutive transport/server failures
 *   open it for 15 minutes (rate limits: 60s, auth: 15 min); success closes.
 * - inference_log rows feed the EMA tok/s + the AI Models page.
 * - GET_LOCK concurrency=1 for local engines (M8) — cloud calls are not
 *   serialized (the vendor scales; the host does not).
 */
final class Manager {

	public const OPTION_USAGE = 'agyl_ai_usage_month';
	public const OPTION_EMA   = 'agyl_ai_ema_tps';
	private const CB_PREFIX   = 'agyl_cb_';
	private const CB_FAILS    = 3;
	private const CB_OPEN_S   = 15 * MINUTE_IN_SECONDS;
	private const CB_RATE_S   = MINUTE_IN_SECONDS;
	private const SAFETY_S    = 3.0;
	private const MIN_BUDGET  = 6.0;

	/**
	 * Resolver returning the current 'models' settings array.
	 *
	 * @var callable
	 */
	private $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param Manifest $manifest          Registry (pricing).
	 * @param callable $settings_resolver Returns the 'models' settings tab.
	 */
	public function __construct(
		private readonly Manifest $manifest,
		callable $settings_resolver
	) {
		$this->settings_resolver = $settings_resolver;
	}

	/**
	 * Wall-clock budget for one provider call, in seconds.
	 *
	 * @param float $elapsed_s Seconds already spent in this request.
	 */
	public function request_budget_s( float $elapsed_s = 0.0 ): float {
		$configured = (float) ( $this->settings()['request_timeout_s'] ?? 25 );
		$budget     = max( self::MIN_BUDGET, $configured - self::SAFETY_S - $elapsed_s );

		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec > 0 && ! self::exec_time_extendable() ) {
			$budget = min( $budget, max( self::MIN_BUDGET, $max_exec - self::SAFETY_S - $elapsed_s ) );
		}

		return round( $budget, 1 );
	}

	/**
	 * Whether the monthly cost cap is reached (0 = no cap).
	 */
	public function cap_reached(): bool {
		$cap = (float) ( $this->settings()['monthly_cost_cap_usd'] ?? 0 );
		if ( $cap <= 0 ) {
			return false;
		}

		return $this->month_usage()['cost_usd'] >= $cap;
	}

	/**
	 * Running month-to-date usage (fast counter option; the inference_log is
	 * the audit trail).
	 *
	 * @return array{month: string, cost_usd: float, tokens_in: int, tokens_out: int, calls: int, errors: int}
	 */
	public function month_usage(): array {
		$month  = gmdate( 'Y-m' );
		$stored = get_option( self::OPTION_USAGE );
		if ( ! is_array( $stored ) || (string) ( $stored['month'] ?? '' ) !== $month ) {
			return array(
				'month'      => $month,
				'cost_usd'   => 0.0,
				'tokens_in'  => 0,
				'tokens_out' => 0,
				'calls'      => 0,
				'errors'     => 0,
			);
		}

		return array(
			'month'      => $month,
			'cost_usd'   => (float) ( $stored['cost_usd'] ?? 0 ),
			'tokens_in'  => (int) ( $stored['tokens_in'] ?? 0 ),
			'tokens_out' => (int) ( $stored['tokens_out'] ?? 0 ),
			'calls'      => (int) ( $stored['calls'] ?? 0 ),
			'errors'     => (int) ( $stored['errors'] ?? 0 ),
		);
	}

	/**
	 * Whether a provider's circuit is open (skip it).
	 *
	 * @param string $provider Provider id.
	 */
	public function circuit_open( string $provider ): bool {
		$state = get_transient( self::CB_PREFIX . $provider );

		return is_array( $state ) && (int) ( $state['open_until'] ?? 0 ) > time();
	}

	/**
	 * Circuit state for the UI.
	 *
	 * @param string $provider Provider id.
	 * @return array{open: bool, fails: int, open_until: int, last_error: string}
	 */
	public function circuit_state( string $provider ): array {
		$state = get_transient( self::CB_PREFIX . $provider );
		$state = is_array( $state ) ? $state : array();

		return array(
			'open'       => (int) ( $state['open_until'] ?? 0 ) > time(),
			'fails'      => (int) ( $state['fails'] ?? 0 ),
			'open_until' => (int) ( $state['open_until'] ?? 0 ),
			'last_error' => (string) ( $state['last_error'] ?? '' ),
		);
	}

	/**
	 * Manually close a circuit (admin "retry now").
	 *
	 * @param string $provider Provider id.
	 */
	public function reset_circuit( string $provider ): void {
		delete_transient( self::CB_PREFIX . $provider );
	}

	/**
	 * Record an inference outcome: usage counters, circuit breaker, log row.
	 *
	 * @param ChatResult  $result   Result.
	 * @param ChatRequest $request  Request.
	 * @param bool        $streamed Whether it was streamed.
	 * @return float Estimated cost in USD.
	 */
	public function record( ChatResult $result, ChatRequest $request, bool $streamed ): float {
		$model_def = $this->manifest->chat_model( $result->provider, $result->model ) ?? array();
		$cost      = Manifest::cost( $model_def, $result->tokens_in, $result->tokens_out );

		$this->bump_usage( $cost, $result );
		$this->update_circuit( $result );
		$this->update_ema( $result );
		$this->log( $result, $request, $streamed, $cost );

		return $cost;
	}

	/**
	 * Exponential moving average of generation speed (tokens/second) for a
	 * provider — 0.0 until measured. Local engines are gated on it (T3 chat
	 * needs ≥ local_min_tok_s; slower hosts get bounded tasks only).
	 *
	 * @param string $provider Provider id.
	 */
	public function ema_tps( string $provider ): float {
		$ema = get_option( self::OPTION_EMA );

		return is_array( $ema ) ? (float) ( $ema[ $provider ] ?? 0.0 ) : 0.0;
	}

	/**
	 * Fold a measured result into the EMA (α = 0.3).
	 *
	 * @param ChatResult $result Result.
	 */
	private function update_ema( ChatResult $result ): void {
		if ( ! $result->ok || $result->tokens_out < 4 || $result->latency_ms <= 0 || '' === $result->provider ) {
			return;
		}
		$tps = $result->tokens_out / ( $result->latency_ms / 1000 );
		$ema = get_option( self::OPTION_EMA );
		$ema = is_array( $ema ) ? $ema : array();
		$old = (float) ( $ema[ $result->provider ] ?? 0.0 );

		$ema[ $result->provider ] = round( $old > 0 ? 0.7 * $old + 0.3 * $tps : $tps, 2 );
		update_option( self::OPTION_EMA, $ema, false );
	}

	/**
	 * Recent inference telemetry for the AI Models page.
	 *
	 * @param int $limit Rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 20 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT provider, model, task, ok, error, tokens_in, tokens_out, cost_usd, latency_ms, tok_per_s, streamed, created_at FROM ' . $wpdb->prefix . 'agyl_inference_log ORDER BY id DESC LIMIT %d',
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate telemetry per provider over the last 7 days (EMA-ish).
	 *
	 * @return array<string, array{calls: int, errors: int, avg_latency_ms: int, avg_tok_per_s: float, cost_usd: float}>
	 */
	public function provider_stats(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT provider, COUNT(*) AS calls, SUM(ok = 0) AS errors, AVG(latency_ms) AS avg_latency_ms, AVG(NULLIF(tok_per_s, 0)) AS avg_tok_per_s, SUM(cost_usd) AS cost_usd FROM ' . $wpdb->prefix . 'agyl_inference_log WHERE created_at >= %s GROUP BY provider',
				gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['provider'] ] = array(
				'calls'          => (int) $row['calls'],
				'errors'         => (int) $row['errors'],
				'avg_latency_ms' => (int) round( (float) $row['avg_latency_ms'] ),
				'avg_tok_per_s'  => round( (float) $row['avg_tok_per_s'], 1 ),
				'cost_usd'       => round( (float) $row['cost_usd'], 4 ),
			);
		}

		return $out;
	}

	/**
	 * Purge inference_log rows older than $days (retention job).
	 *
	 * @param int $days Days to keep.
	 */
	public function purge_log( int $days = 90 ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->prefix . 'agyl_inference_log WHERE created_at < %s',
				gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS )
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Serialize local inference on the host: GET_LOCK with a short wait so
	 * one slow request never stalls the whole site.
	 *
	 * @param string $engine Engine id.
	 * @param int    $wait_s Seconds to wait.
	 */
	public function acquire_local_lock( string $engine, int $wait_s = 3 ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', 'agyl_infer_' . $engine, max( 0, $wait_s ) ) );

		return '1' === (string) $got;
	}

	/**
	 * Release a local inference lock.
	 *
	 * @param string $engine Engine id.
	 */
	public function release_local_lock( string $engine ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'agyl_infer_' . $engine ) );
	}

	/**
	 * Add to the month-to-date counters.
	 *
	 * @param float      $cost   Cost USD.
	 * @param ChatResult $result Result.
	 */
	private function bump_usage( float $cost, ChatResult $result ): void {
		$usage = $this->month_usage();

		$usage['cost_usd']   = round( $usage['cost_usd'] + $cost, 6 );
		$usage['tokens_in'] += $result->tokens_in;
		$usage['tokens_out'] += $result->tokens_out;
		++$usage['calls'];
		if ( ! $result->ok ) {
			++$usage['errors'];
		}

		update_option( self::OPTION_USAGE, $usage, false );
	}

	/**
	 * Circuit-breaker bookkeeping.
	 *
	 * @param ChatResult $result Result.
	 */
	private function update_circuit( ChatResult $result ): void {
		if ( '' === $result->provider ) {
			return;
		}
		$key = self::CB_PREFIX . $result->provider;

		if ( $result->ok ) {
			delete_transient( $key );

			return;
		}

		$code = strtok( (string) $result->error, ':' ) ?: '';
		$code = trim( $code );

		// Content-level outcomes never trip the breaker.
		if ( in_array( $code, array( 'refusal', 'empty', 'aborted', 'parse', 'no_key', 'no_model' ), true ) ) {
			return;
		}

		$state = get_transient( $key );
		$state = is_array( $state ) ? $state : array( 'fails' => 0 );
		$fails = (int) ( $state['fails'] ?? 0 ) + 1;
		$open  = 0;

		if ( 'auth' === $code ) {
			$open = time() + self::CB_OPEN_S;
		} elseif ( 'rate_limit' === $code ) {
			$open = time() + self::CB_RATE_S;
		} elseif ( $fails >= self::CB_FAILS ) {
			$open = time() + self::CB_OPEN_S;
		}

		set_transient(
			$key,
			array(
				'fails'      => $fails,
				'open_until' => $open,
				'last_error' => mb_substr( (string) $result->error, 0, 200 ),
			),
			self::CB_OPEN_S
		);
	}

	/**
	 * Insert an inference_log row (PII-free: no prompt text).
	 *
	 * @param ChatResult  $result   Result.
	 * @param ChatRequest $request  Request.
	 * @param bool        $streamed Streamed.
	 * @param float       $cost     Cost.
	 */
	private function log( ChatResult $result, ChatRequest $request, bool $streamed, float $cost ): void {
		global $wpdb;

		$tok_per_s = $result->latency_ms > 0 && $result->tokens_out > 0
			? round( $result->tokens_out / ( $result->latency_ms / 1000 ), 2 )
			: 0.0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'agyl_inference_log',
			array(
				'provider'   => substr( $result->provider, 0, 32 ),
				'model'      => substr( $result->model, 0, 80 ),
				'task'       => substr( $request->task, 0, 20 ),
				'ok'         => $result->ok ? 1 : 0,
				'error'      => $result->ok ? null : substr( (string) strtok( (string) $result->error, ':' ), 0, 40 ),
				'tokens_in'  => $result->tokens_in,
				'tokens_out' => $result->tokens_out,
				'cost_usd'   => $cost,
				'latency_ms' => $result->latency_ms,
				'tok_per_s'  => $tok_per_s,
				'streamed'   => $streamed ? 1 : 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Whether set_time_limit() is usable (cached probe from the Detector).
	 */
	private static function exec_time_extendable(): bool {
		$report = get_option( 'agyl_capabilities' );
		if ( is_array( $report ) && isset( $report['probes']['exec_time_extendable'] ) ) {
			return (bool) $report['probes']['exec_time_extendable'];
		}

		return function_exists( 'set_time_limit' );
	}

	/**
	 * Current 'models' settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$settings = ( $this->settings_resolver )();

		return is_array( $settings ) ? $settings : array();
	}
}
