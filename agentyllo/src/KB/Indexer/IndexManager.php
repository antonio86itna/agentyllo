<?php
/**
 * KB indexing pipeline orchestrator.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Indexer;

use Agentyllo\AI\Capability\Detector;
use Agentyllo\Agents\Kernel\Journal;
use Agentyllo\KB\AdapterRegistry;
use Agentyllo\KB\Store;
use Closure;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every KB background job (Action Scheduler group `agentyllo-kb`):
 *
 *  - agyl_kb_full_crawl (source, subtype, offset): budget-aware inline batch
 *    indexing with an adaptive batch size; self-reschedules until the cursor
 *    is exhausted, then chains to the next enabled (source, subtype) in
 *    priority order: site, menu, post:page, post:post, post:{cpt…}, product,
 *    taxonomy.
 *  - agyl_kb_index_item (source, external_id): extract → chunk → upsert one
 *    item (null extraction deletes the document).
 *  - agyl_kb_purge (round): drains 'purging' rows in batches, rescheduling
 *    itself with an incremented round so the re-enqueue args always differ
 *    from the running action's.
 *  - agyl_kb_reconcile (source, offset, run_id, subtype): pages through the
 *    adapter cursor per enabled subtype, re-enqueues changed/doubtful items
 *    (upsert is hash-idempotent, so false positives only touch indexed_at)
 *    and, on the final page of the same run_id, removes docs the source no
 *    longer enumerates. The seen-set lives in a per-run option; cleanup only
 *    deletes rows indexed before the run started, so an item indexed while
 *    OFFSET pages shift can survive one extra cycle (residual one-cycle
 *    staleness by design) rather than being wrongly deleted.
 *
 * It also registers every adapter's delta_hooks() (upserts debounced 30s via
 * a unique scheduled action, deletes applied immediately) and reacts to
 * Content Sources settings changes: toggled OFF → mark_purging + purge job,
 * toggled ON → full crawl, wc_* mask change → product crawl,
 * elementor_enabled change → post crawl.
 */
final class IndexManager {

	public const GROUP = 'agentyllo-kb';

	public const HOOK_FULL_CRAWL = 'agyl_kb_full_crawl';
	public const HOOK_INDEX_ITEM = 'agyl_kb_index_item';
	public const HOOK_PURGE      = 'agyl_kb_purge';
	public const HOOK_RECONCILE  = 'agyl_kb_reconcile';

	private const BATCH_MIN     = 5;
	private const BATCH_MAX     = 25;
	private const BATCH_DEFAULT = 10;

	private const RECONCILE_PAGE = 100;
	private const PURGE_BATCH    = 200;
	private const DELTA_DEBOUNCE = 30;

	private const TIME_FRACTION   = 0.7;
	private const MEMORY_FRACTION = 0.6;

	/**
	 * Journal scope for pipeline failures (the kb_curator agent owns indexing).
	 */
	private const AGENT_ID = 'kb_curator';

	/**
	 * Whether register() has run (content_watcher's critical self-check).
	 */
	private static bool $registered = false;

	/**
	 * Resolver returning the effective 'sources' settings tab values.
	 */
	private readonly Closure $settings_resolver;

	/**
	 * Wall-clock deadline for the current job run (lazy, per request).
	 */
	private ?float $deadline = null;

	/**
	 * Constructor.
	 *
	 * @param AdapterRegistry $adapters          Source adapters.
	 * @param Store           $store             KB repository.
	 * @param Chunker         $chunker           Chunker.
	 * @param Detector        $detector          Capability detector (budget source).
	 * @param Journal         $journal           Agent journal.
	 * @param callable        $settings_resolver Returns the 'sources' tab array.
	 */
	public function __construct(
		private readonly AdapterRegistry $adapters,
		private readonly Store $store,
		private readonly Chunker $chunker,
		private readonly Detector $detector,
		private readonly Journal $journal,
		callable $settings_resolver,
	) {
		$this->settings_resolver = $settings_resolver instanceof Closure
			? $settings_resolver
			: Closure::fromCallable( $settings_resolver );
	}

	/**
	 * Whether the pipeline hooks are attached in this request.
	 */
	public static function did_register(): bool {
		return self::$registered;
	}

	/**
	 * Attach job handlers, delta hooks, and the settings listener.
	 * Hooked on `init` by the plugin bootstrap.
	 */
	public function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( self::HOOK_FULL_CRAWL, array( $this, 'run_full_crawl' ), 10, 3 );
		add_action( self::HOOK_INDEX_ITEM, array( $this, 'run_index_item' ), 10, 2 );
		add_action( self::HOOK_PURGE, array( $this, 'run_purge' ), 10, 1 );
		add_action( self::HOOK_RECONCILE, array( $this, 'run_reconcile' ), 10, 4 );
		add_action( 'agyl_settings_updated', array( $this, 'on_settings_updated' ), 10, 3 );

		$this->register_delta_hooks();
	}

	/* ---------------------------------------------------------------------
	 * Public entry points
	 * ------------------------------------------------------------------- */

	/**
	 * Kick off a full crawl. Only the first (source, subtype) target is
	 * enqueued — the crawl handler chains through the rest of the priority
	 * order once each cursor is exhausted.
	 *
	 * @param string|null $only_source Start at this source's first enabled
	 *                                 subtype; null starts at the top of the
	 *                                 priority order.
	 * @return bool Whether a crawl target was actually enqueued.
	 */
	public function start_full_crawl( ?string $only_source = null ): bool {
		foreach ( $this->enabled_targets() as [ $source, $subtype ] ) {
			if ( null !== $only_source && $source !== $only_source ) {
				continue;
			}
			$this->enqueue_crawl( $source, $subtype, 0 );
			update_option( 'agyl_kb_last_crawl', time(), false );
			return true;
		}

		return false;
	}

	/**
	 * Enqueue agyl_kb_reconcile(source, 0, run_id, first-subtype) for every
	 * enabled source. Each run gets a unique run_id: the per-run seen-set
	 * option (which also records the run start time for the cleanup guard)
	 * cannot be clobbered by an overlapping run. Sources that already have a
	 * pending reconcile action are skipped.
	 *
	 * @return string[] Source ids enqueued.
	 */
	public function start_reconcile(): array {
		$sources = array();

		foreach ( $this->enabled_subtypes() as $source => $subtypes ) {
			if ( ! $subtypes ) {
				continue;
			}
			$source = (string) $source;
			if ( $this->has_pending_reconcile( $source ) ) {
				continue; // A reconcile for this source is already queued.
			}

			$run_id = uniqid();
			update_option(
				'agyl_kb_reconcile_seen_' . $source . '_' . $run_id,
				array(
					'started' => time(),
					'ids'     => array(),
				),
				false
			);
			$this->enqueue( self::HOOK_RECONCILE, array(
				'source'  => $source,
				'offset'  => 0,
				'run_id'  => $run_id,
				'subtype' => $this->reconcile_subtypes( $source, $subtypes )[0] ?? '',
			) );
			$sources[] = $source;
		}

		return $sources;
	}

	/**
	 * Extract → chunk → upsert one item. Null extraction deletes the document.
	 * Never throws: failures are journaled under kb_curator and the document
	 * row (when one exists) is flipped to status 'error'.
	 *
	 * @param string $source      Adapter id.
	 * @param string $external_id External id.
	 * @return array{ok: bool, action: string, doc_id?: int, chunks?: int, reason?: string} AgentResult-friendly outcome.
	 */
	public function index_item( string $source, string $external_id ): array {
		$adapter = $this->adapters->get( $source );
		if ( ! $adapter ) {
			return array(
				'ok'     => false,
				'action' => 'skipped',
				'reason' => 'unknown_source',
			);
		}

		try {
			$draft = $adapter->extract( $external_id );

			if ( null === $draft ) {
				$this->store->delete( $source, $external_id );

				return array(
					'ok'     => true,
					'action' => 'deleted',
				);
			}

			$enabled = $this->enabled_subtypes()[ $source ] ?? array();
			if ( ! $enabled || ( $this->is_toggleable_subtype( $source, $draft->subtype ) && ! in_array( $draft->subtype, $enabled, true ) ) ) {
				return array(
					'ok'     => true,
					'action' => 'skipped',
					'reason' => 'subtype_disabled',
				);
			}

			$chunks = $this->chunker->chunk( $draft );
			$doc_id = $this->store->upsert( $draft, $chunks );

			if ( $doc_id <= 0 ) {
				return array(
					'ok'     => false,
					'action' => 'skipped',
					'reason' => 'tombstoned_or_write_failed',
				);
			}

			return array(
				'ok'     => true,
				'action' => 'upserted',
				'doc_id' => $doc_id,
				'chunks' => count( $chunks ),
			);
		} catch ( Throwable $e ) {
			$this->journal->error(
				self::AGENT_ID,
				$e,
				null,
				array(
					'source'      => $source,
					'external_id' => $external_id,
				)
			);
			$this->mark_error_row( $source, $external_id );

			return array(
				'ok'     => false,
				'action' => 'error',
				'reason' => $e->getMessage(),
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Action Scheduler handlers
	 * ------------------------------------------------------------------- */

	/**
	 * agyl_kb_full_crawl handler.
	 *
	 * @param mixed $source  Adapter id.
	 * @param mixed $subtype Subtype ('' = whole source).
	 * @param mixed $offset  Cursor offset.
	 */
	public function run_full_crawl( $source = '', $subtype = '', $offset = 0 ): void {
		$this->reset_deadline();

		$source  = (string) $source;
		$subtype = (string) $subtype;
		$offset  = max( 0, (int) $offset );

		$adapter = $this->adapters->get( $source );
		$enabled = $this->enabled_subtypes()[ $source ] ?? array();
		$active  = $adapter && $enabled && ( '' === $subtype || in_array( $subtype, $enabled, true ) );

		if ( ! $active ) {
			// Toggled off (or gone) mid-chain: keep walking the priority order.
			$this->chain_next( $source, $subtype );
			return;
		}

		$batch = $this->batch_size();
		$ids   = array_map( 'strval', $adapter->enumerate_ids( $subtype, $offset, $batch ) );

		$processed = 0;
		$stopped   = false;
		foreach ( $ids as $external_id ) {
			// Always process at least one item so a re-enqueue never repeats
			// the same offset with zero progress (which AS 3.x dedupe would
			// silently drop as a duplicate of the running action).
			if ( $processed > 0 && ! $this->under_budget() ) {
				$stopped = true;
				break;
			}
			$this->index_item( $source, $external_id );
			++$processed;
		}

		$this->adapt_batch_size( $batch, $stopped );

		if ( $stopped ) {
			$this->enqueue_crawl( $source, $subtype, $offset + $processed );
			return;
		}

		if ( count( $ids ) < $batch ) {
			$this->chain_next( $source, $subtype ); // Cursor exhausted.
			return;
		}

		$this->enqueue_crawl( $source, $subtype, $offset + count( $ids ) );
	}

	/**
	 * agyl_kb_index_item handler.
	 *
	 * @param mixed $source      Adapter id.
	 * @param mixed $external_id External id.
	 */
	public function run_index_item( $source = '', $external_id = '' ): void {
		if ( '' === (string) $source || '' === (string) $external_id ) {
			return;
		}
		$this->index_item( (string) $source, (string) $external_id );
	}

	/**
	 * agyl_kb_purge handler: one batch, reschedule while rows remain. The
	 * incrementing round makes each self-re-enqueue's args differ from the
	 * currently-running action's, so AS 3.x dedupe cannot drop it.
	 *
	 * @param mixed $round Re-enqueue counter (0 on the first run).
	 */
	public function run_purge( $round = 0 ): void {
		$this->reset_deadline();

		$remaining = $this->store->purge_batch( self::PURGE_BATCH );
		if ( $remaining > 0 ) {
			$this->enqueue( self::HOOK_PURGE, array( 'round' => max( 0, (int) $round ) + 1 ) );
		}
	}

	/**
	 * agyl_kb_reconcile handler: one page of adapter ids compared against the
	 * store. There is no stored fingerprint column, so the comparison is a
	 * heuristic — the fingerprint's modified-time prefix (or embedded content
	 * hash) against the stored row. When in doubt the item is re-enqueued:
	 * upsert is hash-idempotent, unchanged content only touches indexed_at,
	 * so false positives are cheap by design.
	 *
	 * The seen-set option is keyed by run_id so overlapping runs cannot
	 * clobber each other, and cleanup only fires on the final page of the
	 * same run_id. OFFSET paging can still shift under concurrent inserts or
	 * deletes; cleanup_missing()'s indexed_at guard turns that race into at
	 * most one cycle of residual staleness instead of a wrongful deletion.
	 *
	 * @param mixed $source  Adapter id.
	 * @param mixed $offset  Cursor offset.
	 * @param mixed $run_id  Run id minted by start_reconcile().
	 * @param mixed $subtype Subtype cursor ('' = whole source).
	 */
	public function run_reconcile( $source = '', $offset = 0, $run_id = '', $subtype = '' ): void {
		$this->reset_deadline();

		$source  = (string) $source;
		$offset  = max( 0, (int) $offset );
		$run_id  = (string) $run_id;
		$subtype = (string) $subtype;

		if ( '' === $source || '' === $run_id ) {
			return; // Reconciles are only started via start_reconcile().
		}

		$option = 'agyl_kb_reconcile_seen_' . $source . '_' . $run_id;

		$adapter = $this->adapters->get( $source );
		$enabled = $this->enabled_subtypes()[ $source ] ?? array();
		if ( ! $adapter || ! $enabled ) {
			delete_option( $option );
			return;
		}

		$run = get_option( $option, false );
		if ( ! is_array( $run ) || ! isset( $run['started'], $run['ids'] ) || ! is_array( $run['ids'] ) ) {
			return; // Run option gone (GC'd or never minted): abort without cleanup.
		}

		$subtypes = $this->reconcile_subtypes( $source, $enabled );
		if ( ! in_array( $subtype, $subtypes, true ) ) {
			// Subtype toggled off mid-run: the seen-set is incomplete, so end
			// the run without the destructive cleanup step.
			delete_option( $option );
			return;
		}

		$ids = array_map( 'strval', $adapter->enumerate_ids( $subtype, $offset, self::RECONCILE_PAGE ) );

		$seen = array_map( 'strval', $run['ids'] );

		foreach ( $ids as $external_id ) {
			try {
				$fingerprint = $adapter->fingerprint( $external_id );
			} catch ( Throwable $e ) {
				$this->journal->error(
					self::AGENT_ID,
					$e,
					null,
					array(
						'source'      => $source,
						'external_id' => $external_id,
					)
				);
				continue;
			}

			if ( null === $fingerprint ) {
				continue; // Gone/not indexable: the final-page cleanup removes it.
			}

			$seen[] = $external_id;

			$row = $this->store->document_row( $source, $external_id );
			if ( $row && Store::STATUS_EXCLUDED === $row['status'] ) {
				continue; // Owner tombstone: never re-add.
			}

			if ( $row && Store::STATUS_ACTIVE === $row['status'] && $this->fingerprint_current( $fingerprint, $row ) ) {
				continue; // Unchanged by the cheap heuristic.
			}

			$this->enqueue( self::HOOK_INDEX_ITEM, array(
				'source'      => $source,
				'external_id' => $external_id,
			) );
		}

		$run['ids'] = array_values( array_unique( $seen ) );

		if ( count( $ids ) === self::RECONCILE_PAGE ) {
			update_option( $option, $run, false );
			$this->enqueue( self::HOOK_RECONCILE, array(
				'source'  => $source,
				'offset'  => $offset + self::RECONCILE_PAGE,
				'run_id'  => $run_id,
				'subtype' => $subtype,
			) );
			return;
		}

		// This subtype cursor is exhausted: chain to the next enabled one.
		$position = array_search( $subtype, $subtypes, true );
		$next     = false !== $position ? ( $subtypes[ (int) $position + 1 ] ?? null ) : null;
		if ( null !== $next ) {
			update_option( $option, $run, false );
			$this->enqueue( self::HOOK_RECONCILE, array(
				'source'  => $source,
				'offset'  => 0,
				'run_id'  => $run_id,
				'subtype' => $next,
			) );
			return;
		}

		$this->cleanup_missing( $source, $run['ids'], (int) $run['started'] );
		delete_option( $option );
		$this->gc_reconcile_options();
	}

	/* ---------------------------------------------------------------------
	 * Settings listener
	 * ------------------------------------------------------------------- */

	/**
	 * agyl_settings_updated listener ('sources' tab only): purge on disable,
	 * crawl on enable, product re-crawl on wc_* mask change, post re-crawl on
	 * elementor_enabled change.
	 *
	 * @param mixed $tab        Tab id.
	 * @param mixed $new_values New effective values.
	 * @param mixed $old_values Previous effective values.
	 */
	public function on_settings_updated( $tab, $new_values, $old_values ): void {
		if ( 'sources' !== $tab || ! is_array( $new_values ) || ! is_array( $old_values ) ) {
			return;
		}

		$crawls       = array();
		$purge_needed = false;

		$keys = array_unique( array_merge( array_keys( $new_values ), array_keys( $old_values ) ) );
		foreach ( $keys as $key ) {
			$key    = (string) $key;
			$new_on = ! empty( $new_values[ $key ] );
			$old_on = ! empty( $old_values[ $key ] );
			if ( $new_on === $old_on ) {
				continue;
			}

			if ( 'elementor_enabled' === $key ) {
				// The Elementor decorator changes what the post adapter
				// extracts → re-crawl the post universe.
				$target = $this->first_post_target();
				if ( $target ) {
					$crawls[ $target[0] . '|' . $target[1] ] = $target;
				}
				continue;
			}

			if ( str_starts_with( $key, 'wc_' ) ) {
				// Field masks change what the product adapter extracts.
				$crawls['product|'] = array( 'product', '' );
				continue;
			}

			$target = $this->setting_target( $key );
			if ( ! $target ) {
				continue;
			}
			[ $source, $subtype ] = $target;

			if ( $new_on ) {
				$crawls[ $source . '|' . (string) $subtype ] = array( $source, (string) $subtype );
			} else {
				$this->store->mark_purging( $source, $subtype );
				$purge_needed = true;
			}
		}

		if ( $purge_needed ) {
			$this->enqueue( self::HOOK_PURGE, array() );
		}

		// The hook fires after the option write, so enabled_targets() already
		// reflects the new toggles — drop crawls for still-disabled targets.
		$allowed = array();
		foreach ( $this->enabled_targets() as [ $source, $subtype ] ) {
			$allowed[ $source . '|' . $subtype ] = true;
		}
		foreach ( $crawls as $crawl_key => [ $source, $subtype ] ) {
			if ( isset( $allowed[ $crawl_key ] ) ) {
				$this->enqueue_crawl( $source, $subtype, 0 );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Delta hooks
	 * ------------------------------------------------------------------- */

	/**
	 * Attach every adapter's delta hooks. Upserts are debounced through a
	 * unique 30s scheduled action; deletes flow to the store immediately.
	 */
	private function register_delta_hooks(): void {
		foreach ( $this->adapters->all() as $source => $adapter ) {
			foreach ( $adapter->delta_hooks() as $hook => $spec ) {
				$map = $spec['map'] ?? null;
				if ( ! is_string( $hook ) || ! is_callable( $map ) ) {
					continue;
				}

				add_action(
					$hook,
					function ( ...$args ) use ( $source, $map ): void {
						$this->on_delta( (string) $source, $map, $args );
					},
					10,
					max( 1, (int) ( $spec['args'] ?? 1 ) )
				);
			}
		}
	}

	/**
	 * Resolve one delta hook firing into a store action.
	 *
	 * @param string   $source Adapter id.
	 * @param callable $map    Hook-args → delta descriptor resolver.
	 * @param array    $args   Raw hook args.
	 */
	private function on_delta( string $source, callable $map, array $args ): void {
		try {
			$delta = $map( ...$args );
		} catch ( Throwable $e ) {
			$this->journal->error( self::AGENT_ID, $e, null, array( 'source' => $source ) );
			return;
		}

		if ( ! is_array( $delta ) || '' === (string) ( $delta['external_id'] ?? '' ) ) {
			return;
		}

		$external_id = (string) $delta['external_id'];

		if ( 'delete' === (string) ( $delta['action'] ?? 'upsert' ) ) {
			$this->store->delete( $source, $external_id );
			return;
		}

		// Upsert: skip when the (source, subtype) toggle is off. Descriptors
		// may carry an optional 'subtype'; without one, only the source-level
		// gate applies here and index_item() gates the extracted subtype.
		$enabled = $this->enabled_subtypes()[ $source ] ?? array();
		if ( ! $enabled ) {
			return;
		}
		$subtype = isset( $delta['subtype'] ) ? (string) $delta['subtype'] : null;
		if ( null !== $subtype && $this->is_toggleable_subtype( $source, $subtype ) && ! in_array( $subtype, $enabled, true ) ) {
			return;
		}

		$this->enqueue(
			self::HOOK_INDEX_ITEM,
			array(
				'source'      => $source,
				'external_id' => $external_id,
			),
			self::DELTA_DEBOUNCE
		);
	}

	/* ---------------------------------------------------------------------
	 * Crawl ordering
	 * ------------------------------------------------------------------- */

	/**
	 * Canonical crawl order over all available adapters, ignoring toggles:
	 * site, menu, post:page, post:post, post:{cpt…} (alphabetical), product,
	 * taxonomy, then custom sources. 'manual' is excluded — its entries are
	 * indexed when created.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function full_targets(): array {
		$adapters = $this->adapters->all();
		$targets  = array();

		foreach ( array( 'site', 'menu' ) as $source ) {
			if ( isset( $adapters[ $source ] ) ) {
				$targets[] = array( $source, '' );
			}
		}

		if ( isset( $adapters['post'] ) ) {
			$types = array_map( 'strval', array_keys( $adapters['post']->subtypes() ) );
			$cpts  = array_values( array_diff( $types, array( 'post', 'page' ) ) );
			sort( $cpts );
			foreach ( array_merge( array_values( array_intersect( array( 'page', 'post' ), $types ) ), $cpts ) as $type ) {
				$targets[] = array( 'post', $type );
			}
		}

		foreach ( array( 'product', 'taxonomy' ) as $source ) {
			if ( isset( $adapters[ $source ] ) ) {
				$targets[] = array( $source, '' );
			}
		}

		foreach ( array_keys( $adapters ) as $source ) {
			if ( ! in_array( $source, array( 'site', 'menu', 'post', 'product', 'taxonomy', 'manual' ), true ) ) {
				$targets[] = array( (string) $source, '' );
			}
		}

		return $targets;
	}

	/**
	 * full_targets() filtered down to currently-enabled toggles.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function enabled_targets(): array {
		$enabled = $this->enabled_subtypes();
		$out     = array();

		foreach ( $this->full_targets() as [ $source, $subtype ] ) {
			$list = $enabled[ $source ] ?? array();
			if ( ! $list ) {
				continue;
			}
			if ( '' !== $subtype && ! in_array( $subtype, $list, true ) ) {
				continue;
			}
			$out[] = array( $source, $subtype );
		}

		return $out;
	}

	/**
	 * First enabled post target in priority order, or null.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function first_post_target(): ?array {
		foreach ( $this->enabled_targets() as $pair ) {
			if ( 'post' === $pair[0] ) {
				return $pair;
			}
		}

		return null;
	}

	/**
	 * Enqueue the crawl for the first enabled target after the given one in
	 * canonical order (the chain step).
	 *
	 * @param string $source  Just-finished source.
	 * @param string $subtype Just-finished subtype.
	 */
	private function chain_next( string $source, string $subtype ): void {
		$full = $this->full_targets();

		$rank = null;
		foreach ( $full as $i => $pair ) {
			if ( $pair[0] === $source && $pair[1] === $subtype ) {
				$rank = $i;
				break;
			}
		}
		if ( null === $rank ) {
			// Unknown pair (subtype vanished): resume after its source's last target.
			foreach ( $full as $i => $pair ) {
				if ( $pair[0] === $source ) {
					$rank = $i;
				}
			}
		}
		if ( null === $rank ) {
			return;
		}

		foreach ( $this->enabled_targets() as $pair ) {
			$pair_rank = array_search( $pair, $full, true );
			if ( false !== $pair_rank && $pair_rank > $rank ) {
				$this->enqueue_crawl( $pair[0], $pair[1], 0 );
				return;
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Budget + batch size
	 * ------------------------------------------------------------------- */

	/**
	 * Recompute the wall-clock deadline for the current handler invocation.
	 * Called at the top of every Action Scheduler handler so long-lived
	 * runners (queue loops, WP-CLI) get a fresh window per invocation instead
	 * of a stale per-process deadline. Under CLI, REQUEST_TIME_FLOAT and
	 * max_execution_time describe the whole process, not this invocation, so
	 * a fixed 60s window is used instead.
	 */
	private function reset_deadline(): void {
		if ( 'cli' === PHP_SAPI ) {
			$this->deadline = microtime( true ) + 60.0;
			return;
		}

		$probes = (array) ( $this->detector->report()['probes'] ?? array() );
		$limit  = (int) ( $probes['max_execution_time'] ?? 30 );

		$this->deadline = microtime( true ) + ( ( $limit > 0 ? $limit : 300 ) * self::TIME_FRACTION );
	}

	/**
	 * Whether the run may index another item: under 70% of the declared
	 * max_execution_time (measured from request start) and under 60% of the
	 * memory limit, both from the capability report probes.
	 */
	private function under_budget(): bool {
		$probes = (array) ( $this->detector->report()['probes'] ?? array() );

		if ( null === $this->deadline ) {
			if ( 'cli' === PHP_SAPI ) {
				$this->reset_deadline();
			} else {
				$limit  = (int) ( $probes['max_execution_time'] ?? 30 );
				$window = ( $limit > 0 ? $limit : 300 ) * self::TIME_FRACTION;
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				$start          = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
				$this->deadline = $start + $window;
			}
		}

		if ( microtime( true ) >= $this->deadline ) {
			return false;
		}

		$memory_limit = (int) ( $probes['memory_limit_bytes'] ?? 128 * 1048576 );
		if ( $memory_limit > 0 && memory_get_usage( true ) >= $memory_limit * self::MEMORY_FRACTION ) {
			return false;
		}

		return true;
	}

	/**
	 * Adaptive batch size, persisted in option agyl_kb_batch_size (5–25).
	 */
	private function batch_size(): int {
		$size = (int) get_option( 'agyl_kb_batch_size', self::BATCH_DEFAULT );
		if ( $size <= 0 ) {
			$size = self::BATCH_DEFAULT;
		}

		return max( self::BATCH_MIN, min( self::BATCH_MAX, $size ) );
	}

	/**
	 * Shrink after a budget stop, grow gently after a clean batch.
	 *
	 * @param int  $current Batch size used this run.
	 * @param bool $stopped Whether the run hit the budget.
	 */
	private function adapt_batch_size( int $current, bool $stopped ): void {
		$next = $stopped ? $current - 5 : $current + 2;
		$next = max( self::BATCH_MIN, min( self::BATCH_MAX, $next ) );

		if ( $next !== $current ) {
			update_option( 'agyl_kb_batch_size', $next, false );
		}
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/**
	 * Effective 'sources' tab values via the injected resolver.
	 *
	 * @return array<string, mixed>
	 */
	private function sources_settings(): array {
		$settings = ( $this->settings_resolver )();

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Enabled subtypes per source for the current settings.
	 *
	 * @return array<string, string[]>
	 */
	private function enabled_subtypes(): array {
		return $this->adapters->enabled_subtypes( $this->sources_settings() );
	}

	/**
	 * Whether the subtype is one of the adapter's toggleable subtype keys.
	 *
	 * @param string $source  Adapter id.
	 * @param string $subtype Subtype.
	 */
	private function is_toggleable_subtype( string $source, string $subtype ): bool {
		$adapter = $this->adapters->get( $source );

		return $adapter && array_key_exists( $subtype, $adapter->subtypes() );
	}

	/**
	 * (source, subtype-or-null) behind one sources toggle key; null for
	 * non-toggle keys (wc_* masks, elementor_enabled).
	 *
	 * @param string $key Settings field key.
	 * @return array{0: string, 1: ?string}|null
	 */
	private function setting_target( string $key ): ?array {
		switch ( $key ) {
			case 'posts_enabled':
				return array( 'post', 'post' );
			case 'pages_enabled':
				return array( 'post', 'page' );
			case 'menus_enabled':
				return array( 'menu', null );
			case 'site_identity_enabled':
				return array( 'site', null );
			case 'taxonomies_enabled':
				return array( 'taxonomy', null );
			case 'woocommerce_enabled':
				return array( 'product', null );
		}

		if ( preg_match( '/^cpt_(.+)_enabled$/', $key, $m ) ) {
			return array( 'post', $m[1] );
		}

		return null;
	}

	/**
	 * Cheap staleness heuristic: the fingerprint contract front-loads the
	 * modified time, so a stored source_modified_gmt prefix match (or an
	 * embedded content hash) means "unchanged".
	 *
	 * @param string $fingerprint Adapter fingerprint.
	 * @param array  $row         Document row.
	 */
	private function fingerprint_current( string $fingerprint, array $row ): bool {
		$modified = (string) ( $row['source_modified_gmt'] ?? '' );
		if ( '' !== $modified && str_starts_with( $fingerprint, $modified ) ) {
			return true;
		}

		$hash = (string) ( $row['content_hash'] ?? '' );
		if ( '' !== $hash && str_contains( $fingerprint, $hash ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Final reconcile page: delete active docs the source no longer
	 * enumerates and clear exclusion tombstones whose items are gone.
	 *
	 * @param string   $source Adapter id.
	 * @param string[] $seen   External ids seen across all pages.
	 */
	private function cleanup_missing( string $source, array $seen, int $run_started = 0 ): void {
		global $wpdb;

		/*
		 * Manual documents have no upstream to re-derive from: their KB rows
		 * ARE the content. The reconciler never deletes them (only the
		 * dedicated DELETE endpoint does).
		 */
		if ( 'manual' === $source ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT external_id, status, indexed_at FROM ' . $wpdb->prefix . 'agyl_kb_documents WHERE source = %s AND status IN (%s, %s)',
				$source,
				Store::STATUS_ACTIVE,
				Store::STATUS_EXCLUDED
			),
			ARRAY_A
		);

		$seen_map = array_fill_keys( array_map( 'strval', $seen ), true );

		foreach ( (array) $rows as $row ) {
			$external_id = (string) $row['external_id'];
			if ( isset( $seen_map[ $external_id ] ) ) {
				continue;
			}

			/*
			 * OFFSET-paged enumeration can skip an item that shifted position
			 * mid-run, and items indexed AFTER the run started were never
			 * enumerated at all. Only rows that pre-date the run start are
			 * eligible; the rest are re-evaluated by the next daily cycle.
			 */
			if ( $run_started > 0 && ! empty( $row['indexed_at'] ) && strtotime( (string) $row['indexed_at'] . ' UTC' ) >= $run_started ) {
				continue;
			}

			if ( Store::STATUS_EXCLUDED === (string) $row['status'] ) {
				$this->store->remove_tombstone( $source, $external_id );
			} else {
				$this->store->delete( $source, $external_id );
			}
		}
	}

	/**
	 * Subtypes a reconcile run must page through for a source. Post-like
	 * sources page per ENABLED subtype (so disabled subtypes are never
	 * enumerated → no doomed index jobs); single-universe sources use ''.
	 *
	 * @param string   $source  Adapter id.
	 * @param string[] $enabled Enabled subtypes for the source.
	 * @return string[]
	 */
	private function reconcile_subtypes( string $source, array $enabled ): array {
		$adapter = $this->adapters->get( $source );
		if ( ! $adapter ) {
			return array();
		}

		$declared = array_keys( $adapter->subtypes() );
		$declared = array_map( 'strval', $declared );

		// Single-universe adapters declare [''] (or [0 => '']) → one pass.
		if ( array() === array_filter( $declared, static fn ( string $s ): bool => '' !== $s ) ) {
			return array( '' );
		}

		$out = array();
		foreach ( $enabled as $subtype ) {
			$subtype = (string) $subtype;
			if ( '' !== $subtype && in_array( $subtype, $declared, true ) ) {
				$out[] = $subtype;
			}
		}

		return $out ?: array( '' );
	}

	/**
	 * Whether a reconcile action for this source is already pending.
	 *
	 * @param string $source Adapter id.
	 */
	private function has_pending_reconcile( string $source ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		try {
			$ids = as_get_scheduled_actions(
				array(
					'hook'          => self::HOOK_RECONCILE,
					'group'         => self::GROUP,
					'status'        => \ActionScheduler_Store::STATUS_PENDING,
					'partial_args_matching' => 'like',
					'args'          => array( 'source' => $source ),
					'per_page'      => 1,
				),
				'ids'
			);

			return is_array( $ids ) && count( $ids ) > 0;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Remove per-run reconcile options older than 7 days (aborted runs,
	 * runs whose final page never executed).
	 */
	private function gc_reconcile_options(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'agy\\_kb\\_reconcile\\_seen\\_%' LIMIT 200"
		);

		$cutoff = time() - WEEK_IN_SECONDS;
		foreach ( (array) $names as $name ) {
			$run = get_option( $name, false );
			if ( ! is_array( $run ) || (int) ( $run['started'] ?? 0 ) < $cutoff ) {
				delete_option( $name );
			}
		}
	}

	/**
	 * Best-effort: flip an existing document row to status 'error' after an
	 * indexing failure (the Store has no status setter; excluded tombstones
	 * are left untouched).
	 *
	 * @param string $source      Adapter id.
	 * @param string $external_id External id.
	 */
	private function mark_error_row( string $source, string $external_id ): void {
		global $wpdb;

		try {
			$row = $this->store->document_row( $source, $external_id );
			// Only ACTIVE rows may flip to error: a 'purging' row must stay
			// purging so purge_batch still drains its chunks/terms, and
			// 'excluded' tombstones are owner intent.
			if ( ! $row || Store::STATUS_ACTIVE !== $row['status'] ) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$wpdb->prefix . 'agyl_kb_documents',
				array( 'status' => Store::STATUS_ERROR ),
				array( 'id' => (int) $row['id'] )
			);

			if ( $updated ) {
				$this->store->bump_kb_version(); // Row left the active set.
			}
		} catch ( Throwable $e ) {
			// Best effort only; the original failure is already journaled.
			unset( $e );
		}
	}

	/**
	 * Enqueue a crawl step.
	 *
	 * @param string $source  Adapter id.
	 * @param string $subtype Subtype.
	 * @param int    $offset  Cursor offset.
	 */
	private function enqueue_crawl( string $source, string $subtype, int $offset ): void {
		$this->enqueue( self::HOOK_FULL_CRAWL, array(
			'source'  => $source,
			'subtype' => $subtype,
			'offset'  => $offset,
		) );
	}

	/**
	 * Unique Action Scheduler enqueue in group agentyllo-kb (AsyncBus
	 * pattern: AS 4.x dedupes on hook+args via `unique`; older elected 3.x
	 * copies are pre-checked with as_has_scheduled_action).
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Hook args (insertion order = handler arg order).
	 * @param int    $delay Seconds to delay; 0 = async.
	 */
	private function enqueue( string $hook, array $args, int $delay = 0 ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		/*
		 * AS < 4.0 dedupes on hook+group only, so we pre-check ourselves —
		 * but PENDING-only: as_has_scheduled_action() also matches the
		 * currently RUNNING action, which would swallow every legitimate
		 * self-re-enqueue (purge rounds, zero-progress crawl retries).
		 */
		if ( ! $this->supports_unique_args() && $this->has_pending_action( $hook, $args ) ) {
			return;
		}

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP, true );
			return;
		}

		as_enqueue_async_action( $hook, $args, self::GROUP, true );
	}

	/**
	 * Whether a PENDING (not running) action with these exact args exists.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Hook args.
	 */
	private function has_pending_action( string $hook, array $args ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		try {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			);

			return is_array( $ids ) && count( $ids ) > 0;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether the elected Action Scheduler honors arg-inclusive uniqueness
	 * (4.0+). Older 3.x copies dedupe on hook+group only.
	 */
	private function supports_unique_args(): bool {
		if ( ! class_exists( '\ActionScheduler_Versions' ) ) {
			return false;
		}

		try {
			$version = (string) \ActionScheduler_Versions::instance()->latest_version();

			return version_compare( $version, '4.0.0', '>=' );
		} catch ( Throwable $e ) {
			return false;
		}
	}
}
