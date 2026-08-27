<?php
/**
 * Deterministic template packs for classic template intents.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Canned replies for greeting/smalltalk/handoff, keyed intent × tone. All base
 * strings are English `__()` calls — the i18n system localizes them into the
 * site language. Variant selection is deterministic (crc32 of session id +
 * UTC hour) so answers vary between visitors and hours without randomness:
 * the same visitor asking twice in the same hour gets the same phrasing.
 */
final class Templates {

	/**
	 * Resolve a template string for an intent and tone.
	 *
	 * Vars: 'site_name' (renders as %1$s), 'session_id' (variant seed).
	 *
	 * @param string $intent Template intent (greeting|smalltalk|handoff).
	 * @param string $tone   Tone (professional|friendly|playful).
	 * @param array  $vars   Variables (site_name, session_id).
	 * @return string Resolved text, '' when the intent has no pack.
	 */
	public function get( string $intent, string $tone, array $vars = array() ): string {
		$packs = $this->packs();

		$variants = $packs[ $intent ][ $tone ] ?? $packs[ $intent ]['friendly'] ?? array();
		if ( ! $variants ) {
			// Retrieval intents have no canned answer: fall back to the
			// honesty lead-in used above a links list.
			$variants = $packs['found'][ $tone ] ?? $packs['found']['friendly'] ?? array();
		}
		if ( ! $variants ) {
			return '';
		}

		$seed  = (string) ( $vars['session_id'] ?? '' );
		$index = (int) ( crc32( $seed . gmdate( 'YmdH' ) ) % count( $variants ) );

		$site_name = (string) ( $vars['site_name'] ?? get_bloginfo( 'name' ) );

		return sprintf( $variants[ $index ], $site_name );
	}

	/**
	 * Template packs: intent => tone => variants (%1$s = site name).
	 *
	 * @return array<string, array<string, string[]>>
	 */
	private function packs(): array {
		$packs = array(
			'greeting'  => array(
				'professional' => array(
					/* translators: %1$s: site name. */
					__( 'Hello, and welcome to %1$s. How may I assist you today?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Good day — you have reached the %1$s assistant. What can I help you with?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Welcome to %1$s. Please let me know what you are looking for.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Hello. I am the assistant for %1$s — how can I be of service?', 'agentyllo' ),
				),
				'friendly'     => array(
					/* translators: %1$s: site name. */
					__( 'Hi there! Welcome to %1$s — what can I help you with?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Hello! Great to see you on %1$s. What are you looking for today?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Hi! I\'m the %1$s assistant. Ask me anything about this site.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Hey! Happy to help you find your way around %1$s.', 'agentyllo' ),
				),
				'playful'      => array(
					/* translators: %1$s: site name. */
					__( 'Well hello! You\'ve reached %1$s — fire away!', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Hey hey! Welcome to %1$s. What shall we explore today?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Hi! I know %1$s inside out. What do you need?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Greetings, traveler! What brings you to %1$s today?', 'agentyllo' ),
				),
			),
			'smalltalk' => array(
				'professional' => array(
					/* translators: %1$s: site name. */
					__( 'I appreciate the conversation. I am best at questions about %1$s — what would you like to know?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Thank you. My expertise is %1$s and its content — feel free to ask about it.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Noted. Is there anything about %1$s I can help you with?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'I am doing well, thank you. How can I assist you with %1$s?', 'agentyllo' ),
				),
				'friendly'     => array(
					/* translators: %1$s: site name. */
					__( 'Ha, I like the chat! I\'m most useful with questions about %1$s though — what can I find for you?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'All good here! Anything about %1$s you\'d like to know?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Thanks! I spend my days reading %1$s, so ask me anything about it.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Doing great, thanks for asking! Now, what can I help you with on %1$s?', 'agentyllo' ),
				),
				'playful'      => array(
					/* translators: %1$s: site name. */
					__( 'You flatter me! But my one true love is %1$s — ask me about it!', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Living the dream — one question about %1$s at a time. Got one for me?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Chit-chat accepted! Now let\'s talk %1$s — what do you need?', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'I could talk all day, but I\'m even better at finding things on %1$s. Try me!', 'agentyllo' ),
				),
			),
			'handoff'   => array(
				'professional' => array(
					/* translators: %1$s: site name. */
					__( 'Certainly. Here is how you can reach the team behind %1$s directly.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Of course. You can contact the %1$s team through the details below.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Understood. The best way to reach a person at %1$s is below.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'I will step aside — here is how to contact the %1$s team directly.', 'agentyllo' ),
				),
				'friendly'     => array(
					/* translators: %1$s: site name. */
					__( 'No problem! Here\'s how to reach a real person at %1$s.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Sure thing — the %1$s team is happy to help. Here are their contact details.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Absolutely! You can get in touch with the %1$s team using the details below.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Happy to connect you! Here\'s how to contact the people behind %1$s.', 'agentyllo' ),
				),
				'playful'      => array(
					/* translators: %1$s: site name. */
					__( 'Summoning the humans of %1$s! Here\'s how to reach them.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Fair enough — sometimes only a human will do. The %1$s team awaits below!', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Passing the mic to the %1$s crew — here\'s where to find them.', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'Human backup, coming right up! Contact the %1$s team below.', 'agentyllo' ),
				),
			),
			// Honesty lead-in for retrieval intents when no extractive answer
			// clears the quality gate: shown above the related-links list.
			'found'     => array(
				'professional' => array(
					/* translators: %1$s: site name. */
					__( 'Here is what I found on %1$s that may help:', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'These pages on %1$s are the closest match to your question:', 'agentyllo' ),
				),
				'friendly'     => array(
					/* translators: %1$s: site name. */
					__( 'Here\'s what I found on %1$s:', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'I couldn\'t pin down an exact answer, but these pages on %1$s should help:', 'agentyllo' ),
				),
				'playful'      => array(
					/* translators: %1$s: site name. */
					__( 'Not a bullseye, but close! Here\'s what %1$s has on that:', 'agentyllo' ),
					/* translators: %1$s: site name. */
					__( 'I dug through %1$s and surfaced these — one of them should do the trick:', 'agentyllo' ),
				),
			),
		);

		/**
		 * Filter the classic template packs.
		 *
		 * @param array $packs intent => tone => sprintf variants (%1$s = site name).
		 */
		return (array) apply_filters( 'agyl_chat_templates', $packs );
	}
}
