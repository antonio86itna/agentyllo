<?php
/**
 * Agent registry.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Kernel;

use Agentyllo\Agents\Contracts\Agent;
use Agentyllo\Infra\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Collects Agent instances via the `agy_register_agents` filter and merges
 * per-agent runtime config (enabled, quarantine state, health streaks) from
 * the `agy_agents` option.
 */
final class Registry {

	/**
	 * Agents keyed by id.
	 *
	 * @var array<string, Agent>
	 */
	private array $agents = array();

	/**
	 * Runtime config keyed by agent id:
	 * {enabled: bool, quarantine: ?{reason: string, at: int}, streaks: array<string,int>}.
	 *
	 * @var array<string, array>
	 */
	private array $config = array();

	/**
	 * Whether boot() ran.
	 */
	private bool $booted = false;

	/**
	 * Constructor.
	 *
	 * @param Options $options Options wrapper.
	 */
	public function __construct( private readonly Options $options ) {
	}

	/**
	 * Collect agents (idempotent). Runs on first access so the filter fires
	 * after all plugins had a chance to subscribe.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/**
		 * Filter used to register agents. Core roster and addons append
		 * instances implementing Agentyllo\Agents\Contracts\Agent.
		 *
		 * @param Agent[] $agents Registered agents.
		 */
		$registered = (array) apply_filters( 'agy_register_agents', array() );

		foreach ( $registered as $agent ) {
			if ( $agent instanceof Agent ) {
				$this->agents[ $agent->id() ] = $agent;
			}
		}

		$stored       = $this->options->get( 'agents', array() );
		$this->config = is_array( $stored ) ? $stored : array();
	}

	/**
	 * All registered agents (including disabled/quarantined).
	 *
	 * @return array<string, Agent>
	 */
	public function all(): array {
		$this->boot();

		return $this->agents;
	}

	/**
	 * One agent by id.
	 *
	 * @param string $id Agent id.
	 */
	public function get( string $id ): ?Agent {
		$this->boot();

		return $this->agents[ $id ] ?? null;
	}

	/**
	 * Runtime config for one agent.
	 *
	 * @param string $id Agent id.
	 */
	public function config( string $id ): array {
		$this->boot();

		return array_merge(
			array(
				'enabled'    => true,
				'quarantine' => null,
				'streaks'    => array(),
			),
			is_array( $this->config[ $id ] ?? null ) ? $this->config[ $id ] : array()
		);
	}

	/**
	 * Persist a partial config update for one agent.
	 *
	 * @param string $id     Agent id.
	 * @param array  $config Partial config.
	 */
	public function update_config( string $id, array $config ): void {
		$this->boot();

		$this->config[ $id ] = array_merge( $this->config( $id ), $config );
		$this->options->set( 'agents', $this->config );
	}

	/**
	 * Whether an agent may receive work (registered + enabled + not quarantined).
	 *
	 * @param string $id Agent id.
	 */
	public function is_active( string $id ): bool {
		if ( ! $this->get( $id ) ) {
			return false;
		}
		$config = $this->config( $id );

		return $config['enabled'] && null === $config['quarantine'];
	}

	/**
	 * Active agents exposing a capability tag.
	 *
	 * @param string $capability Capability tag.
	 * @return array<string, Agent>
	 */
	public function by_capability( string $capability ): array {
		$out = array();
		foreach ( $this->all() as $id => $agent ) {
			if ( $this->is_active( $id ) && in_array( $capability, $agent->capabilities(), true ) ) {
				$out[ $id ] = $agent;
			}
		}

		return $out;
	}

	/**
	 * Active agents subscribed to an event, sorted by declared priority.
	 *
	 * @param string $event Event name.
	 * @return array<string, Agent>
	 */
	public function by_event( string $event ): array {
		$matches = array();
		foreach ( $this->all() as $id => $agent ) {
			$events = $agent->subscribed_events();
			if ( $this->is_active( $id ) && isset( $events[ $event ] ) ) {
				$matches[ $id ] = (int) $events[ $event ];
			}
		}
		asort( $matches );

		$out = array();
		foreach ( array_keys( $matches ) as $id ) {
			$out[ $id ] = $this->agents[ $id ];
		}

		return $out;
	}
}
