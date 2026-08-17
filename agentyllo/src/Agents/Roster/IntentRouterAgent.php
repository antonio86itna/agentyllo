<?php
/**
 * Intent router: roster surface of chat intent classification and routing.
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
 * The mechanics live in the chat pipeline (Chat\Intent\IntentClassifier feeds
 * the classify/route stages that set ChatContext::$intent and ::$route inside
 * the HTTP request). This agent takes no bus tasks — it exists so intent
 * routing is visible in the roster and covered by the sentinel's daily
 * health sweep and the quarantine dashboard.
 */
final class IntentRouterAgent implements Agent {

	public const ID = 'intent_router';

	/**
	 * FQCN of the backing pipeline service (string on purpose: no hard
	 * autoload dependency from the roster to the chat pipeline).
	 */
	private const BACKING = 'Agentyllo\Chat\IntentClassifier';

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
		return array( 'intent', 'route' );
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
		return AgentResult::refused( 'intent_router runs inside the chat pipeline; it takes no bus tasks' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		$exists = class_exists( self::BACKING );

		return ( new HealthReport() )->add(
			'classifier_class_exists',
			$exists,
			$exists ? '' : self::BACKING . ' is missing',
			true
		);
	}
}
