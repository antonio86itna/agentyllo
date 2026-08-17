<?php
/**
 * Per-tab settings storage.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Admin\Settings;

use Agentyllo\Infra\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One option per tab (`agy_settings_{tab}`, JSON-shaped array). Reads merge
 * stored values over schema defaults; writes are schema-sanitized partial
 * merges. Only `general` autoloads (it is consulted on frontend requests).
 */
final class SettingsStore {

	private const AUTOLOADED_TABS = array( 'general' );

	/**
	 * Constructor.
	 *
	 * @param SettingsSchema $schema  Schema provider.
	 * @param Options        $options Options wrapper.
	 */
	public function __construct(
		private readonly SettingsSchema $schema,
		private readonly Options $options,
	) {
	}

	/**
	 * Known tab ids.
	 *
	 * @return string[]
	 */
	public function tabs(): array {
		return array_keys( $this->schema->tabs() );
	}

	/**
	 * Whether a tab exists.
	 *
	 * @param string $tab Tab id.
	 */
	public function has_tab( string $tab ): bool {
		return null !== $this->schema->tab( $tab );
	}

	/**
	 * Effective values for a tab (stored over defaults).
	 *
	 * @param string $tab Tab id.
	 * @return array<string, mixed>
	 */
	public function get( string $tab ): array {
		$stored = $this->options->get( 'settings_' . $tab, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array_merge( $this->schema->defaults( $tab ), $this->schema->sanitize( $tab, $stored ) );
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $tab Tab id.
	 * @param string $key Field key.
	 * @return mixed
	 */
	public function value( string $tab, string $key ): mixed {
		$values = $this->get( $tab );

		return $values[ $key ] ?? null;
	}

	/**
	 * Sanitize and persist a partial update. Returns the new effective values.
	 *
	 * @param string               $tab    Tab id.
	 * @param array<string, mixed> $values Raw partial values.
	 * @return array<string, mixed>
	 */
	public function update( string $tab, array $values ): array {
		$old = $this->get( $tab );

		// Secrets: an empty submission keeps the stored key (the UI never
		// echoes it back), the sentinel '__clear__' removes it, anything
		// else is sealed by the schema sanitizer.
		foreach ( $this->schema->secret_keys( $tab ) as $key ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$incoming = is_scalar( $values[ $key ] ) ? trim( (string) $values[ $key ] ) : '';
			if ( '' === $incoming || str_starts_with( $incoming, '••' ) || '!corrupt' === $incoming ) {
				unset( $values[ $key ] );
			} elseif ( '__clear__' === $incoming ) {
				$values[ $key ] = '';
			}
		}

		$clean = $this->schema->sanitize( $tab, $values );
		$new   = array_merge( $old, $clean );

		$this->options->set( 'settings_' . $tab, $new, in_array( $tab, self::AUTOLOADED_TABS, true ) );

		/**
		 * Fires after a settings tab is updated.
		 *
		 * @param string $tab Tab id.
		 * @param array  $new New effective values.
		 * @param array  $old Previous effective values.
		 */
		do_action( 'agy_settings_updated', $tab, $new, $old );

		return $new;
	}

	/**
	 * Ensure every tab option exists (used at activation).
	 */
	public function seed(): void {
		foreach ( $this->tabs() as $tab ) {
			if ( ! is_array( $this->options->get( 'settings_' . $tab, null ) ) ) {
				$this->options->set( 'settings_' . $tab, $this->schema->defaults( $tab ), in_array( $tab, self::AUTOLOADED_TABS, true ) );
			}
		}
	}
}
