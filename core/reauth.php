<?php
/**
 * SNAPSMACK - Step-up (re-authentication) helper
 *
 * Verifies the CURRENTLY LOGGED-IN admin's credentials again before a sensitive
 * action (disabling SMACKBACK, posting to the support forum while in breach
 * lockdown, pruning DB drift, etc.). ALWAYS requires the account password AND a
 * valid TOTP code — there is no password-only path. An account that has not yet
 * enrolled 2FA cannot perform a step-up action; reauth_verify returns
 * needs_2fa_enrollment=true so the caller can force enrolment. (The 30-day
 * post-install 2FA grace is for LOGIN only — critical actions never get it.)
 *
 * Shared so every step-up gate behaves identically — see [[feedback_reuse_proven_patterns]].
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/client-ip.php';   // SS-1437: the real client, not the CDN edge

if (!defined('REAUTH_MAX_ATTEMPTS')) {
    define('REAUTH_MAX_ATTEMPTS', 5);
}
if (!defined('REAUTH_BASE_LOCKOUT_SECONDS')) {
    define('REAUTH_BASE_LOCKOUT_SECONDS', 900); // 15 minutes
}
// HOW LONG AN ESCALATION IS REMEMBERED. Without this, one bad afternoon a year
// ago would put the owner straight onto the hour-long tier for the rest of the
// install's life. A day of clean behaviour puts them back at the first tier.
if (!defined('REAUTH_CYCLE_MEMORY_SECONDS')) {
    define('REAUTH_CYCLE_MEMORY_SECONDS', 86400); // 24 hours
}

/**
 * TWO ROWS, NOT ONE. SS-1399, after Codex's FAIL on 86346091.
 *
 * The first version kept a single snap_rate_limits row and DELETED it the
 * moment a lock expired. So the next cycle started at count 1, the fifth
 * failure locked for 900 seconds again, and the 1800- and 3600-second tiers
 * documented in the code were unreachable. Codex proved it at runtime:
 * first_lock_seconds=900, second_lock_seconds=900. The shipped test only ever
 * exercised the first lock, so it could not see it.
 *
 * The fix needs two separate facts, and one integer column cannot hold both:
 *   'reauth_fail' - failures inside the CURRENT cycle. Reset at each lock.
 *   'reauth_lock' - how many lock episodes this account has had, with
 *                   window_start = when the current one began. SURVIVES the
 *                   lock expiring; that survival IS the escalation.
 * snap_rate_limits is UNIQUE(ip, action), so the second row is free: same
 * 'uid:N' key, different action. No schema change, so nothing has to reach an
 * install before this works.
 *
 * Each expired lock still hands back a full set of attempts, so a legitimate
 * owner who mistyped is never one keystroke from another lock -- the price of
 * burning the whole budget again is the NEXT tier up.
 */

/**
 * Lockout duration for the Nth lock episode: 15 minutes, 30, then an hour.
 */
function _reauth_lockout_seconds(int $cycle): int {
    if ($cycle < 1) {
        return 0;
    }
    $tier = min(2, $cycle - 1);
    return min(3600, REAUTH_BASE_LOCKOUT_SECONDS * (1 << $tier));
}

/**
 * Format a clear user-facing lockout message.
 */
function _reauth_locked_message(int $retry_after): string {
    $mins = max(1, (int)ceil($retry_after / 60));
    return "Too many failed verification attempts. Locked for {$mins} minute(s); try again later.";
}

/**
 * Write an audit line for step-up failures and lockouts (never logs secrets).
 */
function _reauth_audit(string $event, int $uid, array $meta = []): void {
    // SS-1437. This used to log REMOTE_ADDR. On any site behind Cloudflare --
    // which is most of the fleet -- that is the edge address, so every step-up
    // failure in the log carried the same handful of IPs and the audit trail
    // could not tell one attacker from another, or from the owner.
    //
    // NOT A LOCKOUT BUG, said precisely: the step-up budget keys on 'uid:N'
    // (see below, two places), not on the address, so a shared edge address
    // never locked one user out because of another. This is the forensic
    // record only, and it was useless.
    //
    // snap_trusted_client_ip() is the one resolver -- SECAUDIT 035 -- and it
    // ignores the forgeable headers unless the peer is a proxy we trust. No
    // $pdo here, so the trusted-proxy list falls back to loopback; Cloudflare
    // is recognised from its own published networks and needs no settings.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? snap_trusted_client_ip() : 'cli';
    $parts = ["SnapSmack reauth {$event}", "uid={$uid}", "ip={$ip}"];
    foreach ($meta as $k => $v) {
        $parts[] = "{$k}={$v}";
    }
    error_log(implode(' ', $parts));
}

/**
 * Read one counter row. Returns [count, window_start_unix] or null.
 */
function _reauth_read_counter(PDO $pdo, string $key, string $action, int $now): ?array {
    $stmt = $pdo->prepare(
        "SELECT count, window_start FROM snap_rate_limits
         WHERE ip = ? AND action = ? LIMIT 1"
    );
    $stmt->execute([$key, $action]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $ws = strtotime((string)$row['window_start'] . ' UTC');
    return [(int)$row['count'], $ws === false ? $now : $ws];
}

/**
 * Add one to a counter row WITHOUT a read-modify-write.
 *
 * Codex's third point. The old code did SELECT count, then UPDATE count = N+1,
 * so two failures arriving from separate sessions could both read 3 and both
 * write 4 -- one failure lost from a budget whose whole job is to count them.
 * "UPDATE ... SET count = count + 1" is one statement and the database settles
 * the order. The INSERT is tried first; a duplicate-key error means another
 * request created the row a moment earlier, which is the same race seen from
 * the other side, and falling through to the UPDATE handles it.
 *
 * ON DUPLICATE KEY UPDATE is deliberately NOT used: it is MySQL and MariaDB
 * only, and the regression runs on SQLite. This form behaves the same on both.
 *
 * The count read back afterwards may already include a concurrent increment.
 * That direction is safe: it locks a fraction sooner, never later.
 */
function _reauth_bump_counter(PDO $pdo, string $key, string $action, int $now): int {
    $now_str = gmdate('Y-m-d H:i:s', $now);
    try {
        $pdo->prepare(
            "INSERT INTO snap_rate_limits (ip, action, count, window_start)
             VALUES (?, ?, 1, ?)"
        )->execute([$key, $action, $now_str]);
        return 1;
    } catch (PDOException $e) {
        // Row already exists - fall through to the atomic increment.
    }
    // window_start MOVES WITH THE COUNT, and it has to be in the SAME
    // statement. Codex's second FAIL: my first atomic version incremented the
    // count and left window_start alone, so the row expired 15 minutes after
    // the FIRST failure rather than 15 after the LAST. Wrong passwords spaced
    // four minutes apart from fresh sessions then never locked anything --
    // db_count went 1,2,3,4,1 -- and the only reason it looked right in
    // testing is that the SESSION counter was masking the broken shared
    // budget. window_start means "the last failure", so the quiet period is a
    // quiet period.
    $pdo->prepare(
        "UPDATE snap_rate_limits SET count = count + 1, window_start = ?
         WHERE ip = ? AND action = ?"
    )->execute([$now_str, $key, $action]);
    $read = _reauth_read_counter($pdo, $key, $action, $now);
    return $read === null ? 1 : $read[0];
}

/** Delete one counter row. */
function _reauth_drop_counter(PDO $pdo, string $key, string $action): void {
    $pdo->prepare("DELETE FROM snap_rate_limits WHERE ip = ? AND action = ?")
        ->execute([$key, $action]);
}

/**
 * Take one back off a counter row, atomically, and delete it at zero.
 *
 * SS-1399 r5. A request that records a failure and then discovers the account
 * was locked while it was working has counted an attempt against the NEXT
 * cycle that the owner never spent. Undoing it has to be as unconditional as
 * the bump was: "SET count = count - 1" in one statement, with a WHERE that
 * cannot take a row below zero however many requests unwind at once. The
 * second statement removes a row that has reached nothing, so the next cycle
 * starts from no row at all rather than from a zero that still carries an old
 * window_start.
 *
 * window_start is deliberately NOT moved back. It means "the last failure",
 * and the last failure a real person made is still the time to measure a
 * quiet period from; rewinding it would hand an attacker a way to push the
 * quiet period backwards by racing.
 */
function _reauth_unbump_counter(PDO $pdo, string $key, string $action): void {
    $pdo->prepare(
        "UPDATE snap_rate_limits SET count = count - 1
         WHERE ip = ? AND action = ? AND count > 0"
    )->execute([$key, $action]);
    $pdo->prepare(
        "DELETE FROM snap_rate_limits WHERE ip = ? AND action = ? AND count <= 0"
    )->execute([$key, $action]);
}

/**
 * Report the lock episode that is running RIGHT NOW, or null if none is.
 *
 * SS-1399 r5, from Codex's r4 FAIL. r4 read the standing lock once, before it
 * counted the failure, and a lock opened after that read was invisible for
 * the rest of the request: the loser bumped a fresh reauth_fail row to 1, saw
 * 1 < 5, and answered "code incorrect" to an account that was already locked.
 *
 * One read, in one place, so the SAME question can be asked twice in a single
 * request -- before the bump and again after it -- without the two answers
 * being written in two different ways and drifting apart. A standing lock is
 * a fact about the account at the instant it is read; r4's mistake was
 * treating the first reading as if it stayed true.
 *
 * @return array{cycle:int,retry_after:int}|null
 */
function _reauth_standing_lock(PDO $pdo, string $key, int $now): ?array {
    $standing = _reauth_read_counter($pdo, $key, 'reauth_lock', $now);
    if ($standing === null) {
        return null;
    }
    [$cycle, $ws] = $standing;
    if ($cycle < 1) {
        return null;
    }
    $lock_sec = _reauth_lockout_seconds($cycle);
    if ($now >= $ws + $lock_sec) {
        return null;
    }
    return ['cycle' => $cycle, 'retry_after' => max(1, ($ws + $lock_sec) - $now)];
}

/**
 * Advance the episode counter, but ONLY if nobody has since this caller read
 * the row. $observed is the window_start it read; the first caller through
 * changes that value, so every later caller's WHERE matches nothing and gets
 * a rowCount of 0.
 *
 * It is a function of its own so the guard can be TESTED: a test can hand it
 * a window_start that is deliberately stale -- which is exactly what a
 * request that lost this race holds -- and require that it changes nothing.
 * Inline, the guard was unreachable from any sequential test, because by the
 * time a second caller looks the lock is no longer expired. That is how a
 * guard ends up removed by someone who sees every test still pass.
 *
 * @return int rows changed: 1 for the winner, 0 for everyone else.
 */
function _reauth_escalate_episode(PDO $pdo, string $key, string $observed, int $now): int {
    $upd = $pdo->prepare(
        "UPDATE snap_rate_limits SET count = count + 1, window_start = ?
         WHERE ip = ? AND action = 'reauth_lock' AND window_start = ?"
    );
    $upd->execute([gmdate('Y-m-d H:i:s', $now), $key, $observed]);
    return (int)$upd->rowCount();
}

/**
 * OPEN A LOCK EPISODE, EXACTLY ONCE, WHOEVER ARRIVES.
 *
 * Round 4. Muse found that my first election still had a double winner:
 *
 *   $won = ($del->rowCount() > 0) || ($existing === null);
 *
 * Two requests can both read the episode row before either writes it, so
 * $existing is null for both. A wins on the delete; B wins on the second
 * clause having deleted nothing. Both bump, and a first offence costs the
 * second tier. The fallback was there so a database hiccup could not lose the
 * lock entirely, and it quietly re-opened the hole it was next to.
 *
 * THE DELETE WAS THE WRONG PRIMITIVE. It can only be exclusive when the row
 * it deletes exists, and the case that matters is the one where nothing
 * exists yet. Two operations that ARE exclusive, whatever the interleaving:
 *
 *   1. INSERT the episode row. snap_rate_limits is UNIQUE(ip, action), so of
 *      any number of simultaneous callers exactly one INSERT succeeds and the
 *      rest get a duplicate-key error. That decides the FIRST episode.
 *   2. For a later episode, UPDATE ... WHERE window_start = <the value this
 *      caller read>. The first update changes that value, so every other
 *      caller's WHERE no longer matches and its rowCount is 0. That decides
 *      each ESCALATION.
 *
 * A caller that loses either race re-reads and reports the lock that now
 * exists. Nobody is turned away unlocked, and nobody pays for someone else's
 * episode. Both statements are ordinary SQL on MySQL, MariaDB and SQLite.
 *
 * @return array{cycle:int,retry_after:int,opened:bool}
 */
function _reauth_open_lock_episode(PDO $pdo, string $key, int $now): array {
    $now_str = gmdate('Y-m-d H:i:s', $now);

    $report = static function (PDO $pdo, string $key, int $now, int $fallback): array {
        $row = _reauth_read_counter($pdo, $key, 'reauth_lock', $now);
        if ($row === null) {
            // The row we just lost a race to has gone. Nothing to report but
            // a lock, so report the shortest honest one rather than none.
            return ['cycle' => max(1, $fallback),
                'retry_after' => _reauth_lockout_seconds(max(1, $fallback)),
                'opened' => false];
        }
        [$cycle, $ws] = $row;
        $span = _reauth_lockout_seconds(max(1, $cycle));
        return ['cycle' => max(1, $cycle),
            'retry_after' => max(1, ($ws + $span) - $now),
            'opened' => false];
    };

    // --- 1. the first episode: exclusive INSERT -------------------------
    try {
        $pdo->prepare(
            "INSERT INTO snap_rate_limits (ip, action, count, window_start)
             VALUES (?, 'reauth_lock', 1, ?)"
        )->execute([$key, $now_str]);
        return ['cycle' => 1, 'retry_after' => _reauth_lockout_seconds(1), 'opened' => true];
    } catch (PDOException $e) {
        // A row exists. Either someone just won this race, or there is a
        // history from before. Both are handled below.
    }

    $stmt = $pdo->prepare(
        "SELECT count, window_start FROM snap_rate_limits
         WHERE ip = ? AND action = 'reauth_lock' LIMIT 1"
    );
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $report($pdo, $key, $now, 1);
    }
    $cycle = max(1, (int)$row['count']);
    $observed = (string)$row['window_start'];
    $ws = strtotime($observed . ' UTC');
    if ($ws === false) {
        $ws = $now;
    }

    // --- 2. a lock that is still running is reported, not re-earned -----
    if ($now < $ws + _reauth_lockout_seconds($cycle)) {
        return ['cycle' => $cycle,
            'retry_after' => max(1, ($ws + _reauth_lockout_seconds($cycle)) - $now),
            'opened' => false];
    }

    // --- 3. the previous lock expired: escalate, exclusively ------------
    if (_reauth_escalate_episode($pdo, $key, $observed, $now) > 0) {
        $next = $cycle + 1;
        return ['cycle' => $next, 'retry_after' => _reauth_lockout_seconds($next),
            'opened' => true];
    }
    return $report($pdo, $key, $now, $cycle);
}

/**
 * Inspect server-side (snap_rate_limits) + per-session failure budget.
 *
 * @return array{locked:bool,retry_after:int,count:int,cycle:int}
 */
function _reauth_get_budget(PDO $pdo, int $uid, int $now): array {
    $key = 'uid:' . $uid;
    $db_count = 0;
    $db_locked = false;
    $db_retry = 0;
    $cycle = 0;

    try {
        // --- the escalation row -------------------------------------------
        $lock = _reauth_read_counter($pdo, $key, 'reauth_lock', $now);
        if ($lock !== null) {
            [$cycle, $lock_ws] = $lock;
            if ($cycle > 0 && $now >= $lock_ws + REAUTH_CYCLE_MEMORY_SECONDS) {
                // A clear day. Forget the escalation entirely.
                _reauth_drop_counter($pdo, $key, 'reauth_lock');
                $cycle = 0;
            } else {
                $lock_sec = _reauth_lockout_seconds($cycle);
                if ($now < $lock_ws + $lock_sec) {
                    $db_locked = true;
                    $db_retry = max(1, ($lock_ws + $lock_sec) - $now);
                }
            }
        }

        // --- failures inside the current cycle -----------------------------
        $fail = _reauth_read_counter($pdo, $key, 'reauth_fail', $now);
        if ($fail !== null) {
            [$db_count, $fail_ws] = $fail;
            if ($db_count > 0 && ($now - $fail_ws) >= REAUTH_BASE_LOCKOUT_SECONDS) {
                _reauth_drop_counter($pdo, $key, 'reauth_fail');
                $db_count = 0;
            }
        }
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: budget read failed - ' . $e->getMessage());
    }

    $sess_locked = false;
    $sess_retry = 0;
    $sess_until = (int)($_SESSION['reauth_lock_until'] ?? 0);
    if ($sess_until > $now) {
        $sess_locked = true;
        $sess_retry = max(1, $sess_until - $now);
    } elseif ($sess_until > 0 && $now >= $sess_until) {
        unset($_SESSION['reauth_fail_count'], $_SESSION['reauth_lock_until']);
    }

    return [
        'locked'      => $db_locked || $sess_locked,
        'retry_after' => max($db_retry, $sess_retry),
        'count'       => max($db_count, (int)($_SESSION['reauth_fail_count'] ?? 0)),
        'cycle'       => $cycle,
    ];
}

/**
 * Record a failed step-up attempt in both the session and server-side store.
 *
 * @return array{count:int,locked:bool,retry_after:int,cycle:int}
 */
function _reauth_record_failure(PDO $pdo, int $uid, string $reason, int $now): array {
    $sess_count = (int)($_SESSION['reauth_fail_count'] ?? 0) + 1;
    $_SESSION['reauth_fail_count'] = $sess_count;

    $key = 'uid:' . $uid;
    $db_count = $sess_count;
    $cycle = 0;

    // THE ANSWER A STANDING LOCK GETS, wherever it is discovered.
    //
    // Both checks below end here, so the account is told the same thing
    // whether the lock was already open when the request arrived or opened
    // while it was working: the episode and the time left on it, the session
    // marked locked, this cycle's session count cleared so the next attempt
    // is not double-counted, and a blocked_while_locked audit line rather
    // than an ordinary failure.
    $blocked = static function (array $lock) use ($uid, $reason, $now): array {
        $_SESSION['reauth_lock_until'] = $now + $lock['retry_after'];
        $_SESSION['reauth_fail_count'] = 0;
        _reauth_audit('blocked_while_locked', $uid,
            ['reason' => $reason, 'cycle' => $lock['cycle'],
             'retry_after' => $lock['retry_after']]);
        return ['count' => REAUTH_MAX_ATTEMPTS, 'locked' => true,
            'retry_after' => $lock['retry_after'], 'cycle' => $lock['cycle']];
    };

    // A LOCK THAT ALREADY EXISTS IS REPORTED, NOT RE-EARNED. reauth_verify
    // checks the budget before it gets here, so on the ordinary path this is
    // never true. It is true for a request that passed that check a moment
    // before another one locked the account. Without it, this failure would
    // be counted against an account that has already stopped accepting
    // attempts, and answered as though nothing had been locked at all.
    try {
        $standing = _reauth_standing_lock($pdo, $key, $now);
        if ($standing !== null) {
            return $blocked($standing);
        }
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: standing lock read failed - ' . $e->getMessage());
    }

    $bumped = false;
    try {
        // A quiet period resets the current cycle's attempts before counting
        // this one, so five scattered typos over a week are not a lockout.
        $fail = _reauth_read_counter($pdo, $key, 'reauth_fail', $now);
        if ($fail !== null && ($now - $fail[1]) >= REAUTH_BASE_LOCKOUT_SECONDS) {
            _reauth_drop_counter($pdo, $key, 'reauth_fail');
        }
        $db_count = _reauth_bump_counter($pdo, $key, 'reauth_fail', $now);
        $bumped = true;
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: failure record failed - ' . $e->getMessage());
    }

    // ASK AGAIN, AFTER THE BUMP. SS-1399 r5, from Codex's r4 FAIL.
    //
    // The check above is a reading, not a promise. Between it and the bump,
    // another request can reach the fifth failure, drop reauth_fail, and open
    // the lock. r4 never looked again, so that interleaving ended like this:
    // this request's bump re-created reauth_fail at 1, "1 < 5" sent the
    // account an ordinary "code incorrect" while it was locked, and the 1 sat
    // there waiting to be counted against the owner's next cycle. The attempt
    // budget leaked an attempt per lost race, and the lock went unreported to
    // the one request best placed to report it.
    //
    // So the same question is asked a second time, after the counter moves.
    // A lock found here means this failure was counted after the account had
    // already paid for it, and the increment comes back off before anyone is
    // told anything. Reading after the write is the only ordering that can
    // see a write that happened during it.
    if ($bumped) {
        try {
            $standing = _reauth_standing_lock($pdo, $key, $now);
            if ($standing !== null) {
                _reauth_unbump_counter($pdo, $key, 'reauth_fail');
                return $blocked($standing);
            }
        } catch (PDOException $e) {
            error_log('SnapSmack reauth: post-bump lock read failed - ' . $e->getMessage());
        }
    }

    $effective = max($db_count, $sess_count);
    $locked = $effective >= REAUTH_MAX_ATTEMPTS;
    $lock_sec = 0;

    if ($locked) {
        // ONE LOCK EPISODE PER LOCK, however many requests arrive at once.
        // The whole decision is in _reauth_open_lock_episode(), which uses two
        // exclusive statements instead of the delete-and-hope this used to do.
        $cycle = 0;
        $lock_sec = 0;
        try {
            // This cycle's attempts are spent either way.
            _reauth_drop_counter($pdo, $key, 'reauth_fail');
            $episode = _reauth_open_lock_episode($pdo, $key, $now);
            $cycle = (int)$episode['cycle'];
            $lock_sec = (int)$episode['retry_after'];
        } catch (PDOException $e) {
            error_log('SnapSmack reauth: lock escalation failed - ' . $e->getMessage());
            $cycle = max(1, $cycle);
            $lock_sec = _reauth_lockout_seconds($cycle);
        }
        if ($lock_sec < 1) {
            $lock_sec = _reauth_lockout_seconds(max(1, $cycle));
        }
        $_SESSION['reauth_lock_until'] = $now + $lock_sec;
        $_SESSION['reauth_fail_count'] = 0;
        _reauth_audit('lockout', $uid, ['reason' => $reason, 'count' => $effective,
            'cycle' => $cycle, 'lock_sec' => $lock_sec]);
    } else {
        _reauth_audit('failure', $uid, ['reason' => $reason, 'count' => $effective]);
    }

    return [
        'count'       => $effective,
        'locked'      => $locked,
        'retry_after' => $lock_sec,
        'cycle'       => $cycle,
    ];
}

/**
 * Clear step-up failure budgets on successful verification.
 *
 * BOTH rows go, the escalation included. Proving you are the account holder
 * is the evidence the budget exists to ask for; keeping the tier after that
 * would punish the owner for a password manager that filled the wrong field.
 */
function _reauth_clear_budget(PDO $pdo, int $uid): void {
    unset($_SESSION['reauth_fail_count'], $_SESSION['reauth_lock_until']);
    try {
        $pdo->prepare("DELETE FROM snap_rate_limits WHERE ip = ? AND action IN ('reauth_fail','reauth_lock')")
            ->execute(['uid:' . $uid]);
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: budget clear failed - ' . $e->getMessage());
    }
}

/**
 * Re-verify the logged-in admin.
 *
 * @return array{ok:bool,error:string,locked?:bool,retry_after?:int,needs_2fa_enrollment?:bool,role?:string}
 */
function reauth_verify(PDO $pdo, string $password, string $totp_code = '', ?int $now = null): array {
    $now = $now ?? time();
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        return ['ok' => false, 'error' => 'No active session.'];
    }
    $uid_int = (int)$uid;

    $budget = _reauth_get_budget($pdo, $uid_int, $now);
    if ($budget['locked']) {
        _reauth_audit('blocked_while_locked', $uid_int, ['retry_after' => $budget['retry_after']]);
        return [
            'ok'          => false,
            'locked'      => true,
            'retry_after' => $budget['retry_after'],
            'error'       => _reauth_locked_message($budget['retry_after']),
        ];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT password_hash, totp_enabled, totp_secret, user_role FROM snap_users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$uid_int]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: user lookup failed — ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Verification unavailable. Try again.'];
    }
    if (!$u) {
        return ['ok' => false, 'error' => 'Account not found.'];
    }
    if ($password === '' || !password_verify($password, $u['password_hash'])) {
        $res = _reauth_record_failure($pdo, $uid_int, 'bad_password', $now);
        if ($res['locked']) {
            return [
                'ok'          => false,
                'locked'      => true,
                'retry_after' => $res['retry_after'],
                'error'       => _reauth_locked_message($res['retry_after']),
            ];
        }
        return ['ok' => false, 'error' => 'Password incorrect.'];
    }
    // POLICY (Sean, 2026-06-17): step-up auth is ALWAYS password + TOTP. There is
    // no password-only fallback. An account that has not enrolled 2FA cannot
    // perform a step-up action — it must enrol first. The 30-day post-install
    // grace period applies to LOGIN only; it does NOT extend to critical actions.
    // Callers can route on 'needs_2fa_enrollment' to send the admin to enrolment.
    $enrolled = !empty($u['totp_enabled']) && !empty($u['totp_secret']);
    if (!$enrolled) {
        return [
            'ok'                  => false,
            'error'               => 'Two-factor authentication is required for this action. Set up 2FA to continue.',
            'needs_2fa_enrollment'=> true,
        ];
    }
    $code = preg_replace('/\s+/', '', $totp_code);
    if ($code === '' || !totp_verify($u['totp_secret'], $code)) {
        $res = _reauth_record_failure($pdo, $uid_int, 'bad_totp', $now);
        if ($res['locked']) {
            return [
                'ok'          => false,
                'locked'      => true,
                'retry_after' => $res['retry_after'],
                'error'       => _reauth_locked_message($res['retry_after']),
            ];
        }
        return ['ok' => false, 'error' => 'Authenticator code incorrect.'];
    }
    _reauth_clear_budget($pdo, $uid_int);
    return ['ok' => true, 'error' => '', 'role' => (string)($u['user_role'] ?? 'editor')];
}

/**
 * Grant a time-boxed step-up window in the session (used by the forum breach
 * re-auth: a successful re-auth earns N minutes of posting permission).
 */
function reauth_grant_window(string $scope, int $minutes): void {
    $_SESSION['reauth_window'][$scope] = time() + ($minutes * 60);
}

/**
 * Is there an unexpired step-up window for this scope?
 */
function reauth_window_active(string $scope): bool {
    $exp = $_SESSION['reauth_window'][$scope] ?? 0;
    return is_int($exp) && $exp > time();
}
// ===== SNAPSMACK EOF =====
