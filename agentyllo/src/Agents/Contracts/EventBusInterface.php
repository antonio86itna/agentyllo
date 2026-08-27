<?php
/**
 * In-process event bus contract.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Synchronous, same-request events (dot notation, e.g. 'kb.delta').
 * Every emit is mirrored to WordPress as `do_action('agyl_' . str_replace('.', '_', $event), $payload)`
 * so addons hear everything. Durable cross-request messaging rides Action
 * Scheduler through the AsyncBus instead.
 */
interface EventBusInterface {

	/**
	 * Subscribe a listener.
	 *
	 * @param string   $event    Event name.
	 * @param callable $listener fn(array $payload): void.
	 * @param int      $priority Lower runs earlier. Default 10.
	 */
	public function on( string $event, callable $listener, int $priority = 10 ): void;

	/**
	 * Emit an event to all listeners (and mirror to WP hooks).
	 *
	 * @param string $event   Event name.
	 * @param array  $payload JSON-safe payload.
	 */
	public function emit( string $event, array $payload = array() ): void;
}
