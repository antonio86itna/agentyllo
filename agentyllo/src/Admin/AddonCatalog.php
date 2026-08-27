<?php
/**
 * Addon catalog: the list of optional Agentyllo extensions.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Bundled catalog of optional addons, each distributed as a SEPARATE plugin
 * from agentyllo.com — never as locked code inside this plugin. The free
 * plugin is complete on its own; addons only ever ADD capabilities.
 *
 * Installed addons flip their own entry to installed/active through the
 * `agyl_addons` filter (each addon knows its plugin basename); the same
 * filter lets future addons append entries the catalog does not know yet.
 */
final class AddonCatalog {

	/**
	 * Catalog entries.
	 *
	 * @return array<int, array{id: string, name: string, tagline: string, features: string[], status: string, url: string}>
	 */
	public function all(): array {
		$soon = 'coming_soon';

		$catalog = array(
			array(
				'id'       => 'local-ai',
				'name'     => __( 'Agentyllo Local AI', 'agentyllo' ),
				'tagline'  => __( 'One-click free AI on your own server.', 'agentyllo' ),
				'features' => array(
					__( 'Installs verified llama.cpp engines and open-license models', 'agentyllo' ),
					__( 'Supervised local daemon with idle shutdown', 'agentyllo' ),
					__( 'Checksum-verified downloads with explicit consent', 'agentyllo' ),
				),
				'status'   => 'available',
				'url'      => 'https://www.agentyllo.com/downloads/agentyllo-local-ai-0.1.1.zip',
			),
			array(
				'id'       => 'document-import',
				'name'     => __( 'Agentyllo Document Import', 'agentyllo' ),
				'tagline'  => __( 'Feed the knowledge base with PDFs and spreadsheets.', 'agentyllo' ),
				'features' => array(
					__( 'PDF ingestion with a reviewable preview', 'agentyllo' ),
					__( 'XLSX, ODS and large CSV imports', 'agentyllo' ),
					__( 'Scheduled re-imports from a folder', 'agentyllo' ),
				),
				'status'   => $soon,
				'url'      => '',
			),
			array(
				'id'       => 'advanced-analytics',
				'name'     => __( 'Agentyllo Advanced Analytics', 'agentyllo' ),
				'tagline'  => __( 'Deep insight into what visitors ask.', 'agentyllo' ),
				'features' => array(
					__( 'Unlimited history and custom date ranges', 'agentyllo' ),
					__( 'CSV export and scheduled email reports', 'agentyllo' ),
					__( 'Topic clustering of unanswered questions', 'agentyllo' ),
				),
				'status'   => $soon,
				'url'      => '',
			),
			array(
				'id'       => 'white-label',
				'name'     => __( 'Agentyllo White Label', 'agentyllo' ),
				'tagline'  => __( 'Make the assistant fully yours.', 'agentyllo' ),
				'features' => array(
					__( 'Custom launcher, fonts and full theme control', 'agentyllo' ),
					__( 'Your brand everywhere — no Agentyllo mentions', 'agentyllo' ),
					__( 'Per-page widget appearance rules', 'agentyllo' ),
				),
				'status'   => $soon,
				'url'      => '',
			),
			array(
				'id'       => 'leads-crm',
				'name'     => __( 'Agentyllo Leads & CRM', 'agentyllo' ),
				'tagline'  => __( 'Turn conversations into contacts.', 'agentyllo' ),
				'features' => array(
					__( 'Smart lead capture inside the conversation', 'agentyllo' ),
					__( 'CSV and webhook export, CRM integrations', 'agentyllo' ),
					__( 'Consent-aware, GDPR-friendly by design', 'agentyllo' ),
				),
				'status'   => $soon,
				'url'      => '',
			),
			array(
				'id'       => 'forms',
				'name'     => __( 'Agentyllo Forms in Chat', 'agentyllo' ),
				'tagline'  => __( 'Bookings and requests without leaving the chat.', 'agentyllo' ),
				'features' => array(
					__( 'Conversational forms with validation', 'agentyllo' ),
					__( 'Appointment and callback requests', 'agentyllo' ),
					__( 'Email notifications and export', 'agentyllo' ),
				),
				'status'   => $soon,
				'url'      => '',
			),
			array(
				'id'       => 'product-advisor',
				'name'     => __( 'Agentyllo Product Advisor', 'agentyllo' ),
				'tagline'  => __( 'Guided selling for WooCommerce.', 'agentyllo' ),
				'features' => array(
					__( 'Personalized product recommendations', 'agentyllo' ),
					__( 'Side-by-side comparisons in chat', 'agentyllo' ),
					__( 'Cross-sell prompts backed by live stock', 'agentyllo' ),
				),
				'status'   => $soon,
				'url'      => '',
			),
			array(
				'id'       => 'handoff-live',
				'name'     => __( 'Agentyllo Handoff Live', 'agentyllo' ),
				'tagline'  => __( 'A human takes over in one click.', 'agentyllo' ),
				'features' => array(
					__( 'Live agent inbox inside wp-admin', 'agentyllo' ),
					__( 'Seamless takeover with full transcript', 'agentyllo' ),
					__( 'Office-hours routing and email fallback', 'agentyllo' ),
				),
				'status'   => $soon,
				'url'      => '',
			),
			array(
				'id'       => 'omnichannel',
				'name'     => __( 'Agentyllo Omnichannel', 'agentyllo' ),
				'tagline'  => __( 'The same assistant on every channel.', 'agentyllo' ),
				'features' => array(
					__( 'WhatsApp, Messenger and Telegram', 'agentyllo' ),
					__( 'One knowledge base, one inbox', 'agentyllo' ),
					__( 'Channel-aware formatting', 'agentyllo' ),
				),
				'status'   => $soon,
				'url'      => '',
			),
			array(
				'id'       => 'voice',
				'name'     => __( 'Agentyllo Voice', 'agentyllo' ),
				'tagline'  => __( 'Talk to your site.', 'agentyllo' ),
				'features' => array(
					__( 'Voice input in the widget', 'agentyllo' ),
					__( 'Natural speech replies', 'agentyllo' ),
					__( 'Accessibility-first design', 'agentyllo' ),
				),
				'status'   => $soon,
				'url'      => '',
			),
		);

		/**
		 * Filter the addon catalog. Installed addons use this to mark their
		 * entry as installed/active or to append entries of their own.
		 *
		 * @param array $catalog Catalog entries.
		 */
		$catalog = (array) apply_filters( 'agyl_addons', $catalog );

		return array_values(
			array_filter(
				$catalog,
				static fn ( $entry ): bool => is_array( $entry ) && ! empty( $entry['id'] ) && ! empty( $entry['name'] )
			)
		);
	}
}
