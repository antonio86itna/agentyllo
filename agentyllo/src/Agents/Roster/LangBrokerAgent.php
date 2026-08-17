<?php
/**
 * Language broker: roster surface of visitor-language detection and reply
 * language policy.
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
 * The mechanics live in the chat pipeline: Chat\Language\LanguageDetector
 * sets ChatContext::$visitor_lang/::$lang_confidence, and the reply-language
 * policy (site_language | visitor_language | fixed, plus the classic-tier
 * courtesy notice for confidently different languages) is applied by the
 * pipeline stages inside the HTTP request. This agent takes no bus tasks —
 * it exists so language brokering is visible in the roster and covered by
 * the sentinel's daily health sweep and the quarantine dashboard.
 */
final class LangBrokerAgent implements Agent {

	public const ID = 'lang_broker';

	/**
	 * FQCN of the backing pipeline service (string on purpose: no hard
	 * autoload dependency from the roster to the chat pipeline).
	 */
	private const BACKING = 'Agentyllo\Chat\LanguageDetector';

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
		return array( 'language' );
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
		return AgentResult::refused( 'lang_broker runs inside the chat pipeline; it takes no bus tasks' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		$exists = class_exists( self::BACKING );

		return ( new HealthReport() )->add(
			'detector_class_exists',
			$exists,
			$exists ? '' : self::BACKING . ' is missing',
			true
		);
	}
}
