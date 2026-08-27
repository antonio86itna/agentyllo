<?php
/**
 * Source adapter for site identity facts.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Source;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A single document (external_id 'site') with the site's self-description:
 * name, tagline, URL, locale/timezone, front and privacy pages as link
 * edges, WooCommerce store address + currency when available, and
 * best-effort business identity from Yoast / Rank Math knowledge-graph
 * options. Grounds "who are you / where are you / what currency" answers.
 *
 * Deliberately NO admin emails or any user PII.
 *
 * Single-universe adapter: subtype ''. Toggled by `site_identity_enabled`.
 */
final class SiteIdentityAdapter implements SourceAdapter {

	public const EXTERNAL_ID = 'site';

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'site';
	}

	/**
	 * Always available.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Single universe.
	 *
	 * @return array<string, string>
	 */
	public function subtypes(): array {
		return array( '' => __( 'Site identity', 'agentyllo' ) );
	}

	/**
	 * Exactly one document.
	 *
	 * @param string $subtype Unused ('' = all).
	 */
	public function count_items( string $subtype = '' ): int {
		unset( $subtype );

		return 1;
	}

	/**
	 * The single-id cursor.
	 *
	 * @param string $subtype Unused ('' = all).
	 * @param int    $offset  Cursor offset.
	 * @param int    $limit   Batch size.
	 * @return string[]
	 */
	public function enumerate_ids( string $subtype, int $offset, int $limit ): array {
		unset( $subtype );

		return ( 0 === $offset && $limit > 0 ) ? array( self::EXTERNAL_ID ) : array();
	}

	/**
	 * Cheap probe: hash over the exact payload extraction would use (all
	 * plain option reads, no queries).
	 *
	 * @param string $external_id External id ('site').
	 */
	public function fingerprint( string $external_id ): ?string {
		if ( self::EXTERNAL_ID !== $external_id ) {
			return null;
		}

		return sha1( (string) wp_json_encode( $this->payload() ) );
	}

	/**
	 * Full extraction.
	 *
	 * @param string $external_id External id ('site').
	 */
	public function extract( string $external_id ): ?DocumentDraft {
		if ( self::EXTERNAL_ID !== $external_id ) {
			return null;
		}

		$payload  = $this->payload();
		$identity = $payload['identity'];
		$name     = (string) $identity['name'];
		$tagline  = (string) $identity['tagline'];

		$blocks   = array();
		$blocks[] = new NormalizedBlock( NormalizedBlock::KIND_HEADING, '' !== $name ? $name : (string) $identity['url'], 1 );
		if ( '' !== $tagline ) {
			$blocks[] = new NormalizedBlock( NormalizedBlock::KIND_PARAGRAPH, $tagline );
		}

		$links = array_values( $payload['pages'] );

		// The logo id is a fingerprint ingredient, not a searchable fact.
		unset( $identity['logo'] );

		$structured = array_filter(
			array(
				'site'     => array_filter( $identity, static fn ( $value ): bool => '' !== (string) $value ),
				'store'    => $payload['store'],
				'business' => $payload['business'],
			)
		);

		$logo_id = (int) get_theme_mod( 'custom_logo' );

		/** This filter is documented in src/KB/Source/PostTypeAdapter.php */
		$lang = (string) apply_filters( 'agyl_kb_document_lang', determine_locale(), null );

		return new DocumentDraft(
			$this->id(),
			self::EXTERNAL_ID,
			'',
			'' !== $name ? $name : __( 'Site identity', 'agentyllo' ),
			home_url( '/' ),
			$lang,
			$blocks,
			$structured,
			$links,
			$logo_id > 0 ? $logo_id : null,
			80,
			null
		);
	}

	/**
	 * Change signals for the content watcher: identity-relevant option
	 * updates map to an upsert of the single document.
	 *
	 * @return array<string, array{args: int, map: callable}>
	 */
	public function delta_hooks(): array {
		return array(
			'updated_option' => array(
				'args' => 3,
				'map'  => static function ( $option, $old_value = null, $value = null ): ?array {
					unset( $old_value, $value );
					$option  = (string) $option;
					$watched = in_array( $option, array( 'blogname', 'blogdescription', 'woocommerce_default_country' ), true )
						|| str_starts_with( $option, 'woocommerce_store_' );

					if ( ! $watched ) {
						return null;
					}

					return array(
						'external_id' => self::EXTERNAL_ID,
						'action'      => 'upsert',
					);
				},
			),
		);
	}

	/**
	 * Everything extraction depends on, in one deterministic array (shared
	 * by fingerprint() so probes and extraction can never disagree).
	 *
	 * @return array{identity: array<string, string>, pages: array<string, array{url: string, anchor: string}>, store: array<string, string>, business: array<string, string>}
	 */
	private function payload(): array {
		$identity = array(
			'name'     => (string) get_option( 'blogname', '' ),
			'tagline'  => (string) get_option( 'blogdescription', '' ),
			'url'      => home_url( '/' ),
			'locale'   => determine_locale(),
			'timezone' => wp_timezone_string(),
			'logo'     => (string) (int) get_theme_mod( 'custom_logo' ),
		);

		$pages = array();
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$front = $this->page_link( (int) get_option( 'page_on_front' ) );
			if ( $front ) {
				$pages['front'] = $front;
			}
		}
		$privacy = $this->page_link( (int) get_option( 'wp_page_for_privacy_policy' ) );
		if ( $privacy ) {
			$pages['privacy'] = $privacy;
		}

		return array(
			'identity' => $identity,
			'pages'    => $pages,
			'store'    => $this->store_facts(),
			'business' => $this->business_facts(),
		);
	}

	/**
	 * Link descriptor for a published page, null otherwise.
	 *
	 * @param int $page_id Page ID.
	 * @return array{url: string, anchor: string}|null
	 */
	private function page_link( int $page_id ): ?array {
		if ( $page_id <= 0 ) {
			return null;
		}
		$page = get_post( $page_id );
		if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
			return null;
		}

		return array(
			'url'    => (string) get_permalink( $page ),
			'anchor' => get_the_title( $page ),
		);
	}

	/**
	 * WooCommerce store address + currency (empty when Woo inactive).
	 *
	 * @return array<string, string>
	 */
	private function store_facts(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		$store = array();

		$address = array_filter(
			array(
				(string) get_option( 'woocommerce_store_address', '' ),
				(string) get_option( 'woocommerce_store_address_2', '' ),
				(string) get_option( 'woocommerce_store_city', '' ),
				(string) get_option( 'woocommerce_store_postcode', '' ),
			)
		);
		if ( $address ) {
			$store['address'] = implode( ', ', $address );
		}

		$country = (string) get_option( 'woocommerce_default_country', '' );
		if ( '' !== $country ) {
			[ $code, $state ] = array_pad( explode( ':', $country, 2 ), 2, '' );
			if ( '' !== $code ) {
				$store['country'] = $code;
			}
			if ( '' !== $state ) {
				$store['state'] = $state;
			}
		}

		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = (string) get_woocommerce_currency();
			if ( '' !== $currency ) {
				$store['currency'] = $currency;
			}
		}

		return $store;
	}

	/**
	 * Best-effort business identity from SEO plugins' knowledge-graph
	 * settings (Yoast first, Rank Math fills gaps). Defensive: options may
	 * be absent or malformed.
	 *
	 * @return array<string, string>
	 */
	private function business_facts(): array {
		$business = array();

		$yoast = get_option( 'wpseo_titles' );
		if ( is_array( $yoast ) ) {
			foreach ( array(
				'company_or_person' => 'type',
				'company_name'      => 'name',
			) as $key => $label ) {
				if ( isset( $yoast[ $key ] ) && is_scalar( $yoast[ $key ] ) && '' !== (string) $yoast[ $key ] ) {
					$business[ $label ] = (string) $yoast[ $key ];
				}
			}
		}

		$rank_math = get_option( 'rank-math-options-titles' );
		if ( is_array( $rank_math ) ) {
			foreach ( array(
				'knowledgegraph_type' => 'type',
				'knowledgegraph_name' => 'name',
			) as $key => $label ) {
				if ( empty( $business[ $label ] ) && isset( $rank_math[ $key ] ) && is_scalar( $rank_math[ $key ] ) && '' !== (string) $rank_math[ $key ] ) {
					$business[ $label ] = (string) $rank_math[ $key ];
				}
			}
		}

		return $business;
	}
}
