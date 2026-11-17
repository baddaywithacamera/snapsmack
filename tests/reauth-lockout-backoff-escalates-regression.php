<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * THE LOCKOUT HAS TO GET LONGER. SS-1399, after Codex's FAIL on 86346091.
 *
 * The first version of the step-up budget documented three tiers -- 15
 * minutes, 30, then an hour -- and could only ever reach the first. It kept a
 * single snap_rate_limits row and DELETED it the moment a lock expired, so
 * the next cycle began at count 1 and the fifth failure locked for 900
 * seconds again. Codex proved it at runtime: first_lock_seconds=900,
 * second_lock_seconds=900, and an attacker with patience had an unlimited
 * budget in 15-minute slices.
 *
 * THE SHIPPED TEST COULD NOT SEE IT, which is the part worth remembering. It
 * exercised one lock. Everything it asserted was true. A test that stops at
 * the first cycle cannot test escalation, and escalation was the feature.
 *
 * So this file runs the real helper against a real SQLite table, with time
 * injected, through THREE complete cycles and out the other side.
 */

require_once dirname(__DIR__) . '/core/reauth.php';

$failures = [];
$logFile = tempnam(sys_get_temp_dir(), 'ss1399_backoff_');
ini_set('error_log', $logFile);

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("
    CREATE TABLE snap_users (
        id INTEGER PRIMARY KEY, password_hash TEXT NOT NULL,
        totp_enabled INTEGER NOT NULL DEFAULT 1, totp_secret TEXT NOT NULL,
        user_role TEXT NOT NULL DEFAULT 'admin');
    CREATE TABLE snap_rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, action TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 1, window_start TEXT NOT NULL,
        UNIQUE (ip, action));
");

$secret = 'IFBEGRCFIZDUQSKKJNGE2TSPKBIVEU2U';
$password = 'correct-horse-battery-staple';
$pdo->prepare("INSERT INTO snap_users (id, password_hash, totp_enabled, totp_secret, user_role)
               VALUES (7, ?, 1, ?, 'admin')")
    ->execute([password_hash($password, PASSWORD_DEFAULT), $secret]);

$_SESSION = ['user_id' => 7];
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';

/**
 * Burn the whole budget starting at $now. Returns the last result and the
 * moment the lock began, so the caller can step past it exactly -- the clock
 * has to be handled by hand here, since the lock starts at the LAST failure,
 * not the first.
 */
$burnCycle = static function (PDO $pdo, int $now): array {
    $last = [];
    $at = $now;
    for ($i = 0; $i < REAUTH_MAX_ATTEMPTS; $i++) {
        $at = $now + $i;
        $last = reauth_verify($pdo, 'wrong-password', '000000', $at);
    }
    return ['result' => $last, 'at' => $at];
};

$lockedFor = static function (array $cycle): int {
    return (int)($cycle['result']['retry_after'] ?? 0);
};

/** The first instant after this cycle's lock has lifted. */
$after = static function (array $cycle) use ($lockedFor): int {
    return $cycle['at'] + $lockedFor($cycle) + 1;
};

// ── CYCLE 1 — fifteen minutes ────────────────────────────────────────────
$t = 1_700_000_000;
$r1 = $burnCycle($pdo, $t);
if (empty($r1['result']['locked'])) {
    $failures[] = 'five failures did not lock the account at all';
}
if ($lockedFor($r1) !== 900) {
    $failures[] = 'first lock was ' . $lockedFor($r1) . ' seconds, expected 900';
}

// Still locked a minute before it lifts, and the right credentials do not
// open it -- otherwise the lock is only a lock for people who are wrong.
$mid = reauth_verify($pdo, $password, totp_code($secret), $r1['at'] + 900 - 60);
if (!empty($mid['ok']) || empty($mid['locked'])) {
    $failures[] = 'correct credentials opened an active lock';
}

// ── CYCLE 2 — thirty minutes ─────────────────────────────────────────────
// The lock has lifted. A full set of attempts is available again -- that is
// the "one legitimate attempt after the lock" requirement, and more. Burning
// all of them again must cost DOUBLE.
$t2 = $after($r1);
$r2 = $burnCycle($pdo, $t2);
if ($lockedFor($r2) !== 1800) {
    $failures[] = 'SECOND lock was ' . $lockedFor($r2) . ' seconds, expected 1800. '
        . 'This is the exact defect Codex found: the escalation is not surviving '
        . 'the first lock expiring.';
}

// The first failure of cycle 2 must NOT have re-locked instantly.
$probe = _reauth_get_budget($pdo, 7, $t2 + 1);
if (!empty($probe['locked']) === false && $probe['count'] === 0) {
    $failures[] = 'the second cycle recorded no attempts at all';
}

// ── CYCLE 3 — an hour ────────────────────────────────────────────────────
$t3 = $after($r2);
$r3 = $burnCycle($pdo, $t3);
if ($lockedFor($r3) !== 3600) {
    $failures[] = 'THIRD lock was ' . $lockedFor($r3) . ' seconds, expected 3600';
}

// ── CYCLE 4 — stays at an hour, does not run away ────────────────────────
// An unbounded ladder locks an owner out of their own site for a week over a
// stuck caps-lock key. Sean's standing rule is that we never lock someone out
// of their own install.
$t4 = $after($r3);
$r4 = $burnCycle($pdo, $t4);
if ($lockedFor($r4) !== 3600) {
    $failures[] = 'the fourth lock was ' . $lockedFor($r4) . ' seconds; the ladder '
        . 'must stop at an hour, not keep doubling';
}

// ── SUCCESS CLEARS THE HISTORY ───────────────────────────────────────────
$t5 = $after($r4);
$ok = reauth_verify($pdo, $password, totp_code($secret), $t5);
if (empty($ok['ok'])) {
    $failures[] = 'correct credentials were refused after the last lock expired: '
        . (string)($ok['error'] ?? '');
}
$rows = (int)$pdo->query("SELECT COUNT(*) FROM snap_rate_limits
                          WHERE ip = 'uid:7' AND action IN ('reauth_fail','reauth_lock')")
    ->fetchColumn();
if ($rows !== 0) {
    $failures[] = "a successful step-up left {$rows} budget row(s) behind, so the "
        . 'owner stays on the top tier after proving who they are';
}
// And the next lock after that starts at fifteen minutes again.
$restart = $burnCycle($pdo, $t5 + 1);
if ($lockedFor($restart) !== 900) {
    $failures[] = 'after a success the ladder restarted at ' . $lockedFor($restart)
        . ' seconds, expected 900';
}

// ── THE ESCALATION DECAYS ────────────────────────────────────────────────
// A clear day puts the account back on the first tier without needing a
// successful step-up at all.
$pdo->exec("DELETE FROM snap_rate_limits");
$_SESSION = ['user_id' => 7];
$tA = 1_800_000_000;
$firstDay = $burnCycle($pdo, $tA);
$tB = $firstDay['at'] + REAUTH_CYCLE_MEMORY_SECONDS + 1;
$decayed = $burnCycle($pdo, $tB);
if ($lockedFor($decayed) !== 900) {
    $failures[] = 'after a full quiet day the lock was ' . $lockedFor($decayed)
        . ' seconds, expected it to have decayed back to 900';
}

// ── CONCURRENT FAILURES ARE NOT LOST ─────────────────────────────────────
// The old code read the count and wrote count+1, so two failures arriving
// from separate sessions could both read 3 and both write 4. A budget that
// loses failures is not a budget. Simulated by interleaving the increment
// the way two requests would, against the same row.
$pdo->exec("DELETE FROM snap_rate_limits");
$tC = 1_900_000_000;
for ($i = 0; $i < 12; $i++) {
    _reauth_bump_counter($pdo, 'uid:7', 'reauth_fail', $tC);
}
$counted = (int)$pdo->query("SELECT count FROM snap_rate_limits
                             WHERE ip = 'uid:7' AND action = 'reauth_fail'")->fetchColumn();
if ($counted !== 12) {
    $failures[] = "twelve recorded failures counted as {$counted}; increments are "
        . 'being lost, which is the read-modify-write race';
}
// THE INCREMENT ITSELF, read as source. This one assertion is deliberately
// structural and says so: PHP in one process cannot race itself, so the loop
// above proves the arithmetic and NOT the atomicity -- it passes just as
// happily against the lossy SELECT-then-UPDATE form, which I checked by
// putting that form back. The property that matters is that the database
// does the addition, in one statement. Reading for it is the only way to
// catch the regression that actually happened.
// php_strip_whitespace, not file_get_contents: the comment above the
// function SAYS "ON DUPLICATE KEY UPDATE is deliberately NOT used", and
// matching the prose instead of the code is how a check like this ends up
// failing on its own documentation. String literals survive the strip, so
// the SQL is still there to read.
$src = (string)php_strip_whitespace(dirname(__DIR__) . '/core/reauth.php');
if (!preg_match('/UPDATE\s+snap_rate_limits\s+SET\s+count\s*=\s*count\s*\+\s*1/i', $src)) {
    $failures[] = 'the failure counter is no longer incremented by the database '
        . '("SET count = count + 1"). A value computed in PHP and written back '
        . 'loses one of two failures arriving at the same moment, from a budget '
        . 'whose only job is to count them.';
}
if (preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', $src)) {
    $failures[] = 'ON DUPLICATE KEY UPDATE is MySQL and MariaDB only; the '
        . 'regressions run on SQLite, so this would be untested on the engine '
        . 'that ships';
}

// The very first bump must create the row rather than need one to exist.
$pdo->exec("DELETE FROM snap_rate_limits");
if (_reauth_bump_counter($pdo, 'uid:7', 'reauth_fail', $tC) !== 1) {
    $failures[] = 'the first failure on a clean account did not record as 1';
}
// A duplicate INSERT race must increment, not throw and not reset.
$pdo->prepare("INSERT OR IGNORE INTO snap_rate_limits (ip, action, count, window_start)
               VALUES ('uid:7', 'reauth_fail', 1, ?)")->execute([gmdate('Y-m-d H:i:s', $tC)]);
if (_reauth_bump_counter($pdo, 'uid:7', 'reauth_fail', $tC) !== 2) {
    $failures[] = 'a bump that collided with an existing row did not increment it';
}

// -- SPREAD-OUT ATTEMPTS FROM FRESH SESSIONS STILL LOCK -------------------
// Codex's reproducible bypass, and it is the one that mattered. My first
// atomic increment left window_start alone, so the shared row expired 15
// minutes after the FIRST failure instead of the last. Wrong passwords four
// minutes apart, each from a NEW session, went 1,2,3,4,1 and never locked.
// The session counter had been masking it: in one browser it looked fine.
// The session is wiped between every attempt here, so only the server-side
// budget can do the work.
$pdo->exec("DELETE FROM snap_rate_limits");
$tS = 2_000_000_000;
$spread = [];
for ($i = 0; $i < REAUTH_MAX_ATTEMPTS; $i++) {
    $_SESSION = ['user_id' => 7];            // a brand-new session each time
    $spread = reauth_verify($pdo, 'wrong-password', '000000', $tS + ($i * 240));
}
if (empty($spread['locked'])) {
    $failures[] = 'five wrong passwords four minutes apart, each from a fresh '
        . 'session, did not lock the account. The shared budget is expiring '
        . 'from the FIRST failure instead of the last, so anyone willing to '
        . 'wait four minutes between guesses has no limit at all.';
}
if ((int)($spread['retry_after'] ?? 0) !== 900) {
    $failures[] = 'the spread-out lockout was ' . (int)($spread['retry_after'] ?? 0)
        . ' seconds, expected 900';
}

// A GENUINELY QUIET PERIOD STILL FORGIVES. The counter must not become a
// lifetime tally: four failures, then real silence, then four more is not a
// lockout.
$pdo->exec("DELETE FROM snap_rate_limits");
$tQ = 2_100_000_000;
for ($i = 0; $i < REAUTH_MAX_ATTEMPTS - 1; $i++) {
    $_SESSION = ['user_id' => 7];
    reauth_verify($pdo, 'wrong-password', '000000', $tQ + $i);
}
$_SESSION = ['user_id' => 7];
$afterQuiet = reauth_verify($pdo, 'wrong-password', '000000',
    $tQ + REAUTH_BASE_LOCKOUT_SECONDS + 60);
if (!empty($afterQuiet['locked'])) {
    $failures[] = 'four failures followed by a full quiet period still locked '
        . 'on the next attempt; the quiet period is not being honoured';
}

// -- ONE LOCK EPISODE PER LOCK, NOT ONE PER REQUEST -----------------------
// Two requests that both pass the budget check and both reach the threshold
// must produce ONE episode. Before the winner election, both bumped the
// episode row and the first offence cost 1800 seconds. Driven through
// _reauth_record_failure directly: PHP cannot race itself, but this IS the
// interleaving -- the second caller arrives after the first has consumed the
// spent attempt row, which is exactly what a loser sees.
$pdo->exec("DELETE FROM snap_rate_limits");
$tR = 2_200_000_000;
for ($i = 0; $i < REAUTH_MAX_ATTEMPTS - 1; $i++) {
    _reauth_bump_counter($pdo, 'uid:7', 'reauth_fail', $tR);
}
$_SESSION = ['user_id' => 7];
$firstReq = _reauth_record_failure($pdo, 7, 'bad_password', $tR);
$_SESSION = ['user_id' => 7];
$secondReq = _reauth_record_failure($pdo, 7, 'bad_password', $tR);

$episodes = (int)$pdo->query("SELECT count FROM snap_rate_limits
                              WHERE ip = 'uid:7' AND action = 'reauth_lock'")->fetchColumn();
if ($episodes !== 1) {
    $failures[] = "two requests crossing the threshold together recorded "
        . "{$episodes} lock episodes, not 1. The owner is handed the second "
        . 'tier for a first offence.';
}
if ((int)($firstReq['retry_after'] ?? 0) !== 900) {
    $failures[] = 'the winning request did not report a 900-second lock';
}
if ((int)($secondReq['retry_after'] ?? 0) !== 900) {
    $failures[] = 'the losing request reported '
        . (int)($secondReq['retry_after'] ?? 0) . ' seconds instead of the '
        . 'remaining time on the lock that already exists';
}

// THE WINNER ELECTION ITSELF, read as source, and labelled because it is.
// The assertion above is satisfied by the standing-lock guard: the second
// caller sees the lock the first one created and reports it. That guard
// cannot cover the genuinely simultaneous case, where both requests read "no
// lock" before either writes one, and PHP in a single process cannot produce
// that interleaving. The election is what covers it -- the spent attempt row
// is DELETEd and only the caller whose delete affected a row advances the
// tier -- so the check is that the episode bump is gated on that result and
// not run unconditionally. Without this, removing the gate passes every
// executed assertion in this file; I checked.
$reauthSrc = (string)php_strip_whitespace(dirname(__DIR__) . '/core/reauth.php');
$lockedAt = strpos($reauthSrc, "'reauth_lock'");
$electAt = strpos($reauthSrc, 'rowCount()');
if ($electAt === false) {
    $failures[] = 'the lock-episode transition no longer elects a single '
        . 'winner (no rowCount() check). Two requests crossing the threshold '
        . 'at the same instant would each advance the tier, so a first '
        . 'offence costs the second tier.';
} elseif ($lockedAt !== false && $electAt > strrpos($reauthSrc, "_reauth_bump_counter(\$pdo, \$key, 'reauth_lock'")) {
    $failures[] = 'the winner election runs AFTER the episode counter is '
        . 'bumped, which elects nothing';
}

// -- NO SECRETS IN THE AUDIT LOG ──────────────────────────────────────────
$log = (string)@file_get_contents($logFile);
if ($log === '') {
    $failures[] = 'nothing was written to the audit log, so the log assertions '
        . 'below would pass for the wrong reason';
}
foreach ([$password, $secret, totp_code($secret), '000000'] as $leak) {
    if ($leak !== '' && str_contains($log, $leak)) {
        $failures[] = 'the audit log contains a credential value';
        break;
    }
}
if (!str_contains($log, 'cycle=2')) {
    $failures[] = 'the audit log never records which cycle a lockout was, so an '
        . 'owner reading it cannot tell a first lock from a third';
}
@unlink($logFile);

if ($failures) {
    throw new RuntimeException("Step-up lockout backoff is broken:\n  - "
        . implode("\n  - ", $failures));
}

echo "Step-up lockout escalates 900 -> 1800 -> 3600, caps there, clears on "
    . "success, decays after a quiet day, and loses no concurrent failures: passed.\n";
// ===== SNAPSMACK EOF =====
