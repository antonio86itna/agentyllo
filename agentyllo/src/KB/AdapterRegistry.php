<?php
/**
 * Source adapter registry.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB;

use Agentyllo\KB\Source\SourceAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every KB source adapter, exposes the set through the
 * `agyl_kb_source_adapters` filter (addons can plug in new universes), and
 * maps the Content Sources settings tab onto the enabled subtype list of
 * each source.
 *
 * Canonical toggle mapping:
 *  - 'post'     → per post type: posts_enabled / pages_enabled / cpt_{type}_enabled
 *  - 'menu'     → menus_enabled (single toggle for all menus)
 *  - 'site'     → site_identity_enabled
 *  - 'taxonomy' → taxonomies_enabled
 *  - 'product'  → woocommerce_enabled (subtype ''; wc_* masks select what is
 *                 extracted, not whether the source runs)
 *  - 'manual'   → no toggle, always on
 * (elementor_enabled toggles the Elementor decorator inside the post
 * adapter — it is not a separate source.)
 */
final class AdapterRegistry {

	/**
	 * Adapters as constructed (pre-filter, pre-availability).
	 *
	 * @var SourceAdapter[]
	 */
	private array $adapters;

	/**
	 * Resolved id-keyed available adapters (per-request cache).
	 *
	 * @var array<string, SourceAdapter>|null
	 */
	private ?array $resolved = null;

	/**
	 * Constructor.
	 *
	 * @param SourceAdapter[] $adapters Core adapters.
	 */
	public function __construct( array $adapters = array() ) {
		$this->adapters = array_values(
			array_filter( $adapters, static fn ( mixed $adapter ): bool => $adapter instanceof SourceAdapter )
		);
	}

	/**
	 * Available adapters keyed by id. Anything whose is_available() fails
	 * (e.g. the product adapter without WooCommerce) is dropped.
	 *
	 * @return array<string, SourceAdapter>
	 */
	public function all(): array {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		$map = array();
		foreach ( $this->adapters as $adapter ) {
			$map[ $adapter->id() ] = $adapter;
		}

		/**
		 * Filter the registered KB source adapters.
		 *
		 * @param array<string, SourceAdapter> $map Adapters keyed by id.
		 */
		$map = apply_filters( 'agyl_kb_source_adapters', $map );

		$this->resolved = array();
		foreach ( (array) $map as $adapter ) {
			if ( $adapter instanceof SourceAdapter && $adapter->is_available() ) {
				$this->resolved[ $adapter->id() ] = $adapter;
			}
		}

		return $this->resolved;
	}

	/**
	 * One adapter by id, or null when unknown or unavailable.
	 *
	 * @param string $id Adapter id.
	 */
	public function get( string $id ): ?SourceAdapter {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Enabled subtypes per source, derived from the effective 'sources' tab
	 * values via the canonical toggle mapping. An empty list means the source
	 * is fully disabled.
	 *
	 * @param array<string, mixed> $sources_settings Effective 'sources' tab values.
	 * @return array<string, string[]> Source id => enabled subtype list.
	 */
	public function enabled_subtypes( array $sources_settings ): array {
		$enabled = array();

		foreach ( $this->all() as $id => $adapter ) {
			$enabled[ $id ] = $this->source_subtypes( $id, $adapter, $sources_settings );
		}

		return $enabled;
	}

	/**
	 * Enabled subtype list for one source.
	 *
	 * @param string               $id       Adapter id.
	 * @param SourceAdapter        $adapter  Adapter.
	 * @param array<string, mixed> $settings Effective 'sources' tab values.
	 * @return string[]
	 */
	private function source_subtypes( string $id, SourceAdapter $adapter, array $settings ): array {
		switch ( $id ) {
			case 'post':
				$on = array();
				foreach ( array_keys( $adapter->subtypes() ) as $type ) {
					if ( ! empty( $settings[ self::post_setting_key( (string) $type ) ] ) ) {
						$on[] = (string) $type;
					}
				}
				return $on;

			case 'menu':
				return empty( $settings['menus_enabled'] ) ? array() : self::all_subtypes( $adapter );

			case 'site':
				return empty( $settings['site_identity_enabled'] ) ? array() : self::all_subtypes( $adapter );

			case 'taxonomy':
				return empty( $settings['taxonomies_enabled'] ) ? array() : self::all_subtypes( $adapter );

			case 'product':
				// wc_* field masks select which data the adapter extracts;
				// the single subtype '' carries the whole universe.
				return empty( $settings['woocommerce_enabled'] ) ? array() : array( '' );

			case 'manual':
				return array( '' ); // No toggle: always on.

			default:
				// Custom adapters gate themselves (their own settings or the
				// agyl_kb_source_adapters filter); treat them as fully enabled.
				return self::all_subtypes( $adapter );
		}
	}

	/**
	 * Settings key toggling one post-type subtype.
	 *
	 * @param string $post_type Post type name.
	 */
	public static function post_setting_key( string $post_type ): string {
		if ( 'post' === $post_type ) {
			return 'posts_enabled';
		}
		if ( 'page' === $post_type ) {
			return 'pages_enabled';
		}

		return 'cpt_' . $post_type . '_enabled';
	}

	/**
	 * All subtype keys of an adapter ('' when it declares none).
	 *
	 * @param SourceAdapter $adapter Adapter.
	 * @return string[]
	 */
	private static function all_subtypes( SourceAdapter $adapter ): array {
		$keys = array_map( 'strval', array_keys( $adapter->subtypes() ) );

		return $keys ? $keys : array( '' );
	}
}
