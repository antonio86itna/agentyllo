<?php
/**
 * Dev-only M3 verification for `wp eval-file` (not shipped).
 *
 * @package Agentyllo
 */

global $wpdb;
$c = \Agentyllo\Plugin::instance()->container();
$p = $wpdb->prefix;

$counts = array(
	'documents' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_documents" ),
	'active'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_documents WHERE status='active'" ),
	'chunks'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_chunks" ),
	'terms'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_terms" ),
	'links'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_links" ),
	'by_source' => $wpdb->get_results( "SELECT source, status, COUNT(*) n FROM {$p}agy_kb_documents GROUP BY source, status", ARRAY_A ),
);

$retriever = $c->get( \Agentyllo\KB\Retrieval\HybridRetriever::class );
$hits      = $retriever->search( 'how long does shipping to Europe take?', array( 'limit' => 3 ) );

$search = array();
foreach ( $hits as $hit ) {
	$search[] = array(
		'title' => $hit['title'],
		'score' => round( (float) $hit['score'], 3 ),
		'kind'  => $hit['kind'],
	);
}

echo wp_json_encode( array( 'counts' => $counts, 'search_top' => $search ) ) . "\n";
