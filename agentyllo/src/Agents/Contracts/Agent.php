<?php
/**
 * The agent contract — everything in the roster (and every addon agent)
 * implements this.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Agents are stateless services: durable state lives in the memory store,
 * outcomes in the journal. Registered through the `agyl_register_agents`
 * filter and orchestrated by the kernel.
 */
interface Agent {

	/**
	 * Stable id, e.g. 'kb_curator'. Also the memory/journal scope.
	 */
	public function id(): string;

	/**
	 * Semver of the agent implementation (independent of plugin version).
	 */
	public function version(): string;

	/**
	 * Capability tags, e.g. ['index', 'purge']. Used by byCapability() routing.
	 *
	 * @return string[]
	 */
	public function capabilities(): array;

	/**
	 * Events this agent reacts to, mapped to listener priority.
	 * E.g. ['kb.delta' => 10].
	 *
	 * @return array<string, int>
	 */
	public function subscribed_events(): array;

	/**
	 * Handle a task within the context budget. Must never throw for expected
	 * failures — return AgentResult::failed() so the journal can fingerprint it.
	 *
	 * @param Task         $task    The task.
	 * @param AgentContext $context Execution context.
	 */
	public function handle( Task $task, AgentContext $context ): AgentResult;

	/**
	 * Self-check for the sentinel's daily sweep.
	 *
	 * @param AgentContext $context Execution context.
	 */
	public function self_check( AgentContext $context ): HealthReport;
}
