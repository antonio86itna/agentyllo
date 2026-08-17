<?php
/**
 * Chunker tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\KB\Indexer\Chunker;
use Agentyllo\KB\Retrieval\Tokenizer;
use Agentyllo\KB\Source\DocumentDraft;
use Agentyllo\KB\Source\NormalizedBlock;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\KB\Indexer\Chunker
 */
final class ChunkerTest extends TestCase {

	private Chunker $chunker;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_json_encode' )->alias( static fn ( mixed $data ): string|false => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->chunker = new Chunker( new Tokenizer() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function draft( array $blocks, array $structured = array() ): DocumentDraft {
		return new DocumentDraft( 'post', '1', 'page', 'Shipping FAQ', 'https://x.test/faq', 'en', $blocks, $structured );
	}

	public function test_spec_chunk_comes_first_from_structured(): void {
		$chunks = $this->chunker->chunk(
			$this->draft(
				array( new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, 'We ship worldwide.' ) ),
				array( 'price' => '19.90', 'stock' => array( 'status' => 'instock' ) )
			)
		);

		self::assertSame( 'spec', $chunks[0]['kind'] );
		self::assertSame( 0, $chunks[0]['seq'] );
		self::assertStringContainsString( 'price: 19.90', $chunks[0]['content'] );
		self::assertStringContainsString( 'stock status: instock', $chunks[0]['content'] );
	}

	public function test_heading_path_breadcrumbs(): void {
		$chunks = $this->chunker->chunk(
			$this->draft(
				array(
					new NormalizedBlock( NormalizedBlock::KIND_HEADING, 'Delivery times', 2 ),
					new NormalizedBlock( NormalizedBlock::KIND_HEADING, 'Europe', 3 ),
					new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, 'Delivery to Europe takes 3-5 days.' ),
				)
			)
		);

		self::assertCount( 1, $chunks );
		self::assertSame( 'Shipping FAQ › Delivery times › Europe', $chunks[0]['heading_path'] );
	}

	public function test_long_sections_split_with_overlap(): void {
		$sentence = 'This is a reasonably long sentence about shipping policies and delivery details for testing. ';
		$chunks   = $this->chunker->chunk(
			$this->draft( array( new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, trim( str_repeat( $sentence, 40 ) ) ) ) )
		);

		self::assertGreaterThan( 1, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			self::assertLessThanOrEqual( 2000, mb_strlen( $chunk['content'] ), 'hard max respected' );
		}
		// Overlap: the tail of chunk N shares text with the head of chunk N+1.
		$tail = mb_substr( $chunks[0]['content'], -60 );
		self::assertStringContainsString( mb_substr( $tail, 0, 30 ), $chunks[1]['content'] );
	}

	public function test_simhash_is_16_hex_and_similar_texts_are_close(): void {
		// SimHash needs realistic chunk sizes (~100 terms): tiny texts are noisy by design.
		$paragraph = 'Free shipping applies on orders over fifty euro across Italy and Europe using express couriers. ' .
			'Standard delivery takes three to five working days depending on the destination country and carrier load. ' .
			'Returns are accepted within thirty days of delivery provided items remain unused in original packaging. ' .
			'Refunds are processed back to the original payment method within seven working days after inspection. ' .
			'Customer support answers shipping questions every weekday from nine in the morning until six in the evening. ';
		$base    = trim( $paragraph );
		$similar = trim( str_replace( 'express couriers', 'fast couriers', $paragraph ) );
		$other   = trim( str_repeat( 'Our restaurant serves traditional pizza and handmade pasta every evening in the historic city center with seasonal ingredients from local farmers and a curated wine list. ', 5 ) );

		$a = $this->chunker->chunk( $this->draft( array( new NormalizedBlock( 'paragraph', $base ) ) ) )[0]['simhash'];
		$b = $this->chunker->chunk( $this->draft( array( new NormalizedBlock( 'paragraph', $similar ) ) ) )[0]['simhash'];
		$c = $this->chunker->chunk( $this->draft( array( new NormalizedBlock( 'paragraph', $other ) ) ) )[0]['simhash'];

		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $a );

		$distance = static function ( string $x, string $y ): int {
			$bits = 0;
			for ( $i = 0; $i < 16; $i += 8 ) {
				$bits += substr_count( decbin( hexdec( substr( $x, $i, 8 ) ) ^ hexdec( substr( $y, $i, 8 ) ) ), '1' );
			}
			return $bits;
		};

		self::assertLessThanOrEqual( 6, $distance( $a, $b ), 'near-duplicates should be close in Hamming distance' );
		self::assertGreaterThan( 10, $distance( $a, $c ), 'unrelated texts should be distant' );
		self::assertLessThan( $distance( $a, $c ), $distance( $a, $b ), 'similar pair must be closer than unrelated pair' );
	}

	public function test_numeric_tokens_do_not_break_simhash(): void {
		// "2015" tokenizes to an integer array key; hash() must receive a string.
		$chunks = $this->chunker->chunk(
			$this->draft( array( new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, 'Founded in 2015, reachable at +39 055 123456.' ) ) )
		);

		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $chunks[0]['simhash'] );
	}

	public function test_token_estimate_present(): void {
		$chunks = $this->chunker->chunk(
			$this->draft( array( new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, str_repeat( 'word ', 100 ) ) ) )
		);

		self::assertGreaterThan( 50, $chunks[0]['token_est'] );
	}
}
