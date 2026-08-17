<?php
/**
 * Incremental Server-Sent-Events parser.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Feed arbitrary byte chunks; drain complete events as [event, data] pairs.
 * Pure PHP (unit-testable without WordPress). Handles CRLF/LF, multi-line
 * data, comments and events split across chunk boundaries.
 */
final class SseParser {

	/**
	 * Unconsumed bytes (may end mid-line).
	 */
	private string $buffer = '';

	/**
	 * Complete events waiting for drain().
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $ready = array();

	/**
	 * Current event name.
	 */
	private string $event = '';

	/**
	 * Current data lines.
	 *
	 * @var string[]
	 */
	private array $data = array();

	/**
	 * Append bytes.
	 *
	 * @param string $chunk Bytes.
	 */
	public function feed( string $chunk ): void {
		$this->buffer .= $chunk;

		while ( true ) {
			$pos = strpos( $this->buffer, "\n" );
			if ( false === $pos ) {
				break;
			}
			$line         = substr( $this->buffer, 0, $pos );
			$this->buffer = substr( $this->buffer, $pos + 1 );
			$this->line( rtrim( $line, "\r" ) );
		}
	}

	/**
	 * Take all complete events.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function drain(): array {
		$out         = $this->ready;
		$this->ready = array();

		return $out;
	}

	/**
	 * Process one line.
	 *
	 * @param string $line Line without terminator.
	 */
	private function line( string $line ): void {
		if ( '' === $line ) {
			if ( $this->data || '' !== $this->event ) {
				$this->ready[] = array( $this->event, implode( "\n", $this->data ) );
			}
			$this->event = '';
			$this->data  = array();

			return;
		}
		if ( ':' === $line[0] ) {
			return; // Comment / keep-alive.
		}

		$colon = strpos( $line, ':' );
		if ( false === $colon ) {
			$field = $line;
			$value = '';
		} else {
			$field = substr( $line, 0, $colon );
			$value = substr( $line, $colon + 1 );
			if ( '' !== $value && ' ' === $value[0] ) {
				$value = substr( $value, 1 );
			}
		}

		if ( 'event' === $field ) {
			$this->event = $value;
		} elseif ( 'data' === $field ) {
			$this->data[] = $value;
		}
		// id / retry / unknown fields ignored.
	}
}
