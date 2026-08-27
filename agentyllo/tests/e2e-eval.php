<?php
/**
 * Dev-only smoke script for `wp eval-file` inside wp-env.
 * Not shipped: excluded from the distribution package at build time.
 *
 * @package Agentyllo
 */

$c = \Agentyllo\Plugin::instance()->container();
$c->get( \Agentyllo\Install\Migrator::class )->maybe_upgrade();

$jobs = $c->get( \Agentyllo\Infra\Jobs::class );
$jobs->run_health_sweep();
$jobs->run_maintenance();
$jobs->run_learn();

$mem   = $c->get( \Agentyllo\Agents\Kernel\MemoryStore::class );
$sweep = $mem->recall( 'sentinel', 'last_sweep' );

global $wpdb;
$col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}agyl_agent_memory LIKE 'mem_key'", ARRAY_A );

$healthy = true;
foreach ( ( $sweep['summary'] ?? array() ) as $agent_summary ) {
	$healthy = $healthy && ! empty( $agent_summary['healthy'] );
}

echo wp_json_encode(
	array(
		'db_version'   => (int) get_option( 'agyl_db_version' ),
		'mem_key_type' => $col['Type'] ?? null,
		'sweep_agents' => array_keys( $sweep['summary'] ?? array() ),
		'all_healthy'  => $healthy,
		'journal_rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agyl_agent_journal" ),
		'schema_error' => get_option( 'agyl_schema_error', null ),
	)
) . "\n";
