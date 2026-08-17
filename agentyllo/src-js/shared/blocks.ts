/**
 * Canonical message/block schema shared by the frontend widget (vanilla
 * renderer) and the admin copilot (React renderer). The server builds these
 * in Chat\Pipeline stages; models never emit HTML or URLs — placeholders are
 * resolved server-side into typed blocks, so hallucinated links are
 * structurally impossible.
 */

export type Thumb = {
	id: number;
	src: string;
	srcset?: string;
	alt: string;
	w: number;
	h: number;
};

export type TextBlock = {
	type: 'text';
	/** Safe-markdown subset: bold, italic, lists, inline code. No raw HTML. */
	md: string;
};

export type LinkItem = {
	title: string;
	url: string;
	thumb?: Thumb | null;
	excerpt?: string;
};

export type LinksBlock = {
	type: 'links';
	items: LinkItem[];
};

export type ProductItem = {
	id: number;
	title: string;
	price_html?: string;
	stock?: 'in' | 'low' | 'out' | '';
	thumb?: Thumb | null;
	url: string;
	add_to_cart_url?: string;
};

export type ProductsBlock = {
	type: 'products';
	items: ProductItem[];
};

export type CtaBlock = {
	type: 'cta';
	label: string;
	url: string;
};

export type NoticeBlock = {
	type: 'notice';
	level: 'info' | 'warn';
	md: string;
};

export type Block = TextBlock | LinksBlock | ProductsBlock | CtaBlock | NoticeBlock;

export type StatusState =
	| 'queued'
	| 'understanding'
	| 'searching'
	| 'checking_products'
	| 'linking'
	| 'verifying'
	| 'generating'
	| 'formatting'
	| 'done'
	| 'refused'
	| 'error';

export type StatusEvent = {
	state: StatusState;
	/** ms offset from request start (buffered transports replay these synthetically). */
	ts: number;
};

export type AssistantMessage = {
	id: string;
	role: 'assistant' | 'user';
	blocks: Block[];
	meta?: {
		events?: StatusEvent[];
		disclaimer?: string;
		ai_generated?: boolean;
	};
};

export const isTextBlock = ( b: Block ): b is TextBlock => 'text' === b.type;
export const isLinksBlock = ( b: Block ): b is LinksBlock => 'links' === b.type;
export const isProductsBlock = ( b: Block ): b is ProductsBlock => 'products' === b.type;
export const isCtaBlock = ( b: Block ): b is CtaBlock => 'cta' === b.type;
export const isNoticeBlock = ( b: Block ): b is NoticeBlock => 'notice' === b.type;
