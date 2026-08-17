<?php
/**
 * SettingsSchema sanitization tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Admin\Settings\SettingsSchema;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Admin\Settings\SettingsSchema
 */
final class SettingsSchemaTest extends TestCase {

	private SettingsSchema $schema;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( string $v ): string => trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $v ) ) ?? '' )
		);
		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn ( string $v ): string => trim( strip_tags( $v ) )
		);
		Functions\when( 'rest_sanitize_boolean' )->alias(
			static fn ( mixed $v ): bool => is_string( $v )
				? in_array( strtolower( $v ), array( '1', 'true', 'yes' ), true )
				: (bool) $v
		);

		$this->schema = new SettingsSchema();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_unknown_keys_are_dropped(): void {
		$clean = $this->schema->sanitize( 'general', array( 'evil_key' => 'x', 'tone' => 'playful' ) );

		self::assertSame( array( 'tone' => 'playful' ), $clean );
	}

	public function test_invalid_enum_falls_back_to_default(): void {
		$clean = $this->schema->sanitize( 'general', array( 'operating_mode' => 'hack_mode' ) );

		self::assertSame( 'classic', $clean['operating_mode'] );
	}

	public function test_bool_coercion(): void {
		$clean = $this->schema->sanitize( 'general', array( 'out_of_scope_guard' => 'false' ) );

		self::assertFalse( $clean['out_of_scope_guard'] );
	}

	public function test_string_is_stripped_and_capped(): void {
		$clean = $this->schema->sanitize( 'general', array( 'assistant_name' => '<b>' . str_repeat( 'a', 200 ) . '</b>' ) );

		self::assertSame( 100, mb_strlen( $clean['assistant_name'] ) );
		self::assertStringNotContainsString( '<', $clean['assistant_name'] );
	}

	public function test_text_maxlen_applies(): void {
		$clean = $this->schema->sanitize( 'general', array( 'custom_instructions' => str_repeat( 'x', 5000 ) ) );

		self::assertSame( 2000, mb_strlen( $clean['custom_instructions'] ) );
	}

	public function test_non_scalar_input_falls_back_to_default(): void {
		$clean = $this->schema->sanitize( 'general', array( 'assistant_name' => array( 'nope' ) ) );

		self::assertSame( '', $clean['assistant_name'] );
	}

	public function test_defaults_cover_every_field(): void {
		foreach ( array( 'general', 'advanced' ) as $tab ) {
			$defaults = $this->schema->defaults( $tab );
			$fields   = array_keys( $this->schema->tab( $tab ) ?? array() );

			self::assertSame( $fields, array_keys( $defaults ) );
		}
	}

	public function test_unknown_tab_returns_null(): void {
		self::assertNull( $this->schema->tab( 'nope' ) );
	}
}
