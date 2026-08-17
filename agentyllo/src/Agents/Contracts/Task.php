<?php
/**
 * Immutable unit of agent work.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A task travels between agents over the bus and is correlated across the
 * journal by its `ref` uuid.
 */
final class Task {

	/**
	 * Constructor.
	 *
	 * @param string      $ref          Correlation uuid.
	 * @param string      $type         Dot-notation task type, e.g. 'index.post', 'verify.retrieval'.
	 * @param array       $payload      Task arguments (JSON-safe).
	 * @param int         $priority     0 (lowest) – 100 (highest).
	 * @param string|null $requested_by Originating agent id, null for system/user origin.
	 * @param int         $attempt      1-based delivery attempt.
	 */
	public function __construct(
		public readonly string $ref,
		public readonly string $type,
		public readonly array $payload = array(),
		public readonly int $priority = 50,
		public readonly ?string $requested_by = null,
		public readonly int $attempt = 1,
	) {
	}

	/**
	 * Create a new task with a fresh correlation id.
	 *
	 * @param string      $type         Task type.
	 * @param array       $payload      Payload.
	 * @param int         $priority     Priority 0-100.
	 * @param string|null $requested_by Originating agent id.
	 */
	public static function create( string $type, array $payload = array(), int $priority = 50, ?string $requested_by = null ): self {
		return new self( self::uuid4(), $type, $payload, max( 0, min( 100, $priority ) ), $requested_by );
	}

	/**
	 * Next delivery attempt of the same task.
	 */
	public function retry(): self {
		return new self( $this->ref, $this->type, $this->payload, $this->priority, $this->requested_by, $this->attempt + 1 );
	}

	/**
	 * JSON-safe representation (Action Scheduler args).
	 */
	public function to_array(): array {
		return array(
			'ref'          => $this->ref,
			'type'         => $this->type,
			'payload'      => $this->payload,
			'priority'     => $this->priority,
			'requested_by' => $this->requested_by,
			'attempt'      => $this->attempt,
		);
	}

	/**
	 * Rebuild from to_array() output.
	 *
	 * @param array $data Serialized task.
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['ref'] ?? self::uuid4() ),
			(string) ( $data['type'] ?? 'unknown' ),
			is_array( $data['payload'] ?? null ) ? $data['payload'] : array(),
			(int) ( $data['priority'] ?? 50 ),
			isset( $data['requested_by'] ) ? (string) $data['requested_by'] : null,
			max( 1, (int) ( $data['attempt'] ?? 1 ) ),
		);
	}

	/**
	 * RFC 4122 v4 uuid without WordPress dependency (unit-testable).
	 */
	private static function uuid4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
