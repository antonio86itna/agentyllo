<?php
/**
 * Public chat REST endpoints: session, messages, feedback.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\Admin\Settings\SettingsStore;
use Agentyllo\Chat\ConversationLog;
use Agentyllo\Chat\Pipeline\ChatContext;
use Agentyllo\Chat\Pipeline\Pipeline;
use Agentyllo\Chat\RateLimiter;
use Agentyllo\Chat\Session\SessionManager;
use Agentyllo\Chat\Transport\SseEmitter;
use Agentyllo\Compliance\Redactor;
use Agentyllo\Stats\Stats;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The visitor-facing chat surface. No WP auth, no cookies: POST /session
 * mints an HMAC token, every other route validates the X-Agyl-Session header.
 * Errors are hand-built WP_REST_Response objects in the {code, message,
 * data:{status}} shape so 429s can carry a Retry-After header (WP_Error
 * cannot). Everything here is no-store — /config is the only cacheable
 * chat route.
 */
final class ChatController extends Controller {

	private const SESSION_RATE_LIMIT = 10;
	private const TEXT_MAX           = 1000;
	private const IDEMPOTENCY_TTL    = 60;
	private const LANG_CONFIDENCE    = 0.7;

	/**
	 * Constructor.
	 *
	 * @param SessionManager  $sessions Session manager.
	 * @param RateLimiter     $limiter  Rate limiter.
	 * @param ConversationLog $log      Conversation log.
	 * @param Pipeline        $pipeline Chat pipeline (classic stages injected).
	 * @param SettingsStore   $settings Settings store.
	 */
	public function __construct(
		private readonly SessionManager $sessions,
		private readonly RateLimiter $limiter,
		private readonly ConversationLog $log,
		private readonly Pipeline $pipeline,
		private readonly SettingsStore $settings,
		private readonly Stats $stats,
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_session' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/messages',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_message' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'text'          => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'client_msg_id' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/feedback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_feedback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'rating'     => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'up', 'down' ),
					),
					'comment'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * POST /session — mint a visitor session token (rate-limited per IP).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_session( WP_REST_Request $request ): WP_REST_Response {
		$ip = $this->remote_ip();

		if ( null !== $ip ) {
			$bucket = RateLimiter::bucket_ip( SessionManager::hash_ip( $ip ), 'session' );
			if ( ! $this->limiter->allow( $bucket, self::SESSION_RATE_LIMIT, MINUTE_IN_SECONDS ) ) {
				return $this->error(
					'agyl_rate_limited',
					__( 'Too many requests — please slow down.', 'agentyllo' ),
					429,
					array( 'Retry-After' => '60' )
				);
			}
		}

		$ip_mode    = (string) $this->settings->value( 'privacy', 'ip_mode' );
		$stored_ip  = SessionManager::store_ip( $ip, $ip_mode );
		$session    = $this->sessions->create_stored( $stored_ip );
		if ( null === $session ) {
			return $this->error( 'agyl_session_failed', __( 'Could not start a chat session.', 'agentyllo' ), 500 );
		}

		return $this->respond(
			array(
				'token'   => $session['token'],
				'expires' => $session['expires'],
			),
			201
		);
	}

	/**
	 * POST /messages — run one visitor message through the pipeline.
	 *
	 * Transport ladder: when the client accepts text/event-stream and the
	 * performance setting allows it, the response is an SSE stream generated
	 * in-request (status events live, AI deltas as they arrive, then the
	 * authoritative `message` event); otherwise a buffered JSON response
	 * carrying the same events for synthetic replay. Both paths share every
	 * validation, persistence and learning step below.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_message( WP_REST_Request $request ): WP_REST_Response {
		$session = $this->validate_session( $request );
		if ( null === $session ) {
			return $this->error( 'agyl_invalid_session', __( 'Your chat session is invalid or expired.', 'agentyllo' ), 401 );
		}

		$session_id = (int) $session['id'];
		$text       = trim( (string) $request['text'] );
		if ( '' === $text || mb_strlen( $text ) > self::TEXT_MAX ) {
			return $this->error(
				'agyl_invalid_text',
				sprintf(
					/* translators: %d: maximum message length in characters. */
					__( 'Messages must be between 1 and %d characters.', 'agentyllo' ),
					self::TEXT_MAX
				),
				400
			);
		}

		// Idempotency: a retried client_msg_id replays the cached response.
		$client_msg_id = trim( (string) $request['client_msg_id'] );
		$transient_key = '' === $client_msg_id ? '' : 'agyl_msg_' . $session_id . '_' . md5( $client_msg_id );
		if ( '' !== $transient_key ) {
			$cached = get_transient( $transient_key );
			if ( is_array( $cached ) ) {
				return $this->respond( $cached );
			}
		}

		// Pre-chat gate: when registration is required, the session must have
		// recorded a consent (POST /consent) before any message is accepted.
		$privacy = $this->settings->get( 'privacy' );
		if ( 'off' !== (string) $privacy['registration_gate'] && empty( $session['gated'] ) ) {
			return $this->error( 'agyl_gate_required', __( 'Please complete the short form before starting the chat.', 'agentyllo' ), 403 );
		}

		$throttled = $this->check_message_limits( $session_id );
		if ( null !== $throttled ) {
			return $throttled;
		}

		$ip      = $this->remote_ip();
		$ip_hash = null !== $ip
			? SessionManager::hash_ip( $ip )
			: ( is_string( $session['ip_hash'] ?? null ) ? $session['ip_hash'] : null );

		$session_meta = json_decode( (string) ( $session['meta'] ?? '' ), true );
		$session_meta = is_array( $session_meta ) ? $session_meta : array();

		$context = new ChatContext( $session_id, $text, get_locale() );
		$context->note( 'session_lang', (string) ( $session['lang'] ?? '' ) );
		$context->note( 'session_oos_count', (int) ( $session_meta['oos_count'] ?? 0 ) );

		// Short-term memory for AI tiers: the open conversation's last turns.
		$open_conversation = $this->log->open_id( $session_id );
		if ( $open_conversation > 0 ) {
			$context->note( 'history', $this->log->recent_turns( $open_conversation, 6 ) );
		}

		$emitter = null;
		if ( $this->wants_stream( $request ) && SseEmitter::can_stream() ) {
			$emitter = new SseEmitter();
			$emitter->begin();
			$emitter->event( 'status', array( 'state' => 'queued', 'ts' => 0 ) );

			$context->note(
				'event_sink',
				static function ( string $state, int $ts ) use ( $emitter ): void {
					$emitter->event( 'status', array( 'state' => $state, 'ts' => $ts ) );
				}
			);
			$context->note(
				'stream_sink',
				static function ( string $delta, string $kind = 'delta' ) use ( $emitter ): void {
					if ( 'reset' === $kind ) {
						$emitter->event( 'reset', array() );
					} elseif ( '' !== $delta ) {
						$emitter->event( 'delta', array( 't' => $delta ) );
					}
				}
			);
		}

		$context = $this->pipeline->run( $context );

		$payload = $this->finish_turn( $context, $session, $session_meta, $privacy, $ip_hash );

		if ( '' !== $transient_key ) {
			set_transient( $transient_key, $payload, self::IDEMPOTENCY_TTL );
		}

		if ( null !== $emitter ) {
			$emitter->event( 'message', $payload );
			$emitter->end();
			// The stream IS the response: end the request here so the REST
			// server does not append a JSON body. Shutdown hooks still run.
			exit;
		}

		$response = $this->respond( $payload );
		if ( ! empty( $payload['message']['meta']['ai_generated'] ) ) {
			// Machine-readable Art. 50 marking (mirrors meta.ai_generated).
			$response->header( 'X-AGYL-AI-Generated', '1' );
		}

		return $response;
	}

	/**
	 * Persist the exchange, update session/learning bookkeeping and build the
	 * response payload. Shared by both transports.
	 *
	 * @param ChatContext          $context      Finished pipeline context.
	 * @param array<string, mixed> $session      Session row.
	 * @param array<string, mixed> $session_meta Decoded session meta.
	 * @param array<string, mixed> $privacy      Privacy settings.
	 * @param string|null          $ip_hash      Hashed IP or null.
	 * @return array<string, mixed>
	 */
	private function finish_turn( ChatContext $context, array $session, array $session_meta, array $privacy, ?string $ip_hash ): array {
		$session_id = (int) $session['id'];

		// Identity (name/email/consent) comes from the gate consent stashed in
		// session meta; PII in message text is masked at write time when
		// pii_redaction is 'logs' or 'before_ai'.
		$conversation_lang = '' !== $context->visitor_lang ? $context->visitor_lang : $context->site_lang;
		$identity          = array(
			'visitor_name'  => (string) ( $session_meta['visitor_name'] ?? '' ),
			'visitor_email' => (string) ( $session_meta['visitor_email'] ?? '' ),
			'consent_id'    => $session_meta['consent_id'] ?? null,
		);
		$conversation_id   = $this->log->start_or_get( $session_id, $conversation_lang, 'none' === (string) $privacy['ip_mode'] ? null : $ip_hash, $identity );
		$assistant_id      = 0;
		$redaction         = (string) $privacy['pii_redaction'];

		$ai_generated = ! empty( $context->meta['ai_composed'] );
		$tier         = $ai_generated ? 'cloud' : 'classic';
		$provider     = (string) ( $context->meta['ai_provider'] ?? '' );
		if ( $ai_generated && '' !== $provider ) {
			$tier = in_array( $provider, array( 'openai', 'anthropic' ), true ) ? 'cloud' : 'local';
		}

		if ( $conversation_id > 0 ) {
			$this->log->log_message(
				$conversation_id,
				'user',
				Redactor::apply( $context->raw, $redaction, 'logs' ),
				array(),
				array(
					'tier'       => 'classic',
					'intent'     => $context->intent,
					'confidence' => $context->intent_confidence,
				)
			);

			$refused      = ChatContext::ROUTE_REFUSE === $context->route;
			$assistant_id = $this->log->log_message(
				$conversation_id,
				'assistant',
				Redactor::apply( ConversationLog::blocks_to_text( $context->blocks ), $redaction, 'logs' ),
				$context->blocks,
				array(
					'tier'           => $tier,
					// Stats count refusals by intent: a refused turn is logged as
					// out_of_scope regardless of what the classifier guessed.
					'intent'         => $refused ? 'out_of_scope' : $context->intent,
					'confidence'     => $context->intent_confidence,
					'latency_ms'     => (int) ( $context->meta['total_ms'] ?? 0 ),
					'answered'       => empty( $context->meta['fallback'] ),
					'kb_sources'     => array_values( array_unique( array_map( static fn ( array $chunk ): int => (int) $chunk['document_id'], $context->chunks ) ) ),
					'lang'           => $conversation_lang,
					// AI Act audit trail: model + prompt version per message.
					'model'          => $ai_generated ? (string) ( $context->meta['ai_model'] ?? '' ) : '',
					'prompt_version' => $ai_generated ? (string) ( $context->meta['prompt_version'] ?? '' ) : '',
					'tokens_in'      => $ai_generated ? (int) ( $context->meta['ai_tokens_in'] ?? 0 ) : null,
					'tokens_out'     => $ai_generated ? (int) ( $context->meta['ai_tokens_out'] ?? 0 ) : null,
					'cost_usd'       => $ai_generated ? (float) ( $context->meta['ai_cost_usd'] ?? 0 ) : null,
				)
			);
		}

		// Session bookkeeping: activity, sticky language, out-of-scope streak.
		$sticky = ( $context->lang_confidence >= self::LANG_CONFIDENCE && '' !== $context->visitor_lang ) ? $context->visitor_lang : null;
		$this->sessions->touch( $session_id, true, $sticky );

		if ( ChatContext::ROUTE_REFUSE === $context->route ) {
			$session_meta['oos_count'] = (int) ( $session_meta['oos_count'] ?? 0 ) + 1;
			$this->persist_session_meta( $session_id, $session_meta );
		}

		// Learning loop: honesty fallbacks AND relevance-based refusals
		// (nothing in the KB matched) feed the unanswered queue — those are
		// real knowledge gaps the copilot turns into KB suggestions.
		// Deny-list refusals (off-topic categories) are deliberate and skipped.
		$guard_reason = (string) ( $context->meta['guard'] ?? '' );
		$is_gap       = ! empty( $context->meta['fallback'] ) || in_array( $guard_reason, array( 'low_coverage', 'low_score' ), true );
		if ( $is_gap ) {
			$this->stats->record_unanswered( Redactor::apply( $context->raw, $redaction, 'logs' ), $conversation_lang, $context->intent );
		}

		return array(
			'message'         => array(
				'id'     => (string) $assistant_id,
				'role'   => 'assistant',
				'blocks' => $context->blocks,
				'meta'   => array(
					'events'       => $context->meta['events'] ?? array(),
					'ai_generated' => $ai_generated,
					'tier'         => $tier,
				),
			),
			'conversation_id' => $conversation_id,
		);
	}

	/**
	 * Whether this request should be answered as an SSE stream: the client
	 * asked for it (Accept header or ?stream=1) and the transport setting is
	 * not forced to buffered.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function wants_stream( WP_REST_Request $request ): bool {
		$perf = $this->settings->get( 'performance' );
		if ( 'buffered' === (string) ( $perf['transport'] ?? 'auto' ) ) {
			return false;
		}
		if ( '1' === (string) $request->get_param( 'stream' ) ) {
			return true;
		}
		$accept = strtolower( (string) $request->get_header( 'accept' ) );

		return str_contains( $accept, 'text/event-stream' );
	}

	/**
	 * POST /feedback — rate an assistant message (up/down + comment).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_feedback( WP_REST_Request $request ): WP_REST_Response {
		$session = $this->validate_session( $request );
		if ( null === $session ) {
			return $this->error( 'agyl_invalid_session', __( 'Your chat session is invalid or expired.', 'agentyllo' ), 401 );
		}

		$message_id = (int) $request['message_id'];
		$owner      = $this->log->conversation_for_message( $message_id );

		if ( null === $owner || $owner['session_id'] !== (int) $session['id'] ) {
			return $this->error( 'agyl_not_found', __( 'Message not found.', 'agentyllo' ), 404 );
		}

		$this->log->add_feedback(
			$owner['conversation_id'],
			$message_id,
			(string) $request['rating'],
			mb_substr( (string) $request['comment'], 0, 500 )
		);

		return $this->respond( array( 'ok' => true ) );
	}

	/**
	 * Enforce the three message rate limits from the performance settings.
	 * Returns the 429 response when throttled, null when clear.
	 *
	 * @param int $session_id Session id.
	 */
	private function check_message_limits( int $session_id ): ?WP_REST_Response {
		$perf    = $this->settings->get( 'performance' );
		$message = __( 'Message limit reached — please try again later.', 'agentyllo' );

		if ( ! $this->limiter->allow( RateLimiter::bucket_session_msg( $session_id ), (int) $perf['rate_limit_session_per_min'], MINUTE_IN_SECONDS ) ) {
			return $this->error( 'agyl_rate_limited', $message, 429, array( 'Retry-After' => '60' ) );
		}

		$ip = $this->remote_ip();
		if ( null === $ip ) {
			return null;
		}
		$ip_hash = SessionManager::hash_ip( $ip );

		if ( ! $this->limiter->allow( RateLimiter::bucket_ip( $ip_hash, 'msg_h' ), (int) $perf['rate_limit_ip_per_hour'], HOUR_IN_SECONDS ) ) {
			return $this->error( 'agyl_rate_limited', $message, 429, array( 'Retry-After' => '300' ) );
		}

		if ( ! $this->limiter->allow( RateLimiter::bucket_ip( $ip_hash, 'msg_d' ), (int) $perf['rate_limit_ip_per_day'], DAY_IN_SECONDS ) ) {
			return $this->error( 'agyl_rate_limited', $message, 429, array( 'Retry-After' => '3600' ) );
		}

		return null;
	}

	/**
	 * Resolve the session row from the X-Agyl-Session header.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function validate_session( WP_REST_Request $request ): ?array {
		$token = (string) $request->get_header( 'X-Agyl-Session' );
		if ( '' === $token ) {
			return null;
		}

		return $this->sessions->validate( $token );
	}

	/**
	 * Persist the session meta JSON (oos_count etc.).
	 *
	 * @param int   $session_id Session id.
	 * @param array $meta       Full meta array.
	 */
	private function persist_session_meta( int $session_id, array $meta ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agyl_sessions',
			array( 'meta' => (string) wp_json_encode( $meta ) ),
			array( 'id' => $session_id )
		);
	}

	/**
	 * The remote IP, or null when unavailable.
	 *
	 * Defaults to REMOTE_ADDR (spoof-proof). Sites behind a reverse proxy or
	 * CDN (Cloudflare, nginx) see the proxy's IP there, which would collapse
	 * every visitor into ONE rate-limit bucket and 429 the whole widget. When
	 * the owner opts in via the `trusted_proxy_header` performance setting
	 * (e.g. CF-Connecting-IP or X-Forwarded-For — set ONLY if a trusted proxy
	 * always overwrites it), the first valid IP from that header wins.
	 */
	private function remote_ip(): ?string {
		$header = trim( (string) $this->settings->value( 'performance', 'trusted_proxy_header' ) );
		if ( '' !== $header ) {
			$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$raw = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
				foreach ( explode( ',', $raw ) as $candidate ) {
					$candidate = trim( $candidate );
					if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
						return $candidate;
					}
				}
			}
		}

		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}

		$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );

		return '' === $ip ? null : $ip;
	}

	/**
	 * Error response in the {code, message, data:{status}} shape, never
	 * cached, with optional extra headers (Retry-After on 429s).
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @param array  $headers Extra headers.
	 */
	private function error( string $code, string $message, int $status, array $headers = array() ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		foreach ( $headers as $name => $value ) {
			$response->header( $name, (string) $value );
		}

		return $response;
	}
}
