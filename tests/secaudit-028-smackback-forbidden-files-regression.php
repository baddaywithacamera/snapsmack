<?php
/**
 * SECAUDIT 028 regression — SMACKBACK must never baseline a forbidden (leak/
 * secret-shaped) file, must surface it, and must let the operator remove it.
 *
 * The incident: a leaked root sc-config.php was baselined by PRESENCE
 * (init_from_disk hashes whatever .php is in the webroot), so deleting it read
 * as a MISSING breach and LOCKED the site. This guards the fix: forbidden files
 * are refused at baseline time (both init paths), pruned from any baseline, and
 * routed to a non-locking FORBIDDEN bucket at verify time; since they are never
 * baselined, the admin REMOVE accepts them.
 *
 * Functional for the classifier (smackback_is_forbidden); static for the wiring
 * (the same lightweight pattern as the SECAUDIT 055 dev-dirs regression).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$fail = 0;
function sa028f(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    if (!$ok) $fail++;
}

// ── Functional: load smackback.php and exercise the classifier ───────────────
// SNAPSMACK_ROOT points at a temp dir with NO smack-central/sc-config.php, so the
// forbidden rules are active (they go empty on the Smack Central host).
$tmp = sys_get_temp_dir() . '/sa028-forbidden-' . bin2hex(random_bytes(5));
@mkdir($tmp, 0777, true);
if (!defined('SNAPSMACK_ROOT'))        define('SNAPSMACK_ROOT', $tmp);
if (!defined('SNAPSMACK_DIAGLOG_OFF')) define('SNAPSMACK_DIAGLOG_OFF', true);
require_once $root . '/core/smackback.php';

sa028f(function_exists('smackback_is_forbidden'), 'smackback_is_forbidden() is defined');

// Forbidden shapes → TRUE
foreach ([
    'sc-config.php',
    'site.env', '.env',
    'server.pem', 'private.key', 'cert.p12', 'bundle.pfx',
    'db-dump.bak',
    'backups/anything.php', 'backups/2026.sql',
] as $rel) {
    sa028f(smackback_is_forbidden($rel) === true, "forbidden: {$rel}");
}

// Legitimate files → FALSE (must NOT be flagged)
foreach ([
    'core/db.php',                        // per-install config: legit, must stay
    'index.php',
    'core/updater.php',
    'migrations/migrate-posts-title-text.sql', // migrations legitimately ship
    'database/schema/snapsmack_canonical.sql',
    'smack-central/sc-config.php',        // the hub's own copy (dir handled as dev-dir)
    'assets/js/ss-engine-community.js',
] as $rel) {
    sa028f(smackback_is_forbidden($rel) === false, "not forbidden: {$rel}");
}

@rmdir($tmp);

// ── Static: the wiring that keeps forbidden files out of the baseline ────────
$sb = (string) file_get_contents($root . '/core/smackback.php');

sa028f(str_contains($sb, 'function smackback_forbidden_rules')
    && str_contains($sb, 'function smackback_prune_forbidden_rows'),
    'forbidden-rules and prune helpers exist');

// init_from_disk and init_manifest both refuse forbidden files at baseline time.
sa028f(str_contains($sb, 'if (smackback_is_forbidden($rel)) {'),
    'init_from_disk skips forbidden files at baseline time');
sa028f(str_contains($sb, 'if (smackback_is_forbidden($path)) {'),
    'init_manifest skips forbidden files at baseline time');

// verify_all prunes forbidden rows and exposes a forbidden bucket (non-locking).
sa028f(str_contains($sb, 'smackback_prune_forbidden_rows();'),
    'verify_all prunes forbidden rows before scanning');
sa028f(str_contains($sb, "'forbidden'  => \$forbidden,"),
    'verify_all returns the forbidden bucket');
// Non-locking: forbidden is NOT part of the breach ($any_bad) set.
sa028f(preg_match('/\$any_bad\s*=.*?;/s', $sb, $m) === 1 && strpos($m[0], 'forbidden') === false,
    'forbidden files are non-locking (not in the breach set)');

if ($fail) {
    fwrite(STDERR, "\n{$fail} FAILURE(S)\n");
    exit(1);
}
echo "\nSECAUDIT 028 SMACKBACK forbidden-files: all checks passed\n";
exit(0);

// ===== SNAPSMACK EOF =====
