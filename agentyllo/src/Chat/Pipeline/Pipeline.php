<?php
/**
 * Chat pipeline runner.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Pipeline;

use Agentyllo\Agents\Contracts\JournalInterface;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Executes ordered stages inside the HTTP request. Status events (with
 * timestamps) accumulate in the context so buffered transports can replay
 * them synthetically — UX parity across tiers is an event-contract property.
 * A throwing stage is journaled and skipped; if no blocks exist at the end,
 * an honesty fallback is appended: the pipeline never returns emptiness.
 */
final class Pipeline {

	/**
	 * Constructor.
	 *
	 * @param Stage[]          $stages  Ordered stages (filtered).
	 * @param JournalInterface $journal Journal for stage failures.
	 */
	public function __construct(
		private array $stages,
		private readonly JournalInterface $journal,
	) {
	}

	/**
	 * Run all stages.
	 *
	 * @param ChatContext $context Context.
	 */
	public function run( ChatContext $context ): ChatContext {
		/**
		 * Filter the pipeline stages before a run. Addons may insert stages.
		 *
		 * @param Stage[]     $stages  Ordered stages.
		 * @param ChatContext $context The context (read-only use here).
		 */
		$stages = (array) apply_filters( 'agy_pipeline_steps', $this->stages, $context );

		$start  = microtime( true );
		$events = array( array( 'state' => 'queued', 'ts' => 0 ) );

		foreach ( $stages as $stage ) {
			if ( ! $stage instanceof Stage ) {
				continue;
			}

			$event = $stage->status_event();
			if ( '' !== $event ) {
				$ts       = (int) round( ( microtime( true ) - $start ) * 1000 );
				$events[] = array(
					'state' => $event,
					'ts'    => $ts,
				);
				// Live transports (SSE) get the state now; buffered ones replay
				// the same list afterwards — one event contract, two paces.
				if ( is_callable( $context->meta['event_sink'] ?? null ) ) {
					( $context->meta['event_sink'] )( $event, $ts );
				}
			}

			$context->meta['elapsed_s'] = round( microtime( true ) - $start, 3 );
			$stage_start                = microtime( true );
			try {
				$stage->process( $context );
			} catch ( Throwable $e ) {
				$this->journal->error( 'pipeline', $e, null, array( 'stage' => $stage->name() ) );
				$context->meta['degraded'][] = $stage->name();
			}
			$context->meta['timings'][ $stage->name() ] = (int) round( ( microtime( true ) - $stage_start ) * 1000 );

			// A refusal decided mid-pipeline stops retrieval/composition work.
			if ( ChatContext::ROUTE_REFUSE === $context->route && empty( $context->blocks ) && 'compose' !== $stage->name() ) {
				continue;
			}
		}

		if ( empty( $context->blocks ) ) {
			// Honesty fallback — never bluff, never go silent.
			$context->add_block(
				array(
					'type' => 'text',
					'md'   => __( "I couldn't find an answer to that in this site's content. Could you rephrase, or ask me something about this site?", 'agentyllo' ),
				)
			);
			$context->note( 'fallback', true );
		}

		$events[]                  = array(
			'state' => ChatContext::ROUTE_REFUSE === $context->route ? 'refused' : 'done',
			'ts'    => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
		$context->meta['events']   = $events;
		$context->meta['total_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

		return $context;
	}
}
