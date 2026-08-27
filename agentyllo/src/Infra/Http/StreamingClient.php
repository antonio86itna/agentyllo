<?php
/**
 * Minimal HTTP client with Server-Sent-Events streaming (cURL write callback).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra\Http;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress' HTTP API buffers whole responses; token streaming needs bytes as
 * they arrive. This client uses ext/curl with CURLOPT_WRITEFUNCTION when
 * available and falls back to wp_remote_post() (buffered) otherwise — the
 * caller checks supports_streaming() and picks the blocking path.
 *
 * SSE framing: events are separated by a blank line; `event:` and `data:`
 * fields are collected (multi-line data joined with "\n"); comment lines
 * (":" prefix) and `id:`/`retry:` are ignored. Non-2xx responses are not
 * parsed as SSE — the raw body is returned so providers can surface the
 * vendor error message.
 */
final class StreamingClient {

	private const CONNECT_TIMEOUT = 10;
	private const MAX_RAW_BYTES   = 4 * 1024 * 1024;

	/**
	 * Whether real streaming is possible on this host.
	 */
	public function supports_streaming(): bool {
		return function_exists( 'curl_init' ) && function_exists( 'curl_setopt_array' );
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

		$headers[] = 'Content-Type: application/json';
		$headers[] = 'Accept: ' . ( null !== $on_event ? 'text/event-stream' : 'application/json' );

		if ( null === $on_event || ! $this->supports_streaming() ) {
			return $this->post_buffered( $url, $headers, $json, $timeout, $on_event );
		}

		return $this->post_curl_stream( $url, $headers, $json, $timeout, $on_event );
	}

	/**
	 * Buffered POST through the WP HTTP API. When an SSE consumer is given the
	 * whole body is parsed afterwards (no true streaming, but same contract).
	 *
	 * @param string        $url      URL.
	 * @param string[]      $headers  Header lines.
	 * @param string        $json     Encoded body.
	 * @param float         $timeout  Timeout.
	 * @param callable|null $on_event SSE consumer.
	 * @return array{status: int, body: string, error: ?string, aborted: bool}
	 */
	private function post_buffered( string $url, array $headers, string $json, float $timeout, ?callable $on_event ): array {
		$assoc = array();
		foreach ( $headers as $line ) {
			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$assoc[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => max( 5, (int) ceil( $timeout ) ),
				'redirection' => 0,
				'headers'     => $assoc,
				'body'        => $json,
				'user-agent'  => 'Agentyllo',
			)
		);

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
			$parser = new SseParser();
			$parser->feed( $raw . "\n\n" );
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
	 * Real streaming through cURL.
	 *
	 * @param string   $url      URL.
	 * @param string[] $headers  Header lines.
	 * @param string   $json     Encoded body.
	 * @param float    $timeout  Timeout.
	 * @param callable $on_event SSE consumer.
	 * @return array{status: int, body: string, error: ?string, aborted: bool}
	 */
	private function post_curl_stream( string $url, array $headers, string $json, float $timeout, callable $on_event ): array {
		$ch = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		if ( false === $ch ) {
			return array(
				'status'  => 0,
				'body'    => '',
				'error'   => 'curl_init',
				'aborted' => false,
			);
		}

		$parser  = new SseParser();
		$raw     = '';
		$status  = 0;
		$aborted = false;
		$stream  = false;

		$writer = static function ( $handle, string $chunk ) use ( &$raw, &$status, &$aborted, &$stream, $parser, $on_event ): int {
			if ( 0 === $status ) {
				$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
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

		// NB: CURLOPT_RETURNTRANSFER must NOT be set here — PHP applies it as
		// a write-handler mode and would override CURLOPT_WRITEFUNCTION
		// (echoing the vendor body to stdout). WRITEFUNCTION goes last.
		$options = array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $json,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_HEADER         => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT        => max( 5, (int) ceil( $timeout ) ),
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT      => 'Agentyllo',
			CURLOPT_ENCODING       => '', // Accept any, but SSE arrives uncompressed in practice.
		);

		$ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		if ( is_readable( $ca_bundle ) ) {
			$options[ CURLOPT_CAINFO ] = $ca_bundle;
		}
		$options[ CURLOPT_WRITEFUNCTION ] = $writer;

		curl_setopt_array( $ch, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
		curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$errno = curl_errno( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
		$error = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
		if ( 0 === $status ) {
			$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		}
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		// Flush a trailing event without a final blank line.
		if ( $stream && ! $aborted ) {
			$parser->feed( "\n\n" );
			foreach ( $parser->drain() as [ $event, $data ] ) {
				if ( false === $on_event( $event, $data ) ) {
					break;
				}
			}
		}

		$err = null;
		if ( $aborted ) {
			$err = null; // Deliberate.
		} elseif ( 0 !== $errno ) {
			$err = CURLE_OPERATION_TIMEDOUT === $errno ? 'timeout' : ( 'curl_' . $errno . ' ' . $error );
		}

		return array(
			'status'  => $status,
			'body'    => $raw,
			'error'   => $err,
			'aborted' => $aborted,
		);
	}
}
