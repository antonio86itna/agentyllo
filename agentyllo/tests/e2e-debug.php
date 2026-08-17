<?php
$c = \Agentyllo\Plugin::instance()->container();
$c->get( \Agentyllo\Infra\Jobs::class )->run_health_sweep();
$sweep = $c->get( \Agentyllo\Agents\Kernel\MemoryStore::class )->recall( "sentinel", "last_sweep" );
$bad = array();
foreach ( $sweep["summary"] as $id => $s ) { if ( empty( $s["healthy"] ) ) { $bad[ $id ] = array_values( array_filter( $s["checks"], fn( $c ) => ! $c["pass"] ) ); } }
echo wp_json_encode( array( "swept" => count( $sweep["summary"] ), "unhealthy" => $bad ) ) . "\n";
