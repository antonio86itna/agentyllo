<?php
/**
 * AgentResult tests.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\Agents\Contracts\AgentResult;
use Agentyllo\Agents\Contracts\HealthReport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\Agents\Contracts\AgentResult
 * @covers \Agentyllo\Agents\Contracts\HealthReport
 */
final class AgentResultTest extends TestCase {

	public function test_confident_ok_needs_no_verification(): void {
		self::assertFalse( AgentResult::ok( array(), 0.9 )->requires_verification() );
	}

	public function test_low_confidence_ok_triggers_verification(): void {
		self::assertTrue( AgentResult::ok( array(), 0.4 )->requires_verification() );
	}

	public function test_explicit_flag_triggers_verification(): void {
		$result = new AgentResult( AgentResult::STATUS_OK, array(), 0.95, array(), true );

		self::assertTrue( $result->requires_verification() );
	}

	public function test_failed_carries_reason(): void {
		$result = AgentResult::failed( 'timeout', array( 'elapsed_ms' => 31000 ) );

		self::assertSame( AgentResult::STATUS_FAILED, $result->status );
		self::assertSame( 'timeout', $result->payload['reason'] );
		self::assertSame( 31000, $result->payload['elapsed_ms'] );
		self::assertFalse( $result->requires_verification() );
	}

	public function test_health_report_flags(): void {
		$report = ( new HealthReport() )
			->add( 'a', true, '', true )
			->add( 'b', false, 'broken', true )
			->add( 'c', false, 'minor', false );

		self::assertFalse( $report->healthy() );
		self::assertSame( array( 'b' ), $report->critical_failures() );
		self::assertCount( 3, $report->checks() );

		$clean = ( new HealthReport() )->add( 'a', true );
		self::assertTrue( $clean->healthy() );
		self::assertSame( array(), $clean->critical_failures() );
	}
}
