<?php
/**
 * Minimal PSR-4 autoloader for the Agentyllo\ namespace plus the (optional)
 * scoped third-party dependencies. The plugin's own classes never rely on
 * Composer at runtime.
 *
 * @package Agentyllo
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'Agentyllo\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'Agentyllo\\' ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $path ) ) {
			require $path;
		}
	}
);

// Third-party dependencies: scoped build output first, dev vendor as fallback.
foreach ( array( dirname( __DIR__ ) . '/vendor-prefixed/autoload.php', dirname( __DIR__ ) . '/vendor/autoload.php' ) as $agy_autoload ) {
	if ( is_file( $agy_autoload ) ) {
		require_once $agy_autoload;
		break;
	}
}
unset( $agy_autoload );
