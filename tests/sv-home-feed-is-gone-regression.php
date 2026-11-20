<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 16 — sv_home_feed() is gone, and stays gone.
 *
 * WHAT IT WAS. A live merge-crawl of up to 15 followed accounts, six photos
 * each, on an ~18-second wall-clock budget, run while the owner was waiting for
 * the Home tab to load. It was replaced by the inbound ingest timeline
 * (sv_home_timeline), and tests/pixel-home-latency-regression.php exists
 * specifically to assert the Home handler never calls it again -- the fault it
 * guards against is a page that stalls for 18 seconds because a followed
 * instance is slow.
 *
 * SO THE ONLY THING REFERRING TO IT WAS A TEST ASSERTING IT IS NOT USED. That
 * is a strange state to leave code in: 26 lines that cannot run, next to a
 * guard whose whole job is to stop them running.
 *
 * WHY A TEST AND NOT JUST A DELETION. The PM's instruction was to remove it
 * "only with a test proving nothing calls it". A deletion is easy to justify
 * and easy to get wrong -- a function reached through call_user_func() or a
 * dispatch table looks dead to a grep for `name(`. So this scans EVERY php file
 * for the bare name, in any form, and asserts the only mentions left are the
 * two tests that talk ABOUT it.
 *
 * It also keeps the deletion honest in the other direction: if someone
 * reintroduces the function or a caller, this fails and names the file.
 */

$root = dirname(__DIR__);
$failures = [];

function hf_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

/** Every .php file in the repo, skipping version control and vendor trees. */
function hf_php_files(string $root): array {
    $out = [];
    $skip = ['.git', 'node_modules', 'vendor', '.codex-temp'];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function ($file) use ($skip): bool {
                return !in_array($file->getFilename(), $skip, true);
            }
        )
    );
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}

echo "SS-1481 #16 — sv_home_feed() is gone, and stays gone\n\n";

$files = hf_php_files($root);
hf_check('the scan found a plausible number of php files (' . count($files) . ')',
         count($files) > 100, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 1 — NOTHING MENTIONS IT BUT THE TESTS THAT TALK ABOUT IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// The bare NAME, not the call shape, and read from COMMENT-STRIPPED code. A
// function reached through call_user_func('sv_home_feed') or a dispatch table
// looks dead to a grep for `sv_home_feed(`, and that is the mistake this test
// exists to prevent -- a string literal in code survives the stripper. Prose
// does not: a comment explaining the removal is not a caller.
$allowed = [
    'tests' . DIRECTORY_SEPARATOR . 'sv-home-feed-is-gone-regression.php',
    'tests' . DIRECTORY_SEPARATOR . 'pixel-home-latency-regression.php',
];
/**
 * A file's code with comments removed.
 *
 * The name has to be looked for in CODE, not in prose. A comment explaining
 * why the function was removed is a legitimate mention -- my own breadcrumb in
 * core/fediverse.php failed this check on its first run -- while a string
 * literal 'sv_home_feed' inside code survives comment stripping, so the
 * dynamic-dispatch case this test exists for is still caught.
 */
function hf_code_only(string $php): string {
    $out = '';
    foreach (@token_get_all($php) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
}

$mentions = [];
foreach ($files as $file) {
    $body = hf_code_only((string)file_get_contents($file));
    if (!str_contains($body, 'sv_home_feed')) continue;
    $rel = substr($file, strlen($root) + 1);
    $ok = false;
    foreach ($allowed as $a) { if ($rel === $a) { $ok = true; break; } }
    if (!$ok) $mentions[] = $rel;
}
hf_check('no php CODE outside those two tests mentions sv_home_feed'
         . ($mentions ? ' — FOUND: ' . implode(', ', $mentions) : ''),
         $mentions === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — AND IT IS NOT DEFINED ANY MORE\n";
// ─────────────────────────────────────────────────────────────────────────────
$fedi = (string)file_get_contents($root . '/core/fediverse.php');
hf_check('core/fediverse.php no longer defines it',
         !str_contains($fedi, 'function sv_home_feed'), $failures);
hf_check('and the function does not exist once core is loaded',
         !function_exists('sv_home_feed'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — WHAT REPLACED IT IS STILL THERE\n";
// ─────────────────────────────────────────────────────────────────────────────
// A deletion that also removed the replacement would pass every check above.
// Home must still be served by the inbound timeline, which is the whole reason
// the crawl could go.
hf_check('sv_home_timeline() still exists',
         str_contains($fedi, 'function sv_home_timeline'), $failures);
foreach (['pixel.php', 'smack-pixelfed.php'] as $entry) {
    $src = (string)file_get_contents($root . '/' . $entry);
    hf_check("{$entry} still reads the inbound Home timeline",
             str_contains($src, 'sv_home_timeline('), $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — THE GUARD THAT MADE IT DEAD IS UNTOUCHED\n";
// ─────────────────────────────────────────────────────────────────────────────
// tests/pixel-home-latency-regression.php is why the crawl stopped being
// called. Deleting the function must not be an excuse to drop that guard: the
// fault it protects against is a Home tab that stalls on a slow peer, and a
// future crawl would be just as bad.
$guard = (string)file_get_contents($root . '/tests/pixel-home-latency-regression.php');
hf_check('the Home latency guard still refuses a synchronous crawl',
         str_contains($guard, "sv_home_feed(") && str_contains($guard, 'set_time_limit'),
         $failures);
hf_check('and still requires the inbound timeline instead',
         str_contains($guard, 'sv_home_timeline('), $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #16 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #16 PASS — sv_home_feed() is defined nowhere and mentioned only by\n";
echo "the two tests that talk about it; the inbound Home timeline that replaced\n";
echo "it is intact; and the latency guard that made it dead is untouched.\n";
echo "NOT COVERED: a caller in a language this scan does not read. The repo was\n";
echo "searched for the bare name in php, js, cjs, py and md before deleting, and\n";
echo "only the changelog's historical note and these tests came back.\n";
// ===== SNAPSMACK EOF =====
