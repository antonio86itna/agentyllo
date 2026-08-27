<?php
/**
 * Data-subject access requests: export + erase by email.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Compliance;

use Agentyllo\Infra\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Two paths: (a) plugin-side tools (Privacy page) and (b) WordPress core
 * personal-data exporter/eraser hooks (rides core's email-verification flow).
 * Erase = redact message content to "[erased]", null identity + ip hashes,
 * keep anonymous counters + a consent tombstone (hash only).
 */
final class Dsar {

	/**
	 * Register with WP core privacy tools.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Summary of what we hold for an email.
	 *
	 * @param string $email Email.
	 * @return array{conversations: int, messages: int, consents: int, first_seen: ?string, last_seen: ?string}
	 */
	public function summary( string $email ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conv = $wpdb->get_row(
			$wpdb->prepare( "SELECT COUNT(*) n, MIN(started_at) first_seen, MAX(last_activity_at) last_seen FROM {$p}agyl_conversations WHERE visitor_email = %s", $email ),
			ARRAY_A
		);
		$msgs = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$p}agyl_messages m INNER JOIN {$p}agyl_conversations c ON c.id = m.conversation_id WHERE c.visitor_email = %s", $email )
		);
		$cons = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}agyl_consents WHERE email = %s", $email ) );
		// phpcs:enable

		return array(
			'conversations' => (int) ( $conv['n'] ?? 0 ),
			'messages'      => $msgs,
			'consents'      => $cons,
			'first_seen'    => $conv['first_seen'] ?? null,
			'last_seen'     => $conv['last_seen'] ?? null,
		);
	}

	/**
	 * Full export payload for an email.
	 *
	 * @param string $email Email.
	 */
	public function export( string $email ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conversations = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, uuid, visitor_name, visitor_email, lang, tier, started_at, last_activity_at, message_count FROM {$p}agyl_conversations WHERE visitor_email = %s ORDER BY id", $email ),
			ARRAY_A
		);
		foreach ( $conversations as &$c ) {
			$c['messages'] = $wpdb->get_results(
				$wpdb->prepare( "SELECT role, content, intent, created_at FROM {$p}agyl_messages WHERE conversation_id = %d ORDER BY id", (int) $c['id'] ),
				ARRAY_A
			);
			unset( $c['id'] );
		}
		unset( $c );
		$consents = $wpdb->get_results(
			$wpdb->prepare( "SELECT consent_type, text_version, granted, created_at FROM {$p}agyl_consents WHERE email = %s ORDER BY id", $email ),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'subject'       => $email,
			'generated_at'  => gmdate( 'c' ),
			'site'          => home_url( '/' ),
			'conversations' => $conversations,
			'consents'      => $consents,
		);
	}

	/**
	 * Write an export to the protected uploads dir. Returns the absolute path.
	 *
	 * @param string $email Email.
	 */
	public function export_to_file( string $email ): ?string {
		Uploads::ensure();
		$path = Uploads::dir( 'private' ) . '/dsar-' . wp_generate_password( 24, false, false ) . '.json';
		$json = wp_json_encode( $this->export( $email ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( false === $json || false === file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}
		Audit::log( 'privacy.export', $email );

		return $path;
	}

	/**
	 * Erase (redact) everything tied to an email. Returns counters.
	 *
	 * @param string $email Email.
	 * @return array{conversations: int, messages: int, consents: int}
	 */
	public function erase( string $email ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}agyl_conversations WHERE visitor_email = %s", $email ) );

		$messages = 0;
		if ( $ids ) {
			$in       = implode( ',', array_map( 'absint', $ids ) );
			$messages = (int) $wpdb->query( "UPDATE {$p}agyl_messages SET content = '[erased]', blocks = NULL, kb_sources = NULL WHERE conversation_id IN ({$in})" );
			$wpdb->query( "UPDATE {$p}agyl_conversations SET visitor_name = NULL, visitor_email = NULL, ip_hash = NULL, meta = NULL WHERE id IN ({$in})" );
		}
		// Consent tombstone: keep type/version/hash (proof consent existed), drop identity.
		$consents = (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$p}agyl_consents SET email = NULL, visitor_name = NULL, ip_hash = NULL, ua_hash = NULL WHERE email = %s", $email )
		);
		// phpcs:enable

		Audit::log( 'privacy.erase', $email, array(), 'ok', sprintf( '%d conversations, %d messages', count( $ids ), $messages ) );

		return array(
			'conversations' => count( $ids ),
			'messages'      => $messages,
			'consents'      => $consents,
		);
	}

	/**
	 * WP core exporter registration.
	 *
	 * @param array $exporters Registered exporters.
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['agentyllo'] = array(
			'exporter_friendly_name' => __( 'Agentyllo chat', 'agentyllo' ),
			'callback'               => array( $this, 'core_export' ),
		);

		return $exporters;
	}

	/**
	 * WP core eraser registration.
	 *
	 * @param array $erasers Registered erasers.
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['agentyllo'] = array(
			'eraser_friendly_name' => __( 'Agentyllo chat', 'agentyllo' ),
			'callback'             => array( $this, 'core_erase' ),
		);

		return $erasers;
	}

	/**
	 * Core exporter callback (single page — volumes are small per subject).
	 *
	 * @param string $email Email.
	 */
	public function core_export( string $email ): array {
		$data  = $this->export( $email );
		$items = array();

		foreach ( $data['conversations'] as $conv ) {
			$transcript = array();
			foreach ( (array) $conv['messages'] as $m ) {
				$transcript[] = sprintf( '[%s] %s: %s', $m['created_at'], $m['role'], $m['content'] );
			}
			$items[] = array(
				'group_id'    => 'agentyllo_conversations',
				'group_label' => __( 'Agentyllo conversations', 'agentyllo' ),
				'item_id'     => 'agentyllo-conversation-' . $conv['uuid'],
				'data'        => array(
					array( 'name' => __( 'Started', 'agentyllo' ), 'value' => $conv['started_at'] ),
					array( 'name' => __( 'Language', 'agentyllo' ), 'value' => $conv['lang'] ),
					array( 'name' => __( 'Transcript', 'agentyllo' ), 'value' => implode( "\n", $transcript ) ),
				),
			);
		}

		return array( 'data' => $items, 'done' => true );
	}

	/**
	 * Core eraser callback.
	 *
	 * @param string $email Email.
	 */
	public function core_erase( string $email ): array {
		$r = $this->erase( $email );

		return array(
			'items_removed'  => $r['conversations'] + $r['consents'],
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
