<?php
/**
 * In-process event bus.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Kernel;

use Agentyllo\Agents\Contracts\EventBusInterface;
use Agentyllo\Agents\Contracts\JournalInterface;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Synchronous listeners + WordPress hook mirroring. A throwing listener is
 * journaled and skipped — one broken subscriber can never break the emit.
 */
final class EventBus implements EventBusInterface {

	/**
	 * Listeners: event => priority => callables.
	 *
	 * @var array<string, array<int, array<int, callable>>>
	 */
	private array $listeners = array();

	/**
	 * Constructor.
	 *
	 * @param JournalInterface $journal Journal for listener failures.
	 */
	public function __construct( private readonly JournalInterface $journal ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function on( string $event, callable $listener, int $priority = 10 ): void {
		$this->listeners[ $event ][ $priority ][] = $listener;
	}

	/**
	 * {@inheritDoc}
	 */
	public function emit( string $event, array $payload = array() ): void {
		if ( isset( $this->listeners[ $event ] ) ) {
			$by_priority = $this->listeners[ $event ];
			ksort( $by_priority );

			foreach ( $by_priority as $listeners ) {
				foreach ( $listeners as $listener ) {
					try {
						$listener( $payload );
					} catch ( Throwable $e ) {
						$this->journal->error( 'event_bus', $e, null, array( 'event' => $event ) );
					}
				}
			}
		}

		/**
		 * Mirrored public hook for every internal event: 'kb.delta' becomes
		 * `do_action( 'agyl_kb_delta', $payload )` and so on. This is the addon
		 * listening surface.
		 *
		 * @param array $payload Event payload.
		 */
		do_action( 'agyl_' . str_replace( '.', '_', $event ), $payload );
	}
}
