<?php
/**
 * Dev-only OpenAI-compatible mock server (php -S) to exercise the BYO local
 * endpoint path: /v1/models, /v1/chat/completions (stream + blocking),
 * /v1/embeddings (deterministic char-trigram vectors, 64 dims). NOT shipped.
 *
 * Run inside the wp-env wordpress container:
 *   php -S 127.0.0.1:8123 /tmp/agy-mock-server.php
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$raw  = (string) file_get_contents( 'php://input' );
$body = json_decode( $raw, true ) ?: array();

header( 'Content-Type: application/json' );

if ( '/v1/models' === $path ) {
	echo json_encode( array( 'object' => 'list', 'data' => array( array( 'id' => 'mock-local-1', 'object' => 'model' ) ) ) );
	exit;
}

if ( '/v1/embeddings' === $path ) {
	$inputs = (array) ( $body['input'] ?? array() );
	$data   = array();
	foreach ( $inputs as $i => $text ) {
		$vec  = array_fill( 0, 64, 0.0 );
		$text = mb_strtolower( (string) $text );
		$len  = mb_strlen( $text );
		for ( $k = 0; $k + 3 <= $len; $k++ ) {
			$gram = mb_substr( $text, $k, 3 );
			$h    = crc32( $gram );
			$vec[ $h % 64 ] += 1.0 + ( ( $h >> 8 ) % 3 ) * 0.1;
		}
		$data[] = array( 'object' => 'embedding', 'index' => $i, 'embedding' => $vec );
	}
	echo json_encode( array( 'object' => 'list', 'data' => $data, 'model' => 'mock-embed', 'usage' => array( 'prompt_tokens' => 10, 'total_tokens' => 10 ) ) );
	exit;
}

if ( '/v1/chat/completions' === $path ) {
	$messages = (array) ( $body['messages'] ?? array() );
	$system   = '';
	$user     = '';
	foreach ( $messages as $m ) {
		if ( 'system' === ( $m['role'] ?? '' ) ) {
			$system .= (string) $m['content'];
		} elseif ( 'user' === ( $m['role'] ?? '' ) ) {
			$user = (string) $m['content'];
		}
	}
	if ( false !== stripos( $system, 'speed probe' ) ) {
		$reply = 'one, two, three, four, five, six, seven, eight, nine, ten, eleven, twelve, thirteen, fourteen, fifteen, sixteen, seventeen, eighteen, nineteen, twenty';
	} elseif ( false !== strpos( $user, 'Keywords:' ) ) {
		$reply = 'shipping delivery days';
	} elseif ( preg_match( '/\[#1\][^\n]*\n([^\n]+)/', $user, $m ) ) {
		$reply = 'According to the site: ' . mb_substr( trim( $m[1] ), 0, 200 ) . ' [#1]';
	} else {
		$reply = 'I could not find that in the site content. Ask me about this site!';
	}

	$stream = ! empty( $body['stream'] );
	$id     = 'chatcmpl-mock';
	$model  = (string) ( $body['model'] ?? 'mock-local-1' );
	$usage  = array( 'prompt_tokens' => 120, 'completion_tokens' => (int) ceil( strlen( $reply ) / 4 ), 'total_tokens' => 120 + (int) ceil( strlen( $reply ) / 4 ) );

	if ( ! $stream ) {
		echo json_encode(
			array(
				'id'      => $id,
				'object'  => 'chat.completion',
				'model'   => $model,
				'choices' => array( array( 'index' => 0, 'message' => array( 'role' => 'assistant', 'content' => $reply ), 'finish_reason' => 'stop' ) ),
				'usage'   => $usage,
			)
		);
		exit;
	}

	header( 'Content-Type: text/event-stream' );
	header( 'Cache-Control: no-cache' );
	$words = preg_split( '/(?<=\s)/u', $reply ) ?: array( $reply );
	foreach ( $words as $w ) {
		echo 'data: ' . json_encode( array( 'id' => $id, 'object' => 'chat.completion.chunk', 'model' => $model, 'choices' => array( array( 'index' => 0, 'delta' => array( 'content' => $w ), 'finish_reason' => null ) ) ) ) . "\n\n";
		flush();
		usleep( 20000 );
	}
	echo 'data: ' . json_encode( array( 'id' => $id, 'object' => 'chat.completion.chunk', 'model' => $model, 'choices' => array( array( 'index' => 0, 'delta' => array(), 'finish_reason' => 'stop' ) ), 'usage' => $usage ) ) . "\n\n";
	echo "data: [DONE]\n\n";
	exit;
}

http_response_code( 404 );
echo json_encode( array( 'error' => array( 'message' => 'not found' ) ) );
