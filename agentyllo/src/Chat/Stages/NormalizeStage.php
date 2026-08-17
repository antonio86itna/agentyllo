<?php
/**
 * Normalize stage: text hygiene + injection deny-scan.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Stages;

use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Stage;

defined( 'ABSPATH' ) || exit;

/**
 * First stage of every run. NFC-ish normalization without the intl extension:
 * invalid UTF-8 repaired, control/zero-width/format chars stripped (\p{C}),
 * whitespace collapsed, length capped. The cleaned original lands in
 * $context->text; a lowercase copy in meta['text_lc'] for classifiers (the
 * raw message survives untouched in $context->raw for future AI tiers).
 *
 * The injection deny-scan NEVER blocks — the classic tier is deterministic,
 * so prompt injection has nothing to inject into. meta['guard_injection'] is
 * a hardening signal the AI tiers (M7+) read before building any prompt.
 */
final class NormalizeStage implements Stage {

	private const MAX_LENGTH = 1000;

	/**
	 * Case-insensitive injection markers (substrings, matched on text_lc).
	 *
	 * @var string[]
	 */
	private const INJECTION_MARKERS = array(
		'ignore previous instructions',
		'ignore all previous',
		'ignore the above',
		'disregard',
		'system prompt',
		'you are now',
		'act as',
		'pretend you are',
		'pretend to be',
		'jailbreak',
		'dan mode',
		'developer mode',
		'do anything now',
		'ignora le istruzioni',
		'ignorez les instructions',
		'ignora las instrucciones',
		'ignoriere die anweisungen',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'normalize';
	}

	/**
	 * {@inheritDoc}
	 */
	public function status_event(): string {
		return 'understanding';
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( ChatContext $context ): void {
		$text = wp_check_invalid_utf8( $context->raw, true );

		// Zero-width/format/unassigned chars vanish (they split words when
		// replaced by spaces); other control chars (incl. \n, \t) become
		// spaces so line breaks still separate words.
		$stripped = preg_replace( '/[\p{Cf}\p{Co}\p{Cn}]+/u', '', $text );
		$text     = null === $stripped ? $text : $stripped;
		$spaced   = preg_replace( '/[\p{Cc}\s]+/u', ' ', $text );
		$text     = null === $spaced ? $text : $spaced;

		$text = trim( $text );
		if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_LENGTH, 'UTF-8' );
			$context->note( 'truncated', true );
		}

		$context->text = $text;

		$text_lc = mb_strtolower( $text, 'UTF-8' );
		$context->note( 'text_lc', $text_lc );

		foreach ( self::INJECTION_MARKERS as $marker ) {
			if ( str_contains( $text_lc, $marker ) ) {
				$context->note( 'guard_injection', true );
				break;
			}
		}
	}
}
