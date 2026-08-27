<?php
/**
 * Knowledge-base admin REST endpoints.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\KB\Indexer\IndexManager;
use Agentyllo\KB\Store;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * agentyllo/v1/kb/*: coverage overview, reindex trigger, per-item include
 * toggles, and the indexed-entries listing behind the Knowledge Base page.
 */
final class KbController extends Controller {

	/**
	 * Sources whose real items can be enumerated for the per-item picker.
	 */
	private const ITEM_SOURCES = array( 'post', 'product', 'taxonomy', 'menu' );

	/**
	 * Sources whose items may be toggled. 'manual' is deliberately absent:
	 * manual entries are managed via DELETE /kb/entries/{id} only.
	 */
	private const TOGGLE_SOURCES = array( 'post', 'product', 'taxonomy', 'menu', 'site' );

	/**
	 * Constructor.
	 *
	 * @param Store        $store         KB repository.
	 * @param IndexManager $index_manager Crawl coordinator.
	 */
	public function __construct(
		private readonly Store $store,
		private readonly IndexManager $index_manager,
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/overview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_overview' ),
				'permission_callback' => $this->require_cap( 'agyl_manage_kb' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/reindex',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reindex' ),
				'permission_callback' => $this->require_cap( 'agyl_manage_kb' ),
				'args'                => array(
					'source' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => $this->require_cap( 'agyl_manage_kb' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/items/toggle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_item' ),
				'permission_callback' => $this->require_cap( 'agyl_manage_kb' ),
				'args'                => array(
					'source'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'external_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'subtype'     => array(
						'type'     => 'string',
						'required' => false,
					),
					'include'     => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/entries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_entries' ),
				'permission_callback' => $this->require_cap( 'agyl_manage_kb' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/entries/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_entry' ),
				'permission_callback' => $this->require_cap( 'agyl_manage_kb' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /kb/overview — coverage per source/subtype plus health counters.
	 */
	public function get_overview(): WP_REST_Response {
		$coverage = array();

		foreach ( $this->store->counts() as $row ) {
			$source  = $row['source'];
			$subtype = $row['subtype'];

			if ( ! isset( $coverage[ $source ][ $subtype ] ) ) {
				$coverage[ $source ][ $subtype ] = array(
					'active_docs' => 0,
					'chunks'      => 0,
					'excluded'    => 0,
					'purging'     => 0,
					'error'       => 0,
				);
			}

			switch ( $row['status'] ) {
				case Store::STATUS_ACTIVE:
					$coverage[ $source ][ $subtype ]['active_docs'] += $row['docs'];
					$coverage[ $source ][ $subtype ]['chunks']      += $row['chunks'];
					break;
				case Store::STATUS_EXCLUDED:
					$coverage[ $source ][ $subtype ]['excluded'] += $row['docs'];
					break;
				case Store::STATUS_PURGING:
					$coverage[ $source ][ $subtype ]['purging'] += $row['docs'];
					break;
				case Store::STATUS_ERROR:
					$coverage[ $source ][ $subtype ]['error'] += $row['docs'];
					break;
			}
		}

		return $this->respond(
			array(
				'coverage'   => $coverage ? $coverage : (object) array(),
				'health'     => (array) get_option( 'agyl_kb_health', array() ),
				'kb_version' => (int) get_option( 'agyl_kb_version', 0 ),
				'last_crawl' => get_option( 'agyl_kb_last_crawl', null ),
			)
		);
	}

	/**
	 * POST /kb/reindex — start a full crawl (optionally one source).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reindex( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = sanitize_key( (string) $request->get_param( 'source' ) );

		if ( ! $this->index_manager->start_full_crawl( '' !== $source ? $source : null ) ) {
			return new WP_Error(
				'agyl_no_crawl_target',
				__( 'No enabled source matches that id.', 'agentyllo' ),
				array( 'status' => 400 )
			);
		}

		return $this->respond( array( 'ok' => true ) );
	}

	/**
	 * GET /kb/items — real items of one source merged with their KB state
	 * (indexed | excluded | not_indexed) for the per-item picker.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source              = sanitize_key( (string) $request->get_param( 'source' ) );
		$subtype             = sanitize_key( (string) $request->get_param( 'subtype' ) );
		$search              = sanitize_text_field( (string) $request->get_param( 'search' ) );
		[ $page, $per_page ] = $this->paging( $request );

		if ( ! in_array( $source, self::ITEM_SOURCES, true ) ) {
			return new WP_Error( 'agyl_invalid_source', __( 'Unknown item source.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		switch ( $source ) {
			case 'product':
				$listing = $this->product_items( $search, $page, $per_page );
				break;
			case 'taxonomy':
				$listing = $this->taxonomy_items( $subtype, $search, $page, $per_page );
				break;
			case 'menu':
				$listing = $this->menu_items( $search, $page, $per_page );
				break;
			default:
				$listing = $this->post_items( $subtype, $search, $page, $per_page );
		}

		if ( $listing instanceof WP_Error ) {
			return $listing;
		}

		[ $items, $total ] = $listing;

		$states = $this->kb_states( $source, array_column( $items, 'external_id' ) );
		foreach ( $items as &$item ) {
			$item['state'] = $states[ $item['external_id'] ] ?? 'not_indexed';
		}
		unset( $item );

		return $this->respond(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * POST /kb/items/toggle — include/exclude one item. Excluding writes a
	 * tombstone (data deleted now, row kept); re-including removes it and
	 * queues an async re-index of the item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source      = substr( sanitize_key( (string) $request->get_param( 'source' ) ), 0, 32 );
		$external_id = substr( sanitize_text_field( (string) $request->get_param( 'external_id' ) ), 0, 64 );
		$subtype     = sanitize_key( (string) $request->get_param( 'subtype' ) );
		$include     = rest_sanitize_boolean( $request->get_param( 'include' ) );

		if ( '' === $source || '' === $external_id ) {
			return new WP_Error( 'agyl_invalid_item', __( 'A source and item id are required.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		/*
		 * Only real, upstream-backed sources may be tombstoned. 'manual'
		 * rows ARE the content (irreversible loss) — they go through
		 * DELETE /kb/entries/{id}. Unknown sources would create permanent
		 * garbage tombstones no reconcile ever cleans.
		 */
		if ( ! in_array( $source, self::TOGGLE_SOURCES, true ) ) {
			return new WP_Error( 'agyl_invalid_source', __( 'This source cannot be toggled per item.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		if ( $include ) {
			$this->store->remove_tombstone( $source, $external_id );
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'agyl_kb_index_item', array( $source, $external_id ), 'agentyllo-kb', true );
			}
			$state = 'not_indexed'; // Re-index queued; becomes 'indexed' when the job lands.
		} else {
			$this->store->tombstone( $source, $external_id, $subtype );
			$state = 'excluded';
		}

		return $this->respond(
			array(
				'ok'    => true,
				'state' => $state,
			)
		);
	}

	/**
	 * GET /kb/entries — paginated documents listing with filters.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_entries( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$search              = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$source              = sanitize_key( (string) $request->get_param( 'source' ) );
		$status              = sanitize_key( (string) $request->get_param( 'status' ) );
		[ $page, $per_page ] = $this->paging( $request );

		$statuses = array( Store::STATUS_ACTIVE, Store::STATUS_EXCLUDED, Store::STATUS_PURGING, Store::STATUS_ERROR );
		if ( ! in_array( $status, $statuses, true ) ) {
			$status = '';
		}

		// Placeholder-bearing base clause keeps $wpdb->prepare() valid with no filters.
		$where = array( '1 = %d' );
		$args  = array( 1 );

		if ( '' !== $search ) {
			$where[] = 'title LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( '' !== $source ) {
			$where[] = 'source = %s';
			$args[]  = $source;
		}
		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$table     = $wpdb->prefix . 'agyl_kb_documents';
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$args )
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source, external_id, subtype, status, title, permalink, chunk_count, lang, indexed_at
				 FROM {$table} WHERE {$where_sql} ORDER BY indexed_at DESC, id DESC LIMIT %d OFFSET %d",
				...array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array_map(
			static fn ( array $row ): array => array(
				'id'          => (int) $row['id'],
				'source'      => (string) $row['source'],
				'external_id' => (string) $row['external_id'],
				'subtype'     => (string) $row['subtype'],
				'status'      => (string) $row['status'],
				'title'       => (string) $row['title'],
				'permalink'   => (string) $row['permalink'],
				'chunk_count' => (int) $row['chunk_count'],
				'lang'        => (string) $row['lang'],
				'indexed_at'  => (string) $row['indexed_at'],
			),
			(array) $rows
		);

		return $this->respond(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * DELETE /kb/entries/{id} — manual documents are deleted outright; source
	 * documents become tombstones (excluded) so reconciliation cannot re-add
	 * them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_entry( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id = (int) $request['id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT source, external_id, subtype FROM ' . $wpdb->prefix . 'agyl_kb_documents WHERE id = %d',
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'agyl_not_found', __( 'Knowledge base entry not found.', 'agentyllo' ), array( 'status' => 404 ) );
		}

		if ( 'manual' === $row['source'] ) {
			$this->store->delete( (string) $row['source'], (string) $row['external_id'] );
			$mode = 'deleted';
		} else {
			$this->store->tombstone( (string) $row['source'], (string) $row['external_id'], (string) $row['subtype'] );
			$mode = 'excluded';
		}

		return $this->respond(
			array(
				'ok'   => true,
				'mode' => $mode,
			)
		);
	}

	/**
	 * KB state per external id, from one query over the page's ids.
	 *
	 * @param string   $source       Adapter id.
	 * @param string[] $external_ids External ids on the current page.
	 * @return array<string, string> external_id => indexed|excluded|not_indexed.
	 */
	private function kb_states( string $source, array $external_ids ): array {
		global $wpdb;

		if ( ! $external_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $external_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list is built from count(external_ids).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT external_id, status FROM ' . $wpdb->prefix . "agyl_kb_documents WHERE source = %s AND external_id IN ({$placeholders})",
				$source,
				...$external_ids
			),
			ARRAY_A
		);

		$states = array();
		foreach ( (array) $rows as $row ) {
			$states[ (string) $row['external_id'] ] = match ( (string) $row['status'] ) {
				Store::STATUS_ACTIVE   => 'indexed',
				Store::STATUS_EXCLUDED => 'excluded',
				default                => 'not_indexed',
			};
		}

		return $states;
	}

	/**
	 * Published posts of one post type.
	 *
	 * @param string $subtype  Post type ('' = 'post').
	 * @param string $search   Search term.
	 * @param int    $page     Page number.
	 * @param int    $per_page Items per page.
	 * @return array{0: array<int, array<string, string>>, 1: int}
	 */
	private function post_items( string $subtype, string $search, int $page, int $per_page ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => '' !== $subtype ? $subtype : 'post',
				'post_status'    => 'publish',
				's'              => $search,
				'paged'          => $page,
				'posts_per_page' => $per_page,
				'orderby'        => '' === $search ? 'title' : 'relevance',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$items = array();
		foreach ( $query->posts as $post_id ) {
			$items[] = array(
				'external_id' => (string) $post_id,
				'subtype'     => '' !== $subtype ? $subtype : (string) get_post_type( (int) $post_id ),
				'title'       => (string) get_the_title( (int) $post_id ),
				'permalink'   => (string) get_permalink( (int) $post_id ),
			);
		}

		return array( $items, (int) $query->found_posts );
	}

	/**
	 * Published WooCommerce products.
	 *
	 * @param string $search   Search term.
	 * @param int    $page     Page number.
	 * @param int    $per_page Items per page.
	 * @return array{0: array<int, array<string, string>>, 1: int}|WP_Error
	 */
	private function product_items( string $search, int $page, int $per_page ): array|WP_Error {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'agyl_woocommerce_inactive', __( 'WooCommerce is not active.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		$result = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => $per_page,
				'page'     => $page,
				'paginate' => true,
				'orderby'  => 'title',
				'order'    => 'ASC',
				's'        => $search,
			)
		);

		$items = array();
		foreach ( (array) ( $result->products ?? array() ) as $product ) {
			$items[] = array(
				'external_id' => (string) $product->get_id(),
				'subtype'     => '',
				'title'       => (string) $product->get_name(),
				'permalink'   => (string) $product->get_permalink(),
			);
		}

		return array( $items, (int) ( $result->total ?? 0 ) );
	}

	/**
	 * Terms of one taxonomy (or all public taxonomies).
	 *
	 * @param string $subtype  Taxonomy name ('' = all public).
	 * @param string $search   Search term.
	 * @param int    $page     Page number.
	 * @param int    $per_page Items per page.
	 * @return array{0: array<int, array<string, string>>, 1: int}
	 */
	private function taxonomy_items( string $subtype, string $search, int $page, int $per_page ): array {
		$taxonomies = '' !== $subtype
			? array( $subtype )
			: array_values( get_taxonomies( array( 'public' => true ) ) );

		$common = array(
			'taxonomy'   => $taxonomies,
			'hide_empty' => false,
		);
		if ( '' !== $search ) {
			$common['search'] = $search;
		}

		$total = get_terms( array_merge( $common, array( 'fields' => 'count' ) ) );
		$terms = get_terms(
			array_merge(
				$common,
				array(
					'number'  => $per_page,
					'offset'  => ( $page - 1 ) * $per_page,
					'orderby' => 'name',
				)
			)
		);

		$items = array();
		foreach ( is_wp_error( $terms ) ? array() : (array) $terms as $term ) {
			$link    = get_term_link( $term );
			$items[] = array(
				'external_id' => (string) $term->term_id,
				'subtype'     => (string) $term->taxonomy,
				'title'       => (string) $term->name,
				'permalink'   => is_wp_error( $link ) ? '' : (string) $link,
			);
		}

		return array( $items, is_wp_error( $total ) ? count( $items ) : (int) $total );
	}

	/**
	 * Navigation menus (filtered and paginated in memory — sites have few).
	 *
	 * @param string $search   Search term.
	 * @param int    $page     Page number.
	 * @param int    $per_page Items per page.
	 * @return array{0: array<int, array<string, string>>, 1: int}
	 */
	private function menu_items( string $search, int $page, int $per_page ): array {
		$menus = wp_get_nav_menus();

		if ( '' !== $search ) {
			$menus = array_values(
				array_filter(
					$menus,
					static fn ( $menu ): bool => false !== stripos( (string) $menu->name, $search )
				)
			);
		}

		$total = count( $menus );
		$slice = array_slice( $menus, ( $page - 1 ) * $per_page, $per_page );

		$items = array();
		foreach ( $slice as $menu ) {
			$items[] = array(
				'external_id' => (string) $menu->term_id,
				'subtype'     => '',
				'title'       => (string) $menu->name,
				'permalink'   => '',
			);
		}

		return array( $items, $total );
	}

	/**
	 * Clamped pagination params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{0: int, 1: int} [page, per_page].
	 */
	private function paging( WP_REST_Request $request ): array {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : 20;

		return array( $page, $per_page );
	}
}
