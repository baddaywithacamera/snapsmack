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
// SS-1197 INVERTED THESE TWO, DELIBERATELY. They asserted that the installer
// OFFERS SMACKTHEMUP and ACCEPTS it as a posted site_mode. Both were true, and
// that was the defect: a mode nobody has tested was selectable by a new owner
// on the one screen where the choice shapes their whole site and is not casual
// to undo. A green run here was proving the unwanted behaviour.
// Everything above this line still stands -- SMACKTHEMUP is a real mode, core
// recognises it, it has a label, no web composer and federation off. What
// changed is only whether the INSTALLER may offer it.
$ok(!str_contains($install, "value=\"smackthemup\""),
    'installer does NOT offer a SMACKTHEMUP radio (SS-1197)');
$ok(!str_contains($install, "'photoblog', 'carousel', 'smacktalk', 'smackthemup'"),
    'installer does NOT accept smackthemup as a posted site_mode (SS-1197)');
$ok(str_contains($install, '>4.0<') && strpos($install, 'SMACKTHEMUP') !== false,
    'installer still SHOWS SMACKTHEMUP as version 4.0, as a card without a control');

$fedrouter = (string)file_get_contents($root . '/fediverse.php');
$ok(str_contains($fedrouter, "=== 'smackthemup'"),
    'public federation router hard-404s smackthemup (belt-and-suspenders)');

// --- Render-family mapping: display treats smackthemup like the GRAMOFSMACK grid -
$hdr = (string)file_get_contents($root . '/core/header.php');
$ok(str_contains($hdr, "['carousel', 'smackthemup']"),
    'header masthead treats smackthemup as the GRAMOFSMACK text-only header');
$smap = (string)file_get_contents($root . '/sitemap.php');
$ok(str_contains($smap, "['carousel', 'smackthemup']"),
    'sitemap treats smackthemup like carousel for public output');
$stats = (string)file_get_contents($root . '/core/stats-logger.php');
$ok(str_contains($stats, "['carousel', 'smackthemup']"),
    'scroll-stats track the smackthemup landing feed');
$idx = (string)file_get_contents($root . '/index.php');
$ok(!str_contains($idx, "=== 'smackthemup'"),
    'public landing keeps SMACKTHEMUP mode-agnostic and renders it through the active skin');

// --- Fail-closed writes: no other tool can create on a smackthemup site (§5.2) -
$sp = (string)file_get_contents($root . '/core/smackpress-api.php');
$ok(str_contains($sp, "=== 'smackthemup'") && str_contains($sp, 'smackpress_error(409'),
    'SMACKPRESS/COLD SNAP API refuses smackthemup with 409 (fail closed)');
$px = (string)file_get_contents($root . '/pixelfed-api.php');
$ok(str_contains($px, "px_mode(\$pdo)!=='carousel'"),
    'Pixelix publishing gate already blocks any non-carousel mode (incl. smackthemup)');

// --- Admin nav: no "New Post" composer link for smackthemup (spec §5.1) --------
$sb = (string)file_get_contents($root . '/core/sidebar.php');
$ok(str_contains($sb, '$_site_is_smackthemup') && str_contains($sb, 'if (!$_site_is_smackthemup):'),
    'admin sidebar hides the New Post composer link for smackthemup');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
// ===== SNAPSMACK EOF =====
