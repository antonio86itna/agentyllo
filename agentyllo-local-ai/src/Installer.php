<?php
/**
 * Registry-driven engine/model installer (consent + checksum).
 *
 * @package AgentylloLocalAI
 */

declare( strict_types=1 );

namespace AgentylloLocalAI;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads llama.cpp engine builds and GGUF models ONLY from entries of the
 * Ed25519-signed Agentyllo registry (`engines` / `local_models`), verifies
 * the SHA-256 published there, and stores them under
 * uploads/agentyllo/{bin,models}. Nothing is fetched without the owner
 * ticking the license/consent box; gated (non-open) weights are never
 * offered — the registry lists Apache-2.0/MIT models only. Until the remote
 * registry publishes entries, the page offers manual paths.
 */
final class Installer {

	/**
	 * Registry entries for this platform.
	 *
	 * @return array{engines: array<int, array<string, mixed>>, models: array<int, array<string, mixed>>, platform: string}
	 */
	public function catalog(): array {
		$manifest = \Agentyllo\Plugin::instance()?->container()->get( \Agentyllo\Registry\Manifest::class )->data() ?? array();
		$platform = $this->platform();

		$engines = array();
		foreach ( (array) ( $manifest['engines'] ?? array() ) as $engine ) {
			if ( is_array( $engine ) && ( (string) ( $engine['platform'] ?? '' ) === $platform ) && ! empty( $engine['url'] ) && ! empty( $engine['sha256'] ) ) {
				$engines[] = $engine;
			}
		}
		$models = array();
		foreach ( (array) ( $manifest['local_models'] ?? array() ) as $model ) {
			if ( is_array( $model ) && ! empty( $model['url'] ) && ! empty( $model['sha256'] ) && empty( $model['gated'] ) ) {
				$models[] = $model;
			}
		}

		return array(
			'engines'  => $engines,
			'models'   => $models,
			'platform' => $platform,
		);
	}

	/**
	 * Download + verify + install one catalog entry. Returns [ok, message, path].
	 *
	 * @param string $kind 'engine' | 'model'.
	 * @param string $id   Entry id.
	 * @return array{0: bool, 1: string, 2: string}
	 */
	public function install( string $kind, string $id ): array {
		$catalog = $this->catalog();
		$list    = 'engine' === $kind ? $catalog['engines'] : $catalog['models'];
		$entry   = null;
		foreach ( $list as $candidate ) {
			if ( (string) ( $candidate['id'] ?? '' ) === $id ) {
				$entry = $candidate;
				break;
			}
		}
		if ( null === $entry ) {
			return array( false, __( 'Unknown catalog entry.', 'agentyllo-local-ai' ), '' );
		}

		$url  = (string) $entry['url'];
		$hash = strtolower( (string) $entry['sha256'] );
		if ( ! preg_match( '#^https://#i', $url ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return array( false, __( 'Catalog entry is malformed (URL must be https and SHA-256 hex).', 'agentyllo-local-ai' ), '' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 1800 );
		if ( is_wp_error( $tmp ) ) {
			return array( false, $tmp->get_error_message(), '' );
		}
		$actual = hash_file( 'sha256', $tmp );
		if ( ! hash_equals( $hash, (string) $actual ) ) {
			wp_delete_file( $tmp );

			return array( false, __( 'Checksum mismatch — download rejected.', 'agentyllo-local-ai' ), '' );
		}

		$dir = \Agentyllo\Infra\Uploads::dir( 'engine' === $kind ? 'bin' : 'models' );
		if ( ! wp_mkdir_p( $dir ) ) {
			wp_delete_file( $tmp );

			return array( false, __( 'Cannot create the target directory.', 'agentyllo-local-ai' ), '' );
		}
		$name = sanitize_file_name( (string) ( $entry['file'] ?? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$dest = rtrim( $dir, '/\\' ) . '/' . $name;

		// Engines arrive as tar.gz/zip archives or single binaries.
		if ( 'engine' === $kind && preg_match( '/\.(zip|tar\.gz|tgz)$/i', $name ) ) {
			$extracted = $this->extract( $tmp, $dir, $name );
			wp_delete_file( $tmp );
			if ( '' === $extracted ) {
				return array( false, __( 'Could not extract the engine archive.', 'agentyllo-local-ai' ), '' );
			}
			$dest = $extracted;
		} elseif ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $tmp );

			return array( false, __( 'Could not move the download into place.', 'agentyllo-local-ai' ), '' );
		}
		if ( 'engine' === $kind ) {
			@chmod( $dest, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		$installed         = get_option( 'agyl_installed', array() );
		$installed         = is_array( $installed ) ? $installed : array();
		$installed[ $id ]  = array(
			'kind'    => $kind,
			'path'    => $dest,
			'sha256'  => $hash,
			'version' => (string) ( $entry['version'] ?? '' ),
			'at'      => time(),
		);
		update_option( 'agyl_installed', $installed, false );

		return array( true, sprintf( /* translators: %s: path */ __( 'Installed to %s', 'agentyllo-local-ai' ), $dest ), $dest );
	}

	/**
	 * Installed entries.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function installed(): array {
		$installed = get_option( 'agyl_installed', array() );

		return is_array( $installed ) ? $installed : array();
	}

	/**
	 * Platform key matching registry entries.
	 */
	public function platform(): string {
		$os   = strtolower( PHP_OS_FAMILY );
		$arch = strtolower( php_uname( 'm' ) );
		$arch = in_array( $arch, array( 'x86_64', 'amd64' ), true ) ? 'x64' : ( in_array( $arch, array( 'aarch64', 'arm64' ), true ) ? 'arm64' : $arch );

		return ( 'darwin' === $os ? 'darwin' : ( 'windows' === $os ? 'windows' : 'linux' ) ) . '-' . $arch;
	}

	/**
	 * Extract an archive; returns the llama-server binary path or ''.
	 *
	 * @param string $archive Archive path.
	 * @param string $dir     Target dir.
	 * @param string $name    Archive name.
	 */
	private function extract( string $archive, string $dir, string $name ): string {
		$target = rtrim( $dir, '/\\' ) . '/' . preg_replace( '/\.(zip|tar\.gz|tgz)$/i', '', $name );
		wp_mkdir_p( $target );
		if ( preg_match( '/\.zip$/i', $name ) ) {
			WP_Filesystem();
			$result = unzip_file( $archive, $target );
			if ( is_wp_error( $result ) ) {
				return '';
			}
		} else {
			try {
				$phar = new \PharData( $archive );
				$phar->extractTo( $target, null, true );
			} catch ( \Throwable $e ) {
				return '';
			}
		}
		$found = '';
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $target, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( in_array( $file->getFilename(), array( 'llama-server', 'llama-server.exe' ), true ) ) {
				$found = $file->getPathname();
				break;
			}
		}
		if ( '' !== $found ) {
			@chmod( $found, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		return $found;
	}
}
