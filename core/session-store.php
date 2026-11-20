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
// ===== SNAPSMACK EOF =====
