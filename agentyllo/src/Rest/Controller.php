<?php
/**
 * Base REST controller.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Infra\Caps;
use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for agentyllo/v1 controllers.
 */
abstract class Controller {

	protected const REST_NAMESPACE = 'agentyllo/v1';

	/**
	 * Register routes. Called on rest_api_init.
	 */
	abstract public function register_routes(): void;

	/**
	 * Permission callback for admin surfaces (nonce-authenticated + capability).
	 *
	 * @param string $cap Agentyllo virtual capability.
	 * @return callable
	 */
	protected function require_cap( string $cap ): callable {
		return static function () use ( $cap ) {
			if ( Caps::can( $cap ) ) {
				return true;
			}

			return new WP_Error(
				'agyl_forbidden',
				__( 'You are not allowed to do that.', 'agentyllo' ),
				array( 'status' => rest_authorization_required_code() )
			);
		};
	}

	/**
	 * Build a response that must never be cached.
	 *
	 * @param mixed $data   Payload.
	 * @param int   $status HTTP status.
	 */
	protected function respond( mixed $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
