<?php
/**
 * SNAPSMACK - CSRF token engine
 *
 * Generates and validates per-session CSRF tokens. Forms get a hidden
 * field auto-injected by core/admin-footer.php. AJAX/XHR/fetch calls
 * send the token in an X-CSRF-Token header (auto-attached by
 * assets/js/ss-engine-admin-csrf.js, which reads the token from the
 * <meta name="csrf-token"> tag emitted by core/admin-header.php).
 *
 * Validation runs in core/auth-smack.php before the page's POST handler executes.
 *
 * Public API:
 *   csrf_token()             — returns the session's current token (creates
 *                              one if not yet generated).
 *   csrf_field()             — echoes a ready-to-paste hidden input element.
 *   csrf_check()             — call at the top of any admin POST handler to
 *                              enforce. Bails with HTTP 403 on mismatch.
 *   csrf_meta_tag()          — emits the <meta> tag for the document head.
 *   csrf_exempt()            — call from non-admin endpoints (api.php,
 *                              multisite-api.php, login flow, etc.) BEFORE
 *                              including auth.php to opt out of the
 *                              automatic POST-time validation in auth.php.
 *
 * Endpoints that legitimately accept cross-origin POSTs (multisite hub-spoke
 * traffic, public AJAX from non-admin pages, login form before session
 * exists) call csrf_exempt() at their top so the auto-validator skips them.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        // Caller hasn't started the session yet — return a one-shot value
        // that will be invalid on validate. This lets pages emit the meta
        // tag without throwing, but POSTs without a real session will fail
        // closed which is the correct behaviour.
        return '';
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): void
{
    $t = csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES) . '">';
}

function csrf_meta_tag(): void
{
    $t = csrf_token();
    echo '<meta name="csrf-token" content="' . htmlspecialchars($t, ENT_QUOTES) . '">';
}

function csrf_exempt(): void
{
    if (!defined('SNAPSMACK_CSRF_EXEMPT')) {
        define('SNAPSMACK_CSRF_EXEMPT', true);
    }
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (defined('SNAPSMACK_CSRF_EXEMPT') && SNAPSMACK_CSRF_EXEMPT) return;

    // Some flows post without a session yet (login submit). Those endpoints
    // MUST call csrf_exempt() at the top. Anything else needs a session.
    if (session_status() === PHP_SESSION_NONE) {
        http_response_code(403);
        die('<h1>403 — Session required</h1>');
    }

    $supplied = $_POST['csrf_token']
             ?? $_SERVER['HTTP_X_CSRF_TOKEN']
             ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || $supplied === '' || !hash_equals($expected, $supplied)) {
        http_response_code(403);
        // Plain text body — easier to surface in JS error handlers and
        // avoids leaking page chrome on a security failure.
        header('Content-Type: text/plain; charset=utf-8');
        die("CSRF token mismatch. Reload the page and try again.");
    }
}

/**
 * SECAUDIT 047 — method-agnostic CSRF enforcement.
 *
 * csrf_check() intentionally only guards POST. Several admin actions are
 * triggered by GET links ("delete", "approve", "ban", etc.); those bypass
 * csrf_check() entirely, so a crafted <img>/link in an email could fire them
 * under a logged-in admin's cookies. Call csrf_verify() at the top of any
 * state-changing GET branch. It accepts the token from the query string
 * (?t=…), a POST field, or the X-CSRF-Token header, and fails closed.
 *
 * Emit the matching links with csrf_url(): csrf_url('smack-users.php?delete=5').
 */
function csrf_verify(): void
{
    if (defined('SNAPSMACK_CSRF_EXEMPT') && SNAPSMACK_CSRF_EXEMPT) return;
    if (session_status() === PHP_SESSION_NONE) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        die('CSRF check failed — no session.');
    }
    $supplied = $_GET['t']
             ?? $_POST['csrf_token']
             ?? $_SERVER['HTTP_X_CSRF_TOKEN']
             ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || $supplied === '' || !hash_equals($expected, (string)$supplied)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        die('CSRF token mismatch. Reload the page and try again.');
    }
}

/**
 * Append the session CSRF token to an admin action URL as ?t=…/&t=… so the
 * GET handler's csrf_verify() passes for genuine same-site clicks.
 */
function csrf_url(string $url): string
{
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $sep . 't=' . urlencode(csrf_token());
}

// Auto-rotate the token on logout — clears it from the session so the
// next login mints a fresh value. Other code (logout handlers) can call
// csrf_rotate() explicitly if they want to invalidate during a session.
function csrf_rotate(): void
{
    if (session_status() === PHP_SESSION_NONE) return;
    unset($_SESSION['csrf_token']);
}

/**
 * The token rule for a PUBLIC endpoint that must also serve guests.
 *
 * SS-1385, SS-1634, SS-1635. Three public write endpoints read no token at
 * all: the like endpoint, the community comment endpoint and the comment
 * endpoint. csrf_check() cannot be used on any of them, because it DIES 403
 * when there is no session, and a guest has no session: core/csrf.php's own
 * csrf_token() returns '' without one, so a visitor who never logged in has
 * no token to send. Using csrf_check() here would refuse every guest like and
 * every guest comment, which is a worse defect than the one being fixed.
 *
 * SO THE RULE IS, in order, and the middle one is the one that bites:
 *
 *   a session exists      -> a valid token is REQUIRED. This is the case that
 *                            matters: a signed-in member's cookie is the thing
 *                            a cross-site form abuses, and it is their name on
 *                            whatever gets written
 *   a token is supplied
 *   but does not match    -> REFUSED, session or not. A wrong token is never
 *                            better than no token; it means something is
 *                            replaying or guessing
 *   no session and
 *   no token              -> allowed. A guest has no identity to abuse through
 *                            their browser, and an attacker who wants to post
 *                            as an anonymous stranger can do it directly with
 *                            one request. Requiring a token here would buy
 *                            nothing and break the guest path the owner wants
 *
 * WHAT THIS DOES NOT CLAIM. It does not protect a guest's address or
 * fingerprint from being used by somebody else's browser; that is SS-1635 and
 * it needs a rate limit, not a token. Stated here so nobody reads this helper
 * as covering more than it does.
 *
 * @return bool true when the request may proceed. The caller answers; this
 *              function never prints or exits, because these endpoints speak
 *              JSON and a die() with HTML in it breaks their clients.
 */
function csrf_ok_public(): bool
{
    if (defined('SNAPSMACK_CSRF_EXEMPT') && SNAPSMACK_CSRF_EXEMPT) return true;

    $supplied = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = '';
    if (session_status() === PHP_SESSION_ACTIVE) {
        $expected = (string)($_SESSION['csrf_token'] ?? '');
    }

    if ($expected !== '') {
        return $supplied !== '' && hash_equals($expected, $supplied);
    }
    // No session token to compare against. A supplied token cannot be
    // validated, so it is refused rather than ignored: ignoring it would make
    // a replayed token indistinguishable from none at all.
    return $supplied === '';
}
// ===== SNAPSMACK EOF =====
