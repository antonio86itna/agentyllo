<?php
/**
 * Fact-slot invariant enforcement on generated text.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Prompt;

defined( 'ABSPATH' ) || exit;

/**
 * THE anti-hallucination invariant for AI tiers, enforced structurally:
 * hard facts in a generated answer (currency amounts, phone numbers, email
 * addresses) must appear verbatim either in the immutable fact slots or in
 * the retrieved sources the model was given. Anything else is a violation
 * and the answer is discarded in favour of the classic composer. URLs are
 * never allowed in model output at all (links come from the link graph) —
 * they are stripped rather than failing the answer.
 *
 * Pure PHP; unit-tested without WordPress.
 */
final class FactGuard {

	private const MONEY   = '/(?:[€$£¥]\s?\d[\d.,]*|\b\d[\d.,]*\s?(?:€|\$|£|¥|EUR|USD|GBP|CHF)\b)/u';
	private const EMAIL   = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
	private const PHONE   = '/(?<![\w.,])(?:\+?\d[\d\s().\-]{6,}\d)(?![\w])/u';
	private const URL     = '/\b(?:https?:\/\/|www\.)[^\s)>\]]+/i';
	private const MARKDOWN_LINK = '/\[([^\]]*)\]\((?:https?:\/\/|www\.)[^)]*\)/i';

	/**
	 * Verify generated text against the allowed fact universe.
	 *
	 * @param string   $text       Generated answer.
	 * @param string[] $fact_values Fact-slot values (verbatim).
	 * @param string   $sources    Concatenated source text shown to the model.
	 * @return array{ok: bool, text: string, violations: string[], stripped_urls: int}
	 */
	public static function verify( string $text, array $fact_values, string $sources ): array {
		$stripped = 0;

		// Markdown links → keep the label, drop the URL; bare URLs → drop.
		$text = (string) preg_replace_callback(
			self::MARKDOWN_LINK,
			static function ( array $m ) use ( &$stripped ): string {
				++$stripped;

				return $m[1];
			},
			$text
		);
		$text = (string) preg_replace_callback(
			self::URL,
			static function () use ( &$stripped ): string {
				++$stripped;

				return '';
			},
			$text
		);

		$allowed    = implode( "\n", $fact_values ) . "\n" . $sources;
		$violations = array();

		foreach ( self::money( $text ) as $amount ) {
			if ( ! self::digits_present( $amount, $allowed ) ) {
				$violations[] = 'price:' . $amount;
			}
		}
		foreach ( self::matches( self::EMAIL, $text ) as $email ) {
			if ( false === mb_stripos( $allowed, $email ) ) {
				$violations[] = 'email:' . $email;
			}
		}
		foreach ( self::phones( $text ) as $phone ) {
			if ( ! self::digits_present( $phone, $allowed ) ) {
				$violations[] = 'phone:' . $phone;
			}
		}

		return array(
			'ok'            => ! $violations,
			'text'          => trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $text ) ),
			'violations'    => $violations,
			'stripped_urls' => $stripped,
		);
	}

	/**
	 * Currency amounts in the text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function money( string $text ): array {
		return self::matches( self::MONEY, $text );
	}

	/**
	 * Phone-like digit runs (7-15 digits) — years and prices are excluded by
	 * length and by the money pass running first.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function phones( string $text ): array {
		$out = array();
		foreach ( self::matches( self::PHONE, $text ) as $candidate ) {
			$digits = (string) preg_replace( '/\D/', '', $candidate );
			$len    = strlen( $digits );
			if ( $len >= 7 && $len <= 15 && ! preg_match( self::MONEY, $candidate ) ) {
				$out[] = $candidate;
			}
		}

		return $out;
	}

	/**
	 * Whether the digit sequence of $value occurs in the allowed text
	 * (also comparing digit-only forms, so "€ 12,00" ≈ "12.00 EUR").
	 *
	 * @param string $value   Value from the answer.
	 * @param string $allowed Allowed universe.
	 */
	private static function digits_present( string $value, string $allowed ): bool {
		if ( false !== mb_stripos( $allowed, trim( $value ) ) ) {
			return true;
		}
		$digits = (string) preg_replace( '/\D/', '', $value );
		if ( '' === $digits ) {
			return true;
		}
		// Normalize trailing ",00"/".00" so "12" matches "12.00".
		$core = (string) preg_replace( '/00$/', '', $digits );

		$allowed_digits = (string) preg_replace( '/[^\d\n]/', ' ', $allowed );
		$allowed_digits = (string) preg_replace( '/[ \t]+/', ' ', $allowed_digits );

		foreach ( preg_split( '/[\s\n]+/', $allowed_digits ) ?: array() as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( $token === $digits || $token === $core || (string) preg_replace( '/00$/', '', $token ) === $core ) {
				return true;
			}
		}

		// Digit runs may be split by separators in the source ("12 000").
		$compact = (string) preg_replace( '/\D/', '', $allowed );

		return '' !== $core && strlen( $core ) >= 3 && false !== strpos( $compact, $core );
	}

	/**
	 * Distinct regex matches.
	 *
	 * @param string $pattern Pattern.
	 * @param string $text    Text.
	 * @return string[]
	 */
	private static function matches( string $pattern, string $text ): array {
		if ( ! preg_match_all( $pattern, $text, $m ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'trim', $m[0] ) ) );
	}
}
