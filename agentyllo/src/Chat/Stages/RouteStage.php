<?php
/**
 * Route stage: intent → route decision + fact-slot loading.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Stages;

use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Stage;
use Agentyllo\KB\Source\SiteIdentityAdapter;
use Agentyllo\KB\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Classic-only decision matrix (M4): every intent lands on ROUTE_CLASSIC
 * except handoff (ROUTE_HANDOFF). Greeting/smalltalk/handoff additionally get
 * a template flag so ComposeStage answers from Templates instead of the KB.
 *
 * THE anti-hallucination invariant lives here: hard facts (contact details,
 * live prices, live stock) are loaded into $fact_slots from authoritative
 * sources — the site identity document's structured field and live
 * wc_get_product() reads — never from retrieved chunk text. Downstream
 * stages inject slot values verbatim.
 */
final class RouteStage implements Stage {

	private const TEMPLATE_INTENTS = array( 'greeting', 'smalltalk', 'handoff' );
	private const CONTACT_INTENTS  = array( 'contact', 'handoff' );
	private const PRODUCT_INTENTS  = array( 'product_query', 'price_stock' );

	/**
	 * Constructor.
	 *
	 * @param Store $store KB repository (site identity document lookup).
	 */
	public function __construct( private readonly Store $store ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'route';
	}

	/**
	 * {@inheritDoc}
	 */
	public function status_event(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( ChatContext $context ): void {
		$intent = $context->intent;

		$context->route = 'handoff' === $intent ? ChatContext::ROUTE_HANDOFF : ChatContext::ROUTE_CLASSIC;

		if ( in_array( $intent, self::TEMPLATE_INTENTS, true ) ) {
			$context->meta['template'] = $intent;
		}

		if ( in_array( $intent, self::CONTACT_INTENTS, true ) ) {
			$this->load_contact_facts( $context );
		}

		if ( in_array( $intent, self::PRODUCT_INTENTS, true ) ) {
			$this->load_product_facts( $context );
		}

		$context->note( 'route', $context->route );
	}

	/**
	 * Contact facts (phone/email/address/currency) from the site identity
	 * document's structured JSON — the owner-curated source of truth, never
	 * chunk text.
	 *
	 * @param ChatContext $context Context.
	 */
	private function load_contact_facts( ChatContext $context ): void {
		$row = $this->store->document_row( 'site', SiteIdentityAdapter::EXTERNAL_ID );
		if ( ! $row || Store::STATUS_ACTIVE !== ( $row['status'] ?? '' ) ) {
			return;
		}

		$structured = json_decode( (string) ( $row['structured'] ?? '' ), true );
		if ( ! is_array( $structured ) ) {
			return;
		}

		$site     = is_array( $structured['site'] ?? null ) ? $structured['site'] : array();
		$store    = is_array( $structured['store'] ?? null ) ? $structured['store'] : array();
		$business = is_array( $structured['business'] ?? null ) ? $structured['business'] : array();

		// Phone/email may be contributed by addons/SEO plugins in any bucket.
		foreach ( array( 'phone', 'email' ) as $key ) {
			$value = (string) ( $site[ $key ] ?? $store[ $key ] ?? $business[ $key ] ?? '' );
			if ( '' !== $value ) {
				$this->set_slot( $context, $key, $value, 'site_identity' );
			}
		}

		$address = (string) ( $store['address'] ?? '' );
		if ( '' !== $address ) {
			$this->set_slot( $context, 'address', $address, 'site_identity' );
		}

		$currency = (string) ( $store['currency'] ?? '' );
		if ( '' !== $currency ) {
			$this->set_slot( $context, 'currency', $currency, 'site_identity' );
		}
	}

	/**
	 * LIVE price/stock for the first recognized product entity. The entity
	 * carries a KB document id; the product id is resolved from the document's
	 * external_id and re-read via wc_get_product() at answer time — a stale
	 * index can never quote a wrong price as fact.
	 *
	 * @param ChatContext $context Context.
	 */
	private function load_product_facts( ChatContext $context ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product_id = $this->resolve_product_id( $context );
		if ( $product_id <= 0 ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
			return;
		}

		$price = (string) $product->get_price();
		if ( '' !== $price ) {
			$this->set_slot( $context, 'price', $this->format_price( $price ), 'woocommerce_live' );
		}

		$stock = $this->stock_label( $product );
		if ( '' !== $stock ) {
			$this->set_slot( $context, 'stock', $stock, 'woocommerce_live' );
		}

		$context->note( 'live_product_id', $product_id );
	}

	/**
	 * KB document id from the first product entity → WooCommerce product id
	 * via the document's external_id.
	 *
	 * @param ChatContext $context Context.
	 */
	private function resolve_product_id( ChatContext $context ): int {
		$products = $context->entities['products'] ?? array();
		if ( ! is_array( $products ) || ! $products ) {
			return 0;
		}

		$first  = reset( $products );
		$doc_id = is_array( $first )
			? (int) ( $first['doc_id'] ?? $first['document_id'] ?? $first['id'] ?? 0 )
			: (int) $first;

		if ( $doc_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$external_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT external_id FROM ' . $wpdb->prefix . 'agyl_kb_documents WHERE id = %d AND source = %s AND status = %s',
				$doc_id,
				'product',
				Store::STATUS_ACTIVE
			)
		);

		return absint( (string) $external_id );
	}

	/**
	 * Plain-text formatted price (currency symbol included when wc_price is
	 * available).
	 *
	 * @param string $price Raw price.
	 */
	private function format_price( string $price ): string {
		if ( function_exists( 'wc_price' ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) $price ) ), ENT_QUOTES, 'UTF-8' ) );
		}

		return $price;
	}

	/**
	 * Human-readable live stock label.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function stock_label( \WC_Product $product ): string {
		$status = (string) $product->get_stock_status();

		if ( 'instock' === $status ) {
			if ( $product->managing_stock() && null !== $product->get_stock_quantity() ) {
				/* translators: %d: number of units in stock. */
				return sprintf( __( 'In stock (%d available)', 'agentyllo' ), (int) $product->get_stock_quantity() );
			}

			return __( 'In stock', 'agentyllo' );
		}
		if ( 'outofstock' === $status ) {
			return __( 'Out of stock', 'agentyllo' );
		}
		if ( 'onbackorder' === $status ) {
			return __( 'Available on backorder', 'agentyllo' );
		}

		return '';
	}

	/**
	 * Write one fact slot.
	 *
	 * @param ChatContext $context Context.
	 * @param string      $key     Slot key.
	 * @param string      $value   Verbatim value.
	 * @param string      $source  Provenance tag.
	 */
	private function set_slot( ChatContext $context, string $key, string $value, string $source ): void {
		$context->fact_slots[ $key ] = array(
			'value'  => $value,
			'source' => $source,
		);
	}
}
