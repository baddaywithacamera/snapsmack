<?php
/**
 * SECAUDIT 055 (0.7.641D) regression — shipped dev directories + the SMACKBACK
 * baseline-trust gap. Guards every layer of the fix:
 *   packagers exclude + tripwire, updater removal list + hub-push cleanup,
 *   SMACKBACK never-trust prefixes (baseline refusal, prune, DEV DIRS bucket),
 *   htaccess web-deny, admin panel surfacing.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root     = dirname(__DIR__);
$screl    = file_get_contents($root . '/smack-central/sc-release.php');
$bip      = file_get_contents($root . '/tools/_build/build-install-package.php');
$updater  = file_get_contents($root . '/core/updater.php');
$msapi    = file_get_contents($root . '/core/multisite-api.php');
$sb       = file_get_contents($root . '/core/smackback.php');
$panel    = file_get_contents($root . '/smack-back.php');
$hta      = file_get_contents($root . '/core/htaccess-template');
$help     = file_get_contents($root . '/smack-help.php');

$fail = 0;
function sa_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

// ── Stop the bleed: release packager ────────────────────────────────────────
sa_test(str_contains($screl, "'tests/',") && str_contains($screl, "'wip/',") && str_contains($screl, "'core/tests/',"),
    'sc-release always_exclude carries tests/, wip/ and core/tests/');
sa_test(str_contains($screl, 'BUILD REFUSED — dev path'),
    'sc-release hard-fails the build if a dev path reaches the final zip (tripwire)');
sa_test(str_contains($screl, "@unlink(\$zip_dest);   // never leave a poisoned half-built package behind"),
    'a refused build deletes the half-built package');

// ── Stop the bleed: install-package builder ─────────────────────────────────
foreach (['tests', 'wip', 'core/tests', 'smack-central'] as $d) {
    sa_test(preg_match("#^\\s*'" . preg_quote($d, '#') . "',#m", $bip) === 1,
        "build-install-package excludes {$d}");
}

// ── Self-clean going forward: updater removal list ──────────────────────────
foreach (["'tests'", "'core/tests'", "'smack-central'"] as $d) {
    sa_test(preg_match('/' . preg_quote($d, '/') . "\\s*=>\\s*'0\\.7\\.641D'/", $updater) === 1,
        "UPDATER_DEPRECATED_DIRS carries {$d} as of 0.7.641D");
}
sa_test(str_contains($updater, "in_array(\$rel_path, ['tests', 'core/tests', 'smack-central'], true)")
     && str_contains($updater, "is_file(\$root . '/smack-central/sc-config.php')"),
    'the SC host itself is exempt from the 055 removals (would delete the packager)');

// ── Self-clean going forward: hub-push path runs the scoped cleanup ─────────
sa_test(str_contains($msapi, 'updater_remove_known_orphans($release_info'),
    'the hub-push update path runs the scoped whitelist cleanup (055 reverses 029 in scoped form)');
$p_clean = strpos($msapi, 'updater_remove_known_orphans($release_info');
$p_base  = strpos($msapi, 'smackback_init_from_disk() && ') ?: strpos($msapi, 'smackback_init_from_disk()');
sa_test($p_clean !== false && $p_base !== false && $p_clean < $p_base,
    'cleanup runs BEFORE the SMACKBACK re-baseline — the baseline captures the CLEANED tree');

// ── SMACKBACK: never-trust prefixes ─────────────────────────────────────────
sa_test(str_contains($sb, "function smackback_dev_dir_prefixes(): array"),
    'smackback_dev_dir_prefixes() exists');
sa_test(str_contains($sb, "return ['tests/', 'core/tests/', 'wip/', 'smack-central/'];"),
    'all four dev prefixes are on the never-trust list');
sa_test(preg_match('/function smackback_dev_dir_prefixes[^}]+sc-config\.php/s', $sb) === 1,
    'the never-trust list is empty on the Smack Central host itself');
sa_test(str_contains($sb, 'function smackback_prune_dev_dir_rows(): int'),
    'laundered baseline rows for dev dirs get pruned');
sa_test(preg_match('/if \(smackback_is_dev_dir\(\$rel\)\) \{\s*\n\s*continue;\s*\n\s*\}\s*\n\s*\$hash    = hash_file/s', $sb) === 1,
    'init_from_disk refuses to bless dev-dir files — the exact laundering hole');
sa_test(preg_match('/if \(smackback_is_dev_dir\(\$path\)\) \{\s*\n\s*continue;\s*\n\s*\}/s', $sb) === 1,
    'init_manifest refuses dev-dir rows even if a package carries them');
sa_test(str_contains($sb, "\$dev_dirs[] = \$rel;"),
    'verify_all routes dev-dir files to their own DEV DIRS bucket');
sa_test(str_contains($sb, "'dev_dirs'   => \$dev_dirs,"),
    'verify_all returns the dev_dirs list');
sa_test(preg_match('/\$any_bad\s*=\s*!empty\(\$tampered\)[^;]*;/s', $sb) === 1
     && strpos((preg_match('/\$any_bad\s*=\s*(!empty[^;]*);/s', $sb, $m) ? $m[1] : ''), 'dev_dirs') === false,
    'dev-dir presence is NOT a breach — it must never lock out the whole fleet at once');
sa_test(!preg_match("/^\\s*'wip\\/',\\s*$/m", substr($sb, 0, strpos($sb, 'function smackback_dev_dir_prefixes')))
     || preg_match('/\$excluded_dirs\[\] = \'wip\/\';/', $sb) === 1,
    'wip/ is no longer a blanket monitoring blind spot (only excluded on the SC host)');

// ── Web layer ────────────────────────────────────────────────────────────────
sa_test(str_contains($hta, 'RewriteRule ^(tests|wip|smack-central|core/tests)(/|$) - [R=404,L]'),
    'htaccess template 404s the dev-dir subtrees (propagates via updater_reconcile_htaccess)');

// ── Operator surfacing ──────────────────────────────────────────────────────
sa_test(str_contains($panel, 'DEV DIRECTORIES ON THIS INSTALL'),
    'SMACK-BACK panel shows a DEV DIRECTORIES box when the folders exist');
sa_test(str_contains($panel, "count(\$result['dev_dirs'] ?? [])"),
    'verify result message reports the dev-dir count');
sa_test(str_contains($help, 'Dev Directories (never trusted)'),
    'help explains the never-trusted dev directories');

echo $fail === 0 ? "ALL PASS\n" : ("{$fail} FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
