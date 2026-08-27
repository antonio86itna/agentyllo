<?php
/**
 * Privacy REST endpoints: visitor consent + admin DSAR tools.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Admin\Settings\SettingsStore;
use Agentyllo\Chat\Session\SessionManager;
use Agentyllo\Compliance\AiAct;
use Agentyllo\Compliance\Consent;
use Agentyllo\Compliance\Dsar;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /consent (visitor, session-validated) — records the pre-chat gate.
 * Admin (agyl_manage): GET /privacy/search, POST /privacy/export,
 * POST /privacy/erase, POST /privacy/transparency-page.
 */
final class PrivacyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param SessionManager $sessions Session manager.
	 * @param Consent        $consent  Consent recorder.
	 * @param Dsar           $dsar     DSAR service.
	 * @param AiAct          $ai_act   AI Act surfaces.
	 * @param SettingsStore  $settings Settings store.
	 */
	public function __construct(
		private readonly SessionManager $sessions,
		private readonly Consent $consent,
		private readonly Dsar $dsar,
		private readonly AiAct $ai_act,
		private readonly SettingsStore $settings,
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/consent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'consent' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'name'     => array( 'type' => 'string', 'required' => false ),
					'email'    => array( 'type' => 'string', 'required' => false ),
					'accepted' => array( 'type' => 'boolean', 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/privacy/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
				'args'                => array( 'email' => array( 'type' => 'string', 'required' => true ) ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/privacy/export',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
				'args'                => array( 'email' => array( 'type' => 'string', 'required' => true ) ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/privacy/erase',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'erase' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
				'args'                => array(
					'email'   => array( 'type' => 'string', 'required' => true ),
					'confirm' => array( 'type' => 'boolean', 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/privacy/transparency-page',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'transparency_page' ),
				'permission_callback' => $this->require_cap( 'agyl_manage' ),
			)
		);
	}

	/**
	 * POST /consent — visitor pre-chat gate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function consent( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$session = $this->sessions->validate( (string) $request->get_header( 'X-Agyl-Session' ) );
		if ( ! $session ) {
			return new WP_Error( 'agyl_invalid_session', __( 'Invalid or expired chat session.', 'agentyllo' ), array( 'status' => 401 ) );
		}

		$privacy = $this->settings->get( 'privacy' );
		$gate    = (string) $privacy['registration_gate'];

		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( 'name_email' === $gate ) {
			if ( '' === $name || '' === $email || ! is_email( $email ) ) {
				return new WP_Error( 'agyl_gate_fields', __( 'Please enter your name and a valid email address.', 'agentyllo' ), array( 'status' => 400 ) );
			}
		}

		if ( ! empty( $privacy['privacy_checkbox_required'] ) && ! rest_sanitize_boolean( $request->get_param( 'accepted' ) ) ) {
			return new WP_Error( 'agyl_gate_consent', __( 'Please accept the privacy policy to continue.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		$label = trim( (string) $privacy['privacy_checkbox_label'] );
		if ( '' === $label ) {
			$label = __( 'I have read and accept the privacy policy.', 'agentyllo' );
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : null;
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : null;

		$consent_id = $this->consent->record(
			(int) $session['id'],
			$name,
			$email,
			'name_email' === $gate ? Consent::TYPE_REGISTRATION : Consent::TYPE_PRIVACY,
			(string) $privacy['policy_version'],
			$label,
			'none' === (string) $privacy['ip_mode'] ? null : $ip,
			$ua,
			! empty( $privacy['consent_logging'] )
		);

		return $this->respond(
			array(
				'ok'         => true,
				'gated'      => true,
				'consent_id' => $consent_id,
			),
			201
		);
	}

	/**
	 * GET /privacy/search?email=.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'agyl_invalid_email', __( 'Enter a valid email address.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		return $this->respond( array( 'email' => $email ) + $this->dsar->summary( $email ) );
	}

	/**
	 * POST /privacy/export — returns the export inline (JSON) and stores a
	 * copy in the protected uploads dir for 72h.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'agyl_invalid_email', __( 'Enter a valid email address.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		$this->dsar->export_to_file( $email );

		return $this->respond( $this->dsar->export( $email ) );
	}

	/**
	 * POST /privacy/erase (confirm=true required).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function erase( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'agyl_invalid_email', __( 'Enter a valid email address.', 'agentyllo' ), array( 'status' => 400 ) );
		}
		if ( ! rest_sanitize_boolean( $request->get_param( 'confirm' ) ) ) {
			return new WP_Error( 'agyl_confirm_required', __( 'Erasure must be explicitly confirmed.', 'agentyllo' ), array( 'status' => 400 ) );
		}

		return $this->respond( array( 'ok' => true ) + $this->dsar->erase( $email ) );
	}

	/**
	 * POST /privacy/transparency-page — create/return the draft page.
	 */
	public function transparency_page(): WP_REST_Response|WP_Error {
		$id = $this->ai_act->ensure_page();
		if ( ! $id ) {
			return new WP_Error( 'agyl_page_failed', __( 'Could not create the transparency page.', 'agentyllo' ), array( 'status' => 500 ) );
		}

		return $this->respond(
			array(
				'page_id'  => $id,
				'edit_url' => get_edit_post_link( $id, 'raw' ),
				'view_url' => get_permalink( $id ),
				'status'   => get_post_status( $id ),
			)
		);
	}
}
