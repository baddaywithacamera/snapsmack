<?php
/**
 * SECAUDIT 054 A1 — server-side key-scope enforcement regression (0.7.649D).
 * The server independently refuses an out-of-scope key: EVERY query that
 * authenticates a Bearer key against snap_ohsnap_keys must also constrain
 * key_type, and the central authorizer must refuse when no types are declared.
 * This test is the "remember-to-check" killer: a future endpoint that looks a
 * key up without scoping it fails the suite.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$fail = 0;
function ks_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

// ── 1. Sweep every PHP file: a key-hash lookup must carry a key_type filter ──
// We scan each SQL string that references snap_ohsnap_keys AND key_hash; each
// such query must constrain key_type in the same statement. (Non-auth uses —
// minting, listing, revoking — don't match the key_hash condition.)
$files = array_merge(glob($root . '/core/*.php'), glob($root . '/*.php'));
$unscoped = [];
foreach ($files as $f) {
    $src = file_get_contents($f);
    if (strpos($src, 'snap_ohsnap_keys') === false) continue;
    // Examine each double-quoted SQL chunk containing a key_hash predicate.
    if (preg_match_all('/"[^"]*snap_ohsnap_keys[^"]*key_hash\s*=\s*\?[^"]*"/s', $src, $m)) {
        foreach ($m[0] as $sql) {
            if (stripos($sql, 'key_type') === false) {
                $unscoped[] = basename($f) . ': ' . substr(preg_replace('/\s+/', ' ', $sql), 0, 90);
            }
        }
    }
}
ks_test($unscoped === [],
    'every Bearer key lookup constrains key_type in the same query'
    . ($unscoped ? ' — UNSCOPED: ' . implode(' | ', $unscoped) : ''));

// ── 2. Central authorizer: declared-types-or-nothing ────────────────────────
$auth = file_get_contents($root . '/core/api-auth.php');
ks_test(str_contains($auth, "\$_allowed_types = \$GLOBALS['SNAP_API_KEY_TYPES'] ?? [];")
     && str_contains($auth, 'if (is_array($_allowed_types) && $_allowed_types) {'),
    'central authorizer offers Bearer auth ONLY when the endpoint declares its accepted key types');
ks_test(substr_count($auth, "key_type IN (\$_place)") >= 2,
    'central authorizer scopes both its normal and legacy-schema lookup');
ks_test(str_contains($auth, 'fail closed'),
    'central authorizer fails closed on lookup errors');

// ── 3. Per-surface scoping stays put (the 054/636D boundaries) ───────────────
$gyss = file_get_contents($root . '/core/gyss-api.php');
ks_test(str_contains($gyss, "key_type IN ('gyss','hub')"),
    'GYSS accepts only gyss + hub key types');
ks_test(preg_match("/\\(\\\$api_key_row\\['key_type'\\] \\?\\? ''\\) === 'hub'/", $gyss) === 1,
    'hub-type keys stay route-restricted inside the GYSS surface (0.7.636D boundary)');
foreach ([['core/ohsnap-api.php', "key_type = 'ohsnap'"],
          ['core/tyswy-api.php', "key_type = 'tyswy'"],
          ['core/smackpress-api.php', "key_type = 'smackpress'"],
          ['core/flkrfckr-api.php', "key_type = 'flkrfckr'"]] as [$file, $needle]) {
    ks_test(str_contains(file_get_contents($root . '/' . $file), $needle),
        basename($file) . ' scopes to its own key type');
}

echo $fail === 0 ? "ALL PASS\n" : ("{$fail} FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
