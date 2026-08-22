<?php
/**
 * Real-key smoke-test setup (issue #5). Run via:
 *   wp eval-file tests/e2e-realkey-setup.php <openai_key> <anthropic_key> <provider>
 * Keys are passed as arguments and sealed by the KeyVault — never stored here.
 *
 * @package Agentyllo
 */

$openai    = (string) ( $args[0] ?? '' );
$anthropic = (string) ( $args[1] ?? '' );
$provider  = (string) ( $args[2] ?? 'openai' );

$container = Agentyllo\Plugin::instance()->container();
$store     = $container->get( Agentyllo\Admin\Settings\SettingsStore::class );

$models = array(
	'chat_provider'        => $provider,
	'monthly_cost_cap_usd' => 20.0,
	'max_output_tokens'    => 300,
);
if ( '' !== $openai ) {
	$models['openai_api_key'] = $openai;
}
if ( '' !== $anthropic ) {
	$models['anthropic_api_key'] = $anthropic;
}
$store->update( 'models', $models );
$store->update( 'general', array( 'operating_mode' => 'classic_paid_ai' ) );

// Sanity: the sealed key must reveal back to the original.
$vault  = $container->get( Agentyllo\Infra\Crypto\KeyVault::class );
$sealed = (string) $store->value( 'models', 'openai' === $provider ? 'openai_api_key' : 'anthropic_api_key' );
$plain  = 'openai' === $provider ? $openai : $anthropic;
echo 'provider=' . $provider . ' sealed=' . ( str_starts_with( $sealed, 'agyv1:' ) ? 'yes' : 'NO' ) . ' roundtrip=' . ( $vault->open( $sealed ) === $plain ? 'ok' : 'FAIL' ) . "\n";

// Key test endpoint (the "Test" button in AI Models).
wp_set_current_user( 1 );
$req = new WP_REST_Request( 'POST', '/agentyllo/v1/models/test' );
$req->set_body_params( array( 'provider' => $provider ) );
$res = rest_do_request( $req );
echo 'models/test: ' . wp_json_encode( $res->get_data() ) . "\n";
