<?php
/**
 * Admin REST: copilot drawer + file ingestion.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Copilot\Copilot;
use Agentyllo\Copilot\FileIngest;
use Agentyllo\Stats\Stats;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /copilot/message {text} → {blocks} (text / links / action_proposal /
 * action_result). POST /copilot/confirm {action, args, token} → result.
 * GET /copilot/actions → registry descriptions (Help page). POST
 * /copilot/upload (multipart) → parsed preview rows; POST /copilot/ingest
 * {rows} → KB entries. Everything requires agyl_use_copilot (actions check
 * their own finer capability).
 */
final class CopilotController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param Copilot    $copilot Copilot orchestrator.
	 * @param FileIngest $ingest  File ingestion.
	 * @param Stats      $stats   Statistics (proactive suggestions).
	 */
	public function __construct(
		private readonly Copilot $copilot,
		private readonly FileIngest $ingest,
		private readonly Stats $stats,
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/copilot/message',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_message' ),
				'permission_callback' => $this->require_cap( 'agyl_use_copilot' ),
				'args'                => array(
					'text'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'history' => array(
						'type'     => 'array',
						'required' => false,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/copilot/suggestions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_suggestions' ),
				'permission_callback' => $this->require_cap( 'agyl_use_copilot' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/copilot/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_confirm' ),
				'permission_callback' => $this->require_cap( 'agyl_use_copilot' ),
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
					),
					'args'   => array(
						'type'     => 'object',
						'required' => true,
					),
					'token'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/copilot/actions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_actions' ),
				'permission_callback' => $this->require_cap( 'agyl_use_copilot' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/copilot/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_upload' ),
				'permission_callback' => $this->require_cap( 'agyl_manage_kb' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/copilot/ingest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_ingest' ),
				'permission_callback' => $this->require_cap( 'agyl_manage_kb' ),
				'args'                => array(
					'rows' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * POST /copilot/message.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_message( WP_REST_Request $request ): WP_REST_Response {
		$text = trim( (string) $request['text'] );
		if ( '' === $text ) {
			return $this->respond( array( 'blocks' => array() ) );
		}

		$history = array();
		$raw     = $request->get_param( 'history' );
		if ( is_array( $raw ) ) {
			foreach ( array_slice( $raw, -6 ) as $turn ) {
				if ( is_array( $turn ) ) {
					$history[] = array(
						'role' => 'assistant' === ( $turn['role'] ?? '' ) ? 'assistant' : 'user',
						'text' => sanitize_textarea_field( (string) ( $turn['text'] ?? '' ) ),
					);
				}
			}
		}

		return $this->respond( $this->copilot->handle( mb_substr( $text, 0, 4000 ), $history ) );
	}

	/**
	 * GET /copilot/suggestions — proactive, one-click cards the owner can act
	 * on (top unanswered visitor questions → draft a KB answer). Clicking a
	 * card prefills the composer; with AI on, the copilot drafts the entry and
	 * proposes it for confirmation.
	 */
	public function get_suggestions(): WP_REST_Response {
		$items = array();
		foreach ( $this->stats->unanswered( 5 ) as $row ) {
			$q = trim( (string) ( $row['question_sample'] ?? '' ) );
			if ( '' === $q ) {
				continue;
			}
			$hits = (int) ( $row['hits'] ?? 1 );
			$items[] = array(
				'id'      => 'unanswered_' . (int) ( $row['id'] ?? 0 ),
				'icon'    => 'help',
				'title'   => sprintf(
					/* translators: %d: number of times asked */
					_n( 'A visitor asked (unanswered):', '%d visitors asked (unanswered):', $hits, 'agentyllo' ),
					$hits
				),
				'text'    => mb_substr( $q, 0, 140 ),
				'prefill' => sprintf(
					/* translators: %s: the visitor question */
					__( 'Draft a knowledge base entry that answers: "%s"', 'agentyllo' ),
					mb_substr( $q, 0, 200 )
				),
			);
		}

		return $this->respond( array( 'suggestions' => $items ) );
	}

	/**
	 * POST /copilot/confirm.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_confirm( WP_REST_Request $request ): WP_REST_Response {
		$args = $request->get_param( 'args' );

		return $this->respond(
			array(
				'blocks' => array(
					$this->copilot->confirm( sanitize_text_field( (string) $request['action'] ), is_array( $args ) ? $args : array(), (string) $request['token'] ),
				),
			)
		);
	}

	/**
	 * GET /copilot/actions.
	 */
	public function get_actions(): WP_REST_Response {
		return $this->respond( array( 'actions' => $this->copilot->describe_actions() ) );
	}

	/**
	 * POST /copilot/upload — multipart 'file' (TXT/MD/CSV) → preview rows.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'agyl_no_file', __( 'No file uploaded.', 'agentyllo' ), array( 'status' => 400 ) );
		}
		$result = $this->ingest->parse_upload( (string) $file['tmp_name'], (string) ( $file['name'] ?? 'upload' ), (int) ( $file['size'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->respond( $result );
	}

	/**
	 * POST /copilot/ingest — commit reviewed rows as KB entries.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_ingest( WP_REST_Request $request ): WP_REST_Response {
		$rows = $request->get_param( 'rows' );

		return $this->respond( $this->ingest->commit( is_array( $rows ) ? $rows : array() ) );
	}
}
