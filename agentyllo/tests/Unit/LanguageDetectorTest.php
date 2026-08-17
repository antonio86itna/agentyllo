<?php
/**
 * Language detector tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Chat\LanguageDetector;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Chat\Language\LanguageDetector
 */
final class LanguageDetectorTest extends TestCase {

	private LanguageDetector $detector;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_option' )->justReturn( array() );
		$this->detector = new LanguageDetector();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_detects_italian_sentence(): void {
		$result = $this->detector->detect( 'Quali sono i tempi di spedizione per questo prodotto? Vorrei anche sapere i costi.' );

		self::assertArrayHasKey( 'lang', $result );
		self::assertArrayHasKey( 'confidence', $result );
		self::assertSame( 'it', $result['lang'] );
		self::assertGreaterThanOrEqual( 0.7, $result['confidence'] );
	}

	public function test_detects_english_sentence(): void {
		$result = $this->detector->detect( 'What are your shipping options and how long does the delivery usually take?' );

		self::assertSame( 'en', $result['lang'] );
		self::assertGreaterThanOrEqual( 0.7, $result['confidence'] );
	}

	public function test_detects_german_sentence(): void {
		$result = $this->detector->detect( 'Können Sie mir bitte die Versandkosten und die Lieferzeit für dieses Produkt mitteilen?' );

		self::assertSame( 'de', $result['lang'] );
		self::assertGreaterThanOrEqual( 0.7, $result['confidence'] );
	}

	public function test_short_text_has_low_confidence(): void {
		$result = $this->detector->detect( 'ok' );

		self::assertLessThan( 0.7, $result['confidence'], 'texts under 20 chars must not reach the courtesy-notice threshold' );
	}

	public function test_cjk_script_is_detected(): void {
		$result = $this->detector->detect( '配送方法について教えてください。送料はいくらですか。' );

		self::assertSame( 'ja', $result['lang'] );
		self::assertGreaterThanOrEqual( 0.7, $result['confidence'] );
	}
}
