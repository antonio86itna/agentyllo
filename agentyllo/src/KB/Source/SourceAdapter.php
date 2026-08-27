<?php
/**
 * Knowledge-base source adapter contract.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

defined( 'ABSPATH' ) || exit;

/**
 * One adapter per content universe (posts, products, menus, site identity,
 * taxonomies, manual entries). Registered via the `agyl_kb_source_adapters`
 * filter so addons can plug in new universes.
 */
interface SourceAdapter {

	/**
	 * Stable adapter id: 'post' | 'product' | 'menu' | 'site' | 'taxonomy' | 'manual' | custom.
	 */
	public function id(): string;

	/**
	 * Whether the adapter can run at all (e.g. WooCommerce active).
	 */
	public function is_available(): bool;

	/**
	 * Toggleable subtypes with human labels, e.g. ['page' => 'Pages'] for the
	 * post adapter or [''] for single-universe adapters.
	 *
	 * @return array<string, string>
	 */
	public function subtypes(): array;

	/**
	 * Total indexable items for a subtype (dashboard coverage metrics).
	 *
	 * @param string $subtype Subtype id ('' = all).
	 */
	public function count_items( string $subtype = '' ): int;

	/**
	 * Stable, ordered id cursor for batched crawling.
	 *
	 * @param string $subtype Subtype id ('' = all).
	 * @param int    $offset  Cursor offset.
	 * @param int    $limit   Batch size.
	 * @return string[] External ids.
	 */
	public function enumerate_ids( string $subtype, int $offset, int $limit ): array;

	/**
	 * CHEAP change probe (modified time + meta revision), no full extraction.
	 * Null when the item no longer exists / is no longer indexable.
	 *
	 * @param string $external_id External id.
	 */
	public function fingerprint( string $external_id ): ?string;

	/**
	 * Full normalized extraction. Null when the item no longer exists or has
	 * nothing indexable.
	 *
	 * @param string $external_id External id.
	 */
	public function extract( string $external_id ): ?DocumentDraft;

	/**
	 * WordPress hooks that signal changes in this universe, mapped to a
	 * resolver turning hook args into delta descriptors.
	 *
	 * Shape: [ hook_name => [ 'args' => int, 'map' => callable( mixed ...$hook_args ): ?array{external_id: string, action: 'upsert'|'delete'} ] ]
	 * The content_watcher agent registers these and debounces the results.
	 *
	 * @return array<string, array{args: int, map: callable}>
	 */
	public function delta_hooks(): array;
}
