<?php
/**
 * Tiny SSE test endpoint for the StreamingClient e2e check (php -S target).
 * Emits 4 delta events 400ms apart, then done — padded past the 4KB
 * built-in server buffer so each event flushes immediately.
 *
 * @package Agentyllo
 */

// phpcs:ignoreFile -- dev-only test fixture, never shipped nor loaded by WP.
header( 'Content-Type: text/event-stream' );
header( 'Cache-Control: no-store' );
$pad = ': ' . str_repeat( 'x', 4096 ) . "\n";
for ( $i = 1; $i <= 4; $i++ ) {
	echo "event: delta\ndata: chunk{$i}\n\n" . $pad;
	if ( function_exists( 'ob_flush' ) ) {
		@ob_flush();
	}
	flush();
	usleep( 400000 );
}
echo "event: done\ndata: {}\n\n" . $pad;
