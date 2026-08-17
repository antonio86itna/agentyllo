<?php
/**
 * Outcome of an agent handling a task.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Low confidence (<0.6) or an explicit needs_verification flag makes the
 * orchestrator dispatch a verify.* task to a different agent.
 */
final class AgentResult {

	public const STATUS_OK      = 'ok';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_REFUSED = 'refused';

	/**
	 * Constructor.
	 *
	 * @param string $status             One of the STATUS_* constants.
	 * @param array  $payload            Result data (JSON-safe).
	 * @param float  $confidence         0.0–1.0 self-assessed confidence.
	 * @param Task[] $follow_ups         Tasks to enqueue after this result.
	 * @param bool   $needs_verification Force cross-verification by a peer agent.
	 */
	public function __construct(
		public readonly string $status,
		public readonly array $payload = array(),
		public readonly float $confidence = 1.0,
		public readonly array $follow_ups = array(),
		public readonly bool $needs_verification = false,
	) {
	}

	/**
	 * Successful result.
	 *
	 * @param array  $payload    Result data.
	 * @param float  $confidence Confidence 0-1.
	 * @param Task[] $follow_ups Follow-up tasks.
	 */
	public static function ok( array $payload = array(), float $confidence = 1.0, array $follow_ups = array() ): self {
		return new self( self::STATUS_OK, $payload, $confidence, $follow_ups );
	}

	/**
	 * Partial result: ran out of budget or degraded; payload holds what exists.
	 *
	 * @param array  $payload    Partial data.
	 * @param float  $confidence Confidence 0-1.
	 * @param Task[] $follow_ups Follow-up tasks (e.g. resume cursor).
	 */
	public static function partial( array $payload = array(), float $confidence = 0.5, array $follow_ups = array() ): self {
		return new self( self::STATUS_PARTIAL, $payload, $confidence, $follow_ups );
	}

	/**
	 * Failure with a machine-readable reason.
	 *
	 * @param string $reason  Failure reason code/message.
	 * @param array  $payload Extra context.
	 */
	public static function failed( string $reason, array $payload = array() ): self {
		return new self( self::STATUS_FAILED, array_merge( $payload, array( 'reason' => $reason ) ), 0.0 );
	}

	/**
	 * The agent declines the task (wrong scope, disabled subsystem…).
	 *
	 * @param string $reason Refusal reason.
	 */
	public static function refused( string $reason ): self {
		return new self( self::STATUS_REFUSED, array( 'reason' => $reason ), 1.0 );
	}

	/**
	 * Whether the orchestrator must dispatch a verification pass.
	 */
	public function requires_verification(): bool {
		return $this->needs_verification || ( self::STATUS_OK === $this->status && $this->confidence < 0.6 );
	}
}
