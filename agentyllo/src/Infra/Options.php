<?php
/**
 * Thin, prefixed wrapper over the WordPress options API.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra;

defined( 'ABSPATH' ) || exit;

/**
 * All Agentyllo options live under the `agy_` prefix. JSON-ish array values
 * are stored as native arrays (WordPress serializes them).
 */
final class Options {

	private const PREFIX = 'agy_';

	/**
	 * Read an option.
	 *
	 * @param string $key           Unprefixed key.
	 * @param mixed  $default_value Returned when unset.
	 * @return mixed
	 */
	public function get( string $key, mixed $default_value = null ): mixed {
		return get_option( self::PREFIX . $key, $default_value );
	}

	/**
	 * Write an option.
	 *
	 * @param string $key      Unprefixed key.
	 * @param mixed  $value    Value to store.
	 * @param bool   $autoload Whether WordPress should autoload it. Default false:
	 *                         almost none of our options belong on every request.
	 */
	public function set( string $key, mixed $value, bool $autoload = false ): void {
		$full = self::PREFIX . $key;
		if ( false === get_option( $full, false ) ) {
			add_option( $full, $value, '', $autoload );
			return;
		}
		update_option( $full, $value, $autoload );
	}

	/**
	 * Delete an option.
	 *
	 * @param string $key Unprefixed key.
	 */
	public function delete( string $key ): void {
		delete_option( self::PREFIX . $key );
	}
}
