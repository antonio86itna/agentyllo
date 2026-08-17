<?php
/**
 * Container unit tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Agentyllo\Container
 */
final class ContainerTest extends TestCase {

	public function test_singleton_returns_same_instance(): void {
		$c = new Container();
		$c->singleton( 'svc', static fn (): \stdClass => new \stdClass() );

		self::assertSame( $c->get( 'svc' ), $c->get( 'svc' ) );
	}

	public function test_factory_returns_new_instances(): void {
		$c = new Container();
		$c->factory( 'svc', static fn (): \stdClass => new \stdClass() );

		self::assertNotSame( $c->get( 'svc' ), $c->get( 'svc' ) );
	}

	public function test_instance_binding_and_has(): void {
		$c   = new Container();
		$obj = new \stdClass();
		$c->instance( 'svc', $obj );

		self::assertTrue( $c->has( 'svc' ) );
		self::assertFalse( $c->has( 'other' ) );
		self::assertSame( $obj, $c->get( 'svc' ) );
	}

	public function test_factories_receive_container(): void {
		$c = new Container();
		$c->singleton( 'dep', static fn (): \stdClass => new \stdClass() );
		$c->singleton( 'svc', static function ( Container $c ): array {
			return array( 'dep' => $c->get( 'dep' ) );
		} );

		self::assertSame( $c->get( 'dep' ), $c->get( 'svc' )['dep'] );
	}

	public function test_unknown_service_throws(): void {
		$this->expectException( RuntimeException::class );
		( new Container() )->get( 'missing' );
	}

	public function test_circular_dependency_throws(): void {
		$c = new Container();
		$c->singleton( 'a', static fn ( Container $c ): mixed => $c->get( 'b' ) );
		$c->singleton( 'b', static fn ( Container $c ): mixed => $c->get( 'a' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/circular/i' );
		$c->get( 'a' );
	}
}
