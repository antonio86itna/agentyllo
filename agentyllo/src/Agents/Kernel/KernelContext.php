<?php
/**
 * Concrete AgentContext handed to agents by the orchestrator.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Kernel;

use Agentyllo\Agents\Contracts\AgentContext;
use Agentyllo\Agents\Contracts\EventBusInterface;
use Agentyllo\Agents\Contracts\JournalInterface;
use Agentyllo\Agents\Contracts\MemoryStoreInterface;
use Agentyllo\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable per-task view of the kernel.
 */
final class KernelContext implements AgentContext {

	/**
	 * Constructor.
	 *
	 * @param MemoryStoreInterface $memory       Memory store.
	 * @param JournalInterface     $journal      Journal.
	 * @param EventBusInterface    $bus          Event bus.
	 * @param Container            $services     Container.
	 * @param float                $deadline     Absolute microtime deadline for this task.
	 * @param array                $lessons      Lessons matched to the current task.
	 * @param array                $site_profile Site profile snapshot.
	 */
	public function __construct(
		private readonly MemoryStoreInterface $memory,
		private readonly JournalInterface $journal,
		private readonly EventBusInterface $bus,
		private readonly Container $services,
		private readonly float $deadline,
		private readonly array $lessons = array(),
		private readonly array $site_profile = array(),
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function memory(): MemoryStoreInterface {
		return $this->memory;
	}

	/**
	 * {@inheritDoc}
	 */
	public function journal(): JournalInterface {
		return $this->journal;
	}

	/**
	 * {@inheritDoc}
	 */
	public function bus(): EventBusInterface {
		return $this->bus;
	}

	/**
	 * {@inheritDoc}
	 */
	public function lessons(): array {
		return $this->lessons;
	}

	/**
	 * {@inheritDoc}
	 */
	public function remaining_ms(): int {
		return (int) max( 0, round( ( $this->deadline - microtime( true ) ) * 1000 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function services(): Container {
		return $this->services;
	}

	/**
	 * {@inheritDoc}
	 */
	public function site_profile(): array {
		return $this->site_profile;
	}
}
