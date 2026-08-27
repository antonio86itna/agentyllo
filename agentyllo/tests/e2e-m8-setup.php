<?php
$c = \Agentyllo\Plugin::instance()->container();
$store = $c->get( \Agentyllo\Admin\Settings\SettingsStore::class );
update_option( 'agyl_mock_enabled', false );
$store->update( 'general', array( 'operating_mode' => 'free_ai' ) );
$store->update( 'models', array( 'local_endpoint_url' => 'http://127.0.0.1:8123', 'local_model' => '', 'embedding_provider' => 'local', 'local_min_tok_s' => 8 ) );
delete_option( \Agentyllo\AI\Budget\Manager::OPTION_EMA );
$router = $c->get( \Agentyllo\AI\ProviderRouter::class );
echo "status: " . wp_json_encode( $router->status() ) . "\n";
$local = $router->provider( 'local_endpoint' );
echo "test_connection: " . wp_json_encode( $local->test_connection() ) . "\n";
$emb = $c->get( \Agentyllo\AI\EmbeddingRouter::class );
echo "embedding active: " . ( $emb->active() ? $emb->active()->id() : 'none' ) . " model_key=" . $emb->model_key() . "\n";
$run = $c->get( \Agentyllo\KB\Indexer\VectorIndexer::class )->run();
echo "embed run: " . wp_json_encode( $run ) . " model_key now=" . $emb->model_key() . " count=" . $c->get( \Agentyllo\KB\Retrieval\VectorStore::class )->count( $emb->model_key() ) . "\n";
