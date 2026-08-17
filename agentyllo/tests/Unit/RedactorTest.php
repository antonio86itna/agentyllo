<?php
/**
 * PII redactor tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Compliance\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Compliance\Redactor
 */
final class RedactorTest extends TestCase {

	public function test_masks_email(): void {
		self::assertSame( 'write to [email] please', Redactor::redact( 'write to mario.rossi+shop@example.co.uk please' ) );
	}

	public function test_masks_phone_numbers(): void {
		self::assertStringContainsString( '[phone]', Redactor::redact( 'call me at +39 055 123456' ) );
		self::assertStringContainsString( '[phone]', Redactor::redact( 'my number is (555) 123-4567' ) );
	}

	public function test_keeps_prices_and_years(): void {
		$text = 'Founded in 2015, the bag costs 249 euro and ships in 24 hours.';
		self::assertSame( $text, Redactor::redact( $text ) );
	}

	public function test_masks_luhn_valid_card_only(): void {
		self::assertStringContainsString( '[card]', Redactor::redact( 'card 4111 1111 1111 1111 exp 12/27' ) );
		// Same length, invalid checksum: left alone (could be an order/tracking number).
		self::assertStringNotContainsString( '[card]', Redactor::redact( 'tracking 4111 1111 1111 1112' ) );
	}

	public function test_masks_valid_iban(): void {
		self::assertStringContainsString( '[iban]', Redactor::redact( 'IBAN GB82 WEST 1234 5698 7654 32' ) );
		self::assertStringNotContainsString( '[iban]', Redactor::redact( 'ref GB00 WEST 1234 5698 7654 32' ) );
	}

	public function test_apply_respects_mode_and_site(): void {
		$text = 'mail me at a@b.io';
		self::assertSame( $text, Redactor::apply( $text, Redactor::MODE_OFF, 'logs' ) );
		self::assertSame( 'mail me at [email]', Redactor::apply( $text, Redactor::MODE_LOGS, 'logs' ) );
		self::assertSame( $text, Redactor::apply( $text, Redactor::MODE_LOGS, 'before_ai' ) );
		self::assertSame( 'mail me at [email]', Redactor::apply( $text, Redactor::MODE_BEFORE_AI, 'before_ai' ) );
		self::assertSame( 'mail me at [email]', Redactor::apply( $text, Redactor::MODE_BEFORE_AI, 'logs' ) );
	}

	public function test_luhn_helper(): void {
		self::assertTrue( Redactor::luhn( '4111111111111111' ) );
		self::assertFalse( Redactor::luhn( '4111111111111112' ) );
		self::assertFalse( Redactor::luhn( '1234' ) );
	}
}
