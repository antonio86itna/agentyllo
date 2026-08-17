<?php
/**
 * Journal fingerprint normalization tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Agents\Contracts\Task;
use Agentyllo\Agents\Kernel\Journal;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Agentyllo\Agents\Kernel\Journal::fingerprint
 */
final class JournalFingerprintTest extends TestCase {

	public function test_volatile_parts_do_not_change_the_fingerprint(): void {
		$task = Task::create( 'index.post' );

		$a = Journal::fingerprint( 'kb_curator', new RuntimeException( 'Failed to parse post 123 at /var/www/site/wp-content/file1.php' ), $task );
		$b = Journal::fingerprint( 'kb_curator', new RuntimeException( 'Failed to parse post 456 at /home/other/wp-content/file2.php' ), $task );

		self::assertSame( $a, $b );
	}

	public function test_different_exception_class_changes_fingerprint(): void {
		$task = Task::create( 'index.post' );

		$a = Journal::fingerprint( 'kb_curator', new RuntimeException( 'boom' ), $task );
		$b = Journal::fingerprint( 'kb_curator', new \LogicException( 'boom' ), $task );

		self::assertNotSame( $a, $b );
	}

	public function test_different_agent_or_task_type_changes_fingerprint(): void {
		$e = new RuntimeException( 'boom' );

		$a = Journal::fingerprint( 'kb_curator', $e, Task::create( 'index.post' ) );
		$b = Journal::fingerprint( 'woo_extractor', $e, Task::create( 'index.post' ) );
		$c = Journal::fingerprint( 'kb_curator', $e, Task::create( 'index.product' ) );
		$d = Journal::fingerprint( 'kb_curator', $e, null );

		self::assertNotSame( $a, $b );
		self::assertNotSame( $a, $c );
		self::assertNotSame( $a, $d );
	}
}
