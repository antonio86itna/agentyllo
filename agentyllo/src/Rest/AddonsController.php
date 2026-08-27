<?php
/**
 * Addon catalog REST endpoint.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Admin\AddonCatalog;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET agentyllo/v1/addons — the catalog rendered by the Addons admin page.
 * Data only; nothing here gates or unlocks plugin functionality.
 */
final class AddonsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param AddonCatalog $catalog Addon catalog.
	 */
	public function __construct( private readonly AddonCatalog $catalog ) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/addons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_addons' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
			)
		);
	}

	/**
	 * GET /addons.
	 */
	public function list_addons(): WP_REST_Response {
		return $this->respond( array( 'addons' => $this->catalog->all() ) );
	}
}
