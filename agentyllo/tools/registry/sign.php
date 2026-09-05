<?php
/**
 * Registry signing tool.
 *
 * Signs assets/registry/stable.json with the project's Ed25519 private key and
 * writes a detached base64 signature. The keypair lives in tools/registry/keys/
 * (gitignored). If no keypair exists yet, one is generated and its public key
 * is printed — pin that value in src/Infra/Crypto/Ed25519Verifier::PUBLIC_KEY.
 *
 * Usage:  php tools/registry/sign.php [path/to/stable.json] [path/to/out.sig]
 *
 * @package Agentyllo
 */

$root    = dirname( __DIR__, 2 );
$keydir  = __DIR__ . '/keys';
$secfile = $keydir . '/ed25519.secret';
$pubfile = $keydir . '/ed25519.public';

if ( ! is_dir( $keydir ) ) {
	mkdir( $keydir, 0700, true );
}

if ( ! file_exists( $secfile ) ) {
	$pair   = sodium_crypto_sign_keypair();
	$secret = sodium_crypto_sign_secretkey( $pair );
	$public = sodium_crypto_sign_publickey( $pair );
	file_put_contents( $secfile, base64_encode( $secret ) );
	file_put_contents( $pubfile, base64_encode( $public ) );
	echo "Generated a new keypair.\n";
	echo "PUBLIC KEY (pin in Ed25519Verifier::PUBLIC_KEY):\n" . base64_encode( $public ) . "\n";
}

$secret = base64_decode( trim( (string) file_get_contents( $secfile ) ), true );
$public = base64_decode( trim( (string) file_get_contents( $pubfile ) ), true );

$in  = $argv[1] ?? ( $root . '/assets/registry/stable.json' );
$out = $argv[2] ?? ( $root . '/tests/fixtures/stable.json.sig' );

$payload = (string) file_get_contents( $in );
$sig     = sodium_crypto_sign_detached( $payload, $secret );
file_put_contents( $out, base64_encode( $sig ) );

echo "Signed: {$in}\n  -> {$out}\n";
echo "PUBLIC KEY: " . base64_encode( $public ) . "\n";
