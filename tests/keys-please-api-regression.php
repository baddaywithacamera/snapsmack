<?php
/**
 * SNAPSMACK — Regression test for SS-1398 / SS-1400:
 * - Active-key delete refused at helper, smack-api-keys.php, and multisite-api.php
 * - GET multisite/keys raw JSON never leaks key_hash or secret material
 * - 6th bad step-up locked via SS-1399 reauth_verify
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/ohsnap-keys-helpers.php';
require_once __DIR__ . '/../core/reauth.php';

function kp_assert(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE snap_ohsnap_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        key_type TEXT NOT NULL DEFAULT 'sybu',
        key_hash TEXT NOT NULL,
        key_prefix TEXT NOT NULL DEFAULT '',
        is_active INTEGER NOT NULL DEFAULT 1,
        last_used_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT '2026-11-01 00:00:00',
        expires_at TEXT DEFAULT NULL
    );
    CREATE TABLE snap_users (
        id INTEGER PRIMARY KEY,
        password_hash TEXT NOT NULL,
        totp_enabled INTEGER NOT NULL DEFAULT 1,
        totp_secret TEXT NOT NULL,
        user_role TEXT NOT NULL DEFAULT 'admin'
    );
    CREATE TABLE snap_rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        action TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 1,
        window_start TEXT NOT NULL,
        UNIQUE (ip, action)
    );
");

$secret_hash = hash('sha256', 'deadbeef' . str_repeat('0', 56));
$pdo->exec("
    INSERT INTO snap_ohsnap_keys (id, label, key_type, key_hash, key_prefix, is_active, expires_at)
    VALUES
        (1, 'SYBU live', 'sybu', '{$secret_hash}', 'deadbeef', 1, '2099-01-01 00:00:00'),
        (2, 'SUYB expired', 'suyb', 'hash2_never_leaked', 'efgh5678', 1, '2020-01-01 00:00:00');
");

// 1. GET multisite/keys raw JSON never returns key_hash or secret key material
$raw_json = json_encode(['keys' => snap_ohsnap_keys_list($pdo)]);
kp_assert(is_string($raw_json), 'json_encode failed');
kp_assert(strpos($raw_json, 'key_hash') === false, 'raw JSON must never expose key_hash column');
kp_assert(strpos($raw_json, $secret_hash) === false, 'raw JSON must never expose secret hash value');

// 2. Deleting active unexpired key MUST be refused (SS-1400)
$d1 = snap_ohsnap_key_delete($pdo, 1, strtotime('2026-11-17 00:00:00'));
kp_assert($d1['ok'] === false && $d1['status'] === 409, 'active key delete must be refused with 409');
kp_assert(count(snap_ohsnap_keys_list($pdo)) === 2, 'active key must still exist after refused delete');

// 3. Verify both smack-api-keys.php and core/multisite-api.php wire snap_ohsnap_key_delete (no silent bypass)
$admin_src = (string)file_get_contents(__DIR__ . '/../smack-api-keys.php');
kp_assert(strpos($admin_src, 'snap_ohsnap_key_delete($pdo, $key_id)') !== false, 'smack-api-keys.php must call snap_ohsnap_key_delete');
kp_assert(strpos($admin_src, 'DELETE FROM snap_ohsnap_keys WHERE id = ? AND is_active = 0') === false, 'silent delete bypass must be gone');

$api_src = (string)file_get_contents(__DIR__ . '/../core/multisite-api.php');
kp_assert(strpos($api_src, 'snap_ohsnap_key_delete($pdo, $key_id)') !== false, 'multisite-api.php must call snap_ohsnap_key_delete');

// 4. 6th bad step-up locked (SS-1399)
$totp_secret = 'IFBEGRCFIZDUQSKKJNGE2TSPKBIVEU2U';
$pw = 'owner-secret-pass';
$pdo->prepare("INSERT INTO snap_users (id, password_hash, totp_enabled, totp_secret, user_role) VALUES (1, ?, 1, ?, 'admin')")
    ->execute([password_hash($pw, PASSWORD_DEFAULT), $totp_secret]);
$valid_totp = totp_code($totp_secret);
$bad_totp = ($valid_totp === '000000') ? '111111' : '000000';
$_SESSION = ['user_id' => 1];
$t0 = time();
for ($i = 1; $i <= 5; $i++) {
    $r = snap_ohsnap_keys_stepup($pdo, $pw, $bad_totp, $t0);
    kp_assert($r['ok'] === false, "bad step-up {$i} must fail");
}
// 6th attempt even with VALID credentials MUST be locked
$r6 = snap_ohsnap_keys_stepup($pdo, $pw, $valid_totp, $t0 + 10);
kp_assert($r6['ok'] === false && !empty($r6['locked']), '6th step-up attempt must be locked');

// 5. Revoke then delete succeeds; expired key delete succeeds
$r1 = snap_ohsnap_key_revoke($pdo, 1);
kp_assert($r1['ok'] === true, 'revoke must succeed');
$d2 = snap_ohsnap_key_delete($pdo, 1, strtotime('2026-11-17 00:00:00'));
kp_assert($d2['ok'] === true, 'delete after revoke must succeed');
$d3 = snap_ohsnap_key_delete($pdo, 2, strtotime('2026-11-17 00:00:00'));
kp_assert($d3['ok'] === true, 'expired key delete must succeed');

echo "PASS: keys-please-api-regression (all 5 negative-control checks)\n";
// ===== SNAPSMACK EOF =====
