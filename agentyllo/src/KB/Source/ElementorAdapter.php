<?php
/**
 * Elementor extraction decorator for the post adapter.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

use Agentyllo\KB\Indexer\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * NOT a SourceAdapter: a decorator service the PostTypeAdapter consults when
 * a post is built with Elementor (the rendered-content fallback loses widget
 * structure). Walks the `_elementor_data` JSON tree and reduces both legacy
 * widgets (via a widget map) and Atomic v4 elements ($$type props) to
 * NormalizedBlocks, harvested links, and media-library image ids.
 *
 * The default widget map is a const, overridable through the
 * `agy_kb_elementor_map` option (merged over the defaults) so the remote
 * registry can teach the extractor new widgets without a plugin release.
 *
 * Defensive everywhere: malformed data returns null, never throws.
 */
final class ElementorAdapter {

	private const MAX_DEPTH          = 64;
	private const MIN_SWEEP_LEN      = 25;
	private const TEMPLATE_MAX_DEPTH = 3;

	/**
	 * Default widget → extraction strategy map. Strategy names are internal;
	 * unknown widgets fall back to the heuristic content sweep.
	 *
	 * @var array<string, string>
	 */
	private const WIDGET_MAP = array(
		'heading'        => 'heading',
		'text-editor'    => 'text_editor',
		'image'          => 'image',
		'button'         => 'button',
		'icon-list'      => 'icon_list',
		'accordion'      => 'faq_tabs',
		'toggle'         => 'faq_tabs',
		'tabs'           => 'faq_tabs',
		'image-box'      => 'title_description',
		'icon-box'       => 'title_description',
		'testimonial'    => 'testimonial',
		'call-to-action' => 'call_to_action',
		'shortcode'      => 'shortcode',
		'spacer'         => 'skip',
		'divider'        => 'skip',
	);

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
	 * Attachment ids harvested during the current run.
	 *
	 * @var int[]
	 */
	private array $image_ids = array();

	/**
	 * Template posts already inlined during the current run (cycle guard).
	 *
	 * @var array<int, true>
	 */
	private array $visited_templates = array();

	/**
	 * Template-inlining depth during the current run.
	 *
	 * @var int
	 */
	private int $template_depth = 0;

	/**
	 * Merged widget map, cached per request.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $map = null;

	/**
	 * Constructor.
	 *
	 * @param Normalizer $normalizer HTML → blocks normalizer.
	 */
	public function __construct( private readonly Normalizer $normalizer ) {
	}

	/**
	 * Whether Elementor is active.
	 */
	public function is_available(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Whether the post is built with the Elementor editor.
	 *
	 * @param int $post_id Post id.
	 */
	public function is_built_with_elementor( int $post_id ): bool {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Extract structured content from the post's Elementor tree.
	 *
	 * @param int $post_id Post id.
	 * @return array{blocks: NormalizedBlock[], links: array<int, array{url: string, anchor: string}>, image_ids: int[]}|null
	 *         Null on malformed/empty data (caller falls back to rendered content).
	 */
	public function extract_elementor( int $post_id ): ?array {
		try {
			$raw = get_post_meta( $post_id, '_elementor_data', true );

			if ( is_array( $raw ) ) {
				$data = $raw;
			} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$data = json_decode( $raw, true );
			} else {
				return null;
			}

			if ( ! is_array( $data ) || ! $data ) {
				return null;
			}

			$this->blocks            = array();
			$this->links             = array();
			$this->image_ids         = array();
			$this->visited_templates = array();
			$this->template_depth    = 0;

			foreach ( $data as $element ) {
				$this->walk_element( $element, 0 );
			}

			if ( ! $this->blocks && ! $this->links && ! $this->image_ids ) {
				return null;
			}

			return array(
				'blocks'    => $this->blocks,
				'links'     => array_values( $this->links ),
				'image_ids' => array_values( array_unique( array_map( 'intval', $this->image_ids ) ) ),
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Depth-first walk over one Elementor element and its children.
	 *
	 * @param mixed $element Element node.
	 * @param int   $depth   Recursion depth guard.
	 */
	private function walk_element( mixed $element, int $depth ): void {
		if ( $depth > self::MAX_DEPTH || ! is_array( $element ) ) {
			return;
		}

		$el_type  = is_string( $element['elType'] ?? null ) ? $element['elType'] : '';
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();

		if ( str_starts_with( $el_type, 'e-' ) || $this->has_atomic_props( $settings ) ) {
			// Atomic v4 element: unwrap $$type props.
			$this->extract_atomic( $settings, $depth );
		} elseif ( 'widget' === $el_type ) {
			$widget_type = is_string( $element['widgetType'] ?? null ) ? $element['widgetType'] : '';
			if ( 'global' === $widget_type ) {
				// Global widget: inline the referenced template's own tree.
				$this->walk_template( $this->template_id( $element, $settings ), $depth );
			} else {
				$this->extract_widget( $widget_type, $settings );
			}
		} elseif ( 'template' === $el_type ) {
			$this->walk_template( $this->template_id( $element, $settings ), $depth );
		}

		foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
			$this->walk_element( $child, $depth + 1 );
		}
	}

	/**
	 * Whether any top-level settings value is a {"$$type": ...} atomic prop.
	 *
	 * @param array $settings Element settings.
	 */
	private function has_atomic_props( array $settings ): bool {
		foreach ( $settings as $value ) {
			if ( is_array( $value ) && isset( $value['$$type'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Unwrap Atomic v4 settings: $$type string/html/link/image values.
	 * Styles maps and class props are ignored entirely.
	 *
	 * @param array $settings Atomic element settings.
	 * @param int   $depth    Recursion depth guard.
	 */
	private function extract_atomic( array $settings, int $depth ): void {
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && ( 'styles' === $key || false !== stripos( $key, 'class' ) ) ) {
				continue;
			}
			$this->unwrap_atomic( $value, $depth + 1 );
		}
	}

	/**
	 * Recursively unwrap one atomic prop value.
	 *
	 * @param mixed $value Prop value.
	 * @param int   $depth Recursion depth guard.
	 */
	private function unwrap_atomic( mixed $value, int $depth ): void {
		if ( $depth > self::MAX_DEPTH || ! is_array( $value ) ) {
			return;
		}

		$type = $value['$$type'] ?? null;

		if ( is_string( $type ) ) {
			$inner = $value['value'] ?? null;

			switch ( $type ) {
				case 'html':
					if ( is_string( $inner ) ) {
						$this->append_html( $inner );
					}
					return;

				case 'string':
					if ( is_string( $inner ) ) {
						$this->append_text( $inner );
					}
					return;

				case 'link':
					$this->harvest_atomic_link( $inner );
					return;

				case 'image':
				case 'image-src':
				case 'image-attachment-id':
					$this->harvest_atomic_image( $inner, $depth );
					return;

				case 'styles':
				case 'classes':
					return; // Style noise, never content.

				default:
					// Unknown wrapper type: descend into array payloads only
					// (scalar payloads are style-ish values like "10px").
					if ( is_array( $inner ) ) {
						$this->unwrap_atomic( $inner, $depth + 1 );
					}
					return;
			}
		}

		foreach ( $value as $key => $child ) {
			if ( is_string( $key ) && ( 'styles' === $key || false !== stripos( $key, 'class' ) ) ) {
				continue;
			}
			$this->unwrap_atomic( $child, $depth + 1 );
		}
	}

	/**
	 * Harvest a URL from an atomic link payload.
	 *
	 * @param mixed $inner Link value payload.
	 */
	private function harvest_atomic_link( mixed $inner ): void {
		if ( is_string( $inner ) ) {
			$this->add_link( $inner, '' );
			return;
		}
		if ( ! is_array( $inner ) ) {
			return;
		}

		$anchor = '';
		foreach ( array( 'label', 'text' ) as $anchor_key ) {
			if ( is_string( $inner[ $anchor_key ] ?? null ) ) {
				$anchor = $inner[ $anchor_key ];
				break;
			}
		}

		foreach ( array( 'destination', 'url', 'href' ) as $url_key ) {
			$candidate = $inner[ $url_key ] ?? null;
			if ( is_array( $candidate ) && is_string( $candidate['value'] ?? null ) ) {
				$candidate = $candidate['value'];
			}
			if ( is_string( $candidate ) && '' !== $candidate ) {
				$this->add_link( $candidate, $anchor );
				return;
			}
		}
	}

	/**
	 * Harvest attachment ids from an atomic image payload.
	 *
	 * @param mixed $inner Image value payload.
	 * @param int   $depth Recursion depth guard.
	 */
	private function harvest_atomic_image( mixed $inner, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		if ( is_numeric( $inner ) ) {
			$this->add_image( (int) $inner );
			return;
		}
		if ( ! is_array( $inner ) ) {
			return;
		}

		foreach ( $inner as $key => $value ) {
			if ( in_array( $key, array( 'id', 'attachment_id' ), true ) ) {
				if ( is_array( $value ) && is_numeric( $value['value'] ?? null ) ) {
					$value = $value['value'];
				}
				if ( is_numeric( $value ) ) {
					$this->add_image( (int) $value );
				}
				continue;
			}
			if ( is_array( $value ) ) {
				$this->harvest_atomic_image( $value, $depth + 1 );
			}
		}
	}

	/**
	 * Extract a legacy widget through the widget map, falling back to the
	 * heuristic content sweep for unknown widgets.
	 *
	 * @param string $widget_type Widget type id.
	 * @param array  $settings    Widget settings.
	 */
	private function extract_widget( string $widget_type, array $settings ): void {
		$strategy = $this->widget_map()[ $widget_type ] ?? '';

		switch ( $strategy ) {
			case 'skip':
				return;

			case 'heading':
				$this->append_text( $this->str( $settings, 'title' ), $this->heading_level( $settings['header_size'] ?? null ) );
				$this->harvest_link_setting( $settings['link'] ?? null, $this->str( $settings, 'title' ) );
				return;

			case 'text_editor':
				$this->append_html( $this->str( $settings, 'editor' ) );
				return;

			case 'image':
				$this->harvest_image_setting( $settings['image'] ?? null );
				$this->append_text( $this->str( $settings, 'caption' ) );
				$this->harvest_link_setting( $settings['link'] ?? null, '' );
				return;

			case 'button':
				$this->harvest_link_setting( $settings['link'] ?? null, $this->str( $settings, 'text' ) );
				return;

			case 'icon_list':
				$this->extract_icon_list( $settings );
				return;

			case 'faq_tabs':
				$this->extract_faq_tabs( $settings );
				return;

			case 'title_description':
				$this->append_text( $this->str( $settings, 'title_text' ), 3 );
				$this->append_text( $this->str( $settings, 'description_text' ) );
				$this->harvest_image_setting( $settings['image'] ?? null );
				$this->harvest_link_setting( $settings['link'] ?? null, $this->str( $settings, 'title_text' ) );
				return;

			case 'testimonial':
				$content = $this->clean_text( $this->str( $settings, 'testimonial_content' ) );
				$name    = $this->clean_text( $this->str( $settings, 'testimonial_name' ) );
				if ( '' !== $content ) {
					$this->blocks[] = new NormalizedBlock(
						NormalizedBlock::KIND_PARAGRAPH,
						'' !== $name ? $content . ' — ' . $name : $content
					);
				}
				$this->harvest_image_setting( $settings['testimonial_image'] ?? null );
				return;

			case 'call_to_action':
				$this->append_text( $this->str( $settings, 'title' ), 3 );
				$this->append_text( $this->str( $settings, 'description' ) );
				$this->harvest_link_setting( $settings['link'] ?? null, $this->str( $settings, 'button' ) );
				$this->harvest_image_setting( $settings['bg_image'] ?? null );
				return;

			case 'shortcode':
				$this->extract_shortcode( $this->str( $settings, 'shortcode' ) );
				return;

			default:
				$this->sweep_settings( $settings, 0 );
				return;
		}
	}

	/**
	 * icon-list widget: items to one list block, item links harvested.
	 *
	 * @param array $settings Widget settings.
	 */
	private function extract_icon_list( array $settings ): void {
		$items = array();

		foreach ( (array) ( $settings['icon_list'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$text = $this->clean_text( $this->str( $item, 'text' ) );
			if ( '' !== $text ) {
				$items[] = '• ' . $text;
			}
			$this->harvest_link_setting( $item['link'] ?? null, $text );
		}

		if ( $items ) {
			$this->blocks[] = new NormalizedBlock( NormalizedBlock::KIND_LIST, implode( "\n", $items ) );
		}
	}

	/**
	 * accordion/toggle/tabs widgets: tab_title + tab_content as FAQ blocks.
	 *
	 * @param array $settings Widget settings.
	 */
	private function extract_faq_tabs( array $settings ): void {
		foreach ( (array) ( $settings['tabs'] ?? array() ) as $tab ) {
			if ( ! is_array( $tab ) ) {
				continue;
			}

			$title   = $this->clean_text( $this->str( $tab, 'tab_title' ) );
			$content = $this->html_to_text( $this->str( $tab, 'tab_content' ) );

			if ( '' === $title && '' === $content ) {
				continue;
			}

			$text = '' !== $title && '' !== $content ? $title . "\n" . $content : ( '' !== $title ? $title : $content );

			$this->blocks[] = new NormalizedBlock( NormalizedBlock::KIND_FAQ, $text );
		}
	}

	/**
	 * Referenced template post id of a global-widget / template node
	 * (settings templateID first, then the node-level key).
	 *
	 * @param array $element  Element node.
	 * @param array $settings Element settings.
	 */
	private function template_id( array $element, array $settings ): int {
		foreach ( array( $settings['templateID'] ?? null, $settings['template_id'] ?? null, $element['templateID'] ?? null ) as $candidate ) {
			if ( is_array( $candidate ) && isset( $candidate['value'] ) ) {
				$candidate = $candidate['value'];
			}
			if ( is_numeric( $candidate ) && (int) $candidate > 0 ) {
				return (int) $candidate;
			}
		}

		return 0;
	}

	/**
	 * Inline a referenced template's `_elementor_data` tree (global widgets
	 * and template nodes). Depth-capped and cycle-guarded via the visited
	 * set so self/mutually-referencing templates cannot recurse forever.
	 *
	 * @param int $template_id Template post id.
	 * @param int $depth       Element recursion depth guard.
	 */
	private function walk_template( int $template_id, int $depth ): void {
		if ( $template_id <= 0 || $this->template_depth >= self::TEMPLATE_MAX_DEPTH || isset( $this->visited_templates[ $template_id ] ) ) {
			return;
		}

		$this->visited_templates[ $template_id ] = true;
		++$this->template_depth;

		$raw = get_post_meta( $template_id, '_elementor_data', true );

		if ( is_array( $raw ) ) {
			$data = $raw;
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$data = json_decode( $raw, true );
		} else {
			$data = null;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $child ) {
				$this->walk_element( $child, $depth + 1 );
			}
		}

		--$this->template_depth;
	}

	/**
	 * shortcode widget: render via do_shortcode() and normalize the OUTPUT.
	 * Unrendered shortcodes (unregistered handlers) are dropped, never
	 * indexed raw.
	 *
	 * @param string $shortcode Raw shortcode string.
	 */
	private function extract_shortcode( string $shortcode ): void {
		$shortcode = trim( $shortcode );
		if ( '' === $shortcode || ! function_exists( 'do_shortcode' ) ) {
			return;
		}

		$rendered = do_shortcode( $shortcode );
		if ( ! is_string( $rendered ) ) {
			return;
		}

		$rendered = trim( $rendered );
		if ( '' === $rendered || $rendered === $shortcode ) {
			return; // Nothing rendered: unregistered shortcode.
		}

		$this->append_html( $rendered );
	}

	/**
	 * Heuristic sweep for unknown widgets: content-like strings only.
	 * Skips style-ish keys (leading underscore, css/class/styles) and values
	 * that are URL-only, raw shortcodes, contain '{' (JSON/CSS), or 'px;'
	 * (inline CSS).
	 *
	 * @param mixed $settings Settings subtree.
	 * @param int   $depth    Recursion depth guard.
	 */
	private function sweep_settings( mixed $settings, int $depth ): void {
		if ( $depth > self::MAX_DEPTH || ! is_array( $settings ) ) {
			return;
		}

		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && ( str_starts_with( $key, '_' ) || 'styles' === $key || false !== stripos( $key, 'css' ) || false !== stripos( $key, 'class' ) ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$this->sweep_settings( $value, $depth + 1 );
				continue;
			}

			if ( ! is_string( $value ) || mb_strlen( $value ) <= self::MIN_SWEEP_LEN ) {
				continue;
			}
			if ( preg_match( '#^\s*https?://\S+\s*$#i', $value ) ) {
				continue; // URL-only.
			}
			if ( preg_match( '/^\s*\[[a-zA-Z][^\]]*\]/', $value ) ) {
				continue; // Raw shortcode, never prose.
			}
			if ( str_contains( $value, '{' ) || str_contains( $value, 'px;' ) ) {
				continue; // CSS/JSON payloads.
			}

			if ( str_contains( $value, '<' ) ) {
				$this->append_html( $value );
			} else {
				$this->append_text( $value );
			}
		}
	}

	/**
	 * Merged widget map: `agy_kb_elementor_map` option over the defaults, so
	 * the remote registry can update it without a release.
	 *
	 * @return array<string, string>
	 */
	private function widget_map(): array {
		if ( null !== $this->map ) {
			return $this->map;
		}

		$map      = self::WIDGET_MAP;
		$override = get_option( 'agy_kb_elementor_map', array() );

		if ( is_array( $override ) ) {
			foreach ( $override as $widget => $strategy ) {
				if ( is_string( $widget ) && '' !== $widget && is_string( $strategy ) ) {
					$map[ $widget ] = $strategy;
				}
			}
		}

		$this->map = $map;

		return $map;
	}

	/**
	 * Run an HTML fragment through the Normalizer and merge the results.
	 *
	 * @param string $html HTML fragment.
	 */
	private function append_html( string $html ): void {
		if ( '' === trim( $html ) ) {
			return;
		}

		$normalized      = $this->normalizer->normalize( $html );
		$this->blocks    = array_merge( $this->blocks, $normalized['blocks'] );
		$this->links     = array_merge( $this->links, $normalized['links'] );
		$this->image_ids = array_merge( $this->image_ids, $normalized['image_ids'] );
	}

	/**
	 * Normalize an HTML fragment to plain text, keeping its links/images.
	 *
	 * @param string $html HTML fragment.
	 */
	private function html_to_text( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$normalized      = $this->normalizer->normalize( $html );
		$this->links     = array_merge( $this->links, $normalized['links'] );
		$this->image_ids = array_merge( $this->image_ids, $normalized['image_ids'] );

		return trim(
			implode(
				"\n",
				array_map( static fn ( NormalizedBlock $block ): string => $block->text, $normalized['blocks'] )
			)
		);
	}

	/**
	 * Append a plain-text block (heading when a level is given).
	 *
	 * @param string $text  Raw text.
	 * @param int    $level Heading level, 0 = paragraph.
	 */
	private function append_text( string $text, int $level = 0 ): void {
		$text = $this->clean_text( $text );
		if ( '' === $text || mb_strlen( $text ) < 2 ) {
			return;
		}
		if ( preg_match( '#^https?://\S+$#i', $text ) ) {
			return; // URL-only strings are not content.
		}

		$this->blocks[] = $level > 0
			? new NormalizedBlock( NormalizedBlock::KIND_HEADING, $text, min( 6, max( 1, $level ) ) )
			: new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, $text );
	}

	/**
	 * Harvest a legacy link setting ({url: ...}).
	 *
	 * @param mixed  $link   Link setting.
	 * @param string $anchor Anchor text.
	 */
	private function harvest_link_setting( mixed $link, string $anchor ): void {
		if ( is_string( $link ) ) {
			$this->add_link( $link, $anchor );
			return;
		}
		if ( is_array( $link ) && is_string( $link['url'] ?? null ) ) {
			$this->add_link( $link['url'], $anchor );
		}
	}

	/**
	 * Harvest a legacy image setting ({id: ..., url: ...}).
	 *
	 * @param mixed $image Image setting.
	 */
	private function harvest_image_setting( mixed $image ): void {
		if ( is_array( $image ) && is_numeric( $image['id'] ?? null ) ) {
			$this->add_image( (int) $image['id'] );
		}
	}

	/**
	 * Record one link edge.
	 *
	 * @param string $url    Target URL.
	 * @param string $anchor Anchor text.
	 */
	private function add_link( string $url, string $anchor ): void {
		$url = trim( $url );
		if ( '' === $url || str_starts_with( $url, '#' ) || str_starts_with( $url, 'javascript:' ) || str_starts_with( $url, 'mailto:' ) || str_starts_with( $url, 'tel:' ) ) {
			return;
		}
		if ( str_starts_with( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$url = untrailingslashit( home_url() ) . $url;
		}

		$this->links[] = array(
			'url'    => substr( $url, 0, 2048 ),
			'anchor' => mb_substr( $this->clean_text( $anchor ), 0, 255 ),
		);
	}

	/**
	 * Record one attachment id.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private function add_image( int $attachment_id ): void {
		if ( $attachment_id > 0 ) {
			$this->image_ids[] = $attachment_id;
		}
	}

	/**
	 * Heading level from Elementor's header_size ('h1'…'h6', 'div', 'span').
	 *
	 * @param mixed $size header_size setting.
	 */
	private function heading_level( mixed $size ): int {
		if ( is_string( $size ) && preg_match( '/([1-6])/', $size, $m ) ) {
			return (int) $m[1];
		}

		return 2;
	}

	/**
	 * Strip tags, decode entities, collapse whitespace.
	 *
	 * @param string $text Raw text.
	 */
	private function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[\x{00A0}\s]+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * String setting or '' when absent/non-string.
	 *
	 * @param array  $settings Settings.
	 * @param string $key      Key.
	 */
	private function str( array $settings, string $key ): string {
		$value = $settings[ $key ] ?? null;

		return is_string( $value ) ? $value : ( is_scalar( $value ) ? (string) $value : '' );
	}
}
