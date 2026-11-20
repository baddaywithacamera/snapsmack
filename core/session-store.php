<?php
/**
 * SNAPSMACK — Session store location
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * ONE PLACE THAT KNOWS WHERE SESSIONS LIVE.
 *
 * SS-1470. core/auth-smack.php has always pointed sessions at a private
 * directory inside the install, because shared cPanel hosts run a cron that
 * purges /tmp/sess_* every ~20 minutes no matter what gc_maxlifetime says.
 * core/maintenance-gate.php then called session_start() WITHOUT that path, so
 * on a public page it opened the DEFAULT store, found nothing, and read
 * $_SESSION['user_login'] as empty.
 *
 * The consequence, which Codex reproduced on a live 0.7.999D install: with
 * maintenance mode on, the owner was shown the holding page on their own site.
 * The gate's "logged-in users see the site normally" branch was correct code
 * that could never run, because its input was always missing. That is the same
 * fault shape as the restore containment checks repaired earlier this week --
 * a guard whose input is absent does not fail, it just does not fire.
 *
 * I ALSO TOLD SEAN THE OPPOSITE, an hour before this was filed: that he could
 * verify his new site while logged in without revealing it publicly. I had
 * read the early return and not run it. Reading a branch is not evidence that
 * the branch is reachable.
 *
 * So the path lives here and both callers use it. Two copies of this decision
 * is what made one of them wrong.
 */

/**
 * Point PHP at SnapSmack's private session directory, creating it if needed.
 *
 * Call BEFORE session_start(), in every entry point that reads a session.
 * Safe to call more than once and after a session has started: it does
 * nothing once a session is active, because session_save_path() cannot change
 * then and attempting it emits a warning.
 *
 * @return bool true when the private store is in use; false when the
 *              directory is unusable and PHP's default store stays in effect.
 */
function snap_use_private_session_store(): bool {
    if (session_status() !== PHP_SESSION_NONE) {
        // Already running. Report whether it is our store rather than guessing.
        return rtrim((string)session_save_path(), '/\\')
            === rtrim(snap_private_session_dir(), '/\\');
    }

    snap_apply_session_cookie_params();

    $dir = snap_private_session_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        // Deny-all so session files are never web-readable, in both Apache
        // forms. Copied from the form used elsewhere in the tree rather than
        // invented here.
        @file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
        return true;
    }
    // Fail OPEN on the store, deliberately: a host where the directory cannot
    // be written still needs working logins, and PHP's default store works --
    // it is only vulnerable to the host's own cron. Refusing to start a session
    // here would lock the owner out of their admin entirely, which is worse
    // than the problem being worked around.
    return false;
}

/** Absolute path of the private session directory. */
function snap_private_session_dir(): string {
    return dirname(__DIR__) . '/data/sessions';
}

/**
 * Shape the session cookie, identically wherever a session is started.
 *
 * These values were inline in core/auth-smack.php and are unchanged: a day's
 * lifetime, site-wide path, secure when the request is HTTPS directly or
 * through a proxy, HttpOnly, SameSite Lax. The gc_maxlifetime goes with them
 * because it governs the SHARED store -- a public request that started a
 * session with PHP's shorter default could let garbage collection remove the
 * owner's session file early, which is the same class of surprise SS-1470 was.
 *
 * WHY THE GATE NEEDS THIS AT ALL, since it only ever resumes. Measured: a
 * resume emits no Set-Cookie, so on this box these params are never used by
 * the gate. But session.use_strict_mode is 0 here and a hardened host may set
 * it to 1, and there PHP answers an unknown session id by minting a NEW
 * session and sending a cookie -- which, without this call, would go out from
 * a public page with PHP's defaults: no HttpOnly, no SameSite, not secure.
 * Rare, and a cookie nobody is logged in to, but it costs one call to be the
 * same everywhere rather than correct only where I happened to test.
 */
function snap_apply_session_cookie_params(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                   || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', '86400');
}
// ===== SNAPSMACK EOF =====
