<?php
/**
 * Companion bootstrap: settings tab, supervisor hooks, admin page.
 *
 * @package AgentylloLocalAI
 */

declare( strict_types=1 );

namespace AgentylloLocalAI;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates with Agentyllo purely through its public hooks:
 *  - `agy_settings_tabs`         → adds the `local_ai` tab (schema-driven UI in Settings).
 *  - `agy_local_endpoint_url`    → points the core LocalEndpointProvider at the managed daemon.
 *  - `agy_provider_chain`        → touches/auto-starts the daemon when a request routes to it.
 *  - Agentyllo admin menu        → "Local AI" page (status, start/stop, catalog install with consent).
 * No code is ever downloaded through Agentyllo core; this plugin is the
 * consented binary installer, distributed outside WordPress.org.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Supervisor $supervisor;

	private Installer $installer;

	/**
	 * Boot once.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->run();
		}
	}

	/**
	 * Wire hooks.
	 */
	private function run(): void {
		$this->supervisor = new Supervisor( array( $this, 'settings' ) );
		$this->installer  = new Installer();

		add_filter( 'agy_settings_tabs', array( $this, 'add_settings_tab' ) );
		add_filter( 'agy_local_endpoint_url', array( $this, 'endpoint_url' ) );
		add_filter( 'agy_provider_chain', array( $this, 'on_chain' ), 20 );

		add_action( Supervisor::HOOK_IDLE_CHECK, array( $this->supervisor, 'idle_check' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ), 30 );

		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_agyl_action', array( $this, 'handle_action' ) );
	}

	/**
	 * Effective 'local_ai' settings (through Agentyllo's store so the schema
	 * sanitizer applies).
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$container = \Agentyllo\Plugin::instance()?->container();
		if ( null === $container ) {
			return array();
		}
		try {
			return $container->get( \Agentyllo\Admin\Settings\SettingsStore::class )->get( 'local_ai' );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Settings tab schema.
	 *
	 * @param array $tabs Tabs.
	 */
	public function add_settings_tab( array $tabs ): array {
		$tabs['local_ai'] = array(
			'managed_daemon'    => array( 'type' => 'bool', 'default' => true, 'label' => __( 'Use the managed daemon as Agentyllo\'s local engine', 'agentyllo-local-ai' ) ),
			'engine_binary'     => array( 'type' => 'string', 'default' => '', 'maxlen' => 500, 'label' => __( 'llama-server binary path', 'agentyllo-local-ai' ) ),
			'model_file'        => array( 'type' => 'string', 'default' => '', 'maxlen' => 500, 'label' => __( 'GGUF model file path', 'agentyllo-local-ai' ) ),
			'port'              => array( 'type' => 'int', 'default' => 8756, 'min' => 1024, 'max' => 65535, 'label' => __( 'Port (127.0.0.1)', 'agentyllo-local-ai' ) ),
			'ctx_size'          => array( 'type' => 'int', 'default' => 4096, 'min' => 512, 'max' => 131072, 'label' => __( 'Context size (tokens)', 'agentyllo-local-ai' ) ),
			'threads'           => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 256, 'label' => __( 'Threads (0 = auto)', 'agentyllo-local-ai' ) ),
			'enable_embeddings' => array( 'type' => 'bool', 'default' => false, 'label' => __( 'Enable /v1/embeddings (--embeddings)', 'agentyllo-local-ai' ) ),
			'auto_start'        => array( 'type' => 'bool', 'default' => true, 'label' => __( 'Start the daemon on demand', 'agentyllo-local-ai' ) ),
			'idle_ttl_min'      => array( 'type' => 'int', 'default' => 15, 'min' => 1, 'max' => 1440, 'label' => __( 'Stop after idle minutes', 'agentyllo-local-ai' ) ),
			'extra_args'        => array( 'type' => 'string', 'default' => '', 'maxlen' => 300, 'label' => __( 'Extra llama-server flags', 'agentyllo-local-ai' ) ),
		);

		return $tabs;
	}

	/**
	 * Point core at the managed daemon when configured.
	 *
	 * @param string $url Configured URL.
	 */
	public function endpoint_url( string $url ): string {
		$s = $this->settings();
		if ( ! empty( $s['managed_daemon'] ) && $this->supervisor->configured() ) {
			return $this->supervisor->url();
		}

		return $url;
	}

	/**
	 * When the chain routes to the local endpoint, keep the daemon warm.
	 *
	 * @param array $chain Providers.
	 */
	public function on_chain( array $chain ): array {
		foreach ( $chain as $provider ) {
			if ( is_object( $provider ) && method_exists( $provider, 'id' ) && 'local_endpoint' === $provider->id() ) {
				$s = $this->settings();
				if ( ! empty( $s['managed_daemon'] ) ) {
					$this->supervisor->touch_and_ensure();
				}
				break;
			}
		}

		return $chain;
	}

	/**
	 * Recurring idle check (Action Scheduler, group agentyllo-ai).
	 */
	public function ensure_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( Supervisor::HOOK_IDLE_CHECK, array(), 'agentyllo-ai' ) ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, Supervisor::HOOK_IDLE_CHECK, array(), 'agentyllo-ai', true );
		}
	}

	/**
	 * Admin submenu under Agentyllo.
	 */
	public function menu(): void {
		add_submenu_page(
			'agentyllo',
			__( 'Local AI', 'agentyllo-local-ai' ),
			__( 'Local AI', 'agentyllo-local-ai' ),
			'manage_options',
			'agentyllo-local-ai',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Start/stop/install actions (admin-post, nonce + capability).
	 */
	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentyllo-local-ai' ) );
		}
		check_admin_referer( 'agyl_action' );

		$do   = sanitize_key( (string) ( $_POST['do'] ?? '' ) );
		$msg  = '';
		$type = 'success';

		if ( 'start' === $do ) {
			$err  = $this->supervisor->start();
			$msg  = '' === $err ? __( 'Daemon starting — it may take a minute to load the model.', 'agentyllo-local-ai' ) : $err;
			$type = '' === $err ? 'success' : 'error';
		} elseif ( 'stop' === $do ) {
			$this->supervisor->stop();
			$msg = __( 'Daemon stopped.', 'agentyllo-local-ai' );
		} elseif ( 'install' === $do ) {
			$kind = sanitize_key( (string) ( $_POST['kind'] ?? '' ) );
			$id   = sanitize_text_field( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) );
			if ( empty( $_POST['consent'] ) ) {
				$msg  = __( 'Please accept the license and download consent first.', 'agentyllo-local-ai' );
				$type = 'error';
			} else {
				[ $ok, $msg, $path ] = $this->installer->install( 'engine' === $kind ? 'engine' : 'model', $id );
				$type                = $ok ? 'success' : 'error';
				if ( $ok && '' !== $path ) {
					$store = \Agentyllo\Plugin::instance()?->container()->get( \Agentyllo\Admin\Settings\SettingsStore::class );
					$store?->update( 'local_ai', array( 'engine' === $kind ? 'engine_binary' : 'model_file' => $path ) );
				}
			}
		}

		set_transient( 'agyl_notice_' . get_current_user_id(), array( $type, $msg ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=agentyllo-local-ai' ) );
		exit;
	}

	/**
	 * Render the Local AI page (server-rendered; no build step).
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status  = $this->supervisor->status();
		$catalog = $this->installer->catalog();
		$s       = $this->settings();
		$notice  = get_transient( 'agyl_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'agyl_notice_' . get_current_user_id() );
		}
		$caps = get_option( 'agy_capabilities' );
		$tier = is_array( $caps ) ? (string) ( $caps['tiers']['best_free_tier'] ?? '' ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agentyllo Local AI', 'agentyllo-local-ai' ); ?></h1>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( (string) $notice[1] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Managed daemon', 'agentyllo-local-ai' ); ?></h2>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr><th><?php esc_html_e( 'Configured', 'agentyllo-local-ai' ); ?></th><td><?php echo $this->supervisor->configured() ? esc_html__( 'Yes', 'agentyllo-local-ai' ) : esc_html__( 'No — set the binary and model paths in Settings → Local AI (or install from the catalog below).', 'agentyllo-local-ai' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Endpoint', 'agentyllo-local-ai' ); ?></th><td><code><?php echo esc_html( $this->supervisor->url() ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Status', 'agentyllo-local-ai' ); ?></th><td><?php echo $status['running'] ? ( $status['healthy'] ? esc_html__( 'Running and healthy', 'agentyllo-local-ai' ) : esc_html__( 'Starting / not answering yet', 'agentyllo-local-ai' ) ) : esc_html__( 'Stopped', 'agentyllo-local-ai' ); ?><?php echo $status['pid'] ? ' (PID ' . (int) $status['pid'] . ')' : ''; ?></td></tr>
					<tr><th><?php esc_html_e( 'Hosting tier (Agentyllo scan)', 'agentyllo-local-ai' ); ?></th><td><?php echo esc_html( '' !== $tier ? $tier : '—' ); ?> — <?php echo esc_html( $catalog['platform'] ); ?></td></tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0">
				<?php wp_nonce_field( 'agyl_action' ); ?>
				<input type="hidden" name="action" value="agyl_action">
				<?php if ( $status['running'] ) : ?>
					<button class="button" name="do" value="stop"><?php esc_html_e( 'Stop daemon', 'agentyllo-local-ai' ); ?></button>
				<?php else : ?>
					<button class="button button-primary" name="do" value="start" <?php disabled( ! $this->supervisor->configured() ); ?>><?php esc_html_e( 'Start daemon', 'agentyllo-local-ai' ); ?></button>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=agentyllo-settings' ) ); ?>"><?php esc_html_e( 'Settings → Local AI', 'agentyllo-local-ai' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=agentyllo-models' ) ); ?>"><?php esc_html_e( 'Agentyllo → AI Models', 'agentyllo-local-ai' ); ?></a>
			</form>
			<?php if ( '' !== $status['log'] ) : ?>
				<details><summary><?php esc_html_e( 'Daemon log (tail)', 'agentyllo-local-ai' ); ?></summary><pre style="max-height:240px;overflow:auto;background:#fff;padding:8px"><?php echo esc_html( $status['log'] ); ?></pre></details>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Verified catalog', 'agentyllo-local-ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Engines and open-license models published in the signed Agentyllo registry for your platform. Every download is SHA-256 verified before use. Nothing is downloaded without your explicit consent.', 'agentyllo-local-ai' ); ?></p>
			<?php if ( ! $catalog['engines'] && ! $catalog['models'] ) : ?>
				<p><em><?php esc_html_e( 'The registry has not published verified packages for this platform yet. You can still use a llama-server / Ollama you installed yourself: set the binary and model paths in Settings → Local AI, or configure a BYO endpoint URL in Agentyllo → AI Models.', 'agentyllo-local-ai' ); ?></em></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'agyl_action' ); ?>
					<input type="hidden" name="action" value="agyl_action">
					<input type="hidden" name="do" value="install">
					<table class="widefat striped" style="max-width:960px">
						<thead><tr><th><?php esc_html_e( 'Kind', 'agentyllo-local-ai' ); ?></th><th><?php esc_html_e( 'Package', 'agentyllo-local-ai' ); ?></th><th><?php esc_html_e( 'Size', 'agentyllo-local-ai' ); ?></th><th><?php esc_html_e( 'License', 'agentyllo-local-ai' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( array( 'engine' => $catalog['engines'], 'model' => $catalog['models'] ) as $kind => $entries ) : ?>
							<?php foreach ( $entries as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( $kind ); ?></td>
									<td><strong><?php echo esc_html( (string) ( $entry['label'] ?? $entry['id'] ) ); ?></strong><br><code><?php echo esc_html( (string) $entry['id'] ); ?></code> <?php echo esc_html( (string) ( $entry['version'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( isset( $entry['size_mb'] ) ? (int) $entry['size_mb'] . ' MB' : '—' ); ?></td>
									<td><?php echo esc_html( (string) ( $entry['license'] ?? '—' ) ); ?></td>
									<td><button class="button" name="id" value="<?php echo esc_attr( (string) $entry['id'] ); ?>" onclick="this.form.kind.value='<?php echo esc_js( $kind ); ?>'"><?php esc_html_e( 'Install', 'agentyllo-local-ai' ); ?></button></td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
						</tbody>
					</table>
					<input type="hidden" name="kind" value="">
					<p><label><input type="checkbox" name="consent" value="1"> <?php esc_html_e( 'I accept the package license and consent to downloading it to this server.', 'agentyllo-local-ai' ); ?></label></p>
				</form>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %s: settings summary */ __( 'Current settings: %s', 'agentyllo-local-ai' ), wp_json_encode( array_intersect_key( $s, array_flip( array( 'engine_binary', 'model_file', 'port', 'ctx_size', 'threads', 'idle_ttl_min', 'auto_start', 'managed_daemon' ) ) ) ) ) ); ?></p>
		</div>
		<?php
	}
}
