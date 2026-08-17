<?php
/**
 * Normalized document produced by a source adapter, ready for chunking.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Value object between extraction and indexing.
 */
final class DocumentDraft {

	/**
	 * Constructor.
	 *
	 * @param string                 $source              Adapter id ('post', 'product', 'menu', 'site', 'taxonomy', 'manual').
	 * @param string                 $external_id         Source-scoped id (post ID, term ID, 'site', uuid…).
	 * @param string                 $subtype             Toggleable subtype (post type, product type, menu id…).
	 * @param string                 $title               Document title.
	 * @param string                 $permalink           Public URL ('' when none).
	 * @param string                 $lang                Locale code, '' = site default.
	 * @param NormalizedBlock[]      $blocks              Ordered content blocks.
	 * @param array<string, mixed>   $structured          Machine facts (price, stock, contact…) for exact classic answers.
	 * @param array<int, array{url: string, anchor: string}> $links Outgoing links harvested for the link graph.
	 * @param int|null               $thumbnail_id        Attachment id for chat thumbnails.
	 * @param int                    $weight              0-100 retrieval priority.
	 * @param string|null            $source_modified_gmt Source last-modified (MySQL datetime), for staleness checks.
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $external_id,
		public readonly string $subtype,
		public readonly string $title,
		public readonly string $permalink,
		public readonly string $lang,
		public readonly array $blocks,
		public readonly array $structured = array(),
		public readonly array $links = array(),
		public readonly ?int $thumbnail_id = null,
		public readonly int $weight = 50,
		public readonly ?string $source_modified_gmt = null,
	) {
	}

	/**
	 * Freshness hash over content + structured facts. Unchanged hash means
	 * re-chunking can be skipped (cheap idempotency).
	 */
	public function content_hash(): string {
		$parts = array( $this->title, $this->permalink, $this->lang, (string) $this->thumbnail_id, (string) $this->weight );
		foreach ( $this->blocks as $block ) {
			$parts[] = $block->kind . '|' . $block->level . '|' . $block->text;
		}
		$parts[] = (string) wp_json_encode( $this->structured );

		return sha1( implode( "\n", $parts ) );
	}
}
