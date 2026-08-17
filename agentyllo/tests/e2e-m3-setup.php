<?php
/**
 * Dev-only M3 smoke setup for `wp eval-file` (not shipped).
 * Creates sample content and starts the first full crawl.
 *
 * @package Agentyllo
 */

$c = \Agentyllo\Plugin::instance()->container();
$c->get( \Agentyllo\Install\Migrator::class )->maybe_upgrade();

$pages = array(
	'Shipping & Returns' => "<h2>Shipping times</h2><p>Orders ship within 24 hours. Delivery to Italy takes 2-3 working days, Europe 4-6 days.</p><h2>Returns</h2><p>You can return any item within 30 days. Refunds are processed in 5 working days.</p><ul><li>Free returns over €50</li><li>Return label included</li></ul>",
	'About us'           => "<p>Agentyllo Demo Store has been selling handmade leather bags since 2015 from our workshop in Florence.</p><h2>Our workshop</h2><p>Every bag is cut and stitched by hand using vegetable-tanned Tuscan leather.</p>",
	'Contact'            => "<p>Email: info@example.test — Phone: +39 055 123456.</p><p>Opening hours: Monday to Friday, 9:00–18:00.</p><p>See our <a href=\"/shipping-returns/\">shipping policy</a>.</p>",
);

$created = array();
foreach ( $pages as $title => $content ) {
	$existing = get_page_by_path( sanitize_title( $title ), OBJECT, 'page' );
	if ( $existing ) {
		$created[ $title ] = (int) $existing->ID;
		continue;
	}
	$created[ $title ] = (int) wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		)
	);
}

$post_id = (int) wp_insert_post(
	array(
		'post_title'   => 'New autumn collection is here',
		'post_content' => '<p>Our new autumn collection features five new crossbody bags in walnut and forest green.</p>',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);

$c->get( \Agentyllo\KB\Indexer\IndexManager::class )->start_full_crawl();

echo wp_json_encode( array( 'pages' => $created, 'post' => $post_id, 'crawl' => 'started' ) ) . "\n";
