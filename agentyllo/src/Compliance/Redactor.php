<?php
/**
 * PII redaction.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Compliance;

defined( 'ABSPATH' ) || exit;

/**
 * Masks emails, phone numbers, IBANs and payment-card numbers (Luhn-checked)
 * in free text. Used at log-write time (mode 'logs') and before any paid
 * provider call (mode 'before_ai'). Pure function, unit-testable.
 */
final class Redactor {

	public const MODE_OFF       = 'off';
	public const MODE_LOGS      = 'logs';
	public const MODE_BEFORE_AI = 'before_ai';

	/**
	 * Redact PII in text.
	 *
	 * @param string $text Input.
	 * @return string Text with PII replaced by bracketed placeholders.
	 */
	public static function redact( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		// Emails.
		$text = (string) preg_replace( '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}/u', '[email]', $text );

		// IBAN: 2 letters, 2 digits, 11–30 alphanumerics (spaces allowed in groups).
		$text = (string) preg_replace_callback(
			'/\b([A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,7}(?:[ ]?[A-Z0-9]{1,4})?)\b/',
			static fn ( array $m ): string => self::looks_like_iban( $m[1] ) ? '[iban]' : $m[0],
			$text
		);

		// Payment cards: 13–19 digits with optional spaces/dashes, Luhn-valid.
		$text = (string) preg_replace_callback(
			'/\b(?:\d[ \-]?){13,19}\b/',
			static function ( array $m ): string {
				$digits = preg_replace( '/\D/', '', $m[0] );
				return self::luhn( (string) $digits ) ? '[card]' : $m[0];
			},
			$text
		);

		// Phones: international or local, 7+ digits with separators, not already masked.
		$text = (string) preg_replace_callback(
			'/(?<![\w\[])(?:\+?\d{1,3}[ .\-]?)?(?:\(?\d{2,4}\)?[ .\-]?){2,4}\d{2,4}(?![\w\]])/',
			static function ( array $m ): string {
				$digits = preg_replace( '/\D/', '', $m[0] );
				$n      = strlen( (string) $digits );
				// Years, prices, short refs stay; real phones have 7–15 digits.
				return ( $n >= 7 && $n <= 15 ) ? '[phone]' : $m[0];
			},
			$text
		);

		return $text;
	}

	/**
	 * Redact according to a configured mode and call site.
	 *
	 * @param string $text Input.
	 * @param string $mode Configured pii_redaction mode.
	 * @param string $site 'logs' or 'before_ai'.
	 */
	public static function apply( string $text, string $mode, string $site ): string {
		if ( self::MODE_OFF === $mode ) {
			return $text;
		}
		if ( self::MODE_LOGS === $mode && 'logs' !== $site ) {
			return $text;
		}

		return self::redact( $text );
	}

	/**
	 * Luhn checksum.
	 *
	 * @param string $digits Digit string.
	 */
	public static function luhn( string $digits ): bool {
		$len = strlen( $digits );
		if ( $len < 13 || $len > 19 ) {
			return false;
		}
		$sum = 0;
		$alt = false;
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$d = (int) $digits[ $i ];
			if ( $alt ) {
				$d *= 2;
				if ( $d > 9 ) {
					$d -= 9;
				}
			}
			$sum += $d;
			$alt  = ! $alt;
		}

		return 0 === $sum % 10;
	}

	/**
	 * IBAN mod-97 check.
	 *
	 * @param string $iban Candidate.
	 */
	public static function looks_like_iban( string $iban ): bool {
		$iban = strtoupper( (string) preg_replace( '/\s+/', '', $iban ) );
		if ( strlen( $iban ) < 15 || strlen( $iban ) > 34 || ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban ) ) {
			return false;
		}
		$rearranged = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$numeric    = '';
		foreach ( str_split( $rearranged ) as $ch ) {
			$numeric .= ctype_alpha( $ch ) ? (string) ( ord( $ch ) - 55 ) : $ch;
		}
		// Piecewise mod 97 (string may exceed int range).
		$remainder = 0;
		foreach ( str_split( $numeric, 7 ) as $chunk ) {
			$remainder = ( (int) ( $remainder . $chunk ) ) % 97;
		}

		return 1 === $remainder;
	}
}
