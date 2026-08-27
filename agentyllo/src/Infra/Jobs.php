<?php
/**
 * Recurring background jobs (Action Scheduler).
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Infra;

use Agentyllo\AI\Budget\Manager as BudgetManager;
use Agentyllo\AI\Budget\ResponseCache;
use Agentyllo\AI\Capability\Detector;
use Agentyllo\Agents\Contracts\Task;
use Agentyllo\Agents\Kernel\AsyncBus;
use Agentyllo\Agents\Kernel\Orchestrator;
use Agentyllo\Agents\Roster\JanitorAgent;
use Agentyllo\Agents\Roster\LearnerAgent;
use Agentyllo\Agents\Roster\LinkGrapherAgent;
use Agentyllo\Agents\Roster\ReconcilerAgent;
use Agentyllo\Agents\Roster\SentinelAgent;
use Agentyllo\Agents\Roster\SiteProfilerAgent;
use Agentyllo\Chat\Stages\ScopeGuardStage;
use Agentyllo\Compliance\Retention;
use Agentyllo\KB\Health;
use Agentyllo\Registry\RemoteSync;
use Agentyllo\Stats\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Registers job handlers and idempotently ensures the recurring schedule.
 * All Agentyllo actions run in Action Scheduler group `agentyllo`
 * (KB jobs use `agentyllo-kb`, local inference `agentyllo-ai`).
 *
 * Recurring hooks carry no args; each run mints a fresh Task so refs stay
 * unique per execution. Ad-hoc tasks ride the generic `agyl_task` hook.
 */
final class Jobs {

	public const GROUP    = 'agentyllo';
	public const GROUP_KB = 'agentyllo-kb';

	public const HOOK_CAPABILITIES_RESCAN = 'agyl_capabilities_rescan';
	public const HOOK_HEALTH_SWEEP        = 'agyl_health_sweep';
	public const HOOK_MAINTENANCE         = 'agyl_maintenance';
	public const HOOK_LEARN               = 'agyl_learn';
	public const HOOK_KB_RECONCILE_ALL    = 'agyl_kb_reconcile_all';
	public const HOOK_KB_LINK_CHECK       = 'agyl_kb_link_check';
	public const HOOK_KB_HEALTH           = 'agyl_kb_health_compute';
	public const HOOK_SITE_PROFILE        = 'agyl_site_profile';
	public const HOOK_RETENTION           = 'agyl_retention_daily';
	public const HOOK_STATS_ROLLUP        = 'agyl_stats_rollup';
	public const HOOK_REGISTRY_SYNC       = 'agyl_registry_sync';

	/**
	 * Resolver returning the 'models' settings array.
	 *
	 * @var callable
	 */
	private $models_resolver;

	/**
	 * Constructor.
	 *
	 * @param Detector      $detector        Capability detector.
	 * @param Orchestrator  $orchestrator    Task orchestrator.
	 * @param Health        $kb_health       KB health metrics service.
	 * @param Retention     $retention       Data retention service.
	 * @param Stats         $stats           Statistics service.
	 * @param RemoteSync    $registry_sync   Signed registry sync.
	 * @param ResponseCache $response_cache  AI response cache.
	 * @param BudgetManager $budget          Inference budget manager.
	 * @param callable      $models_resolver Returns the 'models' settings tab.
	 */
	public function __construct(
		private readonly Detector $detector,
		private readonly Orchestrator $orchestrator,
		private readonly Health $kb_health,
		private readonly Retention $retention,
		private readonly Stats $stats,
		private readonly RemoteSync $registry_sync,
		private readonly ResponseCache $response_cache,
		private readonly BudgetManager $budget,
		callable $models_resolver
	) {
		$this->models_resolver = $models_resolver;
	}

	/**
	 * Attach handlers and ensure recurring actions exist. Hooked on `init`.
	 */
	public function register(): void {
		add_action( self::HOOK_CAPABILITIES_RESCAN, array( $this, 'run_capabilities_rescan' ) );
		add_action( self::HOOK_HEALTH_SWEEP, array( $this, 'run_health_sweep' ) );
		add_action( self::HOOK_MAINTENANCE, array( $this, 'run_maintenance' ) );
		add_action( self::HOOK_LEARN, array( $this, 'run_learn' ) );
		add_action( self::HOOK_KB_RECONCILE_ALL, array( $this, 'run_kb_reconcile' ) );
		add_action( self::HOOK_KB_LINK_CHECK, array( $this, 'run_kb_link_check' ) );
		add_action( self::HOOK_KB_HEALTH, array( $this, 'run_kb_health' ) );
		add_action( self::HOOK_SITE_PROFILE, array( $this, 'run_site_profile' ) );
		add_action( self::HOOK_RETENTION, array( $this, 'run_retention' ) );
		add_action( self::HOOK_STATS_ROLLUP, array( $this, 'run_stats_rollup' ) );
		add_action( self::HOOK_REGISTRY_SYNC, array( $this, 'run_registry_sync' ) );
		add_action( AsyncBus::HOOK, array( $this, 'run_task' ), 10, 2 );

		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return; // Action Scheduler unavailable (should not happen; defensive per Woo guidance).
		}
		if ( ! did_action( 'action_scheduler_init' ) && ! doing_action( 'action_scheduler_init' ) ) {
			add_action( 'action_scheduler_init', array( $this, 'ensure_schedule' ) );
			return;
		}
		$this->ensure_schedule();
	}

	/**
	 * Idempotently schedule recurring jobs.
	 */
	public function ensure_schedule(): void {
		$recurring = array(
			self::HOOK_CAPABILITIES_RESCAN => array( DAY_IN_SECONDS, WEEK_IN_SECONDS, self::GROUP ),
			self::HOOK_HEALTH_SWEEP        => array( HOUR_IN_SECONDS, DAY_IN_SECONDS, self::GROUP ),
			self::HOOK_MAINTENANCE         => array( HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::GROUP ),
			self::HOOK_LEARN               => array( 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::GROUP ),
			self::HOOK_KB_RECONCILE_ALL    => array( DAY_IN_SECONDS, DAY_IN_SECONDS, self::GROUP_KB ),
			self::HOOK_KB_LINK_CHECK       => array( 2 * DAY_IN_SECONDS, WEEK_IN_SECONDS, self::GROUP_KB ),
			self::HOOK_KB_HEALTH           => array( 3 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::GROUP_KB ),
			self::HOOK_SITE_PROFILE        => array( 5 * MINUTE_IN_SECONDS, WEEK_IN_SECONDS, self::GROUP ),
			self::HOOK_RETENTION           => array( 4 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::GROUP ),
			self::HOOK_STATS_ROLLUP        => array( HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, self::GROUP ),
			self::HOOK_REGISTRY_SYNC       => array( 10 * MINUTE_IN_SECONDS, WEEK_IN_SECONDS, self::GROUP ),
		);

		foreach ( $recurring as $hook => [ $first_delay, $interval, $group ] ) {
			if ( ! as_has_scheduled_action( $hook, array(), $group ) ) {
				as_schedule_recurring_action( time() + $first_delay, $interval, $hook, array(), $group, true );
			}
		}
	}

	/**
	 * Weekly hosting re-scan handler.
	 */
	public function run_capabilities_rescan(): void {
		$this->detector->detect( true );
	}

	/**
	 * Daily agent health sweep.
	 */
	public function run_health_sweep(): void {
		$this->orchestrator->handle_async( SentinelAgent::ID, Task::create( 'sweep.health' )->to_array() );
	}

	/**
	 * Hourly maintenance (memory pruning, journal rotation, cache GC).
	 */
	public function run_maintenance(): void {
		$this->orchestrator->handle_async( JanitorAgent::ID, Task::create( 'clean.maintenance' )->to_array() );
		$this->response_cache->sweep();
		/**
		 * Fires during hourly maintenance so services can garbage-collect
		 * (manual-entry trash purge, expired tokens…).
		 */
		do_action( 'agyl_maintenance' );
	}

	/**
	 * Weekly signed registry sync (model ids, prices, prompt versions —
	 * data only, never code). Honours the registry_auto_sync setting.
	 */
	public function run_registry_sync(): void {
		$settings = ( $this->models_resolver )();
		if ( is_array( $settings ) && array_key_exists( 'registry_auto_sync', $settings ) && ! $settings['registry_auto_sync'] ) {
			return;
		}
		$this->registry_sync->sync();
	}

	/**
	 * Nightly journal mining into lessons.
	 */
	public function run_learn(): void {
		$this->orchestrator->handle_async( LearnerAgent::ID, Task::create( 'learn.mine_journal' )->to_array() );
	}

	/**
	 * Daily KB reconciliation sweep (catches missed hooks, imports, direct DB edits).
	 */
	public function run_kb_reconcile(): void {
		$this->orchestrator->handle_async( ReconcilerAgent::ID, Task::create( 'kb.reconcile' )->to_array() );
	}

	/**
	 * Weekly internal link resolution + HTTP freshness check.
	 */
	public function run_kb_link_check(): void {
		$this->orchestrator->handle_async( LinkGrapherAgent::ID, Task::create( 'links.resolve' )->to_array() );
		$this->orchestrator->handle_async( LinkGrapherAgent::ID, Task::create( 'links.check' )->to_array() );
	}

	/**
	 * Nightly KB health metrics (coverage, staleness, duplicates, dynamic
	 * stopwords) + scope-guard threshold recalibration on the fresh index.
	 */
	public function run_kb_health(): void {
		$this->kb_health->compute();
		ScopeGuardStage::calibrate();
	}

	/**
	 * Daily GDPR retention: expired conversations/consents, stale DSAR
	 * exports, monthly IP-salt rotation.
	 */
	public function run_retention(): void {
		$this->retention->run();
		$this->budget->purge_log( 90 );
	}

	/**
	 * Stats rollup every 6 hours (today + yesterday, idempotent upsert) —
	 * runs BEFORE retention can purge raw rows, so rollups never miss a day.
	 */
	public function run_stats_rollup(): void {
		$this->stats->rollup( 2 );
	}

	/**
	 * Weekly site profiling (type, stack, cadence) → conditions all agents.
	 */
	public function run_site_profile(): void {
		$this->orchestrator->handle_async( SiteProfilerAgent::ID, Task::create( 'profile.site' )->to_array() );
	}

	/**
	 * Generic async task delivery (Action Scheduler `agyl_task`).
	 *
	 * @param string $agent_id  Target agent id.
	 * @param array  $task_data Serialized task.
	 */
	public function run_task( $agent_id, $task_data ): void {
		if ( ! is_string( $agent_id ) || ! is_array( $task_data ) ) {
			return;
		}
		$this->orchestrator->handle_async( $agent_id, $task_data );
	}
}
