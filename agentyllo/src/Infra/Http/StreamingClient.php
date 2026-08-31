<?php
/**
 * HTTP client with Server-Sent-Events streaming through the WP HTTP API.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Every request goes through wp_remote_post() — transports, proxies, SSL
 * bundles and timeouts are all WordPress' own. Token streaming needs bytes
 * as they arrive, which the HTTP API does not expose directly, so when the
 * cURL transport is in use this client hooks `http_api_curl` (the API's
 * sanctioned customization point) and swaps in a write callback on the
 * handle WordPress created. If WordPress picks a different transport the
 * hook never fires and the full body is parsed after the fact — same
 * contract, just buffered.
 *
 * SSE framing: events are separated by a blank line; `event:` and `data:`
 * fields are collected (multi-line data joined with "\n"); comment lines
 * (":" prefix) and `id:`/`retry:` are ignored. Non-2xx responses are not
 * parsed as SSE — the raw body is returned so providers can surface the
 * vendor error message.
 */
final class StreamingClient {

	private const MAX_RAW_BYTES = 4 * 1024 * 1024;

	/**
	 * Whether real streaming is possible on this host (the WP HTTP API will
	 * use its cURL transport, whose handle we can attach a write callback to).
	 */
	public function supports_streaming(): bool {
		return function_exists( 'curl_init' ) && function_exists( 'curl_setopt' );
	}

	/**
	 * POST a JSON body. With $on_event, the response is streamed and each SSE
	 * event is delivered as $on_event(string $event, string $data): bool —
	 * returning false aborts the transfer (budget exhausted, client gone).
	 *
	 * @param string        $url      Absolute URL.
	 * @param string[]      $headers  "Name: value" header lines.
	 * @param array         $body     JSON body.
	 * @param float         $timeout  Total timeout (seconds).
	 * @param callable|null $on_event SSE consumer, or null for buffered.
	 * @return array{status: int, body: string, error: ?string, aborted: bool}
	 */
	public function post_json( string $url, array $headers, array $body, float $timeout, ?callable $on_event = null ): array {
		$json = wp_json_encode( $body );
		if ( ! is_string( $json ) ) {
			return array(
				'status'  => 0,
				'body'    => '',
				'error'   => 'json_encode',
				'aborted' => false,
			);
		}

		$assoc = array(
			'Content-Type' => 'application/json',
			'Accept'       => null !== $on_event ? 'text/event-stream' : 'application/json',
		);
		foreach ( $headers as $line ) {
			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$assoc[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		$args = array(
			'timeout'     => max( 5, (int) ceil( $timeout ) ),
			'redirection' => 0,
			'headers'     => $assoc,
			'body'        => $json,
			'user-agent'  => 'Agentyllo',
		);

		if ( null === $on_event || ! $this->supports_streaming() ) {
			return $this->request_buffered( $url, $args, $on_event );
		}

		return $this->request_streamed( $url, $args, $on_event );
	}

	/**
	 * Buffered request through the WP HTTP API. When an SSE consumer is given
	 * the whole body is parsed afterwards (no true streaming, same contract).
	 *
	 * @param string               $url      URL.
	 * @param array<string, mixed> $args     wp_remote_post() arguments.
	 * @param callable|null        $on_event SSE consumer.
	 * @return array{status: int, body: string, error: ?string, aborted: bool}
	 */
	private function request_buffered( string $url, array $args, ?callable $on_event ): array {
		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 0,
				'body'    => '',
				'error'   => $response->get_error_message(),
				'aborted' => false,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( null !== $on_event && $status >= 200 && $status < 300 ) {
			$this->replay_buffered( $raw, $on_event );
		}

		return array(
			'status'  => $status,
			'body'    => $raw,
			'error'   => null,
			'aborted' => false,
		);
	}

	/**
	 * Streaming request: wp_remote_post() with a write callback attached to
	 * the transport's cURL handle via the `http_api_curl` action, so bytes
	 * reach the SSE parser as they arrive while WordPress keeps owning the
	 * connection (proxy, SSL, timeout handling).
	 *
	 * @param string               $url      URL.
	 * @param array<string, mixed> $args     wp_remote_post() arguments.
	 * @param callable             $on_event SSE consumer.
	 * @return array{status: int, body: string, error: ?string, aborted: bool}
	 */
	private function request_streamed( string $url, array $args, callable $on_event ): array {
		$parser  = new SseParser();
		$raw     = '';
		$status  = 0;
		$aborted = false;
		$stream  = false;
		$hooked  = false;

		$writer = static function ( $handle, string $chunk ) use ( &$raw, &$status, &$aborted, &$stream, $parser, $on_event ): int {
			if ( 0 === $status ) {
				$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- reading the status mid-stream from the handle the WP HTTP API created.
				$stream = $status >= 200 && $status < 300;
			}

			if ( strlen( $raw ) < self::MAX_RAW_BYTES ) {
				$raw .= $chunk;
			}

			if ( $stream ) {
				$parser->feed( $chunk );
				foreach ( $parser->drain() as [ $event, $data ] ) {
					if ( false === $on_event( $event, $data ) ) {
						$aborted = true;

						return -1; // Abort transfer.
					}
				}
			}

			return strlen( $chunk );
		};

		/*
		 * `http_api_curl` is the HTTP API's own customization point: WordPress
		 * fires it with the handle it built (options, proxy, SSL already set)
		 * right before executing the request. Swapping the write function in
		 * here streams the body to our parser instead of WP's buffer. Scoped
		 * to this exact request URL and removed immediately afterwards.
		 */
		$configure = static function ( $handle, $parsed_args, $request_url ) use ( &$hooked, $writer, $url ): void {
			if ( $request_url !== $url ) {
				return;
			}
			$hooked = true;
			curl_setopt( $handle, CURLOPT_WRITEFUNCTION, $writer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- sanctioned use of the http_api_curl hook to attach a streaming write callback.
		};

		add_action( 'http_api_curl', $configure, PHP_INT_MAX, 3 );
		try {
			$response = wp_remote_post( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $configure, PHP_INT_MAX );
		}

		if ( $aborted ) {
			// The consumer stopped the transfer on purpose; the WP_Error the
			// aborted handle produces is expected, not a failure.
			return array(
				'status'  => $status,
				'body'    => $raw,
				'error'   => null,
				'aborted' => true,
			);
		}

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$timeout = false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' );

			// Bytes may have streamed before the connection dropped.
			return array(
				'status'  => $status,
				'body'    => $raw,
				'error'   => $timeout ? 'timeout' : $message,
				'aborted' => false,
			);
		}

		if ( ! $hooked ) {
			// WordPress chose a non-cURL transport: the body arrived buffered.
			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );
			if ( $status >= 200 && $status < 300 ) {
				$this->replay_buffered( $raw, $on_event );
			}

			return array(
				'status'  => $status,
				'body'    => $raw,
				'error'   => null,
				'aborted' => false,
			);
		}

		if ( 0 === $status ) {
			$status = (int) wp_remote_retrieve_response_code( $response );
		}

		// Flush a trailing event without a final blank line.
		if ( $stream ) {
			$parser->feed( "\n\n" );
			foreach ( $parser->drain() as [ $event, $data ] ) {
				if ( false === $on_event( $event, $data ) ) {
					break;
				}
			}
		}

		return array(
			'status'  => $status,
			'body'    => $raw,
			'error'   => null,
			'aborted' => false,
		);
	}

	/**
	 * Feed a complete buffered body through the SSE parser.
	 *
	 * @param string   $raw      Full response body.
	 * @param callable $on_event SSE consumer.
	 */
	private function replay_buffered( string $raw, callable $on_event ): void {
		$parser = new SseParser();
		$parser->feed( $raw . "\n\n" );
		foreach ( $parser->drain() as [ $event, $data ] ) {
			if ( false === $on_event( $event, $data ) ) {
				break;
			}
		}
	}
}
