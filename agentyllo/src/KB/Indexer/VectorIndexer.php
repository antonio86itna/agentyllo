<?php
/**
 * Background embedding of KB chunks into agy_kb_vectors.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Indexer;

use Agentyllo\AI\EmbeddingRouter;
use Agentyllo\KB\Retrieval\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Time-boxed, idempotent: each run embeds the chunks that still lack a
 * vector for the active model (batches of 32, ~20s budget), garbage-collects
 * orphans/other-model rows, and re-schedules itself while work remains. No
 * provider configured → nothing happens; the lexical floor is untouched.
 * Runs in Action Scheduler group `agentyllo-ai`.
 */
final class VectorIndexer {

	public const HOOK  = 'agy_kb_embed';
	public const GROUP = 'agentyllo-ai';

	private const BATCH    = 32;
	private const BUDGET_S = 20.0;

	/**
	 * Constructor.
	 *
	 * @param EmbeddingRouter $embeddings Embedding router.
	 * @param VectorStore     $vectors    Vector store.
	 */
	public function __construct(
		private readonly EmbeddingRouter $embeddings,
		private readonly VectorStore $vectors,
	) {
	}

	/**
	 * Attach the AS handler and the KB-change trigger.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'agy_kb_changed', array( $this, 'schedule' ) );
	}

	/**
	 * Enqueue one run (dedupes on pending actions).
	 */
	public function schedule(): void {
		if ( null === $this->embeddings->active() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK, array(), self::GROUP, true );
	}

	/**
	 * One time-boxed pass. Returns {embedded, remaining}.
	 *
	 * @return array{embedded: int, remaining: int}
	 */
	public function run(): array {
		$provider = $this->embeddings->active();
		$model    = $this->embeddings->model_key();
		if ( null === $provider || '' === $model ) {
			return array(
				'embedded'  => 0,
				'remaining' => 0,
			);
		}

		$this->vectors->gc( $model );

		$start    = microtime( true );
		$embedded = 0;
		while ( microtime( true ) - $start < self::BUDGET_S ) {
			$batch = $this->vectors->missing( $model, self::BATCH );
			if ( ! $batch ) {
				break;
			}
			$texts   = array_map( static fn ( array $c ): string => mb_substr( $c['content'], 0, 4000 ), $batch );
			$vectors = $provider->embed( $texts );
			if ( count( $vectors ) !== count( $batch ) ) {
				break; // Provider failure: retry on the next run, never loop.
			}
			$by_chunk = array();
			$docs     = array();
			foreach ( $batch as $i => $chunk ) {
				$by_chunk[ $chunk['id'] ] = $vectors[ $i ];
				$docs[ $chunk['id'] ]     = $chunk['document_id'];
			}
			$embedded += $this->vectors->upsert( $by_chunk, $docs, $model );
			if ( count( $batch ) < self::BATCH ) {
				break;
			}
		}

		$remaining = count( $this->vectors->missing( $model, 1 ) );
		if ( $remaining > 0 ) {
			$this->schedule();
		}
		update_option(
			'agy_kb_vectors_status',
			array(
				'model'     => $model,
				'count'     => $this->vectors->count( $model ),
				'remaining' => $remaining,
				'ran_at'    => time(),
			),
			false
		);

		return array(
			'embedded'  => $embedded,
			'remaining' => $remaining,
		);
	}
}
