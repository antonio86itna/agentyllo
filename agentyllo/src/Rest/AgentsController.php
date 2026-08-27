<?php
/**
 * Agents REST endpoints: roster, toggling, quarantine release, memory
 * inspector, journal tail.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Agents\Kernel\MemoryStore;
use Agentyllo\Agents\Kernel\Quarantine;
use Agentyllo\Agents\Kernel\Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * All routes require the agyl_manage capability.
 */
final class AgentsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param Registry    $registry   Agent registry.
	 * @param Quarantine  $quarantine Quarantine protocol.
	 * @param MemoryStore $memory     Memory store.
	 */
	public function __construct(
		private readonly Registry $registry,
		private readonly Quarantine $quarantine,
		private readonly MemoryStore $memory,
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/agents',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'roster' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/agents/(?P<id>[a-z0-9_]+)/toggle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
				'args'                => array(
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/agents/(?P<id>[a-z0-9_]+)/release',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'release' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/agents/(?P<id>[a-z0-9_]+)/memory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'memory' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
				'args'                => array(
					'kind' => array(
						'type'    => 'string',
						'default' => 'fact',
						'enum'    => array( 'fact', 'state', 'task', 'lesson', 'msg' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/agents/journal',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'journal_tail' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			)
		);
	}

	/**
	 * GET /agents.
	 */
	public function roster(): WP_REST_Response {
		$sweep   = $this->memory->recall( 'sentinel', 'last_sweep' ) ?? array();
		$summary = is_array( $sweep['summary'] ?? null ) ? $sweep['summary'] : array();

		$out = array();
		foreach ( $this->registry->all() as $id => $agent ) {
			$config = $this->registry->config( $id );
			$out[]  = array(
				'id'           => $id,
				'version'      => $agent->version(),
				'capabilities' => $agent->capabilities(),
				'enabled'      => (bool) $config['enabled'],
				'quarantine'   => $config['quarantine'],
				'health'       => $summary[ $id ] ?? null,
				'lessons'      => count( $this->memory->by_kind( $id, 'lesson', 100 ) ),
			);
		}

		return $this->respond(
			array(
				'agents'        => $out,
				'last_sweep_at' => $sweep['at'] ?? null,
			)
		);
	}

	/**
	 * POST /agents/{id}/toggle.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (string) $request['id'];

		if ( ! $this->registry->get( $id ) ) {
			return new WP_Error( 'agyl_unknown_agent', __( 'Unknown agent.', 'agentyllo' ), array( 'status' => 404 ) );
		}

		$this->registry->update_config( $id, array( 'enabled' => rest_sanitize_boolean( $request->get_param( 'enabled' ) ) ) );

		return $this->roster();
	}

	/**
	 * POST /agents/{id}/release.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function release( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (string) $request['id'];

		if ( ! $this->registry->get( $id ) ) {
			return new WP_Error( 'agyl_unknown_agent', __( 'Unknown agent.', 'agentyllo' ), array( 'status' => 404 ) );
		}

		$this->quarantine->release( $id );

		return $this->roster();
	}

	/**
	 * GET /agents/{id}/memory.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function memory( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (string) $request['id'];

		if ( ! $this->registry->get( $id ) ) {
			return new WP_Error( 'agyl_unknown_agent', __( 'Unknown agent.', 'agentyllo' ), array( 'status' => 404 ) );
		}

		$kind = (string) $request->get_param( 'kind' );

		return $this->respond(
			array(
				'agent_id' => $id,
				'kind'     => $kind,
				'memories' => $this->memory->by_kind( $id, $kind, 100 ),
			)
		);
	}

	/**
	 * GET /agents/journal — newest entries first.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function journal_tail( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$limit = (int) $request->get_param( 'limit' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent_id, task_ref, level, event, message, occurrences, created_at
				 FROM ' . $wpdb->prefix . 'agyl_agent_journal ORDER BY id DESC LIMIT %d',
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		);

		return $this->respond( array( 'entries' => (array) $rows ) );
	}
}
