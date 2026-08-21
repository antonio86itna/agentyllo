<?php
/**
 * Dashboard aggregate REST endpoint.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Rest;

use Agentyllo\AI\Capability\Detector;
use Agentyllo\Admin\Settings\SettingsStore;
use Agentyllo\Agents\Kernel\MemoryStore;
use Agentyllo\Agents\Kernel\Registry;
use Agentyllo\Stats\Stats;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Repository for Agentyllo's own custom tables: core APIs cannot express these queries, table names are $wpdb->prefix plus literal constants, every value goes through $wpdb->prepare(), and dynamic IN() lists build a matching list of %s placeholders.

/**
 * GET agentyllo/v1/dashboard — one call for every dashboard card, cached
 * 60s in a transient (the page is opened often; the numbers move slowly).
 */
final class DashboardController extends Controller {

	private const CACHE_KEY = 'agy_dashboard_payload';
	private const CACHE_TTL = 60;

	/**
	 * Constructor.
	 *
	 * @param Detector      $detector Capability detector.
	 * @param SettingsStore $store    Settings store.
	 * @param Stats         $stats    Statistics service.
	 * @param Registry      $registry Agent registry.
	 * @param MemoryStore   $memory   Agent memory (sentinel sweep).
	 */
	public function __construct(
		private readonly Detector $detector,
		private readonly SettingsStore $store,
		private readonly Stats $stats,
		private readonly Registry $registry,
		private readonly MemoryStore $memory,
	) {
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard' ),
				'permission_callback' => $this->require_cap( 'agy_manage' ),
			)
		);
	}

	/**
	 * GET /dashboard.
	 */
	public function get_dashboard(): WP_REST_Response {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $this->respond( $cached );
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$capabilities = $this->detector->report();
		$privacy      = $this->store->get( 'privacy' );
		$general      = $this->store->get( 'general' );

		// KB freshness.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$kb = array(
			'documents'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_documents WHERE status = 'active'" ),
			'chunks'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_chunks" ),
			'purging'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_documents WHERE status = 'purging'" ),
			'errors'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}agy_kb_documents WHERE status = 'error'" ),
			'last_indexed' => $wpdb->get_var( "SELECT MAX(indexed_at) FROM {$p}agy_kb_documents" ),
			'last_crawl'  => (int) get_option( 'agy_kb_last_crawl', 0 ),
			'health'      => get_option( 'agy_kb_health', null ),
		);
		// phpcs:enable

		// Agent health from the sentinel's last sweep.
		$sweep   = $this->memory->recall( 'sentinel', 'last_sweep' ) ?? array();
		$summary = is_array( $sweep['summary'] ?? null ) ? $sweep['summary'] : array();
		$agents  = array(
			'total'       => count( $this->registry->all() ),
			'quarantined' => 0,
			'unhealthy'   => 0,
			'last_sweep'  => $sweep['at'] ?? null,
		);
		foreach ( $this->registry->all() as $id => $agent ) {
			$config = $this->registry->config( $id );
			if ( null !== $config['quarantine'] ) {
				++$agents['quarantined'];
			}
			if ( isset( $summary[ $id ] ) && empty( $summary[ $id ]['healthy'] ) ) {
				++$agents['unhealthy'];
			}
		}

		$transparency_id = (int) get_option( 'agy_transparency_page_id', 0 );

		$payload = array(
			'plugin'       => array(
				'version'    => AGY_VERSION,
				'db_version' => (int) get_option( 'agy_db_version', 0 ),
			),
			'settings'     => array(
				'operating_mode' => (string) $general['operating_mode'],
			),
			'capabilities' => array(
				'detected_at'    => $capabilities['detected_at'] ?? null,
				'best_free_tier' => $capabilities['tiers']['best_free_tier'] ?? 't1a',
				'tiers'          => $capabilities['tiers'] ?? array(),
			),
			'background'   => array(
				'scheduler' => function_exists( 'as_has_scheduled_action' ) ? 'action-scheduler' : 'unavailable',
				'pending'   => $this->pending_actions_count(),
			),
			'kb'           => $kb,
			'agents'       => $agents,
			'stats'        => array(
				'days'   => 7,
				'totals' => $this->stats->totals( 7 ),
				'daily'  => $this->stats->daily( 7 ),
			),
			'unanswered'   => $this->stats->unanswered( 5 ),
			'compliance'   => array(
				'gate'                 => (string) $privacy['registration_gate'],
				'retention_days'       => (int) $privacy['retention_days'],
				'ai_disclosure'        => (bool) $privacy['ai_disclosure'] || 'classic' !== (string) $general['operating_mode'],
				'transparency_page'    => $transparency_id > 0 ? (string) get_post_status( $transparency_id ) : 'none',
				'consents_logged'      => (bool) $privacy['consent_logging'],
			),
		);

		set_transient( self::CACHE_KEY, $payload, self::CACHE_TTL );

		return $this->respond( $payload );
	}

	/**
	 * Pending Action Scheduler actions in our groups (best effort).
	 */
	private function pending_actions_count(): ?int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		try {
			$total = 0;
			foreach ( array( 'agentyllo', 'agentyllo-kb', 'agentyllo-ai' ) as $group ) {
				$ids = as_get_scheduled_actions(
					array(
						'group'    => $group,
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => 100,
					),
					'ids'
				);
				$total += is_array( $ids ) ? count( $ids ) : 0;
			}

			return $total;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
