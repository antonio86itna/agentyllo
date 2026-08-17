<?php
/**
 * Protected upload directories for Agentyllo runtime files.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and guards `uploads/agentyllo/{cache,registry,private}`.
 * (`models` and `bin` are managed by the Local AI companion plugin.)
 */
final class Uploads {

	private const SUBDIRS = array( 'cache', 'registry', 'private' );

	/**
	 * Absolute path of the Agentyllo uploads base dir (no trailing slash).
	 */
	public static function base_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return trailingslashit( $uploads['basedir'] ) . 'agentyllo';
	}

	/**
	 * Absolute path of a subdirectory (cache|registry|private).
	 *
	 * @param string $name Subdirectory name.
	 */
	public static function dir( string $name ): string {
		return self::base_dir() . '/' . $name;
	}

	/**
	 * Ensure the directory tree exists with deny-listing guards.
	 * Returns true when every directory is present and writable.
	 */
	public static function ensure(): bool {
		$ok   = true;
		$base = self::base_dir();
		$dirs = array_merge( array( $base ), array_map( array( self::class, 'dir' ), self::SUBDIRS ) );

		foreach ( $dirs as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				$ok = false;
				continue;
			}
			// Block directory listing everywhere; block direct access to private files.
			if ( ! file_exists( $dir . '/index.php' ) ) {
				@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$private_htaccess = self::dir( 'private' ) . '/.htaccess';
		if ( ! file_exists( $private_htaccess ) ) {
			// Apache 2.4 + 2.2 syntax; Nginx users are covered by the random-filename + short-TTL policy.
			@file_put_contents( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$private_htaccess,
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}

		return $ok && wp_is_writable( $base );
	}
}
