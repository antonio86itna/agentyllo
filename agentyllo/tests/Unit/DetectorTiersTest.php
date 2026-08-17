<?php
/**
 * Capability tier computation tests (pure function, no WordPress).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Tests\Unit;

use Agentyllo\AI\Capability\Detector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Agentyllo\AI\Capability\Detector::compute_tiers
 */
final class DetectorTiersTest extends TestCase {

	private const MB = 1048576;
	private const GB = 1073741824;

	public function test_bare_shared_host_gets_classic_floor_only(): void {
		$tiers = Detector::compute_tiers(
			array(
				'memory_limit_bytes' => 128 * self::MB,
				'max_execution_time' => 30,
				'ffi_enabled'        => false,
				'proc_open_works'    => false,
				'cpu_cores'          => 1,
				'cpu_score'          => 20,
				'disk_free_bytes'    => 500 * self::MB,
				'curl'               => true,
			)
		);

		self::assertTrue( $tiers['t0'] );
		self::assertTrue( $tiers['t1a'] );
		self::assertFalse( $tiers['t1b'] );
		self::assertFalse( $tiers['t2'] );
		self::assertFalse( $tiers['t3'] );
		self::assertTrue( $tiers['t4'] );
		self::assertTrue( $tiers['t5'] );
		self::assertSame( 't1a', $tiers['best_free_tier'] );
	}

	public function test_ffi_host_unlocks_embeddings_but_not_generation(): void {
		$tiers = Detector::compute_tiers(
			array(
				'memory_limit_bytes' => 256 * self::MB,
				'max_execution_time' => 30,
				'ffi_enabled'        => true,
				'proc_open_works'    => false,
				'cpu_cores'          => 2,
				'cpu_score'          => 30,
				'disk_free_bytes'    => 5 * self::GB,
				'curl'               => true,
			)
		);

		self::assertTrue( $tiers['t1b'] );
		self::assertFalse( $tiers['t2'], 'T2 needs 512MB + cpu_score>=40' );
		self::assertSame( 't1b', $tiers['best_free_tier'] );
	}

	public function test_vps_class_host_unlocks_llamacpp(): void {
		$tiers = Detector::compute_tiers(
			array(
				'memory_limit_bytes'   => 1024 * self::MB,
				'max_execution_time'   => 0,
				'exec_time_extendable' => true,
				'ffi_enabled'          => true,
				'proc_open_works'      => true,
				'cpu_cores'            => 4,
				'cpu_score'            => 70,
				'disk_free_bytes'      => 20 * self::GB,
				'curl'                 => true,
			)
		);

		self::assertTrue( $tiers['t1b'] );
		self::assertTrue( $tiers['t2'] );
		self::assertTrue( $tiers['t3'] );
		self::assertSame( 't3', $tiers['best_free_tier'] );
		self::assertNull( $tiers['t3s'], 'daemon capability is decided by the M8 cross-request probe' );
	}

	public function test_unknown_disk_space_does_not_block_tiers(): void {
		$tiers = Detector::compute_tiers(
			array(
				'memory_limit_bytes' => 512 * self::MB,
				'max_execution_time' => 120,
				'ffi_enabled'        => true,
				'proc_open_works'    => true,
				'cpu_cores'          => 2,
				'cpu_score'          => 50,
				'disk_free_bytes'    => null,
				'curl'               => false,
			)
		);

		self::assertTrue( $tiers['t1b'], 'null disk (blocked probe) must not disable tiers' );
		self::assertTrue( $tiers['t3'] );
		self::assertFalse( $tiers['t5'], 'no curl, no cloud' );
	}

	public function test_short_timeout_without_extension_blocks_t2(): void {
		$tiers = Detector::compute_tiers(
			array(
				'memory_limit_bytes'   => 1024 * self::MB,
				'max_execution_time'   => 30,
				'exec_time_extendable' => false,
				'ffi_enabled'          => true,
				'proc_open_works'      => false,
				'cpu_cores'            => 4,
				'cpu_score'            => 80,
				'disk_free_bytes'      => 10 * self::GB,
				'curl'                 => true,
			)
		);

		self::assertFalse( $tiers['t2'] );
		self::assertSame( 't1b', $tiers['best_free_tier'] );
	}
}
