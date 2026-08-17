<?php
/**
 * llama-server daemon supervisor.
 *
 * @package AgentylloLocalAI
 */

declare( strict_types=1 );

namespace AgentylloLocalAI;

defined( 'ABSPATH' ) || exit;

/**
 * Starts/stops a local `llama-server` bound to 127.0.0.1, tracks the PID and
 * last-use timestamp, and stops it after an idle TTL (Action Scheduler check
 * every 5 minutes) so a shared VPS never keeps a model in RAM for nothing.
 * Requires proc_open (Agentyllo's capability detector reports it) and a
 * POSIX shell; Windows hosts use the BYO endpoint path instead.
 */
final class Supervisor {

	public const HOOK_IDLE_CHECK = 'agyl_idle_check';

	/**
	 * Settings resolver ('local_ai' tab).
	 *
	 * @var callable
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param callable $settings Returns the 'local_ai' settings array.
	 */
	public function __construct( callable $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether a managed daemon is configured (binary + model paths present).
	 */
	public function configured(): bool {
		$s = ( $this->settings )();

		return is_array( $s ) && is_file( (string) ( $s['engine_binary'] ?? '' ) ) && is_file( (string) ( $s['model_file'] ?? '' ) );
	}

	/**
	 * Base URL of the managed daemon.
	 */
	public function url(): string {
		$s = ( $this->settings )();

		return 'http://127.0.0.1:' . (int) ( $s['port'] ?? 8756 );
	}

	/**
	 * Live status.
	 *
	 * @return array{running: bool, healthy: bool, pid: int, started_at: int, last_used: int, log: string}
	 */
	public function status(): array {
		$state = get_option( 'agyl_daemon', array() );
		$state = is_array( $state ) ? $state : array();
		$pid   = (int) ( $state['pid'] ?? 0 );

		$running = $pid > 0 && $this->pid_alive( $pid );
		$healthy = $running && $this->healthy();

		return array(
			'running'    => $running,
			'healthy'    => $healthy,
			'pid'        => $running ? $pid : 0,
			'started_at' => (int) ( $state['started_at'] ?? 0 ),
			'last_used'  => (int) ( $state['last_used'] ?? 0 ),
			'log'        => $this->log_tail(),
		);
	}

	/**
	 * Start the daemon (no-op when running). Returns an error string or ''.
	 */
	public function start(): string {
		if ( ! $this->configured() ) {
			return __( 'Engine binary or model file not configured.', 'agentyllo-local-ai' );
		}
		if ( $this->status()['running'] ) {
			return '';
		}
		if ( ! function_exists( 'proc_open' ) || in_array( 'proc_open', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
			return __( 'proc_open is disabled on this host — use the BYO endpoint instead.', 'agentyllo-local-ai' );
		}
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			return __( 'Managed daemon is not supported on Windows hosts — use the BYO endpoint instead.', 'agentyllo-local-ai' );
		}

		$s       = ( $this->settings )();
		$bin     = (string) $s['engine_binary'];
		$model   = (string) $s['model_file'];
		$port    = (int) ( $s['port'] ?? 8756 );
		$ctx     = max( 512, (int) ( $s['ctx_size'] ?? 4096 ) );
		$threads = (int) ( $s['threads'] ?? 0 );
		$extra   = trim( (string) ( $s['extra_args'] ?? '' ) );
		$log     = $this->log_path();

		$cmd = escapeshellarg( $bin )
			. ' -m ' . escapeshellarg( $model )
			. ' --host 127.0.0.1 --port ' . $port
			. ' -c ' . $ctx
			. ( $threads > 0 ? ' -t ' . $threads : '' )
			. ( ! empty( $s['enable_embeddings'] ) ? ' --embeddings' : '' )
			. ( '' !== $extra ? ' ' . $this->sanitize_extra( $extra ) : '' );

		$shell = 'nohup ' . $cmd . ' > ' . escapeshellarg( $log ) . ' 2>&1 & echo $!';
		$spec  = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc = @proc_open( array( '/bin/sh', '-c', $shell ), $spec, $pipes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_resource( $proc ) ) {
			return __( 'Could not spawn the daemon process.', 'agentyllo-local-ai' );
		}
		$pid = (int) trim( (string) stream_get_contents( $pipes[1] ) );
		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}
		proc_close( $proc );
		if ( $pid <= 0 ) {
			return __( 'Daemon did not report a PID.', 'agentyllo-local-ai' );
		}

		update_option(
			'agyl_daemon',
			array(
				'pid'        => $pid,
				'started_at' => time(),
				'last_used'  => time(),
			),
			false
		);

		return '';
	}

	/**
	 * Stop the daemon.
	 */
	public function stop(): void {
		$state = get_option( 'agyl_daemon', array() );
		$pid   = is_array( $state ) ? (int) ( $state['pid'] ?? 0 ) : 0;
		if ( $pid > 0 && function_exists( 'posix_kill' ) ) {
			@posix_kill( $pid, 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} elseif ( $pid > 0 ) {
			@shell_exec( 'kill ' . (int) $pid . ' 2>/dev/null' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		}
		delete_option( 'agyl_daemon' );
	}

	/**
	 * Mark the daemon as used (idle timer reset) and start it when the owner
	 * enabled auto-start. Called when Agentyllo routes a request to it.
	 */
	public function touch_and_ensure(): void {
		$s = ( $this->settings )();
		if ( ! $this->configured() ) {
			return;
		}
		$state = get_option( 'agyl_daemon', array() );
		if ( is_array( $state ) && ! empty( $state['pid'] ) ) {
			$state['last_used'] = time();
			update_option( 'agyl_daemon', $state, false );

			return;
		}
		if ( ! empty( $s['auto_start'] ) ) {
			$this->start();
		}
	}

	/**
	 * Idle-TTL check (Action Scheduler): stop when unused for longer than the TTL.
	 */
	public function idle_check(): void {
		$s   = ( $this->settings )();
		$ttl = max( 1, (int) ( $s['idle_ttl_min'] ?? 15 ) ) * MINUTE_IN_SECONDS;
		$st  = $this->status();
		if ( $st['running'] && $st['last_used'] > 0 && time() - $st['last_used'] > $ttl ) {
			$this->stop();
		}
	}

	/**
	 * Whether the HTTP endpoint answers.
	 */
	public function healthy(): bool {
		$r = wp_remote_get( $this->url() . '/health', array( 'timeout' => 2 ) );
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			return true;
		}
		$r = wp_remote_get( $this->url() . '/v1/models', array( 'timeout' => 2 ) );

		return ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r );
	}

	/**
	 * Log file path (uploads/agentyllo/private).
	 */
	public function log_path(): string {
		$dir = \Agentyllo\Infra\Uploads::dir( 'private' );

		return rtrim( $dir, '/\\' ) . '/local-ai-daemon.log';
	}

	/**
	 * Last ~40 lines of the daemon log.
	 */
	private function log_tail(): string {
		$path = $this->log_path();
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$size = (int) filesize( $path );
		$fh   = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}
		fseek( $fh, max( 0, $size - 6000 ) );
		$tail = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$lines = explode( "\n", $tail );

		return implode( "\n", array_slice( $lines, -40 ) );
	}

	/**
	 * Whether a PID is alive.
	 *
	 * @param int $pid PID.
	 */
	private function pid_alive( int $pid ): bool {
		if ( function_exists( 'posix_kill' ) ) {
			return @posix_kill( $pid, 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return is_dir( '/proc/' . $pid );
	}

	/**
	 * Allow only plain flag tokens in extra args (no shell metacharacters).
	 *
	 * @param string $extra Raw extra args.
	 */
	private function sanitize_extra( string $extra ): string {
		$tokens = preg_split( '/\s+/', $extra ) ?: array();
		$safe   = array();
		foreach ( $tokens as $token ) {
			if ( preg_match( '/^[A-Za-z0-9._\-=\/]+$/', $token ) ) {
				$safe[] = escapeshellarg( $token );
			}
		}

		return implode( ' ', $safe );
	}
}
