<?php
/**
 * Registry manifest tests (bundled snapshot + pure helpers).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Infra\Crypto\Ed25519Verifier;
use Agentyllo\Registry\Manifest;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Registry\Manifest
 * @covers \Agentyllo\Infra\Crypto\Ed25519Verifier
 */
final class ManifestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_bundled_snapshot_is_valid_and_signed(): void {
		$file = AGYL_DIR . 'assets/registry/stable.json';
		// The signature is a test fixture, not a shipped file: WP.org allows
		// only ordinary asset types in the zip, and nothing at runtime reads
		// a bundled signature (only remote syncs are verified).
		$sig = AGYL_DIR . 'tests/fixtures/stable.json.sig';
		self::assertFileExists( $file );
		self::assertFileExists( $sig );

		$verifier = new Ed25519Verifier();
		self::assertTrue( $verifier->verify( (string) file_get_contents( $file ), (string) file_get_contents( $sig ) ) );
		self::assertFalse( $verifier->verify( "tampered\n" . file_get_contents( $file ), (string) file_get_contents( $sig ) ) );
	}

	public function test_default_models_and_pricing_come_from_the_registry(): void {
		$manifest = new Manifest();

		self::assertSame( 'bundled', $manifest->origin() );
		self::assertContains( 'openai', $manifest->providers() );
		self::assertContains( 'anthropic', $manifest->providers() );

		self::assertSame( 'gpt-5.6-luna', $manifest->default_chat_model( 'openai' ) );
		self::assertSame( 'claude-haiku-4-5', $manifest->default_chat_model( 'anthropic' ) );
		self::assertSame( 'text-embedding-3-small', $manifest->default_embedding_model( 'openai' ) );

		$haiku = $manifest->chat_model( 'anthropic', 'claude-haiku-4-5' );
		self::assertNotNull( $haiku );
		self::assertEqualsWithDelta( 0.0035, Manifest::cost( $haiku, 1000, 500 ), 0.000001 );

		// Unknown configured id resolves to the provider default, never to null.
		$resolved = $manifest->resolve_chat_model( 'openai', 'gpt-does-not-exist' );
		self::assertSame( 'gpt-5.6-luna', $resolved['id'] );

		// Sampling/effort flags are registry-driven per model.
		self::assertTrue( (bool) $haiku['sampling'] );
		self::assertFalse( (bool) $manifest->chat_model( 'anthropic', 'claude-sonnet-5' )['sampling'] );
	}
}
