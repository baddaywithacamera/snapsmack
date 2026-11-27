<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * A documented API path must actually reach the dispatcher.
 *
 * SS-1598. The POCKET ALBUM docblock advertised
 * GET /api/pocket-album?album=N&page=1, and on an installed site that returns
 * the HTML 404 page. api.php does accept a path-style route -- it reads
 * PATH_INFO and strips a leading "api/" -- but ONLY where a RewriteRule hands
 * it the path, and .htaccess has rules for api/desktop-auth, api/v1,
 * api/pixelfed and oauth and nothing else. So the pretty path never arrived.
 *
 * The instance was one wrong line. THE FAULT CLASS is that nothing connected
 * the documentation to the rewrite rules, so any pretty path anyone writes in
 * a comment reads as true and is never tested. This checks the class: every
 * /api/... path advertised in the source must have a rewrite behind it.
 */

$root = dirname(__DIR__);
$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

// ---------------------------------------------------------------------------
// What .htaccess actually rewrites into a dispatcher.
// ---------------------------------------------------------------------------
// THE TRACKED TEMPLATE, not the live .htaccess. Muse's condition on 6f4ade7e,
// and she is right that it broke the test: .htaccess is GITIGNORED and absent
// from a clean checkout, so this read an untracked file that happened to exist
// in my working tree. Four checks would have failed on a fresh clone and my
// "13 checks pass" only held here. core/htaccess-template IS tracked and is
// what the updater installs and self-heals from, so it is the authority for
// which paths a site will actually route.
$template = $root . '/core/htaccess-template';
$htaccess = (string)@file_get_contents($template);
$check('the tracked htaccess template is in the build', $htaccess !== '');
// THE PATH ACTUALLY READ must be tracked, not a hard-coded name. Pointing
// $template back at .htaccess passed this check while it named the template
// literally, because my working tree has a local .htaccess -- the mutant
// survived. It now asks git about $template itself.
// Built with DIRECTORY_SEPARATOR rather than a literal backslash: writing one
// in a quoted string is how the previous two edits of mine ended up with a
// stray escape and a parse error.
$templateReal = (string)realpath($template);
$rootReal = (string)realpath($root);
$relative = $templateReal !== '' && $rootReal !== ''
    ? str_replace(DIRECTORY_SEPARATOR, '/', substr($templateReal, strlen($rootReal) + 1))
    : '';
$tracked = trim((string)@shell_exec('git -C ' . escapeshellarg($root)
    . ' ls-files --error-unmatch ' . escapeshellarg($relative) . ' 2>&1'));
$check('the file this test reads (' . $relative . ') is TRACKED, so it works on a clean checkout',
    $tracked === $relative);

// The LIVE file is checked too when it happens to be present, because a site
// whose .htaccess has drifted from the template is a real condition -- but its
// absence is not a failure.
$live = $root . '/.htaccess';
if (is_file($live)) {
    $liveText = (string)file_get_contents($live);
    foreach (['api/desktop-auth', 'api/v([12])', 'api/pixelfed'] as $needle) {
        $check('the live .htaccess still carries ' . $needle,
            str_contains($liveText, $needle));
    }
} else {
    echo "     (no local .htaccess; template-only check, which is the clean-checkout case)
";
}
// THE TARGET MATTERS, not just the pattern. My first version collected
// patterns only, and reported api/pocket-album as reachable because
// ^(.*)$ -> https://%{HTTP_HOST}/$1 matched it -- the HTTPS redirect, which
// sends the browser round again and ends at index.php's HTML 404, which is
// EXACTLY what the ticket reported seeing. A rule only makes a path reachable
// when it routes to a PHP dispatcher.
preg_match_all('~^\s*RewriteRule\s+\^([^\s]+)\s+([^\s]+)~mi', $htaccess, $m, PREG_SET_ORDER);
$rules = [];
foreach ($m as $rule) {
    $target = $rule[2];
    if (!str_contains($target, '.php')) { continue; }   // a redirect, not a route
    if (str_contains($target, 'index.php')) { continue; }  // the site router, not an API
    $rules[] = ['pattern' => $rule[1], 'target' => $target];
}
$check('it carries rewrite rules that route to a dispatcher', $rules !== []);

/** Does some rewrite rule accept this path? */
$rewritten = static function (string $path) use ($rules): bool {
    $path = ltrim($path, '/');
    foreach ($rules as $rule) {
        // The rule's own regex, used as Apache uses it.
        if (@preg_match('~^' . $rule['pattern'] . '~', $path) === 1) {
            return true;
        }
    }
    return false;
};

$check('the rules that DO exist are recognised by this check',
    $rewritten('api/desktop-auth/status') && $rewritten('api/v1/anything')
    && $rewritten('api/pixelfed/v1/anything') && $rewritten('oauth/token'));
$check('and a path with no rule is not', !$rewritten('api/pocket-album'));

// ---------------------------------------------------------------------------
// Every /api/... path written in the source must be reachable.
// ---------------------------------------------------------------------------
// THE EXEMPTION DECISION, defined ONCE and used by both the scan below and the
// sample checks further down. Muse's condition on bfcfc064: the scan had its
// own copy of these two rules, so a mutant that dropped the advertises-check
// FROM THE SCAN ALONE passed every sample check and the string check too. Two
// copies of one rule is the drift fault I keep writing tests about, in my own
// test.
//
// A line recording that a path 404s is documenting ABSENCE and is excused. A
// line that ADVERTISES a path names a method -- "GET /api/x" -- and is never
// excused, however it is annotated.
$excused = static function (string $line): bool {
    $advertises = preg_match('~(?:GET|POST|PUT|PATCH|DELETE)\s+/api/~i', $line) === 1;
    $records_absence = preg_match('~404|does not exist|never existed|unreachable~i', $line) === 1;
    return $records_absence && !$advertises;
};

$sources = [];
foreach ([$root . '/*.php', $root . '/core/*.php'] as $glob) {
    foreach (glob($glob) ?: [] as $file) { $sources[] = $file; }
}
$check('there are source files to read', count($sources) > 50);

$unreachable = [];
$documented = 0;
foreach ($sources as $file) {
    $text = (string)file_get_contents($file);
    // Only comment lines: a string in code may be built for a client that has
    // its own rewrite, and this check is about what we TELL people.
    foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
        $trimmed = ltrim($line);
        if (!str_starts_with($trimmed, '*') && !str_starts_with($trimmed, '//')
            && !str_starts_with($trimmed, '#')) {
            continue;
        }
        // GET /api/<something>, which is how every one of these is written.
        // $hits is reset explicitly: a compound guard here left the PREVIOUS
        // line's matches in scope, so lines were attributed to each other and
        // the exclusion below looked broken when it was not. Found by
        // replicating the scan on its own and getting a different answer.
        $hits = [];
        if (preg_match_all('~(?:^|\s)/(api/[A-Za-z0-9_\-/]+)~', $line, $hits) < 1) {
            continue;
        }
        foreach ($hits[1] ?? [] as $path) {
            // A path that names the dispatcher directly is fine by definition.
            if (str_starts_with($path, 'api/') && str_contains($line, 'api.php')) {
                continue;
            }
            // A LINE THAT SAYS THE PATH 404s IS DOCUMENTING ABSENCE, not
            // advertising an endpoint. core/pocket-album-api.php and
            // core/pocket-album-feed.php both record that /api/albums/191
            // answered with 404 before POCKET ALBUM existed -- that is
            // evidence, and flagging it would push someone to delete the
            // evidence to quiet the test. The fault class is advertising a
            // path as USABLE, so only those count.
            // TIGHTER THAN "the line mentions 404 somewhere". Muse's nit: the
            // first version would excuse a line that genuinely advertised an
            // unreachable path as long as 404 appeared anywhere on it. An
            // ADVERTISEMENT names a method -- "GET /api/x" -- so a line doing
            // that is never excused, however it is annotated.
            if ($excused($line)) {
                continue;
            }
            $documented++;
            if (!$rewritten($path)) {
                $unreachable[] = basename($file) . ':' . ($number + 1) . '  /' . $path;
            }
        }
    }
}
$check('some /api/ paths are documented, so this check has something to do',
    $documented > 0);
$check('EVERY documented /api/ path has a rewrite behind it: '
    . ($unreachable ? implode(' | ', array_slice($unreachable, 0, 6)) : 'all reachable'),
    $unreachable === []);

// ---------------------------------------------------------------------------
// The POCKET ALBUM example specifically, which is what SS-1598 reported.
// ---------------------------------------------------------------------------
$api = (string)file_get_contents($root . '/core/pocket-album-api.php');
$display = (string)file_get_contents($root . '/core/pocket-album-display.php');
$dispatcher = (string)file_get_contents($root . '/api.php');

$check('the endpoint documents the query form that works',
    str_contains($api, 'api.php?route=pocket-album'));
$check('the display docblock documents it too',
    str_contains($display, 'api.php?route=pocket-album'));
$check('the dispatcher comment documents it too',
    str_contains($dispatcher, 'api.php?route=pocket-album'));
// Muse's nit: an advertised path must not be excused by an annotation. This is
// the positive control for the rule above -- if somebody writes
// "GET /api/nope -- 404 for now", it is still an advertisement.
// The exemption DECISION, driven on sample lines. Testing the regex alone let
// a mutant that dropped the advertises-check survive, because no line in the
// tree happens to both advertise a path and mention 404 -- so the repo could
// not tell the two rules apart. These samples can.
$check('a line recording that a path 404s IS excused',
    $excused(' *   /api/albums/191                        with 404'));
$check('a line that ADVERTISES a path is NOT excused, even mentioning 404',
    !$excused(' * GET /api/nope?x=1 -- returns 404 for now'));
$check('an advertisement with no annotation is not excused',
    !$excused(' * GET /api/nope?x=1'));
$check('a line with neither is not excused', !$excused(' * just prose about the api'));
// The scan must CALL the decision, not carry a copy of it. A substring search
// for the expression passed while two copies existed, which is what let the
// mutant live.
$ownSource = (string)file_get_contents(__FILE__);
$check('the scan calls the shared decision rather than repeating it',
    str_contains($ownSource, 'if ($excused($line)) {'));
// Counting the DEFINITION, not the expression: my first version searched for
// the expression text, which this very check contains, so it counted itself
// and reported two.
// THE NEEDLE IS ASSEMBLED, so it never appears whole in this file and cannot
// count itself. Two earlier versions of this check did exactly that -- one
// searched for the expression it contains, the next for the definition it
// contains -- and both reported two occurrences when there was one.
$needle = 'advertises' . ' = ' . 'preg_match(';
$check('and the rule is DEFINED exactly once in this file',
    substr_count($ownSource, $needle) === 1);

$check('the docblock explains why the pretty path did not work, not just what to type',
    stripos($api, 'RewriteRule') !== false || stripos($api, 'rewrite') !== false);

// AND THE FORM THE CODE USES MUST BE THE FORM WE DOCUMENT. The fetch in
// pocket-album-display.php is what actually works against a live site, so if
// the documentation and that line ever disagree again, one of them is wrong.
preg_match('~\$src \. \'([^\']+)\'~', $display, $fetch);
$check('the remote fetch builds the documented route: ' . ($fetch[1] ?? '(not found)'),
    isset($fetch[1]) && str_contains($fetch[1], 'api.php?route=pocket-album'));

$check('nothing still advertises the broken pretty path as the endpoint',
    !preg_match('~(?:GET|POST)\s+/api/pocket-album~', $api . $display . $dispatcher));

echo "\n";
if ($fails) {
    echo "FAILED (" . count($fails) . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS\n";

// NOT CHECKED, and it is the ticket's own acceptance: that the example responds
// as JSON on an INSTALLED site. That needs a running site, which this machine
// cannot reach. What is checked here is that the documented path has a rewrite
// behind it and that the code and the documentation agree.

// ===== SNAPSMACK EOF =====
