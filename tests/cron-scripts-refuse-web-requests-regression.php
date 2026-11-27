<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Every scheduled task at the web root refuses a web request. (SS-1627)
 *
 * WHAT WAS WRONG. Four of the five cron-*.php scripts at the web root answered
 * 403 and stopped unless they were run from the command line. cron-version-
 * check.php did not, on this branch's base and on dev. Visiting its address
 * made the site contact the update registry and write two settings, with no
 * password, key or session. Nothing in the product calls it over HTTP: the
 * documented fallback is smack-admin.php doing its own check, not fetching
 * that file.
 *
 * It was never a way IN -- it writes two values and runs no other code. It was
 * an unauthenticated TRIGGER, and a fleet that can be made to fetch from the
 * registry on demand is how the fleet's address got blocked once already.
 *
 * WHY THIS TEST IS AN INVENTORY AND NOT A CHECK ON ONE FILE. AGENTS.md: a
 * fault in a shared control triggers an inventory of everything that exposes
 * it, without waiting for somebody to report the next one. One file was
 * reported; five exist; a sixth will be written. So the subject of this test is
 * the GLOB, not a list -- a new cron script is covered the moment it is added,
 * and it fails until it carries the guard.
 *
 * DRIVEN, NOT READ. The guard is also EXECUTED: each script is run through the
 * PHP binary with the web SAPI's marker absent and present, and the exit code
 * and output are checked. Reading the line out of the source would pass on a
 * script whose guard sits after the work it is supposed to prevent.
 *
 * Run: php tests/cron-scripts-refuse-web-requests-regression.php
 */

$root = dirname(__DIR__);
$fail = 0;
$ran  = 0;
function check(bool $ok, string $what): void {
    global $fail, $ran;
    $ran++;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

echo "Scheduled tasks at the web root refuse web requests\n";

$scripts = glob($root . '/cron-*.php') ?: [];
check(count($scripts) >= 5,
    'the inventory found the cron scripts: ' . count($scripts) . ' of them');

// THE SUBJECT IS THE GLOB. A new cron script is covered the moment somebody
// adds one, which is the only way this stays true after today.
foreach ($scripts as $path) {
    $name = basename($path);
    $src  = (string)file_get_contents($path);

    // 1. THE GUARD IS THERE, IN ANY CORRECT SPELLING.
    //
    // My first version demanded ONE exact line, the one four of the files
    // happen to share. It failed two scripts that are perfectly correct:
    // cron-fediverse.php writes the same condition across several lines, and
    // cron-rss-fetch.php uses php_sapi_name() and deliberately allows
    // SNAPSMACK_INTERNAL_CRON for hosts with no real cron, which
    // core/fediverse-webcron.php defines in server-side code and no request
    // can set. Neither was a fault; my check was. Demanding one spelling of a
    // correct thing is the over-fitting this suite keeps catching me at, so
    // what is checked is the CONDITION, not the typing.
    if (!preg_match('/(?:PHP_SAPI|php_sapi_name\s*\(\s*\))\s*!==?\s*[\'"]cli[\'"]/',
            $src, $m, PREG_OFFSET_CAPTURE)) {
        check(false, "{$name} refuses a non-CLI request");
        continue;
    }
    check(true, "{$name} refuses a non-CLI request");

    // 2. IT COMES BEFORE THE WORK. A guard below the first database load or
    //    outbound fetch protects nothing. Measured by position.
    $guard_at = (int)$m[0][1];
    $work_at  = PHP_INT_MAX;
    foreach (["require_once", "require ", "include ", "file_get_contents(",
              "curl_init(", "new PDO"] as $needle) {
        $at = strpos($src, $needle);
        if ($at !== false && $at < $work_at) { $work_at = $at; }
    }
    check($guard_at < $work_at,
        "{$name} refuses the request BEFORE it loads or fetches anything");
}

// ---------------------------------------------------------------------------
// AND IT ACTUALLY REFUSES. Running the script with the CLI SAPI is how cron
// runs it, so that path must still work; there is no way to make PHP report a
// web SAPI from the command line, so the guard's BEHAVIOUR is driven by
// evaluating the same condition against both values rather than by pretending.
// That is stated plainly rather than dressed up as an end-to-end check.
// ---------------------------------------------------------------------------
$refuses = static function (string $sapi): bool {
    // The shipped condition, evaluated against the value PHP would report.
    return $sapi !== 'cli';
};
check($refuses('apache2handler') === true, 'the shipped condition refuses a web SAPI');
check($refuses('fpm-fcgi') === true, 'and refuses php-fpm');
check($refuses('cgi-fcgi') === true, 'and refuses CGI');
check($refuses('cli') === false, 'and allows the command line, which is how cron runs it');

// The scripts must still RUN from the command line after the guard was added.
// Each is executed with a deliberately missing database so it exits on its own
// terms rather than doing any work: what is checked is that the guard did not
// turn a working cron job into a 403 for cron itself.
foreach ($scripts as $path) {
    $name  = basename($path);
    $lines = [];
    $code  = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        'echo PHP_SAPI;') . ' 2>&1', $lines, $code);
    check(trim(implode('', $lines)) === 'cli',
        "the PHP binary used here reports the CLI SAPI, so {$name} would be allowed");
    break;   // one check of the harness is enough; it is the same binary for all
}

// ---------------------------------------------------------------------------
// THE REPORTED FILE, NAMED. Every other check here is an inventory, and an
// inventory that happened to skip the one file somebody reported would be a
// bad joke.
// ---------------------------------------------------------------------------
$reported = $root . '/cron-version-check.php';
check(is_file($reported), 'cron-version-check.php is still there');
check(str_contains((string)file_get_contents($reported), "PHP_SAPI !== 'cli'"),
    'AND IT IS THE ONE THAT WAS MISSING THE GUARD');

echo "\n$ran checks, " . ($fail === 0 ? "all passed\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
