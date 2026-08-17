<?php
/**
 * T2 bounded task: rewrite a weak query into search keywords.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Tasks;

use Agentyllo\AI\Budget\Manager;
use Agentyllo\AI\Contracts\ChatRequest;
use Agentyllo\AI\ProviderRouter;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded (≤ 48 tokens, ≤ 6 s) generation that never writes user-facing
 * text: it turns a chatty or paraphrased question into 3–8 site-language
 * keywords the lexical retriever can match. Runs only when a provider is
 * available and the first retrieval pass was weak; a slow/failed rewrite
 * simply keeps the original query. Local engines too slow for chat (T2 in
 * the ladder) are exactly the right size for this.
 */
final class QueryRewriter {

	private const MAX_TOKENS = 48;
	private const BUDGET_S   = 6.0;

	/**
	 * Constructor.
	 *
	 * @param ProviderRouter $router Provider router.
	 * @param Manager        $budget Budget manager.
	 */
	public function __construct(
		private readonly ProviderRouter $router,
		private readonly Manager $budget,
	) {
	}

	/**
	 * Whether a provider can run bounded tasks right now.
	 */
	public function available(): bool {
		return null !== $this->router->pick();
	}

	/**
	 * Rewrite. Returns '' when unavailable/failed/unchanged.
	 *
	 * @param string $question Visitor question (already normalized).
	 * @param string $lang     Site language (locale or two-letter).
	 */
	public function rewrite( string $question, string $lang ): string {
		$provider = $this->router->pick();
		if ( null === $provider || mb_strlen( $question ) < 12 ) {
			return '';
		}
		$is_local = 'local' === (string) ( $provider->capabilities()['tier'] ?? 'cloud' );
		if ( $is_local && ! $this->budget->acquire_local_lock( $provider->id(), 1 ) ) {
			return '';
		}

		$request = new ChatRequest(
			array(
				array(
					'role'    => 'user',
					'content' => 'Question: ' . mb_substr( $question, 0, 400 ) . "\nKeywords:",
				),
			),
			'Extract 3-8 search keywords from the visitor question, in the same language as the question ('
				. substr( $lang, 0, 2 ) . '). Output ONLY the keywords separated by spaces — no sentences, no punctuation, no explanations.',
			self::MAX_TOKENS,
			0.0,
			ChatRequest::TASK_REWRITE,
			substr( $lang, 0, 2 ),
			null,
			min( self::BUDGET_S, $this->budget->request_budget_s() )
		);

		try {
			$result = $provider->complete( $request );
		} finally {
			if ( $is_local ) {
				$this->budget->release_local_lock( $provider->id() );
			}
		}
		$this->budget->record( $result, $request, false );

		if ( ! $result->ok ) {
			return '';
		}
		$keywords = trim( (string) preg_replace( '/[^\p{L}\p{N}\s\-]/u', ' ', $result->text ) );
		$keywords = trim( (string) preg_replace( '/\s+/u', ' ', $keywords ) );
		if ( '' === $keywords || mb_strtolower( $keywords ) === mb_strtolower( $question ) ) {
			return '';
		}
		$words = explode( ' ', $keywords );
		if ( count( $words ) > 10 ) {
			$keywords = implode( ' ', array_slice( $words, 0, 10 ) );
		}

		return $keywords;
	}
}
