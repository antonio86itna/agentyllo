<?php
/**
 * One normalized content block.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

defined( 'ABSPATH' ) || exit;

/**
 * The unit every source adapter reduces content to: clean text with light
 * structure, no markup, no style noise.
 */
final class NormalizedBlock {

	public const KIND_HEADING   = 'heading';
	public const KIND_PARAGRAPH = 'paragraph';
	public const KIND_LIST      = 'list';
	public const KIND_TABLE     = 'table';
	public const KIND_SPEC      = 'spec';
	public const KIND_FAQ       = 'faq';

	/**
	 * Constructor.
	 *
	 * @param string $kind  One of the KIND_* constants.
	 * @param string $text  Clean plain text (lists/tables linearized).
	 * @param int    $level Heading level 1-6 (0 for non-headings).
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $text,
		public readonly int $level = 0,
	) {
	}
}
