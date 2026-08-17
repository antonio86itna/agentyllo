<?php
/**
 * Agentyllo registry signing tool (NOT shipped with the plugin).
 *
 * Usage:
 *   php tools/registry/sign.php keygen                    # one-time: creates keys/agentyllo-registry.{secret,public}
 *   php tools/registry/sign.php sign path/to/stable.json  # writes stable.json.sig (base64 detached Ed25519)
 *   php tools/registry/sign.php verify path/to/stable.json
 *
 * Deploy stable.json + stable.json.sig under https://registry.agentyllo.com/v1/.
 * The PUBLIC key (base64) must match Agentyllo\Infra\Crypto\Ed25519Verifier::PUBLIC_KEY.
 */

declare( strict_types=1 );

$keys_dir = __DIR__ . '/keys';
$secret   = $keys_dir . '/agentyllo-registry.secret';
$public   = $keys_dir . '/agentyllo-registry.public';
$cmd      = $argv[1] ?? '';

switch ( $cmd ) {
	case 'keygen':
		if ( file_exists( $secret ) ) {
			fwrite( STDERR, "Refusing to overwrite existing secret key: {$secret}\n" );
			exit( 1 );
		}
		if ( ! is_dir( $keys_dir ) ) {
			mkdir( $keys_dir, 0700, true );
		}
		$pair = sodium_crypto_sign_keypair();
		file_put_contents( $secret, base64_encode( sodium_crypto_sign_secretkey( $pair ) ) );
		file_put_contents( $public, base64_encode( sodium_crypto_sign_publickey( $pair ) ) );
		chmod( $secret, 0600 );
		echo "Public key (base64):\n" . file_get_contents( $public ) . "\n";
		break;

	case 'sign':
		$file = $argv[2] ?? '';
		if ( ! is_file( $file ) || ! is_file( $secret ) ) {
			fwrite( STDERR, "Need a payload file and an existing secret key.\n" );
			exit( 1 );
		}
		$sk  = base64_decode( trim( (string) file_get_contents( $secret ) ), true );
		$sig = sodium_crypto_sign_detached( (string) file_get_contents( $file ), $sk );
		file_put_contents( $file . '.sig', base64_encode( $sig ) );
		echo "Wrote {$file}.sig\n";
		break;

	case 'verify':
		$file = $argv[2] ?? '';
		$pk   = base64_decode( trim( (string) file_get_contents( $public ) ), true );
		$sig  = base64_decode( trim( (string) file_get_contents( $file . '.sig' ) ), true );
		$ok   = sodium_crypto_sign_verify_detached( $sig, (string) file_get_contents( $file ), $pk );
		echo $ok ? "OK\n" : "INVALID\n";
		exit( $ok ? 0 : 2 );

	default:
		fwrite( STDERR, "Usage: sign.php keygen | sign <file> | verify <file>\n" );
		exit( 1 );
}
