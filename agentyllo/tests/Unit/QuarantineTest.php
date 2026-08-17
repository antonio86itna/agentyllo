<?php
/**
 * Quarantine streak-protocol tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Agents\Contracts\EventBusInterface;
use Agentyllo\Agents\Contracts\HealthReport;
use Agentyllo\Agents\Contracts\JournalInterface;
use Agentyllo\Agents\Contracts\Task;
use Agentyllo\Agents\Kernel\Quarantine;
use Agentyllo\Agents\Kernel\Registry;
use Agentyllo\Infra\Options;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Agents\Kernel\Quarantine
 * @covers \Agentyllo\Agents\Kernel\Registry
 */
final class QuarantineTest extends TestCase {

	/**
	 * In-memory option storage backing the stubbed options API.
	 *
	 * @var array<string, mixed>
	 */
	private array $option_store = array();

	private Registry $registry;
	private Quarantine $quarantine;

	/**
	 * Events emitted through the fake bus.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $events = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$store = &$this->option_store;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $default_value = false ) use ( &$store ): mixed {
				return $store[ $key ] ?? $default_value;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( string $key, mixed $value = '' ) use ( &$store ): bool {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ) use ( &$store ): bool {
				$store[ $key ] = $value;
				return true;
			}
		);

		$events  = &$this->events;
		$journal = new class() implements JournalInterface {
			public function log( string $agent_id, string $level, string $event, string $message = '', array $context = array(), ?string $task_ref = null ): void {}
			public function error( string $agent_id, \Throwable $e, ?Task $task = null, array $context = array() ): void {}
			public function recurring_errors( int $min = 3, int $days = 30 ): array {
				return array();
			}
			public function rotate( int $days_default = 30, int $days_errors = 90 ): int {
				return 0;
			}
		};
		$bus = new class( $events ) implements EventBusInterface {
			public function __construct( private array &$events ) {}
			public function on( string $event, callable $listener, int $priority = 10 ): void {}
			public function emit( string $event, array $payload = array() ): void {
				$this->events[] = array( $event, $payload );
			}
		};

		$this->registry   = new Registry( new Options() );
		$this->quarantine = new Quarantine( $this->registry, $journal, $bus );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function failing_report(): HealthReport {
		return ( new HealthReport() )->add( 'db_ok', false, 'gone', true );
	}

	private function passing_report(): HealthReport {
		return ( new HealthReport() )->add( 'db_ok', true, '', true );
	}

	public function test_two_failures_do_not_quarantine(): void {
		$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );
		$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );

		self::assertNull( $this->registry->config( 'kb_curator' )['quarantine'] );
	}

	public function test_third_consecutive_failure_quarantines(): void {
		$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );
		$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );
		$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );

		$config = $this->registry->config( 'kb_curator' );
		self::assertNotNull( $config['quarantine'] );
		self::assertStringContainsString( 'db_ok', $config['quarantine']['reason'] );
		self::assertSame( 'agent.quarantined', $this->events[0][0] ?? null );
	}

	public function test_passing_sweep_resets_streak(): void {
		$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );
		$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );
		$this->quarantine->record_sweep( 'kb_curator', $this->passing_report() );
		$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );
		$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );

		self::assertNull( $this->registry->config( 'kb_curator' )['quarantine'] );
	}

	public function test_release_clears_quarantine_and_streaks(): void {
		foreach ( range( 1, 3 ) as $i ) {
			$this->quarantine->record_sweep( 'kb_curator', $this->failing_report() );
		}
		self::assertNotNull( $this->registry->config( 'kb_curator' )['quarantine'] );

		$this->quarantine->release( 'kb_curator' );

		$config = $this->registry->config( 'kb_curator' );
		self::assertNull( $config['quarantine'] );
		self::assertSame( array(), $config['streaks'] );
	}
}
