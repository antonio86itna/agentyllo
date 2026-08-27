<?php
/**
 * Core copilot actions: KB CRUD, settings (whitelisted), memory, stats.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Copilot;

use Agentyllo\Admin\Settings\SettingsSchema;
use Agentyllo\Admin\Settings\SettingsStore;
use Agentyllo\Agents\Kernel\MemoryStore;
use Agentyllo\KB\Indexer\IndexManager;
use Agentyllo\KB\ManualEntries;
use Agentyllo\Stats\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the built-in actions. Settings access is a strict whitelist of
 * non-secret keys (never API keys, never uninstall mode); memory.teach writes
 * owner facts the composer agent can recall; kb.delete is a soft delete with
 * a 30-day trash. Destructive actions demand the signed confirmation token.
 */
final class CoreActions {

	private const SETTINGS_WHITELIST = array(
		'general'     => array( 'assistant_name', 'tone', 'custom_instructions', 'out_of_scope_guard', 'oos_refusal_message', 'operating_mode' ),
		'widget'      => array( 'widget_enabled', 'position', 'theme', 'primary_color', 'welcome_message', 'launcher_teaser', 'show_thumbnails', 'show_internal_links', 'animations' ),
		'language'    => array( 'reply_language_mode', 'fixed_locale' ),
		'privacy'     => array( 'registration_gate', 'retention_days', 'pii_redaction' ),
		'performance' => array( 'transport', 'rate_limit_session_per_min' ),
	);

	/**
	 * Constructor.
	 *
	 * @param ManualEntries  $entries  Manual KB entries.
	 * @param IndexManager   $indexer  Index manager (reindex).
	 * @param SettingsStore  $settings Settings store.
	 * @param SettingsSchema $schema   Settings schema.
	 * @param MemoryStore    $memory   Agent memory.
	 * @param Stats          $stats    Stats service.
	 */
	public function __construct(
		private readonly ManualEntries $entries,
		private readonly IndexManager $indexer,
		private readonly SettingsStore $settings,
		private readonly SettingsSchema $schema,
		private readonly MemoryStore $memory,
		private readonly Stats $stats,
	) {
	}

	/**
	 * Register everything on the registry.
	 *
	 * @param ActionRegistry $registry Registry.
	 */
	public function register( ActionRegistry $registry ): void {
		$registry->register(
			array(
				'id'          => 'kb.add_entry',
				'group'       => 'kb',
				'description' => __( 'Add a manual knowledge-base entry (note or FAQ) that the assistant can quote immediately.', 'agentyllo' ),
				'cap'         => 'agyl_manage_kb',
				'args'        => array(
					'title'   => array( 'type' => 'string', 'required' => true, 'maxlen' => 200 ),
					'content' => array( 'type' => 'text', 'required' => true, 'maxlen' => 20000 ),
					'type'    => array( 'type' => 'enum', 'values' => array( 'note', 'faq' ), 'default' => 'note' ),
					'url'     => array( 'type' => 'string', 'maxlen' => 500 ),
				),
				'dry_run'     => static fn ( array $a ): array => array(
					'summary' => sprintf( /* translators: %s: title */ __( 'Create KB entry "%s"', 'agentyllo' ), $a['title'] ),
					'details' => array( 'title' => $a['title'], 'type' => $a['type'] ?? 'note', 'content_preview' => mb_substr( (string) $a['content'], 0, 200 ) ),
				),
				'run'         => function ( array $a ): array {
					$id = $this->entries->create( (string) $a['title'], (string) $a['content'], (string) ( $a['type'] ?? 'note' ), '', (string) ( $a['url'] ?? '' ) );

					return array(
						'ok'      => $id > 0,
						'message' => $id > 0 ? sprintf( /* translators: %d: document id */ __( 'Entry #%d added to the knowledge base.', 'agentyllo' ), $id ) : __( 'Could not save the entry.', 'agentyllo' ),
						'data'    => array( 'document_id' => $id ),
					);
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'kb.update',
				'group'       => 'kb',
				'description' => __( 'Update the title or content of a manual KB entry.', 'agentyllo' ),
				'cap'         => 'agyl_manage_kb',
				'args'        => array(
					'id'      => array( 'type' => 'int', 'required' => true, 'min' => 1 ),
					'title'   => array( 'type' => 'string', 'maxlen' => 200 ),
					'content' => array( 'type' => 'text', 'maxlen' => 20000 ),
				),
				'dry_run'     => function ( array $a ): array {
					$row = $this->entries->row( (int) $a['id'] );

					return array(
						'summary' => null === $row ? __( 'Entry not found.', 'agentyllo' ) : sprintf( /* translators: 1: id, 2: title */ __( 'Update entry #%1$d "%2$s"', 'agentyllo' ), $a['id'], $row['title'] ),
						'details' => array_intersect_key( $a, array_flip( array( 'title', 'content' ) ) ),
					);
				},
				'run'         => function ( array $a ): array {
					$id = $this->entries->update( (int) $a['id'], $a['title'] ?? null, $a['content'] ?? null );

					return array(
						'ok'      => $id > 0,
						'message' => $id > 0 ? __( 'Entry updated.', 'agentyllo' ) : __( 'Entry not found or nothing to update.', 'agentyllo' ),
						'data'    => array( 'document_id' => $id ),
					);
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'kb.delete',
				'group'       => 'kb',
				'description' => __( 'Move a manual KB entry to the trash (restorable for 30 days).', 'agentyllo' ),
				'cap'         => 'agyl_manage_kb',
				'destructive' => true,
				'args'        => array(
					'id' => array( 'type' => 'int', 'required' => true, 'min' => 1 ),
				),
				'dry_run'     => function ( array $a ): array {
					$row = $this->entries->row( (int) $a['id'] );

					return array(
						'summary' => null === $row ? __( 'Entry not found.', 'agentyllo' ) : sprintf( /* translators: 1: id, 2: title */ __( 'Trash entry #%1$d "%2$s" (undo available for 30 days)', 'agentyllo' ), $a['id'], $row['title'] ),
						'details' => $row ?? array(),
					);
				},
				'run'         => function ( array $a ): array {
					$ok = $this->entries->trash( (int) $a['id'] );

					return array(
						'ok'      => $ok,
						'message' => $ok ? __( 'Entry moved to the trash.', 'agentyllo' ) : __( 'Entry not found.', 'agentyllo' ),
						'data'    => array( 'document_id' => (int) $a['id'], 'undo' => 'kb.restore' ),
					);
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'kb.restore',
				'group'       => 'kb',
				'description' => __( 'Restore a trashed manual KB entry.', 'agentyllo' ),
				'cap'         => 'agyl_manage_kb',
				'args'        => array( 'id' => array( 'type' => 'int', 'required' => true, 'min' => 1 ) ),
				'run'         => function ( array $a ): array {
					$ok = $this->entries->restore( (int) $a['id'] );

					return array(
						'ok'      => $ok,
						'message' => $ok ? __( 'Entry restored.', 'agentyllo' ) : __( 'Nothing to restore.', 'agentyllo' ),
						'data'    => array(),
					);
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'kb.list',
				'group'       => 'kb',
				'description' => __( 'List recent manual KB entries.', 'agentyllo' ),
				'cap'         => 'agyl_manage_kb',
				'args'        => array( 'limit' => array( 'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 50 ) ),
				'run'         => function ( array $a ): array {
					$rows = $this->entries->recent( (int) ( $a['limit'] ?? 10 ) );

					return array(
						'ok'      => true,
						'message' => $rows ? sprintf( /* translators: %d: count */ _n( '%d manual entry.', '%d manual entries.', count( $rows ), 'agentyllo' ), count( $rows ) ) : __( 'No manual entries yet.', 'agentyllo' ),
						'data'    => array( 'entries' => $rows ),
					);
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'kb.reindex',
				'group'       => 'kb',
				'description' => __( 'Start a full re-crawl of the knowledge base (background).', 'agentyllo' ),
				'cap'         => 'agyl_manage_kb',
				'destructive' => true,
				'args'        => array( 'source' => array( 'type' => 'enum', 'values' => array( 'all', 'post', 'product', 'menu', 'site', 'taxonomy' ), 'default' => 'all' ) ),
				'run'         => function ( array $a ): array {
					$source = (string) ( $a['source'] ?? 'all' );
					$ok     = $this->indexer->start_full_crawl( 'all' === $source ? null : $source );

					return array(
						'ok'      => $ok,
						'message' => $ok ? __( 'Re-crawl scheduled — it runs in the background.', 'agentyllo' ) : __( 'Nothing to crawl for that source.', 'agentyllo' ),
						'data'    => array(),
					);
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'settings.get',
				'group'       => 'settings',
				'description' => __( 'Read a non-secret setting.', 'agentyllo' ),
				'cap'         => 'agyl_manage_settings',
				'args'        => array(
					'tab' => array( 'type' => 'enum', 'values' => array_keys( self::SETTINGS_WHITELIST ), 'required' => true ),
					'key' => array( 'type' => 'string', 'required' => true, 'maxlen' => 60 ),
				),
				'run'         => function ( array $a ): array {
					$tab = (string) $a['tab'];
					$key = (string) $a['key'];
					if ( ! in_array( $key, self::SETTINGS_WHITELIST[ $tab ] ?? array(), true ) ) {
						return array( 'ok' => false, 'message' => __( 'That setting is not readable through the copilot.', 'agentyllo' ), 'data' => array() );
					}
					$value = $this->settings->value( $tab, $key );

					return array(
						'ok'      => true,
						'message' => sprintf( '%s.%s = %s', $tab, $key, is_scalar( $value ) ? var_export( $value, true ) : wp_json_encode( $value ) ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
						'data'    => array( 'value' => $value ),
					);
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'settings.set',
				'group'       => 'settings',
				'description' => __( 'Change a non-secret setting (schema-validated).', 'agentyllo' ),
				'cap'         => 'agyl_manage_settings',
				'destructive' => true,
				'args'        => array(
					'tab'   => array( 'type' => 'enum', 'values' => array_keys( self::SETTINGS_WHITELIST ), 'required' => true ),
					'key'   => array( 'type' => 'string', 'required' => true, 'maxlen' => 60 ),
					'value' => array( 'type' => 'text', 'required' => true, 'maxlen' => 2000 ),
				),
				'dry_run'     => function ( array $a ): array {
					$tab = (string) $a['tab'];
					$key = (string) $a['key'];
					if ( ! in_array( $key, self::SETTINGS_WHITELIST[ $tab ] ?? array(), true ) ) {
						return array( 'summary' => __( 'That setting cannot be changed through the copilot.', 'agentyllo' ), 'details' => array() );
					}
					$current = $this->settings->value( $tab, $key );
					$new     = $this->schema->sanitize( $tab, array( $key => $this->coerce( $tab, $key, (string) $a['value'] ) ) )[ $key ] ?? null;

					return array(
						'summary' => sprintf( /* translators: 1: tab, 2: key */ __( 'Change %1$s.%2$s', 'agentyllo' ), $tab, $key ),
						'details' => array( 'from' => $current, 'to' => $new ),
					);
				},
				'run'         => function ( array $a ): array {
					$tab = (string) $a['tab'];
					$key = (string) $a['key'];
					if ( ! in_array( $key, self::SETTINGS_WHITELIST[ $tab ] ?? array(), true ) ) {
						return array( 'ok' => false, 'message' => __( 'That setting cannot be changed through the copilot.', 'agentyllo' ), 'data' => array() );
					}
					$new = $this->settings->update( $tab, array( $key => $this->coerce( $tab, $key, (string) $a['value'] ) ) );

					return array(
						'ok'      => true,
						'message' => sprintf( '%s.%s = %s', $tab, $key, is_scalar( $new[ $key ] ?? null ) ? var_export( $new[ $key ], true ) : wp_json_encode( $new[ $key ] ?? null ) ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
						'data'    => array( 'value' => $new[ $key ] ?? null ),
					);
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'memory.teach',
				'group'       => 'memory',
				'description' => __( 'Teach the assistant a fact about your business (used by the composer, e.g. "we close in August").', 'agentyllo' ),
				'cap'         => 'agyl_use_copilot',
				'args'        => array(
					'fact' => array( 'type' => 'text', 'required' => true, 'maxlen' => 500 ),
					'key'  => array( 'type' => 'string', 'maxlen' => 60 ),
				),
				'run'         => function ( array $a ): array {
					$key = '' !== (string) ( $a['key'] ?? '' ) ? sanitize_key( (string) $a['key'] ) : 'fact_' . substr( sha1( (string) $a['fact'] ), 0, 10 );
					$this->memory->remember( 'composer', $key, array( 'text' => (string) $a['fact'], 'by' => get_current_user_id() ), 'fact', 80 );
					// Owner facts also become a manual KB entry so retrieval finds them.
					$this->entries->create( mb_substr( (string) $a['fact'], 0, 80 ), (string) $a['fact'], 'note' );

					return array( 'ok' => true, 'message' => __( 'Got it — I will remember that.', 'agentyllo' ), 'data' => array( 'key' => $key ) );
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'memory.query',
				'group'       => 'memory',
				'description' => __( 'Show what the assistant has been taught.', 'agentyllo' ),
				'cap'         => 'agyl_use_copilot',
				'args'        => array( 'limit' => array( 'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 100 ) ),
				'run'         => function ( array $a ): array {
					$facts = $this->memory->by_kind( 'composer', 'fact', (int) ( $a['limit'] ?? 20 ) );

					return array(
						'ok'      => true,
						'message' => $facts ? sprintf( /* translators: %d: count */ _n( '%d remembered fact.', '%d remembered facts.', count( $facts ), 'agentyllo' ), count( $facts ) ) : __( 'Nothing taught yet.', 'agentyllo' ),
						'data'    => array(
							'facts' => array_map(
								static fn ( string $key, array $content ): array => array(
									'key'  => $key,
									'text' => (string) ( $content['text'] ?? '' ),
								),
								array_keys( $facts ),
								array_values( $facts )
							),
						),
					);
				},
			)
		);

		$registry->register(
			array(
				'id'          => 'stats.query',
				'group'       => 'stats',
				'description' => __( 'Summarize conversations, resolution and top unanswered questions for a period.', 'agentyllo' ),
				'cap'         => 'agyl_view_stats',
				'args'        => array( 'period' => array( 'type' => 'enum', 'values' => array( '7d', '30d', '90d' ), 'default' => '30d' ) ),
				'run'         => function ( array $a ): array {
					$days   = (int) rtrim( (string) ( $a['period'] ?? '30d' ), 'd' );
					$totals = $this->stats->totals( $days );
					$gaps   = $this->stats->unanswered( 5 );

					return array(
						'ok'      => true,
						'message' => sprintf(
							/* translators: 1: days, 2: conversations, 3: messages, 4: unanswered */
							__( 'Last %1$d days: %2$d conversations, %3$d messages, %4$d unanswered.', 'agentyllo' ),
							$days,
							(int) ( $totals['conversations'] ?? 0 ),
							(int) ( $totals['messages'] ?? 0 ),
							(int) ( $totals['unanswered'] ?? 0 )
						),
						'data'    => array( 'totals' => $totals, 'top_unanswered' => $gaps ),
					);
				},
			)
		);
	}

	/**
	 * Coerce a text value to the field's type using the schema (bool/int).
	 *
	 * @param string $tab   Tab.
	 * @param string $key   Key.
	 * @param string $value Raw.
	 */
	private function coerce( string $tab, string $key, string $value ): mixed {
		$field = $this->schema->tab( $tab )[ $key ] ?? array();
		switch ( (string) ( $field['type'] ?? 'string' ) ) {
			case 'bool':
				return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on', 'si', 'sì' ), true );
			case 'int':
				return (int) $value;
			case 'float':
				return (float) $value;
			default:
				return $value;
		}
	}
}
