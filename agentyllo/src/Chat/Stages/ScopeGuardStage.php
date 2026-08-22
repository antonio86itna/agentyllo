<?php
/**
 * Scope guard stage: deny-list categories + retrieval-score threshold.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Stages;

use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Stage;
use Agentyllo\KB\Retrieval\HybridRetriever;
use Agentyllo\KB\Retrieval\Tokenizer;
use Agentyllo\KB\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Runs AFTER retrieve so the top fused score is available. Two layers:
 *
 * 1. Deny-list categories (phrase patterns, translatable, filterable via
 *    'agy_scope_guard_policy'). Jailbreak/adult stay active even when the
 *    site owner disables the out-of-scope guard; the others are gated.
 * 2. Score threshold: when the guard is on and the top fused score falls
 *    below option 'agy_scope_threshold' for KB-answerable intents, the
 *    question is judged off-topic → ROUTE_REFUSE.
 *
 * Refusals soften over two strikes: the first one is a warm redirect with
 * example questions built from top-weight KB documents; repeats get a short
 * refusal. The REST layer passes the prior per-session refusal count in
 * ctx->meta['session_oos_count'].
 *
 * calibrate() derives a site-specific threshold from self-retrieval: each
 * document title should retrieve its own document strongly, so half of the
 * 5th-percentile self-score is a safe "this is about the site" floor.
 */
final class ScopeGuardStage implements Stage {

	public const THRESHOLD_OPTION  = 'agy_scope_threshold';
	public const DEFAULT_THRESHOLD = 0.35;

	/**
	 * Minimum fraction of the visitor's content terms the best chunk must
	 * contain before the message counts as "about this site".
	 */
	public const MIN_COVERAGE = 0.34;

	private const SKIP_INTENTS      = array( 'greeting', 'smalltalk', 'contact', 'handoff' );
	private const THRESHOLD_INTENTS = array( 'site_info', 'product_query', 'price_stock', 'hours_policy', 'navigation_find_page' );
	private const CALIBRATE_SAMPLE  = 30;
	private const EXAMPLE_COUNT     = 3;

	/**
	 * Resolver returning the current 'general' settings array.
	 *
	 * @var callable
	 */
	private $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param HybridRetriever $retriever         Retrieval engine (calibration/probing).
	 * @param callable        $settings_resolver Returns the current 'general' settings array.
	 */
	public function __construct(
		private readonly HybridRetriever $retriever,
		callable $settings_resolver
	) {
		$this->settings_resolver = $settings_resolver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'scope_guard';
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
		if ( in_array( $context->intent, self::SKIP_INTENTS, true ) ) {
			return;
		}

		$settings = $this->settings();
		$guard_on = (bool) ( $settings['out_of_scope_guard'] ?? true );

		// Layer 1: deny-list categories. Jailbreak/adult are non-negotiable.
		$category = $this->match_category( $context->text, $guard_on );
		if ( '' !== $category ) {
			$this->refuse( $context, 'denylist:' . $category, $settings );

			return;
		}

		/*
		 * Navigation asks for a page BY NAME — the name usually lives only in
		 * the title, so chunk coverage under-reports relevance ("take me to
		 * the contact page" matches the Contact page's title but barely its
		 * body). A title match settles scope by definition; the targets are
		 * stashed for ComposeStage so the lookup runs once.
		 */
		if ( 'navigation_find_page' === $context->intent ) {
			$targets = $this->retriever->title_lookup( $context->text, array( 'lang' => $context->site_lang ) );
			if ( $targets ) {
				$context->meta['nav_targets'] = $targets;
				$context->note( 'nav_targets', count( $targets ) );

				return;
			}
		}

		if ( ! $guard_on ) {
			return;
		}

		// Layer 2: retrieval-score threshold for KB-answerable intents.
		if ( ! in_array( $context->intent, self::THRESHOLD_INTENTS, true ) ) {
			return;
		}

		$threshold = (float) get_option( self::THRESHOLD_OPTION, self::DEFAULT_THRESHOLD );
		$top       = $context->chunks[0] ?? array();
		$top_score = (float) ( $top['score'] ?? 0.0 );
		$coverage  = (float) ( $top['coverage'] ?? 0.0 );
		$matched   = (int) ( $top['matched_terms'] ?? 0 );

		$context->note( 'guard_top_score', $top_score );
		$context->note( 'guard_coverage', $coverage );

		/*
		 * Relevance test — coverage first (RRF rank scores are ~1.0 for ANY
		 * top hit, so they cannot tell "in scope" from "unknown"). A message
		 * is out of scope when the best chunk contains none of its content
		 * terms, or fewer than a third of them, or the fused score is under
		 * the calibrated floor.
		 */
		$no_terms = 0 === $matched;

		/*
		 * Cross-language messages (KB in the site language, visitor writing
		 * in another) can only match on shared tokens — names, loanwords,
		 * numbers — so a single shared content term is already the strongest
		 * classic signal available; the thin-match rule applies to
		 * same-language messages only. Detection at ≥0.4 counts here: even a
		 * tentative "this is Italian" is enough to relax, never to refuse.
		 */
		$site_two      = strtolower( substr( $context->site_lang, 0, 2 ) );
		$detected      = strtolower( (string) ( $context->meta['lang_detected'] ?? '' ) );
		$cross_lingual = '' !== $detected && $detected !== $site_two && $context->lang_confidence >= 0.4;
		$thin_match    = ! $cross_lingual && $coverage < self::MIN_COVERAGE && $matched < 2;

		if ( ! $context->chunks || $no_terms || $thin_match || $top_score < $threshold ) {
			$this->refuse( $context, $no_terms || $thin_match ? 'low_coverage' : 'low_score', $settings );
		}
	}

	/**
	 * First matching deny-list category, '' when clean.
	 *
	 * @param string $text     Normalized message.
	 * @param bool   $guard_on Whether the full guard is enabled.
	 */
	private function match_category( string $text, bool $guard_on ): string {
		foreach ( $this->policy() as $category => $rules ) {
			$always = (bool) ( $rules['always'] ?? false );
			if ( ! $guard_on && ! $always ) {
				continue;
			}

			foreach ( (array) ( $rules['patterns'] ?? array() ) as $phrase ) {
				$phrase = trim( (string) $phrase );
				if ( '' === $phrase ) {
					continue;
				}
				if ( preg_match( '/\b' . preg_quote( $phrase, '/' ) . '\b/iu', $text ) ) {
					return (string) $category;
				}
			}
		}

		return '';
	}

	/**
	 * Deny-list policy: category => {always: bool, patterns: string[]}.
	 * Patterns are translatable so localized sites can adapt the phrase lists.
	 *
	 * @return array<string, array{always: bool, patterns: string[]}>
	 */
	private function policy(): array {
		$policy = array(
			'jailbreak' => array(
				'always'   => true,
				'patterns' => array(
					__( 'ignore previous instructions', 'agentyllo' ),
					__( 'ignore all instructions', 'agentyllo' ),
					__( 'disregard your rules', 'agentyllo' ),
					__( 'system prompt', 'agentyllo' ),
					__( 'developer mode', 'agentyllo' ),
					__( 'jailbreak', 'agentyllo' ),
					__( 'pretend you are', 'agentyllo' ),
					__( 'act as an ai without', 'agentyllo' ),
				),
			),
			'adult'     => array(
				'always'   => true,
				'patterns' => array(
					__( 'porn', 'agentyllo' ),
					__( 'nsfw', 'agentyllo' ),
					__( 'xxx', 'agentyllo' ),
					__( 'explicit sexual', 'agentyllo' ),
					__( 'nude photos', 'agentyllo' ),
					__( 'escort service', 'agentyllo' ),
				),
			),
			'coding'    => array(
				'always'   => false,
				'patterns' => array(
					__( 'write code', 'agentyllo' ),
					__( 'write a function', 'agentyllo' ),
					__( 'write a script', 'agentyllo' ),
					__( 'debug my code', 'agentyllo' ),
					__( 'fix my code', 'agentyllo' ),
					__( 'python script', 'agentyllo' ),
					__( 'javascript function', 'agentyllo' ),
					__( 'sql query', 'agentyllo' ),
					__( 'stack trace', 'agentyllo' ),
					__( 'compile error', 'agentyllo' ),
				),
			),
			'medical'   => array(
				'always'   => false,
				'patterns' => array(
					__( 'medical advice', 'agentyllo' ),
					__( 'diagnose', 'agentyllo' ),
					__( 'diagnosis', 'agentyllo' ),
					__( 'what medication', 'agentyllo' ),
					__( 'dosage', 'agentyllo' ),
					__( 'symptoms of', 'agentyllo' ),
					__( 'prescription for', 'agentyllo' ),
				),
			),
			'legal'     => array(
				'always'   => false,
				'patterns' => array(
					__( 'legal advice', 'agentyllo' ),
					__( 'lawsuit', 'agentyllo' ),
					__( 'sue them', 'agentyllo' ),
					__( 'is it legal', 'agentyllo' ),
					__( 'contract clause', 'agentyllo' ),
					__( 'hire a lawyer', 'agentyllo' ),
				),
			),
			'financial' => array(
				'always'   => false,
				'patterns' => array(
					__( 'financial advice', 'agentyllo' ),
					__( 'investment advice', 'agentyllo' ),
					__( 'should i invest', 'agentyllo' ),
					__( 'stock tips', 'agentyllo' ),
					__( 'buy bitcoin', 'agentyllo' ),
					__( 'crypto price', 'agentyllo' ),
				),
			),
			'politics'  => array(
				'always'   => false,
				'patterns' => array(
					__( 'who should i vote', 'agentyllo' ),
					__( 'political party', 'agentyllo' ),
					__( 'election fraud', 'agentyllo' ),
					__( 'immigration policy', 'agentyllo' ),
				),
			),
			'homework'  => array(
				'always'   => false,
				'patterns' => array(
					__( 'my homework', 'agentyllo' ),
					__( 'homework help', 'agentyllo' ),
					__( 'solve this equation', 'agentyllo' ),
					__( 'essay for school', 'agentyllo' ),
					__( 'math problem', 'agentyllo' ),
				),
			),
		);

		/**
		 * Filter the scope-guard deny-list policy.
		 *
		 * @param array $policy category => {always: bool, patterns: string[]}.
		 */
		return (array) apply_filters( 'agy_scope_guard_policy', $policy );
	}

	/**
	 * Refuse with two-strike softening: the first refusal in a session is a
	 * warm redirect listing example questions; repeats are short.
	 *
	 * @param ChatContext          $context  Context.
	 * @param string               $reason   Guard reason (diagnostics).
	 * @param array<string, mixed> $settings General settings.
	 */
	private function refuse( ChatContext $context, string $reason, array $settings ): void {
		$context->route = ChatContext::ROUTE_REFUSE;
		$context->note( 'guard', $reason );

		$strikes   = (int) ( $context->meta['session_oos_count'] ?? 0 );
		$site_name = (string) get_bloginfo( 'name' );
		$custom    = trim( (string) ( $settings['oos_refusal_message'] ?? '' ) );

		if ( '' !== $custom ) {
			$message = $custom;
		} elseif ( $strikes > 0 ) {
			/* translators: %s: site name. */
			$message = sprintf( __( 'I can only help with questions about %s.', 'agentyllo' ), $site_name );
		} else {
			/* translators: %s: site name. */
			$message = sprintf( __( 'That is outside what I can help with — I am the assistant for %s, so I only answer questions about this site and its content.', 'agentyllo' ), $site_name );
		}

		$context->add_block(
			array(
				'type' => 'text',
				'md'   => $message,
			)
		);

		if ( 0 === $strikes ) {
			$examples = $this->example_questions();
			if ( $examples ) {
				$lines = array( __( 'Here are a few things you can ask me:', 'agentyllo' ) );
				foreach ( $examples as $title ) {
					/* translators: %s: knowledge-base document title. */
					$lines[] = '- ' . sprintf( __( 'Tell me about "%s"', 'agentyllo' ), $title );
				}
				$context->add_block(
					array(
						'type' => 'text',
						'md'   => implode( "\n", $lines ),
					)
				);
			}
		}
	}

	/**
	 * Titles of the top-weight active KB documents (site identity excluded —
	 * suggesting the site's own name as a question reads oddly).
	 *
	 * @return string[]
	 */
	private function example_questions(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$titles = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT title FROM ' . $wpdb->prefix . "agy_kb_documents WHERE status = %s AND title <> '' AND source <> %s ORDER BY weight DESC, id ASC LIMIT %d",
				Store::STATUS_ACTIVE,
				'site',
				self::EXAMPLE_COUNT
			)
		);

		return array_values( array_filter( array_map( 'strval', (array) $titles ) ) );
	}

	/**
	 * Calibrate the score threshold from self-retrieval: sample up to 30
	 * active document titles, search each title, and record the top fused
	 * score. Half of the 5th percentile becomes the new threshold (stored in
	 * option agy_scope_threshold). Called by the KB health job.
	 *
	 * @param HybridRetriever|null $retriever Optional injected engine.
	 * @return float The effective threshold after calibration.
	 */
	public static function calibrate( ?HybridRetriever $retriever = null ): float {
		global $wpdb;

		$retriever ??= new HybridRetriever( new Tokenizer() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$titles = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT title FROM ' . $wpdb->prefix . "agy_kb_documents WHERE status = %s AND title <> '' ORDER BY weight DESC, id ASC LIMIT %d",
				Store::STATUS_ACTIVE,
				self::CALIBRATE_SAMPLE
			)
		);

		$scores = array();
		foreach ( (array) $titles as $title ) {
			$hits = $retriever->search( (string) $title, array( 'limit' => 1 ) );
			if ( $hits ) {
				$scores[] = (float) $hits[0]['score'];
			}
		}

		if ( count( $scores ) < 5 ) {
			// Too small a corpus to calibrate — keep the current threshold.
			return (float) get_option( self::THRESHOLD_OPTION, self::DEFAULT_THRESHOLD );
		}

		sort( $scores );
		$index     = (int) floor( 0.05 * ( count( $scores ) - 1 ) );
		$threshold = round( max( 0.05, min( 1.5, $scores[ $index ] * 0.5 ) ), 4 );

		update_option( self::THRESHOLD_OPTION, $threshold, false );

		return $threshold;
	}

	/**
	 * Current 'general' settings via the injected resolver.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$settings = ( $this->settings_resolver )();

		return is_array( $settings ) ? $settings : array();
	}
}
