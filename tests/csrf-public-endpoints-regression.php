<?php
declare(strict_types=1);

/**
 * SS-1385 / SS-1634 / SS-1635 — the token rule for PUBLIC write endpoints.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 *
 * Three public endpoints that WRITE read no CSRF token at all. The admin pages
 * are covered automatically, because core/auth-smack.php calls csrf_check()
 * for every POST behind the owner gate. These three sit outside that gate and
 * were never given the check by hand.
 *
 * WHY csrf_check() CANNOT BE USED HERE, which is the whole reason a second
 * helper exists. csrf_check() DIES 403 when there is no session, and a guest
 * has no session: csrf_token() returns '' without one. Dropping csrf_check()
 * into these endpoints would refuse every guest like and every guest comment,
 * which is a worse defect than the one being fixed. So csrf_ok_public()
 * carries a different rule, and the rule is EXECUTED below rather than
 * described.
 *
 * The helper is pure and returns a bool, so every case can be driven directly.
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

// A session is needed for the signed-in cases. CLI has none until asked.
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/** Drive the real helper with one request shape. */
$ask = static function (?string $sessionToken, ?string $supplied, ?string $header = null): bool {
    if ($sessionToken === null) { unset($_SESSION['csrf_token']); }
    else                        { $_SESSION['csrf_token'] = $sessionToken; }
    if ($supplied === null) { unset($_POST['csrf_token']); }
    else                    { $_POST['csrf_token'] = $supplied; }
    if ($header === null) { unset($_SERVER['HTTP_X_CSRF_TOKEN']); }
    else                  { $_SERVER['HTTP_X_CSRF_TOKEN'] = $header; }
    return csrf_ok_public();
};

$good = str_repeat('a', 64);
$bad  = str_repeat('b', 64);

echo "\n-- the rule, executed --\n";

// THE CASE THAT MATTERS. A signed-in member's cookie is what a cross-site
// form abuses, and it is their name on whatever gets written.
$check('a session with a token REQUIRES a matching token', $ask($good, $good) === true);
$check('a session with a WRONG token is refused',          $ask($good, $bad) === false);
$check('a session with NO token supplied is refused',      $ask($good, null) === false);
$check('the X-CSRF-Token header is accepted too',          $ask($good, null, $good) === true);
$check('a wrong header is refused',                        $ask($good, null, $bad) === false);

// THE GUEST PATH, which must keep working: a like and a comment need no
// account, and the owner wants them available without one.
$check('a guest with no session and no token is ALLOWED',  $ask(null, null) === true);

// AND THE MIDDLE CASE, which is the one worth having. A token that cannot be
// validated is refused rather than ignored; ignoring it would make a replayed
// token indistinguishable from none at all.
$check('a token with no session to check it against is REFUSED',
    $ask(null, $good) === false);
$check('and so is a junk token with no session',           $ask(null, 'x') === false);

echo "\n-- an exempt endpoint still bypasses, deliberately --\n";
// suyb-data.php and friends swap the token for a Bearer key. The constant is
// how they say so, and it must keep working or those endpoints break.
$check('SNAPSMACK_CSRF_EXEMPT is honoured', !defined('SNAPSMACK_CSRF_EXEMPT'));
define('SNAPSMACK_CSRF_EXEMPT', true);
$check('once exempt, even a mismatch is allowed', $ask($good, $bad) === true);

echo "\n-- the endpoints actually call it --\n";
// Source assertions, and labelled as such: these files authenticate and emit
// JSON at the top level, so requiring one here would run an endpoint. What is
// executed above is the RULE; what is checked here is that each endpoint is
// wired to it.
foreach ([
    'process-like.php'              => 'SS-1385, the like endpoint',
    'process-community-comment.php' => 'SS-1634, community comment submit and delete',
    'process-comment.php'           => 'SS-1635, the comment endpoint',
] as $file => $why) {
    $src = (string) @file_get_contents($root . '/' . $file);
    $check("{$file} is readable", $src !== '');
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    $code = (string) preg_replace('#^\s*//.*$#m', '', $code);
    $check("{$why}: calls csrf_ok_public()", str_contains($code, 'csrf_ok_public()'));
    $check("{$why}: requires core/csrf.php", str_contains($code, "/core/csrf.php'"));
    // NEGATED AND INSIDE AN if, not merely called. A call whose answer is
    // thrown away is the shape this whole ticket is about.
    //
    // The pattern allows OTHER conditions alongside it, because
    // process-comment.php guards with the method test in the same if -- its
    // handler only acts on POST, so checking a token on a GET there would be
    // checking nothing. My first version of this assertion demanded the guard
    // be alone in the condition and failed on correct code.
    $check("{$why}: refuses rather than continuing",
        (bool) preg_match('/if\s*\([^)]*!\s*csrf_ok_public\(\)[^)]*\)\s*\{/', $code));
}

echo "\n-- SS-1635's second half: the rate limit, which is not a token --\n";
$pc = (string) @file_get_contents($root . '/process-comment.php');
$pcCode = (string) preg_replace('#/\*.*?\*/#s', '', $pc);
$pcCode = (string) preg_replace('#^\s*//.*$#m', '', $pcCode);
$check('the comment endpoint now has a rate limit at all',
    str_contains($pcCode, 'community_rate_limit('));
$check('and it REFUSES rather than recording the refusal and carrying on',
    (bool) preg_match('/if\s*\([^)]*!\s*community_rate_limit\([^)]*\)[^)]*\)\s*\{/', $pcCode));
$check('it reuses the shared limiter rather than a second implementation',
    str_contains($pcCode, "/core/community-session.php'")
    && !preg_match('/INSERT\s+INTO\s+snap_rate_limits/i', $pcCode));
$check('on its OWN action, so a flood of one does not throttle the other',
    str_contains($pcCode, "community_rate_limit('image_comments')"));
$cc = (string) @file_get_contents($root . '/process-community-comment.php');
$check("and the community endpoint's own limit is untouched",
    str_contains($cc, "community_rate_limit('comments')"));

echo "\n";
if ($fails) {
    echo 'FAILED (' . count($fails) . " of {$checks}):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    fwrite(STDERR, "\nA public write endpoint can be driven from another site.\n");
    exit(1);
}
echo "SS-1385 / SS-1634 / SS-1635: the public token rule holds ({$checks} checks)\n";
echo "NOT COVERED: no HTTP was exercised. The token RULE is executed here; that\n"
   . "each endpoint is wired to it, and that the rate limit is present and\n"
   . "refuses, are SOURCE assertions and are labelled as such above. Proving\n"
   . "the wire needs a running site. The rate limit's own counting is the\n"
   . "shared limiter's, already covered where it lives, and is not re-tested\n"
   . "here -- what is checked is that this endpoint calls it on its own action\n"
   . "and acts on the answer.\n";

// ===== SNAPSMACK EOF =====
