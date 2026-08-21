<?php
/**
 * Exact-match response cache for AI-composed answers.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Budget;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * Keyed on sha1(provider|model|prompt_version|kb_version|lang|question|fact
 * hash). Any KB change bumps kb_version and naturally invalidates every
 * entry; a prompt-pack update does the same. Rows expire after 24h and are
 * swept by the hourly maintenance job. The semantic (cosine ≥ 0.95) layer
 * arrives with dense embeddings in M8 — this class is its exact floor.
 */
final class ResponseCache {

	private const TTL = DAY_IN_SECONDS;

	/**
	 * Build the cache key.
	 *
	 * @param string $provider       Provider id.
	 * @param string $model          Model id.
	 * @param string $prompt_version Prompt-pack version.
	 * @param string $lang           Reply language.
	 * @param string $question       Normalized question.
	 * @param string $facts_hash     Hash of fact-slot values.
	 */
	public static function key( string $provider, string $model, string $prompt_version, string $lang, string $question, string $facts_hash ): string {
		$kb_version = (int) get_option( 'agy_kb_version', 0 );
		$normalized = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $question ) ) );

		return sha1( implode( '|', array( $provider, $model, $prompt_version, (string) $kb_version, $lang, $normalized, $facts_hash ) ) );
	}

	/**
	 * Cached blocks for a key, or null.
	 *
	 * @param string $key Cache key.
	 * @return array{text: string, blocks: array, model: string, provider: string}|null
	 */
	public function get( string $key ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, provider, model, text, blocks FROM ' . $wpdb->prefix . 'agy_response_cache WHERE cache_key = %s AND expires_at > %s',
				$key,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'agy_response_cache SET hits = hits + 1 WHERE id = %d', (int) $row['id'] ) );

		$blocks = json_decode( (string) $row['blocks'], true );

		return array(
			'text'     => (string) $row['text'],
			'blocks'   => is_array( $blocks ) ? $blocks : array(),
			'model'    => (string) $row['model'],
			'provider' => (string) $row['provider'],
		);
	}

	/**
	 * Store an answer.
	 *
	 * @param string $key            Cache key.
	 * @param string $provider       Provider id.
	 * @param string $model          Model id.
	 * @param string $prompt_version Prompt-pack version.
	 * @param string $text           Answer text.
	 * @param array  $blocks         Composed blocks.
	 */
	public function put( string $key, string $provider, string $model, string $prompt_version, string $text, array $blocks ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . 'agy_response_cache (cache_key, provider, model, prompt_version, text, blocks, hits, created_at, expires_at)
				 VALUES (%s, %s, %s, %s, %s, %s, 0, %s, %s)
				 ON DUPLICATE KEY UPDATE text = VALUES(text), blocks = VALUES(blocks), expires_at = VALUES(expires_at)',
				$key,
				substr( $provider, 0, 32 ),
				substr( $model, 0, 80 ),
				substr( $prompt_version, 0, 20 ),
				$text,
				(string) wp_json_encode( $blocks ),
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', time() + self::TTL )
			)
		);
	}

	/**
	 * Delete expired rows (maintenance job).
	 */
	public function sweep(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'agy_response_cache WHERE expires_at < %s', gmdate( 'Y-m-d H:i:s' ) ) );

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Drop everything (settings change, admin "clear cache").
	 */
	public function flush(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'agy_response_cache' );
	}
}
