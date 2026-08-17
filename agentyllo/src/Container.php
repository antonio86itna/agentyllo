<?php
/**
 * Minimal lazy dependency-injection container.
 *
 * Deliberately tiny (~PSR-11-shaped): every third-party DI library is a
 * scoping liability on WordPress.org, and this stays auditable. Addons can
 * override any binding through the `agy_container` filter fired at boot.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo;

defined( 'ABSPATH' ) || exit;

/**
 * Service container. Bindings are lazy: factories run on first get().
 */
final class Container {

	/**
	 * Factory callables keyed by service id.
	 *
	 * @var array<string, callable(Container): mixed>
	 */
	private array $definitions = array();

	/**
	 * Ids registered as shared (singleton) services.
	 *
	 * @var array<string, bool>
	 */
	private array $shared = array();

	/**
	 * Resolved singleton instances.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Ids currently being resolved, to detect circular dependencies.
	 *
	 * @var array<string, bool>
	 */
	private array $resolving = array();

	/**
	 * Register a shared (singleton) service.
	 *
	 * @param string                      $id      Service id (usually a FQCN).
	 * @param callable(Container): mixed $factory Factory receiving the container.
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->definitions[ $id ] = $factory;
		$this->shared[ $id ]      = true;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register a non-shared service (new instance on every get()).
	 *
	 * @param string                      $id      Service id.
	 * @param callable(Container): mixed $factory Factory receiving the container.
	 */
	public function factory( string $id, callable $factory ): void {
		$this->definitions[ $id ] = $factory;
		unset( $this->shared[ $id ], $this->instances[ $id ] );
	}

	/**
	 * Register an already-built instance as a shared service.
	 *
	 * @param string $id       Service id.
	 * @param mixed  $instance The instance.
	 */
	public function instance( string $id, mixed $instance ): void {
		$this->instances[ $id ] = $instance;
		$this->shared[ $id ]    = true;
		unset( $this->definitions[ $id ] );
	}

	/**
	 * Whether the container can resolve the given id.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->definitions[ $id ] ) || array_key_exists( $id, $this->instances );
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 * @throws \RuntimeException When the id is unknown or resolution is circular.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->definitions[ $id ] ) ) {
			throw new \RuntimeException( \sprintf( 'Agentyllo container: unknown service "%s".', $id ) );
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new \RuntimeException( \sprintf( 'Agentyllo container: circular dependency while resolving "%s".', $id ) );
		}

		$this->resolving[ $id ] = true;
		try {
			$resolved = ( $this->definitions[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		if ( isset( $this->shared[ $id ] ) ) {
			$this->instances[ $id ] = $resolved;
		}

		return $resolved;
	}
}
