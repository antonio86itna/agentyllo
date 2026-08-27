<?php
/**
 * Source adapter for public taxonomy terms.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

use Agentyllo\KB\Indexer\Normalizer;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * One document per non-empty term of every public taxonomy: term name as
 * title, description through the Normalizer, archive permalink, and an item
 * count so "how many posts about X" style questions have grounding.
 *
 * Subtype = taxonomy name. The whole adapter is toggled by the single
 * `taxonomies_enabled` setting.
 */
final class TaxonomyAdapter implements SourceAdapter {

	/**
	 * Per-request subtype cache.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $subtype_cache = null;

	/**
	 * Constructor.
	 *
	 * @param Normalizer $normalizer HTML → blocks normalizer.
	 */
	public function __construct( private readonly Normalizer $normalizer ) {
	}

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'taxonomy';
	}

	/**
	 * Taxonomies always exist.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Public taxonomies keyed by name with human labels ('post_format' is
	 * structural noise and skipped).
	 *
	 * @return array<string, string>
	 */
	public function subtypes(): array {
		if ( null !== $this->subtype_cache ) {
			return $this->subtype_cache;
		}

		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}
			$out[ $taxonomy->name ] = (string) ( $taxonomy->labels->name ?? $taxonomy->name );
		}

		$this->subtype_cache = $out;

		return $out;
	}

	/**
	 * Non-empty term count.
	 *
	 * @param string $subtype Taxonomy name ('' = all).
	 */
	public function count_items( string $subtype = '' ): int {
		$taxonomies = '' === $subtype ? array_keys( $this->subtypes() ) : array( $subtype );
		$taxonomies = array_values( array_filter( $taxonomies, array( $this, 'is_indexable_taxonomy' ) ) );
		if ( ! $taxonomies ) {
			return 0;
		}

		$count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
			)
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Stable ID cursor over non-empty terms.
	 *
	 * @param string $subtype Taxonomy name ('' = all).
	 * @param int    $offset  Cursor offset.
	 * @param int    $limit   Batch size.
	 * @return string[]
	 */
	public function enumerate_ids( string $subtype, int $offset, int $limit ): array {
		$taxonomies = '' === $subtype ? array_keys( $this->subtypes() ) : array( $subtype );
		$taxonomies = array_values( array_filter( $taxonomies, array( $this, 'is_indexable_taxonomy' ) ) );
		if ( ! $taxonomies ) {
			return array();
		}

		$ids = get_terms(
			array(
				'taxonomy'               => $taxonomies,
				'fields'                 => 'ids',
				'hide_empty'             => true,
				'orderby'                => 'id',
				'order'                  => 'ASC',
				'number'                 => max( 1, $limit ),
				'offset'                 => max( 0, $offset ),
				'update_term_meta_cache' => false,
			)
		);

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_map( static fn ( $id ): string => (string) (int) $id, $ids );
	}

	/**
	 * Cheap probe: terms carry no modified timestamp, so hash the fields that
	 * feed extraction.
	 *
	 * @param string $external_id Term ID.
	 */
	public function fingerprint( string $external_id ): ?string {
		$term = get_term( (int) $external_id );
		if ( ! $term instanceof WP_Term || ! $this->is_indexable_taxonomy( $term->taxonomy ) ) {
			return null;
		}

		return sha1( $term->name . '|' . $term->slug . '|' . $term->description . '|' . $term->count . '|' . $term->parent );
	}

	/**
	 * Full extraction.
	 *
	 * @param string $external_id Term ID.
	 */
	public function extract( string $external_id ): ?DocumentDraft {
		$term = get_term( (int) $external_id );
		if ( ! $term instanceof WP_Term || ! $this->is_indexable_taxonomy( $term->taxonomy ) ) {
			return null;
		}

		$normalized = $this->normalizer->normalize( (string) $term->description );
		$blocks     = $normalized['blocks'];

		$blocks[] = new NormalizedBlock(
			NormalizedBlock::KIND_PARAGRAPH,
			sprintf(
				/* translators: %d: number of published items assigned to the term. */
				_n( 'Contains %d item', 'Contains %d items', (int) $term->count, 'agentyllo' ),
				(int) $term->count
			)
		);

		$link      = get_term_link( $term );
		$permalink = is_wp_error( $link ) ? '' : (string) $link;

		$taxonomy_object = get_taxonomy( $term->taxonomy );
		$taxonomy_label  = $taxonomy_object ? (string) ( $taxonomy_object->labels->singular_name ?? $taxonomy_object->label ) : $term->taxonomy;

		// WooCommerce category thumbnails (and compatible themes) live in term meta.
		$thumbnail_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );

		/** This filter is documented in src/KB/Source/PostTypeAdapter.php */
		$lang = (string) apply_filters( 'agyl_kb_document_lang', determine_locale(), $term );

		return new DocumentDraft(
			$this->id(),
			(string) $term->term_id,
			$term->taxonomy,
			$term->name,
			$permalink,
			$lang,
			$blocks,
			array(
				'type'  => $taxonomy_label,
				'items' => (int) $term->count,
			),
			$normalized['links'],
			$thumbnail_id > 0 ? $thumbnail_id : null,
			40,
			null
		);
	}

	/**
	 * Change signals for the content watcher.
	 *
	 * @return array<string, array{args: int, map: callable}>
	 */
	public function delta_hooks(): array {
		$upsert = function ( $term_id, $tt_id = 0, $taxonomy = '' ): ?array {
			unset( $tt_id );
			if ( ! $this->is_indexable_taxonomy( (string) $taxonomy ) ) {
				return null;
			}

			return array(
				'external_id' => (string) (int) $term_id,
				'action'      => 'upsert',
			);
		};

		return array(
			'created_term' => array(
				'args' => 3,
				'map'  => $upsert,
			),
			'edited_term'  => array(
				'args' => 3,
				'map'  => $upsert,
			),
			'delete_term'  => array(
				'args' => 3,
				'map'  => function ( $term_id, $tt_id = 0, $taxonomy = '' ): ?array {
					unset( $tt_id );
					if ( ! $this->is_indexable_taxonomy( (string) $taxonomy ) ) {
						return null;
					}

					return array(
						'external_id' => (string) (int) $term_id,
						'action'      => 'delete',
					);
				},
			),
		);
	}

	/**
	 * Whether a taxonomy is in the indexable set.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	private function is_indexable_taxonomy( string $taxonomy ): bool {
		return isset( $this->subtypes()[ $taxonomy ] );
	}
}
