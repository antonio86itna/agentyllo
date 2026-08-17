<?php
/**
 * Chat pipeline stage contract.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Stages mutate the ChatContext in order. A stage must degrade, not throw,
 * for expected conditions; the pipeline catches Throwables defensively and
 * continues so the classic floor always answers.
 */
interface Stage {

	/**
	 * Stable stage id, e.g. 'normalize', 'retrieve', 'compose'.
	 */
	public function name(): string;

	/**
	 * Canonical widget status event this stage emits
	 * (queued|understanding|searching|checking_products|linking|verifying|generating|formatting)
	 * or '' for silent stages.
	 */
	public function status_event(): string;

	/**
	 * Process the context in place.
	 *
	 * @param ChatContext $context The conversation context.
	 */
	public function process( ChatContext $context ): void;
}
