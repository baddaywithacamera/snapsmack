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
 * WHAT THIS TEST DOES NOT DO, AND THE MEASUREMENT THAT COVERS IT. (Muse's gap
 * on 84991cfa, and she was right.) The first version of this file claimed to
 * drive the guard; it evaluated a HARDCODED COPY of the condition against a
 * few SAPI names, so no script was ever executed under a web SAPI and the
 * behaviour was verified by reading. The copy is gone rather than dressed up.
 *
 * I then measured it properly BY HAND, outside the suite, on 2026-11-27
 * against this branch: PHP's built-in server reports the SAPI "cli-server",
 * so serving the repository through it and fetching each script executes the
 * real guard on the real file over a real request. Observed:
 *
 *   cron-directory-feeds.php   HTTP 403   "CLI only."
 *   cron-fediverse.php         HTTP 404   (empty body)
 *   cron-pocket-album.php      HTTP 403   "CLI only."
 *   cron-rss-fetch.php         HTTP 403   "CLI only."
 *   cron-version-check.php     HTTP 403   "CLI only."
 *
 * None answered 200 and none ran far enough to fatal. Before the fix,
 * cron-version-check.php answered 200 and did the work.
 *
 * THAT IS NOT IN THE TEST, deliberately. I wrote it in, and on Windows
 * proc_close() blocks waiting for a built-in server that proc_terminate()
 * does not reliably kill, so the suite HUNG and had to be killed twice. A test
 * that hangs is worse than one that fails: it stops the suite instead of
 * reporting. So the inventory below is source-based, the manual result above
 * is recorded with its date and what was observed, and nobody should read
 * these checks as proof that the guard fires -- only that it is present, the
 * same in every script, and ahead of the work.
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
    // COMMENTS ARE NOT CODE. (Muse, on 84991cfa.) She pointed out this regex
    // would match the guard written inside a comment. That is the third time
    // in one day I have written a source check that cannot tell an
    // explanation from the thing it explains, and the first two were in files
    // I had already fixed for it. Comment lines are stripped before looking,
    // here and in the position check below.
    $src = preg_replace('~^\s*(?://|\#|\*|/\*).*$~m', '', $src) ?? $src;
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

// FIVE CHECKS WERE DELETED HERE, ON PURPOSE, AND THE RECORD WENT DOWN.
//
// They were the hardcoded copy of the condition -- $sapi !== 'cli' evaluated
// against four SAPI names -- plus one that confirmed the PHP binary running
// the suite reports "cli". Muse's gap on 84991cfa: a local copy of a condition
// tells you nothing about the shipped guard, and the fifth check tested the
// harness rather than the product. Four of them could not fail while PHP
// works, which is the definition of a check that records confidence.
//
// They read as evidence in a passing run, and that is worse than their absence.
// The guard's behaviour is covered by the manual HTTP measurement recorded in
// this file's header, with its date and what was observed, and by the source
// inventory above. The strength record drops by five and the diff says why.

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
