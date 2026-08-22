<?php
/**
 * Heading-aware hierarchical chunker.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Indexer;

use Agentyllo\KB\Retrieval\Tokenizer;
use Agentyllo\KB\Source\DocumentDraft;
use Agentyllo\KB\Source\NormalizedBlock;

defined( 'ABSPATH' ) || exit;

/**
 * Splits a draft into retrieval chunks: H2/H3 sections first, target
 * 800–1200 chars (hard max 2000), 120-char overlap only when splitting
 * inside one section, sentence-boundary splits, breadcrumb heading_path.
 * Structured facts become chunk 0 (kind 'spec').
 */
final class Chunker {

	private const TARGET_MIN = 800;
	private const TARGET_MAX = 1200;
	private const HARD_MAX   = 2000;
	private const OVERLAP    = 120;

	/**
	 * Constructor.
	 *
	 * @param Tokenizer $tokenizer Shared tokenizer (simhash term source).
	 */
	public function __construct( private readonly Tokenizer $tokenizer ) {
	}

	/**
	 * Chunk a draft.
	 *
	 * @param DocumentDraft $draft Normalized document.
	 * @return array<int, array{seq: int, kind: string, heading_path: string, content: string, token_est: int, lang: string, chunk_hash: string, simhash: string}>
	 */
	public function chunk( DocumentDraft $draft ): array {
		$chunks = array();
		$seq    = 0;

		// Chunk 0: spec chunk with machine facts as "key: value" lines.
		if ( ! empty( $draft->structured ) ) {
			$spec = $this->flatten_structured( $draft->structured );
			if ( '' !== $spec ) {
				$chunks[] = $this->build( $seq++, 'spec', $draft->title, $spec, $draft->lang );
			}
		}

		// Group blocks into sections on H2/H3 boundaries.
		$sections = $this->sections( $draft );

		foreach ( $sections as $section ) {
			$path = $section['path'];
			$text = trim( implode( "\n\n", $section['texts'] ) );
			if ( '' === $text ) {
				continue;
			}

			foreach ( $this->split_section( $text ) as $piece ) {
				$kind     = $section['kind'];
				$chunks[] = $this->build( $seq++, $kind, $path, $piece, $draft->lang );
			}
		}

		/*
		 * The title lives in heading_path, which only the OPTIONAL FULLTEXT
		 * booster searches. Prepending it to the first chunk's content puts
		 * product/term/page names into the BM25 postings — the always-on
		 * floor — so a thin-description product is findable by name.
		 */
		$title = trim( $draft->title );
		if ( '' !== $title && isset( $chunks[0] ) && ! str_starts_with( $chunks[0]['content'], $title ) ) {
			$chunks[0] = $this->build(
				$chunks[0]['seq'],
				$chunks[0]['kind'],
				$chunks[0]['heading_path'],
				$title . "\n" . $chunks[0]['content'],
				$draft->lang
			);
		}

		return $chunks;
	}

	/**
	 * Group blocks by heading structure.
	 *
	 * @param DocumentDraft $draft Draft.
	 * @return array<int, array{path: string, kind: string, texts: string[]}>
	 */
	private function sections( DocumentDraft $draft ): array {
		/** @var array<int, array{path: string, kind: string, texts: string[]}> $sections */
		$sections = array();
		$h2       = '';
		$h3       = '';
		/** @var array{path: string, kind: string, texts: string[]} $current */
		$current = array(
			'path'  => $draft->title,
			'kind'  => 'prose',
			'texts' => array(),
		);

		$flush = static function () use ( &$sections, &$current ): void {
			if ( $current['texts'] ) {
				$sections[] = $current;
			}
		};

		foreach ( $draft->blocks as $block ) {
			if ( NormalizedBlock::KIND_HEADING === $block->kind && $block->level >= 1 && $block->level <= 3 ) {
				$flush();
				if ( $block->level <= 2 ) {
					$h2 = $block->text;
					$h3 = '';
				} else {
					$h3 = $block->text;
				}
				$path    = implode( ' › ', array_filter( array( $draft->title, $h2, $h3 ) ) );
				$current = array(
					'path'  => mb_substr( $path, 0, 512 ),
					'kind'  => 'prose',
					'texts' => array(),
				);
				continue;
			}

			$kind = match ( $block->kind ) {
				NormalizedBlock::KIND_TABLE => 'table',
				NormalizedBlock::KIND_LIST  => 'list',
				NormalizedBlock::KIND_FAQ   => 'faq',
				NormalizedBlock::KIND_SPEC  => 'spec',
				default                     => 'prose',
			};

			// Deeper headings stay inline as emphasized text.
			$text = NormalizedBlock::KIND_HEADING === $block->kind ? $block->text . ':' : $block->text;

			if ( 'prose' !== $kind && 'prose' === $current['kind'] && ! $current['texts'] ) {
				$current['kind'] = $kind;
			}
			$current['texts'][] = $text;
		}

		$flush();

		return $sections;
	}

	/**
	 * Split one section's text into target-size pieces on sentence
	 * boundaries, with overlap between consecutive pieces.
	 *
	 * @param string $text Section text.
	 * @return string[]
	 */
	private function split_section( string $text ): array {
		if ( mb_strlen( $text ) <= self::TARGET_MAX ) {
			return array( $text );
		}

		// Sentence-ish segmentation (mb-safe).
		$sentences = preg_split( '/(?<=[.!?…:;])\s+(?=[\p{Lu}\p{N}•])|\n+/u', $text ) ?: array( $text );

		$pieces  = array();
		$current = '';

		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( '' === $sentence ) {
				continue;
			}

			// A single overlong sentence gets hard-wrapped.
			while ( mb_strlen( $sentence ) > self::HARD_MAX ) {
				$pieces[]  = ( '' !== $current ? $current . ' ' : '' ) . mb_substr( $sentence, 0, self::HARD_MAX );
				$sentence  = mb_substr( $sentence, self::HARD_MAX - self::OVERLAP );
				$current   = '';
			}

			$candidate = '' === $current ? $sentence : $current . ' ' . $sentence;

			if ( mb_strlen( $candidate ) > self::TARGET_MAX && mb_strlen( $current ) >= self::TARGET_MIN ) {
				$pieces[] = $current;
				$overlap  = mb_substr( $current, -self::OVERLAP );
				$space    = mb_strpos( $overlap, ' ' );
				$overlap  = false === $space ? '' : mb_substr( $overlap, $space + 1 );
				$current  = '' !== $overlap ? $overlap . ' ' . $sentence : $sentence;
			} else {
				$current = $candidate;
			}
		}

		if ( '' !== trim( $current ) ) {
			$pieces[] = trim( $current );
		}

		return $pieces;
	}

	/**
	 * Build one chunk row.
	 *
	 * @param int    $seq     Sequence.
	 * @param string $kind    Chunk kind.
	 * @param string $path    Heading path.
	 * @param string $content Chunk text.
	 * @param string $lang    Language.
	 */
	private function build( int $seq, string $kind, string $path, string $content, string $lang ): array {
		return array(
			'seq'          => $seq,
			'kind'         => $kind,
			'heading_path' => mb_substr( $path, 0, 512 ),
			'content'      => $content,
			'token_est'    => (int) ceil( mb_strlen( $content ) / 4 ),
			'lang'         => $lang,
			'chunk_hash'   => sha1( $path . '|' . $content ),
			'simhash'      => $this->simhash( $content, $lang ),
		);
	}

	/**
	 * 64-bit SimHash over the chunk's term set (near-duplicate detection),
	 * returned as 16 hex chars (avoids 64-bit signed overflow in PHP).
	 *
	 * @param string $content Chunk text.
	 * @param string $lang    Language.
	 */
	private function simhash( string $content, string $lang ): string {
		$vector = array_fill( 0, 64, 0 );

		foreach ( $this->tokenizer->terms( $content, $lang ) as $term => $tf ) {
			// Numeric-looking tokens become int array keys — cast back.
			$hash = substr( hash( 'sha1', (string) $term ), 0, 16 ); // 64 bits as hex.
			$hi   = (int) hexdec( substr( $hash, 0, 8 ) );
			$lo   = (int) hexdec( substr( $hash, 8, 8 ) );

			for ( $bit = 0; $bit < 32; $bit++ ) {
				$vector[ $bit ]      += ( ( $lo >> $bit ) & 1 ) ? $tf : -$tf;
				$vector[ $bit + 32 ] += ( ( $hi >> $bit ) & 1 ) ? $tf : -$tf;
			}
		}

		$hi_bits = 0;
		$lo_bits = 0;
		for ( $bit = 0; $bit < 32; $bit++ ) {
			if ( $vector[ $bit ] > 0 ) {
				$lo_bits |= ( 1 << $bit );
			}
			if ( $vector[ $bit + 32 ] > 0 ) {
				$hi_bits |= ( 1 << $bit );
			}
		}

		return sprintf( '%08x%08x', $hi_bits, $lo_bits );
	}

	/**
	 * Flatten structured facts to "key: value" lines.
	 *
	 * @param array  $structured Structured facts.
	 * @param string $prefix     Key prefix for nesting.
	 */
	private function flatten_structured( array $structured, string $prefix = '' ): string {
		$lines = array();

		foreach ( $structured as $key => $value ) {
			$label = '' === $prefix ? (string) $key : $prefix . ' ' . $key;
			if ( is_array( $value ) ) {
				$flat = $this->flatten_structured( $value, $label );
				if ( '' !== $flat ) {
					$lines[] = $flat;
				}
			} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
				$lines[] = $label . ': ' . ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value );
			}
		}

		return implode( "\n", $lines );
	}
}
