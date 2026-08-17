<?php
/**
 * AI compose stage: RAG answer from a cloud/local provider, fact-guarded.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Stages;

use Agentyllo\AI\Budget\Manager;
use Agentyllo\AI\Budget\ResponseCache;
use Agentyllo\AI\Contracts\ChatResult;
use Agentyllo\AI\Prompt\ChatPromptBuilder;
use Agentyllo\AI\Prompt\FactGuard;
use Agentyllo\AI\ProviderRouter;
use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Stage;

defined( 'ABSPATH' ) || exit;

/**
 * Runs after the scope guard and before the classic composer. When the
 * operating mode allows an AI tier and a provider is usable, it composes the
 * answer from the retrieved sources + fact slots (RAG with [#n] citations),
 * streaming deltas to the transport sink when one is present. Every generated
 * answer passes FactGuard: an ungrounded price/phone/email discards the AI
 * text and lets the classic composer answer — the invariant holds
 * structurally, not by prompt hope. Any provider failure degrades the same
 * way; the classic floor is never bypassed.
 *
 * Hybrid modes (classic_*_ai) keep the deterministic intents (greeting,
 * navigation, contact, price cards, handoff) on the classic path and use the
 * model where prose adds value (site_info, product_query, hours_policy,
 * smalltalk, unknown). AI-only modes let the model compose everything except
 * refusals and handoffs.
 */
final class AiComposeStage implements Stage {

	private const HYBRID_AI_INTENTS = array( 'site_info', 'product_query', 'hours_policy', 'smalltalk', 'unknown' );
	private const CITATION          = '/\s?\[#(\d{1,2})\]/';

	/**
	 * Resolver returning the 'general' settings array.
	 *
	 * @var callable
	 */
	private $general_resolver;

	/**
	 * Resolver returning the 'language' settings array.
	 *
	 * @var callable
	 */
	private $language_resolver;

	/**
	 * Resolver returning the 'privacy' settings array.
	 *
	 * @var callable
	 */
	private $privacy_resolver;

	/**
	 * Resolver returning the 'models' settings array.
	 *
	 * @var callable
	 */
	private $models_resolver;

	/**
	 * Constructor.
	 *
	 * @param ProviderRouter    $router            Provider router.
	 * @param Manager           $budget            Budget manager.
	 * @param ResponseCache     $cache             Response cache.
	 * @param ChatPromptBuilder $prompts           Prompt builder.
	 * @param callable          $general_resolver  Returns 'general' settings.
	 * @param callable          $language_resolver Returns 'language' settings.
	 * @param callable          $privacy_resolver  Returns 'privacy' settings.
	 * @param callable          $models_resolver   Returns 'models' settings.
	 */
	public function __construct(
		private readonly ProviderRouter $router,
		private readonly Manager $budget,
		private readonly ResponseCache $cache,
		private readonly ChatPromptBuilder $prompts,
		callable $general_resolver,
		callable $language_resolver,
		callable $privacy_resolver,
		callable $models_resolver
	) {
		$this->general_resolver  = $general_resolver;
		$this->language_resolver = $language_resolver;
		$this->privacy_resolver  = $privacy_resolver;
		$this->models_resolver   = $models_resolver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ai_compose';
	}

	/**
	 * {@inheritDoc}
	 */
	public function status_event(): string {
		// Only announce "generating" when this stage will actually run — the
		// classic path must not show an AI state it never entered.
		return $this->router->ai_enabled() ? 'generating' : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( ChatContext $context ): void {
		if ( in_array( $context->route, array( ChatContext::ROUTE_REFUSE, ChatContext::ROUTE_HANDOFF ), true ) ) {
			return;
		}
		if ( ! $this->router->ai_enabled() ) {
			return;
		}
		if ( $this->router->is_hybrid() && ! in_array( $context->intent, self::HYBRID_AI_INTENTS, true ) ) {
			$context->note( 'ai_skipped', 'hybrid_classic_intent' );

			return;
		}
		// Nothing to ground on and not a conversational intent: the classic
		// honesty fallback is the better (and free) answer.
		if ( ! $context->chunks && ! $context->fact_slots && ! in_array( $context->intent, array( 'greeting', 'smalltalk' ), true ) ) {
			$context->note( 'ai_skipped', 'no_grounding' );

			return;
		}

		$provider = $this->router->pick();
		if ( null === $provider ) {
			$context->note( 'ai_skipped', $this->router->status()['reason'] );

			return;
		}

		// Local engines: chat composition only when the measured speed clears
		// the T3 gate (default 8 tok/s); slower hosts keep the model for
		// bounded tasks (query rewriting) and answer with the classic floor.
		$is_local = 'local' === (string) ( $provider->capabilities()['tier'] ?? 'cloud' );
		if ( $is_local ) {
			$models  = $this->resolve( $this->models_resolver );
			$min_tps = (float) ( $models['local_min_tok_s'] ?? 8 );
			$ema     = $this->budget->ema_tps( $provider->id() );
			if ( $ema > 0.0 && $ema < $min_tps ) {
				$context->note( 'ai_skipped', 'local_too_slow' );
				$context->note( 'ai_ema_tps', $ema );

				return;
			}
		}

		$general    = $this->resolve( $this->general_resolver );
		$privacy    = $this->resolve( $this->privacy_resolver );
		$models     = $this->resolve( $this->models_resolver );
		$reply_lang = $this->reply_language( $context );
		$redaction  = (string) ( $privacy['pii_redaction'] ?? 'logs' );
		$history    = is_array( $context->meta['history'] ?? null ) ? $context->meta['history'] : array();
		$elapsed    = (float) ( $context->meta['elapsed_s'] ?? 0.0 );
		$max_tokens = max( 100, (int) ( $models['max_output_tokens'] ?? 600 ) );

		$request = $this->prompts->build(
			$context,
			$general,
			$reply_lang,
			$history,
			$redaction,
			$max_tokens,
			$this->budget->request_budget_s( $elapsed )
		);

		$model_def = method_exists( $provider, 'model' ) ? $provider->model() : null;
		$model_id  = is_array( $model_def ) ? (string) ( $model_def['id'] ?? '' ) : '';
		$version   = $this->prompts->version();
		$facts     = $this->fact_values( $context );
		$sink      = is_callable( $context->meta['stream_sink'] ?? null ) ? $context->meta['stream_sink'] : null;

		$cache_key = ResponseCache::key( $provider->id(), $model_id, $version, $reply_lang ?: $context->site_lang, $context->text, sha1( implode( '|', $facts ) ) );
		$cached    = $this->cache->get( $cache_key );
		if ( null !== $cached && '' !== $cached['text'] ) {
			if ( null !== $sink ) {
				$sink( $cached['text'] );
			}
			$this->apply_answer( $context, $cached['text'], $reply_lang );
			$context->note( 'ai_cached', true );
			$context->note( 'ai_provider', $cached['provider'] );
			$context->note( 'ai_model', $cached['model'] );
			$context->note( 'prompt_version', $version );

			return;
		}

		$sources_text = ChatPromptBuilder::sources_text( $context );
		$accumulated  = '';
		$violation    = false;

		$on_delta = static function ( string $delta ) use ( &$accumulated, &$violation, $sink, $facts, $sources_text ): bool {
			$accumulated .= $delta;
			// Verify only the stable prefix (up to the last whitespace) so a
			// half-typed amount is never judged.
			$boundary = max( (int) mb_strrpos( $accumulated, ' ' ), (int) mb_strrpos( $accumulated, "\n" ) );
			if ( $boundary > 0 ) {
				$stable = mb_substr( $accumulated, 0, $boundary );
				if ( ! FactGuard::verify( $stable, $facts, $sources_text )['ok'] ) {
					$violation = true;

					return false; // Abort the stream: the classic composer answers.
				}
			}
			if ( null !== $sink ) {
				$sink( $delta );
			}

			return true;
		};

		// Local engines run one inference at a time on the host (GET_LOCK);
		// a busy engine yields to the classic floor instead of queueing.
		if ( $is_local && ! $this->budget->acquire_local_lock( $provider->id(), 2 ) ) {
			$context->note( 'ai_skipped', 'local_busy' );

			return;
		}
		try {
			$result = null !== $sink ? $provider->stream( $request, $on_delta ) : $provider->complete( $request );
		} finally {
			if ( $is_local ) {
				$this->budget->release_local_lock( $provider->id() );
			}
		}
		$cost = $this->budget->record( $result, $request, null !== $sink );

		$context->note( 'ai_provider', $result->provider );
		$context->note( 'ai_model', $result->model );
		$context->note( 'ai_latency_ms', $result->latency_ms );
		$context->note( 'ai_tokens_in', $result->tokens_in );
		$context->note( 'ai_tokens_out', $result->tokens_out );
		$context->note( 'ai_cost_usd', $cost );
		$context->note( 'prompt_version', $version );

		if ( $violation ) {
			$context->note( 'ai_fact_violation', true );
			$context->note( 'ai_failed', 'fact_guard' );
			$this->signal_reset( $sink );

			return;
		}
		if ( ! $result->ok || '' === trim( $result->text ) ) {
			$context->note( 'ai_failed', (string) ( $result->error ?? 'empty' ) );
			$this->signal_reset( $sink );

			return;
		}

		$guard = FactGuard::verify( $result->text, $facts, $sources_text );
		if ( ! $guard['ok'] ) {
			$context->note( 'ai_fact_violation', $guard['violations'] );
			$context->note( 'ai_failed', 'fact_guard' );
			$this->signal_reset( $sink );

			return;
		}

		$this->apply_answer( $context, $guard['text'], $reply_lang );
		if ( 'length' === $result->finish ) {
			$context->note( 'ai_truncated', true );
		}

		if ( '' !== $model_id && 'aborted' !== $result->finish ) {
			$this->cache->put( $cache_key, $result->provider, $result->model, $version, $guard['text'], $context->blocks );
		}
	}

	/**
	 * Turn verified model text into blocks + citation metadata.
	 *
	 * @param ChatContext $context    Context.
	 * @param string      $text       Verified text (URLs already stripped).
	 * @param string      $reply_lang Reply language.
	 */
	private function apply_answer( ChatContext $context, string $text, string $reply_lang ): void {
		$sources = is_array( $context->meta['ai_sources'] ?? null ) ? $context->meta['ai_sources'] : array();
		$cited   = array();

		$clean = (string) preg_replace_callback(
			self::CITATION,
			static function ( array $m ) use ( &$cited, $sources ): string {
				$n = (int) $m[1];
				if ( isset( $sources[ $n ] ) ) {
					$doc = (int) $sources[ $n ]['document_id'];
					if ( ! in_array( $doc, $cited, true ) ) {
						$cited[] = $doc;
					}
				}

				return '';
			},
			$text
		);
		$clean = trim( (string) preg_replace( '/[ \t]+([.,;:!?])/u', '$1', $clean ) );

		// Site-language reply for a foreign-language visitor: same courtesy
		// notice the classic composer shows.
		$site_two = strtolower( substr( $context->site_lang ?: (string) get_locale(), 0, 2 ) );
		$detected = strtolower( (string) ( $context->meta['lang_detected'] ?? '' ) );
		if ( '' !== $detected && $detected !== $site_two && '' === $reply_lang && $context->lang_confidence >= 0.4 && mb_strlen( $context->raw ) >= 15 ) {
			$context->add_block(
				array(
					'type'  => 'notice',
					'level' => 'info',
					'md'    => __( 'Just so you know: I reply in this site\'s language. I hope the answer below still helps!', 'agentyllo' ),
				)
			);
		}

		$context->add_block(
			array(
				'type' => 'text',
				'md'   => $clean,
			)
		);
		$context->route = ChatContext::ROUTE_AI_RAG;
		$context->note( 'ai_composed', true );
		$context->note( 'ai_cited', $cited );
		$context->note( 'answered', true );
	}

	/**
	 * Tell the transport to discard streamed preview text (the final message
	 * will differ from what the visitor saw so far).
	 *
	 * @param callable|null $sink Stream sink.
	 */
	private function signal_reset( ?callable $sink ): void {
		if ( null !== $sink ) {
			$sink( '', 'reset' );
		}
	}

	/**
	 * Fact-slot values as a flat list.
	 *
	 * @param ChatContext $context Context.
	 * @return string[]
	 */
	private function fact_values( ChatContext $context ): array {
		$out = array();
		foreach ( $context->fact_slots as $slot ) {
			$value = trim( (string) ( $slot['value'] ?? '' ) );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}

		return $out;
	}

	/**
	 * Two-letter reply language per the language settings ('' = site).
	 *
	 * @param ChatContext $context Context.
	 */
	private function reply_language( ChatContext $context ): string {
		$language = $this->resolve( $this->language_resolver );
		$mode     = (string) ( $language['reply_language_mode'] ?? 'site_language' );
		$site_two = strtolower( substr( $context->site_lang ?: (string) get_locale(), 0, 2 ) );

		if ( 'fixed' === $mode ) {
			$fixed = strtolower( substr( (string) ( $language['fixed_locale'] ?? '' ), 0, 2 ) );

			return '' !== $fixed && $fixed !== $site_two ? $fixed : '';
		}
		if ( 'visitor_language' === $mode ) {
			$detected = strtolower( (string) ( $context->meta['lang_detected'] ?? $context->visitor_lang ) );
			if ( '' !== $detected && $detected !== $site_two && $context->lang_confidence >= 0.4 ) {
				return $detected;
			}
		}

		return '';
	}

	/**
	 * Resolve a settings array.
	 *
	 * @param callable $resolver Resolver.
	 * @return array<string, mixed>
	 */
	private function resolve( callable $resolver ): array {
		$values = $resolver();

		return is_array( $values ) ? $values : array();
	}
}
