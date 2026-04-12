<?php
/**
 * SNAPSMACK - Release Signing Utility
 *
 * Command-line tool for signing release packages. Run this on your local
 * dev machine (never on the web server). It takes a zip file, generates
 * the SHA-256 checksum, signs it with your Ed25519 secret key, and outputs
 * everything you need for latest.json on the update server.
 *
 * USAGE:
 *   php sign-release.php <zipfile> [--version X.X] [--key <secret_key_hex>]
 *
 * EXAMPLES:
 *   php sign-release.php snapsmack-0.8.zip --version 0.8
 *   php sign-release.php snapsmack-0.8.zip --version 0.8 --key abc123...
 *
 * If --key is not provided, you will be prompted for it (safer — keeps
 * the key out of your shell history).
 *
 * OUTPUT:
 *   Prints the JSON fields you need to paste into latest.json on your
 *   update server at https://snapsmack.ca/releases/latest.json
 *
 * REQUIREMENTS:
 *   - PHP 8.0+ with libsodium (built-in)
 *   - Your Ed25519 secret key (128 hex characters)
 *
 * INSTALL PHP ON WINDOWS:
 *   1. Download from https://windows.php.net/download/ (VS16 x64 Thread Safe)
 *   2. Extract to C:\php
 *   3. Add C:\php to your system PATH
 *   4. Copy php.ini-development to php.ini
 *   5. Uncomment: extension=sodium
 *   6. Test: php -v
 */

// ─── REQUIREMENTS CHECK ─────────────────────────────────────────────────────

if (php_sapi_name() !== 'cli') {
    die("This tool must be run from the command line.\n");
}

if (!function_exists('sodium_crypto_sign_detached')) {
    die("ERROR: libsodium is required but not available.\n"
      . "Enable it in php.ini: extension=sodium\n");
}

// ─── ARGUMENT PARSING ────────────────────────────────────────────────────────

$args = parse_args($argv);

if (empty($args['file'])) {
    echo "SNAPSMACK RELEASE SIGNING UTILITY\n";
    echo "─────────────────────────────────\n\n";
    echo "Usage: php sign-release.php <zipfile> [options]\n\n";
    echo "Options:\n";
    echo "  --version X.X    Version number for this release\n";
    echo "  --key <hex>      Ed25519 secret key (128 hex chars)\n";
    echo "                   If omitted, you'll be prompted (recommended)\n\n";
    echo "Example:\n";
    echo "  php sign-release.php snapsmack-0.8.zip --version 0.8\n\n";
    exit(0);
}

$zip_file = $args['file'];
$version  = $args['version'] ?? null;

if (!file_exists($zip_file)) {
    die("ERROR: File not found: {$zip_file}\n");
}

// ─── VERSION ─────────────────────────────────────────────────────────────────

if (!$version) {
    echo "Version number (e.g. 0.8): ";
    $version = trim(fgets(STDIN));
    if (!$version) die("ERROR: Version is required.\n");
}

// ─── SECRET KEY ──────────────────────────────────────────────────────────────

$secret_hex = $args['key'] ?? null;

if (!$secret_hex) {
    echo "Ed25519 secret key (128 hex chars): ";
    // Try to hide input on supported systems
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

// Validate hex
if (!ctype_xdigit($secret_hex)) {
    die("ERROR: Secret key contains non-hex characters.\n");
}

// ─── SIGN ────────────────────────────────────────────────────────────────────

echo "\nSigning release...\n";
echo "─────────────────\n";

// Step 1: SHA-256 checksum
$checksum = hash_file('sha256', $zip_file);
echo "  Checksum (SHA-256): {$checksum}\n";

// Step 2: File size
$filesize = filesize($zip_file);
echo "  Package size:       " . number_format($filesize) . " bytes (" . round($filesize / 1048576, 2) . " MB)\n";

// Step 3: Sign the checksum
try {
    $secret_key = sodium_hex2bin($secret_hex);
    $signature  = sodium_bin2hex(sodium_crypto_sign_detached($checksum, $secret_key));
    echo "  Signature:          {$signature}\n";

    // Step 4: Verify our own signature using the embedded public key
    $public_key = sodium_crypto_sign_publickey_from_secretkey($secret_key);
    $public_hex = sodium_bin2hex($public_key);
    $verified   = sodium_crypto_sign_verify_detached(
        sodium_hex2bin($signature),
        $checksum,
        $public_key
    );
    echo "  Self-verify:        " . ($verified ? 'PASSED' : 'FAILED') . "\n";
    echo "  Public key:         {$public_hex}\n";

    if (!$verified) {
        die("\nERROR: Signature failed self-verification. Something is wrong.\n");
    }

} catch (\SodiumException $e) {
    die("\nERROR: Signing failed — " . $e->getMessage() . "\n");
}

// ─── OUTPUT ──────────────────────────────────────────────────────────────────

$download_url = "https://snapsmack.ca/releases/snapsmack-{$version}.zip";

echo "\n";
echo "LATEST.JSON TEMPLATE\n";
echo "════════════════════\n";
echo "Copy the JSON below into your update server at:\n";
echo "https://snapsmack.ca/releases/latest.json\n\n";

$json = [
    'version'         => $version,
    'version_full'    => "Alpha {$version}",
    'released'        => date('Y-m-d'),
    'download_url'    => $download_url,
    'checksum_sha256' => $checksum,
    'signature'       => $signature,
    'download_size'   => $filesize,
    'requires_php'    => '8.0',
    'requires_mysql'  => '5.7',
    'schema_changes'  => false,
    'changelog'       => [
        'Change 1 — EDIT THIS',
        'Change 2 — EDIT THIS',
    ],
    'file_changes'    => [
        'added'    => [],
        'modified' => [],
        'removed'  => [],
    ],
];

echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

echo "\nREMINDERS:\n";
echo "  1. Edit the changelog and file_changes arrays\n";
echo "  2. Set schema_changes to true if this version includes migrations\n";
echo "  3. Upload the zip to: {$download_url}\n";
echo "  4. Upload the JSON to: https://snapsmack.ca/releases/latest.json\n";
echo "  5. Test by clicking CHECK FOR UPDATES in SnapSmack admin\n";
echo "\nDone.\n";

// Clear sensitive data from memory
sodium_memzero($secret_key);
$secret_hex = str_repeat('0', 128);


// ─── HELPERS ─────────────────────────────────────────────────────────────────

function parse_args(array $argv): array {
    $result = ['file' => null, 'version' => null, 'key' => null];

    $skip_next = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($skip_next) {
            $skip_next = false;
            continue;
        }

        $arg = $argv[$i];

        if ($arg === '--version' && isset($argv[$i + 1])) {
            $result['version'] = $argv[$i + 1];
            $skip_next = true;
        } elseif ($arg === '--key' && isset($argv[$i + 1])) {
            $result['key'] = $argv[$i + 1];
            $skip_next = true;
        } elseif (!str_starts_with($arg, '-') && $result['file'] === null) {
            $result['file'] = $arg;
        }
    }

    return $result;
}
