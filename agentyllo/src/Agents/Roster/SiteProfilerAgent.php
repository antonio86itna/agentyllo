<?php
/**
 * Site profiler: classifies what kind of site this is.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Agents\Roster;

use Agentyllo\Admin\Settings\SettingsStore;
use Agentyllo\Agents\Contracts\Agent;
use Agentyllo\Agents\Contracts\AgentContext;
use Agentyllo\Agents\Contracts\AgentResult;
use Agentyllo\Agents\Contracts\HealthReport;
use Agentyllo\Agents\Contracts\Task;

defined( 'ABSPATH' ) || exit;

/**
 * Derives a site profile (blog|business|shop|portfolio|docs|mixed) from cheap
 * local signals: post/page ratios, Woo product counts, Elementor presence,
 * booking/contact plugin fingerprints, and posting cadence. The result is
 * stored at memory key ('site_profiler', 'profile') — AgentContext::site_profile()
 * and Orchestrator::site_profile() read exactly that key — so every consumer
 * shares one snapshot instead of recomputing. When the owner set an explicit
 * site_type_hint in settings, it overrides the heuristic type.
 */
final class SiteProfilerAgent implements Agent {

	public const ID = 'site_profiler';

	/**
	 * Known profile types (besides the 'mixed' fallback).
	 */
	private const TYPES = array( 'blog', 'business', 'shop', 'portfolio', 'docs' );

	/**
	 * Portfolio-ish public CPT names.
	 */
	private const PORTFOLIO_CPTS = array( 'portfolio', 'jetpack-portfolio', 'project', 'projects' );

	/**
	 * Docs-ish public CPT names.
	 */
	private const DOCS_CPTS = array( 'docs', 'doc', 'documentation', 'knowledgebase', 'knowledge_base', 'wiki', 'manual', 'epkb_post_type_1' );

	/**
	 * Contact-form plugin fingerprints (class names).
	 */
	private const CONTACT_CLASSES = array( 'WPCF7', 'GFForms', 'WPForms\WPForms', 'Ninja_Forms', 'FrmAppHelper' );

	/**
	 * Booking plugin fingerprints (class names).
	 */
	private const BOOKING_CLASSES = array( 'WC_Bookings', 'AmeliaBooking\Plugin', 'Bookly\Lib\Plugin' );

	/**
	 * Constructor.
	 *
	 * @param SettingsStore $settings Settings store (site_type_hint override).
	 */
	public function __construct( private readonly SettingsStore $settings ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array( 'profile' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function subscribed_events(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle( Task $task, AgentContext $context ): AgentResult {
		if ( 'profile.get' === $task->type ) {
			return AgentResult::ok( array( 'profile' => $context->memory()->recall( self::ID, 'profile' ) ?? array() ) );
		}

		if ( 'profile.site' !== $task->type ) {
			return AgentResult::refused( 'unsupported task type' );
		}

		$woo      = class_exists( 'WooCommerce' );
		$posts    = $this->published_count( 'post' );
		$pages    = $this->published_count( 'page' );
		$products = $woo ? $this->published_count( 'product' ) : 0;
		$cadence  = $this->posting_cadence();

		$elementor = defined( 'ELEMENTOR_VERSION' );
		$contact   = $this->any_class_exists( self::CONTACT_CLASSES ) || function_exists( 'wpforms' ) || defined( 'FLUENTFORM' );
		$booking   = $this->any_class_exists( self::BOOKING_CLASSES ) || function_exists( 'MPHB' );

		$cpts = array_values(
			(array) get_post_types(
				array(
					'public'   => true,
					'_builtin' => false,
				),
				'names'
			)
		);

		$portfolio_cpt = $this->first_match( $cpts, self::PORTFOLIO_CPTS );
		$docs_cpt      = $this->first_match( $cpts, self::DOCS_CPTS );

		[ $type, $confidence, $scores ] = $this->classify(
			array(
				'posts'         => $posts,
				'pages'         => $pages,
				'products'      => $products,
				'woo'           => $woo,
				'cadence'       => $cadence,
				'elementor'     => $elementor,
				'contact'       => $contact,
				'booking'       => $booking,
				'portfolio_cpt' => $portfolio_cpt,
				'docs_cpt'      => $docs_cpt,
			)
		);

		$signals = array(
			'posts:' . $posts,
			'pages:' . $pages,
			'cadence:' . $cadence,
		);
		if ( $products > 0 ) {
			$signals[] = 'products:' . $products;
		}
		if ( $woo ) {
			$signals[] = 'woocommerce';
		}
		if ( $elementor ) {
			$signals[] = 'elementor';
		}
		if ( $contact ) {
			$signals[] = 'contact_form';
		}
		if ( $booking ) {
			$signals[] = 'booking';
		}
		if ( '' !== $portfolio_cpt ) {
			$signals[] = 'portfolio_cpt:' . $portfolio_cpt;
		}
		if ( '' !== $docs_cpt ) {
			$signals[] = 'docs_cpt:' . $docs_cpt;
		}

		// The owner's explicit hint beats the heuristic.
		$hint = (string) $this->settings->value( 'general', 'site_type_hint' );
		if ( '' !== $hint && 'auto' !== $hint && in_array( $hint, self::TYPES, true ) ) {
			$type       = $hint;
			$confidence = max( $confidence, 0.9 );
			$signals[]  = 'owner_hint:' . $hint;
		}

		$profile = array(
			'type'        => $type,
			'confidence'  => $confidence,
			'locale'      => (string) get_locale(),
			'counts'      => array(
				'posts'    => $posts,
				'pages'    => $pages,
				'products' => $products,
			),
			'cadence'     => $cadence,
			'stack'       => array(
				'woocommerce'  => $woo,
				'elementor'    => $elementor,
				'contact_form' => $contact,
				'booking'      => $booking,
			),
			'scores'      => array_map( static fn ( float $score ): float => round( $score, 2 ), $scores ),
			'signals'     => $signals,
			'profiled_at' => gmdate( 'c' ),
		);

		$context->memory()->remember( self::ID, 'profile', $profile, 'fact', 90 );
		$context->bus()->emit( 'site.profiled', $profile );

		return AgentResult::ok( $profile, $confidence );
	}

	/**
	 * Score every profile type and pick a winner. A weak or contested top
	 * score degrades honestly to 'mixed' instead of guessing.
	 *
	 * @param array $facts Collected signals (counts, cadence, stack flags).
	 * @return array{0: string, 1: float, 2: array<string, float>} Type, confidence, scores.
	 */
	private function classify( array $facts ): array {
		$scores = array_fill_keys( self::TYPES, 0.0 );

		if ( $facts['products'] > 0 ) {
			$scores['shop'] += 2.0 + min( 2.0, $facts['products'] / 25 );
		} elseif ( $facts['woo'] ) {
			$scores['shop'] += 0.5;
		}

		if ( $facts['posts'] > $facts['pages'] ) {
			$scores['blog'] += 1.0;
		}
		if ( $facts['posts'] >= 10 ) {
			$scores['blog'] += 0.5;
		}
		if ( 'active' === $facts['cadence'] ) {
			$scores['blog'] += 1.5;
		} elseif ( 'occasional' === $facts['cadence'] ) {
			$scores['blog'] += 0.75;
		}

		if ( $facts['pages'] >= $facts['posts'] ) {
			$scores['business'] += 1.0;
		}
		if ( $facts['pages'] >= 4 ) {
			$scores['business'] += 0.25;
		}
		if ( $facts['contact'] ) {
			$scores['business'] += 0.75;
		}
		if ( $facts['booking'] ) {
			$scores['business'] += 0.75;
		}
		if ( in_array( $facts['cadence'], array( 'dormant', 'none' ), true ) ) {
			$scores['business'] += 0.5;
		}

		if ( '' !== $facts['portfolio_cpt'] ) {
			$scores['portfolio'] += 2.0;
			if ( $facts['elementor'] ) {
				$scores['portfolio'] += 0.25;
			}
		}

		if ( '' !== $facts['docs_cpt'] ) {
			$scores['docs'] += 2.0;
			if ( $facts['pages'] > 3 * max( 1, $facts['posts'] ) ) {
				$scores['docs'] += 0.5;
			}
		}

		arsort( $scores );
		$ranked = array_keys( $scores );
		$top    = $scores[ $ranked[0] ];
		$second = $scores[ $ranked[1] ];

		if ( $top <= 0.0 ) {
			return array( 'mixed', 0.35, $scores );
		}
		if ( $second > 0.0 && ( $top - $second ) < 0.5 ) {
			return array( 'mixed', 0.45, $scores );
		}

		$confidence = round( min( 0.95, max( 0.5, $top / ( $top + $second + 0.75 ) ) ), 2 );

		return array( $ranked[0], $confidence, $scores );
	}

	/**
	 * Published item count for a post type.
	 *
	 * @param string $post_type Post type name.
	 */
	private function published_count( string $post_type ): int {
		$counts = wp_count_posts( $post_type );

		return is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Posting cadence from the latest 10 published posts' date spread:
	 * active | occasional | dormant | none.
	 */
	private function posting_cadence(): string {
		$recent = get_posts(
			array(
				'numberposts' => 10,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		if ( empty( $recent ) ) {
			return 'none';
		}

		$newest   = (int) strtotime( (string) $recent[0]->post_date_gmt );
		$age_days = ( time() - $newest ) / DAY_IN_SECONDS;

		if ( count( $recent ) < 2 ) {
			return $age_days <= 60 ? 'occasional' : 'dormant';
		}

		$oldest    = (int) strtotime( (string) end( $recent )->post_date_gmt );
		$span_days = max( 1.0, ( $newest - $oldest ) / DAY_IN_SECONDS );
		$per_month = ( count( $recent ) - 1 ) * 30 / $span_days;

		if ( $age_days <= 60 && $per_month >= 2 ) {
			return 'active';
		}

		return $age_days <= 180 ? 'occasional' : 'dormant';
	}

	/**
	 * Whether any of the given classes is loaded.
	 *
	 * @param string[] $classes Class names.
	 */
	private function any_class_exists( array $classes ): bool {
		foreach ( $classes as $class ) {
			if ( class_exists( $class ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * First needle present in the haystack, '' when none.
	 *
	 * @param string[] $haystack Registered CPT names.
	 * @param string[] $needles  Known CPT names to look for.
	 */
	private function first_match( array $haystack, array $needles ): string {
		foreach ( $needles as $needle ) {
			if ( in_array( $needle, $haystack, true ) ) {
				return $needle;
			}
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function self_check( AgentContext $context ): HealthReport {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts ) );

		return ( new HealthReport() )
			->add( 'posts_table_readable', ! empty( $posts_table ), '', true )
			->add(
				'profile_memory_exists',
				null !== $context->memory()->recall( self::ID, 'profile' ),
				'run a profile.site task to build the site profile',
				false
			);
	}
}
