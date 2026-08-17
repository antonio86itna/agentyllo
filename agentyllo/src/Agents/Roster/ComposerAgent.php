<?php
/**
 * Composer: roster surface of chat response composition.
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
 * The mechanics live in the chat pipeline's compose stage: intent + tone
 * templates (Chat\Compose\Templates) filled with verbatim fact slots, emitted
 * as typed blocks per the shared schema. This agent takes no bus tasks — it
 * exists so composition is visible in the roster and covered by the
 * sentinel's daily health sweep and the quarantine dashboard.
 */
final class ComposerAgent implements Agent {

	public const ID = 'composer';

	/**
	 * FQCN of the backing pipeline service (string on purpose: no hard
	 * autoload dependency from the roster to the chat pipeline).
	 */
	private const BACKING = 'Agentyllo\Chat\Templates';

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
		return array( 'compose' );
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
		return AgentResult::refused( 'composer runs inside the chat pipeline; it takes no bus tasks' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		$exists = class_exists( self::BACKING );

		return ( new HealthReport() )->add(
			'templates_class_exists',
			$exists,
			$exists ? '' : self::BACKING . ' is missing',
			true
		);
	}
}
