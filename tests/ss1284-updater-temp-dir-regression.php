<?php
/**
 * SS-1284 regression — one updater temp directory per install, and no silent
 * skip of the post-update SMACKBACK re-baseline.
 *
 * WHAT HAPPENED (measured, 2026-11-11): UPDATER_TEMP_DIR was a bare
 * sys_get_temp_dir() . '/snapsmack_update', so every virtual host in CT102
 * downloaded to, extracted from and cleaned up the SAME
 * /tmp/snapsmack_update/snapsmack-update.zip. During the fleet update one
 * site's updater_cleanup() removed the package another site was still using.
 * strathmore.pics logged
 *     hash_file(/tmp/snapsmack_update/snapsmack-update.zip): No such file
 * at 03:57:05. Its post-update re-baseline sat inside `if (file_exists($zip))`,
 * so the whole block was skipped WITHOUT A LOG LINE while the version was still
 * stamped and the update reported success. The 04:11:40 cron verify then
 * compared 0.7.992D files against the 0.7.991D manifest, flagged the six files
 * the update had rewritten, and put a live site into BREACH -> LOCKOUT.
 *
 * Three things must stay true:
 *   1. The temp directory is per-install, not shared.
 *   2. The re-baseline is not gated on the package file. It hashes files on
 *      disk and never reads the package, so gating it on one was always wrong.
 *   3. Neither a missing package nor a failed re-baseline is silent: both are
 *      logged, and the hub reports them instead of answering a clean success.
 *
 * Static source assertions — no DB, no network, no site required.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function ss1284(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$updater   = (string) @file_get_contents($root . '/core/updater.php');
$local     = (string) @file_get_contents($root . '/smack-update.php');
$hub       = (string) @file_get_contents($root . '/core/multisite-api.php');

ss1284('core/updater.php is readable',        $updater !== '');
ss1284('smack-update.php is readable',        $local   !== '');
ss1284('core/multisite-api.php is readable',  $hub     !== '');

if ($updater === '' || $local === '' || $hub === '') {
    fwrite(STDERR, "\nSS-1284: source files missing — cannot check.\n");
    exit(1);
}

// ── 1. TEMP DIRECTORY IS PER-INSTALL ────────────────────────────────────────

// The exact shared-directory definition that caused the incident must not return.
ss1284(
    'UPDATER_TEMP_DIR is not the bare shared /snapsmack_update path',
    !preg_match(
        "/define\s*\(\s*'UPDATER_TEMP_DIR'\s*,\s*sys_get_temp_dir\s*\(\s*\)\s*\.\s*'\/snapsmack_update'\s*\)/",
        $updater
    )
);

// It must be derived from something install-specific. dirname(__DIR__) is the
// install root; hashing it keeps the name short and filesystem-safe.
ss1284(
    'UPDATER_TEMP_DIR is derived from the install root',
    (bool) preg_match(
        "/define\s*\(\s*'UPDATER_TEMP_DIR'\s*,.*hash\s*\(\s*'sha256'\s*,\s*dirname\s*\(\s*__DIR__\s*\)\s*\)/s",
        $updater
    )
);

// A per-REQUEST directory would break the staged update, which spans several
// requests (download, verify, extract chunks, migrate) sharing $_SESSION state.
// Guard against the obvious ways someone might "improve" it into one.
foreach (['getmypid', 'uniqid', 'random_bytes', 'session_id', 'microtime'] as $volatile) {
    ss1284(
        "UPDATER_TEMP_DIR does not use {$volatile}() (would break the staged update)",
        !preg_match("/define\s*\(\s*'UPDATER_TEMP_DIR'\s*,[^;]*\b" . preg_quote($volatile, '/') . "\s*\(/s", $updater)
    );
}

// ── 2. THE RE-BASELINE IS NOT GATED ON THE PACKAGE ──────────────────────────

ss1284(
    'local finalize does not gate the re-baseline on the package file',
    !preg_match('/if\s*\(\s*\$smack_zip\s*&&\s*file_exists\s*\(\s*\$smack_zip\s*\)\s*\)/', $local)
);

ss1284(
    'hub finalize does not gate the re-baseline on the package file',
    !preg_match('/if\s*\(\s*file_exists\s*\(\s*\$zip_path\s*\)\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*require_once\s+__DIR__\s*\.\s*\'\/smackback\.php\'/s', $hub)
);

ss1284(
    'local finalize still calls smackback_init_from_disk()',
    str_contains($local, 'smackback_init_from_disk()')
);

ss1284(
    'hub finalize still calls smackback_init_from_disk()',
    str_contains($hub, 'smackback_init_from_disk()')
);

// ── 3. NEITHER FAILURE IS SILENT ────────────────────────────────────────────

// The hub used to discard the bool entirely:
//   if (function_exists('smackback_init_from_disk') && smackback_init_from_disk()) {
ss1284(
    'hub does not discard the re-baseline result in an && condition',
    !preg_match(
        '/if\s*\(\s*function_exists\s*\(\s*\'smackback_init_from_disk\'\s*\)\s*&&\s*smackback_init_from_disk\s*\(\s*\)\s*\)/',
        $hub
    )
);

ss1284(
    'hub captures the re-baseline result in a variable',
    (bool) preg_match('/\$smack_ok\s*=\s*smackback_init_from_disk\s*\(\s*\)\s*;/', $hub)
);

ss1284(
    'hub logs a missing package at finalize',
    str_contains($hub, 'package missing at finalize')
);

ss1284(
    'hub logs a failed re-baseline',
    (bool) preg_match('/SMACKBACK re-baseline FAILED/i', $hub)
);

ss1284(
    'hub reports baseline state back to the caller',
    str_contains($hub, "'baseline_refreshed'") && str_contains($hub, "'package_missing'")
);

ss1284(
    'hub response includes the smackback report',
    (bool) preg_match("/'smackback'\s*=>\s*\\\$smack_report/", $hub)
);

ss1284(
    'local finalize logs a missing package at finalize',
    str_contains($local, 'Package was gone before finalize')
);

ss1284(
    'local finalize logs a failed re-baseline',
    (bool) preg_match('/SMACKBACK re-baseline FAILED/i', $local)
);

// A failed re-baseline is a failure in the update log, not a warning: the site
// is now guaranteed to false-breach on its next verify.
ss1284(
    'local update log marks a failed re-baseline as fail, not warn',
    (bool) preg_match("/'status'\s*=>\s*\\\$smack_ok\s*\?\s*'ok'\s*:\s*'fail'/", $local)
);

// ── 4. A BREACH IS NOT AUTO-CLEARED WHEN WE CANNOT VOUCH ────────────────────

ss1284(
    'local finalize withholds the breach auto-clear when the package vanished',
    (bool) preg_match('/\$smack_ok\s*&&\s*!\$smack_zip_missing/', $local)
);

ss1284(
    'hub finalize withholds the breach auto-clear when the package vanished',
    (bool) preg_match('/\$smack_ok\s*&&\s*!\$smack_zip_missing/', $hub)
);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): SS-1284 guards are not in place — a shared temp "
        . "directory or a silently skipped re-baseline can false-breach a live site.\n");
    exit(1);
}
echo "SS-1284 updater temp dir + re-baseline reporting: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
