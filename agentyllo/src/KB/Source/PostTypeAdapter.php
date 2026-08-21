<?php
/**
 * Source adapter for posts, pages, and public custom post types.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

use Agentyllo\KB\Indexer\Normalizer;
use Closure;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * Indexes published entries of every public post type. Elementor builder
 * pages are delegated to the optional ElementorAdapter decorator (rendered
 * builder data instead of raw `the_content`), everything else runs through
 * `the_content` + the HTML Normalizer.
 *
 * Subtype = post type name; the settings tab maps them to posts_enabled /
 * pages_enabled / cpt_{type}_enabled toggles. 'attachment' is never indexed
 * and 'product' is excluded when WooCommerce is active (it has its own
 * adapter).
 */
final class PostTypeAdapter implements SourceAdapter {

	/**
	 * Resolver for 'sources'-tab settings: callable( string $key ): mixed.
	 * Null resolver = every toggle treated as enabled.
	 *
	 * @var Closure|null
	 */
	private readonly ?Closure $settings_resolver;

	/**
	 * Per-request subtype cache.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $subtype_cache = null;

	/**
	 * Constructor.
	 *
	 * @param Normalizer            $normalizer        HTML → blocks normalizer.
	 * @param ElementorAdapter|null $elementor         Optional Elementor decorator.
	 * @param callable|null         $settings_resolver Resolver for 'sources' settings, callable( string $key ): mixed.
	 */
	public function __construct(
		private readonly Normalizer $normalizer,
		private readonly ?ElementorAdapter $elementor = null,
		?callable $settings_resolver = null,
	) {
		$this->settings_resolver = null === $settings_resolver ? null : Closure::fromCallable( $settings_resolver );
	}

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'post';
	}

	/**
	 * Posts always exist.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Public post types keyed by name with human labels. Excludes
	 * 'attachment' always and 'product' when WooCommerce is active.
	 *
	 * @return array<string, string>
	 */
	public function subtypes(): array {
		if ( null !== $this->subtype_cache ) {
			return $this->subtype_cache;
		}

		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			if ( 'product' === $type->name && class_exists( 'WooCommerce' ) ) {
				continue; // Products belong to the WooCommerce adapter.
			}
			$out[ $type->name ] = (string) ( $type->labels->name ?? $type->name );
		}

		$this->subtype_cache = $out;

		return $out;
	}

	/**
	 * Published item count.
	 *
	 * @param string $subtype Post type ('' = all indexable types).
	 */
	public function count_items( string $subtype = '' ): int {
		$types = '' === $subtype ? array_keys( $this->subtypes() ) : array( $subtype );
		$total = 0;

		foreach ( $types as $type ) {
			if ( ! $this->is_indexable_type( $type ) ) {
				continue;
			}
			$counts = wp_count_posts( $type );
			$total += (int) ( $counts->publish ?? 0 );
		}

		return $total;
	}

	/**
	 * Stable ID cursor over published entries.
	 *
	 * @param string $subtype Post type ('' = all indexable types).
	 * @param int    $offset  Cursor offset.
	 * @param int    $limit   Batch size.
	 * @return string[]
	 */
	public function enumerate_ids( string $subtype, int $offset, int $limit ): array {
		$types = '' === $subtype ? array_keys( $this->subtypes() ) : array( $subtype );
		$types = array_values( array_filter( $types, array( $this, 'is_indexable_type' ) ) );
		if ( ! $types ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => max( 1, $limit ),
				'offset'                 => max( 0, $offset ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( static fn ( $id ): string => (string) (int) $id, $query->posts );
	}

	/**
	 * Cheap probe: modification time + Elementor marker (so toggling the
	 * Elementor extraction path re-fingerprints builder pages).
	 *
	 * @param string $external_id Post ID.
	 */
	public function fingerprint( string $external_id ): ?string {
		$post = get_post( (int) $external_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->is_indexable_type( $post->post_type ) ) {
			return null;
		}

		return $post->post_modified_gmt . '|el:' . ( $this->uses_elementor( $post ) ? '1' : '0' );
	}

	/**
	 * Full extraction.
	 *
	 * @param string $external_id Post ID.
	 */
	public function extract( string $external_id ): ?DocumentDraft {
		$post = get_post( (int) $external_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->is_indexable_type( $post->post_type ) ) {
			return null;
		}

		$blocks    = array();
		$links     = array();
		$image_ids = array();

		if ( $this->uses_elementor( $post ) && null !== $this->elementor ) {
			$data = $this->elementor->extract_elementor( $post->ID );
			if ( is_array( $data ) ) {
				$blocks    = array_values(
					array_filter(
						(array) ( $data['blocks'] ?? array() ),
						static fn ( $block ): bool => $block instanceof NormalizedBlock
					)
				);
				$links     = (array) ( $data['links'] ?? array() );
				$image_ids = array_map( 'intval', (array) ( $data['image_ids'] ?? array() ) );
			}
		}

		if ( ! $blocks ) {
			$normalized = $this->normalizer->normalize( $this->render_content( $post ) );
			$blocks     = $normalized['blocks'];
			$links      = $normalized['links'];
			$image_ids  = $normalized['image_ids'];
		}

		// Hand-written excerpts are strong summaries: prepend as first paragraph.
		$excerpt = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		if ( '' !== $excerpt ) {
			array_unshift( $blocks, new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, $excerpt ) );
		}

		$term_lines = $this->term_lines( $post );
		if ( $term_lines ) {
			$blocks[] = new NormalizedBlock( NormalizedBlock::KIND_LIST, implode( "\n", $term_lines ) );
		}

		$structured = $this->whitelisted_meta( $post );

		if ( ! $blocks && ! $structured ) {
			return null; // Nothing indexable.
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post );
		if ( $thumbnail_id <= 0 ) {
			$thumbnail_id = (int) ( $image_ids[0] ?? 0 );
		}

		/**
		 * Filter the document language. Multilingual plugins (Polylang, WPML)
		 * hook here to return the per-post locale.
		 *
		 * @param string  $lang Detected locale.
		 * @param WP_Post $post The post being indexed.
		 */
		$lang = (string) apply_filters( 'agy_kb_document_lang', determine_locale(), $post );

		return new DocumentDraft(
			$this->id(),
			(string) $post->ID,
			$post->post_type,
			wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
			(string) get_permalink( $post ),
			$lang,
			$blocks,
			$structured,
			$links,
			$thumbnail_id > 0 ? $thumbnail_id : null,
			'page' === $post->post_type ? 60 : 50,
			$post->post_modified_gmt
		);
	}

	/**
	 * Change signals for the content watcher.
	 *
	 * @return array<string, array{args: int, map: callable}>
	 */
	public function delta_hooks(): array {
		return array(
			'wp_after_insert_post' => array(
				'args' => 4,
				'map'  => function ( $post_id, $post = null, $update = false, $post_before = null ): ?array {
					unset( $update, $post_before );
					$post = $post instanceof WP_Post ? $post : get_post( (int) $post_id );
					if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
						return null;
					}
					if ( ! $this->is_indexable_type( $post->post_type ) ) {
						return null;
					}

					return array(
						'external_id' => (string) $post->ID,
						'action'      => 'publish' === $post->post_status ? 'upsert' : 'delete',
					);
				},
			),
			'deleted_post'         => array(
				'args' => 2,
				'map'  => function ( $post_id, $post = null ): ?array {
					$type = $post instanceof WP_Post ? $post->post_type : (string) get_post_type( (int) $post_id );
					if ( '' !== $type && ! $this->is_indexable_type( $type ) ) {
						return null;
					}

					return array(
						'external_id' => (string) (int) $post_id,
						'action'      => 'delete',
					);
				},
			),
			'trashed_post'         => array(
				'args' => 1,
				'map'  => function ( $post_id ): ?array {
					$type = (string) get_post_type( (int) $post_id );
					if ( '' !== $type && ! $this->is_indexable_type( $type ) ) {
						return null;
					}

					return array(
						'external_id' => (string) (int) $post_id,
						'action'      => 'delete',
					);
				},
			),
			'untrashed_post'       => array(
				'args' => 2,
				'map'  => function ( $post_id, $previous_status = '' ): ?array {
					$post = get_post( (int) $post_id );
					if ( ! $post instanceof WP_Post || ! $this->is_indexable_type( $post->post_type ) ) {
						return null;
					}
					// Status is already restored when the hook fires.
					if ( 'publish' !== $post->post_status && 'publish' !== (string) $previous_status ) {
						return null;
					}

					return array(
						'external_id' => (string) $post->ID,
						'action'      => 'upsert',
					);
				},
			),
			'set_object_terms'     => array(
				'args' => 6,
				'map'  => function ( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ): ?array {
					unset( $terms, $append );
					$tax = get_taxonomy( (string) $taxonomy );
					if ( ! $tax || ! $tax->public ) {
						return null;
					}

					$new = array_map( 'intval', (array) $tt_ids );
					$old = array_map( 'intval', (array) $old_tt_ids );
					sort( $new );
					sort( $old );
					if ( $new === $old ) {
						return null; // No actual assignment change.
					}

					$post = get_post( (int) $object_id );
					if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->is_indexable_type( $post->post_type ) ) {
						return null;
					}

					return array(
						'external_id' => (string) $post->ID,
						'action'      => 'upsert',
					);
				},
			),
		);
	}

	/**
	 * Whether a post type is in the indexable set.
	 *
	 * @param string $post_type Post type name.
	 */
	private function is_indexable_type( string $post_type ): bool {
		return isset( $this->subtypes()[ $post_type ] );
	}

	/**
	 * Whether this post should go through the Elementor decorator: decorator
	 * injected, Elementor active, setting on, page built with the builder.
	 *
	 * @param WP_Post $post The post.
	 */
	private function uses_elementor( WP_Post $post ): bool {
		return null !== $this->elementor
			&& $this->elementor->is_available()
			&& $this->elementor_enabled()
			&& $this->elementor->is_built_with_elementor( $post->ID );
	}

	/**
	 * Effective 'elementor_enabled' setting (default true when no resolver).
	 */
	private function elementor_enabled(): bool {
		if ( null === $this->settings_resolver ) {
			return true;
		}
		$value = ( $this->settings_resolver )( 'elementor_enabled' );

		return null === $value ? true : (bool) $value;
	}

	/**
	 * Rendered post content with `the_content` filters applied, with the
	 * global post swapped in so shortcodes/builders resolve correctly.
	 *
	 * @param WP_Post $post The post.
	 */
	private function render_content( WP_Post $post ): string {
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Scoped swap for the_content filters, restored below.
		setup_postdata( $post );

		/** This filter is documented in wp-includes/post-template.php */
		$html = (string) apply_filters( 'the_content', $post->post_content );

		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore previous global.
		if ( $previous instanceof WP_Post ) {
			setup_postdata( $previous );
		} else {
			wp_reset_postdata();
		}

		return $html;
	}

	/**
	 * Taxonomy assignment lines, e.g. "Categories: a, b".
	 *
	 * @param WP_Post $post The post.
	 * @return string[]
	 */
	private function term_lines( WP_Post $post ): array {
		$lines = array();

		foreach ( get_object_taxonomies( $post, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || 'post_format' === $taxonomy->name ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) || ! $terms ) {
				continue;
			}
			$names = array_filter( array_map( static fn ( $term ): string => (string) $term->name, $terms ) );
			if ( ! $names ) {
				continue;
			}
			$label   = (string) ( $taxonomy->labels->name ?? $taxonomy->label );
			$lines[] = $label . ': ' . implode( ', ', $names );
		}

		return $lines;
	}

	/**
	 * Whitelisted post meta → structured facts.
	 *
	 * @param WP_Post $post The post.
	 * @return array<string, mixed>
	 */
	private function whitelisted_meta( WP_Post $post ): array {
		/**
		 * Filter the post meta keys added to the document's structured facts.
		 * Empty by default — only explicitly whitelisted keys are indexed.
		 *
		 * @param string[] $keys Meta keys to index.
		 * @param WP_Post  $post The post being indexed.
		 */
		$keys       = (array) apply_filters( 'agy_kb_post_meta_keys', array(), $post );
		$structured = array();

		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$structured[ $key ] = $value;
			}
		}

		return $structured;
	}
}
