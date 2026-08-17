<?php
/**
 * Language detection stage (silent, sticky).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Stages;

use Agentyllo\Chat\LanguageDetector;
use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Stage;

defined( 'ABSPATH' ) || exit;

/**
 * Sets $context->visitor_lang with sticky-session semantics: a session that
 * settled on a language keeps it until the detector is CONFIDENT about a
 * switch (>= 0.7 confidence AND >= 20 chars — short messages like "ok" or
 * "ciao" never flip the language). The stored session language arrives in
 * meta['session_lang'], set by the REST layer from the agy_sessions row; the
 * REST layer persists any switch back via SessionManager::touch(). Without a
 * session language the site language's two-letter code is the floor, so the
 * classic tier always has a definite language to address the visitor in.
 */
final class LanguageDetectStage implements Stage {

	private const SWITCH_CONFIDENCE = 0.7;
	private const SWITCH_MIN_CHARS  = 20;

	/**
	 * Constructor.
	 *
	 * @param LanguageDetector $detector Detection service.
	 */
	public function __construct( private readonly LanguageDetector $detector ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'language_detect';
	}

	/**
	 * {@inheritDoc}
	 */
	public function status_event(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( ChatContext $context ): void {
		$detected = $this->detector->detect( $context->text );

		$session_lang = strtolower( substr( (string) ( $context->meta['session_lang'] ?? '' ), 0, 2 ) );
		$site_lang    = strtolower( substr( $context->site_lang, 0, 2 ) );

		$context->lang_confidence = (float) $detected['confidence'];
		$context->note( 'lang_detected', $detected['lang'] );

		$confident = '' !== $detected['lang']
			&& $detected['confidence'] >= self::SWITCH_CONFIDENCE
			&& mb_strlen( $context->text, 'UTF-8' ) >= self::SWITCH_MIN_CHARS;

		if ( $confident ) {
			$context->visitor_lang = $detected['lang'];
			if ( '' !== $session_lang && $session_lang !== $detected['lang'] ) {
				$context->note( 'lang_switched', $session_lang . '>' . $detected['lang'] );
			}
			return;
		}

		$context->visitor_lang = '' !== $session_lang ? $session_lang : $site_lang;
	}
}
