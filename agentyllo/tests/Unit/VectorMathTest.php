<?php
/**
 * Vector math tests (pure PHP parts of VectorStore).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\KB\Retrieval\VectorStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\KB\Retrieval\VectorStore::normalize
 * @covers \Agentyllo\KB\Retrieval\VectorStore::cosine
 */
final class VectorMathTest extends TestCase {

	public function test_normalize_produces_unit_length_and_rejects_zero(): void {
		$n = VectorStore::normalize( array( 3.0, 4.0 ) );
		self::assertNotNull( $n );
		self::assertEqualsWithDelta( 0.6, $n[0], 1e-9 );
		self::assertEqualsWithDelta( 0.8, $n[1], 1e-9 );
		self::assertNull( VectorStore::normalize( array( 0.0, 0.0 ) ) );
		self::assertNull( VectorStore::normalize( array() ) );
	}

	public function test_cosine_similarity_orders_paraphrases_above_unrelated(): void {
		$a = array( 1.0, 1.0, 0.0, 0.0 );
		$b = array( 0.9, 1.1, 0.1, 0.0 );
		$c = array( 0.0, 0.0, 1.0, 1.0 );

		self::assertEqualsWithDelta( 1.0, VectorStore::cosine( $a, $a ), 1e-9 );
		self::assertGreaterThan( VectorStore::cosine( $a, $c ), VectorStore::cosine( $a, $b ) );
		self::assertSame( 0.0, VectorStore::cosine( $a, array( 1.0, 2.0 ) ) ); // Dimension mismatch.
	}

	public function test_packed_float32_roundtrip_keeps_dot_products(): void {
		$v      = VectorStore::normalize( array( 0.25, -0.5, 0.75, 1.0 ) );
		$packed = pack( 'g*', ...$v );
		$back   = array_values( unpack( 'g*', $packed ) );

		self::assertCount( 4, $back );
		self::assertEqualsWithDelta( 1.0, VectorStore::cosine( $v, $back ), 1e-6 );
	}
}
