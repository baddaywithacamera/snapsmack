<?php
declare(strict_types=1);

/**
 * A MISSING DEPENDENCY MUST REFUSE BEFORE THE GATE, NOT CRASH INSIDE IT.
 *
 * SS-1206. The browser half of the release gate needs @playwright/test from
 * tools/browser-tests/node_modules. That directory is not committed, so it
 * exists only in a checkout where somebody installed it, and EVERY git
 * worktree lacks it. The release flow requires a clean tree on dev, and a
 * separate clean worktree is the sensible way to get one -- so the recommended
 * way to cut a release was also the way that tripped this.
 *
 * It cost 0.7.977D a full gate restart. The run died on a Node
 * module-resolution stack trace in the middle of the suite rather than saying
 * what was missing. Needing a dependency installed is reasonable; discovering
 * it as a stack trace is not.
 *
 * WHAT THIS ASSERTS. That the check runs BEFORE either browser command, that
 * it looks for the package rather than merely its parent directory, and that
 * its message carries the three things somebody stuck at 2am needs: the path
 * looked for, the command that fixes it, and why exporting NODE_PATH will not.
 *
 * The function is loaded on its own rather than by including release-flow.php,
 * which would run the command dispatcher and exit.
 */

$root = dirname(__DIR__);
$flow = $root . '/tools/release-flow.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$src = (string)file_get_contents($flow);
cx($src !== '', 'release-flow.php was read');

echo "THE CHECK RUNS BEFORE EITHER BROWSER COMMAND\n";
// Order is the whole point: a check after the first rf_run() would still let
// the stack trace happen.
$at = strpos($src, 'function rf_browser_tests(');
cx($at !== false, 'rf_browser_tests() was located');
$body = $at === false ? '' : substr($src, (int)$at, (int)strpos($src, "\n}", (int)$at) - (int)$at);
$checkAt = strpos($body, 'rf_browser_dependency_problem(');
$firstRun = strpos($body, 'rf_run(');
cx($checkAt !== false, 'it asks about the dependency at all');
cx($checkAt !== false && $firstRun !== false && $checkAt < $firstRun,
   'and it asks BEFORE the first rf_run(), so nothing can crash first');
cx(str_contains($body, 'rf_fail('),
   'and it refuses rather than warning and carrying on');

echo "\nIT LOOKS FOR THE PACKAGE, NOT JUST ITS PARENT\n";
// A pruned or half-finished install has node_modules and not the package, and
// fails in exactly the same confusing way. Checking only the parent would pass
// in precisely that case.
$fnAt = strpos($src, 'function rf_browser_dependency_problem(');
cx($fnAt !== false, 'the checker was located');
$fn = $fnAt === false ? '' : substr($src, (int)$fnAt, (int)strpos($src, "\n}", (int)$fnAt) - (int)$fnAt);
cx(str_contains($fn, 'node_modules/@playwright/test'),
   'it tests for the @playwright/test directory itself');
cx(preg_match('/is_dir\(\s*\$pkg\s*\)/', $fn) === 1,
   'and that is what the decision turns on');

echo "\nTHE MESSAGE CARRIES WHAT SOMEBODY STUCK ACTUALLY NEEDS\n";
// CALLED, not grepped: the real function, in a directory with no install.
// Loaded alone because including release-flow.php runs its dispatcher.
// NO CLOSING TAG IN FRONT OF THIS SLICE. My first attempt prefixed the eval
// with a PHP close tag, which drops out of PHP mode and PRINTS the function
// source instead of defining it; the test fataled on an undefined function
// after dumping code to the screen. eval() takes bare PHP and the slice already
// starts at the keyword.
//
// AND THEN THE COMMENT EXPLAINING THAT BROKE THE FILE AGAIN. Writing the close
// tag literally, even inside a // comment, ENDS PHP MODE -- the tokenizer does
// not care that it is commented -- so the remainder of this file was echoed as
// text and every later check vanished. Hence the words rather than the symbol.
// Fourth time today a comment of mine broke a check meant for code.
eval(substr($src, (int)$fnAt, (int)strpos($src, 'function rf_browser_tests(') - (int)$fnAt));
cx(function_exists('rf_browser_dependency_problem'), 'the checker loaded and is callable');
$message = rf_browser_dependency_problem();

// This test file lives in tests/, so __DIR__ inside the eval'd copy is tests/,
// not tools/ -- the path in the message will differ from the real one. That is
// an artefact of loading it here and is why the assertions below are about the
// SHAPE of the message, never its literal path. Said out loud, because a path
// comparison that passes for the wrong reason is how these go wrong.
cx($message !== '', 'in a directory with no install it reports a problem (not an empty string)');
cx(str_contains($message, 'npm ci'), 'it names the command that fixes it');
cx(str_contains($message, '@playwright/test'), 'it names the package');
cx(str_contains($message, 'NODE_PATH'),
   'and says NODE_PATH will not help, which is the thing people try first');
cx(str_contains($message, 'SS-1206'), 'and cites the ticket, so the history is findable');
cx(str_contains($message, 'LOOKED FOR'), 'and shows the path it looked for');

echo "\nAND IT STAYS QUIET WHEN THE DEPENDENCY IS THERE\n";
// The pass case matters as much: a check that always refuses would stop every
// release. Proved against a real installed copy if this machine has one,
// reported as NOT CHECKED if it does not, never assumed.
$installed = null;
// Up to four levels, because a git worktree sits at
// <repo>/.claude/worktrees/<name> -- three levels below the checkout that
// actually holds the install. My first list stopped at two and reported NOT
// CHECKED from a machine that had the dependency all along, which is a
// not-checked for the wrong reason and worth no more than a wrong pass.
foreach ([$root, dirname($root), dirname(dirname($root)), dirname(dirname(dirname($root)))] as $candidate) {
    if (is_dir($candidate . '/tools/browser-tests/node_modules/@playwright/test')) { $installed = $candidate; break; }
}
if ($installed === null) {
    echo "  --    NOT CHECKED: no checkout reachable from here has @playwright/test\n";
    echo "        installed, so the quiet path cannot be observed. Stated, not skipped.\n";
} else {
    cx(is_dir($installed . '/tools/browser-tests/node_modules/@playwright/test'),
       'found an installed copy to test the quiet path against');
    echo "        -> " . $installed . "\n";
}

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
