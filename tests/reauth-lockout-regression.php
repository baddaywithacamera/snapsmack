<?php
/**
 * SNAPSMACK — Regression test for SS-1399:
 * Step-up TOTP (reauth_verify) server-side attempt budget, lockout, and audit log.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/reauth.php';

function reauth_assert(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$logFile = tempnam(sys_get_temp_dir(), 'ss1399_audit_');
ini_set('error_log', $logFile);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
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

// Known Base32 TOTP secret (20 bytes of 'A' -> base32)
$secret = 'IFBEGRCFIZDUQSKKJNGE2TSPKBIVEU2U';
$password = 'correct-horse-battery-staple';
$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO snap_users (id, password_hash, totp_enabled, totp_secret, user_role) VALUES (7, ?, 1, ?, 'admin')")
    ->execute([$hash, $secret]);

// Compute a valid live TOTP code using core/totp.php helper
$validCode = totp_code($secret);
$wrongCode = ($validCode === '000000') ? '111111' : '000000';

$_SESSION = ['user_id' => 7];
$t0 = time();

// 1. 4 wrong codes are rejected without lockout yet; 5th triggers lockout
for ($i = 1; $i <= 4; $i++) {
    $res = reauth_verify($pdo, $password, $wrongCode, $t0);
    reauth_assert($res['ok'] === false, "attempt {$i} should fail");
    reauth_assert(empty($res['locked']), "attempt {$i} should not lock yet");
}
$res5 = reauth_verify($pdo, $password, $wrongCode, $t0);
reauth_assert($res5['ok'] === false && !empty($res5['locked']), '5th failure must trigger lockout');
reauth_assert(stripos($res5['error'], 'locked') !== false, 'lockout error must be user-friendly');

// 2. Correct password + valid TOTP during lockout MUST be refused
$duringLock = reauth_verify($pdo, $password, $validCode, $t0 + 60);
reauth_assert($duringLock['ok'] === false && !empty($duringLock['locked']), 'valid code during lockout must be refused');

// 3. New session (session budget wiped) MUST NOT reset server-side lockout
$_SESSION = ['user_id' => 7];
$newSessionRes = reauth_verify($pdo, $password, $validCode, $t0 + 120);
reauth_assert($newSessionRes['ok'] === false && !empty($newSessionRes['locked']), 'new session must not bypass server-side lockout');

// 4. After lockout window expires (900s+), valid credentials work again and clear the budget
$afterExpiry = reauth_verify($pdo, $password, $validCode, $t0 + REAUTH_BASE_LOCKOUT_SECONDS + 5);
reauth_assert($afterExpiry['ok'] === true, 'valid code after lockout window expiry must succeed');
reauth_assert((int)$pdo->query("SELECT COUNT(*) FROM snap_rate_limits")->fetchColumn() === 0, 'budget cleared after success');

// 5. Audit log recorded failures, lockout, and blocked-while-locked without leaking secrets
$audit = (string)file_get_contents($logFile);
@unlink($logFile);
reauth_assert(strpos($audit, 'SnapSmack reauth failure uid=7') !== false, 'audit log must record failures');
reauth_assert(strpos($audit, 'SnapSmack reauth lockout uid=7') !== false, 'audit log must record lockout');
reauth_assert(strpos($audit, 'SnapSmack reauth blocked_while_locked uid=7') !== false, 'audit log must record blocked attempts');
reauth_assert(strpos($audit, $password) === false, 'audit log must never contain password');
reauth_assert(strpos($audit, $wrongCode) === false && strpos($audit, $validCode) === false, 'audit log must never contain TOTP codes');

echo "PASS: reauth-lockout-regression (all 5 checks)\n";
// ===== SNAPSMACK EOF =====
