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

/**
 * Re-verify the logged-in admin.
 *
 * @return array{ok:bool,error:string}
 */
function reauth_verify(PDO $pdo, string $password, string $totp_code = ''): array {
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        return ['ok' => false, 'error' => 'No active session.'];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT password_hash, totp_enabled, totp_secret FROM snap_users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$uid]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('SnapSmack reauth: user lookup failed — ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Verification unavailable. Try again.'];
    }
    if (!$u) {
        return ['ok' => false, 'error' => 'Account not found.'];
    }
    if ($password === '' || !password_verify($password, $u['password_hash'])) {
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
        return ['ok' => false, 'error' => 'Authenticator code incorrect.'];
    }
    return ['ok' => true, 'error' => ''];
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
