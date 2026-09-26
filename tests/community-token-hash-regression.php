<?php
/**
 * Community session and email-link tokens are stored as SHA-256, never raw.
 *
 * SECAUDIT 049 found them in plain text; the item was handed to 050 and dropped.
 * Closed 2026-09-26. Also guards the two bugs found with it: the raw 128-char
 * token did not fit its varchar(64) column, and snap_community_tokens lacked the
 * used_at column that verify-email and password-reset read.
 *
 * Runs the real functions against an in-memory SQLite stand-in for the tables.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];
function tok_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

// ── Static checks ────────────────────────────────────────────────────────────
$auth = file_get_contents("$root/community-auth.php");
tok_test(substr_count($auth, 'community_token_hash(') >= 4,
    'community-auth.php does not hash every token it stores and looks up');
tok_test(!preg_match('/execute\(\[\$(new_user_id|user\[.id.\]), \$(verify|reset)_token/', $auth),
    'community-auth.php still stores a raw email-link token');

foreach (['database/schema/snapsmack_canonical.sql', 'migrations/migrate-create-missing-tables.sql'] as $schema) {
    $sql = file_get_contents("$root/$schema");
    $i = strpos($sql, 'CREATE TABLE IF NOT EXISTS `snap_community_tokens`');
    $block = substr($sql, $i, strpos($sql, ') ENGINE', $i) - $i);
    tok_test(strpos($block, '`used_at`') !== false, "$schema: snap_community_tokens has no used_at column");
}
tok_test(strpos(file_get_contents("$root/core/updater.php"), "'migrate-community-token-hash.sql'") !== false,
    'purge migration is not registered in UPDATER_KNOWN_MIGRATIONS');

// ── Live: the real session functions against a stand-in database ────────────
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP live part: pdo_sqlite not available\n");
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $now = fn() => date('Y-m-d H:i:s');
    method_exists($pdo, 'createFunction') ? $pdo->createFunction('NOW', $now, 0)
                                          : @$pdo->sqliteCreateFunction('NOW', $now, 0);
    $pdo->exec("CREATE TABLE snap_settings (setting_key TEXT, setting_val TEXT)");
    $pdo->exec("CREATE TABLE snap_community_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT,
        email TEXT, avatar_url TEXT, bio TEXT, email_verified INT, status TEXT, last_seen_at TEXT)");
    $pdo->exec("CREATE TABLE snap_community_sessions (id INTEGER PRIMARY KEY, user_id INT,
        token VARCHAR(64) NOT NULL CHECK (length(token) <= 64), expires_at TEXT, ip TEXT, user_agent TEXT)");
    $pdo->exec("INSERT INTO snap_community_users VALUES (7, 'ray', 'Ray', 'r@example.test', '', '', 1, 'active', NULL)");

    // community-session.php sends cookies; capture instead of emitting headers.
    if (!function_exists('snap_is_https')) { function snap_is_https(): bool { return true; } }
    ob_start();
    require_once "$root/core/community-session.php";
    $raw = @community_login(7);
    ob_end_clean();

    $stored = $pdo->query("SELECT token FROM snap_community_sessions")->fetchColumn();
    tok_test(strlen($raw) === 128, 'raw token is not the 128-char random value');
    tok_test($stored === hash('sha256', $raw), 'session row does not hold the SHA-256 of the cookie token');
    tok_test($stored !== $raw && strpos($stored, substr($raw, 0, 32)) === false, 'raw token material is in the database');

    $_COOKIE[COMMUNITY_COOKIE_NAME] = $raw;
    $user = @community_current_user();
    tok_test(is_array($user) && (int)$user['id'] === 7, 'a valid cookie no longer logs the member in');

    $_COOKIE[COMMUNITY_COOKIE_NAME] = $stored;   // a stolen database row, replayed
    $replayed = @community_current_user();
    tok_test($replayed === null, 'the stored hash can be replayed as a login cookie');

    $_COOKIE[COMMUNITY_COOKIE_NAME] = $raw;
    @community_logout();
    tok_test((int)$pdo->query("SELECT COUNT(*) FROM snap_community_sessions")->fetchColumn() === 0,
        'logout did not remove the hashed session row');
}

if ($failures) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS: community tokens stored as SHA-256 (sessions + email links)\n";

// ===== SNAPSMACK EOF =====
