<?php
/**
 * Task value-object tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Agents\Contracts\Task;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Agents\Contracts\Task
 */
final class TaskTest extends TestCase {

	public function test_create_generates_valid_uuid4(): void {
		$task = Task::create( 'index.post', array( 'post_id' => 5 ) );

		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$task->ref
		);
	}

	public function test_serialization_roundtrip(): void {
		$task  = Task::create( 'verify.retrieval', array( 'chunk_id' => 42 ), 80, 'retriever' );
		$again = Task::from_array( $task->to_array() );

		self::assertSame( $task->ref, $again->ref );
		self::assertSame( $task->type, $again->type );
		self::assertSame( $task->payload, $again->payload );
		self::assertSame( $task->priority, $again->priority );
		self::assertSame( $task->requested_by, $again->requested_by );
		self::assertSame( $task->attempt, $again->attempt );
	}

	public function test_retry_increments_attempt_and_keeps_ref(): void {
		$task  = Task::create( 'index.post' );
		$retry = $task->retry();

		self::assertSame( $task->ref, $retry->ref );
		self::assertSame( 2, $retry->attempt );
	}

	public function test_priority_is_clamped(): void {
		self::assertSame( 100, Task::create( 'x', array(), 999 )->priority );
		self::assertSame( 0, Task::create( 'x', array(), -5 )->priority );
	}

	public function test_from_array_tolerates_garbage(): void {
		$task = Task::from_array( array( 'payload' => 'not-an-array', 'attempt' => 0 ) );

		self::assertSame( 'unknown', $task->type );
		self::assertSame( array(), $task->payload );
		self::assertSame( 1, $task->attempt );
	}
}
