<?php
/**
 * Entity extraction against the KB document catalog.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat;

use Agentyllo\KB\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Matches the visitor text against agy_kb_documents titles (post + product
 * sources, active only). Deliberately simple: one catalog fetch (top 500 by
 * weight), then in-PHP matching — exact title-in-text first, then word
 * overlap (>= 70% of title words present), then levenshtein <= 2 for
 * single-word titles. Ids returned are KB document ids (external_id carried
 * alongside so product stages can reach the WooCommerce product without a
 * second lookup).
 */
final class EntityExtractor {

	private const CATALOG_LIMIT   = 500;
	private const MAX_PER_BUCKET  = 5;
	private const OVERLAP_RATIO   = 0.7;
	private const FUZZY_DISTANCE  = 2;
	private const FUZZY_MIN_CHARS = 5;

	/**
	 * Per-request catalog cache.
	 *
	 * @var array<int, array{id: int, external_id: string, source: string, title: string}>|null
	 */
	private ?array $catalog = null;

	/**
	 * Extract entities from a normalized text.
	 *
	 * @param string $text Normalized visitor text (original casing).
	 * @return array{products: array<int, array{id: int, external_id: string, title: string}>, pages: array<int, array{id: int, external_id: string, title: string}>, skus: string[], price_bounds: array{min?: float, max?: float}}
	 */
	public function extract( string $text ): array {
		$out = array(
			'products'     => array(),
			'pages'        => array(),
			'skus'         => array(),
			'price_bounds' => array(),
		);

		if ( '' === trim( $text ) ) {
			return $out;
		}

		$text_lc    = mb_strtolower( $text, 'UTF-8' );
		$text_words = $this->words( $text_lc );

		foreach ( $this->catalog() as $doc ) {
			$bucket = 'product' === $doc['source'] ? 'products' : 'pages';
			if ( count( $out[ $bucket ] ) >= self::MAX_PER_BUCKET ) {
				continue;
			}
			if ( $this->matches( $doc['title'], $text_lc, $text_words ) ) {
				$out[ $bucket ][] = array(
					'id'          => $doc['id'],
					'external_id' => $doc['external_id'],
					'title'       => $doc['title'],
				);
			}
		}

		$out['skus']         = $this->extract_skus( $text );
		$out['price_bounds'] = $this->extract_price_bounds( $text_lc );

		return $out;
	}

	/**
	 * Title-vs-text match: exact substring, high word overlap, or fuzzy
	 * single-word.
	 *
	 * @param string   $title      Document title.
	 * @param string   $text_lc    Lowercased visitor text.
	 * @param string[] $text_words Lowercased visitor words.
	 */
	private function matches( string $title, string $text_lc, array $text_words ): bool {
		$title_lc = mb_strtolower( trim( $title ), 'UTF-8' );
		if ( mb_strlen( $title_lc, 'UTF-8' ) < 3 ) {
			return false;
		}

		if ( str_contains( $text_lc, $title_lc ) ) {
			return true;
		}

		$title_words = $this->words( $title_lc );
		if ( ! $title_words ) {
			return false;
		}

		if ( count( $title_words ) >= 2 ) {
			$set     = array_flip( $text_words );
			$present = 0;
			foreach ( $title_words as $word ) {
				if ( isset( $set[ $word ] ) ) {
					++$present;
				}
			}

			return $present / count( $title_words ) >= self::OVERLAP_RATIO;
		}

		// Single-word title: typo tolerance via levenshtein (byte-based —
		// fine as a tolerance heuristic, lengths guarded below).
		$word = $title_words[0];
		if ( strlen( $word ) < self::FUZZY_MIN_CHARS || strlen( $word ) > 255 ) {
			return false;
		}
		foreach ( $text_words as $candidate ) {
			if ( strlen( $candidate ) > 255 || abs( strlen( $candidate ) - strlen( $word ) ) > self::FUZZY_DISTANCE ) {
				continue;
			}
			if ( levenshtein( $candidate, $word ) <= self::FUZZY_DISTANCE ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * SKU-shaped codes. The alnum-dash pattern also matches dates/phone
	 * fragments, so at least one letter is required.
	 *
	 * @param string $text Original-case text.
	 * @return string[]
	 */
	private function extract_skus( string $text ): array {
		if ( ! preg_match_all( '/\b[A-Z0-9]{2,}-[A-Z0-9-]{2,}\b/', $text, $matches ) ) {
			return array();
		}

		$skus = array();
		foreach ( $matches[0] as $candidate ) {
			if ( preg_match( '/[A-Z]/', $candidate ) ) {
				$skus[] = $candidate;
			}
		}

		return array_values( array_unique( $skus ) );
	}

	/**
	 * "under 50" / "sotto i 100 €" style price bounds.
	 *
	 * @param string $text_lc Lowercased text.
	 * @return array{min?: float, max?: float}
	 */
	private function extract_price_bounds( string $text_lc ): array {
		$bounds = array();
		$number = '(?:[€$£]\s*)?(\d+(?:[.,]\d{1,2})?)\s*(?:[€$£])?';

		$max_words = '(?:under|below|less\s+than|cheaper\s+than|max(?:imum)?|sotto(?:\s+i)?|meno\s+di|al\s+massimo|unter|weniger\s+als|h[öo]chstens|moins\s+de|maximum|menos\s+de|como\s+m[áa]ximo|abaixo\s+de|minder\s+dan|maximaal)';
		if ( preg_match( '/\b' . $max_words . '\s*' . $number . '/u', $text_lc, $m ) ) {
			$bounds['max'] = (float) str_replace( ',', '.', $m[1] );
		}

		$min_words = '(?:over|above|more\s+than|at\s+least|sopra(?:\s+i)?|pi[ùu]\s+di|almeno|[üu]ber|mehr\s+als|mindestens|plus\s+de|au\s+moins|m[áa]s\s+de|al\s+menos|mais\s+de|pelo\s+menos|meer\s+dan|minstens)';
		if ( preg_match( '/\b' . $min_words . '\s*' . $number . '/u', $text_lc, $m ) ) {
			$bounds['min'] = (float) str_replace( ',', '.', $m[1] );
		}

		return $bounds;
	}

	/**
	 * Word list (letters/digits, >= 3 chars — short function words only add
	 * overlap noise).
	 *
	 * @param string $text_lc Lowercased text.
	 * @return string[]
	 */
	private function words( string $text_lc ): array {
		preg_match_all( '/[\p{L}\p{N}]{3,}/u', $text_lc, $matches );

		return $matches[0];
	}

	/**
	 * Active post/product documents, heaviest first (fetched once per
	 * request).
	 *
	 * @return array<int, array{id: int, external_id: string, source: string, title: string}>
	 */
	private function catalog(): array {
		if ( null !== $this->catalog ) {
			return $this->catalog;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, external_id, source, title FROM ' . $wpdb->prefix . "agy_kb_documents
				 WHERE source IN ('post', 'product') AND status = %s AND title <> ''
				 ORDER BY weight DESC, id ASC LIMIT %d",
				Store::STATUS_ACTIVE,
				self::CATALOG_LIMIT
			),
			ARRAY_A
		);

		$this->catalog = array();
		foreach ( (array) $rows as $row ) {
			$this->catalog[] = array(
				'id'          => (int) $row['id'],
				'external_id' => (string) $row['external_id'],
				'source'      => (string) $row['source'],
				'title'       => (string) $row['title'],
			);
		}

		return $this->catalog;
	}
}
