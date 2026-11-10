<?php
/**
 * derive-root-pubkey.php — print ONLY the Ed25519 public key for a given
 * private key. For reconciling SECAUDIT 028's root-key mismatch: run this on
 * your own machine against your OFFLINE root signing key to learn which public
 * key it derives, then tell the team that public hex (the public half is not
 * secret). It is the value core/updater.php (SNAPSMACK_ROOT_PUBKEY) and
 * smack-central/sc-release.php must both carry.
 *
 * It NEVER writes the secret anywhere, makes NO network calls, and prints
 * nothing but the 64-character public hex. Reads the private key from STDIN so
 * it never lands in your shell history or the process list.
 *
 * USAGE (the key never appears on the command line):
 *   php derive-root-pubkey.php < rootkey.hex
 *   # or, typed/pasted then Ctrl-D (Ctrl-Z Enter on Windows):
 *   php derive-root-pubkey.php
 *
 * Accepts either an Ed25519 secret key (128 hex chars) or a 32-byte seed
 * (64 hex chars). Output: the 64-char public key hex, one line, nothing else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
if (!function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
    fwrite(STDERR, "libsodium not available in this PHP build.\n");
    exit(1);
}

$raw = stream_get_contents(STDIN);
if ($raw === false) {
    fwrite(STDERR, "Could not read the private key from stdin.\n");
    exit(1);
}

// Keep only hex characters; tolerates whitespace, newlines, a trailing prompt.
$hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $raw));
sodium_memzero($raw);

$len = strlen($hex);
if ($len !== 128 && $len !== 64) {
    sodium_memzero($hex);
    fwrite(STDERR, "Expected 128 hex chars (Ed25519 secret key) or 64 (seed); got {$len}.\n");
    exit(1);
}

try {
    if ($len === 128) {
        $sk  = sodium_hex2bin($hex);
        $pub = sodium_crypto_sign_publickey_from_secretkey($sk);
        sodium_memzero($sk);
    } else {
        $seed = sodium_hex2bin($hex);
        $kp   = sodium_crypto_sign_seed_keypair($seed);
        $pub  = sodium_crypto_sign_publickey($kp);
        sodium_memzero($seed);
        sodium_memzero($kp);
    }
} catch (\Throwable $e) {
    sodium_memzero($hex);
    fwrite(STDERR, "Could not derive a public key from that input.\n");
    exit(1);
}
sodium_memzero($hex);

echo sodium_bin2hex($pub) . "\n";
exit(0);

// ===== SNAPSMACK EOF =====
