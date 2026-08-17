<?php
/**
 * HTML → normalized blocks.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Indexer;

use Agentyllo\KB\Source\NormalizedBlock;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

defined( 'ABSPATH' ) || exit;

/**
 * Reduces rendered HTML to clean text blocks, harvesting outgoing links and
 * media-library image ids on the way. Style/script/nav noise is dropped.
 */
final class Normalizer {

	private const SKIP_TAGS = array( 'script', 'style', 'noscript', 'iframe', 'svg', 'form', 'button', 'nav', 'template', 'head' );

	/**
	 * Blocks harvested during the current run.
	 *
	 * @var NormalizedBlock[]
	 */
	private array $blocks = array();

	/**
	 * Links harvested during the current run.
	 *
	 * @var array<int, array{url: string, anchor: string}>
	 */
	private array $links = array();

	/**
	 * wp-image-{id} attachment ids in encounter order.
	 *
	 * @var int[]
	 */
	private array $image_ids = array();

	/**
	 * Text runs accumulating toward the current paragraph block.
	 *
	 * @var string[]
	 */
	private array $pending = array();

	/**
	 * Normalize an HTML fragment.
	 *
	 * @param string $html Rendered HTML.
	 * @return array{blocks: NormalizedBlock[], links: array<int, array{url: string, anchor: string}>, image_ids: int[]}
	 */
	public function normalize( string $html ): array {
		$this->blocks    = array();
		$this->links     = array();
		$this->image_ids = array();
		$this->pending   = array();

		$html = trim( $html );
		if ( '' === $html ) {
			return array(
				'blocks'    => array(),
				'links'     => array(),
				'image_ids' => array(),
			);
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>',
			LIBXML_NOWARNING | LIBXML_NOERROR
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( $body ) {
			$this->walk( $body );
		}
		$this->flush_pending();

		return array(
			'blocks'    => $this->blocks,
			'links'     => $this->links,
			'image_ids' => array_values( array_unique( $this->image_ids ) ),
		);
	}

	/**
	 * Depth-first walk emitting blocks.
	 *
	 * @param DOMNode $node Current node.
	 */
	private function walk( DOMNode $node ): void {
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof DOMText ) {
				$text = $this->clean( $child->wholeText );
				if ( '' !== $text ) {
					$this->pending[] = $text;
				}
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );

			if ( in_array( $tag, self::SKIP_TAGS, true ) ) {
				continue;
			}

			switch ( $tag ) {
				case 'h1':
				case 'h2':
				case 'h3':
				case 'h4':
				case 'h5':
				case 'h6':
					$this->flush_pending();
					$text = $this->clean( $child->textContent );
					if ( '' !== $text ) {
						$this->blocks[] = new NormalizedBlock( NormalizedBlock::KIND_HEADING, $text, (int) substr( $tag, 1 ) );
					}
					$this->harvest_inline( $child );
					break;

				case 'ul':
				case 'ol':
					$this->flush_pending();
					$items = array();
					foreach ( $child->getElementsByTagName( 'li' ) as $li ) {
						$text = $this->clean( $li->textContent );
						if ( '' !== $text ) {
							$items[] = '• ' . $text;
						}
					}
					if ( $items ) {
						$this->blocks[] = new NormalizedBlock( NormalizedBlock::KIND_LIST, implode( "\n", $items ) );
					}
					$this->harvest_inline( $child );
					break;

				case 'table':
					$this->flush_pending();
					$rows = $this->linearize_table( $child );
					if ( '' !== $rows ) {
						$this->blocks[] = new NormalizedBlock( NormalizedBlock::KIND_TABLE, $rows );
					}
					$this->harvest_inline( $child );
					break;

				case 'p':
				case 'blockquote':
				case 'pre':
				case 'figcaption':
					$text = $this->clean( $child->textContent );
					if ( '' !== $text ) {
						$this->flush_pending();
						$this->blocks[] = new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, $text );
					}
					$this->harvest_inline( $child );
					break;

				case 'br':
					$this->flush_pending();
					break;

				case 'img':
					$this->harvest_image( $child );
					break;

				case 'a':
					$this->harvest_link( $child );
					$text = $this->clean( $child->textContent );
					if ( '' !== $text ) {
						$this->pending[] = $text;
					}
					break;

				default:
					// Containers (div/section/article/span/…): recurse.
					$this->walk( $child );
					break;
			}
		}

		$this->flush_pending();
	}

	/**
	 * Collect links/images inside an already-consumed block element.
	 *
	 * @param DOMElement $element Element.
	 */
	private function harvest_inline( DOMElement $element ): void {
		foreach ( $element->getElementsByTagName( 'a' ) as $a ) {
			$this->harvest_link( $a );
		}
		foreach ( $element->getElementsByTagName( 'img' ) as $img ) {
			$this->harvest_image( $img );
		}
	}

	/**
	 * Record an anchor.
	 *
	 * @param DOMElement $a Anchor element.
	 */
	private function harvest_link( DOMElement $a ): void {
		$href = trim( $a->getAttribute( 'href' ) );
		if ( '' === $href || str_starts_with( $href, '#' ) || str_starts_with( $href, 'javascript:' ) || str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) ) {
			return;
		}

		// Resolve relative URLs against the site.
		if ( str_starts_with( $href, '//' ) ) {
			$href = ( is_ssl() ? 'https:' : 'http:' ) . $href;
		} elseif ( str_starts_with( $href, '/' ) ) {
			$href = untrailingslashit( home_url() ) . $href;
		}

		$this->links[] = array(
			'url'    => $href,
			'anchor' => mb_substr( $this->clean( $a->textContent ), 0, 255 ),
		);
	}

	/**
	 * Record a media-library image id (class="wp-image-{id}").
	 *
	 * @param DOMElement $img Image element.
	 */
	private function harvest_image( DOMElement $img ): void {
		if ( preg_match( '/wp-image-(\d+)/', $img->getAttribute( 'class' ), $m ) ) {
			$this->image_ids[] = (int) $m[1];
		}
	}

	/**
	 * Linearize a table into "Header: value; …" lines.
	 *
	 * @param DOMElement $table Table element.
	 */
	private function linearize_table( DOMElement $table ): string {
		$headers = array();
		$lines   = array();

		foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
			$cells    = array();
			$is_headers = true;
			foreach ( $tr->childNodes as $cell ) {
				if ( $cell instanceof DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
					$cells[]    = $this->clean( $cell->textContent );
					$is_headers = $is_headers && 'th' === strtolower( $cell->tagName );
				}
			}
			if ( ! $cells ) {
				continue;
			}
			if ( $is_headers && empty( $headers ) ) {
				$headers = $cells;
				continue;
			}
			if ( $headers ) {
				$pairs = array();
				foreach ( $cells as $i => $value ) {
					$label   = $headers[ $i ] ?? (string) ( $i + 1 );
					$pairs[] = $label . ': ' . $value;
				}
				$lines[] = implode( '; ', $pairs );
			} else {
				$lines[] = implode( '; ', $cells );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Entity decode + whitespace collapse.
	 *
	 * @param string $text Raw text.
	 */
	private function clean( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[\x{00A0}\s]+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Emit accumulated inline text runs as one paragraph block.
	 */
	private function flush_pending(): void {
		if ( ! $this->pending ) {
			return;
		}
		$text          = trim( implode( ' ', $this->pending ) );
		$this->pending = array();
		if ( '' !== $text && mb_strlen( $text ) > 1 ) {
			$this->blocks[] = new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, $text );
		}
	}
}
