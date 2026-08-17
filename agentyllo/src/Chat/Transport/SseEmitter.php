<?php
/**
 * Server-Sent-Events emitter for in-request streaming.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * "SSE-in-request": the POST /messages response itself becomes an event
 * stream (status → delta* → message → done) generated while the pipeline
 * runs. No second connection, no polling table dependency, no cache
 * involvement (POST is never cached). Buffering proxies only delay delivery;
 * the final `message` event is always authoritative and the widget replaces
 * any streamed preview with it.
 */
final class SseEmitter {

	/**
	 * Whether headers were sent already.
	 */
	private bool $started = false;

	/**
	 * Whether streaming can start on this request (nothing sent yet).
	 */
	public static function can_stream(): bool {
		return ! headers_sent();
	}

	/**
	 * Send headers and defeat output buffering.
	 */
	public function begin(): void {
		if ( $this->started ) {
			return;
		}
		$this->started = true;

		// WordPress/REST server may have opened buffers; flush them all so
		// bytes reach the client as we write them.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		status_header( 200 );
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
		header( 'X-Robots-Tag: noindex' );

		// Padding comment: some proxies hold the first ~1-2KB before forwarding.
		echo ':' . str_repeat( ' ', 2048 ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->flush();
	}

	/**
	 * Emit one event.
	 *
	 * @param string $name Event name.
	 * @param array  $data JSON payload.
	 */
	public function event( string $name, array $data ): void {
		if ( ! $this->started ) {
			return;
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return;
		}
		// Data must not contain raw newlines (JSON escapes them already).
		echo 'event: ' . $name . "\n" . 'data: ' . $json . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->flush();
	}

	/**
	 * Close the stream.
	 */
	public function end(): void {
		if ( ! $this->started ) {
			return;
		}
		echo "event: done\ndata: {}\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->flush();
	}

	/**
	 * Whether begin() ran.
	 */
	public function started(): bool {
		return $this->started;
	}

	/**
	 * Push bytes to the client.
	 */
	private function flush(): void {
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}
}
