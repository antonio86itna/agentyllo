<?php
/**
 * Stepwise database migrations.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the stored schema version with AGYL_DB_VERSION on admin_init and
 * upgrades when behind. dbDelta is idempotent, so re-running Schema::install()
 * is always safe; version-specific data migrations register in steps().
 */
final class Migrator {

	/**
	 * Upgrade when the stored schema version is behind. Cheap no-op otherwise.
	 */
	public function maybe_upgrade(): void {
		$installed = (int) get_option( 'agyl_db_version', 0 );

		if ( $installed >= AGYL_DB_VERSION ) {
			return;
		}

		Schema::install();

		foreach ( $this->steps() as $version => $step ) {
			if ( $installed < $version ) {
				$step();
			}
		}

		update_option( 'agyl_db_version', AGYL_DB_VERSION, false );
	}

	/**
	 * Data-migration steps keyed by the schema version that introduces them.
	 * Runs in ascending order after dbDelta.
	 *
	 * @return array<int, callable(): void>
	 */
	private function steps(): array {
		return array();
	}
}
