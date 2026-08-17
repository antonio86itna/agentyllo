/**
 * Block renderer: shared block schema → DOM nodes.
 *
 * Hard rule: content flows through document.createElement/textContent only —
 * innerHTML is never used for content. The single exception is price_html
 * (server-sanitized WooCommerce markup), which goes through an inert
 * <template> element and a defensive second-pass strip of script/style/
 * iframe/object/embed elements, on* attributes and javascript: URLs.
 *
 * The markdown subset (~1.5KB): **bold**, *italic*, `inline code`, "- " lists
 * and [label](url) links. Links render only when the URL is same-origin or
 * https; cross-origin links get rel="noopener nofollow", target="_blank" and
 * an aria-hidden "↗" marker. Anything else renders as plain text.
 */
import type {
	Block,
	LinkItem,
	LinksBlock,
	NoticeBlock,
	ProductItem,
	ProductsBlock,
	Thumb,
} from '../../shared/blocks';

export type RendererText = {
	in_stock: string;
	low_stock: string;
	out_of_stock: string;
	add_to_cart: string;
};

export type RenderOptions = {
	showThumbnails?: boolean;
	text?: Partial< RendererText >;
};

const TEXT_DEFAULTS: RendererText = {
	in_stock: 'In stock',
	low_stock: 'Low stock',
	out_of_stock: 'Out of stock',
	add_to_cart: 'Add to cart',
};

/* -------------------------------------------------------------------------
 * URL policy
 * ---------------------------------------------------------------------- */

/**
 * Resolve a URL and admit it only when same-origin or https.
 */
function safeUrl( url: string ): URL | null {
	try {
		const parsed = new URL( url, window.location.href );
		if ( parsed.origin === window.location.origin || 'https:' === parsed.protocol ) {
			return parsed;
		}
	} catch ( e ) {
		// Unparseable — reject.
	}
	return null;
}

/**
 * Create an anchor under the link policy, or null when the URL is refused.
 * Cross-origin targets are hardened and visually marked.
 */
function safeAnchor( url: string, className: string ): HTMLAnchorElement | null {
	const parsed = safeUrl( url );
	if ( null === parsed ) {
		return null;
	}
	const a = document.createElement( 'a' );
	a.className = className;
	a.href = parsed.href;
	if ( parsed.origin !== window.location.origin ) {
		a.rel = 'noopener nofollow';
		a.target = '_blank';
		a.setAttribute( 'data-external', '' );
	}
	return a;
}

function externalMarker(): HTMLSpanElement {
	const marker = document.createElement( 'span' );
	marker.className = 'agy-ext';
	marker.textContent = '↗';
	marker.setAttribute( 'aria-hidden', 'true' );
	return marker;
}

/* -------------------------------------------------------------------------
 * Markdown subset
 * ---------------------------------------------------------------------- */

const INLINE = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)|(\*[^*\n]+\*)|\[([^\]\n]+)\]\(([^)\s]+)\)/g;

function appendInline( parent: Node, text: string ): void {
	let last = 0;
	let match: RegExpExecArray | null;
	const pattern = new RegExp( INLINE.source, 'g' );

	while ( null !== ( match = pattern.exec( text ) ) ) {
		if ( match.index > last ) {
			parent.appendChild( document.createTextNode( text.slice( last, match.index ) ) );
		}
		if ( match[ 1 ] ) {
			const code = document.createElement( 'code' );
			code.textContent = match[ 1 ].slice( 1, -1 );
			parent.appendChild( code );
		} else if ( match[ 2 ] ) {
			const strong = document.createElement( 'strong' );
			appendInline( strong, match[ 2 ].slice( 2, -2 ) );
			parent.appendChild( strong );
		} else if ( match[ 3 ] ) {
			const em = document.createElement( 'em' );
			appendInline( em, match[ 3 ].slice( 1, -1 ) );
			parent.appendChild( em );
		} else {
			const a = safeAnchor( match[ 5 ], 'agy-link' );
			if ( null === a ) {
				// Refused URL: keep the human label, drop the link.
				parent.appendChild( document.createTextNode( match[ 4 ] ) );
			} else {
				a.textContent = match[ 4 ];
				if ( a.hasAttribute( 'data-external' ) ) {
					a.appendChild( externalMarker() );
				}
				parent.appendChild( a );
			}
		}
		last = pattern.lastIndex;
	}

	if ( last < text.length ) {
		parent.appendChild( document.createTextNode( text.slice( last ) ) );
	}
}

/**
 * Parse the markdown subset into paragraphs and flat "- " lists.
 */
export function renderMarkdown( md: string ): DocumentFragment {
	const frag = document.createDocumentFragment();
	let list: HTMLUListElement | null = null;
	let para: string[] = [];

	const flush = (): void => {
		if ( para.length > 0 ) {
			const p = document.createElement( 'p' );
			appendInline( p, para.join( ' ' ) );
			frag.appendChild( p );
			para = [];
		}
	};

	for ( const line of md.split( /\r?\n/ ) ) {
		const trimmed = line.trim();
		if ( trimmed.startsWith( '- ' ) ) {
			flush();
			if ( null === list ) {
				list = document.createElement( 'ul' );
				frag.appendChild( list );
			}
			const li = document.createElement( 'li' );
			appendInline( li, trimmed.slice( 2 ) );
			list.appendChild( li );
		} else if ( '' === trimmed ) {
			flush();
			list = null;
		} else {
			list = null;
			para.push( trimmed );
		}
	}
	flush();

	return frag;
}

/* -------------------------------------------------------------------------
 * price_html sanitizer (defense in depth — server sanitizes first)
 * ---------------------------------------------------------------------- */

function sanitizeHtmlInto( target: HTMLElement, html: string ): void {
	const tpl = document.createElement( 'template' );
	tpl.innerHTML = html; // Inert: template content never executes.

	tpl.content
		.querySelectorAll( 'script, style, iframe, object, embed, link, meta, form' )
		.forEach( ( node ) => node.remove() );

	tpl.content.querySelectorAll( '*' ).forEach( ( el ) => {
		for ( const attr of Array.from( el.attributes ) ) {
			const name = attr.name.toLowerCase();
			const isUrlAttr = 'href' === name || 'src' === name || 'xlink:href' === name;
			if ( name.startsWith( 'on' ) || ( isUrlAttr && /^\s*javascript:/i.test( attr.value ) ) ) {
				el.removeAttribute( attr.name );
			}
		}
	} );

	target.appendChild( tpl.content );
}

/* -------------------------------------------------------------------------
 * Block renderers
 * ---------------------------------------------------------------------- */

function renderThumb( thumb: Thumb ): HTMLElement {
	const media = document.createElement( 'span' );
	media.className = 'agy-media';
	const img = document.createElement( 'img' );
	img.src = thumb.src;
	if ( thumb.srcset ) {
		img.srcset = thumb.srcset;
	}
	img.alt = thumb.alt || '';
	img.width = thumb.w;
	img.height = thumb.h;
	img.loading = 'lazy';
	img.decoding = 'async';
	media.appendChild( img );
	return media;
}

function renderLinks( block: LinksBlock, opts: RenderOptions ): HTMLElement | null {
	const wrap = document.createElement( 'div' );
	wrap.className = 'agy-cards';

	for ( const item of block.items as LinkItem[] ) {
		const card = safeAnchor( item.url, 'agy-card' );
		if ( null === card ) {
			continue;
		}
		if ( false !== opts.showThumbnails && item.thumb ) {
			card.appendChild( renderThumb( item.thumb ) );
		}
		const body = document.createElement( 'span' );
		body.className = 'agy-card-body';
		const title = document.createElement( 'span' );
		title.className = 'agy-card-title';
		title.textContent = item.title;
		if ( card.hasAttribute( 'data-external' ) ) {
			title.appendChild( externalMarker() );
		}
		body.appendChild( title );
		if ( item.excerpt ) {
			const excerpt = document.createElement( 'span' );
			excerpt.className = 'agy-card-excerpt';
			excerpt.textContent = item.excerpt;
			body.appendChild( excerpt );
		}
		card.appendChild( body );
		wrap.appendChild( card );
	}

	return wrap.childElementCount > 0 ? wrap : null;
}

function renderProducts( block: ProductsBlock, opts: RenderOptions ): HTMLElement | null {
	const text: RendererText = { ...TEXT_DEFAULTS, ...( opts.text || {} ) };
	const wrap = document.createElement( 'div' );
	wrap.className = 'agy-products';

	for ( const item of block.items as ProductItem[] ) {
		const link = safeAnchor( item.url, 'agy-product-link' );
		if ( null === link ) {
			continue;
		}
		const card = document.createElement( 'article' );
		card.className = 'agy-product';

		if ( false !== opts.showThumbnails && item.thumb ) {
			link.appendChild( renderThumb( item.thumb ) );
		}
		const title = document.createElement( 'span' );
		title.className = 'agy-product-title';
		title.textContent = item.title;
		link.appendChild( title );
		card.appendChild( link );

		if ( item.price_html ) {
			const price = document.createElement( 'span' );
			price.className = 'agy-product-price';
			sanitizeHtmlInto( price, item.price_html );
			card.appendChild( price );
		}

		if ( item.stock ) {
			const stock = document.createElement( 'span' );
			stock.className = 'agy-stock agy-stock-' + item.stock;
			stock.textContent =
				'in' === item.stock ? text.in_stock : 'low' === item.stock ? text.low_stock : text.out_of_stock;
			card.appendChild( stock );
		}

		if ( item.add_to_cart_url && 'out' !== item.stock ) {
			const cart = safeAnchor( item.add_to_cart_url, 'agy-cart-link' );
			if ( null !== cart ) {
				cart.textContent = text.add_to_cart;
				card.appendChild( cart );
			}
		}

		wrap.appendChild( card );
	}

	return wrap.childElementCount > 0 ? wrap : null;
}

function renderCta( label: string, url: string ): HTMLElement | null {
	const a = safeAnchor( url, 'agy-cta' );
	if ( null === a ) {
		return null;
	}
	a.textContent = label;
	if ( a.hasAttribute( 'data-external' ) ) {
		a.appendChild( externalMarker() );
	}
	return a;
}

function renderNotice( block: NoticeBlock ): HTMLElement {
	const div = document.createElement( 'div' );
	div.className = 'agy-notice agy-notice-' + ( 'warn' === block.level ? 'warn' : 'info' );
	div.appendChild( renderMarkdown( block.md ) );
	return div;
}

/**
 * Render a block list into a fragment. Unknown block types are skipped so
 * newer servers degrade gracefully against older bundles.
 */
export function renderBlocks( blocks: Block[], opts: RenderOptions = {} ): DocumentFragment {
	const frag = document.createDocumentFragment();

	for ( const block of blocks ) {
		let node: Node | null = null;
		switch ( block.type ) {
			case 'text':
				node = renderMarkdown( block.md );
				break;
			case 'links':
				node = renderLinks( block, opts );
				break;
			case 'products':
				node = renderProducts( block, opts );
				break;
			case 'cta':
				node = renderCta( block.label, block.url );
				break;
			case 'notice':
				node = renderNotice( block );
				break;
			default:
				node = null;
		}
		if ( null !== node ) {
			frag.appendChild( node );
		}
	}

	return frag;
}

/**
 * Flatten blocks to plain text for the aria-live announcer (markdown
 * markers stripped, link labels kept).
 */
export function blocksToText( blocks: Block[] ): string {
	const parts: string[] = [];

	const strip = ( md: string ): string =>
		md
			.replace( /\[([^\]\n]+)\]\([^)\s]+\)/g, '$1' )
			.replace( /\*\*|\*|`/g, '' )
			.replace( /\s+/g, ' ' )
			.trim();

	for ( const block of blocks ) {
		switch ( block.type ) {
			case 'text':
			case 'notice':
				parts.push( strip( block.md ) );
				break;
			case 'links':
				parts.push( block.items.map( ( i ) => i.title ).join( ', ' ) );
				break;
			case 'products':
				parts.push( block.items.map( ( i ) => i.title ).join( ', ' ) );
				break;
			case 'cta':
				parts.push( block.label );
				break;
		}
	}

	return parts.filter( ( p ) => '' !== p ).join( ' ' );
}
