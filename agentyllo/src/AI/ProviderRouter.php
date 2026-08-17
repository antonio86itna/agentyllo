<?php
/**
 * Operating-mode aware provider selection.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI;

use Agentyllo\AI\Budget\Manager;
use Agentyllo\AI\Contracts\LLMProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the operating mode + settings + live health into an ordered list of
 * usable providers. Cloud modes use the ONE provider the owner configured
 * (their key, their bill); free modes use local/browser providers (M8 and
 * the Local AI companion register them through `agy_llm_providers`). A
 * provider is skipped when unavailable (no key/model), when its circuit is
 * open, or — cloud only — when the monthly cost cap is reached. An empty
 * chain means "classic composer answers", which is always allowed.
 */
final class ProviderRouter {

	public const MODES_PAID = array( 'paid_ai', 'classic_paid_ai' );
	public const MODES_FREE = array( 'free_ai', 'classic_free_ai' );
	public const MODES_HYBRID = array( 'classic_paid_ai', 'classic_free_ai' );

	/**
	 * Registered providers keyed by id.
	 *
	 * @var array<string, LLMProvider>
	 */
	private array $providers = array();

	/**
	 * Whether the `agy_llm_providers` filter has run.
	 */
	private bool $filtered = false;

	/**
	 * Resolver returning the 'general' settings array.
	 *
	 * @var callable
	 */
	private $general_resolver;

	/**
	 * Resolver returning the 'models' settings array.
	 *
	 * @var callable
	 */
	private $models_resolver;

	/**
	 * Constructor.
	 *
	 * @param Manager       $budget           Budget manager.
	 * @param LLMProvider[] $core             Core providers (cloud).
	 * @param callable      $general_resolver Returns 'general' settings.
	 * @param callable      $models_resolver  Returns 'models' settings.
	 */
	public function __construct(
		private readonly Manager $budget,
		array $core,
		callable $general_resolver,
		callable $models_resolver
	) {
		foreach ( $core as $provider ) {
			if ( $provider instanceof LLMProvider ) {
				$this->providers[ $provider->id() ] = $provider;
			}
		}
		$this->general_resolver = $general_resolver;
		$this->models_resolver  = $models_resolver;
	}

	/**
	 * Configured operating mode.
	 */
	public function mode(): string {
		$general = ( $this->general_resolver )();
		$mode    = is_array( $general ) ? (string) ( $general['operating_mode'] ?? 'classic' ) : 'classic';

		return '' === $mode ? 'classic' : $mode;
	}

	/**
	 * Whether any AI tier may compose (mode ≠ classic).
	 */
	public function ai_enabled(): bool {
		return 'classic' !== $this->mode();
	}

	/**
	 * Whether classic composition keeps the deterministic intents (hybrid
	 * modes) or the AI composes everything answerable (AI-only modes).
	 */
	public function is_hybrid(): bool {
		return in_array( $this->mode(), self::MODES_HYBRID, true );
	}

	/**
	 * All registered providers (after the filter).
	 *
	 * @return array<string, LLMProvider>
	 */
	public function providers(): array {
		if ( ! $this->filtered ) {
			$this->filtered = true;
			/**
			 * Filter the registered LLM providers. Local engines (M8) and the
			 * Agentyllo Local AI companion add theirs here.
			 *
			 * @param LLMProvider[] $providers Providers keyed by id.
			 */
			$extra = (array) apply_filters( 'agy_llm_providers', $this->providers );
			foreach ( $extra as $provider ) {
				if ( $provider instanceof LLMProvider ) {
					$this->providers[ $provider->id() ] = $provider;
				}
			}
		}

		return $this->providers;
	}

	/**
	 * A provider by id.
	 *
	 * @param string $id Provider id.
	 */
	public function provider( string $id ): ?LLMProvider {
		return $this->providers()[ $id ] ?? null;
	}

	/**
	 * Ordered usable providers for the current mode (empty = classic only).
	 *
	 * @return LLMProvider[]
	 */
	public function chain(): array {
		$mode = $this->mode();
		if ( 'classic' === $mode ) {
			return array();
		}

		$candidates = array();
		if ( in_array( $mode, self::MODES_PAID, true ) ) {
			$configured = $this->configured_cloud_provider();
			if ( '' !== $configured && isset( $this->providers()[ $configured ] ) ) {
				$candidates[] = $this->providers()[ $configured ];
			}
		} else {
			foreach ( $this->providers() as $provider ) {
				if ( 'cloud' !== (string) ( $provider->capabilities()['tier'] ?? 'cloud' ) ) {
					$candidates[] = $provider;
				}
			}
		}

		$cap_reached = null;
		$chain       = array();
		foreach ( $candidates as $provider ) {
			if ( ! $provider->is_available() || $this->budget->circuit_open( $provider->id() ) ) {
				continue;
			}
			if ( 'cloud' === (string) ( $provider->capabilities()['tier'] ?? 'cloud' ) ) {
				$cap_reached ??= $this->budget->cap_reached();
				if ( $cap_reached ) {
					continue;
				}
			}
			$chain[] = $provider;
		}

		/**
		 * Filter the ordered provider chain for the current request.
		 *
		 * @param LLMProvider[] $chain Usable providers in order.
		 * @param string        $mode  Operating mode.
		 */
		return (array) apply_filters( 'agy_provider_chain', $chain, $mode );
	}

	/**
	 * First usable provider, or null (classic answers).
	 */
	public function pick(): ?LLMProvider {
		$chain = $this->chain();

		return $chain ? $chain[0] : null;
	}

	/**
	 * Diagnostic status for the AI Models page and dashboard.
	 *
	 * @return array{mode: string, ai_enabled: bool, active: ?string, reason: string, cap_reached: bool}
	 */
	public function status(): array {
		$mode   = $this->mode();
		$active = $this->pick();
		$reason = '';

		if ( 'classic' === $mode ) {
			$reason = 'classic_mode';
		} elseif ( null === $active ) {
			if ( in_array( $mode, self::MODES_PAID, true ) ) {
				$configured = $this->configured_cloud_provider();
				$provider   = '' === $configured ? null : $this->provider( $configured );
				if ( null === $provider ) {
					$reason = 'no_provider';
				} elseif ( ! $provider->is_available() ) {
					$reason = 'no_key';
				} elseif ( $this->budget->circuit_open( $configured ) ) {
					$reason = 'circuit_open';
				} elseif ( $this->budget->cap_reached() ) {
					$reason = 'cap_reached';
				} else {
					$reason = 'unavailable';
				}
			} else {
				$reason = 'no_local_engine';
			}
		}

		return array(
			'mode'        => $mode,
			'ai_enabled'  => 'classic' !== $mode,
			'active'      => $active ? $active->id() : null,
			'reason'      => $reason,
			'cap_reached' => 'classic' !== $mode && $this->budget->cap_reached(),
		);
	}

	/**
	 * Cloud provider id chosen in the models tab ('' when none).
	 */
	private function configured_cloud_provider(): string {
		$models = ( $this->models_resolver )();
		$id     = is_array( $models ) ? (string) ( $models['chat_provider'] ?? 'none' ) : 'none';

		return 'none' === $id ? '' : $id;
	}
}
