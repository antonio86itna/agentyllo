<?php
/**
 * Intent classifier tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Chat\IntentClassifier;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Chat\Intent\IntentClassifier
 */
final class IntentClassifierTest extends TestCase {

	private IntentClassifier $classifier;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_option' )->justReturn( array() );
		$this->classifier = new IntentClassifier();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_greeting_pattern_hits_with_high_confidence(): void {
		$result = $this->classifier->classify( 'hi there!', array() );

		self::assertArrayHasKey( 'intent', $result );
		self::assertArrayHasKey( 'confidence', $result );
		self::assertSame( 'greeting', $result['intent'] );
		self::assertGreaterThanOrEqual( 0.9, $result['confidence'] );
	}

	public function test_price_pattern_hits_price_stock(): void {
		$result = $this->classifier->classify( 'how much does the blue widget cost?', array() );

		self::assertSame( 'price_stock', $result['intent'] );
		self::assertGreaterThanOrEqual( 0.9, $result['confidence'] );
	}

	public function test_contact_pattern_hits_contact(): void {
		$result = $this->classifier->classify( 'how can i contact you?', array() );

		self::assertSame( 'contact', $result['intent'] );
		self::assertGreaterThanOrEqual( 0.9, $result['confidence'] );
	}

	public function test_handoff_pattern_hits_handoff(): void {
		$result = $this->classifier->classify( 'i want to speak with a human agent', array() );

		self::assertSame( 'handoff', $result['intent'] );
		self::assertGreaterThanOrEqual( 0.9, $result['confidence'] );
	}

	public function test_product_query_fallback_when_entities_present(): void {
		$entities = array(
			'products'     => array( 'wireless headphones' ),
			'pages'        => array(),
			'skus'         => array(),
			'price_bounds' => array(),
		);

		$result = $this->classifier->classify( 'looking for wireless headphones', $entities );

		self::assertSame( 'product_query', $result['intent'] );
	}

	public function test_site_info_fallback_without_entities(): void {
		$result = $this->classifier->classify( 'the quick brown fox jumps over the lazy dog', array() );

		self::assertSame( 'site_info', $result['intent'] );
		self::assertLessThan( 0.9, $result['confidence'], 'fallback must not claim pattern-level confidence' );
	}
}
