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

echo "\n-- the like endpoint: the carrier, which is what the first repair lacked --\n";
// THE FIRST REPAIR WAS FAILED ON REVIEW and these assertions are why. It added
// the check to process-like.php and nothing else, and my test passed because I
// set $_SESSION['csrf_token'] BY HAND. I executed the function and never the
// situation. On a real public page csrf_meta_tag() is never emitted, so there
// was no token at all: the guard allowed every cross-site POST and REFUSED a
// signed-in owner's own likes on his own site.
//
// So this section does not ask whether a call exists. It asks whether a token
// is ISSUED on the page and ACCEPTED by the endpoint, in that order, with
// nothing set by hand.
$strip = static function (string $src): string {
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    return (string) preg_replace('#^\s*//.*$#m', '', $src);
};
$runtime = $strip((string) @file_get_contents($root . '/core/public-runtime.php'));
$ctrl    = $strip((string) @file_get_contents($root . '/core/tile-like-control.php'));
$engine  = $strip((string) @file_get_contents($root . '/assets/js/ss-engine-tile-like.js'));
$like    = $strip((string) @file_get_contents($root . '/process-like.php'));

// BRACE-MATCHED, not a pattern that happens to match. My first version of
// this pair asked whether the call sat alone on an indented line -- which the
// CORRECT code also does, so it could not tell right from wrong and failed on
// working code. This walks to the branch's own closing brace and asks whether
// the call is inside it.
$branch = static function (string $src, string $open): string {
    $i = strpos($src, $open);
    if ($i === false) return '';
    $j = $i + strlen($open);          // just past the '{'
    $depth = 1;
    for ($n = strlen($src); $j < $n && $depth > 0; $j++) {
        if ($src[$j] === '{') $depth++;
        elseif ($src[$j] === '}') $depth--;
    }
    return $depth === 0 ? substr($src, $i, $j - $i) : '';
};
$enabled = $branch($runtime, 'if (snap_tile_like_enabled($settings)) {');
$check('the enabled branch is found and closes', $enabled !== '');
$check('the session is started INSIDE it', str_contains($enabled, 'snap_tile_like_session()'));
$check('and nowhere else, so a site with likes off gets no cookie and no session file',
    substr_count($runtime, 'snap_tile_like_session()')
    === substr_count($enabled, 'snap_tile_like_session()'));
$check('the CMS-built button carries the token', str_contains($ctrl, 'data-csrf="'));
$check('the engine reads the BUTTON first and the meta tag second',
    strpos($engine, 'dataset.csrf') !== false
    && strpos($engine, 'dataset.csrf') < strpos($engine, 'meta[name="csrf-token"]'));
$check('and sends it under the name the endpoint reads', str_contains($engine, "'csrf_token'"));
$check('the endpoint RESUMES that same session before it checks anything',
    strpos($like, 'snap_tile_like_session()') !== false
    && strpos($like, 'snap_tile_like_session()') < strpos($like, 'csrf_ok_public()'));
$check('and refuses rather than continuing',
    (bool) preg_match('/if\s*\(\s*!\s*csrf_ok_public\(\)\s*\)\s*\{/', $like));

echo "\n-- the round trip, in a real session, nothing set by hand --\n";
// A SUBPROCESS PER CASE, because this needs a session that does not exist yet
// and this harness has already sent output. Each case starts PHP clean, starts
// the session through the shared store exactly as the page does, takes the
// token out of the MARKUP snap_tile_like_html() produced -- not out of
// $_SESSION -- and hands it to the guard.
$trip = static function (string $body) use ($root): string {
    $src = "<?php\n"
         . "error_reporting(E_ALL);\n" . "ini_set('display_errors', '1');\n"
         . "require_once " . var_export($root . '/core/tile-like-control.php', true) . ";\n"
         . "require_once " . var_export($root . '/core/csrf.php', true) . ";\n"
         . "snap_tile_like_session();\n"
         . "\$html = snap_tile_like_html(7, false);\n"
         . "\$token = preg_match('/data-csrf=\"([^\"]+)\"/', \$html, \$m) ? \$m[1] : '';\n"
         . $body . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'csrftrip');
    file_put_contents($tmp, $src);
    // NO STDERR REDIRECT, and the reason is the strength ratchet rather than
    // taste: it reads a digit beside a comparison operator as a figure this
    // test pins the code to, and a shell stderr redirect has exactly that
    // shape. Writing the redirect into a COMMENT to explain itself put the
    // shape back twice over and the ratchet counted both, which is the
    // needle-inside-the-check mistake in miniature -- so it is described
    // here and not spelled. The child sends its own errors to stdout
    // instead, which is better anyway: a PHP warning then lands in $out and
    // makes the case FAIL loudly rather than being swallowed.
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp));
    @unlink($tmp);
    return trim($out);
};

$out = $trip('echo $token === "" ? "EMPTY" : "ISSUED:" . strlen($token);');
$check('a real page issues a token at all -- the empty string the first repair was comparing',
    (bool) preg_match('/^ISSUED:(\d+)$/', $out, $m) && (int) $m[1] >= 32, $out);

$out = $trip('$_POST["csrf_token"] = $token; echo csrf_ok_public() ? "PASS" : "REFUSED";');
$check("THE OWNER'S OWN LIKE PASSES -- the case the first repair broke", $out === 'PASS', $out);

// THE TAMPER MUST ACTUALLY TAMPER. This read "f" . substr($token, 1), which
// leaves the token UNCHANGED whenever its first character is already 'f' --
// about one run in sixteen on a hex token. It failed twice in twelve runs
// before I looked, and a test that fails at random gets ignored, which is
// worse than not having it. strtr maps each hex digit to a different one, so
// the result differs from the original in every character and the length is
// identical.
$out = $trip('$_POST["csrf_token"] = strtr($token, "0123456789abcdef", "123456789abcdef0");'
    . ' echo $_POST["csrf_token"] === $token ? "NOT-TAMPERED" : (csrf_ok_public() ? "PASS" : "REFUSED");');
$check('a tampered token is refused', $out === 'REFUSED', $out);

$out = $trip('echo csrf_ok_public() ? "PASS" : "REFUSED";');
$check('a session with NO token is refused -- the cross-site shape when the cookie DOES arrive',
    $out === 'REFUSED', $out);

$out = $trip('$_SERVER["HTTP_X_CSRF_TOKEN"] = $token; echo csrf_ok_public() ? "PASS" : "REFUSED";');
$check('the header carrier works too, for a client that cannot add a field', $out === 'PASS', $out);

echo "\n-- the two comment endpoints: the hole is OPEN and must READ as open --\n";
// THESE ASSERTIONS ARE INVERTED FROM WHAT THEY SAID, deliberately and in
// place rather than deleted. They used to read:
//
//     $check("{$why}: calls csrf_ok_public()", str_contains($code, 'csrf_ok_public()'));
//
// and they PASSED, on both endpoints, while neither was protected. Those
// clients -- ss-engine-community.js and ss-engine-photogram.js -- send no
// token, so the guard had nothing to compare and allowed every request. A
// check that cannot fail is not a check, and a test asserting its presence
// certifies the hole.
//
// Until a carrier exists for those clients the correct source state is NO
// GUARD, and this is what holds it there: put csrf_ok_public() back without a
// carrier and these fail.
foreach ([
    'process-community-comment.php' => 'SS-1634, community comment submit and delete',
    'process-comment.php'           => 'SS-1635 first half, the image comment endpoint',
] as $file => $why) {
    $src = (string) @file_get_contents($root . '/' . $file);
    $check("{$file} is readable", $src !== '');
    $check("{$why}: carries NO token guard, because its client sends no token",
        !str_contains($strip($src), 'csrf_ok_public('));
    $check("{$why}: and the open state is written down where the guard was",
        str_contains($src, 'OPEN'));
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
echo "WHAT THIS PROVES, and it is more than the first attempt proved:\n"
   . "  EXECUTED  the token rule itself, every branch of it.\n"
   . "  EXECUTED  the round trip -- a real session started through the shared\n"
   . "            store, the token read back out of the MARKUP the CMS button\n"
   . "            emits, and that token accepted by the endpoint's guard. A\n"
   . "            tampered one and a missing one are both refused. Nothing in\n"
   . "            those cases is set by hand, which is exactly how the first\n"
   . "            repair passed a test while being inverted in production.\n"
   . "  SOURCE    that the page starts the session only where the heart is\n"
   . "            enabled, that the engine prefers the button over the meta\n"
   . "            tag, that the endpoint resumes before it checks, and that\n"
   . "            the two comment endpoints carry no unvalidatable guard.\n"
   . "\n"
   . "NOT COVERED, and a running site is needed for only two of these:\n"
   . "  NOT CHECKED  the browser leg. That the engine reads the attribute off a\n"
   . "               rendered page and sends it is a browser test, not this one.\n"
   . "  NOT CHECKED  a real cross-site POST being refused over HTTP.\n"
   . "  OPEN         a visitor whose browser sends no session cookie has no\n"
   . "               token and is allowed, so a cross-site POST can still\n"
   . "               toggle an anonymous IP-keyed like for them. Refusing them\n"
   . "               decides who may like a photograph; that one is the PM's.\n"
   . "  ELSEWHERE    the rate limit's own counting is the shared limiter's and is\n"
   . "               covered where it lives. What is checked here is that this\n"
   . "               endpoint calls it on its own action and acts on the answer.\n";

// ===== SNAPSMACK EOF =====
