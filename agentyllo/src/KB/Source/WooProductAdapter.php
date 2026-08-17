<?php
/**
 * WooCommerce product source adapter.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

use Agentyllo\KB\Indexer\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Indexes published WooCommerce products through CRUD getters only
 * (wc_get_product / wc_get_products — HPOS-safe, never raw meta).
 *
 * Field masks from the 'sources' settings tab (wc_prices, wc_stock,
 * wc_attributes, wc_variations, wc_reviews, wc_linked) select which data is
 * extracted; masked-off fields are OMITTED from both structured facts and
 * content blocks.
 *
 * NOTE: prices and stock stored in the KB are retrieval hints only — the
 * chat pipeline re-reads live values via wc_get_product() at answer time,
 * so a stale index can never quote a wrong price as fact.
 */
final class WooProductAdapter implements SourceAdapter {

	private const WEIGHT             = 65;
	private const VARIATIONS_CAP     = 50;
	private const REVIEW_EXCERPTS    = 5;
	private const REVIEW_EXCERPT_LEN = 200;

	/**
	 * Resolver returning the current 'sources' settings array.
	 *
	 * @var callable
	 */
	private $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param Normalizer $normalizer        HTML → blocks normalizer.
	 * @param callable   $settings_resolver Returns the current 'sources' settings array.
	 */
	public function __construct(
		private readonly Normalizer $normalizer,
		callable $settings_resolver
	) {
		$this->settings_resolver = $settings_resolver;
	}

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'product';
	}

	/**
	 * Available only when WooCommerce is active.
	 */
	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Single universe: all products behind one toggle (woocommerce_enabled).
	 *
	 * @return array<string, string>
	 */
	public function subtypes(): array {
		return array( '' => __( 'Products', 'agentyllo' ) );
	}

	/**
	 * Published product count.
	 *
	 * @param string $subtype Subtype id ('' = all).
	 */
	public function count_items( string $subtype = '' ): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		$result = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			)
		);

		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Stable ID-ordered cursor over published products.
	 *
	 * @param string $subtype Subtype id ('' = all).
	 * @param int    $offset  Cursor offset.
	 * @param int    $limit   Batch size.
	 * @return string[]
	 */
	public function enumerate_ids( string $subtype, int $offset, int $limit ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$ids = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => max( 1, $limit ),
				'offset'  => max( 0, $offset ),
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		return array_map( 'strval', (array) $ids );
	}

	/**
	 * Cheap change probe: modified_gmt:stock_status:price. The MySQL-format
	 * modified time is front-loaded so IndexManager::fingerprint_current()
	 * can prefix-match it against the stored source_modified_gmt.
	 *
	 * @param string $external_id Product id.
	 */
	public function fingerprint( string $external_id ): ?string {
		$product = $this->product( $external_id );
		if ( ! $product ) {
			return null;
		}

		$modified = $product->get_date_modified();

		return implode(
			':',
			array(
				$modified ? gmdate( 'Y-m-d H:i:s', $modified->getTimestamp() ) : '',
				(string) $product->get_stock_status(),
				(string) $product->get_price(),
			)
		);
	}

	/**
	 * Full normalized extraction honoring the wc_* field masks.
	 *
	 * @param string $external_id Product id.
	 */
	public function extract( string $external_id ): ?DocumentDraft {
		$product = $this->product( $external_id );
		if ( ! $product ) {
			return null;
		}

		$settings  = $this->settings();
		$blocks    = array();
		$links     = array();
		$image_ids = array();

		// Content: short description first, then long description.
		foreach ( array( (string) $product->get_short_description(), (string) $product->get_description() ) as $html ) {
			if ( '' === trim( $html ) ) {
				continue;
			}
			$normalized = $this->normalizer->normalize( $html );
			$blocks     = array_merge( $blocks, $normalized['blocks'] );
			$links      = array_merge( $links, $normalized['links'] );
			$image_ids  = array_merge( $image_ids, $normalized['image_ids'] );
		}

		$structured = array();

		$sku = (string) $product->get_sku();
		if ( '' !== $sku ) {
			$structured['sku'] = $sku;
		}
		$structured['type'] = $product->get_type();

		if ( $this->mask( $settings, 'wc_prices' ) ) {
			$structured['price']         = (string) $product->get_price();
			$structured['regular_price'] = (string) $product->get_regular_price();
			$structured['sale_price']    = (string) $product->get_sale_price();
			$structured['currency']      = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
			$structured['is_on_sale']    = (bool) $product->is_on_sale();
		}

		if ( $this->mask( $settings, 'wc_stock' ) ) {
			$structured['stock_status'] = (string) $product->get_stock_status();
			if ( $product->managing_stock() ) {
				$quantity = $product->get_stock_quantity();
				if ( null !== $quantity ) {
					$structured['stock_quantity'] = (int) $quantity;
				}
			}
		}

		if ( $this->mask( $settings, 'wc_attributes' ) ) {
			$attributes = $this->attribute_facts( $product );
			if ( $attributes ) {
				$structured['attributes'] = $attributes;
			}
		}

		// Categories + tags are always extracted (taxonomy context, not a mask).
		$categories = $this->term_names( (int) $product->get_id(), 'product_cat' );
		if ( $categories ) {
			$structured['categories'] = implode( ', ', $categories );
		}
		$tags = $this->term_names( (int) $product->get_id(), 'product_tag' );
		if ( $tags ) {
			$structured['tags'] = implode( ', ', $tags );
		}

		if ( $this->mask( $settings, 'wc_variations' ) && $product->is_type( 'variable' ) ) {
			$this->extract_variations( $product, $settings, $structured );
		}

		if ( $this->mask( $settings, 'wc_reviews' ) ) {
			$this->extract_reviews( $product, $structured, $blocks );
		}

		if ( $this->mask( $settings, 'wc_linked' ) ) {
			$this->extract_linked( $product, $links, $blocks );
		}

		$modified  = $product->get_date_modified();
		$thumbnail = (int) $product->get_image_id();

		return new DocumentDraft(
			$this->id(),
			(string) $product->get_id(),
			'',
			(string) $product->get_name(),
			(string) $product->get_permalink(),
			'',
			$blocks,
			$structured,
			$links,
			$thumbnail > 0 ? $thumbnail : null,
			self::WEIGHT,
			$modified ? gmdate( 'Y-m-d H:i:s', $modified->getTimestamp() ) : null
		);
	}

	/**
	 * Change signals. The content_watcher agent registers these and
	 * debounces the resulting delta descriptors.
	 *
	 * @return array<string, array{args: int, map: callable}>
	 */
	public function delta_hooks(): array {
		$upsert_by_id = static function ( mixed $product_id ): ?array {
			$id = absint( is_object( $product_id ) && method_exists( $product_id, 'get_id' ) ? $product_id->get_id() : $product_id );

			return $id > 0 ? array(
				'external_id' => (string) $id,
				'action'      => 'upsert',
			) : null;
		};

		$delete_by_id = static function ( mixed $product_id ): ?array {
			$id = absint( is_object( $product_id ) && method_exists( $product_id, 'get_id' ) ? $product_id->get_id() : $product_id );

			return $id > 0 ? array(
				'external_id' => (string) $id,
				'action'      => 'delete',
			) : null;
		};

		// Stock hooks pass the product object; variations map to their parent.
		$stock_map = static function ( mixed $product ): ?array {
			if ( ! $product instanceof \WC_Product ) {
				return null;
			}
			$parent_id = (int) $product->get_parent_id();
			$id        = $parent_id > 0 ? $parent_id : (int) $product->get_id();

			return $id > 0 ? array(
				'external_id' => (string) $id,
				'action'      => 'upsert',
			) : null;
		};

		// Admin trash/delete go through core post hooks, not the WC CRUD hooks.
		$post_delete_map = static function ( mixed $post_id, mixed $post = null ): ?array {
			$id = absint( is_scalar( $post_id ) ? $post_id : 0 );
			if ( $id <= 0 ) {
				return null;
			}
			$type = $post instanceof \WP_Post ? (string) $post->post_type : (string) get_post_type( $id );

			return 'product' === $type ? array(
				'external_id' => (string) $id,
				'action'      => 'delete',
			) : null;
		};

		$untrash_map = static function ( mixed $post_id ): ?array {
			$id = absint( is_scalar( $post_id ) ? $post_id : 0 );
			if ( $id <= 0 || 'product' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
				return null;
			}

			return array(
				'external_id' => (string) $id,
				'action'      => 'upsert',
			);
		};

		// Review lifecycle: any review change re-indexes its parent product.
		$comment_to_product = static function ( mixed $comment ): ?array {
			if ( ! $comment instanceof \WP_Comment ) {
				$comment = get_comment( absint( is_scalar( $comment ) ? $comment : 0 ) );
			}
			if ( ! $comment instanceof \WP_Comment || 'review' !== (string) $comment->comment_type ) {
				return null;
			}

			$post = (int) $comment->comment_post_ID;
			if ( $post <= 0 || ! function_exists( 'wc_get_product' ) || ! wc_get_product( $post ) instanceof \WC_Product ) {
				return null;
			}

			return array(
				'external_id' => (string) $post,
				'action'      => 'upsert',
			);
		};

		$review_map = static function ( mixed $comment_id, mixed $approved, mixed $commentdata ): ?array {
			if ( ! is_scalar( $approved ) || 1 !== (int) $approved ) {
				return null; // Pending/spam reviews never reach the index.
			}

			$data = is_array( $commentdata ) ? $commentdata : array();
			$type = (string) ( $data['comment_type'] ?? '' );
			$post = (int) ( $data['comment_post_ID'] ?? 0 );

			if ( '' === $type || 0 === $post ) {
				$comment = get_comment( absint( is_scalar( $comment_id ) ? $comment_id : 0 ) );
				if ( $comment ) {
					$type = (string) $comment->comment_type;
					$post = (int) $comment->comment_post_ID;
				}
			}

			if ( 'review' !== $type || $post <= 0 ) {
				return null;
			}
			if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( $post ) instanceof \WC_Product ) {
				return null;
			}

			return array(
				'external_id' => (string) $post,
				'action'      => 'upsert',
			);
		};

		return array(
			'woocommerce_new_product'         => array(
				'args' => 1,
				'map'  => $upsert_by_id,
			),
			'woocommerce_update_product'      => array(
				'args' => 1,
				'map'  => $upsert_by_id,
			),
			'woocommerce_delete_product'      => array(
				'args' => 1,
				'map'  => $delete_by_id,
			),
			'woocommerce_trash_product'       => array(
				'args' => 1,
				'map'  => $delete_by_id,
			),
			'trashed_post'                    => array(
				'args' => 1,
				'map'  => $post_delete_map,
			),
			'deleted_post'                    => array(
				'args' => 2,
				'map'  => $post_delete_map,
			),
			'untrashed_post'                  => array(
				'args' => 1,
				'map'  => $untrash_map,
			),
			'woocommerce_product_set_stock'   => array(
				'args' => 1,
				'map'  => $stock_map,
			),
			'woocommerce_variation_set_stock' => array(
				'args' => 1,
				'map'  => $stock_map,
			),
			'comment_post'                    => array(
				'args' => 3,
				'map'  => $review_map,
			),
			'transition_comment_status'       => array(
				'args' => 3,
				'map'  => static fn ( mixed $new_status, mixed $old_status, mixed $comment ): ?array => $comment_to_product( $comment ),
			),
			'edit_comment'                    => array(
				'args' => 2,
				'map'  => static fn ( mixed $comment_id, mixed $data = null ): ?array => $comment_to_product( $comment_id ),
			),
			'deleted_comment'                 => array(
				'args' => 2,
				'map'  => static fn ( mixed $comment_id, mixed $comment = null ): ?array => $comment_to_product( $comment instanceof \WP_Comment ? $comment : $comment_id ),
			),
		);
	}

	/**
	 * Load a published top-level product (variations are never documents).
	 *
	 * @param string $external_id Product id.
	 */
	private function product( string $external_id ): ?object {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( absint( $external_id ) );
		if ( ! $product instanceof \WC_Product || $product->is_type( 'variation' ) ) {
			return null;
		}
		if ( 'publish' !== $product->get_status() ) {
			return null;
		}

		return $product;
	}

	/**
	 * Attribute label => value-list map (taxonomy attrs via term names,
	 * custom attrs via their options).
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, string>
	 */
	private function attribute_facts( \WC_Product $product ): array {
		$facts = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$label = wc_attribute_label( $attribute->get_name(), $product );

			if ( $attribute->is_taxonomy() ) {
				$values = $this->term_names( (int) $product->get_id(), $attribute->get_name() );
			} else {
				$values = array_filter( array_map( 'strval', (array) $attribute->get_options() ) );
			}

			if ( $values && '' !== $label ) {
				$facts[ $label ] = implode( ', ', $values );
			}
		}

		return $facts;
	}

	/**
	 * Per-variation facts (capped; the cap is noted in structured when hit).
	 * Price/stock inside variations honor the wc_prices/wc_stock masks too.
	 *
	 * @param \WC_Product          $product    Variable product.
	 * @param array<string, mixed> $settings   Sources settings.
	 * @param array<string, mixed> $structured Structured facts (by reference).
	 */
	private function extract_variations( \WC_Product $product, array $settings, array &$structured ): void {
		$children = array_map( 'intval', (array) $product->get_children() );
		$total    = count( $children );
		$rows     = array();

		foreach ( array_slice( $children, 0, self::VARIATIONS_CAP ) as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof \WC_Product ) {
				continue;
			}

			$row = array();

			$summary = $this->variation_summary( $variation );
			if ( '' !== $summary ) {
				$row['attributes'] = $summary;
			}

			$sku = (string) $variation->get_sku();
			if ( '' !== $sku ) {
				$row['sku'] = $sku;
			}

			if ( $this->mask( $settings, 'wc_prices' ) ) {
				$row['price'] = (string) $variation->get_price();
			}

			if ( $this->mask( $settings, 'wc_stock' ) ) {
				$row['stock_status'] = (string) $variation->get_stock_status();
				if ( $variation->managing_stock() ) {
					$quantity = $variation->get_stock_quantity();
					if ( null !== $quantity ) {
						$row['stock_quantity'] = (int) $quantity;
					}
				}
			}

			if ( $row ) {
				$rows[] = $row;
			}
		}

		if ( $rows ) {
			$structured['variations'] = $rows;
		}
		if ( $total > self::VARIATIONS_CAP ) {
			$structured['variations_note'] = sprintf( 'showing %d of %d variations', self::VARIATIONS_CAP, $total );
		}
	}

	/**
	 * "Label: value, Label: value" summary of a variation's attributes.
	 *
	 * @param \WC_Product $variation Variation.
	 */
	private function variation_summary( \WC_Product $variation ): string {
		$pairs = array();

		foreach ( (array) $variation->get_attributes() as $name => $value ) {
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			$label   = wc_attribute_label( (string) $name, $variation );
			$pairs[] = ( '' !== $label ? $label . ': ' : '' ) . (string) $value;
		}

		return implode( ', ', $pairs );
	}

	/**
	 * Rating facts + up to five latest approved review excerpts as one
	 * faq-kind block.
	 *
	 * @param \WC_Product          $product    Product.
	 * @param array<string, mixed> $structured Structured facts (by reference).
	 * @param NormalizedBlock[]    $blocks     Blocks (by reference).
	 */
	private function extract_reviews( \WC_Product $product, array &$structured, array &$blocks ): void {
		$structured['average_rating'] = (string) $product->get_average_rating();
		$structured['review_count']   = (int) $product->get_review_count();

		$comments = get_comments(
			array(
				'post_id' => (int) $product->get_id(),
				'type'    => 'review',
				'status'  => 'approve',
				'number'  => self::REVIEW_EXCERPTS,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);

		$lines = array();
		foreach ( (array) $comments as $comment ) {
			if ( ! isset( $comment->comment_content ) ) {
				continue;
			}
			$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $comment->comment_content ) );
			if ( '' === $text ) {
				continue;
			}
			if ( mb_strlen( $text ) > self::REVIEW_EXCERPT_LEN ) {
				$text = mb_substr( $text, 0, self::REVIEW_EXCERPT_LEN ) . '…';
			}
			$lines[] = '• ' . $text;
		}

		if ( $lines ) {
			$blocks[] = new NormalizedBlock(
				NormalizedBlock::KIND_FAQ,
				__( 'Customer reviews', 'agentyllo' ) . ":\n" . implode( "\n", $lines )
			);
		}
	}

	/**
	 * Upsell/cross-sell titles + permalinks as link edges and a list block.
	 *
	 * @param \WC_Product       $product Product.
	 * @param array             $links   Link edges (by reference).
	 * @param NormalizedBlock[] $blocks  Blocks (by reference).
	 */
	private function extract_linked( \WC_Product $product, array &$links, array &$blocks ): void {
		$related_ids = array_unique(
			array_map( 'intval', array_merge( (array) $product->get_upsell_ids(), (array) $product->get_cross_sell_ids() ) )
		);

		$items = array();
		foreach ( $related_ids as $related_id ) {
			$related = wc_get_product( $related_id );
			if ( ! $related instanceof \WC_Product || 'publish' !== $related->get_status() ) {
				continue;
			}

			$name = (string) $related->get_name();
			$url  = (string) $related->get_permalink();
			if ( '' === $url ) {
				continue;
			}

			$links[] = array(
				'url'    => $url,
				'anchor' => $name,
			);
			$items[] = '• ' . $name;
		}

		if ( $items ) {
			$blocks[] = new NormalizedBlock(
				NormalizedBlock::KIND_LIST,
				__( 'Related products', 'agentyllo' ) . ":\n" . implode( "\n", $items )
			);
		}
	}

	/**
	 * Product/taxonomy term name list ([] on error).
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy name.
	 * @return string[]
	 */
	private function term_names( int $product_id, string $taxonomy ): array {
		if ( ! function_exists( 'wc_get_product_terms' ) ) {
			return array();
		}

		$names = wc_get_product_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
		if ( is_wp_error( $names ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', (array) $names ) ) );
	}

	/**
	 * Current 'sources' settings via the injected resolver.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$settings = ( $this->settings_resolver )();

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Field mask check (missing key = enabled, matching schema defaults).
	 *
	 * @param array<string, mixed> $settings Sources settings.
	 * @param string               $key      Mask field key.
	 */
	private function mask( array $settings, string $key ): bool {
		return (bool) ( $settings[ $key ] ?? true );
	}
}
