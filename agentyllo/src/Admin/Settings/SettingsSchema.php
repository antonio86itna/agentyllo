<?php
/**
 * Declarative settings schema, one array per tab.
 *
 * Each field: type (string|text|bool|int|float|enum|secret), default, and
 * constraints (enum values, min/max, maxlen). `secret` values are stored
 * sealed by KeyVault and never returned in clear to the REST surface. The store sanitizes strictly against this
 * schema — unknown keys are dropped, so the REST surface can never write
 * arbitrary options. Later milestones extend tabs here; addons add tabs via
 * the `agyl_settings_tabs` filter.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Admin\Settings;

use Agentyllo\Infra\Crypto\KeyVault;

defined( 'ABSPATH' ) || exit;

/**
 * Schema provider.
 */
final class SettingsSchema {

	public const OPERATING_MODES = array( 'classic', 'free_ai', 'paid_ai', 'classic_free_ai', 'classic_paid_ai' );

	/**
	 * All tab schemas.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function tabs(): array {
		$tabs = array(
			'general'  => array(
				'operating_mode'      => array(
					'type'    => 'enum',
					'values'  => self::OPERATING_MODES,
					'default' => 'classic',
				),
				'assistant_name'      => array(
					'type'    => 'string',
					'default' => '',
					'maxlen'  => 100,
				),
				'site_type_hint'      => array(
					'type'    => 'enum',
					'values'  => array( 'auto', 'blog', 'business', 'shop', 'portfolio', 'docs' ),
					'default' => 'auto',
				),
				'tone'                => array(
					'type'    => 'enum',
					'values'  => array( 'professional', 'friendly', 'playful' ),
					'default' => 'friendly',
				),
				'custom_instructions' => array(
					'type'    => 'text',
					'default' => '',
					'maxlen'  => 2000,
				),
				'out_of_scope_guard'  => array(
					'type'    => 'bool',
					'default' => true,
				),
				'oos_refusal_message' => array(
					'type'    => 'text',
					'default' => '',
					'maxlen'  => 500,
				),
			),
			'sources'  => $this->sources_tab(),
			'widget'   => array(
				'widget_enabled'      => array( 'type' => 'bool', 'default' => true ),
				'position'            => array(
					'type'    => 'enum',
					'values'  => array( 'bottom_right', 'bottom_left' ),
					'default' => 'bottom_right',
				),
				'theme'               => array(
					'type'    => 'enum',
					'values'  => array( 'auto', 'light', 'dark' ),
					'default' => 'auto',
				),
				'primary_color'       => array( 'type' => 'string', 'default' => '#3858e9', 'maxlen' => 7 ),
				'welcome_message'     => array( 'type' => 'text', 'default' => '', 'maxlen' => 300 ),
				'launcher_teaser'     => array( 'type' => 'string', 'default' => '', 'maxlen' => 100 ),
				'show_thumbnails'     => array( 'type' => 'bool', 'default' => true ),
				'show_powered_by'     => array( 'type' => 'bool', 'default' => false ),
				'show_internal_links' => array( 'type' => 'bool', 'default' => true ),
				'animations'          => array( 'type' => 'bool', 'default' => true ),
				'z_index'             => array( 'type' => 'int', 'default' => 99990, 'min' => 1, 'max' => 2147483000 ),
			),
			'language' => array(
				'reply_language_mode' => array(
					'type'    => 'enum',
					'values'  => array( 'site_language', 'visitor_language', 'fixed' ),
					'default' => 'site_language',
				),
				'fixed_locale'        => array( 'type' => 'string', 'default' => '', 'maxlen' => 10 ),
			),
			'models'   => array(
				'chat_provider'          => array(
					'type'    => 'enum',
					'values'  => array( 'none', 'openai', 'anthropic' ),
					'default' => 'none',
				),
				'openai_api_key'         => array( 'type' => 'secret', 'default' => '' ),
				'anthropic_api_key'      => array( 'type' => 'secret', 'default' => '' ),
				'openai_chat_model'      => array( 'type' => 'string', 'default' => '', 'maxlen' => 80 ),
				'anthropic_chat_model'   => array( 'type' => 'string', 'default' => '', 'maxlen' => 80 ),
				'embedding_provider'     => array(
					'type'    => 'enum',
					'values'  => array( 'none', 'openai', 'local' ),
					'default' => 'none',
				),
				'openai_embedding_model' => array( 'type' => 'string', 'default' => '', 'maxlen' => 80 ),
				'monthly_cost_cap_usd'   => array( 'type' => 'float', 'default' => 20.0, 'min' => 0, 'max' => 100000 ),
				'max_output_tokens'      => array( 'type' => 'int', 'default' => 600, 'min' => 100, 'max' => 4000 ),
				'request_timeout_s'      => array( 'type' => 'int', 'default' => 25, 'min' => 8, 'max' => 90 ),
				'registry_auto_sync'     => array( 'type' => 'bool', 'default' => false ),
				// Free / local tiers (M8).
				'local_endpoint_url'     => array( 'type' => 'string', 'default' => '', 'maxlen' => 300 ),
				'local_model'            => array( 'type' => 'string', 'default' => '', 'maxlen' => 120 ),
				'local_api_key'          => array( 'type' => 'secret', 'default' => '' ),
				'local_min_tok_s'        => array( 'type' => 'int', 'default' => 8, 'min' => 1, 'max' => 200 ),
				'local_embedding_model'  => array( 'type' => 'string', 'default' => '', 'maxlen' => 120 ),
				'browser_ai_enabled'     => array( 'type' => 'bool', 'default' => false ),
			),
			'privacy'  => array(
				'registration_gate'         => array(
					'type'    => 'enum',
					'values'  => array( 'off', 'name_email' ),
					'default' => 'off',
				),
				'privacy_checkbox_required' => array( 'type' => 'bool', 'default' => true ),
				'privacy_policy_url'        => array( 'type' => 'string', 'default' => '', 'maxlen' => 500 ),
				'gate_intro_text'           => array( 'type' => 'text', 'default' => '', 'maxlen' => 500 ),
				'privacy_checkbox_label'    => array( 'type' => 'text', 'default' => '', 'maxlen' => 300 ),
				'legal_disclaimer_text'     => array( 'type' => 'text', 'default' => '', 'maxlen' => 300 ),
				'transparency_text'         => array( 'type' => 'text', 'default' => '', 'maxlen' => 2000 ),
				'policy_version'            => array( 'type' => 'string', 'default' => '1', 'maxlen' => 20 ),
				'retention_days'            => array( 'type' => 'int', 'default' => 90, 'min' => 0, 'max' => 3650 ),
				'ip_mode'                   => array(
					'type'    => 'enum',
					'values'  => array( 'none', 'hash', 'truncate' ),
					'default' => 'hash',
				),
				'consent_logging'           => array( 'type' => 'bool', 'default' => true ),
				'pii_redaction'             => array(
					'type'    => 'enum',
					'values'  => array( 'off', 'logs', 'before_ai' ),
					'default' => 'logs',
				),
				'ai_disclosure'             => array( 'type' => 'bool', 'default' => true ),
			),
			'performance' => array(
				'transport'                  => array(
					'type'    => 'enum',
					'values'  => array( 'auto', 'buffered' ),
					'default' => 'auto',
				),
				'rate_limit_session_per_min' => array( 'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 120 ),
				'rate_limit_ip_per_hour'     => array( 'type' => 'int', 'default' => 40, 'min' => 5, 'max' => 1000 ),
				'rate_limit_ip_per_day'      => array( 'type' => 'int', 'default' => 200, 'min' => 10, 'max' => 10000 ),
			),
			'advanced' => array(
				'uninstall_mode' => array(
					'type'    => 'enum',
					'values'  => array( 'keep', 'remove_settings', 'remove_all' ),
					'default' => 'keep',
				),
				'debug_log'      => array(
					'type'    => 'bool',
					'default' => false,
				),
			),
		);

		/**
		 * Filter the settings tab schemas. Addons may add tabs or fields.
		 * Field shape: type|default plus constraints (values, min, max, maxlen).
		 *
		 * @param array $tabs Tab schemas keyed by tab id.
		 */
		return (array) apply_filters( 'agyl_settings_tabs', $tabs );
	}

	/**
	 * Content Sources tab. Toggling any field off must purge its data from
	 * the KB immediately (KB\Indexer\IndexManager listens on
	 * agyl_settings_updated). Public CPTs are enumerated dynamically so new
	 * post types appear without a plugin update.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function sources_tab(): array {
		$fields = array(
			'posts_enabled'         => array( 'type' => 'bool', 'default' => true ),
			'pages_enabled'         => array( 'type' => 'bool', 'default' => true ),
			'menus_enabled'         => array( 'type' => 'bool', 'default' => true ),
			'site_identity_enabled' => array( 'type' => 'bool', 'default' => true ),
			'taxonomies_enabled'    => array( 'type' => 'bool', 'default' => false ),
		);

		if ( did_action( 'init' ) ) {
			$cpts = get_post_types(
				array(
					'public'   => true,
					'_builtin' => false,
				),
				'objects'
			);
			foreach ( $cpts as $cpt ) {
				if ( 'product' === $cpt->name && class_exists( 'WooCommerce' ) ) {
					continue; // Products have their own toggle below.
				}
				$fields[ 'cpt_' . $cpt->name . '_enabled' ] = array(
					'type'    => 'bool',
					'default' => true,
					'label'   => $cpt->labels->name ?? $cpt->name,
				);
			}
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$fields['woocommerce_enabled'] = array( 'type' => 'bool', 'default' => true );
			foreach ( array( 'wc_prices', 'wc_stock', 'wc_attributes', 'wc_variations', 'wc_reviews', 'wc_linked' ) as $mask ) {
				$fields[ $mask ] = array( 'type' => 'bool', 'default' => true );
			}
		}

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$fields['elementor_enabled'] = array( 'type' => 'bool', 'default' => true );
		}

		return $fields;
	}

	/**
	 * Schema for one tab, or null when unknown.
	 *
	 * @param string $tab Tab id.
	 * @return array<string, array<string, mixed>>|null
	 */
	public function tab( string $tab ): ?array {
		$tabs = $this->tabs();

		return $tabs[ $tab ] ?? null;
	}

	/**
	 * Default values for one tab.
	 *
	 * @param string $tab Tab id.
	 * @return array<string, mixed>
	 */
	public function defaults( string $tab ): array {
		$schema = $this->tab( $tab ) ?? array();

		return array_map( static fn ( array $field ): mixed => $field['default'] ?? null, $schema );
	}

	/**
	 * Sanitize a partial value set against a tab schema. Unknown keys are
	 * dropped; invalid values fall back to the field default.
	 *
	 * @param string               $tab    Tab id.
	 * @param array<string, mixed> $values Raw input.
	 * @return array<string, mixed> Sanitized subset.
	 */
	public function sanitize( string $tab, array $values ): array {
		$schema = $this->tab( $tab ) ?? array();
		$clean  = array();

		foreach ( $values as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}
			$clean[ $key ] = $this->sanitize_field( $schema[ $key ], $value );
		}

		return $clean;
	}

	/**
	 * Sanitize a single value against its field definition.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw value.
	 * @return mixed
	 */
	private function sanitize_field( array $field, mixed $value ): mixed {
		$default = $field['default'] ?? null;

		switch ( $field['type'] ?? 'string' ) {
			case 'bool':
				return rest_sanitize_boolean( $value );

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return $default;
				}
				$int = (int) $value;
				if ( isset( $field['min'] ) ) {
					$int = max( (int) $field['min'], $int );
				}
				if ( isset( $field['max'] ) ) {
					$int = min( (int) $field['max'], $int );
				}
				return $int;

			case 'float':
				if ( ! is_numeric( $value ) ) {
					return $default;
				}
				$float = (float) $value;
				if ( isset( $field['min'] ) ) {
					$float = max( (float) $field['min'], $float );
				}
				if ( isset( $field['max'] ) ) {
					$float = min( (float) $field['max'], $float );
				}
				return round( $float, 4 );

			case 'secret':
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				$secret = trim( (string) $value );
				if ( '' === $secret ) {
					return '';
				}
				// Already sealed (re-sanitized on read) → keep; plaintext → seal.
				return str_starts_with( $secret, KeyVault::PREFIX ) ? $secret : ( new KeyVault() )->seal( mb_substr( $secret, 0, 512 ) );

			case 'enum':
				$values = (array) ( $field['values'] ?? array() );
				return in_array( $value, $values, true ) ? $value : $default;

			case 'text':
				if ( ! is_scalar( $value ) ) {
					return $default;
				}
				$text = sanitize_textarea_field( (string) $value );
				return isset( $field['maxlen'] ) ? mb_substr( $text, 0, (int) $field['maxlen'] ) : $text;

			case 'string':
			default:
				if ( ! is_scalar( $value ) ) {
					return $default;
				}
				$text = sanitize_text_field( (string) $value );
				return isset( $field['maxlen'] ) ? mb_substr( $text, 0, (int) $field['maxlen'] ) : $text;
		}
	}

	/**
	 * Keys of secret fields in a tab.
	 *
	 * @param string $tab Tab id.
	 * @return string[]
	 */
	public function secret_keys( string $tab ): array {
		$out = array();
		foreach ( $this->tab( $tab ) ?? array() as $key => $field ) {
			if ( 'secret' === ( $field['type'] ?? '' ) ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * Replace secret values with a masked preview for the REST surface.
	 * Undecryptable values (vault key lost) surface as '!corrupt' so the UI
	 * can ask for re-entry.
	 *
	 * @param string               $tab    Tab id.
	 * @param array<string, mixed> $values Effective values.
	 * @return array<string, mixed>
	 */
	public function redact( string $tab, array $values ): array {
		$vault = null;
		foreach ( $this->secret_keys( $tab ) as $key ) {
			$sealed = (string) ( $values[ $key ] ?? '' );
			if ( '' === $sealed ) {
				$values[ $key ] = '';
				continue;
			}
			$vault ??= new KeyVault();
			$values[ $key ] = $vault->is_corrupt( $sealed ) ? '!corrupt' : $vault->mask( $sealed );
		}

		return $values;
	}

	/**
	 * Public (client-safe) schema description for the admin UI: types,
	 * enum values and defaults — no callables.
	 *
	 * @param string $tab Tab id.
	 * @return array<string, array<string, mixed>>
	 */
	public function describe( string $tab ): array {
		$schema = $this->tab( $tab ) ?? array();
		$out    = array();

		foreach ( $schema as $key => $field ) {
			$out[ $key ] = array_intersect_key(
				$field,
				array_flip( array( 'type', 'values', 'default', 'min', 'max', 'maxlen', 'label' ) )
			);
		}

		return $out;
	}
}
