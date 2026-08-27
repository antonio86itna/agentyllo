<?php
/**
 * Real-key smoke-test diagnostics: settings state, breaker, direct provider call.
 *
 * @package Agentyllo
 */

$container = Agentyllo\Plugin::instance()->container();
$store     = $container->get( Agentyllo\Admin\Settings\SettingsStore::class );

echo 'chat_provider=' . wp_json_encode( $store->value( 'models', 'chat_provider' ) )
	. ' mode=' . wp_json_encode( $store->value( 'general', 'operating_mode' ) ) . "\n";
echo 'cb_anthropic=' . wp_json_encode( get_transient( 'agyl_cb_anthropic' ) )
	. ' cb_openai=' . wp_json_encode( get_transient( 'agyl_cb_openai' ) ) . "\n";

$provider = $container->get( Agentyllo\AI\Providers\AnthropicProvider::class );
$result   = $provider->complete(
	new Agentyllo\AI\Contracts\ChatRequest(
		array(
			array(
				'role'    => 'user',
				'content' => 'Say the word PING and nothing else.',
			),
		),
		'probe',
		16
	)
);
echo 'direct anthropic: ok=' . (int) $result->ok . ' model=' . $result->model
	. ' text=' . substr( $result->text, 0, 40 ) . ' err=' . wp_json_encode( $result->error ) . "\n";

global $wpdb;
echo 'latest journal error: ' . wp_json_encode(
	$wpdb->get_row( "SELECT message, created_at FROM {$wpdb->prefix}agyl_agent_journal WHERE level='error' ORDER BY id DESC LIMIT 1", ARRAY_A )
) . "\n";
echo 'latest inference: ' . wp_json_encode(
	$wpdb->get_row( "SELECT provider, model, ok, error FROM {$wpdb->prefix}agyl_inference_log ORDER BY id DESC LIMIT 1", ARRAY_A )
) . "\n";
