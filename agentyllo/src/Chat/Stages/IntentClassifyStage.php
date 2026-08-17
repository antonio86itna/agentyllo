<?php
/**
 * Intent classification stage (silent).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Stages;

use Agentyllo\Chat\EntityExtractor;
use Agentyllo\Chat\IntentClassifier;
use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Stage;

defined( 'ABSPATH' ) || exit;

/**
 * Wires EntityExtractor + IntentClassifier into the context. Entities run
 * first on purpose: the classifier's Layer B fallback is entity-informed
 * (a recognized product title turns an otherwise unclassifiable message
 * into product_query). Downstream stages read $context->entities,
 * $context->intent and $context->intent_confidence — never 'unknown' after
 * this stage.
 */
final class IntentClassifyStage implements Stage {

	/**
	 * Constructor.
	 *
	 * @param EntityExtractor  $extractor  Entity extraction service.
	 * @param IntentClassifier $classifier Intent classification service.
	 */
	public function __construct(
		private readonly EntityExtractor $extractor,
		private readonly IntentClassifier $classifier,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'intent_classify';
	}

	/**
	 * {@inheritDoc}
	 */
	public function status_event(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( ChatContext $context ): void {
		$context->entities = $this->extractor->extract( $context->text );

		$text_lc = (string) ( $context->meta['text_lc'] ?? mb_strtolower( $context->text, 'UTF-8' ) );
		$result  = $this->classifier->classify( $text_lc, $context->entities );

		$context->intent            = (string) $result['intent'];
		$context->intent_confidence = (float) $result['confidence'];

		$context->note( 'entity_counts', array(
			'products' => count( (array) ( $context->entities['products'] ?? array() ) ),
			'pages'    => count( (array) ( $context->entities['pages'] ?? array() ) ),
			'skus'     => count( (array) ( $context->entities['skus'] ?? array() ) ),
		) );
	}
}
