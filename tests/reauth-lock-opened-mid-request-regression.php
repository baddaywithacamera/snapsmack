<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * A LOCK OPENED WHILE A REQUEST IS WORKING. SS-1399 r5, from Codex's FAIL on
 * r4 (6ba6abe8, review cAGdZUoX).
 *
 * r4 read the standing lock ONCE, before it counted the failure. Codex's
 * interleaving:
 *
 *   1. Request B reads no reauth_lock, then pauses.
 *   2. Request A reaches the fifth failure, deletes reauth_fail, and opens
 *      the lock episode.
 *   3. B resumes and bumps reauth_fail -- creating a FRESH row at count 1,
 *      because A deleted the old one.
 *   4. B sees 1 < 5, answers "code incorrect" to an account that is already
 *      locked, and leaves that 1 preloaded against the owner's next cycle.
 *
 * So the account's lock went unreported, and the attempt budget leaked one
 * attempt for every request that lost this race. An attacker racing requests
 * gets both: no lock notice, and a next cycle that starts a step short.
 *
 * WHY r4's TESTS COULD NOT SEE IT. They call the episode helper sequentially
 * and exercise stale escalation directly. Nothing in them pauses a failing
 * request between its standing-lock read and its counter bump, and that gap
 * is the whole defect. A test that cannot stop time inside a function cannot
 * test what another request does during it.
 *
 * This file forces the gap. RacingPdo is a real PDO on a real SQLite table
 * that fires a callback at the exact statement the race needs -- the bump --
 * and the callback is request A finishing. No sleeps, no threads, no mocks of
 * the code under test: reauth.php runs unmodified against a database that
 * changes underneath it, which is what a second request IS.
 *
 * Run: php tests/reauth-lock-opened-mid-request-regression.php
 */

require_once dirname(__DIR__) . '/core/reauth.php';

$failures = [];
$logFile  = tempnam(sys_get_temp_dir(), 'ss1399_race_');
ini_set('error_log', $logFile);

function check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/**
 * A PDO that lets another request land in the middle of this one.
 *
 * $trip is a fragment of the SQL this request is about to prepare. The first
 * time that statement comes past, the callback runs -- on this same
 * connection, so it is a genuine concurrent write and not a fixture -- and
 * then the original statement is prepared and runs as normal. One shot: it
 * disarms itself, so the code under test is only interrupted where the race
 * actually puts another request.
 */
class RacingPdo extends PDO
{
    private ?string $trip = null;
    /** @var callable|null */
    private $onTrip = null;
    public int $trips = 0;

    public function armBefore(string $sql_fragment, callable $other_request): void
    {
        $this->trip   = $sql_fragment;
        $this->onTrip = $other_request;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        if ($this->trip !== null && str_contains($query, $this->trip)) {
            $fire        = $this->onTrip;
            $this->trip  = null;
            $this->onTrip = null;
            $this->trips++;
            $fire($this);
        }
        return parent::prepare($query, $options);
    }
}

function fresh_pdo(): RacingPdo
{
    $pdo = new RacingPdo('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
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
    $pdo->prepare("INSERT INTO snap_users (id, password_hash, totp_enabled, totp_secret, user_role)
                   VALUES (7, ?, 1, ?, 'admin')")
        ->execute([password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT),
                   'IFBEGRCFIZDUQSKKJNGE2TSPKBIVEU2U']);
    return $pdo;
}

/** Put a counter row in place directly, the way another request would leave it. */
function seed_counter(PDO $pdo, string $action, int $count, int $at): void
{
    $pdo->prepare("INSERT INTO snap_rate_limits (ip, action, count, window_start)
                   VALUES ('uid:7', ?, ?, ?)")
        ->execute([$action, $count, gmdate('Y-m-d H:i:s', $at)]);
}

function counter_row(PDO $pdo, string $action): ?array
{
    $st = $pdo->prepare("SELECT count, window_start FROM snap_rate_limits
                         WHERE ip = 'uid:7' AND action = ? LIMIT 1");
    $st->execute([$action]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
$NOW = 1795000000;

echo "SS-1399 r5 — a lock opened mid-request is reported, and costs no attempt\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the two new helpers do what the fix depends on\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = fresh_pdo();

// _reauth_standing_lock: three answers, and only one of them is a lock.
check('standing lock: no row means no lock',
      _reauth_standing_lock($pdo, 'uid:7', $NOW) === null, $failures);

seed_counter($pdo, 'reauth_lock', 1, $NOW - 100);
$live = _reauth_standing_lock($pdo, 'uid:7', $NOW);
check('standing lock: a running first-tier lock is reported',
      is_array($live) && $live['cycle'] === 1, $failures);
check('standing lock: retry_after is the time actually left (800s of 900)',
      is_array($live) && $live['retry_after'] === 800, $failures);

$pdo->exec("UPDATE snap_rate_limits SET window_start = '"
           . gmdate('Y-m-d H:i:s', $NOW - 1000) . "' WHERE action = 'reauth_lock'");
check('standing lock: an EXPIRED first-tier lock is not a lock',
      _reauth_standing_lock($pdo, 'uid:7', $NOW) === null, $failures);

// A second-tier episode runs 1800s, so the same 1000s-old window is still live.
$pdo->exec("UPDATE snap_rate_limits SET count = 2 WHERE action = 'reauth_lock'");
$tier2 = _reauth_standing_lock($pdo, 'uid:7', $NOW);
check('standing lock: the tier decides the expiry, not a fixed 900',
      is_array($tier2) && $tier2['cycle'] === 2 && $tier2['retry_after'] === 800, $failures);

// _reauth_unbump_counter: takes one back, stops at nothing, never goes under.
$pdo2 = fresh_pdo();
seed_counter($pdo2, 'reauth_fail', 3, $NOW);
_reauth_unbump_counter($pdo2, 'uid:7', 'reauth_fail');
$row = counter_row($pdo2, 'reauth_fail');
check('unbump: 3 becomes 2', is_array($row) && (int)$row['count'] === 2, $failures);
check('unbump: window_start is left alone (it means "the last failure")',
      is_array($row) && $row['window_start'] === gmdate('Y-m-d H:i:s', $NOW), $failures);

_reauth_unbump_counter($pdo2, 'uid:7', 'reauth_fail');
_reauth_unbump_counter($pdo2, 'uid:7', 'reauth_fail');
check('unbump: the row is removed at zero, not left holding a stale window',
      counter_row($pdo2, 'reauth_fail') === null, $failures);

_reauth_unbump_counter($pdo2, 'uid:7', 'reauth_fail');   // nothing there at all
check('unbump: unwinding past zero creates nothing and throws nothing',
      counter_row($pdo2, 'reauth_fail') === null, $failures);

seed_counter($pdo2, 'reauth_fail', 0, $NOW);
_reauth_unbump_counter($pdo2, 'uid:7', 'reauth_fail');
$row = counter_row($pdo2, 'reauth_fail');
check('unbump: a zero row is cleared, never driven negative',
      $row === null, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — Codex's interleaving, forced\n";
// ─────────────────────────────────────────────────────────────────────────────
// Request B is a SEPARATE session, so its session counter is 1 and cannot
// mask the shared budget. That is the case Codex described, and it is also
// the dangerous one: the session counter is the thing an attacker discards.
$pdo = fresh_pdo();
$_SESSION = ['user_id' => 7];
seed_counter($pdo, 'reauth_fail', REAUTH_MAX_ATTEMPTS - 1, $NOW);   // 4 in this cycle

$pdo->armBefore(
    'INSERT INTO snap_rate_limits (ip, action, count, window_start)',
    static function (PDO $conn) use ($NOW): void {
        // This IS request A, finishing: it spends the cycle's attempts and
        // opens the lock, exactly as _reauth_record_failure does at the
        // threshold. B is suspended between its lock read and its own bump.
        _reauth_drop_counter($conn, 'uid:7', 'reauth_fail');
        _reauth_open_lock_episode($conn, 'uid:7', $NOW);
    }
);

$before_log = (string)@file_get_contents($logFile);
$b = _reauth_record_failure($pdo, 7, 'totp_mismatch', $NOW);
$b_log = substr((string)@file_get_contents($logFile), strlen($before_log));

check('the race actually fired (the test proves nothing otherwise)',
      $pdo->trips === 1, $failures);
check('request A did open a lock episode',
      is_array(counter_row($pdo, 'reauth_lock')), $failures);

check('B is told the account is LOCKED, not "code incorrect"',
      ($b['locked'] ?? false) === true, $failures);
check('B reports the standing episode (cycle 1), not a new one',
      ($b['cycle'] ?? 0) === 1, $failures);
check('B reports the real time left on that lock (900s)',
      ($b['retry_after'] ?? 0) === 900, $failures);
check('B hands the caller a message, not a bare failure',
      _reauth_locked_message((int)($b['retry_after'] ?? 0)) !== '', $failures);

check('B LEAVES NO FAILURE BEHIND: the next cycle is not preloaded',
      counter_row($pdo, 'reauth_fail') === null, $failures);

check('the audit line says blocked_while_locked',
      str_contains($b_log, 'blocked_while_locked'), $failures);
check('the audit line does NOT record an ordinary failure for this attempt',
      !str_contains($b_log, 'SnapSmack reauth failure uid=7'), $failures);
check('B clears its own session counter, so its next attempt is not double-counted',
      (int)($_SESSION['reauth_fail_count'] ?? -1) === 0, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the leaked attempt, measured as behaviour\n";
// ─────────────────────────────────────────────────────────────────────────────
// The preloaded 1 is not just an untidy row. Carried into the next cycle it
// locks the owner out one attempt early, for a failure nobody made. Step the
// clock past the lock and count how many wrong codes the next cycle allows.
$past = $NOW + 901;
$_SESSION = ['user_id' => 7];                       // a fresh session again
$attempts_until_lock = 0;
for ($i = 1; $i <= REAUTH_MAX_ATTEMPTS + 1; $i++) {
    unset($_SESSION['reauth_fail_count'], $_SESSION['reauth_lock_until']);
    $r = _reauth_record_failure($pdo, 7, 'totp_mismatch', $past + $i);
    if (($r['locked'] ?? false) === true) {
        $attempts_until_lock = $i;
        break;
    }
}
check('the next cycle still allows a full five attempts (not four)',
      $attempts_until_lock === REAUTH_MAX_ATTEMPTS, $failures);
check('and that second lock escalates to tier 2 (1800s), as the tiers promise',
      (int)(counter_row($pdo, 'reauth_lock')['count'] ?? 0) === 2, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — the ordinary path is untouched\n";
// ─────────────────────────────────────────────────────────────────────────────
// A fix that reports a lock to everyone would be worse than the bug. With no
// lock standing, a wrong code must still count as one wrong code.
$pdo3 = fresh_pdo();
$_SESSION = ['user_id' => 7];
$r1 = _reauth_record_failure($pdo3, 7, 'totp_mismatch', $NOW);
check('no lock standing: a wrong code is still just a wrong code',
      ($r1['locked'] ?? true) === false && ($r1['count'] ?? 0) === 1, $failures);
check('no lock standing: the failure IS recorded (the undo did not eat it)',
      (int)(counter_row($pdo3, 'reauth_fail')['count'] ?? 0) === 1, $failures);

// And the pre-bump check still short-circuits a lock that was already there
// when the request arrived -- r4's behaviour, which must survive.
$pdo4 = fresh_pdo();
$_SESSION = ['user_id' => 7];
seed_counter($pdo4, 'reauth_lock', 1, $NOW - 100);
$r2 = _reauth_record_failure($pdo4, 7, 'totp_mismatch', $NOW);
check('a lock that predates the request is reported without counting anything',
      ($r2['locked'] ?? false) === true && counter_row($pdo4, 'reauth_fail') === null,
      $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — the second read is after the bump, and stays there\n";
// ─────────────────────────────────────────────────────────────────────────────
// The behaviour above is produced by an ORDERING, and an ordering is the kind
// of thing a later edit moves without noticing. Pin it in the source: two
// standing-lock reads inside _reauth_record_failure, one of them after the
// counter moves. r4 had one read, before.
$src  = (string)file_get_contents(dirname(__DIR__) . '/core/reauth.php');
$start = strpos($src, 'function _reauth_record_failure(');
$end   = strpos($src, "\nfunction ", (int)$start + 10);
$body  = substr($src, (int)$start, ($end === false ? strlen($src) : $end) - (int)$start);

$reads = substr_count($body, '_reauth_standing_lock($pdo, $key, $now)');
check("_reauth_record_failure asks for the standing lock twice (found {$reads})",
      $reads >= 2, $failures);

$bump_at  = strpos($body, "_reauth_bump_counter(\$pdo, \$key, 'reauth_fail'");
$after_at = strpos($body, '_reauth_standing_lock($pdo, $key, $now)', (int)$bump_at);
check('one of those reads comes AFTER the reauth_fail bump',
      $bump_at !== false && $after_at !== false, $failures);
check('the post-bump branch undoes this failure before reporting the lock',
      $bump_at !== false
      && strpos($body, "_reauth_unbump_counter(\$pdo, \$key, 'reauth_fail')", (int)$bump_at) !== false,
      $failures);

@unlink($logFile);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1399 r5 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "SS-1399 r5 PASS — a lock opened during a request is reported by that\n";
echo "request, the attempt it had already recorded is taken back off, and the\n";
echo "owner's next cycle still begins with a full budget.\n";
// ===== SNAPSMACK EOF =====
