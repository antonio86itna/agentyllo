<?php
// wp eval-file: run one message through the pipeline and dump AI meta.
$c = \Agentyllo\Plugin::instance()->container();
$router = $c->get( \Agentyllo\AI\ProviderRouter::class );
echo "router status: " . wp_json_encode( $router->status() ) . "\n";
echo "providers: " . implode( ',', array_keys( $router->providers() ) ) . "\n";
echo "chain: " . implode( ',', array_map( fn( $p ) => $p->id(), $router->chain() ) ) . "\n";
$ctx = new \Agentyllo\Chat\Pipeline\ChatContext( 1, ( $args[0] ?? $argv[1] ?? null ) ?? 'how much does shipping cost?', get_locale() );
$ctx = $c->get( \Agentyllo\Chat\Pipeline\Pipeline::class )->run( $ctx );
$meta = $ctx->meta;
unset( $meta['ai_sources_text'], $meta['events'], $meta['timings'] );
echo "intent={$ctx->intent} route={$ctx->route} chunks=" . count( $ctx->chunks ) . "\n";
echo "meta: " . wp_json_encode( $meta, JSON_PRETTY_PRINT ) . "\n";
echo "blocks: " . wp_json_encode( $ctx->blocks ) . "\n";
