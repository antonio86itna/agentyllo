<?php
/**
 * Streaming self-test endpoint.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Infra\Caps;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET agentyllo/v1/probe/stream — emits three tiny chunks 300ms apart.
 * A client (or a loopback self-call) measuring inter-chunk arrival can tell
 * whether this stack buffers streaming responses (FastCGI/proxy/deflate),
 * which decides SSE vs buffered chat transport.
 *
 * Access: admin capability OR a short-lived single-use token minted by
 * mint_token() (used by the server's own loopback self-call). Never public —
 * the endpoint deliberately holds a worker for ~600ms.
 */
final class ProbeController extends Controller {

	private const TOKEN_TRANSIENT = 'agy_probe_token';
	private const TOKEN_TTL       = 120;

	/**
	 * Mint a single-use loopback token (2-minute TTL).
	 */
	public static function mint_token(): string {
		$token = wp_generate_password( 32, false, false );
		set_transient( self::TOKEN_TRANSIENT, $token, self::TOKEN_TTL );

		return $token;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/probe/stream',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stream' ),
				'permission_callback' => array( $this, 'check_access' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Admin capability or a valid single-use loopback token.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function check_access( WP_REST_Request $request ): bool {
		if ( Caps::can( 'agy_manage' ) ) {
			return true;
		}

		$token  = (string) $request->get_param( 'token' );
		$stored = get_transient( self::TOKEN_TRANSIENT );

		if ( is_string( $stored ) && '' !== $token && hash_equals( $stored, $token ) ) {
			delete_transient( self::TOKEN_TRANSIENT ); // single use.
			return true;
		}

		return false;
	}

	/**
	 * Emit chunks directly and terminate — the REST layer cannot stream.
	 */
	public function stream(): void {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Accel-Buffering: no' );

		@ini_set( 'zlib.output_compression', '0' );
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}

		// Bounded like core's wp_ob_end_flush_all(): a non-removable buffer
		// (zlib, exotic ob_start handlers) must break, never spin.
		for ( $i = ob_get_level(); $i > 0; $i-- ) {
			if ( false === @ob_end_flush() ) {
				break;
			}
		}

		$start = microtime( true );
		for ( $i = 1; $i <= 3; $i++ ) {
			if ( connection_aborted() ) {
				break;
			}
			$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );
			echo 'chunk:' . (int) $i . ':' . $elapsed . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			// Padding defeats some proxy buffers with small thresholds.
			echo str_repeat( ' ', 2048 ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( ob_get_level() > 0 ) {
				@ob_flush();
			}
			flush();
			if ( $i < 3 ) {
				usleep( 300000 );
			}
		}
		// phpcs:enable

		exit;
	}
}
