<?php
/**
 * AI reasoning for the backend Copilot.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Copilot;

use Agentyllo\AI\Contracts\ChatRequest;
use Agentyllo\AI\ProviderRouter;
use Agentyllo\KB\Retrieval\HybridRetriever;

defined( 'ABSPATH' ) || exit;

/**
 * When a chat AI provider is connected and the owner leaves Copilot-AI on,
 * this turns a plain-English admin message into EITHER a grounded answer
 * (built from the site's own knowledge base + the model's general knowledge)
 * OR a single PROPOSED action drawn from the action registry.
 *
 * Safety is unchanged: the model can only choose an action + args; it never
 * executes. The choice flows back through Copilot::propose(), which keeps the
 * dry-run card, the single-use HMAC confirm token and the audit log. Structured
 * JSON output (json_schema when the provider supports it, tolerant parse
 * otherwise) keeps the contract provider-agnostic — no vendor tool-calling API.
 */
final class CopilotBrain {

	private const MAX_KB_HITS   = 5;
	private const MAX_KB_CHARS  = 700;
	private const MAX_HISTORY   = 6;

	/**
	 * Constructor.
	 *
	 * @param ProviderRouter  $router    Provider router (any_available()).
	 * @param HybridRetriever $retriever KB retriever for grounding.
	 * @param ActionRegistry  $registry  Action registry (proposable actions).
	 * @param callable        $general   Returns the 'general' settings array.
	 */
	public function __construct(
		private readonly ProviderRouter $router,
		private readonly HybridRetriever $retriever,
		private readonly ActionRegistry $registry,
		private $general
	) {
	}

	/**
	 * Whether the AI copilot path should run: the owner left it on AND a
	 * provider is usable right now.
	 */
	public function available(): bool {
		$general = ( $this->general )();
		$on      = ! is_array( $general ) || (bool) ( $general['copilot_use_ai'] ?? true );

		return $on && null !== $this->router->any_available();
	}

	/**
	 * Reason over one message. Returns a normalized decision:
	 *   ['kind' => 'answer', 'answer' => md, 'links' => [...]]
	 *   ['kind' => 'action', 'action_id' => id, 'args' => [...]]
	 *   ['kind' => 'none']  (AI unusable / failed → caller falls back to classic)
	 *
	 * @param string                                       $text    Admin message.
	 * @param array<int, array{role: string, text: string}> $history Prior turns.
	 * @return array<string, mixed>
	 */
	public function reason( string $text, array $history = array() ): array {
		$provider = $this->router->any_available();
		if ( null === $provider ) {
			return array( 'kind' => 'none' );
		}

		$hits    = $this->retriever->search( $text, array( 'limit' => self::MAX_KB_HITS ) );
		$grounding = $this->grounding_block( $hits );
		$actions   = $this->action_catalogue();

		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'mode' ),
			'properties'           => array(
				'mode'      => array( 'type' => 'string', 'enum' => array( 'answer', 'action' ) ),
				'answer'    => array( 'type' => 'string' ),
				'action_id' => array( 'type' => 'string' ),
				'args'      => array( 'type' => 'object' ),
			),
		);

		$system = $this->system_prompt( $actions );
		$user   = "SITE KNOWLEDGE (only source of site-specific facts; may be empty):\n"
			. ( '' === $grounding ? "(nothing relevant found)\n" : $grounding )
			. "\nADMIN MESSAGE:\n" . $text
			. "\n\nReply with JSON only, following the schema.";

		$messages   = $this->history_messages( $history );
		$messages[] = array( 'role' => 'user', 'content' => $user );

		$request = new ChatRequest(
			$messages,
			$system,
			700,
			0.2,
			ChatRequest::TASK_CHAT,
			'',
			$schema,
			20.0,
			array( 'source' => 'copilot' )
		);

		$result = $provider->complete( $request );
		if ( ! $result->ok || '' === trim( $result->text ) ) {
			return array( 'kind' => 'none' );
		}

		$decision = $this->parse( $result->text );
		if ( null === $decision ) {
			// Model answered in prose despite the schema — treat as an answer.
			return array( 'kind' => 'answer', 'answer' => trim( $result->text ), 'links' => $this->links( $hits ) );
		}

		if ( 'action' === ( $decision['mode'] ?? '' ) && '' !== (string) ( $decision['action_id'] ?? '' ) ) {
			return array(
				'kind'      => 'action',
				'action_id' => (string) $decision['action_id'],
				'args'      => is_array( $decision['args'] ?? null ) ? $decision['args'] : array(),
			);
		}

		return array(
			'kind'   => 'answer',
			'answer' => trim( (string) ( $decision['answer'] ?? '' ) ),
			'links'  => $this->links( $hits ),
		);
	}

	/**
	 * System prompt: role, grounding rule, and the action menu.
	 *
	 * @param string $actions Rendered action catalogue.
	 */
	private function system_prompt( string $actions ): string {
		return "You are Agentyllo's admin Copilot inside WordPress wp-admin. You help the SITE OWNER manage their site and its AI assistant. You are knowledgeable, concise and proactive.\n\n"
			. "Decide between two modes:\n"
			. "- \"answer\": reply to the owner. Ground every site-specific fact ONLY in the provided SITE KNOWLEDGE; never invent site facts, prices, URLs or policies. For general WordPress/plugin/how-to questions you may use your own knowledge. Answer in the owner's language. Use short Markdown.\n"
			. "- \"action\": when the owner clearly wants you to DO something you can do, choose ONE action from the list below and fill its args from the message. Do NOT execute — the owner confirms it. Prefer \"answer\" when unsure.\n\n"
			. "AVAILABLE ACTIONS (id — description — args):\n" . $actions . "\n"
			. "Return JSON only: {\"mode\":\"answer\",\"answer\":\"...\"} or {\"mode\":\"action\",\"action_id\":\"kb.add_entry\",\"args\":{...}}.";
	}

	/**
	 * Compact catalogue of the actions this user may propose.
	 */
	private function action_catalogue(): string {
		$lines = array();
		foreach ( $this->registry->describe() as $a ) {
			if ( ! $this->registry->allowed( (string) $a['id'] ) ) {
				continue;
			}
			$args = array();
			foreach ( (array) ( $a['args'] ?? array() ) as $name => $spec ) {
				$args[] = $name . ( ! empty( $spec['required'] ) ? '*' : '' );
			}
			$lines[] = '- ' . $a['id'] . ' — ' . $a['description'] . ' — args: ' . ( $args ? implode( ', ', $args ) : '(none)' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Grounding text from KB hits.
	 *
	 * @param array<int, array<string, mixed>> $hits Retriever hits.
	 */
	private function grounding_block( array $hits ): string {
		$out = array();
		foreach ( $hits as $i => $hit ) {
			$title   = wp_specialchars_decode( (string) ( $hit['title'] ?? '' ), ENT_QUOTES );
			$content = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $hit['content'] ?? '' ) ) );
			if ( '' === $content ) {
				continue;
			}
			$out[] = '[' . ( $i + 1 ) . '] ' . $title . ': ' . mb_substr( $content, 0, self::MAX_KB_CHARS );
		}

		return implode( "\n", $out );
	}

	/**
	 * Source links for an answer.
	 *
	 * @param array<int, array<string, mixed>> $hits Retriever hits.
	 * @return array<int, array{title: string, url: string}>
	 */
	private function links( array $hits ): array {
		$items = array();
		foreach ( $hits as $hit ) {
			$url = (string) ( $hit['permalink'] ?? '' );
			if ( '' !== $url ) {
				$items[] = array(
					'title' => wp_specialchars_decode( (string) ( $hit['title'] ?? '' ), ENT_QUOTES ),
					'url'   => $url,
				);
			}
			if ( count( $items ) >= 3 ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Prior drawer turns → provider messages.
	 *
	 * @param array<int, array{role: string, text: string}> $history History.
	 * @return array<int, array{role: string, content: string}>
	 */
	private function history_messages( array $history ): array {
		$out = array();
		foreach ( array_slice( $history, -self::MAX_HISTORY ) as $turn ) {
			$role = ( 'assistant' === ( $turn['role'] ?? '' ) ) ? 'assistant' : 'user';
			$text = trim( (string) ( $turn['text'] ?? '' ) );
			if ( '' !== $text ) {
				$out[] = array( 'role' => $role, 'content' => mb_substr( $text, 0, 1500 ) );
			}
		}

		return $out;
	}

	/**
	 * Parse a JSON object from model output (tolerant of code fences / prose).
	 *
	 * @param string $text Raw model text.
	 * @return array<string, mixed>|null
	 */
	private function parse( string $text ): ?array {
		$text = trim( $text );
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}
}
