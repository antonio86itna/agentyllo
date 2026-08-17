<?php
/**
 * Scope guard: roster surface of out-of-scope refusal handling.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Roster;

use Agentyllo\Agents\Contracts\Agent;
use Agentyllo\Agents\Contracts\AgentContext;
use Agentyllo\Agents\Contracts\AgentResult;
use Agentyllo\Agents\Contracts\HealthReport;
use Agentyllo\Agents\Contracts\Task;

defined( 'ABSPATH' ) || exit;

/**
 * The mechanics live in the chat pipeline's scope-guard stage: it flips
 * ChatContext::$route to ROUTE_REFUSE for out-of-scope questions (honoring
 * the out_of_scope_guard setting and oos_refusal_message) inside the HTTP
 * request. This agent takes no bus tasks — it exists so the guard is visible
 * in the roster and covered by the sentinel's daily health sweep and the
 * quarantine dashboard.
 */
final class ScopeGuardAgent implements Agent {

	public const ID = 'scope_guard';

	/**
	 * FQCN of the backing pipeline stage (string on purpose: no hard
	 * autoload dependency from the roster to the chat pipeline).
	 */
	private const BACKING = 'Agentyllo\Chat\Stages\ScopeGuardStage';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array( 'guard' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function subscribed_events(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle( Task $task, AgentContext $context ): AgentResult {
		return AgentResult::refused( 'scope_guard runs inside the chat pipeline; it takes no bus tasks' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		$exists = class_exists( self::BACKING );

		return ( new HealthReport() )->add(
			'guard_stage_class_exists',
			$exists,
			$exists ? '' : self::BACKING . ' is missing',
			true
		);
	}
}
