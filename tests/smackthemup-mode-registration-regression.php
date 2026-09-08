<?php
/**
 * SNAPSMACK — SMACKTHEMUP install-mode registration + federation-off regression
 *
 * Slice 1 of the SMACKTHEMUP build (spec: _spec/SPEC-smackthemup-public-album-blog-mode-v1_2.md).
 * Locks the safe backbone: the mode is registered centrally, it has NO browser
 * composer (desktop-only publishing), and it is non-federated at the server level
 * regardless of the fediverse_enabled flag (spec §5.3 — the two federation
 * negatives, at the gate).
 *
 * Run: php tests/smackthemup-mode-registration-regression.php
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
require $root . '/core/app-mode.php';
require $root . '/core/mode-guard.php';
require $root . '/core/fediverse.php';

$fail = 0;
$ok = function (bool $v, string $m) use (&$fail): void {
    echo ($v ? "ok   " : "FAIL ") . $m . "\n";
    if (!$v) { $fail++; }
};

// --- Central mode registration -------------------------------------------------
$ok(snapsmack_app_mode(['site_mode' => 'smackthemup']) === 'smackthemup',
    'snapsmack_app_mode recognises smackthemup');
$ok(snapsmack_app_mode(['site_mode' => 'carousel']) === 'carousel',
    'snapsmack_app_mode still recognises carousel');
$ok(snapsmack_app_mode(['site_mode' => 'bogus']) === 'photoblog',
    'unknown mode still falls back to photoblog');
$ok(str_contains(snap_mode_label('smackthemup'), 'SMACKTHEMUP'),
    'snap_mode_label has a SMACKTHEMUP label');

// --- Desktop-only publishing: NO browser composer (spec §4.1, §5.1) ------------
$ok(snapsmack_mode_has_web_composer(['site_mode' => 'smackthemup']) === false,
    'smackthemup has no web composer (desktop/SNAP SLAPPER only)');
$ok(snapsmack_mode_has_web_composer(['site_mode' => 'carousel']) === true,
    'carousel still has a web composer (unaffected)');
$ok(snapsmack_mode_has_web_composer(['site_mode' => 'photoblog']) === true,
    'photoblog still has a web composer (unaffected)');

// --- Federation OFF as a mode rule, not a setting (spec §5.3) -------------------
$ok(sv_enabled(['site_mode' => 'smackthemup', 'fediverse_enabled' => '1']) === false,
    'federation-out negative: sv_enabled is FALSE for smackthemup even with the flag ON');
$ok(sv_enabled(['site_mode' => 'smackthemup', 'fediverse_enabled' => '0']) === false,
    'smackthemup federation off with flag off too');
$ok(sv_enabled(['site_mode' => 'carousel', 'fediverse_enabled' => '1']) === true,
    'other modes still federate when their flag is on (unaffected)');
$ok(sv_enabled(['site_mode' => 'carousel', 'fediverse_enabled' => '0']) === false,
    'other modes still respect the flag when off');

// --- Installer + router wiring (source-level, mirrors existing fed tests) -------
$install = (string)file_get_contents($root . '/install.php');
$ok(str_contains($install, "value=\"smackthemup\""),
    'installer offers the SMACKTHEMUP radio card');
$ok(str_contains($install, '>4.0<') && strpos($install, 'SMACKTHEMUP') !== false,
    'installer shows SMACKTHEMUP as version 4.0');
$ok(str_contains($install, "'photoblog', 'carousel', 'smacktalk', 'smackthemup'"),
    'installer accepts smackthemup as a posted site_mode');

$fedrouter = (string)file_get_contents($root . '/fediverse.php');
$ok(str_contains($fedrouter, "=== 'smackthemup'"),
    'public federation router hard-404s smackthemup (belt-and-suspenders)');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
// ===== SNAPSMACK EOF =====
