<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * A RECOVERY KIT CARRIES NO LIVE FLEET KEY. SS-1412.
 *
 * redactSecrets() replaced api_key_local and api_key_remote on
 * snap_multisite_nodes and missed api_key_backup -- 32 random bytes generated
 * at core/multisite-api.php:194, which the hub presents for backup-only pulls
 * and which core/multisite-api.php:405 accepts as authentication. So every
 * Recovery Kit taken from a hub carried a live spoke credential in clear text,
 * inside the one file whose whole purpose is to be copied somewhere else and
 * kept for years.
 *
 * THE HAND-WRITTEN LIST WAS THE DEFECT, not the one missing entry. This test
 * is therefore written against the TABLE, not against three column names: it
 * reads the canonical schema, finds every column on snap_multisite_nodes
 * whose name looks like a secret, and requires each one to come out redacted.
 * A key column added tomorrow fails this without anyone remembering the
 * function exists.
 *
 * AND THE OTHER DIRECTION, which matters just as much: snap_users password
 * hashes must survive. A kit that restores a site nobody can log into is not
 * a backup. The assertions below pin both edges.
 *
 * Executed, not read: redactSecrets() is private, so it is called through a
 * reflection handle on a real instance.
 */

$root = dirname(__DIR__);
$failures = [];

require_once $root . '/core/export-engine.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// The constructor reads snap_settings for the origin stamp, so the table has
// to exist before the object does.
$pdo->exec("CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)");
$pdo->exec("INSERT INTO snap_settings (setting_key, setting_val)
            VALUES ('site_mode','photoblog'), ('site_url','https://example.test')");
$exporter = new SnapSmackExport($pdo, $root);

$method = new ReflectionMethod(SnapSmackExport::class, 'redactSecrets');
$redact = static fn(string $table, array $row): array => $method->invoke($exporter, $table, $row);

// ── 1. THE COLUMNS THE SCHEMA ACTUALLY HAS ───────────────────────────────
// Read from the canonical schema so this cannot drift from what ships.
$schema = (string)@file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
if ($schema === '') {
    throw new RuntimeException('could not read the canonical schema');
}
$at = strpos($schema, 'CREATE TABLE `snap_multisite_nodes`');
if ($at === false) {
    $at = strpos($schema, 'snap_multisite_nodes');
}
if ($at === false) {
    throw new RuntimeException('snap_multisite_nodes is not in the canonical schema');
}
$block = substr($schema, $at, 4000);
$end = strpos($block, 'CREATE TABLE', 20);
if ($end !== false) {
    $block = substr($block, 0, $end);
}
preg_match_all('/^\s*`([a-z0-9_]+)`/im', $block, $m);
$columns = array_values(array_unique($m[1] ?? []));
if (count($columns) < 6) {
    throw new RuntimeException('only ' . count($columns) . ' columns were parsed '
        . 'from snap_multisite_nodes, so this test is not reading the schema '
        . 'properly rather than the table being tiny');
}

$looksSecret = static function (string $name): bool {
    $n = strtolower($name);
    foreach (['_key', '_token', '_secret', 'password', '_pass', '_salt', 'api_key',
              'apikey', 'client_secret', 'bearer', 'private_key'] as $frag) {
        if (str_contains($n, $frag)) return true;
    }
    return false;
};

$secretColumns = array_values(array_filter($columns, $looksSecret));
if (!in_array('api_key_backup', $secretColumns, true)) {
    $failures[] = 'api_key_backup is not among the secret-looking columns found '
        . 'in the schema, so the very column SS-1412 is about is not being '
        . 'checked. Either the schema changed or the parse is wrong.';
}
if (count($secretColumns) < 3) {
    $failures[] = 'only ' . count($secretColumns) . ' secret-looking columns '
        . 'were found on snap_multisite_nodes; expected at least the three '
        . 'api_key_* ones';
}

// ── 2. EVERY ONE OF THEM COMES OUT REDACTED ──────────────────────────────
$row = ['id' => 4, 'role' => 'hub', 'site_url' => 'https://example.test',
        'site_name' => 'A site', 'status' => 'active'];
foreach ($secretColumns as $c) {
    $row[$c] = 'live-value-' . $c;
}
$out = $redact('snap_multisite_nodes', $row);
foreach ($secretColumns as $c) {
    if (($out[$c] ?? null) !== '__REDACTED__') {
        $failures[] = "snap_multisite_nodes.{$c} left the Recovery Kit as "
            . var_export($out[$c] ?? null, true) . ' instead of __REDACTED__. '
            . 'That is a live fleet credential in a file made to be copied '
            . 'somewhere else and kept.';
    }
}

// ── 3. NOTHING ELSE ON THE ROW IS DAMAGED ────────────────────────────────
// A restore needs the node's identity. Over-redacting would break rejoining
// just as surely as under-redacting leaks.
foreach (['role' => 'hub', 'site_url' => 'https://example.test',
          'site_name' => 'A site', 'status' => 'active'] as $k => $v) {
    if (($out[$k] ?? null) !== $v) {
        $failures[] = "snap_multisite_nodes.{$k} was altered to "
            . var_export($out[$k] ?? null, true) . '; only secrets may change';
    }
}
// An empty column stays empty rather than becoming the word REDACTED, which
// would read as "there is a key here" on a node that has none.
$empty = $redact('snap_multisite_nodes',
    ['api_key_backup' => '', 'api_key_local' => null, 'site_url' => 'x']);
// Written with array_key_exists, not ??: a null value makes ?? fall through to
// the default, so the first version of this assertion failed against correct
// code and would have had me "fixing" the redaction to satisfy a broken test.
if (($empty['api_key_backup'] ?? null) !== ''
    || !array_key_exists('api_key_local', $empty)
    || $empty['api_key_local'] !== null) {
    $failures[] = 'an empty key column was filled in with __REDACTED__, which '
        . 'claims a credential exists where none does';
}

// ── 4. PASSWORD HASHES SURVIVE. A kit must still restore a usable site. ──
$users = $redact('snap_users', ['id' => 1, 'username' => 'sean',
    'password_hash' => '$2y$10$abcdefghijklmnopqrstuv', 'totp_secret' => 'ABCDEF']);
if (($users['password_hash'] ?? '') !== '$2y$10$abcdefghijklmnopqrstuv') {
    $failures[] = 'snap_users.password_hash was redacted. A Recovery Kit that '
        . 'restores a site nobody can log into is not a backup -- the hashes '
        . 'are the reason the kit itself must be protected, not redacted.';
}

// ── 5. THE snap_settings RULE STILL HOLDS ────────────────────────────────
$setting = $redact('snap_settings', ['setting_key' => 'hub_api_key',
    'setting_val' => 'live-secret']);
if (($setting['setting_val'] ?? '') !== '__REDACTED__') {
    $failures[] = 'a sensitive snap_settings value is no longer redacted';
}
$plain = $redact('snap_settings', ['setting_key' => 'site_name',
    'setting_val' => 'Bad Day With A Camera']);
if (($plain['setting_val'] ?? '') !== 'Bad Day With A Camera') {
    $failures[] = 'an ordinary setting was redacted';
}

// ── 6. THE FIX IS GENERIC, NOT THREE MORE NAMES ──────────────────────────
// Checked in source because the behaviour above would also pass a list that
// happens to name today's columns -- and that list is exactly what failed.
$src = (string)php_strip_whitespace($root . '/core/export-engine.php');
if (preg_match("/\[\s*'api_key_local'\s*,\s*'api_key_remote'/", $src)) {
    $failures[] = 'the node redaction is back to a hand-written column list. '
        . 'That list is what missed api_key_backup; the next column added will '
        . 'be missed the same way.';
}
if (!str_contains($src, 'array_keys($row)')) {
    $failures[] = 'the node redaction no longer walks the row\'s own columns, '
        . 'so a new secret column would not be covered automatically';
}

if ($failures) {
    throw new RuntimeException("A Recovery Kit can carry a live fleet key:\n  - "
        . implode("\n  - ", $failures));
}

printf("Recovery Kit redaction: %d secret column(s) on snap_multisite_nodes "
    . "redacted, identity and password hashes intact: passed.\n",
    count($secretColumns));
// ===== SNAPSMACK EOF =====
