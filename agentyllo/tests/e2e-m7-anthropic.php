<?php
$c = \Agentyllo\Plugin::instance()->container();
$store = $c->get( \Agentyllo\Admin\Settings\SettingsStore::class );
$store->update( 'models', array( 'anthropic_api_key' => 'sk-ant-test-not-real-0000' ) );
$p = $c->get( \Agentyllo\AI\ProviderRouter::class )->provider( 'anthropic' );
echo "available=" . ( $p->is_available() ? 'yes' : 'no' ) . " caps=" . wp_json_encode( $p->capabilities() ) . " model=" . $p->model()['id'] . "\n";
$req = new \Agentyllo\AI\Contracts\ChatRequest( array( array( 'role' => 'user', 'content' => 'Say OK' ) ), 'probe', 8, null, 'classify', '', null, 15.0 );
$deltas = 0;
$res = $p->stream( $req, function ( $d ) use ( &$deltas ) { $deltas++; return true; } );
echo "stream(fake key): ok=" . ( $res->ok ? 'y' : 'n' ) . " error=" . $res->error . " status-latency=" . $res->latency_ms . "ms deltas=$deltas\n";
$store->update( 'models', array( 'anthropic_api_key' => '__clear__' ) );
echo "http streaming capable: " . ( $c->get( \Agentyllo\Infra\Http\StreamingClient::class )->supports_streaming() ? 'yes' : 'no' ) . "\n";
