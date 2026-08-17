<?php
/**
 * Execution context handed to every agent.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Contracts;

use Agentyllo\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Scoped view of the kernel for one agent handling one task.
 */
interface AgentContext {

	/**
	 * Memory store (already scoped helpers use the agent id from this context).
	 */
	public function memory(): MemoryStoreInterface;

	/**
	 * Journal writer.
	 */
	public function journal(): JournalInterface;

	/**
	 * In-process event bus.
	 */
	public function bus(): EventBusInterface;

	/**
	 * Lessons matching the current task signature, ordered by importance.
	 * Each: {match: array, action: 'skip'|'fallback'|'param_override', note: string}.
	 *
	 * @return array<int, array>
	 */
	public function lessons(): array;

	/**
	 * Milliseconds this agent may still spend before it must return partial.
	 */
	public function remaining_ms(): int;

	/**
	 * Service container (read access for collaborating services).
	 */
	public function services(): Container;

	/**
	 * Site profile written by the site_profiler agent
	 * (type, stack flags, locale, confidence). Empty before first profiling.
	 */
	public function site_profile(): array;
}
