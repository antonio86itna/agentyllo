<?php
/**
 * Natural-language starter questions for the chat widget.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat;

use Agentyllo\KB\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Derives tappable starter questions from the top-weight active KB documents,
 * phrased per source: products become "Do you have X?", policy-ish pages map
 * to canned questions (shipping, contact, returns), everything else falls
 * back to "Tell me about X". Results are cached in a 5-minute transient
 * stamped with the KB version, so any index change invalidates them at most
 * 5 minutes late and usually immediately. Consumed by the chat /config REST
 * payload.
 */
final class StarterSuggestions {

	private const TRANSIENT = 'agyl_starter_suggestions';
	private const TTL       = 5 * MINUTE_IN_SECONDS;
	private const POOL      = 12;
	private const MAX       = 6;

	/**
	 * Lowercased-title keywords that map a page to a canned question.
	 */
	private const SHIPPING_KEYWORDS = array( 'shipping', 'delivery', 'spedizion', 'consegna', 'versand', 'lieferung', 'livraison', 'envío', 'envio' );

	/**
	 * Contact-page keywords.
	 */
	private const CONTACT_KEYWORDS = array( 'contact', 'contatt', 'kontakt' );

	/**
	 * Returns/refunds keywords.
	 */
	private const RETURNS_KEYWORDS = array( 'return', 'refund', 'reso', 'resi', 'rimbors', 'rückgabe', 'erstattung', 'remboursement' );

	/**
	 * Starter questions, best candidates first.
	 *
	 * @param int $limit Max suggestions (1-6).
	 * @return string[]
	 */
	public function get( int $limit = 3 ): array {
		$limit      = max( 1, min( self::MAX, $limit ) );
		$kb_version = (int) get_option( 'agyl_kb_version', 0 );

		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && $kb_version === (int) ( $cached['kb_version'] ?? -1 ) && is_array( $cached['items'] ?? null ) ) {
			$items = $cached['items'];
		} else {
			$items = $this->build();
			set_transient(
				self::TRANSIENT,
				array(
					'kb_version' => $kb_version,
					'items'      => $items,
				),
				self::TTL
			);
		}

		/**
		 * Filter the widget starter suggestions (before the limit is applied).
		 *
		 * @param string[] $items Suggestion strings.
		 */
		$items = (array) apply_filters( 'agyl_starter_suggestions', $items );

		return array_slice( array_values( array_unique( array_filter( array_map( 'strval', $items ) ) ) ), 0, $limit );
	}

	/**
	 * Build the suggestion pool from the top-weight active documents. Menus
	 * and taxonomies are skipped (their titles make poor questions).
	 *
	 * @return string[]
	 */
	private function build(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source, title FROM ' . $wpdb->prefix . "agyl_kb_documents WHERE status = %s AND title <> '' AND source NOT IN ('menu', 'taxonomy') ORDER BY weight DESC, indexed_at DESC LIMIT %d",
				Store::STATUS_ACTIVE,
				self::POOL
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$question = $this->phrase( (string) $row['source'], (string) $row['title'] );
			if ( '' !== $question && ! in_array( $question, $items, true ) ) {
				$items[] = $question;
			}
			if ( count( $items ) >= self::MAX ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Phrase one document as a starter question.
	 *
	 * @param string $source Adapter id (product|site|post|manual|…).
	 * @param string $title  Document title.
	 */
	private function phrase( string $source, string $title ): string {
		$title = trim( wp_strip_all_tags( $title ) );
		if ( '' === $title ) {
			return '';
		}
		if ( mb_strlen( $title ) > 60 ) {
			$title = rtrim( mb_substr( $title, 0, 57 ) ) . '…';
		}

		if ( 'product' === $source ) {
			/* translators: %s: product title. */
			return sprintf( __( 'Do you have %s?', 'agentyllo' ), $title );
		}

		if ( 'site' === $source ) {
			return __( 'What is this site about?', 'agentyllo' );
		}

		$lc = mb_strtolower( $title );
		if ( $this->contains_any( $lc, self::SHIPPING_KEYWORDS ) ) {
			return __( 'What are your shipping options?', 'agentyllo' );
		}
		if ( $this->contains_any( $lc, self::CONTACT_KEYWORDS ) ) {
			return __( 'How can I contact you?', 'agentyllo' );
		}
		if ( $this->contains_any( $lc, self::RETURNS_KEYWORDS ) ) {
			return __( 'What is your return policy?', 'agentyllo' );
		}

		/* translators: %s: page or article title. */
		return sprintf( __( 'Tell me about %s', 'agentyllo' ), $title );
	}

	/**
	 * Whether any needle occurs in the haystack.
	 *
	 * @param string   $haystack Lowercased title.
	 * @param string[] $needles  Keywords.
	 */
	private function contains_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
