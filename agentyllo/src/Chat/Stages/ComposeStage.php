<?php
/**
 * Compose stage: deterministic classic answer construction.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Stages;

use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Stage;
use Agentyllo\Chat\Templates;
use Agentyllo\KB\Retrieval\HybridRetriever;
use Agentyllo\KB\Retrieval\Tokenizer;

defined( 'ABSPATH' ) || exit;

/**
 * Classic (no-AI) composition:
 *
 * - Template intents (greeting/smalltalk/handoff) answer from Templates,
 *   handoff adds contact fact slots and a contact-page links block.
 * - Everything else is extractive: the best 1-3 sentences from the top
 *   retrieval hits, scored by query-term density with a positional bonus and
 *   verbatim boosts for spec-chunk lines. A quality gate (top chunk score and
 *   query-term coverage) decides between quoting the extract and an honest
 *   "here is what I found" hand-over to the links block.
 * - Fact slots (price/stock/contact) render FIRST and verbatim — they were
 *   loaded from authoritative sources in RouteStage, never from chunk text.
 * - Replies are always in the site language; a confidently detected foreign
 *   visitor language earns one pre-translated courtesy notice line.
 */
final class ComposeStage implements Stage {

	private const TEMPLATE_INTENTS  = array( 'greeting', 'smalltalk', 'handoff' );
	private const MAX_SENTENCES     = 3;
	private const MAX_EXTRACT_CHARS = 600;
	private const MIN_SENTENCE_LEN  = 20;
	private const MAX_SENTENCE_LEN  = 400;

	/**
	 * Resolver returning the current 'general' settings array.
	 *
	 * @var callable
	 */
	private $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param Templates       $templates         Template packs.
	 * @param HybridRetriever $retriever         Retrieval engine (contact-page lookup).
	 * @param Tokenizer       $tokenizer         Shared tokenizer (query-term density).
	 * @param callable        $settings_resolver Returns the current 'general' settings array.
	 */
	public function __construct(
		private readonly Templates $templates,
		private readonly HybridRetriever $retriever,
		private readonly Tokenizer $tokenizer,
		callable $settings_resolver
	) {
		$this->settings_resolver = $settings_resolver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'compose';
	}

	/**
	 * {@inheritDoc}
	 */
	public function status_event(): string {
		return 'formatting';
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( ChatContext $context ): void {
		// Refusals were fully composed by the scope guard.
		if ( ChatContext::ROUTE_REFUSE === $context->route ) {
			$context->note( 'answered', false );

			return;
		}

		// An AI tier already composed (and FactGuard verified) the answer.
		if ( ! empty( $context->meta['ai_composed'] ) ) {
			return;
		}

		$this->maybe_language_notice( $context );

		$settings  = $this->settings();
		$site_name = (string) get_bloginfo( 'name' );

		$template = (string) ( $context->meta['template'] ?? '' );
		if ( '' === $template && in_array( $context->intent, self::TEMPLATE_INTENTS, true ) ) {
			$template = $context->intent;
		}

		if ( '' !== $template ) {
			$this->compose_template( $context, $template, $settings, $site_name );

			return;
		}

		// Navigation: a deterministic link card to the page(s) whose TITLE
		// matches — never an extractive quote (the target's name rarely
		// appears in body prose, so extraction picks the wrong page).
		if ( 'navigation_find_page' === $context->intent && $this->compose_navigation( $context ) ) {
			return;
		}

		// Hard facts first, verbatim — never re-derived from chunk text.
		$facts_md = $this->fact_lines( $context->fact_slots );
		if ( '' !== $facts_md ) {
			$context->add_block(
				array(
					'type' => 'text',
					'md'   => $facts_md,
				)
			);
		}

		$extract = $this->extract_answer( $context );

		if ( null !== $extract ) {
			$context->add_block(
				array(
					'type' => 'text',
					'md'   => $extract,
				)
			);
			$context->note( 'answered', true );

			return;
		}

		if ( '' !== $facts_md ) {
			// Live facts alone answer price/stock/contact questions.
			$context->note( 'answered', true );

			return;
		}

		if ( $this->has_linkable_chunk( $context ) ) {
			// Honesty block: no confident extract, but relevant pages exist —
			// PostProcess appends the links.
			$context->add_block(
				array(
					'type' => 'text',
					/* translators: %s: site name. */
					'md'   => sprintf( __( 'Here is what I found on %s:', 'agentyllo' ), $site_name ),
				)
			);
		}
		// Otherwise leave blocks empty: the pipeline's honesty fallback answers.

		$context->note( 'answered', false );
	}

	/**
	 * Template-intent composition. Handoff adds the contact fact slots and a
	 * links block pointing at the contact page found in the KB.
	 *
	 * @param ChatContext          $context   Context.
	 * @param string               $template  Template intent.
	 * @param array<string, mixed> $settings  General settings.
	 * @param string               $site_name Site name.
	 */
	private function compose_template( ChatContext $context, string $template, array $settings, string $site_name ): void {
		$tone = (string) ( $settings['tone'] ?? 'friendly' );

		$text = $this->templates->get(
			$template,
			$tone,
			array(
				'site_name'  => $site_name,
				'session_id' => (string) $context->session_id,
			)
		);

		if ( '' !== $text ) {
			$context->add_block(
				array(
					'type' => 'text',
					'md'   => $text,
				)
			);
		}

		if ( 'handoff' === $template ) {
			$facts_md = $this->fact_lines( $context->fact_slots );
			if ( '' !== $facts_md ) {
				$context->add_block(
					array(
						'type' => 'text',
						'md'   => $facts_md,
					)
				);
			}

			$contact = $this->contact_page_link();
			if ( $contact ) {
				$context->add_block(
					array(
						'type'  => 'links',
						'items' => array( $contact ),
					)
				);
			}
		}

		$context->note( 'answered', true );
	}

	/**
	 * Navigation composition: link card(s) for the documents whose title
	 * matches the query. ScopeGuard stashes its lookup in meta['nav_targets'];
	 * when the guard is disabled the lookup runs here instead. Returns false
	 * when no title matches — the generic extract/honesty path takes over.
	 *
	 * @param ChatContext $context Context.
	 */
	private function compose_navigation( ChatContext $context ): bool {
		$targets = $context->meta['nav_targets'] ?? null;
		if ( ! is_array( $targets ) ) {
			$targets = $this->retriever->title_lookup( $context->text, array( 'lang' => $context->site_lang ) );
		}
		if ( ! $targets ) {
			return false;
		}

		$items = array();
		foreach ( $targets as $target ) {
			$url = (string) ( $target['permalink'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$items[] = array(
				'title' => wp_specialchars_decode( (string) ( $target['title'] ?? '' ), ENT_QUOTES ),
				'url'   => $url,
			);
		}
		if ( ! $items ) {
			return false;
		}

		$context->add_block(
			array(
				'type' => 'text',
				'md'   => 1 === count( $items )
					? __( 'Here is the page you are looking for:', 'agentyllo' )
					: __( 'These pages should be what you are looking for:', 'agentyllo' ),
			)
		);
		$context->add_block(
			array(
				'type'  => 'links',
				'items' => $items,
			)
		);

		$context->meta['nav_composed'] = true;
		$context->note( 'answered', true );

		return true;
	}

	/**
	 * Best contact page from the KB (search 'contact' in the site language).
	 *
	 * @return array{title: string, url: string}|null
	 */
	private function contact_page_link(): ?array {
		$hits = $this->retriever->search( __( 'contact', 'agentyllo' ), array( 'limit' => 3 ) );

		foreach ( $hits as $hit ) {
			$url = (string) ( $hit['permalink'] ?? '' );
			if ( '' !== $url && 'site' !== ( $hit['source'] ?? '' ) ) {
				return array(
					'title' => (string) $hit['title'],
					'url'   => $url,
				);
			}
		}

		return null;
	}

	/**
	 * Extractive answer: pick the best 1-3 sentences from the top chunks by
	 * query-term density, with a positional bonus for leading sentences and a
	 * verbatim boost for spec-chunk lines. Returns null when the quality gate
	 * fails (weak top score or no query-term coverage).
	 *
	 * @param ChatContext $context Context.
	 */
	private function extract_answer( ChatContext $context ): ?string {
		if ( ! $context->chunks ) {
			return null;
		}

		$threshold = (float) get_option( ScopeGuardStage::THRESHOLD_OPTION, ScopeGuardStage::DEFAULT_THRESHOLD );
		$top       = $context->chunks[0];
		$top_score = (float) ( $top['score'] ?? 0.0 );

		// Quality gate, part 1: the best hit must be RELEVANT — it must
		// contain at least a third of the visitor's content terms (RRF rank
		// scores alone cannot tell a real match from noise) and clear the
		// scope threshold with margin — before its text is quoted verbatim.
		// Cross-language messages (see ScopeGuardStage) match on shared tokens
		// only: one shared content term is enough to quote the site-language
		// sentence — the courtesy notice above it explains the language.
		$site_two      = strtolower( substr( $context->site_lang, 0, 2 ) );
		$detected      = strtolower( (string) ( $context->meta['lang_detected'] ?? '' ) );
		$cross_lingual = '' !== $detected && $detected !== $site_two && $context->lang_confidence >= 0.4;
		$min_coverage  = $cross_lingual ? 0.0 : ScopeGuardStage::MIN_COVERAGE;

		if ( $top_score < $threshold * 1.2 || (float) ( $top['coverage'] ?? 0.0 ) < $min_coverage || (int) ( $top['matched_terms'] ?? 0 ) < 1 ) {
			return null;
		}

		$query_terms = array_values( array_unique( $this->tokenizer->tokenize( $context->text, $context->site_lang ) ) );
		if ( ! $query_terms ) {
			return null;
		}
		$query_set = array_flip( $query_terms );

		// Sentences from lower-ranked documents must match harder than the
		// top document's: never stitch a stray sentence from another page
		// into the answer just because it shares one word with the question.
		$top_document = (int) ( $top['document_id'] ?? 0 );
		$min_overlap_other = max( 2, (int) ceil( 0.5 * count( $query_terms ) ) );

		$candidates = array();
		$order      = 0;

		foreach ( array_slice( $context->chunks, 0, 3 ) as $chunk_index => $chunk ) {
			$is_spec   = 'spec' === ( $chunk['kind'] ?? '' );
			$sentences = $this->split_sentences( (string) $chunk['content'], $is_spec );
			$same_doc  = (int) ( $chunk['document_id'] ?? -1 ) === $top_document;
			$doc_title = mb_strtolower( trim( wp_specialchars_decode( (string) ( $chunk['title'] ?? '' ), ENT_QUOTES ) ) );

			foreach ( $sentences as $position => $sentence ) {
				$sentence = trim( $sentence );
				$length   = mb_strlen( $sentence );
				// The indexer prepends the document title as the first line for
				// BM25 findability — it is a label, never an answer sentence.
				if ( '' !== $doc_title && mb_strtolower( $sentence ) === $doc_title ) {
					continue;
				}
				if ( $length < self::MIN_SENTENCE_LEN || $length > self::MAX_SENTENCE_LEN ) {
					continue;
				}

				$tokens  = $this->tokenizer->tokenize( $sentence, $context->site_lang );
				$overlap = 0;
				foreach ( array_unique( $tokens ) as $token ) {
					if ( isset( $query_set[ $token ] ) ) {
						++$overlap;
					}
				}
				if ( 0 === $overlap || ( ! $same_doc && $overlap < $min_overlap_other ) ) {
					continue;
				}

				$score = $overlap / max( 1.0, sqrt( (float) count( $tokens ) ) );

				// Positional bonus: leading sentences summarize.
				$score += max( 0.0, 0.3 - 0.1 * $position );

				// Spec lines matching a query term verbatim are exact facts.
				if ( $is_spec && $this->contains_verbatim( $sentence, $query_terms ) ) {
					$score += 0.5;
				}

				// Earlier (higher-ranked) chunks win ties.
				$score += 0.05 * ( 3 - $chunk_index );

				$candidates[] = array(
					'sentence' => $sentence,
					'score'    => $score,
					'order'    => $order,
				);
				++$order;
			}
		}

		if ( ! $candidates ) {
			return null;
		}

		usort( $candidates, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );
		$picked = array_slice( $candidates, 0, self::MAX_SENTENCES );

		// Reading order, not score order.
		usort( $picked, static fn ( array $a, array $b ): int => $a['order'] <=> $b['order'] );

		$out = '';
		foreach ( $picked as $candidate ) {
			$sentence = $candidate['sentence'];
			// List items / spec lines carry no terminal punctuation: add one so
			// stitched sentences read as prose, not as a run-on.
			if ( ! preg_match( '/[.!?…:]$/u', $sentence ) ) {
				$sentence .= '.';
			}
			$next = ( '' === $out ? '' : ' ' ) . $sentence;
			if ( mb_strlen( $out . $next ) > self::MAX_EXTRACT_CHARS && '' !== $out ) {
				break;
			}
			$out .= $next;
		}

		return '' !== $out ? $out : null;
	}

	/**
	 * Sentence segmentation. Spec chunks split on lines (each "Label: value"
	 * line is one fact); prose splits on sentence-ending punctuation.
	 *
	 * @param string $content Chunk content.
	 * @param bool   $is_spec Whether the chunk is a spec chunk.
	 * @return string[]
	 */
	private function split_sentences( string $content, bool $is_spec ): array {
		// Newlines always separate units (title line, headings, list items);
		// prose additionally splits on sentence punctuation. Bullet glyphs
		// are stripped so list items read as clean sentences.
		$pattern = $is_spec ? '/\r\n|\r|\n/' : '/\r\n|\r|\n|(?<=[.!?…])\s+/u';
		$parts   = preg_split( $pattern, $content );
		if ( false === $parts ) {
			return array( $content );
		}

		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) preg_replace( '/^[\s\x{2022}\x{25CF}\x{25E6}\-\*•]+/u', '', trim( $part ) ) );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * Whether any query term appears verbatim (case-insensitive) in the text.
	 *
	 * @param string   $text  Candidate sentence/line.
	 * @param string[] $terms Query terms.
	 */
	private function contains_verbatim( string $text, array $terms ): bool {
		foreach ( $terms as $term ) {
			if ( mb_strlen( $term ) >= 3 && false !== mb_stripos( $text, $term ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Markdown lines for the fact slots, exact values only.
	 *
	 * @param array<string, array{value: string, source: string}> $slots Fact slots.
	 */
	private function fact_lines( array $slots ): string {
		$labels = array(
			'price'    => __( 'Price', 'agentyllo' ),
			'stock'    => __( 'Availability', 'agentyllo' ),
			'phone'    => __( 'Phone', 'agentyllo' ),
			'email'    => __( 'Email', 'agentyllo' ),
			'address'  => __( 'Address', 'agentyllo' ),
			'currency' => __( 'Currency', 'agentyllo' ),
		);

		$lines = array();
		foreach ( $labels as $key => $label ) {
			// A formatted price already carries the currency symbol.
			if ( 'currency' === $key && isset( $slots['price'] ) ) {
				continue;
			}
			$value = (string) ( $slots[ $key ]['value'] ?? '' );
			if ( '' !== $value ) {
				$lines[] = '**' . $label . ':** ' . $value;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Whether at least one retrieved chunk points at a linkable page.
	 *
	 * @param ChatContext $context Context.
	 */
	private function has_linkable_chunk( ChatContext $context ): bool {
		foreach ( $context->chunks as $chunk ) {
			if ( '' !== (string) ( $chunk['permalink'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classic always answers in the site language. When the detector saw a
	 * different major language confidently (>= 0.7 on >= 20 chars), prepend
	 * one courtesy notice line (pre-translated by the i18n system).
	 *
	 * @param ChatContext $context Context.
	 */
	private function maybe_language_notice( ChatContext $context ): void {
		$site_two = strtolower( substr( $context->site_lang ?: (string) get_locale(), 0, 2 ) );
		$detected = strtolower( (string) ( $context->meta['lang_detected'] ?? '' ) );

		// Show the notice whenever the message was (even tentatively) read as
		// another language and we are quoting site-language content: the
		// visitor deserves to know why the reply is not in their language.
		if (
			'' === $detected
			|| $detected === $site_two
			|| $context->lang_confidence < 0.4
			|| mb_strlen( $context->raw ) < 15
		) {
			return;
		}

		$context->add_block(
			array(
				'type'  => 'notice',
				'level' => 'info',
				'md'    => __( 'Just so you know: I reply in this site\'s language. I hope the answer below still helps!', 'agentyllo' ),
			)
		);
		$context->note( 'language_notice', $context->visitor_lang );
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
