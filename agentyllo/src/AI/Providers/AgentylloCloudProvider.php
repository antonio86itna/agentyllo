<?php
/**
 * Agentyllo Cloud provider — free, centralized AI (no key required).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Providers;

use Agentyllo\AI\Cloud\CloudClient;
use Agentyllo\AI\Contracts\ChatRequest;
use Agentyllo\AI\Contracts\ChatResult;
use Agentyllo\AI\Contracts\LLMProvider;

defined( 'ABSPATH' ) || exit;

/**
 * The zero-config free tier: routes chat through the first-party Agentyllo
 * Cloud service, which uses pooled OpenRouter free models behind the scenes.
 * The site owner enables it with one click — no API key, no cost — within a
 * generous monthly per-domain quota. Reported as tier 'cloud' but flagged
 * free, so the monthly-cost cap never applies. Falls back to classic when the
 * service is unreachable or the quota is spent.
 */
final class AgentylloCloudProvider implements LLMProvider {

	public const ID = 'agentyllo_cloud';

	/**
	 * Resolver returning the 'models' settings array.
	 *
	 * @var callable
	 */
	private $settings_resolver;

	/**
	 * Constructor.
	 *
	 * @param CloudClient $client            Cloud HTTP client.
	 * @param callable    $settings_resolver Returns the 'models' settings tab.
	 */
	public function __construct(
		private readonly CloudClient $client,
		callable $settings_resolver
	) {
		$this->settings_resolver = $settings_resolver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Enabled by the owner AND registered (has a site token).
	 */
	public function is_available(): bool {
		$models  = ( $this->settings_resolver )();
		$enabled = is_array( $models ) && (bool) ( $models['cloud_free_enabled'] ?? false );

		return $enabled && $this->client->registered();
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array(
			'streaming' => false,
			'json_mode' => true,
			'vision'    => false,
			'context'   => 32000,
			'tier'      => 'cloud',
			'free'      => true,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( ChatRequest $request ): ChatResult {
		$started = microtime( true );

		$body = $this->client->chat(
			array(
				'messages'    => $request->messages,
				'system'      => $request->system,
				'max_tokens'  => $request->max_tokens,
				'temperature' => $request->temperature,
				'json_schema' => $request->json_schema,
				'lang'        => $request->lang,
				'budget_s'    => $request->budget_s,
			)
		);

		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( empty( $body['ok'] ) || '' === (string) ( $body['text'] ?? '' ) ) {
			$error = (string) ( $body['error'] ?? 'error' );
			return ChatResult::failed( 'quota' === $error ? 'rate_limit' : $error, self::ID, (string) ( $body['model'] ?? '' ), $latency );
		}

		return new ChatResult(
			true,
			(string) $body['text'],
			self::ID,
			(string) ( $body['model'] ?? 'agentyllo-cloud' ),
			(int) ( $body['usage']['tokens_in'] ?? 0 ),
			(int) ( $body['usage']['tokens_out'] ?? 0 ),
			$latency,
			null,
			'stop'
		);
	}

	/**
	 * Buffered "stream": one emit. The service may add true SSE later.
	 *
	 * @param ChatRequest $request  Request.
	 * @param callable    $on_delta fn(string $delta): void.
	 */
	public function stream( ChatRequest $request, callable $on_delta ): ChatResult {
		$result = $this->complete( $request );
		if ( $result->ok && '' !== $result->text ) {
			$on_delta( $result->text );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): array {
		$usage = $this->client->usage( true );
		if ( null === $usage ) {
			return array( 'ok' => false, 'message' => __( 'Not connected to Agentyllo Cloud yet.', 'agentyllo' ), 'latency_ms' => 0 );
		}

		return array(
			'ok'         => true,
			'message'    => __( 'Agentyllo Cloud is connected.', 'agentyllo' ),
			'latency_ms' => 0,
		);
	}
}
