<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1470 — maintenance mode must not shut the owner out of their own site.
 *
 * WHAT WAS WRONG. SnapSmack keeps sessions in a private directory inside the
 * install, because shared cPanel hosts purge /tmp/sess_* on their own cron no
 * matter what gc_maxlifetime says. core/auth-smack.php set that save path
 * inline before starting the session. core/maintenance-gate.php called
 * session_start() with NO save path, so on a public page it opened PHP's
 * default store, found no file there, and read $_SESSION['user_login'] as
 * empty. The gate's "logged-in users see the site normally" branch was correct
 * and unreachable: with maintenance on, the owner was served their own holding
 * page. Codex reproduced it on a live 0.7.999D install.
 *
 * AND I TOLD SEAN THE OPPOSITE an hour before it was filed -- that he could
 * verify his new site logged in without revealing it. I had read the early
 * return and never run it. Reading a branch is not evidence it is reachable,
 * which is the whole reason this test runs the gate instead of inspecting it.
 *
 * HOW IT IS PROVEN. The gate is executed in a child PHP process, twice, with
 * maintenance_mode on: once with a real session file in the private store and
 * the matching cookie, once with neither. One must pass through, the other must
 * be held. Nothing here reads the source of the gate, because the previous
 * version of this fault would have satisfied any source check -- the branch was
 * present the whole time.
 *
 * NOT COVERED: a breach lockout, which is a different trigger with its own
 * neutral wording, and the rendered appearance of the holding page. This is
 * about who gets past the gate.
 */

$root = dirname(__DIR__);
$failures = [];

function mo_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/**
 * Run the gate in a child process and return what came out.
 *
 * A child is the only honest way: the gate starts a session and may exit, and
 * session_save_path() cannot be changed once a session is running, so a single
 * process could not test both outcomes.
 */
function mo_run_gate(string $root, ?string $sessionId, string $maintenance): string {
    $bootstrap = $root . '/data/sessions/_ss1470_probe_' . bin2hex(random_bytes(4)) . '.php';
    $cookie = $sessionId === null
        ? ''
        : '$_COOKIE[session_name()] = ' . var_export($sessionId, true) . ';';
    file_put_contents($bootstrap, "<?php\n"
        . "\$settings = ['maintenance_mode' => " . var_export($maintenance, true) . "];\n"
        . $cookie . "\n"
        . "require " . var_export($root . '/core/maintenance-gate.php', true) . ";\n"
        . "echo 'GATE_PASSED_THROUGH';\n");

    $out = [];
    exec('php ' . escapeshellarg($bootstrap) . ' 2>&1', $out);
    @unlink($bootstrap);
    return implode("\n", $out);
}

/**
 * Write a session file into the private store exactly as PHP would.
 *
 * Cleanup is registered at the same moment the file is created, not left to
 * the end of the script. The first version of this test only tidied up on the
 * success path, so every FAILING run -- which is every mutation run, the exact
 * thing the file exists to produce -- left a session file behind in the
 * install's own data directory. Seven of them, before I looked.
 */
function mo_write_session(string $root, string $login): string {
    $dir = $root . '/data/sessions';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $id = 'ss1470' . bin2hex(random_bytes(10));
    $file = $dir . '/sess_' . $id;
    file_put_contents($file, 'user_login|' . serialize($login)
        . 'user_role|' . serialize('admin'));
    return $id;
}

/** Remove session files left by an earlier run that died before cleaning up. */
function mo_sweep_stale(string $root): int {
    $swept = 0;
    foreach (glob($root . '/data/sessions/sess_ss1470*') ?: [] as $stale) {
        if (@unlink($stale)) $swept++;
    }
    return $swept;
}

echo "SS-1470 — maintenance mode lets the owner through\n\n";

// Sweep before AND after, on every exit path.
//
// Two separate sources of litter, and I only found the second by looking at
// the directory: the session file this test writes, and the ones PHP itself
// writes IN THE CHILD PROCESS when the gate starts a session for the
// deliberately-bogus cookie id. Those are zero-byte files I never created and
// would never have thought to unlink by name. Sweeping the whole ss1470
// pattern catches both, and registering it as a shutdown handler means a
// failing run -- which is every mutation run -- cleans up too. The first
// version tidied only on success and left seven files in the install.
mo_sweep_stale($root);
register_shutdown_function(static function () use ($root): void {
    mo_sweep_stale($root);
});

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the premise: both entry points must agree on the store\n";
// ─────────────────────────────────────────────────────────────────────────────
// The fault was two places deciding where sessions live. This is the one check
// here that reads source, and it is about there being ONE decision, not about
// the behaviour -- the behaviour is executed below.
mo_check('the shared session-store helper exists',
         is_file($root . '/core/session-store.php'), $failures);
foreach (['core/auth-smack.php', 'core/maintenance-gate.php'] as $caller) {
    $src = (string)file_get_contents($root . '/' . $caller);
    mo_check("{$caller} uses the shared helper",
             str_contains($src, 'snap_use_private_session_store('), $failures);
}

// The cookie shaping moved into the helper alongside the save path, so both
// entry points start a session the same way. These are the values that were
// inline in auth-smack.php, executed rather than read, because the point of
// moving them was that they must not differ.
//
// IN A CHILD PROCESS, and that is not fussiness. PHP refuses
// session_set_cookie_params() once any output has been sent, so calling it
// after this file's own echo statements silently warns and changes nothing --
// which made my first version of this check fail against correct code. The
// same constraint is real in production: anything that prints before the
// session is started defeats these settings.
$cookieProbe = $root . '/data/sessions/_ss1470_params_' . bin2hex(random_bytes(4)) . '.php';
if (!is_dir($root . '/data/sessions')) mkdir($root . '/data/sessions', 0700, true);
file_put_contents($cookieProbe, "<?php\n"
    . "\$_SERVER['HTTPS'] = 'on';\n"
    . "require " . var_export($root . '/core/session-store.php', true) . ";\n"
    . "snap_apply_session_cookie_params();\n"
    . "echo json_encode(session_get_cookie_params()"
    . " + ['gc' => (int)ini_get('session.gc_maxlifetime')]);\n");
$probeOut = [];
exec('php ' . escapeshellarg($cookieProbe) . ' 2>&1', $probeOut);
@unlink($cookieProbe);
$cookie = json_decode(implode('', $probeOut), true) ?: [];

mo_check('the cookie probe ran cleanly, with no PHP warning',
         $cookie !== [] && !str_contains(implode(' ', $probeOut), 'Warning'), $failures);
mo_check('the shared helper shapes the session cookie as auth-smack did',
         ($cookie['lifetime'] ?? null) === 86400
         && ($cookie['path'] ?? null) === '/'
         && ($cookie['httponly'] ?? null) === true
         && ($cookie['samesite'] ?? '') === 'Lax', $failures);
mo_check('and marks it secure on an HTTPS request',
         ($cookie['secure'] ?? null) === true, $failures);
mo_check('and keeps the day-long garbage-collection lifetime on the shared store',
         ($cookie['gc'] ?? null) === 86400, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — AN AUTHENTICATED OWNER GETS THROUGH (the defect)\n";
// ─────────────────────────────────────────────────────────────────────────────
$sessionId = mo_write_session($root, 'sean');
$ownerOut = mo_run_gate($root, $sessionId, '1');

mo_check('the gate ran at all, rather than erroring out',
         $ownerOut !== '' && !str_contains($ownerOut, 'Fatal error'), $failures);
mo_check('THE OWNER PASSES THROUGH with maintenance on',
         str_contains($ownerOut, 'GATE_PASSED_THROUGH'), $failures);
mo_check('and is NOT served the holding page',
         !str_contains($ownerOut, 'Under Maintenance')
         && !str_contains($ownerOut, 'maint-body'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — AND A VISITOR IS STILL HELD\n";
// ─────────────────────────────────────────────────────────────────────────────
// Without this the fix could be "let everyone through", which would put an
// unfinished site in front of the public -- the exact thing maintenance mode
// is for.
$visitorOut = mo_run_gate($root, null, '1');
mo_check('a visitor with no session does NOT pass through',
         !str_contains($visitorOut, 'GATE_PASSED_THROUGH'), $failures);
mo_check('and is served the holding page',
         str_contains($visitorOut, 'maint-body')
         || str_contains($visitorOut, 'Under Maintenance'), $failures);
mo_check('the holding page still tells crawlers not to index it',
         str_contains($visitorOut, 'noindex'), $failures);

// A cookie naming a session that does not exist is a visitor, not an owner.
$bogusOut = mo_run_gate($root, 'ss1470' . bin2hex(random_bytes(10)), '1');
mo_check('a cookie for a session that does not exist is treated as a visitor',
         !str_contains($bogusOut, 'GATE_PASSED_THROUGH'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — maintenance OFF lets everyone through, as before\n";
// ─────────────────────────────────────────────────────────────────────────────
$offOut = mo_run_gate($root, null, '0');
mo_check('with maintenance off a visitor passes through untouched',
         str_contains($offOut, 'GATE_PASSED_THROUGH'), $failures);

// The session file is removed by the shutdown handler registered when it was
// created, so it goes whether this run passes or fails. data/sessions/ is
// gitignored, but leaving session-shaped litter in an install directory is not
// acceptable regardless of what git thinks.
@unlink($root . '/data/sessions/sess_' . $sessionId);
mo_check('the test removed the session file it created',
         !is_file($root . '/data/sessions/sess_' . $sessionId), $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1470 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1470 PASS — with maintenance on, an authenticated owner reaches the\n";
echo "site and an anonymous visitor gets the holding page. Both outcomes are\n";
echo "executed in a child process, not read from the source, because the\n";
echo "branch that was broken had always been present.\n";
echo "NOT COVERED: breach lockout, which is a separate trigger, and the\n";
echo "appearance of the holding page. Nothing installed or deployed.\n";
// ===== SNAPSMACK EOF =====
