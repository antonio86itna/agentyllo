<?php
/**
 * PHPUnit bootstrap for unit tests (no WordPress).
 *
 * Defines the constants our source files expect at load time, so classes can
 * be autoloaded and their pure logic tested with Brain Monkey stubs.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

define( 'AGYL_VERSION', '0.0.0-test' );
define( 'AGYL_DB_VERSION', 1 );
define( 'AGYL_API_VERSION', 1 );
define( 'AGYL_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/../vendor/autoload.php';
