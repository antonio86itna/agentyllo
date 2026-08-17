<?php
/**
 * Normalizer tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\KB\Indexer\Normalizer;
use Agentyllo\KB\Source\NormalizedBlock;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\KB\Indexer\Normalizer
 */
final class NormalizerTest extends TestCase {

	private Normalizer $normalizer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'untrailingslashit' )->alias( static fn ( string $v ): string => rtrim( $v, '/' ) );
		$this->normalizer = new Normalizer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_headings_and_paragraphs(): void {
		$result = $this->normalizer->normalize( '<h2>Shipping</h2><p>We ship worldwide.</p><h3>Costs</h3><p>Free over €50.</p>' );
		$blocks = $result['blocks'];

		self::assertSame( NormalizedBlock::KIND_HEADING, $blocks[0]->kind );
		self::assertSame( 2, $blocks[0]->level );
		self::assertSame( 'Shipping', $blocks[0]->text );
		self::assertSame( 'We ship worldwide.', $blocks[1]->text );
		self::assertSame( 3, $blocks[2]->level );
		self::assertSame( 'Free over €50.', $blocks[3]->text );
	}

	public function test_lists_become_bullets_and_tables_linearize(): void {
		$result = $this->normalizer->normalize(
			'<ul><li>Fast delivery</li><li>Free returns</li></ul>' .
			'<table><tr><th>Size</th><th>Price</th></tr><tr><td>M</td><td>€20</td></tr></table>'
		);

		$list  = $result['blocks'][0];
		$table = $result['blocks'][1];

		self::assertSame( NormalizedBlock::KIND_LIST, $list->kind );
		self::assertStringContainsString( '• Fast delivery', $list->text );
		self::assertSame( NormalizedBlock::KIND_TABLE, $table->kind );
		self::assertStringContainsString( 'Size: M; Price: €20', $table->text );
	}

	public function test_links_harvested_and_relative_urls_resolved(): void {
		$result = $this->normalizer->normalize(
			'<p>See <a href="/shipping/">shipping info</a> or <a href="https://other.test/x">external</a>.' .
			' <a href="#anchor">skip</a> <a href="mailto:a@b.c">skip too</a></p>'
		);

		$urls = array_column( $result['links'], 'url' );

		self::assertContains( 'https://example.test/shipping/', $urls );
		self::assertContains( 'https://other.test/x', $urls );
		self::assertCount( 2, $urls, 'fragment and mailto links must be skipped' );
		self::assertSame( 'shipping info', $result['links'][0]['anchor'] );
	}

	public function test_media_library_image_ids_extracted(): void {
		$result = $this->normalizer->normalize( '<p>x</p><img class="alignnone wp-image-42 size-large" src="a.jpg"><img src="b.jpg">' );

		self::assertSame( array( 42 ), $result['image_ids'] );
	}

	public function test_script_and_style_noise_dropped(): void {
		$result = $this->normalizer->normalize( '<script>alert(1)</script><style>.x{color:red}</style><p>Real content.</p>' );

		self::assertCount( 1, $result['blocks'] );
		self::assertSame( 'Real content.', $result['blocks'][0]->text );
	}

	public function test_empty_input(): void {
		$result = $this->normalizer->normalize( '  ' );

		self::assertSame( array(), $result['blocks'] );
	}
}
