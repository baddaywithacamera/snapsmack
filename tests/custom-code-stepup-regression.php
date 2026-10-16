<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Enabling owner custom code needs password + 2FA.
 *
 * Sean, looking at SMACK YOUR SCRIPTS UP! on 15 October 2026: "spotted a
 * security issue. should enabling not require pass/totp?"
 *
 * It should. That checkbox runs arbitrary JavaScript in every visitor's
 * browser on every page -- the highest-privilege control the CMS has -- and
 * it was protected by a session cookie and a CSRF token alone. Any borrowed
 * session, or any XSS that could reach this form, could switch it on and
 * install a payload on the public site. SMACKBACK's file actions, which are
 * less dangerous than this, have required step-up since SECAUDIT 029.
 *
 * What is gated, and what deliberately is not:
 *
 *   ON, or editing the code WHILE on   gated -- the payload is as dangerous
 *                                      as the switch
 *   OFF                                NOT gated
 *   editing while off                  NOT gated
 *
 * Making someone find their authenticator before they can DISABLE arbitrary
 * code execution would be a worse rule than having none.
 */

$root = dirname(__DIR__);
$src  = (string)@file_get_contents($root . '/smack-scripts.php');
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$check($src !== '', 'smack-scripts.php not found');

// --- the gate exists and uses the shared mechanism ----------------------
$check(str_contains($src, "require_once 'core/reauth.php'"),
    'must use core/reauth.php, the same step-up as SMACKBACK -- not a new one');
$check(str_contains($src, "reauth_window_active('owner_custom_code')"),
    'the save path must require an active step-up window');
$check(str_contains($src, "reauth_grant_window('owner_custom_code', 10)"),
    'authorizing must grant a bounded window, not a permanent flag');
$check(str_contains($src, 'reauth_verify($pdo'),
    'the window must be granted only after password and 2FA are verified');

// --- it gates the right thing -------------------------------------------
$check(str_contains($src, '$needs_stepup = ($custom_enabled === \'1\') && (!$was_enabled || $code_changed)'),
    'the gate must fire on enabling AND on changing the code while enabled');
$check(str_contains($src, '$code_changed'),
    'changing the payload while enabled must be gated: the code is the danger');

// --- and does NOT gate switching it off ---------------------------------
// A rule that makes disabling harder than enabling is worse than no rule.
$offPath = strpos($src, '$needs_stepup');
$check($offPath !== false && !preg_match('/\$needs_stepup\s*=\s*true/', $src),
    'disabling must never require step-up');

// --- the operator can actually authorize --------------------------------
$check(str_contains($src, 'name="scripts_stepup"'), 'the page must offer an authorize form');
$check(str_contains($src, 'name="reauth_password"') && str_contains($src, 'name="reauth_totp"'),
    'the form must collect both the password and the 2FA code');
$check(str_contains($src, 'csrf_verify();'), 'the step-up POST must still verify CSRF');

// --- no inline CSS was introduced doing it ------------------------------
// The markup this was copied from uses inline styles. Copying a violation
// is still committing one.
$check(!preg_match('/<form[^>]*\sstyle="/', $src),
    'the authorize form must not carry an inline style attribute');

printf("custom code step-up: gated on enable and on change, never on disable.\n");

if ($failures) {
    fwrite(STDERR, "Custom code step-up regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Custom code step-up regression passed.\n";

// ===== SNAPSMACK EOF =====
