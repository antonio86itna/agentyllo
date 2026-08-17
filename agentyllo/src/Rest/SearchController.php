<?php
/**
 * KB search REST endpoint (admin test surface).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\KB\Retrieval\HybridRetriever;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET agentyllo/v1/kb/search?q=&limit= — direct HybridRetriever results for
 * the admin "test my knowledge base" surface. Content is truncated so the
 * response stays light; the chat pipeline uses the retriever directly.
 */
final class SearchController extends Controller {

	private const CONTENT_TRUNCATE = 300;

	/**
	 * Constructor.
	 *
	 * @param HybridRetriever $retriever Retrieval engine.
	 */
	public function __construct( private readonly HybridRetriever $retriever ) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_search' ),
				'permission_callback' => $this->require_cap( 'agy_manage' ),
				'args'                => array(
					'q'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'type'    => 'integer',
						'default' => 8,
						'minimum' => 1,
						'maximum' => 20,
					),
					'lang'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET /kb/search.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_search( WP_REST_Request $request ): WP_REST_Response {
		$query = trim( (string) $request['q'] );
		$limit = max( 1, min( 20, (int) $request['limit'] ) );
		$lang  = (string) $request['lang'];

		$results = $this->retriever->search(
			$query,
			array(
				'lang'  => $lang,
				'limit' => $limit,
			)
		);

		foreach ( $results as &$row ) {
			if ( mb_strlen( $row['content'] ) > self::CONTENT_TRUNCATE ) {
				$row['content'] = mb_substr( $row['content'], 0, self::CONTENT_TRUNCATE ) . '…';
			}
		}
		unset( $row );

		return $this->respond(
			array(
				'query'   => $query,
				'count'   => count( $results ),
				'results' => $results,
			)
		);
	}
}
