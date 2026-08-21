<?php
/**
 * Conversation and message persistence for the chat surfaces.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * One open conversation per session: a session's messages append to the same
 * agy_conversations row while it stays active (last activity within 1 hour);
 * after that, a new conversation starts. Message rows carry the block JSON
 * plus flat analytics columns (intent, confidence, tier, latency) so the M6
 * stats rollups never need to parse JSON. Feedback lives in the conversation
 * meta JSON (key 'feedback') — the messages table has no meta column, and a
 * conversation-level journal keeps ratings alongside their transcript.
 */
final class ConversationLog {

	/**
	 * Find the session's open conversation (last activity within 1 hour) or
	 * create a fresh one. Returns the conversation id, or 0 on DB failure.
	 *
	 * @param int         $session_id Session row id.
	 * @param string      $lang       Conversation language (locale or 2-letter code).
	 * @param string|null $ip_hash    Hashed visitor IP (never raw).
	 */
	public function start_or_get( int $session_id, string $lang, ?string $ip_hash, array $identity = array() ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$open = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'agy_conversations WHERE session_id = %d AND last_activity_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1',
				$session_id
			)
		);

		if ( $open ) {
			return (int) $open;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'agy_conversations',
			array(
				'uuid'             => wp_generate_uuid4(),
				'session_id'       => $session_id,
				'visitor_name'     => isset( $identity['visitor_name'] ) && '' !== $identity['visitor_name'] ? substr( (string) $identity['visitor_name'], 0, 190 ) : null,
				'visitor_email'    => isset( $identity['visitor_email'] ) && '' !== $identity['visitor_email'] ? substr( (string) $identity['visitor_email'], 0, 190 ) : null,
				'consent_id'       => isset( $identity['consent_id'] ) ? (int) $identity['consent_id'] : null,
				'lang'             => substr( $lang, 0, 12 ),
				'tier'             => 'classic',
				'source'           => 'widget',
				'ip_hash'          => $ip_hash,
				'message_count'    => 0,
				'started_at'       => $now,
				'last_activity_at' => $now,
			)
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * The session's open conversation id (last activity within 1 hour), or 0.
	 *
	 * @param int $session_id Session row id.
	 */
	public function open_id( int $session_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$open = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'agy_conversations WHERE session_id = %d AND last_activity_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1',
				$session_id
			)
		);

		return (int) $open;
	}

	/**
	 * Recent user/assistant turns of a conversation, oldest first — the
	 * short-term memory handed to AI tiers. Content is the stored plain text
	 * (already PII-redacted per the logs policy).
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $limit           Max turns.
	 * @return array<int, array{role: string, content: string}>
	 */
	public function recent_turns( int $conversation_id, int $limit = 6 ): array {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT role, content FROM ' . $wpdb->prefix . "agy_messages WHERE conversation_id = %d AND role IN ('user', 'assistant') ORDER BY id DESC LIMIT %d",
				$conversation_id,
				max( 1, min( 20, $limit ) )
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$turns = array();
		foreach ( array_reverse( $rows ) as $row ) {
			$turns[] = array(
				'role'    => (string) $row['role'],
				'content' => (string) $row['content'],
			);
		}

		return $turns;
	}

	/**
	 * Persist one message and bump the conversation counters.
	 *
	 * Content is the plain-text rendering (user role: the raw text;
	 * assistant role: concatenated text-block markdown — see
	 * blocks_to_text()). Meta keys read: tier, intent, confidence,
	 * latency_ms, kb_sources (document ids), lang (bumps the conversation
	 * language when non-empty), answered (=== false flags the row
	 * unanswered for the learning loop).
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $role            'user' or 'assistant'.
	 * @param string $content         Plain-text content.
	 * @param array  $blocks          Response blocks (shared block schema), empty for user rows.
	 * @param array  $meta            Analytics meta (see above).
	 * @return int Message row id, 0 on DB failure.
	 */
	public function log_message( int $conversation_id, string $role, string $content, array $blocks, array $meta ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'agy_messages',
			array(
				'conversation_id'    => $conversation_id,
				'role'               => substr( $role, 0, 12 ),
				'content'            => $content,
				'blocks'             => $blocks ? (string) wp_json_encode( $blocks ) : null,
				'tier'               => substr( (string) ( $meta['tier'] ?? 'classic' ), 0, 20 ),
				'intent'             => isset( $meta['intent'] ) ? substr( (string) $meta['intent'], 0, 100 ) : null,
				'confidence'         => isset( $meta['confidence'] ) ? round( (float) $meta['confidence'], 3 ) : null,
				'kb_sources'         => empty( $meta['kb_sources'] ) ? null : (string) wp_json_encode( array_values( (array) $meta['kb_sources'] ) ),
				'latency_ms'         => isset( $meta['latency_ms'] ) ? max( 0, (int) $meta['latency_ms'] ) : null,
				'model'              => isset( $meta['model'] ) && '' !== (string) $meta['model'] ? substr( (string) $meta['model'], 0, 80 ) : null,
				'prompt_version'     => isset( $meta['prompt_version'] ) && '' !== (string) $meta['prompt_version'] ? substr( (string) $meta['prompt_version'], 0, 20 ) : null,
				'tokens_in'          => isset( $meta['tokens_in'] ) ? max( 0, (int) $meta['tokens_in'] ) : null,
				'tokens_out'         => isset( $meta['tokens_out'] ) ? max( 0, (int) $meta['tokens_out'] ) : null,
				'cost_usd'           => isset( $meta['cost_usd'] ) ? round( (float) $meta['cost_usd'], 6 ) : null,
				'flagged_unanswered' => ( array_key_exists( 'answered', $meta ) && false === $meta['answered'] ) ? 1 : 0,
				'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( false === $inserted ) {
			return 0;
		}

		$message_id = (int) $wpdb->insert_id;

		$sets = 'message_count = message_count + 1, last_activity_at = UTC_TIMESTAMP()';
		$args = array();
		$lang = isset( $meta['lang'] ) ? substr( (string) $meta['lang'], 0, 12 ) : '';
		if ( '' !== $lang ) {
			$sets  .= ', lang = %s';
			$args[] = $lang;
		}
		// A handoff turn marks the whole conversation (deflection metric).
		if ( 'assistant' === $role && 'handoff' === (string) ( $meta['intent'] ?? '' ) ) {
			$sets .= ', handoff = 1';
		}
		// A conversation that ever used an AI tier is reported under that tier.
		$tier = (string) ( $meta['tier'] ?? 'classic' );
		if ( 'assistant' === $role && 'classic' !== $tier && '' !== $tier ) {
			$sets  .= ', tier = %s';
			$args[] = substr( $tier, 0, 20 );
		}
		$args[] = $conversation_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'agy_conversations SET ' . $sets . ' WHERE id = %d', ...$args ) );

		return $message_id;
	}

	/**
	 * Append a feedback entry ({message_id, rating, comment, at}) to the
	 * conversation meta JSON under key 'feedback'.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param int    $message_id      Rated message id.
	 * @param string $rating          'up' or 'down'.
	 * @param string $comment         Optional free-text comment (truncated to 500 chars).
	 * @return bool Whether the write succeeded.
	 */
	public function add_feedback( int $conversation_id, int $message_id, string $rating, string $comment = '' ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw = $wpdb->get_var(
			$wpdb->prepare( 'SELECT meta FROM ' . $wpdb->prefix . 'agy_conversations WHERE id = %d', $conversation_id )
		);

		$meta             = json_decode( (string) $raw, true );
		$meta             = is_array( $meta ) ? $meta : array();
		$meta['feedback'] = isset( $meta['feedback'] ) && is_array( $meta['feedback'] ) ? $meta['feedback'] : array();

		$meta['feedback'][] = array(
			'message_id' => $message_id,
			'rating'     => 'down' === $rating ? 'down' : 'up',
			'comment'    => mb_substr( $comment, 0, 500 ),
			'at'         => gmdate( 'c' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'agy_conversations',
			array( 'meta' => (string) wp_json_encode( $meta ) ),
			array( 'id' => $conversation_id )
		);

		return false !== $updated;
	}

	/**
	 * Ownership lookup for a message: which conversation and session does it
	 * belong to? Used to scope feedback writes to the caller's own session.
	 *
	 * @param int $message_id Message row id.
	 * @return array{conversation_id: int, session_id: int}|null
	 */
	public function conversation_for_message( int $message_id ): ?array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.conversation_id, c.session_id FROM {$p}agy_messages m INNER JOIN {$p}agy_conversations c ON c.id = m.conversation_id WHERE m.id = %d",
				$message_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'conversation_id' => (int) $row['conversation_id'],
			'session_id'      => (int) $row['session_id'],
		);
	}

	/**
	 * Plain-text rendering of a block list: the markdown of every text block,
	 * joined by blank lines. Non-text blocks (links, products, cta, notice)
	 * are structural and stay out of the searchable content column.
	 *
	 * @param array $blocks Blocks per the shared schema.
	 */
	public static function blocks_to_text( array $blocks ): string {
		$parts = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || 'text' !== ( $block['type'] ?? '' ) ) {
				continue;
			}
			$md = trim( (string) ( $block['md'] ?? '' ) );
			if ( '' !== $md ) {
				$parts[] = $md;
			}
		}

		return implode( "\n\n", $parts );
	}
}
