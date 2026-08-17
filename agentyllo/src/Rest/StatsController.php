<?php
/**
 * Statistics + conversations REST endpoints (admin).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Stats\Stats;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /stats/{report}?days=, POST /stats/unanswered/{id}, GET
 * /conversations, GET /conversations/{id}, POST /stats/rollup.
 */
final class StatsController extends Controller {

	private const RANGES = array( 7, 30, 90 );

	/**
	 * Constructor.
	 *
	 * @param Stats $stats Stats service.
	 */
	public function __construct( private readonly Stats $stats ) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/overview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => $this->require_cap( 'agy_view_stats' ),
				'args'                => array( 'days' => array( 'type' => 'integer', 'default' => 30 ) ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/unanswered/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_unanswered' ),
				'permission_callback' => $this->require_cap( 'agy_view_stats' ),
				'args'                => array(
					'status' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'open', 'dismissed', 'resolved' ) ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/rollup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rollup' ),
				'permission_callback' => $this->require_cap( 'agy_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/conversations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'conversations' ),
				'permission_callback' => $this->require_cap( 'agy_view_stats' ),
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/conversations/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'transcript' ),
				'permission_callback' => $this->require_cap( 'agy_view_stats' ),
			)
		);
	}

	/**
	 * GET /stats/overview.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function overview( WP_REST_Request $request ): WP_REST_Response {
		$days = (int) $request->get_param( 'days' );
		if ( ! in_array( $days, self::RANGES, true ) ) {
			$days = 30;
		}

		// Free tier: 30 days max (Pro unlocks 90 + CSV).
		if ( $days > 30 && ! \Agentyllo\Plugin::feature_enabled( 'advanced_analytics' ) ) {
			$days = 30;
		}

		return $this->respond(
			array(
				'days'       => $days,
				'totals'     => $this->stats->totals( $days ),
				'daily'      => $this->stats->daily( $days ),
				'intents'    => $this->stats->top_intents( $days ),
				'unanswered' => $this->stats->unanswered( 20 ),
			)
		);
	}

	/**
	 * POST /stats/unanswered/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_unanswered( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ok = $this->stats->set_unanswered_status( (int) $request['id'], (string) $request->get_param( 'status' ) );
		if ( ! $ok ) {
			return new WP_Error( 'agy_update_failed', __( 'Could not update the item.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		return $this->respond( array( 'ok' => true, 'unanswered' => $this->stats->unanswered( 20 ) ) );
	}

	/**
	 * POST /stats/rollup — recompute the last 7 days on demand.
	 */
	public function rollup(): WP_REST_Response {
		$this->stats->rollup( 7 );

		return $this->respond( array( 'ok' => true ) );
	}

	/**
	 * GET /conversations.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function conversations( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond( $this->stats->conversations( (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) ) );
	}

	/**
	 * GET /conversations/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function transcript( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$conv = $this->stats->transcript( (int) $request['id'] );
		if ( ! $conv ) {
			return new WP_Error( 'agy_not_found', __( 'Conversation not found.', 'agentyllo' ), array( 'status' => 404 ) );
		}

		return $this->respond( $conv );
	}
}
