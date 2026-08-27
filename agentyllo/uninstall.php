<?php
/**
 * Agentyllo uninstall.
 *
 * Honors the tri-state Advanced setting `uninstall_mode`:
 *   keep            – remove nothing but scheduled actions (default)
 *   remove_settings – also delete all agyl_* options/transients
 *   remove_all      – also drop agyl_* tables and Agentyllo's upload dirs
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
function agyl_uninstall_table_names(): array {
	return array(
		'agyl_agent_memory',
		'agyl_agent_journal',
		'agyl_kb_documents',
		'agyl_kb_chunks',
		'agyl_kb_terms',
		'agyl_kb_links',
		'agyl_kb_vectors',
		'agyl_sessions',
		'agyl_rate_events',
		'agyl_conversations',
		'agyl_messages',
		'agyl_consents',
		'agyl_audit_log',
		'agyl_stats_daily',
		'agyl_stats_intents',
		'agyl_stats_unanswered',
		'agyl_inference_log',
		'agyl_response_cache',
		'agyl_chat_events',
	);
}

/**
 * Uninstall one site (current $wpdb prefix scope).
 */
function agyl_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'agyl_settings_advanced', array() );
	$mode     = is_array( $settings ) && isset( $settings['uninstall_mode'] ) ? $settings['uninstall_mode'] : 'keep';

	/*
	 * Always: remove our scheduled actions, their log rows, and our group
	 * registrations — if the Action Scheduler tables exist at all.
	 */
	$as_groups  = "'agentyllo','agentyllo-kb','agentyllo-ai'";
	$group_tbl  = $wpdb->prefix . 'actionscheduler_groups';
	$action_tbl = $wpdb->prefix . 'actionscheduler_actions';
	$logs_tbl   = $wpdb->prefix . 'actionscheduler_logs';
	// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB
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

	// remove_settings and remove_all: delete every agyl_* option and transient.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'agy\\_%' OR option_name LIKE '\\_transient\\_agy\\_%' OR option_name LIKE '\\_transient\\_timeout\\_agy\\_%'" );

	if ( 'remove_all' === $mode ) {
		foreach ( agyl_uninstall_table_names() as $table ) {
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
					agyl_uninstall_rmdir( $dir );
				}
			}
			// Remove the base dir only when nothing (companion data) remains.
			$leftovers = array_diff( (array) scandir( $base ), array( '.', '..', 'index.php' ) );
			if ( empty( $leftovers ) ) {
				agyl_uninstall_rmdir( $base );
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
 * Recursive directory removal via WP_Filesystem (no symlink traversal: the
 * direct method never follows links). When WP_Filesystem cannot initialize
 * without credentials (FTP-mode hosts) the files are simply left in place.
 *
 * @param string $dir Absolute directory path.
 */
function agyl_uninstall_rmdir( $dir ): void {
	global $wp_filesystem;

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! $wp_filesystem instanceof WP_Filesystem_Base && ! WP_Filesystem() ) {
		return;
	}
	if ( $wp_filesystem instanceof WP_Filesystem_Base && ! is_link( $dir ) ) {
		$wp_filesystem->delete( $dir, true );
	}
}

if ( is_multisite() ) {
	$agyl_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $agyl_site_ids as $agyl_site_id ) {
		switch_to_blog( (int) $agyl_site_id );
		agyl_uninstall_site();
		restore_current_blog();
	}
	unset( $agyl_site_ids, $agyl_site_id );
} else {
	agyl_uninstall_site();
}
