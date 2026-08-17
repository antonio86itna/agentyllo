<?php
$c = \Agentyllo\Plugin::instance()->container();
$router = $c->get( \Agentyllo\AI\ProviderRouter::class );
$store  = $c->get( \Agentyllo\Admin\Settings\SettingsStore::class );
update_option( 'agy_mock_enabled', false );
delete_transient( 'agy_cb_mock' );
delete_transient( 'agy_cb_openai' );
echo "1 no provider:  " . wp_json_encode( $router->status() ) . "\n";
$store->update( 'models', array( 'chat_provider' => 'openai' ) );
echo "2 no key:       " . wp_json_encode( $router->status() ) . "\n";
$store->update( 'models', array( 'openai_api_key' => 'sk-test-not-a-real-key-1234567890' ) );
$stored = $store->get( 'models' );
echo "   stored key sealed? " . ( str_starts_with( (string) $stored['openai_api_key'], 'agyv1:' ) ? 'yes' : 'NO' ) . " masked=" . $c->get( \Agentyllo\Admin\Settings\SettingsSchema::class )->redact( 'models', $stored )['openai_api_key'] . "\n";
echo "3 key present:  " . wp_json_encode( $router->status() ) . "\n";
update_option( 'agy_ai_usage_month', array( 'month' => gmdate( 'Y-m' ), 'cost_usd' => 25.0, 'tokens_in' => 0, 'tokens_out' => 0, 'calls' => 1, 'errors' => 0 ) );
echo "4 cap reached:  " . wp_json_encode( $router->status() ) . "\n";
delete_option( 'agy_ai_usage_month' );
// Empty submission keeps the key; __clear__ removes it.
$store->update( 'models', array( 'openai_api_key' => '' ) );
echo "   empty submit keeps key? " . ( '' !== (string) $store->value( 'models', 'openai_api_key' ) ? 'yes' : 'NO' ) . "\n";
// Real provider call with a fake key → auth error path over the network.
$openai = $router->provider( 'openai' );
echo "5 test_connection(fake key): " . wp_json_encode( $openai->test_connection() ) . "\n";
echo "   circuit: " . wp_json_encode( $c->get( \Agentyllo\AI\Budget\Manager::class )->circuit_state( 'openai' ) ) . "\n";
$store->update( 'models', array( 'openai_api_key' => '__clear__', 'chat_provider' => 'none' ) );
echo "   cleared? " . ( '' === (string) $store->value( 'models', 'openai_api_key' ) ? 'yes' : 'NO' ) . "\n";
update_option( 'agy_mock_enabled', true );
