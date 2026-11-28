<?php
declare(strict_types=1);

/**
 * SS-1603 — the reset page refused everyone because it never issued a token.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 *
 * THE OPPOSITE OF A MISSING CHECK, and easy to misfile as one. password-reset.php
 * already verified a token: csrf_verify() on the request, csrf_field() in the
 * form. What it never did was START A SESSION. csrf_token() returns '' without
 * one, so the form rendered an EMPTY hidden input and the submission was then
 * refused with "no session" -- HTTP 403 before the reset mail could even be
 * requested.
 *
 * The guard worked perfectly and turned away every legitimate person. On the
 * LOCKED-OUT path, which is the one people reach when they cannot get in any
 * other way. A control that refuses everyone is still a defect.
 *
 * The root cause is executed below against the real csrf functions. The page
 * itself cannot be required -- it renders HTML and sends headers at the top
 * level -- so the wiring and the ORDER are source assertions, labelled.
 */

$root   = dirname(__DIR__);
$checks = 0;
$fails  = [];
$check  = static function (string $what, bool $ok) use (&$checks, &$fails): void {
    $checks++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $what . "\n";
    if (!$ok) { $fails[] = $what; }
};

require_once $root . '/core/csrf.php';

// ── BOTH STATES ARE MEASURED BEFORE ANYTHING IS PRINTED ──────────────────────
//
// session_start() FAILS ONCE OUTPUT HAS BEEN SENT. My first version of this
// file printed a heading and an assertion line first, so the session could
// never start and four checks failed for a reason that had nothing to do with
// the product. The no-session reading has to be taken first and the session
// started immediately after, with the reporting deferred.
//
// The save path is test setup, not a product need: it is empty under CLI here,
// so session_start() returns false without one. The product runs under a web
// SAPI where it is configured, and core/session-store.php sets its own.
$tokenWithoutSession = csrf_token();
$statusWithoutSession = session_status();
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (session_save_path() === '') { session_save_path(sys_get_temp_dir()); }
    @session_start();
}
$sessionStarted = session_status() === PHP_SESSION_ACTIVE;

echo "\n-- the root cause, executed --\n";
$check('WITHOUT a session, csrf_token() is empty, which is what emptied the field',
    $statusWithoutSession !== PHP_SESSION_ACTIVE && $tokenWithoutSession === '');
$check('a session could actually be started for this test', $sessionStarted);

$token = csrf_token();
$check('WITH a session, csrf_token() returns a real token',
    $token !== '' && strlen($token) >= 32);
$check('and it is stable within the request, so the field and the check agree',
    $token !== '' && csrf_token() === $token);

// csrf_field() prints the hidden input the form carries.
ob_start(); csrf_field(); $field = (string) ob_get_clean();
// THE EMPTY NEEDLE IS CHECKED FOR EXPLICITLY. My first version asserted
// str_contains($field, $token) alone, and str_contains(anything, '') is TRUE,
// so this PASSED while the token was empty -- the exact fault the whole ticket
// is about, reproduced in the test written to catch it. A check whose needle
// can be nothing is a check that cannot fail.
$check('csrf_field() now emits a NON-EMPTY value',
    $token !== '' && str_contains($field, $token));
$check('and it is a hidden input named csrf_token',
    str_contains($field, 'name="csrf_token"') && str_contains($field, 'type="hidden"'));

echo "\n-- the page is wired, and in the right ORDER --\n";
$src = (string) @file_get_contents($root . '/password-reset.php');
$check('password-reset.php is readable', $src !== '');
$code = (string) preg_replace('#/\*.*?\*/#s', '', $src);
$code = (string) preg_replace('#^\s*//.*$#m', '', $code);

$check('it starts a session at all', str_contains($code, 'session_start()'));
$check('it uses the SHARED store helper, not a bare session_start',
    str_contains($code, 'snap_use_private_session_store()')
    && str_contains($code, "core/session-store.php"));
// WHY THE SHARED HELPER MATTERS, not merely that it is called: SS-1470 moved
// the save path and cookie params there so every entry point reads the SAME
// store. A bare session_start() opens PHP's default store, which is how
// core/maintenance-gate.php once made a logged-in owner invisible to itself.
$check('the helper is called BEFORE session_start, or the params do not apply',
    strpos($code, 'snap_use_private_session_store()') < strpos($code, 'session_start()'));

// THE ORDER IS THE WHOLE FIX. A session started after the token is asked for
// is a session that arrived too late.
$sess = strpos($code, 'session_start()');
foreach (['csrf_verify()' => 'the request check', 'csrf_field()' => 'the form field'] as $call => $what) {
    $at = strpos($code, $call);
    $check("the session is started before {$what} ({$call})",
        $at !== false && $sess !== false && $sess < $at);
}
$check('and before core/csrf.php is even required',
    $sess < strpos($code, "require_once 'core/csrf.php'"));

echo "\n-- and it still does not say whether an account exists --\n";
// Part of SS-1603's acceptance, and a thing a fix like this could easily
// break by adding a branch. One message, either way.
$check('the same message is returned whether the address is registered or not',
    str_contains($src, 'If that email address is registered'));
$check('and that intent is written down beside it',
    (bool) preg_match('/same message whether the email exists or not/i', $src));

echo "\n";
if ($fails) {
    echo 'FAILED (' . count($fails) . " of {$checks}):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    fwrite(STDERR, "\nThe locked-out path can still refuse everyone who uses it.\n");
    exit(1);
}
echo "SS-1603: the reset page issues the token it demands ({$checks} checks)\n";
echo "NOT CHECKED: the live page. Whether a real submission now queues exactly\n"
   . "one reset message needs a running site and a mail path, and that is the\n"
   . "rest of SS-1603's acceptance. What is proved here is that the token the\n"
   . "form carries is no longer empty and that the session precedes every use\n"
   . "of it.\n";

// ===== SNAPSMACK EOF =====
