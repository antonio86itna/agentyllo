<?php
/**
 * Settings REST endpoints.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Admin\Settings\SettingsStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET/PUT agentyllo/v1/settings/{tab}.
 */
final class SettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param SettingsStore                            $store  Settings store.
	 * @param \Agentyllo\Admin\Settings\SettingsSchema $schema Settings schema.
	 */
	public function __construct(
		private readonly SettingsStore $store,
		private readonly \Agentyllo\Admin\Settings\SettingsSchema $schema,
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings/(?P<tab>[a-z_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tab' ),
					'permission_callback' => $this->require_cap( 'agyl_manage_settings' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_tab' ),
					'permission_callback' => $this->require_cap( 'agyl_manage_settings' ),
					'args'                => array(
						'values' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_tabs' ),
				'permission_callback' => $this->require_cap( 'agyl_manage_settings' ),
			)
		);
	}

	/**
	 * GET /settings — tab ids.
	 */
	public function list_tabs(): WP_REST_Response {
		return $this->respond( array( 'tabs' => $this->store->tabs() ) );
	}

	/**
	 * GET /settings/{tab}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tab( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tab = (string) $request['tab'];

		if ( ! $this->store->has_tab( $tab ) ) {
			return new WP_Error( 'agyl_unknown_tab', __( 'Unknown settings tab.', 'agentyllo' ), array( 'status' => 404 ) );
		}

		return $this->respond( $this->tab_payload( $tab ) );
	}

	/**
	 * PUT /settings/{tab}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_tab( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tab = (string) $request['tab'];

		if ( ! $this->store->has_tab( $tab ) ) {
			return new WP_Error( 'agyl_unknown_tab', __( 'Unknown settings tab.', 'agentyllo' ), array( 'status' => 404 ) );
		}

		$values = $request->get_param( 'values' );
		if ( ! is_array( $values ) ) {
			return new WP_Error( 'agyl_invalid_values', __( 'Settings payload must be an object.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		$this->store->update( $tab, $values );

		return $this->respond( $this->tab_payload( $tab ) );
	}

	/**
	 * Standard tab payload: values + client-safe schema.
	 *
	 * @param string $tab Tab id.
	 */
	private function tab_payload( string $tab ): array {
		return array(
			'tab'    => $tab,
			'values' => $this->schema->redact( $tab, $this->store->get( $tab ) ),
			'schema' => $this->schema->describe( $tab ),
		);
	}
}
