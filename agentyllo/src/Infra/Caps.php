<?php
/**
 * Capability mapping for Agentyllo admin surfaces.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra;

defined( 'ABSPATH' ) || exit;

/**
 * Agentyllo uses virtual capabilities (agyl_manage, agyl_manage_kb,
 * agyl_manage_settings, agyl_use_copilot, agyl_view_stats) that all map to
 * `manage_options` by default. Site owners can remap them via filter.
 */
final class Caps {

	/**
	 * Whether the current user holds an Agentyllo capability.
	 *
	 * @param string $cap Virtual capability id (e.g. 'agyl_manage').
	 */
	public static function can( string $cap ): bool {
		/**
		 * Filter the real WordPress capability an Agentyllo virtual
		 * capability maps to.
		 *
		 * @param string $mapped Real capability. Default 'manage_options'.
		 * @param string $cap    Agentyllo virtual capability.
		 */
		$mapped = (string) apply_filters( 'agyl_capability_map', 'manage_options', $cap );

		return current_user_can( $mapped );
	}
}
