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
 * Agentyllo uses virtual capabilities (agy_manage, agy_manage_kb,
 * agy_manage_settings, agy_use_copilot, agy_view_stats) that all map to
 * `manage_options` by default. Site owners can remap them via filter.
 */
final class Caps {

	/**
	 * Whether the current user holds an Agentyllo capability.
	 *
	 * @param string $cap Virtual capability id (e.g. 'agy_manage').
	 */
	public static function can( string $cap ): bool {
		/**
		 * Filter the real WordPress capability an Agentyllo virtual
		 * capability maps to.
		 *
		 * @param string $mapped Real capability. Default 'manage_options'.
		 * @param string $cap    Agentyllo virtual capability.
		 */
		$mapped = (string) apply_filters( 'agy_capability_map', 'manage_options', $cap );

		return current_user_can( $mapped );
	}
}
