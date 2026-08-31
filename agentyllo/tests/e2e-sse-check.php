<?php
/**
 * E2E check for StreamingClient over the WP HTTP API (http_api_curl hook).
 * Run: wp eval-file tests/e2e-sse-check.php — expects the SSE test server
 * from e2e-sse-server.php on 127.0.0.1:8123 in the same container.
 *
 * @package Agentyllo
 */

$client = Agentyllo\Plugin::instance()->container()->get( Agentyllo\Infra\Http\StreamingClient::class );

$events = array();
$start  = microtime( true );

$result = $client->post_json(
	'http://127.0.0.1:8123/e2e-sse-server.php',
	array(),
	array( 'probe' => 1 ),
	15.0,
	function ( string $event, string $data ) use ( &$events, $start ): bool {
		$events[] = array( $event, $data, round( microtime( true ) - $start, 2 ) );
		return true;
	}
);

echo 'supports_streaming: ' . ( $client->supports_streaming() ? 'yes' : 'no' ) . "\n";
echo 'status=' . $result['status'] . ' error=' . wp_json_encode( $result['error'] ) . ' aborted=' . (int) $result['aborted'] . "\n";
echo 'events: ' . count( $events ) . "\n";
foreach ( $events as [ $ev, $data, $t ] ) {
	echo "  {$t}s  {$ev}  {$data}\n";
}
$first = $events[0][2] ?? null;
$last  = end( $events )[2] ?? null;
$span  = null !== $first && null !== $last ? $last - $first : 0;
echo 'first->last span: ' . round( $span, 2 ) . "s => " . ( $span >= 1.0 ? 'REAL STREAMING' : 'BUFFERED' ) . "\n";
