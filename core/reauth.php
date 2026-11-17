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
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
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

    // A LOCK THAT ALREADY EXISTS IS REPORTED, NOT RE-EARNED. reauth_verify
    // checks the budget before it gets here, so on the ordinary path this is
    // never true. It is true for the loser of the race above: that request
    // passed the budget check a moment before another one locked the account.
    // Without this it would record a fresh failure -- pre-loading the next
    // cycle with an attempt the owner never made -- and answer as though
    // nothing had been locked at all.
    try {
        $standing = _reauth_read_counter($pdo, $key, 'reauth_lock', $now);
        if ($standing !== null) {
            [$standing_cycle, $standing_ws] = $standing;
            $standing_sec = _reauth_lockout_seconds($standing_cycle);
            if ($standing_cycle > 0 && $now < $standing_ws + $standing_sec) {
                $retry = max(1, ($standing_ws + $standing_sec) - $now);
                $_SESSION['reauth_lock_until'] = $now + $retry;
                $_SESSION['reauth_fail_count'] = 0;
                _reauth_audit('blocked_while_locked', $uid,
                    ['reason' => $reason, 'cycle' => $standing_cycle, 'retry_after' => $retry]);
                return ['count' => REAUTH_MAX_ATTEMPTS, 'locked' => true,
                    'retry_after' => $retry, 'cycle' => $standing_cycle];
            }
        }
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: standing lock read failed - ' . $e->getMessage());
    }

    try {
        // A quiet period resets the current cycle's attempts before counting
        // this one, so five scattered typos over a week are not a lockout.
        $fail = _reauth_read_counter($pdo, $key, 'reauth_fail', $now);
        if ($fail !== null && ($now - $fail[1]) >= REAUTH_BASE_LOCKOUT_SECONDS) {
            _reauth_drop_counter($pdo, $key, 'reauth_fail');
        }
        $db_count = _reauth_bump_counter($pdo, $key, 'reauth_fail', $now);
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: failure record failed - ' . $e->getMessage());
    }

    $effective = max($db_count, $sess_count);
    $locked = $effective >= REAUTH_MAX_ATTEMPTS;
    $lock_sec = 0;

    if ($locked) {
        // ONE LOCK EPISODE PER LOCK, however many requests arrive at once.
        // Codex's third FAIL: every request that saw effective >= 5 bumped
        // the episode row, so two threshold requests landing together turned
        // one lock into cycle 2 and handed the owner half an hour for the
        // first offence. The spent attempt row elects the winner: DELETE is
        // atomic, exactly one caller can delete a row that exists, and that
        // caller is the one that advances the tier. The others read the lock
        // that was just created and report its remaining time.
        $won = true;
        $remaining = null;
        try {
            $del = $pdo->prepare(
                "DELETE FROM snap_rate_limits WHERE ip = ? AND action = 'reauth_fail'"
            );
            $del->execute([$key]);
            $existing = _reauth_read_counter($pdo, $key, 'reauth_lock', $now);
            // No row deleted AND a lock already standing means someone else
            // got there first. No lock row at all means nobody has, whatever
            // the delete said -- a database error must not lose the lock.
            $won = ($del->rowCount() > 0) || ($existing === null);
            if ($won) {
                $cycle = _reauth_bump_counter($pdo, $key, 'reauth_lock', $now);
            } else {
                [$cycle, $lock_ws] = $existing;
                $remaining = max(1, ($lock_ws + _reauth_lockout_seconds($cycle)) - $now);
            }
        } catch (PDOException $e) {
            error_log('SnapSmack reauth: lock escalation failed - ' . $e->getMessage());
            $cycle = max(1, $cycle);
        }
        $lock_sec = $remaining ?? _reauth_lockout_seconds(max(1, $cycle));
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
