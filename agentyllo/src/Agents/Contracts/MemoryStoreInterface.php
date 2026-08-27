<?php
/**
 * Agent memory contract.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Backed by wp_agyl_agent_memory. Kinds: fact | state | task | lesson | msg.
 * Writes are upserts on (agent_id, mem_key).
 */
interface MemoryStoreInterface {

	/**
	 * Upsert a memory.
	 *
	 * @param string   $agent_id   Owning agent.
	 * @param string   $key        Memory key (≤191 chars).
	 * @param array    $content    JSON-safe content.
	 * @param string   $kind       fact|state|task|lesson|msg.
	 * @param int      $importance 0-100; decayed over time for lessons.
	 * @param int|null $ttl        Seconds until expiry, null = permanent.
	 */
	public function remember( string $agent_id, string $key, array $content, string $kind = 'fact', int $importance = 50, ?int $ttl = null ): void;

	/**
	 * Fetch one memory's content (null when missing/expired). Increments hits.
	 *
	 * @param string $agent_id Owning agent.
	 * @param string $key      Memory key.
	 */
	public function recall( string $agent_id, string $key ): ?array;

	/**
	 * Delete one memory.
	 *
	 * @param string $agent_id Owning agent.
	 * @param string $key      Memory key.
	 */
	public function forget( string $agent_id, string $key ): void;

	/**
	 * List memories of a kind, most important first.
	 *
	 * @param string $agent_id Owning agent.
	 * @param string $kind     Memory kind.
	 * @param int    $limit    Max rows.
	 * @return array<string, array> Content keyed by mem_key.
	 */
	public function by_kind( string $agent_id, string $kind, int $limit = 50 ): array;

	/**
	 * Delete expired rows and decay lesson importance. Returns rows removed.
	 */
	public function prune(): int;
}
