<?php
/**
 * Dev-only mu-plugin: a scripted LLM provider to exercise the M7 pipeline
 * (streaming, FactGuard, cache, budget) inside wp-env without real API keys.
 * Copy to wp-content/mu-plugins/ in the test container. NOT shipped.
 *
 * Control via options:
 *   agy_mock_enabled  (bool)   force the provider chain to [mock]
 *   agy_mock_reply    (string) canned reply text ('' = grounded default)
 *   agy_mock_delay_ms (int)    per-word delay to make streaming visible
 *   agy_mock_fail     (string) '' | 'timeout' | 'auth' | 'refusal'
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

add_action(
	'agy_init',
	static function (): void {
		if ( ! interface_exists( \Agentyllo\AI\Contracts\LLMProvider::class ) ) {
			return;
		}

		$mock = new class() implements \Agentyllo\AI\Contracts\LLMProvider {
			public function id(): string {
				return 'mock';
			}

			public function is_available(): bool {
				return (bool) get_option( 'agy_mock_enabled', false );
			}

			public function capabilities(): array {
				return array(
					'streaming' => true,
					'json_mode' => false,
					'vision'    => false,
					'context'   => 8000,
					'tier'      => 'cloud',
				);
			}

			public function model(): ?array {
				return array(
					'id'        => 'mock-1',
					'price_in'  => 1.0,
					'price_out' => 5.0,
				);
			}

			public function complete( \Agentyllo\AI\Contracts\ChatRequest $request ): \Agentyllo\AI\Contracts\ChatResult {
				return $this->stream( $request, static function (): bool { return true; } );
			}

			public function stream( \Agentyllo\AI\Contracts\ChatRequest $request, callable $on_delta ): \Agentyllo\AI\Contracts\ChatResult {
				$fail = (string) get_option( 'agy_mock_fail', '' );
				if ( 'timeout' === $fail ) {
					return \Agentyllo\AI\Contracts\ChatResult::failed( 'timeout', 'mock', 'mock-1', 5000 );
				}
				if ( 'auth' === $fail ) {
					return \Agentyllo\AI\Contracts\ChatResult::failed( 'auth: invalid key', 'mock', 'mock-1', 120 );
				}
				if ( 'refusal' === $fail ) {
					return new \Agentyllo\AI\Contracts\ChatResult( false, '', 'mock', 'mock-1', 50, 0, 80, 'refusal', 'refusal' );
				}

				$reply = (string) get_option( 'agy_mock_reply', '' );
				if ( '' === $reply ) {
					// Default: echo the first source sentence, grounded by construction.
					$turns = $request->messages;
					$last  = $turns ? $turns[ count( $turns ) - 1 ] : null;
					$user  = is_array( $last ) ? (string) $last['content'] : '';
					if ( preg_match( '/\[#1\][^\n]*\n([^\n]+)/', $user, $m ) ) {
						$reply = 'According to the site: ' . mb_substr( trim( $m[1] ), 0, 220 ) . ' [#1]';
					} else {
						$reply = 'I could not find that in the site content. Ask me about this site!';
					}
				}

				$delay = (int) get_option( 'agy_mock_delay_ms', 0 );
				$words = preg_split( '/(?<=\s)/u', $reply ) ?: array( $reply );
				$sent  = '';
				$start = microtime( true );
				foreach ( $words as $word ) {
					if ( false === $on_delta( $word ) ) {
						return new \Agentyllo\AI\Contracts\ChatResult( true, $sent, 'mock', 'mock-1', 120, (int) ( strlen( $sent ) / 4 ), (int) ( ( microtime( true ) - $start ) * 1000 ), null, 'aborted' );
					}
					$sent .= $word;
					if ( $delay > 0 ) {
						usleep( $delay * 1000 );
					}
				}

				return new \Agentyllo\AI\Contracts\ChatResult( true, $sent, 'mock', 'mock-1', 120, (int) ( strlen( $sent ) / 4 ), (int) ( ( microtime( true ) - $start ) * 1000 ), null, 'stop' );
			}

			public function test_connection(): array {
				return array(
					'ok'         => true,
					'message'    => 'mock',
					'latency_ms' => 1,
				);
			}
		};

		add_filter(
			'agy_llm_providers',
			static function ( array $providers ) use ( $mock ): array {
				$providers['mock'] = $mock;

				return $providers;
			}
		);
		add_filter(
			'agy_provider_chain',
			static function ( array $chain ) use ( $mock ): array {
				return get_option( 'agy_mock_enabled', false ) ? array( $mock ) : $chain;
			}
		);
	}
);
