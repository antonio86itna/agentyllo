<?php
/**
 * Single owner of every Agentyllo dbDelta DDL statement.
 *
 * Rules: VARCHAR instead of ENUM (dbDelta mishandles ENUM), explicit
 * $charset_collate on every table, indexes named, utf8mb4-safe key lengths.
 * New milestones append tables HERE and bump AGY_DB_VERSION.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Install;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- Agentyllo's own custom tables: names are $wpdb->prefix plus literal constants; every value goes through $wpdb->prepare().

/**
 * Table definitions + dbDelta runner.
 */
final class Schema {

	/**
	 * Unprefixed Agentyllo table names (without the `agy_` prefix).
	 * Used by the migrator and mirrored (as a literal list) in uninstall.php.
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array(
			'agy_agent_memory',
			'agy_agent_journal',
			'agy_kb_documents',
			'agy_kb_chunks',
			'agy_kb_terms',
			'agy_kb_links',
			'agy_kb_vectors',
			'agy_sessions',
			'agy_rate_events',
			'agy_conversations',
			'agy_messages',
			'agy_consents',
			'agy_audit_log',
			'agy_stats_daily',
			'agy_stats_intents',
			'agy_stats_unanswered',
			'agy_inference_log',
			'agy_response_cache',
			'agy_chat_events',
		);
	}

	/**
	 * Full CREATE TABLE statements keyed by prefixed table name.
	 *
	 * @return array<string, string>
	 */
	public static function tables(): array {
		global $wpdb;

		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();
		$tables  = array();

		// Composite unique key must stay ≤191 utf8mb4 chars total (767-byte
		// InnoDB prefix limit on older MySQL/MariaDB): 64 + 125 = 189.
		$tables[ "{$p}agy_agent_memory" ] = "CREATE TABLE {$p}agy_agent_memory (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id VARCHAR(64) NOT NULL,
  mem_key VARCHAR(125) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'fact',
  content LONGTEXT NULL,
  content_hash CHAR(40) NULL,
  importance TINYINT UNSIGNED NOT NULL DEFAULT 50,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY agent_key (agent_id, mem_key),
  KEY agent_kind (agent_id, kind),
  KEY expires_at (expires_at)
) {$charset};";

		$tables[ "{$p}agy_agent_journal" ] = "CREATE TABLE {$p}agy_agent_journal (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id VARCHAR(64) NOT NULL,
  task_ref VARCHAR(36) NULL,
  level VARCHAR(10) NOT NULL DEFAULT 'info',
  event VARCHAR(64) NOT NULL,
  message TEXT NULL,
  context LONGTEXT NULL,
  fingerprint CHAR(40) NULL,
  occurrences INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY agent_level (agent_id, level),
  KEY fingerprint (fingerprint),
  KEY event_time (event, created_at)
) {$charset};";

		$tables[ "{$p}agy_kb_documents" ] = "CREATE TABLE {$p}agy_kb_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(32) NOT NULL,
  external_id VARCHAR(64) NOT NULL,
  subtype VARCHAR(32) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  title TEXT NOT NULL,
  permalink VARCHAR(2048) NOT NULL DEFAULT '',
  lang VARCHAR(10) NOT NULL DEFAULT '',
  thumbnail_id BIGINT UNSIGNED NULL,
  structured LONGTEXT NULL,
  content_hash CHAR(40) NOT NULL,
  weight TINYINT UNSIGNED NOT NULL DEFAULT 50,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  chunk_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  source_modified_gmt DATETIME NULL,
  indexed_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY src_ext (source, external_id),
  KEY by_state (source, subtype, status),
  KEY hash (content_hash),
  KEY permalink (permalink(191))
) {$charset};";

		$tables[ "{$p}agy_kb_chunks" ] = "CREATE TABLE {$p}agy_kb_chunks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id BIGINT UNSIGNED NOT NULL,
  seq SMALLINT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'prose',
  heading_path VARCHAR(512) NOT NULL DEFAULT '',
  content MEDIUMTEXT NOT NULL,
  token_est SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  lang VARCHAR(10) NOT NULL DEFAULT '',
  chunk_hash CHAR(40) NOT NULL,
  simhash CHAR(16) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY doc_seq (document_id, seq)
) {$charset};";

		$tables[ "{$p}agy_kb_terms" ] = "CREATE TABLE {$p}agy_kb_terms (
  term VARCHAR(48) NOT NULL,
  chunk_id BIGINT UNSIGNED NOT NULL,
  lang VARCHAR(10) NOT NULL DEFAULT '',
  tf SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY  (term, chunk_id),
  KEY by_chunk (chunk_id)
) {$charset};";

		$tables[ "{$p}agy_sessions" ] = "CREATE TABLE {$p}agy_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_hash CHAR(64) NULL,
  lang VARCHAR(10) NOT NULL DEFAULT '',
  message_count INT UNSIGNED NOT NULL DEFAULT 0,
  gated TINYINT(1) NOT NULL DEFAULT 0,
  meta LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY expires_at (expires_at)
) {$charset};";

		$tables[ "{$p}agy_rate_events" ] = "CREATE TABLE {$p}agy_rate_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket CHAR(64) NOT NULL,
  event_time DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY bucket_time (bucket, event_time)
) {$charset};";

		$tables[ "{$p}agy_conversations" ] = "CREATE TABLE {$p}agy_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  visitor_name VARCHAR(190) NULL,
  visitor_email VARCHAR(190) NULL,
  lang VARCHAR(12) NOT NULL DEFAULT '',
  tier VARCHAR(20) NOT NULL DEFAULT 'classic',
  source VARCHAR(20) NOT NULL DEFAULT 'widget',
  ip_hash CHAR(64) NULL,
  consent_id BIGINT UNSIGNED NULL,
  message_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  resolved TINYINT(1) NULL,
  handoff TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  last_activity_at DATETIME NOT NULL,
  meta LONGTEXT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY email (visitor_email),
  KEY started (started_at),
  KEY by_session (session_id)
) {$charset};";

		$tables[ "{$p}agy_messages" ] = "CREATE TABLE {$p}agy_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(12) NOT NULL,
  content LONGTEXT NOT NULL,
  blocks LONGTEXT NULL,
  tier VARCHAR(20) NOT NULL DEFAULT 'classic',
  agent VARCHAR(40) NULL,
  model VARCHAR(80) NULL,
  prompt_version VARCHAR(20) NULL,
  intent VARCHAR(100) NULL,
  confidence DECIMAL(4,3) NULL,
  kb_sources TEXT NULL,
  latency_ms INT UNSIGNED NULL,
  tokens_in INT UNSIGNED NULL,
  tokens_out INT UNSIGNED NULL,
  cost_usd DECIMAL(10,6) NULL,
  flagged_unanswered TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY convo (conversation_id),
  KEY created (created_at),
  KEY intent (intent(80))
) {$charset};";

		$tables[ "{$p}agy_consents" ] = "CREATE TABLE {$p}agy_consents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NULL,
  conversation_id BIGINT UNSIGNED NULL,
  email VARCHAR(190) NULL,
  visitor_name VARCHAR(190) NULL,
  consent_type VARCHAR(40) NOT NULL,
  text_version VARCHAR(20) NOT NULL DEFAULT '',
  text_hash CHAR(64) NOT NULL DEFAULT '',
  granted TINYINT(1) NOT NULL DEFAULT 1,
  ip_hash CHAR(64) NULL,
  ua_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY by_session (session_id),
  KEY created (created_at)
) {$charset};";

		$tables[ "{$p}agy_audit_log" ] = "CREATE TABLE {$p}agy_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id BIGINT UNSIGNED NULL,
  actor_type VARCHAR(20) NOT NULL DEFAULT 'user',
  action VARCHAR(64) NOT NULL,
  target VARCHAR(190) NULL,
  args_hash CHAR(64) NULL,
  result VARCHAR(20) NOT NULL DEFAULT 'ok',
  detail TEXT NULL,
  ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY action_time (action, created_at),
  KEY actor (actor_id)
) {$charset};";

		$tables[ "{$p}agy_stats_daily" ] = "CREATE TABLE {$p}agy_stats_daily (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stat_date DATE NOT NULL,
  tier VARCHAR(20) NOT NULL DEFAULT 'classic',
  conversations INT UNSIGNED NOT NULL DEFAULT 0,
  messages INT UNSIGNED NOT NULL DEFAULT 0,
  resolved INT UNSIGNED NOT NULL DEFAULT 0,
  handoffs INT UNSIGNED NOT NULL DEFAULT 0,
  oos_refusals INT UNSIGNED NOT NULL DEFAULT 0,
  unanswered INT UNSIGNED NOT NULL DEFAULT 0,
  kb_hit_answers INT UNSIGNED NOT NULL DEFAULT 0,
  avg_latency_ms INT UNSIGNED NULL,
  p95_latency_ms INT UNSIGNED NULL,
  tokens_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tokens_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cost_usd DECIMAL(12,4) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY day_tier (stat_date, tier)
) {$charset};";

		$tables[ "{$p}agy_stats_intents" ] = "CREATE TABLE {$p}agy_stats_intents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stat_date DATE NOT NULL,
  intent VARCHAR(100) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  answered INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY day_intent (stat_date, intent(80))
) {$charset};";

		$tables[ "{$p}agy_stats_unanswered" ] = "CREATE TABLE {$p}agy_stats_unanswered (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_hash CHAR(40) NOT NULL,
  question_sample TEXT NOT NULL,
  lang VARCHAR(12) NOT NULL DEFAULT '',
  intent VARCHAR(100) NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'open',
  suggested_action TEXT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY qhash (question_hash),
  KEY status_hits (status, hits)
) {$charset};";

		$tables[ "{$p}agy_kb_links" ] = "CREATE TABLE {$p}agy_kb_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_document_id BIGINT UNSIGNED NULL,
  to_document_id BIGINT UNSIGNED NULL,
  to_url VARCHAR(2048) NOT NULL,
  to_url_hash CHAR(40) NOT NULL,
  anchor VARCHAR(255) NOT NULL DEFAULT '',
  rel VARCHAR(16) NOT NULL DEFAULT 'content',
  is_internal TINYINT(1) NOT NULL DEFAULT 1,
  http_status SMALLINT NULL,
  checked_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY from_doc (from_document_id),
  KEY to_doc (to_document_id),
  KEY url (to_url_hash)
) {$charset};";

		$tables[ "{$p}agy_kb_vectors" ] = "CREATE TABLE {$p}agy_kb_vectors (
  chunk_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  model VARCHAR(80) NOT NULL,
  dims SMALLINT UNSIGNED NOT NULL,
  vec LONGBLOB NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (chunk_id),
  KEY model_doc (model, document_id)
) {$charset};";

		$tables[ "{$p}agy_inference_log" ] = "CREATE TABLE {$p}agy_inference_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(32) NOT NULL,
  model VARCHAR(80) NOT NULL DEFAULT '',
  task VARCHAR(20) NOT NULL DEFAULT 'chat',
  ok TINYINT(1) NOT NULL DEFAULT 1,
  error VARCHAR(40) NULL,
  tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
  cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
  latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
  tok_per_s DECIMAL(8,2) NOT NULL DEFAULT 0,
  streamed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY provider_time (provider, created_at),
  KEY created (created_at)
) {$charset};";

		$tables[ "{$p}agy_response_cache" ] = "CREATE TABLE {$p}agy_response_cache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cache_key CHAR(40) NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT '',
  model VARCHAR(80) NOT NULL DEFAULT '',
  prompt_version VARCHAR(20) NOT NULL DEFAULT '',
  text LONGTEXT NOT NULL,
  blocks LONGTEXT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ckey (cache_key),
  KEY expires (expires_at)
) {$charset};";

		$tables[ "{$p}agy_chat_events" ] = "CREATE TABLE {$p}agy_chat_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NULL,
  seq INT UNSIGNED NOT NULL DEFAULT 0,
  kind VARCHAR(16) NOT NULL,
  payload MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY convo_seq (conversation_id, seq),
  KEY created (created_at)
) {$charset};";

		return $tables;
	}

	/**
	 * Capability-dependent extras dbDelta cannot express. FULLTEXT failure is
	 * non-fatal by design: the terms-table BM25 floor works without it, and
	 * the stored flag tells retrieval which mode it has.
	 */
	private static function install_fulltext(): void {
		global $wpdb;

		$caps  = get_option( 'agy_kb_caps', array() );
		$caps  = is_array( $caps ) ? $caps : array();
		$table = $wpdb->prefix . 'agy_kb_chunks';

		if ( ! isset( $caps['fulltext'] ) ) {
			// MATCH … AGAINST is MySQL/MariaDB-only. On SQLite backends
			// (Playground, Studio/wp-now, the sqlite-database-integration
			// plugin) the shim may accept the ALTER without creating a real
			// FULLTEXT index — flag it off and let BM25 carry retrieval.
			$server = strtolower( (string) $wpdb->db_server_info() );
			if ( ( defined( 'DB_ENGINE' ) && 'mysql' !== DB_ENGINE ) || str_contains( $server, 'sqlite' ) ) {
				$caps['fulltext'] = false;
				update_option( 'agy_kb_caps', $caps, false );

				return;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'ft_content'" );
			if ( empty( $existing ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT ft_content (heading_path, content(768))" );
				$existing = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'ft_content'" );
			}
			// phpcs:enable
			$caps['fulltext'] = ! empty( $existing );
			update_option( 'agy_kb_caps', $caps, false );
		}
	}

	/**
	 * Create/upgrade all tables via dbDelta and stamp the schema version.
	 * The version is stamped ONLY when every table verifiably exists —
	 * dbDelta swallows CREATE TABLE errors, and a stamped-but-missing schema
	 * would silently disable all later writes.
	 *
	 * @return bool Whether every table exists after the run.
	 */
	public static function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::tables() as $ddl ) {
			dbDelta( $ddl );
		}

		foreach ( array_keys( self::tables() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				update_option( 'agy_schema_error', $table, false );
				return false;
			}
		}

		self::install_fulltext();

		delete_option( 'agy_schema_error' );
		update_option( 'agy_db_version', AGY_DB_VERSION, false );

		return true;
	}
}
