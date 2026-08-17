<?php
/**
 * Source adapter for navigation menus.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * One document per nav menu: a hierarchical "Label → URL (parent path)" list
 * block plus one link edge per item. Menus encode the site's own idea of its
 * structure, so they get a high retrieval weight and feed the link graph
 * (`rel` = 'menu' via the Store).
 *
 * Single-universe adapter: subtype ''. Toggled by `menus_enabled`.
 */
final class MenuAdapter implements SourceAdapter {

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'menu';
	}

	/**
	 * Menus always exist as a concept.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Single universe.
	 *
	 * @return array<string, string>
	 */
	public function subtypes(): array {
		return array( '' => __( 'Menus', 'agentyllo' ) );
	}

	/**
	 * Number of nav menus.
	 *
	 * @param string $subtype Unused ('' = all).
	 */
	public function count_items( string $subtype = '' ): int {
		unset( $subtype );

		return count( wp_get_nav_menus() );
	}

	/**
	 * Stable ID cursor over menu term ids.
	 *
	 * @param string $subtype Unused ('' = all).
	 * @param int    $offset  Cursor offset.
	 * @param int    $limit   Batch size.
	 * @return string[]
	 */
	public function enumerate_ids( string $subtype, int $offset, int $limit ): array {
		unset( $subtype );

		$ids = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			if ( $menu instanceof WP_Term ) {
				$ids[] = (int) $menu->term_id;
			}
		}
		sort( $ids );

		return array_map( 'strval', array_slice( $ids, max( 0, $offset ), max( 1, $limit ) ) );
	}

	/**
	 * Cheap probe: menus carry no modified timestamp, so hash name + item
	 * signature (menus are small, this stays cheap).
	 *
	 * @param string $external_id Menu term ID.
	 */
	public function fingerprint( string $external_id ): ?string {
		$menu = wp_get_nav_menu_object( (int) $external_id );
		if ( ! $menu instanceof WP_Term ) {
			return null;
		}

		$parts = array( $menu->name );
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) ) as $item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}
			$parts[] = $item->ID . '|' . $item->title . '|' . $item->url . '|' . $item->menu_item_parent . '|' . $item->menu_order;
		}

		return sha1( implode( "\n", $parts ) );
	}

	/**
	 * Full extraction.
	 *
	 * @param string $external_id Menu term ID.
	 */
	public function extract( string $external_id ): ?DocumentDraft {
		$menu = wp_get_nav_menu_object( (int) $external_id );
		if ( ! $menu instanceof WP_Term ) {
			return null;
		}

		$items = wp_get_nav_menu_items( $menu->term_id );
		if ( ! is_array( $items ) || ! $items ) {
			return null; // Empty menu: nothing indexable.
		}

		$by_id = array();
		foreach ( $items as $item ) {
			if ( $item instanceof WP_Post ) {
				$by_id[ (int) $item->ID ] = $item;
			}
		}

		$lines = array();
		$links = array();

		foreach ( $by_id as $item ) {
			$label = trim( wp_strip_all_tags( (string) $item->title ) );
			$url   = trim( (string) $item->url );
			if ( '' === $label && '' === $url ) {
				continue;
			}

			// Ancestor labels, root-first, cycle-guarded.
			$path      = array();
			$parent_id = (int) $item->menu_item_parent;
			$depth     = 0;
			while ( $parent_id > 0 && isset( $by_id[ $parent_id ] ) && $depth < 10 ) {
				array_unshift( $path, trim( wp_strip_all_tags( (string) $by_id[ $parent_id ]->title ) ) );
				$parent_id = (int) $by_id[ $parent_id ]->menu_item_parent;
				++$depth;
			}

			$line = $label;
			if ( '' !== $url && '#' !== $url ) {
				$line   .= ' → ' . $url;
				$links[] = array(
					'url'    => $url,
					'anchor' => $label,
				);
			}
			if ( $path ) {
				$line .= ' (' . implode( ' › ', array_filter( $path ) ) . ')';
			}
			$lines[] = '• ' . $line;
		}

		if ( ! $lines ) {
			return null;
		}

		/** This filter is documented in src/KB/Source/PostTypeAdapter.php */
		$lang = (string) apply_filters( 'agy_kb_document_lang', determine_locale(), $menu );

		return new DocumentDraft(
			$this->id(),
			(string) $menu->term_id,
			'',
			$menu->name,
			'',
			$lang,
			array( new NormalizedBlock( NormalizedBlock::KIND_LIST, implode( "\n", $lines ) ) ),
			array(),
			$links,
			null,
			70,
			null
		);
	}

	/**
	 * Change signals for the content watcher.
	 *
	 * @return array<string, array{args: int, map: callable}>
	 */
	public function delta_hooks(): array {
		return array(
			'wp_update_nav_menu' => array(
				'args' => 1,
				'map'  => static function ( $menu_id ): ?array {
					$menu_id = (int) $menu_id;
					if ( $menu_id <= 0 ) {
						return null;
					}

					return array(
						'external_id' => (string) $menu_id,
						'action'      => 'upsert',
					);
				},
			),
			'wp_delete_nav_menu' => array(
				'args' => 1,
				'map'  => static function ( $menu_id ): ?array {
					$menu_id = is_object( $menu_id ) ? (int) ( $menu_id->term_id ?? 0 ) : (int) $menu_id;
					if ( $menu_id <= 0 ) {
						return null;
					}

					return array(
						'external_id' => (string) $menu_id,
						'action'      => 'delete',
					);
				},
			),
		);
	}
}
