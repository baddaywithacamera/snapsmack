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
    'upload path does not gate the re-baseline on the uploaded package',
    !preg_match('/if\s*\(\s*file_exists\s*\(\s*\$smack_upload_zip\s*\)\s*\)/', $local)
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

// ── 3b. THE HUB CONSUMES THE REPORT (Codex verify, blocker 1) ───────────────
//
// Producing a report nobody reads is decoration. The first version of this fix
// returned data.smackback from the spoke and the hub copied only
// status/from/to/files/migs/errors, so a spoke that updated without refreshing
// its baseline was still painted green on the fleet board — the exact blindness
// that let strathmore.pics reach LOCKOUT unnoticed. Assert the CONSUMER.

$board = (string) @file_get_contents($root . '/smack-multisite.php');
ss1284('smack-multisite.php is readable', $board !== '');

ss1284(
    'hub board reads the spoke smackback report',
    (bool) preg_match("/\\\$data\s*\[\s*'smackback'\s*\]/", $board)
);

ss1284(
    'hub board carries baseline_refreshed and package_missing into the result',
    str_contains($board, "'baseline_refreshed'") && str_contains($board, "'package_missing'")
);

ss1284(
    'hub board flags a spoke that updated without refreshing its baseline',
    (bool) preg_match("/\\\$result\s*\[\s*'warn'\s*\]\s*=\s*true/", $board)
);

// An older spoke sends no 'smackback' key. That is UNKNOWN, not a failure —
// painting every pre-fix spoke amber would bury the real ones.
ss1284(
    'hub board treats a missing report as unknown rather than failure',
    (bool) preg_match("/isset\s*\(\s*\\\$data\s*\[\s*'smackback'\s*\]\s*\)\s*&&\s*is_array/", $board)
);

ss1284(
    'fleet board renderer does not paint a warned spoke green',
    (bool) preg_match('/res\.ok\s*&&\s*res\.warn/', $board)
);

ss1284(
    'fleet board renderer marks a warned spoke with the warning colour',
    (bool) preg_match('/rowMark\s*\(\s*id\s*,\s*"var\(--warning/', $board)
);

// ── 3b-ii. THE WARNING IS VISIBLE, NOT JUST COLOURED (Codex re-verify) ──────
//
// First attempt painted the row amber and put the reason in a title tooltip,
// while the spoke still counted as "ok" and the run still finished with a green
// "Finished — N updated, 0 failed". An outline plus a hidden tooltip under a
// reassuring green banner is the same blindness in a different costume.

ss1284(
    'warned spokes are counted separately from ok',
    (bool) preg_match('/\bwarnCount\s*\+\+/', $board)
    && !preg_match('/if\s*\(\s*res\.ok\s*&&\s*res\.warn\s*\)\s*\{[^}]*\bokCount\s*\+\+/s', $board)
);

ss1284(
    'the final summary reports the not-clean count',
    (bool) preg_match('/warnCount\s*\+\s*\x27 with warnings/', $board)
);

ss1284(
    'a run with warnings is not rendered as alert-success',
    (bool) preg_match('/warnCount\s*\?\s*"alert-warning"\s*:\s*"alert-success"/', $board)
);

ss1284(
    'warned spokes are named in the final summary, not only tooltipped',
    (bool) preg_match('/warnLines\s*\.\s*push\s*\(/', $board)
    && (bool) preg_match('/warnLines\s*\[\s*i\s*\]/', $board)
);

ss1284(
    'the in-progress counter shows the warning tally',
    (bool) preg_match("/warnCount\s*\+\s*' with warnings/", $board)
);

// PM ruling, SS-1284: the wording is neutral. The update succeeded and the
// files are correct; what failed is the integrity bookkeeping, and the
// per-spoke warn_detail is where the specific reason belongs. "NOT CLEAN" in
// the summary overstated it. Assert the overstatement is gone, not merely that
// the neutral text is present — otherwise both could sit in the file at once.
ss1284(
    'the summary does not use the overstated "NOT CLEAN" wording',
    !str_contains($board, 'NOT CLEAN')
);

ss1284(
    'the warned-spokes heading is the neutral one',
    str_contains($board, 'These spokes updated with SMACKBACK')
);

ss1284(
    'the plain-POST renderer labels it WARNING',
    (bool) preg_match('/<strong>WARNING:<\/strong>/', $board)
);

// The reason must still be reported per spoke — neutral wording must not mean
// vague wording. warn_detail is what distinguishes "baseline not refreshed"
// from "package missing at finalize".
ss1284(
    'per-spoke warn_detail still distinguishes the two causes',
    str_contains($board, 'SMACKBACK baseline was NOT refreshed')
    && str_contains($board, 'package was missing at finalize')
);

// ── 3b-iii. THE NON-AJAX RENDERER TOO (Codex re-verify, blocker 2) ──────────
//
// The per-spoke UPDATE form is a plain POST and is also the no-JavaScript
// fallback for UPDATE ALL. Its PHP renderer checked only $ur['ok'], so a warned
// spoke got a checkmark and alert-success.

ss1284(
    'PHP renderer handles ok+warn BEFORE the clean-ok branch',
    (bool) preg_match('/if\s*\(\s*!empty\s*\(\s*\$ur\s*\[\s*\x27ok\x27\s*\]\s*\)\s*&&\s*!empty\s*\(\s*\$ur\s*\[\s*\x27warn\x27\s*\]\s*\)\s*\)\s*:[\s\S]{0,2500}?elseif\s*\(\s*\$ur\s*\[\s*\x27ok\x27\s*\]\s*\)\s*:/', $board)
);

ss1284(
    'PHP renderer shows a warned spoke in a warning class, not alert-success',
    (bool) preg_match('/\x27warn\x27[\s\S]{0,1500}?alert alert-warning/', $board)
);

ss1284(
    'PHP renderer prints the reason, not just a colour',
    (bool) preg_match('/\$ur\s*\[\s*\x27warn_detail\x27\s*\]/', $board)
);

// ── 3c. UPLOAD PACKAGE-MISSING IS VISIBLE (Codex verify, blocker 2) ─────────
//
// It must show in the operator's step list even when the re-baseline SUCCEEDED,
// because a package that vanished mid-update is the signature of the race.
ss1284(
    'upload path adds a visible fail row for a missing package',
    (bool) preg_match(
        "/if\s*\(\s*\\\$smack_upload_missing\s*\)\s*\{\s*\\\$upload_steps\s*\[\s*\]\s*=\s*\[\s*'label'\s*=>\s*'Update package'\s*,\s*'status'\s*=>\s*'fail'/s",
        $local
    )
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
