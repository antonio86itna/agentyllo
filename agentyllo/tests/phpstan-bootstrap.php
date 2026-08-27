<?php
/**
 * PHPStan bootstrap: plugin constants that agentyllo.php defines at runtime.
 *
 * @package Agentyllo
 */

if ( ! defined( 'AGYL_VERSION' ) ) {
	define( 'AGYL_VERSION', '0.0.0' );
}
if ( ! defined( 'AGYL_DB_VERSION' ) ) {
	define( 'AGYL_DB_VERSION', 8 );
}
if ( ! defined( 'AGYL_API_VERSION' ) ) {
	define( 'AGYL_API_VERSION', 1 );
}
if ( ! defined( 'AGYL_FILE' ) ) {
	define( 'AGYL_FILE', __DIR__ . '/../agentyllo.php' );
}
if ( ! defined( 'AGYL_DIR' ) ) {
	define( 'AGYL_DIR', __DIR__ . '/../' );
}
if ( ! defined( 'AGYL_URL' ) ) {
	define( 'AGYL_URL', 'https://example.test/wp-content/plugins/agentyllo/' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
