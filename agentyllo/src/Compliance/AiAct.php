<?php
/**
 * EU AI Act (Art. 50) transparency surfaces.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Compliance;

use Agentyllo\Admin\Settings\SettingsStore;

defined( 'ABSPATH' ) || exit;

/**
 * Art. 50(1): people must be told they are interacting with an AI system,
 * at the latest at first interaction (widget badge + first bubble + footer,
 * all shipped via /config). Art. 50(2): machine-readable marking on
 * generated output (`data-ai-generated` on the widget's response DOM +
 * `X-AGY-AI-Generated` header on AI-tier responses). This class provides
 * the transparency PAGE (deployer/provider identity, models in use, data
 * flows, human alternative, limitations, complaints) as a shortcode +
 * one-click generator.
 */
final class AiAct {

	public const SHORTCODE = 'agentyllo_transparency';

	/**
	 * Constructor.
	 *
	 * @param SettingsStore $settings Settings store.
	 */
	public function __construct( private readonly SettingsStore $settings ) {
	}

	/**
	 * Register the shortcode.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	/**
	 * Whether AI disclosure is currently mandatory (any AI mode active) —
	 * the setting is locked ON in that case.
	 */
	public function disclosure_locked(): bool {
		$mode = (string) $this->settings->value( 'general', 'operating_mode' );

		return 'classic' !== $mode;
	}

	/**
	 * Create (or return the existing) transparency page as a draft.
	 * Returns the page id.
	 */
	public function ensure_page(): int {
		$existing = (int) get_option( 'agy_transparency_page_id', 0 );
		if ( $existing > 0 && get_post( $existing ) ) {
			return $existing;
		}

		$id = wp_insert_post(
			array(
				'post_title'   => __( 'AI Assistant Transparency', 'agentyllo' ),
				'post_content' => '[' . self::SHORTCODE . ']',
				'post_status'  => 'draft',
				'post_type'    => 'page',
			)
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_option( 'agy_transparency_page_id', (int) $id, false );
		Audit::log( 'privacy.transparency_page_created', (string) $id );

		return (int) $id;
	}

	/**
	 * Shortcode renderer.
	 */
	public function render_shortcode(): string {
		$general = $this->settings->get( 'general' );
		$privacy = $this->settings->get( 'privacy' );
		$mode    = (string) $general['operating_mode'];

		$tiers = array( 'classic' => __( 'Classic (non-generative) agents: answers are extracted verbatim from this site\'s own content.', 'agentyllo' ) );
		if ( in_array( $mode, array( 'free_ai', 'classic_free_ai' ), true ) ) {
			$tiers['free_ai'] = __( 'Local AI models running on this website\'s own server (no data leaves the server).', 'agentyllo' );
		}
		if ( in_array( $mode, array( 'paid_ai', 'classic_paid_ai' ), true ) ) {
			$tiers['paid_ai'] = __( 'Third-party AI providers configured by the site owner (see data flows below).', 'agentyllo' );
		}

		$custom = trim( (string) $privacy['transparency_text'] );

		ob_start();
		?>
		<div class="agy-transparency">
			<h2><?php esc_html_e( 'About the AI assistant on this site', 'agentyllo' ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'The chat assistant on %s is an automated system. You are not talking to a human. This page explains how it works, what data it processes, and how to reach a person.', 'agentyllo' ), get_bloginfo( 'name' ) ) ); ?></p>

			<h3><?php esc_html_e( 'Who operates it', 'agentyllo' ); ?></h3>
			<p><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Deployer: the owner of %s. Software: Agentyllo (open source, GPL). Where third-party AI providers are enabled, they act as model providers.', 'agentyllo' ), get_bloginfo( 'name' ) ) ); ?></p>

			<h3><?php esc_html_e( 'How answers are produced', 'agentyllo' ); ?></h3>
			<ul>
				<?php foreach ( $tiers as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>

			<h3><?php esc_html_e( 'What data is processed', 'agentyllo' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'The messages you type into the chat and the assistant\'s replies.', 'agentyllo' ); ?></li>
				<?php if ( 'off' !== (string) $privacy['registration_gate'] ) : ?>
					<li><?php esc_html_e( 'The name and email address you provide before starting a chat.', 'agentyllo' ); ?></li>
				<?php endif; ?>
				<li><?php echo esc_html( 'none' === (string) $privacy['ip_mode'] ? __( 'Your IP address is not stored.', 'agentyllo' ) : __( 'A one-way hash of your IP address (rotated monthly) for abuse prevention — never the raw address.', 'agentyllo' ) ); ?></li>
				<?php if ( isset( $tiers['paid_ai'] ) ) : ?>
					<li><?php esc_html_e( 'When third-party AI providers are enabled, your message and relevant excerpts of this site\'s public content are sent to that provider to generate the answer.', 'agentyllo' ); ?></li>
				<?php endif; ?>
			</ul>
			<p><?php echo esc_html( 0 === (int) $privacy['retention_days'] ? __( 'Conversations are kept until deleted by the site owner.', 'agentyllo' ) : sprintf( /* translators: %d: number of days */ _n( 'Conversations are deleted automatically after %d day.', 'Conversations are deleted automatically after %d days.', (int) $privacy['retention_days'], 'agentyllo' ), (int) $privacy['retention_days'] ) ); ?></p>

			<h3><?php esc_html_e( 'Limitations', 'agentyllo' ); ?></h3>
			<p><?php esc_html_e( 'The assistant can make mistakes. It only answers questions about this site and its content. Always verify important information (prices, availability, legal or medical matters) through the official channels.', 'agentyllo' ); ?></p>

			<h3><?php esc_html_e( 'Talk to a person / your rights', 'agentyllo' ); ?></h3>
			<p><?php esc_html_e( 'You can ask the assistant to connect you with a human at any time. To access, export or delete the data the assistant holds about you, contact the site owner via the contact details published on this site.', 'agentyllo' ); ?></p>

			<?php if ( '' !== $custom ) : ?>
				<h3><?php esc_html_e( 'Additional information', 'agentyllo' ); ?></h3>
				<?php echo wp_kses_post( wpautop( $custom ) ); ?>
			<?php endif; ?>

			<p class="agy-transparency__updated"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Last updated: %s', 'agentyllo' ), wp_date( get_option( 'date_format' ) ) ) ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
