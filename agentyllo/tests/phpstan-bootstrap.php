<?php
/**
 * PHPStan bootstrap: plugin constants that agentyllo.php defines at runtime.
 *
 * @package Agentyllo
 */

if ( ! defined( 'AGY_VERSION' ) ) {
	define( 'AGY_VERSION', '0.0.0' );
}
if ( ! defined( 'AGY_DB_VERSION' ) ) {
	define( 'AGY_DB_VERSION', 8 );
}
if ( ! defined( 'AGY_API_VERSION' ) ) {
	define( 'AGY_API_VERSION', 1 );
}
if ( ! defined( 'AGY_FILE' ) ) {
	define( 'AGY_FILE', __DIR__ . '/../agentyllo.php' );
}
if ( ! defined( 'AGY_DIR' ) ) {
	define( 'AGY_DIR', __DIR__ . '/../' );
}
if ( ! defined( 'AGY_URL' ) ) {
	define( 'AGY_URL', 'https://example.test/wp-content/plugins/agentyllo/' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
