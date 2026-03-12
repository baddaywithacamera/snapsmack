<?php
/**
 * SNAPSMACK - Registry Signing Utility
 * Alpha v0.7.3
 *
 * Adds Ed25519 signatures to each skin entry in registry.json.
 * For each skin: SHA-256 the zip → sign with Ed25519 → store hex sig.
 * Reuses the signing logic from tools/sign-release.php.
 *
 * USAGE:
 *   php sign-registry.php --registry registry.json --packages-dir DIR
 *   php sign-registry.php --registry registry.json --packages-dir DIR --key <hex>
 *
 * EXAMPLES:
 *   php sign-registry.php --registry registry.json --packages-dir ./packages/
 *
 * If --key is not provided, you will be prompted (safer — keeps it out
 * of shell history).
 *
 * REQUIREMENTS:
 *   - PHP 8.0+ with libsodium (built-in)
 *   - Your Ed25519 secret key (128 hex characters)
 */

// ─── REQUIREMENTS CHECK ─────────────────────────────────────────────────────

if (php_sapi_name() !== 'cli') {
    die("This tool must be run from the command line.\n");
}

if (!function_exists('sodium_crypto_sign_detached')) {
    die("ERROR: libsodium is required but not available.\n"
      . "Enable it in php.ini: extension=sodium\n");
}

// ─── ARGUMENT PARSING ───────────────────────────────────────────────────────

$args = parse_args($argv);

if (!$args['registry'] || !$args['packages-dir']) {
    echo "SNAPSMACK REGISTRY SIGNING UTILITY\n";
    echo "──────────────────────────────────\n\n";
    echo "Usage:\n";
    echo "  php sign-registry.php --registry FILE --packages-dir DIR [--key HEX]\n\n";
    echo "Options:\n";
    echo "  --registry FILE      Path to registry.json\n";
    echo "  --packages-dir DIR   Directory containing skin zip files\n";
    echo "  --key HEX            Ed25519 secret key (128 hex chars)\n";
    echo "                       If omitted, you'll be prompted (recommended)\n\n";
    echo "Example:\n";
    echo "  php sign-registry.php --registry registry.json --packages-dir ./packages/\n\n";
    exit(0);
}

$registry_file = $args['registry'];
$packages_dir  = rtrim($args['packages-dir'], '/');

if (!file_exists($registry_file)) {
    die("ERROR: Registry file not found: {$registry_file}\n");
}

if (!is_dir($packages_dir)) {
    die("ERROR: Packages directory not found: {$packages_dir}\n");
}

// ─── LOAD REGISTRY ──────────────────────────────────────────────────────────

$json_raw = file_get_contents($registry_file);
$registry = json_decode($json_raw, true);

if (!$registry || !isset($registry['skins'])) {
    die("ERROR: Invalid registry JSON or missing 'skins' key.\n");
}

// ─── SECRET KEY ─────────────────────────────────────────────────────────────

$secret_hex = $args['key'] ?? null;

if (!$secret_hex) {
    echo "Ed25519 secret key (128 hex chars): ";
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && function_exists('shell_exec')) {
        shell_exec('stty -echo 2>/dev/null');
        $secret_hex = trim(fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        echo "\n";
    } else {
        $secret_hex = trim(fgets(STDIN));
    }
}

if (strlen($secret_hex) !== 128) {
    die("ERROR: Secret key must be 128 hex characters. Got " . strlen($secret_hex) . ".\n");
}

if (!ctype_xdigit($secret_hex)) {
    die("ERROR: Secret key contains non-hex characters.\n");
}

$secret_key = sodium_hex2bin($secret_hex);

// ─── SIGN EACH SKIN ────────────────────────────────────────────────────────

echo "\nSigning skin packages...\n";
echo "────────────────────────\n";

$signed  = 0;
$skipped = 0;
$errors  = 0;

foreach ($registry['skins'] as $slug => &$entry) {
    $version  = $entry['version'] ?? '1.0';
    $zip_name = "{$slug}-{$version}.zip";
    $zip_path = $packages_dir . '/' . $zip_name;

    if (!file_exists($zip_path)) {
        echo "  SKIP  {$slug} — {$zip_name} not found\n";
        $skipped++;
        continue;
    }

    // SHA-256 the zip
    $checksum = hash_file('sha256', $zip_path);

    // Sign the checksum with Ed25519
    try {
        $sig_hex = sodium_bin2hex(
            sodium_crypto_sign_detached($checksum, $secret_key)
        );

        // Verify immediately
        $public_key = sodium_crypto_sign_publickey_from_secretkey($secret_key);
        $verified = sodium_crypto_sign_verify_detached(
            sodium_hex2bin($sig_hex),
            $checksum,
            $public_key
        );

        if (!$verified) {
            echo "  FAIL  {$slug} — signature failed self-verification\n";
            $errors++;
            continue;
        }

        $entry['signature'] = $sig_hex;
        $entry['download_size'] = filesize($zip_path);
        $signed++;

        echo "  SIGN  {$slug} v{$version} ✓\n";

    } catch (\SodiumException $e) {
        echo "  FAIL  {$slug} — " . $e->getMessage() . "\n";
        $errors++;
    }
}
unset($entry); // break reference

// ─── UPDATE TIMESTAMP ───────────────────────────────────────────────────────

$registry['generated'] = gmdate('Y-m-d\TH:i:s\Z');

// ─── WRITE BACK ─────────────────────────────────────────────────────────────

$json_out = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($registry_file, $json_out . "\n");

// ─── SUMMARY ────────────────────────────────────────────────────────────────

echo "\n";
echo "Signed {$signed} skin(s)";
if ($skipped > 0) echo ", {$skipped} skipped";
if ($errors > 0) echo ", {$errors} error(s)";
echo ".\n";

$public_hex = sodium_bin2hex(sodium_crypto_sign_publickey_from_secretkey($secret_key));
echo "Public key: {$public_hex}\n";
echo "Registry updated: {$registry_file}\n";

// Clear sensitive data
sodium_memzero($secret_key);
$secret_hex = str_repeat('0', 128);

exit($errors > 0 ? 1 : 0);


// ─── HELPERS ────────────────────────────────────────────────────────────────

function parse_args(array $argv): array {
    $result = ['registry' => null, 'packages-dir' => null, 'key' => null];

    $skip_next = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($skip_next) { $skip_next = false; continue; }

        $arg = $argv[$i];

        if ($arg === '--registry' && isset($argv[$i + 1])) {
            $result['registry'] = $argv[$i + 1];
            $skip_next = true;
        } elseif ($arg === '--packages-dir' && isset($argv[$i + 1])) {
            $result['packages-dir'] = $argv[$i + 1];
            $skip_next = true;
        } elseif ($arg === '--key' && isset($argv[$i + 1])) {
            $result['key'] = $argv[$i + 1];
            $skip_next = true;
        }
    }

    return $result;
}
