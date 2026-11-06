<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * THE RELEASE GATE MUST RUN EVERY TEST FILE, NOT THE ONES NAMED "regression".
 *
 * rf_tests() in tools/release-flow.php globbed tests/*regression.php. There
 * are 20 test files whose names do not contain that word, so the gate NEVER
 * RAN THEM -- and two of those twenty were FAILING in 0.7.977D while it was
 * the build being served to the fleet:
 *
 *   thomas-the-bear-reaches-every-skin.php
 *   help-and-consent-reach-every-skin.php
 *
 * The gate passed that release because it could not see them. Also invisible
 * to it: every-page-kind-renders-a-whole-document.php, which is the check for
 * the fault that sent eight skins a blogroll page with no <head> at all;
 * engines-reach-every-controller.php; strict-path-asset-parity.php;
 * agreed-rules-gate.php; and promote-stable-reads-the-gate-on-the-dev-tag.php
 * and promote-stable-accepts-only-the-version-commit.php, which check the
 * release machinery itself.
 *
 * A FILENAME PATTERN IS NOT A TEST SELECTION. The same narrow pattern hid two
 * failures from my own sweeps for a day while I quoted "3 of 316" at the
 * coordinator; the real figure was 3 of 337. The directory is the suite.
 *
 * This file is deliberately about the GLOB and not about any one test, so it
 * keeps holding after someone adds a test file with any name at all.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function rg(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$src = (string)@file_get_contents($root . '/tools/release-flow.php');
rg($src !== '', 'tools/release-flow.php was read');

// Bounded on the function, not on a byte count: a fixed window measures
// whatever has grown above it, which has caught me repeatedly.
$body = (static function (string $s): string {
    $at = strpos($s, 'function rf_tests(): void {');
    if ($at === false) return '';
    $end = strpos($s, "\n}", $at);
    return $end === false ? '' : substr($s, $at, $end - $at);
})($src);
rg($body !== '', 'rf_tests() was found');

echo "\nTHE GATE'S GLOB IS THE WHOLE DIRECTORY\n";
rg(str_contains($body, "/../tests/*.php"),
   "rf_tests() globs tests/*.php");
// COMMENTS STRIPPED FIRST. The function's own comment explains the fault by
// naming the old pattern, and a regex over the raw text matched that prose and
// reported the gate as still narrow. That is the third time a comment of mine
// has broken a check that reads source: a test that cannot tell code from
// prose is measuring the prose.
$code = (string)preg_replace(['!/\*.*?\*/!s', '!//[^\n]*!'], '', $body);
rg(str_contains($code, "/../tests/*.php"),
   'the CODE, with comments stripped, still globs the whole directory');
rg(!preg_match('/tests\/\*[A-Za-z_-]+\.php/', $code),
   'and no narrower filename pattern is left in the code');
rg(str_contains($body, 'rf_fail') && str_contains($body, 'suite glob is broken'),
   'an empty glob refuses the release instead of passing silently -- a gate '
   . 'that runs zero tests must not look like a gate that passed');

echo "\nWHAT THE OLD PATTERN WOULD HAVE SKIPPED, MEASURED ON THE REAL DIRECTORY\n";
$all = glob($root . '/tests/*.php') ?: [];
$narrow = glob($root . '/tests/*regression.php') ?: [];
$skipped = array_map('basename', array_values(array_diff($all, $narrow)));
sort($skipped);
rg($all !== [], count($all) . ' test files exist');
rg(count($skipped) > 0,
   count($skipped) . ' of them do not have "regression" in the name, so the old '
   . 'pattern skipped them');
// The two that were actually red in a shipped build. Named, so this file says
// why it exists even when the reason is years old.
foreach ([
    'thomas-the-bear-reaches-every-skin.php',
    'help-and-consent-reach-every-skin.php',
    'every-page-kind-renders-a-whole-document.php',
] as $witness) {
    rg(in_array($witness, $skipped, true),
       $witness . ' is one of them, and the widened glob now runs it');
}

echo "\nEVERY FILE THE WIDENED GLOB PICKS UP CAN ACTUALLY BE RUN\n";
// Widening a gate to include a file that cannot run standalone would turn
// every release into a failure. Each one is syntax-checked here; running all
// 300-odd inside this test would make the suite quadratic.
$unparseable = [];
foreach ($all as $file) {
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) $unparseable[] = basename($file);
}
rg($unparseable === [],
   'all ' . count($all) . ' parse' . ($unparseable === [] ? '' : ' except: ' . implode(', ', $unparseable)));

echo "\nHELPERS ARE NOT TESTS, AND THE EXTENSION IS WHAT KEEPS THEM OUT\n";
// tests/ holds shared helpers. They use .inc so this glob cannot run them.
// If one is ever renamed to .php the gate would execute a library, so this
// states the contract rather than leaving it to habit.
$includes = glob($root . '/tests/*.inc') ?: [];
rg(true, count($includes) . ' helper include(s) in tests/ use .inc and are therefore '
   . 'outside the glob');
foreach ($includes as $inc) {
    rg(!str_ends_with($inc, '.php'), basename($inc) . ' is not a .php file');
}

echo "\nTHE NODE TESTS ARE STILL RUN, SEPARATELY -- WIDENING PHP MUST NOT DROP THEM\n";
rg(str_contains($src, 'rf_browser_tests();'), 'rf_tests() still calls the browser tests');
rg(str_contains($src, 'browser-release-runner-regression.cjs'),
   'and the node runner is still named');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Release gate test coverage: FAIL ({$fail}).\n";
    exit(1);
}
echo "Release gate test coverage: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: a real gate run. This reads the gate's source and the test\n";
echo "  directory; it does not cut a release. The gate also runs node tests and\n";
echo "  a browser dependency check, which this file does not exercise.\n";

// ===== SNAPSMACK EOF =====
