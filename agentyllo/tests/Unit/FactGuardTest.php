<?php
/**
 * Fact-slot invariant tests (pure PHP).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\AI\Prompt\FactGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\AI\Prompt\FactGuard
 */
final class FactGuardTest extends TestCase {

	private const SOURCES = "Shipping\nOrders over €50 ship free. Standard delivery costs €4.90 and takes 2-3 days.\nContact\nCall us at +39 06 1234567 or write to hello@example.com.";

	public function test_grounded_price_and_contact_pass(): void {
		$result = FactGuard::verify(
			'Standard delivery costs €4.90 and orders over €50 ship free [#1]. You can call +39 06 1234567.',
			array( '€12.00' ),
			self::SOURCES
		);

		self::assertTrue( $result['ok'], implode( ',', $result['violations'] ) );
		self::assertSame( array(), $result['violations'] );
	}

	public function test_fact_slot_price_passes_even_with_different_formatting(): void {
		$result = FactGuard::verify( 'The price is 12,00 € per unit.', array( '€12.00' ), '' );

		self::assertTrue( $result['ok'], implode( ',', $result['violations'] ) );
	}

	public function test_invented_price_is_a_violation(): void {
		$result = FactGuard::verify( 'It costs €19.99 and shipping is €4.90.', array( '€12.00' ), self::SOURCES );

		self::assertFalse( $result['ok'] );
		self::assertCount( 1, $result['violations'] );
		self::assertStringStartsWith( 'price:', $result['violations'][0] );
	}

	public function test_invented_email_and_phone_are_violations(): void {
		$result = FactGuard::verify( 'Write to sales@other.com or call +1 555 010 9999.', array(), self::SOURCES );

		self::assertFalse( $result['ok'] );
		self::assertCount( 2, $result['violations'] );
	}

	public function test_urls_are_stripped_not_failed(): void {
		$result = FactGuard::verify( 'See https://example.com/shop or [our page](https://example.com/p) for details.', array(), self::SOURCES );

		self::assertTrue( $result['ok'] );
		self::assertSame( 2, $result['stripped_urls'] );
		self::assertStringNotContainsString( 'http', $result['text'] );
		self::assertStringContainsString( 'our page', $result['text'] );
	}

	public function test_years_and_small_numbers_are_not_phones(): void {
		$result = FactGuard::verify( 'Founded in 2012, we ship 3-5 items per order and open at 9:30.', array(), '' );

		self::assertTrue( $result['ok'], implode( ',', $result['violations'] ) );
	}

	public function test_partial_prefix_with_no_amount_is_ok(): void {
		// Streaming verification runs on stable prefixes; text without facts must never fail.
		$result = FactGuard::verify( 'Our shipping policy is simple: orders over', array(), self::SOURCES );

		self::assertTrue( $result['ok'] );
	}
}
