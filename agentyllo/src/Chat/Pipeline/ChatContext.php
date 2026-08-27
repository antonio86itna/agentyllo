<?php
/**
 * Mutable context flowing through the chat pipeline.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Every stage reads and mutates this. The invariant that matters: hard facts
 * (prices, stock, contacts, URLs) live in $fact_slots and are injected into
 * responses verbatim — no generative layer may write them.
 */
final class ChatContext {

	public const ROUTE_CLASSIC            = 'classic';
	public const ROUTE_CLASSIC_AI_REWRITE = 'classic_ai_rewrite';
	public const ROUTE_AI_RAG             = 'ai_rag';
	public const ROUTE_REFUSE             = 'refuse';
	public const ROUTE_HANDOFF            = 'handoff';

	/**
	 * Intent taxonomy (filterable via agyl_intents at classification time).
	 */
	public const INTENTS = array(
		'greeting',
		'site_info',
		'product_query',
		'price_stock',
		'contact',
		'navigation_find_page',
		'hours_policy',
		'smalltalk',
		'out_of_scope',
		'handoff',
	);

	/**
	 * Normalized message text (Normalize stage output).
	 */
	public string $text = '';

	/**
	 * Site content language (locale code).
	 */
	public string $site_lang = '';

	/**
	 * Detected visitor language (two-letter code, '' = unknown).
	 */
	public string $visitor_lang = '';

	/**
	 * Language detection confidence 0-1.
	 */
	public float $lang_confidence = 0.0;

	/**
	 * Classified intent (one of self::INTENTS or 'unknown').
	 */
	public string $intent = 'unknown';

	/**
	 * Intent confidence 0-1.
	 */
	public float $intent_confidence = 0.0;

	/**
	 * Extracted entities: {products: [], pages: [], skus: [], price_bounds: []}.
	 */
	public array $entities = array();

	/**
	 * Chosen route (ROUTE_* constant).
	 */
	public string $route = self::ROUTE_CLASSIC;

	/**
	 * Retrieval hits (HybridRetriever rows).
	 */
	public array $chunks = array();

	/**
	 * Immutable hard facts injected into the reply verbatim.
	 * Shape: key => {value: string, source: string}.
	 */
	public array $fact_slots = array();

	/**
	 * Response blocks (shared block schema; see src-js/shared/blocks.ts).
	 */
	public array $blocks = array();

	/**
	 * Diagnostics: timings, engine, degrade reasons, guard hits, events.
	 */
	public array $meta = array();

	/**
	 * Constructor.
	 *
	 * @param int    $session_id Session row id.
	 * @param string $raw        Original visitor message (preserved for AI tiers).
	 * @param string $site_lang  Site locale.
	 */
	public function __construct(
		public readonly int $session_id,
		public readonly string $raw,
		string $site_lang = '',
	) {
		$this->site_lang = $site_lang;
	}

	/**
	 * Append a response block.
	 *
	 * @param array $block Block (type + payload per the shared schema).
	 */
	public function add_block( array $block ): void {
		$this->blocks[] = $block;
	}

	/**
	 * Record a diagnostic value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 */
	public function note( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}
}
