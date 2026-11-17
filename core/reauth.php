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

/**
 * Compute lockout duration (seconds) with exponential backoff for repeated failures.
 */
function _reauth_lockout_seconds(int $count): int {
    if ($count < REAUTH_MAX_ATTEMPTS) {
        return 0;
    }
    $tier = min(2, intdiv($count - REAUTH_MAX_ATTEMPTS, REAUTH_MAX_ATTEMPTS));
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
 * Inspect server-side (snap_rate_limits) + per-session failure budget.
 *
 * @return array{locked:bool,retry_after:int,count:int}
 */
function _reauth_get_budget(PDO $pdo, int $uid, int $now): array {
    $key = 'uid:' . $uid;
    $db_count = 0;
    $db_locked = false;
    $db_retry = 0;

    try {
        $stmt = $pdo->prepare(
            "SELECT count, window_start FROM snap_rate_limits
             WHERE ip = ? AND action = 'reauth_fail' LIMIT 1"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $db_count = (int)$row['count'];
            $ws = strtotime((string)$row['window_start'] . ' UTC');
            if ($ws === false) {
                $ws = $now;
            }
            $lock_sec = _reauth_lockout_seconds($db_count);
            if ($db_count >= REAUTH_MAX_ATTEMPTS) {
                if ($now < $ws + $lock_sec) {
                    $db_locked = true;
                    $db_retry = max(1, ($ws + $lock_sec) - $now);
                } else {
                    $pdo->prepare("DELETE FROM snap_rate_limits WHERE ip = ? AND action = 'reauth_fail'")
                        ->execute([$key]);
                    $db_count = 0;
                }
            } elseif ($db_count > 0 && ($now - $ws) >= REAUTH_BASE_LOCKOUT_SECONDS) {
                $pdo->prepare("DELETE FROM snap_rate_limits WHERE ip = ? AND action = 'reauth_fail'")
                    ->execute([$key]);
                $db_count = 0;
            }
        }
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: budget read failed — ' . $e->getMessage());
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
    ];
}

/**
 * Record a failed step-up attempt in both the session and server-side store.
 *
 * @return array{count:int,locked:bool,retry_after:int}
 */
function _reauth_record_failure(PDO $pdo, int $uid, string $reason, int $now): array {
    $sess_count = (int)($_SESSION['reauth_fail_count'] ?? 0) + 1;
    $_SESSION['reauth_fail_count'] = $sess_count;

    $key = 'uid:' . $uid;
    $now_str = gmdate('Y-m-d H:i:s', $now);
    $db_count = $sess_count;

    try {
        $stmt = $pdo->prepare(
            "SELECT count, window_start FROM snap_rate_limits
             WHERE ip = ? AND action = 'reauth_fail' LIMIT 1"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $ws = strtotime((string)$row['window_start'] . ' UTC');
            $stale = ($ws === false) || (($now - $ws) >= REAUTH_BASE_LOCKOUT_SECONDS && (int)$row['count'] < REAUTH_MAX_ATTEMPTS);
            if ($stale) {
                $db_count = 1;
                $pdo->prepare(
                    "UPDATE snap_rate_limits SET count = 1, window_start = ?
                     WHERE ip = ? AND action = 'reauth_fail'"
                )->execute([$now_str, $key]);
            } else {
                $db_count = (int)$row['count'] + 1;
                $pdo->prepare(
                    "UPDATE snap_rate_limits SET count = ?, window_start = ?
                     WHERE ip = ? AND action = 'reauth_fail'"
                )->execute([$db_count, $now_str, $key]);
            }
        } else {
            $db_count = 1;
            $pdo->prepare(
                "INSERT INTO snap_rate_limits (ip, action, count, window_start)
                 VALUES (?, 'reauth_fail', 1, ?)"
            )->execute([$key, $now_str]);
        }
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: failure record failed — ' . $e->getMessage());
    }

    $effective = max($db_count, $sess_count);
    $lock_sec = _reauth_lockout_seconds($effective);
    $locked = $effective >= REAUTH_MAX_ATTEMPTS;
    if ($locked) {
        $_SESSION['reauth_lock_until'] = $now + $lock_sec;
        _reauth_audit('lockout', $uid, ['reason' => $reason, 'count' => $effective, 'lock_sec' => $lock_sec]);
    } else {
        _reauth_audit('failure', $uid, ['reason' => $reason, 'count' => $effective]);
    }

    return [
        'count'       => $effective,
        'locked'      => $locked,
        'retry_after' => $lock_sec,
    ];
}

/**
 * Clear step-up failure budgets on successful verification.
 */
function _reauth_clear_budget(PDO $pdo, int $uid): void {
    unset($_SESSION['reauth_fail_count'], $_SESSION['reauth_lock_until']);
    try {
        $pdo->prepare("DELETE FROM snap_rate_limits WHERE ip = ? AND action = 'reauth_fail'")
            ->execute(['uid:' . $uid]);
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: budget clear failed — ' . $e->getMessage());
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
