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
 * Validation runs in core/auth.php before the page's POST handler executes.
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

// Auto-rotate the token on logout — clears it from the session so the
// next login mints a fresh value. Other code (logout handlers) can call
// csrf_rotate() explicitly if they want to invalidate during a session.
function csrf_rotate(): void
{
    if (session_status() === PHP_SESSION_NONE) return;
    unset($_SESSION['csrf_token']);
}
// ===== SNAPSMACK EOF =====
