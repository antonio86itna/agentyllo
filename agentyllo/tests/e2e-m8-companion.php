<?php
$c = \Agentyllo\Plugin::instance()->container();
$store = $c->get( \Agentyllo\Admin\Settings\SettingsStore::class );
echo "tabs: " . implode( ',', $store->tabs() ) . "\n";
echo "local_ai defaults: " . wp_json_encode( $store->get( 'local_ai' ) ) . "\n";
// Simulate a configured managed daemon: use the mock server binary path trick — a fake "engine" script that just runs php -S.
$bin = '/tmp/fake-llama-server.sh';
file_put_contents( $bin, "#!/bin/sh\n# args ignored: -m model --host --port ... ; serve the mock on the given port\nPORT=8756\nwhile [ \"\$1\" != \"\" ]; do if [ \"\$1\" = \"--port\" ]; then PORT=\$2; fi; shift; done\nexec php -S 127.0.0.1:\$PORT /tmp/agy-mock-server.php\n" );
chmod( $bin, 0755 );
file_put_contents( '/tmp/fake-model.gguf', 'gguf' );
$store->update( 'local_ai', array( 'engine_binary' => $bin, 'model_file' => '/tmp/fake-model.gguf', 'port' => 8757, 'auto_start' => true, 'idle_ttl_min' => 1 ) );
$sup = new \AgentylloLocalAI\Supervisor( fn() => $store->get( 'local_ai' ) );
echo "configured: " . ( $sup->configured() ? 'yes' : 'no' ) . " url=" . $sup->url() . "\n";
$err = $sup->start();
echo "start: " . ( '' === $err ? 'ok' : $err ) . "\n";
sleep( 1 );
$st = $sup->status();
echo "status: running=" . ( $st['running'] ? 'y' : 'n' ) . " healthy=" . ( $st['healthy'] ? 'y' : 'n' ) . " pid=" . $st['pid'] . "\n";
// The core provider must now resolve to the managed daemon URL through the filter.
$local = $c->get( \Agentyllo\AI\ProviderRouter::class )->provider( 'local_endpoint' );
echo "core endpoint url via filter: " . $local->base_url() . "\n";
echo "test_connection: " . wp_json_encode( $local->test_connection() ) . "\n";
// Idle check after TTL: fake last_used in the past.
$state = get_option( 'agyl_daemon' ); $state['last_used'] = time() - 120; update_option( 'agyl_daemon', $state );
$sup->idle_check();
echo "after idle_check running=" . ( $sup->status()['running'] ? 'y' : 'n' ) . "\n";
// Cleanup: restore BYO url (mock on 8123) and unset managed daemon.
$store->update( 'local_ai', array( 'managed_daemon' => false ) );
echo "byo url restored: " . $local->base_url() . "\n";
