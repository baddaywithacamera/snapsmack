<?php
/**
 * Regression — the cron SMACKBACK verify must not run during an update.
 *
 * core/constants.php gates the maintenance lock on `PHP_SAPI !== 'cli'`. That
 * exemption is correct for the updater itself: its own chunk requests must not
 * 503 themselves. But cron-version-check.php runs from CLI and inherited the
 * exemption, so it walked straight through a lock that every web request
 * respects, and verified file integrity in the middle of a file replacement.
 *
 * A verify in that window compares updated files against the PRE-UPDATE
 * manifest. Every file the update legitimately rewrote reads as TAMPERED.
 *
 * strathmore.pics, 2026-11-11: update finalized 03:57, its post-update
 * re-baseline was skipped (SS-1284), the 04:11:40 cron verify flagged the six
 * files the update had rewritten, and a live site went BREACH -> LOCKOUT.
 * SS-1284 stops the baseline being skipped. This stops the verify running
 * mid-update at all. Two defects, both real, fixed separately.
 *
 * The fix must SKIP, never disarm: smackback_verify_due() is left alone, so the
 * next scheduled run verifies normally. A skipped pass costs one interval; a
 * false breach costs a site. It must also honour the same 5-minute safety valve
 * core/constants.php uses, so a lock left behind by a crashed update cannot
 * silence verification for ever.
 *
 * Static source assertions — no DB, no network, no site required.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function cvml(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$cron      = (string) @file_get_contents($root . '/cron-version-check.php');
$constants = (string) @file_get_contents($root . '/core/constants.php');

cvml('cron-version-check.php is readable', $cron      !== '');
cvml('core/constants.php is readable',     $constants !== '');

if ($cron === '' || $constants === '') {
    fwrite(STDERR, "\nCron maintenance-lock guard: source files missing — cannot check.\n");
    exit(1);
}

// ── The premise this guard exists for ───────────────────────────────────────
// If constants.php ever stops exempting CLI, the web-side lock would cover cron
// too and this guard becomes belt-and-braces rather than load-bearing. Assert
// the premise so a future change to constants.php shows up here as a signal
// rather than leaving a guard nobody understands.
cvml(
    'core/constants.php still exempts CLI from the maintenance lock (the reason this guard exists)',
    (bool) preg_match("/PHP_SAPI\s*!==\s*'cli'/", $constants)
);

cvml(
    'core/constants.php still uses the 300-second stale-lock safety valve',
    (bool) preg_match('/>\s*300\s*\)/', $constants)
);

// ── The guard itself ────────────────────────────────────────────────────────

cvml(
    'cron checks for data/maintenance.lock',
    (bool) preg_match("/data\/maintenance\.lock/", $cron)
);

cvml(
    'cron records whether an update is running',
    (bool) preg_match('/\$snap_update_running\s*=\s*true\s*;/', $cron)
);

cvml(
    'the SMACKBACK verify is gated on no update running',
    (bool) preg_match('/!\$snap_update_running\s*&&\s*smackback_verify_due\s*\(\s*\)/', $cron)
);

// ── It must SKIP, not disarm ────────────────────────────────────────────────
// Writing smackback_enabled=0, or stamping last_verified, would silence future
// runs too. The only correct behaviour is to do nothing this pass.
cvml(
    'the guard does not disable SMACKBACK',
    !preg_match("/smackback_enabled'?\s*\]?\s*=\s*'?0'?/", $cron)
);

cvml(
    'the guard does not write any setting to suppress later runs',
    !preg_match('/\$snap_update_running[^;]*\n[^\n]*(INSERT INTO|UPDATE)\s+snap_settings/i', $cron)
);

// ── It must honour the stale-lock safety valve ──────────────────────────────
// Otherwise a lock left by a crashed update silences integrity checking for
// ever, which is strictly worse than the bug being fixed.
cvml(
    'the guard honours a 300-second stale-lock ceiling',
    (bool) preg_match('/\$snap_maint_age\s*<=\s*300/', $cron)
);

cvml(
    'the guard reads the lock age from the lock file',
    (bool) preg_match("/\\\$snap_maint\s*\[\s*'since'\s*\]/", $cron)
);

// ── It must say so ──────────────────────────────────────────────────────────
// A silent skip is how this class of problem stays invisible. Say it on stdout
// (cron mail / LOG FLOGGER) and in the error log.
cvml(
    'the skip is announced on stdout',
    (bool) preg_match('/SMACKBACK:\s*verify skipped/', $cron)
);

cvml(
    'the skip is written to the error log',
    (bool) preg_match('/error_log\s*\(\s*\'SnapSmack cron: SMACKBACK verify skipped/', $cron)
);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the cron verify can run during an update and false-breach a live site.\n");
    exit(1);
}
echo "Cron SMACKBACK verify respects the maintenance lock: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
