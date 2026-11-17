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

cvml(
    'the decision is delegated to the pure lock helper, not inlined',
    (bool) preg_match('/snap_maintenance_lock_held\s*\(\s*\$snap_maint_lock\s*,\s*300\s*,/', $cron)
);

// ── BEHAVIOUR, not source text ──────────────────────────────────────────────
//
// Everything above reads the source. That is how the first version of this
// guard passed 13 assertions while still walking into the race: the code SAID
// the right words and DID the wrong thing. core/maintenance-lock.php exists as
// one pure function precisely so the decision can be run against real files.
//
// FAIL CLOSED is the rule: held unless provably stale. The dangerous case is a
// lock that exists but has no readable timestamp yet, which is what
// updater_acquire_lock() leaves on disk for the instant between creating the
// file and writing to it — the exact moment an update is starting.

require_once $root . '/core/maintenance-lock.php';

cvml('the lock helper is loadable and defines its function', function_exists('snap_maintenance_lock_held'));

if (function_exists('snap_maintenance_lock_held')) {
    $tmp = sys_get_temp_dir() . '/snap-lock-test-' . getmypid();
    @mkdir($tmp, 0700, true);
    $lock = $tmp . '/maintenance.lock';

    $case = function (string $label, $contents, bool $expect_held) use ($lock) {
        if ($contents === null) {
            @unlink($lock);
        } else {
            file_put_contents($lock, $contents);
        }
        $age = null;
        $got = snap_maintenance_lock_held($lock, 300, $age);
        cvml($label . ' -> ' . ($expect_held ? 'HELD' : 'not held'), $got === $expect_held);
    };

    $now = time();

    // No lock at all: the ordinary case, verification proceeds.
    $case('no lock file', null, false);

    // A fresh, valid lock: an update is running.
    $case('fresh valid lock', json_encode(['since' => $now, 'site_name' => 'x']), true);
    $case('valid lock 299s old', json_encode(['since' => $now - 299]), true);
    $case('valid lock exactly 300s old', json_encode(['since' => $now - 300]), true);

    // Provably stale: a crashed update must not block verification for ever.
    $case('valid lock 301s old (provably stale)', json_encode(['since' => $now - 301]), false);
    $case('valid lock a day old (provably stale)', json_encode(['since' => $now - 86400]), false);

    // THE CASE CODEX CAUGHT. Each of these is a lock we cannot prove stale, so
    // each must block. The empty and partial cases are what the file actually
    // looks like mid-write.
    $case('empty lock file (mid-write)', '', true);
    $case('partial JSON (mid-write)', '{"since":', true);
    $case('valid JSON, no since key', json_encode(['site_name' => 'x']), true);
    $case('since is zero', json_encode(['since' => 0]), true);
    $case('since is negative', json_encode(['since' => -5]), true);
    $case('since is a non-numeric string', json_encode(['since' => 'soon']), true);
    $case('since is null', json_encode(['since' => null]), true);
    $case('lock is a JSON array, not an object', json_encode([1, 2, 3]), true);
    $case('lock is plain text', 'locked', true);
    $case('future-dated since (clock skew)', json_encode(['since' => $now + 600]), true);

    // The reported age is for humans only and must never drive the decision.
    $age = null;
    file_put_contents($lock, json_encode(['since' => $now - 42]));
    snap_maintenance_lock_held($lock, 300, $age);
    cvml('age is reported for a valid timestamp', $age === 42 || $age === 43);

    $age = -1;
    file_put_contents($lock, '');
    snap_maintenance_lock_held($lock, 300, $age);
    cvml('age is null when there is no readable timestamp', $age === null);

    @unlink($lock);
    @rmdir($tmp);
}

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
