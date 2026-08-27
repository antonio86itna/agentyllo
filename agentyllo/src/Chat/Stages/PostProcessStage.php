<?php
/**
 * Post-process stage: link/product blocks from retrieval results.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Stages;

use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Stage;
use Agentyllo\KB\Store;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Agentyllo's own custom tables: names are $wpdb->prefix plus literal constants; every value goes through $wpdb->prepare().

/**
 * Turns the distinct documents behind ctx->chunks into a links block (max 3,
 * score order, thumbnails per widget settings) — or a products block instead
 * when WooCommerce is active and product documents were retrieved (with LIVE
 * price_html/stock via wc_get_product, never index values).
 *
 * Never emits anything on ROUTE_REFUSE, dedupes against links already present
 * (e.g. the handoff contact link), and respects the show_internal_links /
 * show_thumbnails widget settings.
 */
final class PostProcessStage implements Stage {

	private const MAX_ITEMS   = 3;
	private const EXCERPT_LEN = 140;

	/**
	 * Resolver returning the current 'widget' settings array.
	 *
	 * @var callable
	 */
	private $widget_settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param callable $widget_settings_resolver Returns the current 'widget' settings array.
	 */
	public function __construct( callable $widget_settings_resolver ) {
		$this->widget_settings_resolver = $widget_settings_resolver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'post_process';
	}

	/**
	 * {@inheritDoc}
	 */
	public function status_event(): string {
		return 'linking';
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( ChatContext $context ): void {
		if ( ChatContext::ROUTE_REFUSE === $context->route || ! $context->chunks ) {
			return;
		}

		// A navigation answer already carries its exact link card; appending
		// the chunk-ranked documents would re-introduce the near-miss pages
		// the title lookup just filtered out.
		if ( ! empty( $context->meta['nav_composed'] ) ) {
			return;
		}

		$settings        = $this->settings();
		$show_thumbnails = (bool) ( $settings['show_thumbnails'] ?? true );
		$existing_urls   = $this->existing_urls( $context );

		// Distinct documents, best chunk first (chunks are score-ordered).
		$docs = array();
		foreach ( $context->chunks as $chunk ) {
			$doc_id = (int) $chunk['document_id'];
			if ( ! isset( $docs[ $doc_id ] ) ) {
				$docs[ $doc_id ] = $chunk;
			}
		}

		// AI answers cite [#n] sources: the pages the model actually used
		// come first (in citation order); uncited hits follow only when
		// nothing was cited — a citing answer links exactly what it quoted.
		$cited = is_array( $context->meta['ai_cited'] ?? null ) ? array_map( 'intval', $context->meta['ai_cited'] ) : array();
		if ( $cited ) {
			$ordered = array();
			foreach ( $cited as $doc_id ) {
				if ( isset( $docs[ $doc_id ] ) ) {
					$ordered[ $doc_id ] = $docs[ $doc_id ];
				}
			}
			if ( $ordered ) {
				$docs = $ordered;
			}
		}

		$product_docs = array_filter( $docs, static fn ( array $doc ): bool => 'product' === $doc['source'] );

		if ( $product_docs && function_exists( 'wc_get_product' ) ) {
			$this->add_products_block( $context, $product_docs, $existing_urls, $show_thumbnails );

			return;
		}

		if ( ! (bool) ( $settings['show_internal_links'] ?? true ) ) {
			return;
		}

		$this->add_links_block( $context, $docs, $existing_urls, $show_thumbnails );
	}

	/**
	 * Products block with LIVE price/stock — index values are retrieval hints
	 * only and never surface.
	 *
	 * @param ChatContext          $context         Context.
	 * @param array<int, array>    $product_docs    Best chunk per product document, keyed by document id.
	 * @param array<string, bool>  $existing_urls   URLs already present in blocks.
	 * @param bool                 $show_thumbnails Widget thumbnail setting.
	 */
	private function add_products_block( ChatContext $context, array $product_docs, array $existing_urls, bool $show_thumbnails ): void {
		$product_ids = $this->product_ids_for_documents( array_keys( $product_docs ) );

		$items = array();
		foreach ( $product_docs as $doc_id => $doc ) {
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}

			$url = (string) $doc['permalink'];
			if ( '' === $url || isset( $existing_urls[ $url ] ) ) {
				continue;
			}

			$product_id = (int) ( $product_ids[ $doc_id ] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
				continue;
			}

			$item = array(
				'id'    => $product_id,
				'title' => (string) $product->get_name(),
				'url'   => $url,
				'stock' => $this->stock_state( $product ),
			);

			$price_html = (string) $product->get_price_html();
			if ( '' !== $price_html ) {
				$item['price_html'] = $price_html;
			}

			if ( $show_thumbnails ) {
				$thumb = $this->thumb( $doc['thumbnail_id'] ?? null );
				if ( $thumb ) {
					$item['thumb'] = $thumb;
				}
			}

			$cart_url = $this->add_to_cart_url( $product );
			if ( '' !== $cart_url ) {
				$item['add_to_cart_url'] = $cart_url;
			}

			$existing_urls[ $url ] = true;
			$items[]               = $item;
		}

		if ( $items ) {
			$context->add_block(
				array(
					'type'  => 'products',
					'items' => $items,
				)
			);
		}
	}

	/**
	 * Links block from the distinct retrieved documents.
	 *
	 * @param ChatContext         $context         Context.
	 * @param array<int, array>   $docs            Best chunk per document, keyed by document id.
	 * @param array<string, bool> $existing_urls   URLs already present in blocks.
	 * @param bool                $show_thumbnails Widget thumbnail setting.
	 */
	private function add_links_block( ChatContext $context, array $docs, array $existing_urls, bool $show_thumbnails ): void {
		$items = array();

		foreach ( $docs as $doc ) {
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}

			$url = (string) $doc['permalink'];
			if ( '' === $url || isset( $existing_urls[ $url ] ) ) {
				continue;
			}

			$item = array(
				'title' => wp_specialchars_decode( (string) $doc['title'], ENT_QUOTES ),
				'url'   => $url,
			);

			$excerpt = $this->excerpt( (string) $doc['content'], (string) $doc['title'] );
			if ( '' !== $excerpt ) {
				$item['excerpt'] = $excerpt;
			}

			if ( $show_thumbnails ) {
				$thumb = $this->thumb( $doc['thumbnail_id'] ?? null );
				if ( $thumb ) {
					$item['thumb'] = $thumb;
				}
			}

			$existing_urls[ $url ] = true;
			$items[]               = $item;
		}

		if ( $items ) {
			$context->add_block(
				array(
					'type'  => 'links',
					'items' => $items,
				)
			);
		}
	}

	/**
	 * WooCommerce product ids for KB document ids (one query, via external_id).
	 *
	 * @param int[] $doc_ids Document ids.
	 * @return array<int, int> document_id => product_id.
	 */
	private function product_ids_for_documents( array $doc_ids ): array {
		$doc_ids = array_filter( array_map( 'absint', $doc_ids ) );
		if ( ! $doc_ids ) {
			return array();
		}

		global $wpdb;
		$in = implode( ',', $doc_ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, external_id FROM ' . $wpdb->prefix . "agyl_kb_documents WHERE id IN ({$in}) AND source = %s AND status = %s",
				'product',
				Store::STATUS_ACTIVE
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['id'] ] = absint( (string) $row['external_id'] );
		}

		return $map;
	}

	/**
	 * Live stock mapped to the block schema ('in'|'low'|'out'|'').
	 *
	 * @param \WC_Product $product Product.
	 */
	private function stock_state( \WC_Product $product ): string {
		return match ( (string) $product->get_stock_status() ) {
			'instock'     => 'in',
			'outofstock'  => 'out',
			'onbackorder' => 'low',
			default       => '',
		};
	}

	/**
	 * Direct add-to-cart URL for simple purchasable in-stock products.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function add_to_cart_url( \WC_Product $product ): string {
		if ( ! function_exists( 'wc_get_cart_url' ) ) {
			return '';
		}
		if ( ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return '';
		}

		return (string) add_query_arg( 'add-to-cart', (int) $product->get_id(), wc_get_cart_url() );
	}

	/**
	 * Thumb descriptor per the shared block schema, null when unavailable.
	 *
	 * @param int|null $attachment_id Attachment id.
	 * @return array{id: int, src: string, srcset?: string, alt: string, w: int, h: int}|null
	 */
	private function thumb( ?int $attachment_id ): ?array {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'medium' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}

		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			$alt = (string) get_the_title( $attachment_id );
		}

		$thumb = array(
			'id'  => $attachment_id,
			'src' => (string) $src[0],
			'alt' => $alt,
			'w'   => (int) $src[1],
			'h'   => (int) $src[2],
		);

		$srcset = wp_get_attachment_image_srcset( $attachment_id, 'medium' );
		if ( is_string( $srcset ) && '' !== $srcset ) {
			$thumb['srcset'] = $srcset;
		}

		return $thumb;
	}

	/**
	 * Word-safe plain-text excerpt from chunk content.
	 *
	 * @param string $content Chunk content.
	 */
	private function excerpt( string $content, string $title = '' ): string {
		// The indexer prepends the document title as the chunk's first line
		// (BM25 findability); the card already shows the title, so drop it.
		$title = trim( wp_specialchars_decode( $title, ENT_QUOTES ) );
		if ( '' !== $title ) {
			$content = (string) preg_replace( '/^\s*' . preg_quote( $title, '/' ) . '\s*/iu', '', $content );
		}
		$content = trim( (string) preg_replace( '/\s+/u', ' ', $content ) );
		if ( mb_strlen( $content ) <= self::EXCERPT_LEN ) {
			return $content;
		}

		$cut   = mb_substr( $content, 0, self::EXCERPT_LEN );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > 60 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return $cut . '…';
	}

	/**
	 * URLs already present in any block (links/products/cta) for dedupe.
	 *
	 * @param ChatContext $context Context.
	 * @return array<string, bool>
	 */
	private function existing_urls( ChatContext $context ): array {
		$urls = array();

		foreach ( $context->blocks as $block ) {
			$type = (string) ( $block['type'] ?? '' );

			if ( 'cta' === $type && ! empty( $block['url'] ) ) {
				$urls[ (string) $block['url'] ] = true;
				continue;
			}

			if ( in_array( $type, array( 'links', 'products' ), true ) ) {
				foreach ( (array) ( $block['items'] ?? array() ) as $item ) {
					if ( ! empty( $item['url'] ) ) {
						$urls[ (string) $item['url'] ] = true;
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * Current 'widget' settings via the injected resolver.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$settings = ( $this->widget_settings_resolver )();

		return is_array( $settings ) ? $settings : array();
	}
}
