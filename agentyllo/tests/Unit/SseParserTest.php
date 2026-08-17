<?php
/**
 * SSE parser tests (pure PHP).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Infra\Http\SseParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Infra\Http\SseParser
 */
final class SseParserTest extends TestCase {

	public function test_parses_events_split_across_chunks(): void {
		$parser = new SseParser();
		$parser->feed( "event: content_block_delta\ndata: {\"delta\":{\"type\":\"text_de" );
		self::assertSame( array(), $parser->drain() );

		$parser->feed( "lta\",\"text\":\"Hi\"}}\n\nevent: message_stop\ndata: {}\n\n" );
		$events = $parser->drain();

		self::assertCount( 2, $events );
		self::assertSame( 'content_block_delta', $events[0][0] );
		self::assertSame( '{"delta":{"type":"text_delta","text":"Hi"}}', $events[0][1] );
		self::assertSame( 'message_stop', $events[1][0] );
	}

	public function test_handles_crlf_comments_and_multiline_data(): void {
		$parser = new SseParser();
		$parser->feed( ": keep-alive\r\ndata: line1\r\ndata: line2\r\n\r\n" );
		$events = $parser->drain();

		self::assertCount( 1, $events );
		self::assertSame( '', $events[0][0] );
		self::assertSame( "line1\nline2", $events[0][1] );
	}

	public function test_openai_style_data_only_events(): void {
		$parser = new SseParser();
		$parser->feed( "data: {\"type\":\"response.output_text.delta\",\"delta\":\"He\"}\n\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"llo\"}\n\n" );
		$events = $parser->drain();

		self::assertCount( 2, $events );
		$first = json_decode( $events[0][1], true );
		self::assertSame( 'response.output_text.delta', $first['type'] );
		self::assertSame( 'He', $first['delta'] );
	}
}
