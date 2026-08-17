<?php
/**
 * Agent self-check report.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Produced by Agent::selfCheck(); consumed by the sentinel. A critical check
 * failing three consecutive daily sweeps quarantines the agent.
 */
final class HealthReport {

	/**
	 * Checks: list of {name, pass, detail, critical}.
	 *
	 * @var array<int, array{name: string, pass: bool, detail: string, critical: bool}>
	 */
	private array $checks = array();

	/**
	 * Record a check result.
	 *
	 * @param string $name     Check id (stable across runs).
	 * @param bool   $pass     Whether it passed.
	 * @param string $detail   Human-readable detail for the dashboard.
	 * @param bool   $critical Critical checks can quarantine the agent.
	 */
	public function add( string $name, bool $pass, string $detail = '', bool $critical = false ): self {
		$this->checks[] = array(
			'name'     => $name,
			'pass'     => $pass,
			'detail'   => $detail,
			'critical' => $critical,
		);

		return $this;
	}

	/**
	 * All checks.
	 *
	 * @return array<int, array{name: string, pass: bool, detail: string, critical: bool}>
	 */
	public function checks(): array {
		return $this->checks;
	}

	/**
	 * Whether every check passed.
	 */
	public function healthy(): bool {
		foreach ( $this->checks as $check ) {
			if ( ! $check['pass'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Names of failing critical checks.
	 *
	 * @return string[]
	 */
	public function critical_failures(): array {
		$failed = array();
		foreach ( $this->checks as $check ) {
			if ( $check['critical'] && ! $check['pass'] ) {
				$failed[] = $check['name'];
			}
		}

		return $failed;
	}
}
