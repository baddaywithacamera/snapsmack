<?php
/**
 * SNAPSMACK — smack-vax.php  (Emergency DB Injection Tool)
 *
 * Delivers and executes signed SQL payloads from Smack Central without
 * requiring FTP or a full release package. Works through SMACKBACK lockdown
 * because this file is already tracked — no new/modified files hit the
 * filesystem on the spoke side.
 *
 * Usage:
 *   POST https://yoursite.com/core/smack-vax.php?pkg=CODE
 *   Body (application/x-www-form-urlencoded): token=TOKEN
 *
 * Flow:
 *   1. Rate-limit check (3 failures → 1-hour lockout)
 *   2. Fetch payload + detached sig from SC over HTTPS
 *   3. Verify Ed25519 signature against SNAPSMACK_RELEASE_PUBKEY
 *   4. Extract one-time token from payload header; constant-time compare
 *   5. Check pkg not already consumed; check expiry
 *   6. Execute SQL
 *   7. Mark pkg consumed; reset fail counter; log
 *
 * Payload format (.vax file — plaintext):
 *   VAX-PKG: {code}
 *   VAX-TOKEN: {hex, 32–128 chars}
 *   VAX-EXPIRES: {unix timestamp, 0 = no expiry}
 *   ----
 *   SQL statements...
 *
 * The .vax.sig file is a detached Ed25519 signature (hex) of the .vax content.
 * SC creates both files; vax.php fetches them from:
 *   https://snapsmack.ca/releases/vax/{pkg}.vax
 *   https://snapsmack.ca/releases/vax/{pkg}.vax.sig
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

header('Content-Type: text/plain; charset=utf-8');

// ── Must be POST ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("405 Method Not Allowed\n");
}

// ── pkg from URL ─────────────────────────────────────────────────────────────

$pkg = trim($_GET['pkg'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $pkg)) {
    http_response_code(400);
    exit("400 Bad pkg.\n");
}

// ── Bootstrap ────────────────────────────────────────────────────────────────

require_once __DIR__ . '/db.php';       // sets $pdo
require_once __DIR__ . '/updater.php';  // provides SNAPSMACK_RELEASE_PUBKEY

// ── Helpers ──────────────────────────────────────────────────────────────────

function _vax_get(PDO $pdo, string $key, string $default = ''): string {
    $s = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = ? LIMIT 1");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return ($v !== false && $v !== '') ? (string)$v : $default;
}

function _vax_set(PDO $pdo, string $key, string $val): void {
    $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    )->execute([$key, $val]);
}

function _vax_fail(PDO $pdo, int $current_count): void {
    $new = $current_count + 1;
    _vax_set($pdo, 'vax_fail_count', (string)$new);
    if ($new >= 3) {
        _vax_set($pdo, 'vax_lockout_until', (string)(time() + 3600));
    }
}

function _vax_fetch(string $url): ?string {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 10,
            'header'  => "User-Agent: SnapSmack-Vax/1.0\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $result = @file_get_contents($url, false, $ctx);
    return ($result === false) ? null : $result;
}

// ── Rate limit check ─────────────────────────────────────────────────────────

$fail_count    = (int)_vax_get($pdo, 'vax_fail_count',    '0');
$lockout_until = (int)_vax_get($pdo, 'vax_lockout_until', '0');

if ($lockout_until > time()) {
    $mins = (int)ceil(($lockout_until - time()) / 60);
    http_response_code(429);
    exit("429 Endpoint locked after repeated failures. Try again in {$mins} minute(s).\n");
}

// ── Token from POST body ─────────────────────────────────────────────────────

$token = trim($_POST['token'] ?? '');
if (!preg_match('/^[a-fA-F0-9]{32,128}$/', $token)) {
    _vax_fail($pdo, $fail_count);
    http_response_code(403);
    exit("403 Invalid token format.\n");
}

// ── sodium available? ────────────────────────────────────────────────────────

if (!extension_loaded('sodium')) {
    http_response_code(500);
    exit("500 sodium extension required.\n");
}

// ── Signing key installed? ───────────────────────────────────────────────────

if (SNAPSMACK_RELEASE_PUBKEY === str_repeat('0', 64)) {
    http_response_code(500);
    exit("500 No signing key installed. Refusing to run unsigned payloads.\n");
}

// ── Fetch payload and signature from SC ─────────────────────────────────────

define('VAX_SC_BASE', 'https://snapsmack.ca/releases/vax/');

$payload_url = VAX_SC_BASE . rawurlencode($pkg) . '.vax';
$sig_url     = VAX_SC_BASE . rawurlencode($pkg) . '.vax.sig';

$payload_raw = _vax_fetch($payload_url);
$sig_raw     = _vax_fetch($sig_url);

if ($payload_raw === null || $sig_raw === null) {
    http_response_code(502);
    exit("502 Could not fetch payload from SC. Check pkg code or SC availability.\n");
}

// ── Verify Ed25519 signature ─────────────────────────────────────────────────

try {
    $pubkey = sodium_hex2bin(SNAPSMACK_RELEASE_PUBKEY);
    $sig    = sodium_hex2bin(trim($sig_raw));
    $valid  = sodium_crypto_sign_verify_detached($sig, $payload_raw, $pubkey);
} catch (SodiumException $e) {
    $valid = false;
}

if (!$valid) {
    _vax_fail($pdo, $fail_count);
    http_response_code(403);
    exit("403 Signature verification failed.\n");
}

// ── Parse payload header ─────────────────────────────────────────────────────

$lines     = explode("\n", str_replace("\r\n", "\n", $payload_raw));
$headers   = [];
$sql_lines = [];
$in_sql    = false;

foreach ($lines as $line) {
    if ($in_sql) {
        $sql_lines[] = $line;
        continue;
    }
    if (rtrim($line) === '----') {
        $in_sql = true;
        continue;
    }
    if (preg_match('/^VAX-([A-Z-]+):\s*(.+)$/', rtrim($line), $m)) {
        $headers[$m[1]] = trim($m[2]);
    }
}

$payload_pkg     = $headers['PKG']     ?? '';
$payload_token   = $headers['TOKEN']   ?? '';
$payload_expires = (int)($headers['EXPIRES'] ?? 0);
$sql             = trim(implode("\n", $sql_lines));

// ── Validate pkg match ───────────────────────────────────────────────────────

if ($payload_pkg !== $pkg) {
    _vax_fail($pdo, $fail_count);
    http_response_code(403);
    exit("403 pkg mismatch — payload does not match requested code.\n");
}

// ── Expiry check ─────────────────────────────────────────────────────────────

if ($payload_expires > 0 && time() > $payload_expires) {
    http_response_code(410);
    exit("410 Payload has expired.\n");
}

// ── Token compare (constant-time) ────────────────────────────────────────────

if (!hash_equals($payload_token, $token)) {
    _vax_fail($pdo, $fail_count);
    http_response_code(403);
    exit("403 Token mismatch.\n");
}

// ── Replay guard ─────────────────────────────────────────────────────────────

if (_vax_get($pdo, 'vax_consumed_' . $pkg) === 'yes') {
    http_response_code(409);
    exit("409 Payload already consumed.\n");
}

// ── SQL sanity ───────────────────────────────────────────────────────────────

if (empty($sql)) {
    http_response_code(422);
    exit("422 Payload contains no SQL.\n");
}

// ── Execute ──────────────────────────────────────────────────────────────────

try {
    $pdo->exec($sql);
} catch (PDOException $e) {
    http_response_code(500);
    exit("500 SQL execution failed: " . $e->getMessage() . "\n");
}

// ── Commit state ─────────────────────────────────────────────────────────────

_vax_set($pdo, 'vax_consumed_' . $pkg, 'yes');
_vax_set($pdo, 'vax_fail_count',       '0');
_vax_set($pdo, 'vax_lockout_until',    '0');

// Log — record timestamp + pkg in snap_settings as audit trail
// snap_smackback_log is for file integrity events; not appropriate here
_vax_set($pdo, 'vax_last_pkg',  $pkg);
_vax_set($pdo, 'vax_last_at',   date('Y-m-d H:i:s'));

echo "VAX OK — pkg={$pkg} injected and consumed.\n";

// ===== SNAPSMACK EOF =====
