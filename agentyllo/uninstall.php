<?php
/**
 * Agentyllo uninstall.
 *
 * Honors the tri-state Advanced setting `uninstall_mode`:
 *   keep            – remove nothing but scheduled actions (default)
 *   remove_settings – also delete all agy_* options/transients
 *   remove_all      – also drop agy_* tables and Agentyllo's upload dirs
 *
 * Deliberately self-contained: no plugin classes are loaded here. Action
 * Scheduler may belong to another plugin (or not be loaded at all) during
 * uninstall, so its rows are removed with guarded direct SQL.
 *
 * @package Agentyllo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Table list mirrored from Agentyllo\Install\Schema::table_names().
 * Keep in sync when milestones add tables.
 */
function agy_uninstall_table_names() {
	return array(
		'agy_agent_memory',
		'agy_agent_journal',
		'agy_kb_documents',
		'agy_kb_chunks',
		'agy_kb_terms',
		'agy_kb_links',
		'agy_kb_vectors',
		'agy_sessions',
		'agy_rate_events',
		'agy_conversations',
		'agy_messages',
		'agy_consents',
		'agy_audit_log',
		'agy_stats_daily',
		'agy_stats_intents',
		'agy_stats_unanswered',
		'agy_inference_log',
		'agy_response_cache',
		'agy_chat_events',
	);
}

/**
 * Uninstall one site (current $wpdb prefix scope).
 */
function agy_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'agy_settings_advanced', array() );
	$mode     = is_array( $settings ) && isset( $settings['uninstall_mode'] ) ? $settings['uninstall_mode'] : 'keep';

	/*
	 * Always: remove our scheduled actions, their log rows, and our group
	 * registrations — if the Action Scheduler tables exist at all.
	 */
	$as_groups  = "'agentyllo','agentyllo-kb','agentyllo-ai'";
	$group_tbl  = $wpdb->prefix . 'actionscheduler_groups';
	$action_tbl = $wpdb->prefix . 'actionscheduler_actions';
	$logs_tbl   = $wpdb->prefix . 'actionscheduler_logs';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $group_tbl ) ) === $group_tbl ) {
		$group_ids = $wpdb->get_col( "SELECT group_id FROM {$group_tbl} WHERE slug IN ({$as_groups})" );
		if ( $group_ids ) {
			$gids       = implode( ',', array_map( 'absint', $group_ids ) );
			$action_ids = $wpdb->get_col( "SELECT action_id FROM {$action_tbl} WHERE group_id IN ({$gids})" );
			if ( $action_ids && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_tbl ) ) === $logs_tbl ) {
				$aids = implode( ',', array_map( 'absint', $action_ids ) );
				$wpdb->query( "DELETE FROM {$logs_tbl} WHERE action_id IN ({$aids})" );
			}
			$wpdb->query( "DELETE FROM {$action_tbl} WHERE group_id IN ({$gids})" );
			$wpdb->query( "DELETE FROM {$group_tbl} WHERE group_id IN ({$gids})" );
		}
	}

	if ( 'keep' === $mode ) {
		return;
	}

	// remove_settings and remove_all: delete every agy_* option and transient.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'agy\\_%' OR option_name LIKE '\\_transient\\_agy\\_%' OR option_name LIKE '\\_transient\\_timeout\\_agy\\_%'" );

	if ( 'remove_all' === $mode ) {
		foreach ( agy_uninstall_table_names() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}

		/*
		 * Remove ONLY core-owned upload subdirs. models/ and bin/ belong to
		 * the "Agentyllo Local AI" companion plugin and must survive a core
		 * uninstall while the companion is still installed.
		 */
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( $uploads['basedir'] ) . 'agentyllo';
		if ( is_dir( $base ) && ! is_link( $base ) ) {
			foreach ( array( 'cache', 'registry', 'private' ) as $sub ) {
				$dir = $base . '/' . $sub;
				if ( is_dir( $dir ) && ! is_link( $dir ) ) {
					agy_uninstall_rmdir( $dir );
				}
			}
			// Remove the base dir only when nothing (companion data) remains.
			$leftovers = array_diff( (array) scandir( $base ), array( '.', '..', 'index.php' ) );
			if ( empty( $leftovers ) ) {
				@unlink( $base . '/index.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
	// phpcs:enable

	/*
	 * Direct SQL bypasses the object cache: on Redis/Memcached sites the
	 * deleted options (some autoloaded in alloptions) would otherwise
	 * resurrect from cache and break reinstall seeding.
	 */
	wp_cache_flush();
}

/**
 * Recursive directory removal (no symlink traversal).
 *
 * @param string $dir Absolute directory path.
 */
function agy_uninstall_rmdir( $dir ) {
	$items = scandir( $dir );
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			agy_uninstall_rmdir( $path );
		} else {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

if ( is_multisite() ) {
	$agy_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $agy_site_ids as $agy_site_id ) {
		switch_to_blog( (int) $agy_site_id );
		agy_uninstall_site();
		restore_current_blog();
	}
	unset( $agy_site_ids, $agy_site_id );
} else {
	agy_uninstall_site();
}
