<?php
/**
 * Copilot orchestration: message → proposal/answer, confirm → execute.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Copilot;

use Agentyllo\Compliance\Audit;
use Agentyllo\KB\Retrieval\HybridRetriever;

defined( 'ABSPATH' ) || exit;

/**
 * Proposal protocol: any input (slash command today, natural language via
 * the AI tool-calling later) can only PROPOSE an action. The proposal card
 * carries the validated args, a dry-run summary and — for destructive
 * actions — a single-use HMAC confirmation token (10-minute TTL, bound to
 * user + action + args hash). Execution happens exclusively on the human
 * click that returns that token. Every executed action is written to
 * agy_audit_log (who, what, args hash, result). Free-text questions in
 * classic mode are answered from the site's KB (same retriever the widget
 * uses) plus a short built-in help index.
 */
final class Copilot {

	private const TOKEN_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param ActionRegistry  $registry  Action registry.
	 * @param HybridRetriever $retriever KB retriever (free-text questions).
	 */
	public function __construct(
		private readonly ActionRegistry $registry,
		private readonly HybridRetriever $retriever,
	) {
	}

	/**
	 * Handle one admin message. Returns blocks for the drawer.
	 *
	 * @param string $text Message.
	 * @return array{blocks: array<int, array<string, mixed>>}
	 */
	public function handle( string $text ): array {
		$parsed = SlashParser::parse( $text );

		if ( null === $parsed ) {
			return array( 'blocks' => $this->answer_question( $text ) );
		}
		if ( $parsed['help'] || '' === $parsed['action'] ) {
			return array( 'blocks' => $this->help_blocks() );
		}

		return array( 'blocks' => $this->propose( $parsed['action'], $parsed['args'] ) );
	}

	/**
	 * Build a proposal (or execute immediately for non-destructive read-only
	 * actions the user is allowed to run).
	 *
	 * @param string               $action_id Action id.
	 * @param array<string, mixed> $raw_args  Raw args.
	 * @return array<int, array<string, mixed>>
	 */
	public function propose( string $action_id, array $raw_args ): array {
		$action = $this->registry->get( $action_id );
		if ( null === $action ) {
			return array( $this->text( sprintf( /* translators: %s: action id */ __( 'I do not know the action "%s". Type /help to see what I can do.', 'agentyllo' ), $action_id ) ) );
		}
		if ( ! $this->registry->allowed( $action_id ) ) {
			return array( $this->text( __( 'You do not have permission for that action.', 'agentyllo' ) ) );
		}

		// Tail text → the first missing required text argument (content/fact/value).
		if ( isset( $raw_args['_tail'] ) ) {
			foreach ( array( 'content', 'fact', 'value', 'title', 'key' ) as $candidate ) {
				if ( isset( $action['args'][ $candidate ] ) && ! isset( $raw_args[ $candidate ] ) ) {
					$raw_args[ $candidate ] = $raw_args['_tail'];
					break;
				}
			}
			unset( $raw_args['_tail'] );
		}

		[ $args, $errors ] = $this->registry->validate( $action_id, $raw_args );
		if ( $errors ) {
			return array(
				$this->text( implode( "\n", array_map( static fn ( string $e ): string => '• ' . $e, $errors ) ) . "\n\n" . sprintf( /* translators: %s: usage */ __( 'Usage: `%s`', 'agentyllo' ), ActionRegistry::usage( $action_id, (array) $action['args'] ) ) ),
			);
		}

		// Read-only, non-destructive: run right away.
		if ( empty( $action['destructive'] ) && in_array( $action_id, array( 'kb.list', 'settings.get', 'memory.query', 'stats.query' ), true ) ) {
			return array( $this->execute( $action_id, $args ) );
		}

		$dry = $this->registry->dry_run( $action_id, $args );

		return array(
			array(
				'type'        => 'action_proposal',
				'action'      => $action_id,
				'destructive' => (bool) $action['destructive'],
				'summary'     => $dry['summary'],
				'details'     => $dry['details'],
				'args'        => $args,
				'token'       => $this->token( $action_id, $args ),
			),
		);
	}

	/**
	 * Confirm + execute a proposal.
	 *
	 * @param string               $action_id Action id.
	 * @param array<string, mixed> $args      Args as returned in the proposal.
	 * @param string               $token     Confirmation token.
	 * @return array<string, mixed> action_result block.
	 */
	public function confirm( string $action_id, array $args, string $token ): array {
		if ( ! $this->registry->allowed( $action_id ) ) {
			return $this->result_block( $action_id, false, __( 'You do not have permission for that action.', 'agentyllo' ), array() );
		}
		[ $clean, $errors ] = $this->registry->validate( $action_id, $args );
		if ( $errors ) {
			return $this->result_block( $action_id, false, implode( ' ', $errors ), array() );
		}
		if ( ! $this->verify_token( $action_id, $clean, $token ) ) {
			return $this->result_block( $action_id, false, __( 'This confirmation expired or was already used — please repeat the command.', 'agentyllo' ), array() );
		}

		return $this->execute( $action_id, $clean );
	}

	/**
	 * Registry descriptions for the Help page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function describe_actions(): array {
		return $this->registry->describe();
	}

	/**
	 * Execute + audit.
	 *
	 * @param string               $action_id Action id.
	 * @param array<string, mixed> $args      Validated args.
	 * @return array<string, mixed>
	 */
	private function execute( string $action_id, array $args ): array {
		$result = $this->registry->run( $action_id, $args );

		Audit::log(
			'copilot.' . $action_id,
			isset( $args['id'] ) ? (string) $args['id'] : ( isset( $args['title'] ) ? (string) $args['title'] : null ),
			$args,
			$result['ok'] ? 'ok' : 'failed',
			mb_substr( $result['message'], 0, 500 )
		);

		return $this->result_block( $action_id, $result['ok'], $result['message'], $result['data'] );
	}

	/**
	 * Single-use HMAC token bound to user, action and args.
	 *
	 * @param string               $action_id Action id.
	 * @param array<string, mixed> $args      Args.
	 */
	private function token( string $action_id, array $args ): string {
		$nonce   = wp_generate_password( 12, false );
		$expires = time() + self::TOKEN_TTL;
		$payload = $nonce . '|' . $expires;
		$sig     = hash_hmac( 'sha256', $payload . '|' . $this->binding( $action_id, $args ), wp_salt( 'auth' ) );
		set_transient( 'agy_cp_' . $nonce, 1, self::TOKEN_TTL );

		return $payload . '|' . $sig;
	}

	/**
	 * Verify and consume a token.
	 *
	 * @param string               $action_id Action id.
	 * @param array<string, mixed> $args      Args.
	 * @param string               $token     Token.
	 */
	private function verify_token( string $action_id, array $args, string $token ): bool {
		$parts = explode( '|', $token );
		if ( 3 !== count( $parts ) ) {
			return false;
		}
		[ $nonce, $expires, $sig ] = $parts;
		if ( (int) $expires < time() || ! preg_match( '/^[A-Za-z0-9]{12}$/', $nonce ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $nonce . '|' . $expires . '|' . $this->binding( $action_id, $args ), wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return false;
		}
		if ( ! get_transient( 'agy_cp_' . $nonce ) ) {
			return false; // Already consumed or never issued.
		}
		delete_transient( 'agy_cp_' . $nonce );

		return true;
	}

	/**
	 * Token binding material.
	 *
	 * @param string               $action_id Action id.
	 * @param array<string, mixed> $args      Args.
	 */
	private function binding( string $action_id, array $args ): string {
		ksort( $args );

		return get_current_user_id() . '|' . $action_id . '|' . sha1( (string) wp_json_encode( $args ) );
	}

	/**
	 * Free-text question: search the site KB + help hints.
	 *
	 * @param string $text Question.
	 * @return array<int, array<string, mixed>>
	 */
	private function answer_question( string $text ): array {
		$blocks = array();
		$hits   = $this->retriever->search( $text, array( 'limit' => 3 ) );
		$top    = $hits[0] ?? null;

		if ( $top && (float) ( $top['coverage'] ?? 0 ) >= 0.34 ) {
			$blocks[] = $this->text( sprintf( /* translators: %s: page title */ __( 'From your site content ("%s"):', 'agentyllo' ), wp_specialchars_decode( (string) $top['title'], ENT_QUOTES ) ) . "\n\n" . mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', (string) $top['content'] ) ), 0, 500 ) );
			$items = array();
			foreach ( $hits as $hit ) {
				if ( '' !== (string) ( $hit['permalink'] ?? '' ) ) {
					$items[] = array(
						'title' => wp_specialchars_decode( (string) $hit['title'], ENT_QUOTES ),
						'url'   => (string) $hit['permalink'],
					);
				}
			}
			if ( $items ) {
				$blocks[] = array(
					'type'  => 'links',
					'items' => array_slice( $items, 0, 3 ),
				);
			}

			return $blocks;
		}

		$blocks[] = $this->text( __( 'I could not find that in your site content. I can run commands for you — try `/help`, or ask e.g. `/kb add title:"Opening hours" content:"We are open Mon–Fri 9–18"`.', 'agentyllo' ) );

		return $blocks;
	}

	/**
	 * Help listing from the registry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function help_blocks(): array {
		$lines = array( __( 'Here is what I can do (every change is proposed first and applied only when you confirm):', 'agentyllo' ), '' );
		foreach ( $this->registry->describe() as $action ) {
			if ( ! $this->registry->allowed( $action['id'] ) ) {
				continue;
			}
			$lines[] = '• `' . $action['usage'] . '` — ' . $action['description'] . ( $action['destructive'] ? ' ' . __( '(asks for confirmation)', 'agentyllo' ) : '' );
		}
		$lines[] = '';
		$lines[] = __( 'Anything else you type is answered from your site content.', 'agentyllo' );

		return array( $this->text( implode( "\n", $lines ) ) );
	}

	/**
	 * Text block.
	 *
	 * @param string $md Markdown.
	 * @return array<string, mixed>
	 */
	private function text( string $md ): array {
		return array(
			'type' => 'text',
			'md'   => $md,
		);
	}

	/**
	 * action_result block.
	 *
	 * @param string $action_id Action.
	 * @param bool   $ok        Success.
	 * @param string $message   Message.
	 * @param array  $data      Data.
	 * @return array<string, mixed>
	 */
	private function result_block( string $action_id, bool $ok, string $message, array $data ): array {
		return array(
			'type'    => 'action_result',
			'action'  => $action_id,
			'ok'      => $ok,
			'message' => $message,
			'data'    => $data,
		);
	}
}
