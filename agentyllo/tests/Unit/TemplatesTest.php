<?php
/**
 * Response templates tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Chat\Templates;
use Agentyllo\Chat\Pipeline\ChatContext;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Chat\Compose\Templates
 */
final class TemplatesTest extends TestCase {

	private const TONES = array( 'professional', 'friendly', 'playful' );

	private const VARS = array(
		'site_name'      => 'Acme Widgets',
		'assistant_name' => 'Aria',
	);

	private Templates $templates;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme Widgets' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		$this->templates = new Templates();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_every_intent_tone_combo_returns_text(): void {
		foreach ( ChatContext::INTENTS as $intent ) {
			foreach ( self::TONES as $tone ) {
				$text = $this->templates->get( $intent, $tone, self::VARS );

				self::assertNotSame( '', trim( $text ), "empty template for {$intent}/{$tone}" );
			}
		}
	}

	public function test_vars_are_interpolated(): void {
		$greeting  = $this->templates->get( 'greeting', 'friendly', self::VARS );
		$site_info = $this->templates->get( 'site_info', 'professional', self::VARS );
		$combined  = $greeting . ' ' . $site_info;

		self::assertTrue(
			str_contains( $combined, 'Aria' ) || str_contains( $combined, 'Acme Widgets' ),
			'greeting/site_info should mention the provided assistant or site name'
		);
	}

	public function test_no_placeholder_leaks(): void {
		foreach ( ChatContext::INTENTS as $intent ) {
			$text = $this->templates->get( $intent, 'friendly', self::VARS );

			self::assertStringNotContainsString( '%s', $text, "unresolved sprintf placeholder in {$intent}" );
			self::assertStringNotContainsString( '{', $text, "unresolved brace placeholder in {$intent}" );
		}
	}
}
