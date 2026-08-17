<?php
/**
 * Retrieve stage: KB search into ctx->chunks.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Stages;

use Agentyllo\AI\EmbeddingRouter;
use Agentyllo\AI\Tasks\QueryRewriter;
use Agentyllo\Chat\Stages\ScopeGuardStage;
use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Stage;
use Agentyllo\KB\Retrieval\HybridRetriever;
use Agentyllo\KB\Retrieval\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Runs HybridRetriever over the normalized message. Template intents
 * (greeting/smalltalk/handoff) skip retrieval entirely: their answers come
 * from Templates, and retrieved chunks would only feed noise links into
 * PostProcess.
 *
 * Status events: the Stage interface emits one canonical event per stage and
 * takes no context, so this stage always announces 'searching'. For
 * product_query/price_stock the richer 'checking_products' beat is appended
 * to ctx->meta['extra_events'] — the Pipeline does not merge these today
 * (acceptable v1: a single 'searching' event); a transport may merge them
 * later.
 */
final class RetrieveStage implements Stage {

	private const LIMIT = 8;

	/**
	 * Constructor.
	 *
	 * @param HybridRetriever $retriever Retrieval engine.
	 */
	public function __construct(
		private readonly HybridRetriever $retriever,
		private readonly ?EmbeddingRouter $embeddings = null,
		private readonly ?VectorStore $vectors = null,
		private readonly ?QueryRewriter $rewriter = null,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'retrieve';
	}

	/**
	 * {@inheritDoc}
	 */
	public function status_event(): string {
		return 'searching';
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( ChatContext $context ): void {
		if ( isset( $context->meta['template'] ) ) {
			$context->note( 'retrieve_skipped', 'template_intent' );

			return;
		}

		if ( in_array( $context->intent, array( 'product_query', 'price_stock' ), true ) ) {
			$context->meta['extra_events'][] = 'checking_products';
		}

		// Lang '' = any: v1 targets single-language sites (the KB stores full
		// locales, queries carry two-letter codes). TODO(WPML/Polylang): slice
		// by the current language once multilingual mapping lands.
		$opts = array(
			'lang'  => '',
			'limit' => self::LIMIT,
		);

		// Dense channel when an embedding provider is active (M8): the query
		// is embedded once (transient-cached) and resolved against the vector
		// store inside the retriever's fusion.
		if ( null !== $this->embeddings && null !== $this->vectors && null !== $this->embeddings->active() ) {
			$vector = $this->embeddings->embed_query( $context->text );
			$model  = $this->embeddings->model_key();
			if ( $vector && '' !== $model ) {
				$vectors = $this->vectors;
				$opts['dense_resolver'] = static function ( array $lexical ) use ( $vectors, $vector, $model ): array {
					$hits = $vectors->search( $vector, $model, 30 );
					if ( ! $hits && $lexical ) {
						$hits = $vectors->search( $vector, $model, 30, $lexical );
					}

					return $hits;
				};
				$context->note( 'dense', true );
			}
		}

		$context->chunks = $this->retriever->search( $context->text, $opts );

		// T2 bounded task: when the lexical pass is weak and an AI provider is
		// usable, ask it for search keywords and retry once. The rewrite never
		// reaches the visitor; a failure keeps the original results.
		if ( null !== $this->rewriter && $this->is_weak( $context->chunks ) && $this->rewriter->available() ) {
			$keywords = $this->rewriter->rewrite( $context->text, $context->site_lang );
			if ( '' !== $keywords ) {
				$second = $this->retriever->search( $keywords, $opts );
				if ( ! $this->is_weak( $second ) || count( $second ) > count( $context->chunks ) ) {
					$context->chunks = $second;
					$context->note( 'query_rewritten', $keywords );
				}
			}
		}

		$context->note( 'retrieved', count( $context->chunks ) );
	}

	/**
	 * Whether a result set would fail the scope/quality gates.
	 *
	 * @param array<int, array<string, mixed>> $chunks Retrieval rows.
	 */
	private function is_weak( array $chunks ): bool {
		if ( ! $chunks ) {
			return true;
		}
		$top = $chunks[0];

		return (float) ( $top['coverage'] ?? 0.0 ) < ScopeGuardStage::MIN_COVERAGE && (int) ( $top['matched_terms'] ?? 0 ) < 2;
	}
}
