<?php
/**
 * Capability report REST endpoints.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\AI\Capability\Detector;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET agentyllo/v1/capabilities, POST agentyllo/v1/capabilities/rescan.
 */
final class CapabilitiesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param Detector $detector Capability detector.
	 */
	public function __construct( private readonly Detector $detector ) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/capabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => $this->require_cap( 'agy_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/capabilities/rescan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rescan' ),
				'permission_callback' => $this->require_cap( 'agy_manage' ),
			)
		);
	}

	/**
	 * GET /capabilities.
	 */
	public function get_report(): WP_REST_Response {
		return $this->respond( $this->detector->report() );
	}

	/**
	 * POST /capabilities/rescan — force a fresh scan.
	 */
	public function rescan(): WP_REST_Response {
		return $this->respond( $this->detector->detect( true ) );
	}
}
