<?php
/**
 * SNAPSMACK — Regression test for SS-1398 / SS-1400:
 * Live API key must be revoked (or expired) before it can be deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/ohsnap-keys-helpers.php';

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
");

$pdo->exec("
    INSERT INTO snap_ohsnap_keys (id, label, key_type, key_hash, key_prefix, is_active, expires_at)
    VALUES
        (1, 'SYBU live', 'sybu', 'hash1', 'abcd1234', 1, '2099-01-01 00:00:00'),
        (2, 'SUYB expired', 'suyb', 'hash2', 'efgh5678', 1, '2020-01-01 00:00:00');
");

// 1. Deleting active unexpired key MUST be refused (SS-1400)
$d1 = snap_ohsnap_key_delete($pdo, 1, strtotime('2026-11-17 00:00:00'));
kp_assert($d1['ok'] === false && $d1['status'] === 409, 'active key delete must be refused with 409');
kp_assert(count(snap_ohsnap_keys_list($pdo)) === 2, 'active key must still exist after refused delete');

// 2. Revoke then delete MUST succeed
$r1 = snap_ohsnap_key_revoke($pdo, 1);
kp_assert($r1['ok'] === true, 'revoke must succeed');
$d2 = snap_ohsnap_key_delete($pdo, 1, strtotime('2026-11-17 00:00:00'));
kp_assert($d2['ok'] === true, 'delete after revoke must succeed');

// 3. Expired key can also be deleted
$d3 = snap_ohsnap_key_delete($pdo, 2, strtotime('2026-11-17 00:00:00'));
kp_assert($d3['ok'] === true, 'expired key delete must succeed');
kp_assert(count(snap_ohsnap_keys_list($pdo)) === 0, 'all deleted keys removed');

echo "PASS: keys-please-api-regression (all 3 checks)\n";
// ===== SNAPSMACK EOF =====
