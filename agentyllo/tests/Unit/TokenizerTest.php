<?php
/**
 * Tokenizer tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\KB\Retrieval\Tokenizer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\KB\Retrieval\Tokenizer
 */
final class TokenizerTest extends TestCase {

	private Tokenizer $tokenizer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_option' )->justReturn( array() );
		$this->tokenizer = new Tokenizer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_stopwords_removed_english(): void {
		$tokens = $this->tokenizer->tokenize( 'The price of the product and the shipping', 'en' );

		self::assertNotContains( 'the', $tokens );
		self::assertNotContains( 'and', $tokens );
		self::assertContains( 'price', $tokens );
		self::assertContains( 'product', $tokens );
	}

	public function test_stopwords_removed_italian(): void {
		$tokens = $this->tokenizer->tokenize( 'Il prezzo della spedizione per il prodotto', 'it_IT' );

		self::assertNotContains( 'il', $tokens );
		self::assertNotContains( 'della', $tokens );
		self::assertContains( 'prezz', $tokens ); // 'prezzo' stemmed.
	}

	public function test_light_stemming_groups_variants(): void {
		$a = $this->tokenizer->tokenize( 'shipping', 'en' );
		$b = $this->tokenizer->tokenize( 'shipped', 'en' );

		self::assertSame( $a, $b, 'shipping and shipped should stem identically' );
	}

	public function test_cjk_becomes_bigrams(): void {
		$tokens = $this->tokenizer->tokenize( '配送方法', 'ja' );

		self::assertContains( '配送', $tokens );
		self::assertContains( '送方', $tokens );
		self::assertContains( '方法', $tokens );
	}

	public function test_case_and_length_filters(): void {
		$tokens = $this->tokenizer->tokenize( 'SKU-1234 X a ProductName', 'en' );

		self::assertContains( 'sku-1234', $tokens );
		self::assertNotContains( 'x', $tokens, 'single chars dropped' );
		self::assertNotContains( 'a', $tokens );
	}

	public function test_dynamic_stopwords_are_applied(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, mixed $default_value = false ): mixed => 'agyl_kb_dynamic_stopwords' === $key ? array( 'acme' ) : $default_value
		);
		$tokenizer = new Tokenizer();

		$tokens = $tokenizer->tokenize( 'acme widget acme gadget', 'en' );

		self::assertNotContains( 'acme', $tokens );
		self::assertContains( 'widget', $tokens );
	}

	public function test_terms_counts_frequencies(): void {
		$terms = $this->tokenizer->terms( 'widget widget gadget', 'en' );

		self::assertSame( 2, $terms['widget'] );
		self::assertSame( 1, $terms['gadget'] );
	}
}
