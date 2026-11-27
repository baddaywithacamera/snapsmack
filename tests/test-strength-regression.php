<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A check cannot be weakened quietly.
 *
 * OPAUDIT 026, step 2. On 24 October 2026 three regression tests were red on
 * dev for a day. Two of them were made green by editing the test rather than
 * the code: one threshold went from 23 to 22. That number was standing in
 * front of the phone skin losing the owner's menu, and lowering it by one
 * removed the only thing that would have said so. The diff was one character.
 * Nothing announced it. Three release tags were cut over the top.
 *
 * Deleting a wall is loud. Lowering a bar was silent. This file makes them
 * the same volume, using the trick already working in
 * tests/skin-render-snapshot-regression.php: record what each test demands
 * into a committed fixture, and fail when it drops.
 *
 *   php tests/test-strength-regression.php            check
 *   php tests/test-strength-regression.php --update   accept the change
 *
 * Three things are recorded per test file.
 *
 *   assertions  — how many times the file asserts anything. A RATCHET: it may
 *                 grow freely, because adding checks needs no ceremony. It may
 *                 not drop without --update.
 *
 *   predicates  — how many questions it asks of the thing under test. Fifty
 *                 files here never call an assertion helper; they build a
 *                 table of label => str_contains(...) and loop it. Same
 *                 ratchet, so those files are held up too.
 *
 *   thresholds  — every number the file compares against, sorted. Any change
 *                 at all needs --update, up or down, because a bar being
 *                 raised and a bar being lowered look identical in a diff and
 *                 only the person making the change knows which it is.
 *
 * Numbers below 2 are left out. `=== 0`, `!== 1` and friends are plumbing, not
 * bars, and counting them would make this file fire constantly — which is the
 * fastest way to train somebody to run --update without reading it.
 *
 * What this does NOT cover, said plainly. It counts assertions; it cannot tell
 * a strong one from a weak one, so a check gutted from the inside while its
 * count stays the same passes here. It reads test files as text, so a
 * threshold held in a fixture file or computed at run time is invisible to it.
 * It is a tripwire on the shape of the suite, not a judge of the suite.
 */

$root        = dirname(__DIR__);
$fixturePath = $root . '/tests/fixtures/test-strength.json';
$updating    = in_array('--update', $argv, true);

/**
 * How many times this file asserts something.
 *
 * The idioms are the ones the suite actually uses, measured across all of
 * tests/ rather than guessed: helper calls whose name contains expect, assert,
 * check or verify; a thrown RuntimeException; and an append to a failures or
 * problems list. Helper definitions match too, which adds a constant one or
 * two per file and does not matter — only the direction of the number does.
 */
function strength_assertions(string $source): int
{
    $total = 0;
    // The prefix is optional so a closure called as $check(...) counts too;
    // much of this suite asserts through a local closure, not a function.
    $total += preg_match_all('/\b[A-Za-z0-9_]*(?:expect|assert|check|verify|_test|test_)[A-Za-z0-9_]*\s*\(/i', $source);
    // The short helper names the rest of the suite uses, matched whole so
    // that ordinary words containing them (token, hook, lookup) do not count.
    // cx is the two-letter closure five of the .cjs files assert through;
    // measured across tests/, not guessed, same as the rest of this list.
    $total += preg_match_all('/\b(?:test|ok|fail|failure|cx)\s*\(/i', $source);
    // node:assert, which is a DOTTED call -- assert.equal(...) -- so the
    // identifier pattern above never sees it followed by a bracket. Without
    // this, jive-border-geometry-browser.cjs recorded zero assertions and zero
    // predicates: in the record and unprotected by it, which is the one
    // outcome worse than being left out.
    $total += preg_match_all('/\b(?:assert|expect)\s*\.\s*[A-Za-z0-9_]+\s*\(/i', $source);
    $total += preg_match_all('/\bthrow\s+new\s+\w*(?:Exception|Error)\b/i', $source);
    $total += preg_match_all('/\$(?:failures|problems|errors|dirty)\s*\[\s*\]\s*=/i', $source);
    // The plainest style in the suite: if the thing is wrong, say so and stop.
    // This also catches process.exit(1), which is how the .cjs files do it.
    $total += preg_match_all('/\bexit\s*\(\s*1\s*\)/', $source);
    // A COUNTER THAT GOES UP WHEN SOMETHING IS WRONG. The JavaScript
    // counterpart of the failures-list append, and the PHP one too: the
    // optional quote-and-bracket covers $GLOBALS['fail']++, which is how
    // archive-query-time-budget-benchmark.php counts. That file recorded zero
    // assertions and zero asks, so it sat inside the record with nothing
    // holding it.
    //
    // This GENERALISES the idiom rather than adding its helper name to the
    // short list. Adding bx() would have fixed one file and left the next
    // two-letter closure invisible, which is the over-fitting this gate exists
    // to catch; and renaming the helper to match an existing one would be
    // changing somebody's test to suit my counter.
    $total += preg_match_all(
        '/\b(?:fail|fails|failures|failed)\b\s*[\'"]?\s*\]?\s*(?:\+\+|\+=)/i',
        $source
    );

    // THE HELPER THIS FILE ASSERTS THROUGH, WHATEVER IT IS CALLED. 158 of the
    // files in tests/ define a boolean-first helper -- function x(bool $ok,
    // string $what) -- and they use at least a dozen different names for it:
    // cx, tx, tt, bx, s_ok, ar_test, check. The short-name list above catches
    // some by luck. Rather than growing that list one name at a time, which
    // leaves the NEXT two-letter closure invisible and is the over-fitting
    // this gate exists to catch, the name is read out of the file's own
    // definition and its calls are counted.
    //
    // This double-counts the names the patterns above already match. That is
    // fine and deliberate: only the DIRECTION of these numbers is ever read,
    // never the size, and every baseline is derived by the same code.
    $helpers = [];
    preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(\s*bool\s/i', $source, $named);
    foreach ($named[1] ?? [] as $name) { $helpers[] = '\b' . preg_quote($name, '/'); }
    preg_match_all(
        '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:static\s+)?(?:fn|function)\s*\(\s*bool\s/i',
        $source, $closures);
    foreach ($closures[1] ?? [] as $name) { $helpers[] = '\$' . preg_quote($name, '/'); }
    foreach (array_unique($helpers) as $helper) {
        $total += preg_match_all('/' . $helper . '\s*\(/', $source);
    }
    return $total;
}

/**
 * How many questions this file asks of the thing under test.
 *
 * Fifty of the files in tests/ never call a helper at all: they build a table
 * of label => str_contains(...) and loop over it. Counting helper calls alone
 * records nothing for those, and a ratchet at zero holds nothing up. The
 * predicate is the check in that style, so the predicates are counted.
 *
 * This over-counts — the same functions get used in setup code — and that is
 * fine. Only the direction is read, never the size.
 */
function strength_predicates(string $source, bool $isJs): int
{
    $total = preg_match_all(
        '/\b(?:str_contains|str_starts_with|str_ends_with|substr_count|strpos|stripos'
        . '|preg_match|preg_match_all|in_array|array_key_exists|isset)\s*\(/i',
        $source
    );
    // The same idea in JavaScript, for the .cjs and .js tests. Several of them
    // never call a helper at all -- they build a list of {ok, name} objects and
    // print it -- so without these they would record a zero, and a ratchet at
    // zero holds nothing up. That would be the worst outcome of widening this
    // gate: a file inside the record that the record cannot protect.
    $total += preg_match_all(
        '/\.(?:includes|startsWith|endsWith|indexOf|match|matchAll)\s*\(/',
        $source
    );
    $total += preg_match_all('/\b(?:Array\.isArray|RegExp)\s*\(/', $source);

    // IDENTITY COMPARISONS ARE COUNTED FOR JAVASCRIPT ONLY, and the reason is
    // the point of this whole gate. PHP uses === everywhere, so counting it
    // moved 469 existing baselines upward and DILUTED them: one file went from
    // 1 predicate to 6, so deleting its single real check would read as 6 to 5
    // instead of 1 to 0. Widening the record must not weaken the half of it
    // that already worked. The .cjs files need it because several of them
    // compare values directly with no helper at all.
    if ($isJs) {
        $total += preg_match_all('/[A-Za-z0-9_$\)\]\'"]\s*(?:===|!==)\s*/', $source);
    }
    return $total;
}

/**
 * Every number this file holds something to, sorted, smallest first.
 *
 * Both sides are read: `count($x) >= 23` and `23 <= count($x)` are the same
 * bar. Values under 2 are dropped as plumbing. The list is a multiset, so a
 * file with three separate `>= 5` checks records 5 three times and losing one
 * of them shows up.
 */
function strength_thresholds(string $source): array
{
    $found = [];
    // A bare < or > must not match PHP's => arrow or -> operator, or every
    // array value in the file gets recorded as a bar the code is held to.
    $operator = '(?:<=|>=|===|!==|==|!=|(?<![-=!<>])[<>](?![=>]))';
    preg_match_all('/' . $operator . '\s*(-?\d+(?:\.\d+)?)\b/', $source, $right);
    preg_match_all('/\b(-?\d+(?:\.\d+)?)\s*' . $operator . '/', $source, $left);
    foreach (array_merge($right[1] ?? [], $left[1] ?? []) as $raw) {
        $value = (float)$raw;
        if (abs($value) < 2) continue;
        $found[] = $value == (int)$value ? (int)$value : $value;
    }
    sort($found);
    return array_values($found);
}

// THE BROWSER AND GEOMETRY TESTS ARE IN THE RECORD NOW. (PM, 2026-11-27, with
// Codex agreeing it affects his evidence too.) This gate globbed tests/*.php
// only, so all 29 .cjs and .js files sat outside it and a check could be
// deleted from any of them unnoticed. Those are the tests that measure what
// actually reaches the owner -- geometry on a rendered page -- which made the
// gap the wrong way round: the tripwire guarded the half of the suite that
// reads markup and not the half that measures pixels.
//
// tools/browser-tests/ is NOT globbed. Codex owns that directory and its
// runners, and it is not tests/.
$paths = [];
foreach (['php', 'cjs', 'js', 'mjs'] as $extension) {
    foreach (glob($root . '/tests/*.' . $extension) ?: [] as $path) { $paths[] = $path; }
}

$current = [];
foreach ($paths as $path) {
    $name = basename($path);
    $isJs = !str_ends_with($name, '.php');
    if ($name === basename(__FILE__)) continue;   // this file is not a check of the code
    $source = (string)file_get_contents($path);
    $current[$name] = [
        'assertions' => strength_assertions($source),
        'predicates' => strength_predicates($source, $isJs),
        'thresholds' => strength_thresholds($source),
    ];
}
ksort($current);
if ($current === []) throw new RuntimeException('No test files found; this gate would pass by measuring nothing.');

$encoded = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($updating || !is_file($fixturePath)) {
    if (!is_dir(dirname($fixturePath))) mkdir(dirname($fixturePath), 0775, true);
    file_put_contents($fixturePath, $encoded);
    $verb = $updating ? 'Updated' : 'Created';
    echo "{$verb} the strength record for " . count($current) . " test files.\n";
    echo "Read the diff before committing it. A number going down is a check going away.\n";
    // A FILE THAT RECORDS ZERO AND ZERO IS IN THE RECORD AND NOT PROTECTED BY
    // IT: a ratchet at zero cannot go down, so every check in that file could
    // be deleted and this gate would still pass. Before the .cjs files were
    // adopted there were FOUR such PHP files and nobody knew, because the gate
    // never said. It says now. This reports rather than refuses, because the
    // honest fix is to recognise that file's idiom, not to fail the suite over
    // a file somebody wrote in good faith.
    $unprotected = [];
    foreach ($current as $name => $counts) {
        if ((int)$counts['assertions'] === 0 && (int)$counts['predicates'] === 0) {
            $unprotected[] = $name;
        }
    }
    if ($unprotected !== []) {
        echo "\nIN THE RECORD BUT NOT PROTECTED BY IT (zero assertions, zero asks):\n";
        foreach ($unprotected as $name) { echo "  - {$name}\n"; }
        echo "A ratchet at zero holds nothing. Each of these asserts in a way\n"
           . "strength_assertions() does not recognise; teach it that idiom.\n";
    }
    // ===== SNAPSMACK EOF =====
    return;
}

$stored = json_decode((string)file_get_contents($fixturePath), true);
if (!is_array($stored)) throw new RuntimeException('tests/fixtures/test-strength.json is unreadable; regenerate it with --update.');

/**
 * Everything the record says has got weaker, in the words somebody will read.
 *
 * A function rather than a loop in the body, so the mutation checks at the
 * bottom of this file can run it against made-up records and watch it object.
 */
function strength_problems(array $current, array $stored): array
{
    $problems = [];

foreach ($current as $name => $now) {
    if (!array_key_exists($name, $stored)) {
        $problems[] = "{$name}: a new test file, not yet in the record. Run --update to adopt it.";
        continue;
    }
    $was = $stored[$name];

    foreach (['assertions' => 'asserts', 'predicates' => 'asks'] as $field => $verb) {
        $wasCount = (int)($was[$field] ?? 0);
        if ($now[$field] < $wasCount) {
            $problems[] = sprintf(
                "%s: %s less than it did — %d, was %d.\n"
                . "      Something was deleted. If that was the point, run --update.",
                $name, $verb, $now[$field], $wasCount
            );
        }
    }

    $wasBars = array_map('floatval', (array)($was['thresholds'] ?? []));
    $nowBars = array_map('floatval', $now['thresholds']);
    if ($wasBars !== $nowBars) {
        $lowered = [];
        foreach (array_values(array_diff_assoc($wasBars, $nowBars)) as $gone) {
            $lowered[] = (string)($gone == (int)$gone ? (int)$gone : $gone);
        }
        $problems[] = sprintf(
            "%s: the numbers it holds the code to changed.\n      was: %s\n      now: %s\n"
            . "      %sIf every one of those was deliberate, run --update.",
            $name,
            implode(', ', array_map('strval', $was['thresholds'] ?? [])) ?: '(none)',
            implode(', ', array_map('strval', $now['thresholds'])) ?: '(none)',
            $lowered === [] ? '' : 'Gone from the file: ' . implode(', ', $lowered) . ".\n      "
        );
    }
}

foreach ($stored as $name => $ignored) {
    if (!array_key_exists($name, $current)) {
        $problems[] = "{$name}: this test file is gone. If it was meant to go, run --update.";
    }
}

    return $problems;
}

/* ── the gate, watched failing ────────────────────────────────────────────
 * A check nobody has seen fail is not a check. These run every time and cost
 * nothing: five made-up records, each a way a check has actually been
 * weakened, and one control that must stay silent. The second one is the real
 * October case -- a threshold edited from 23 to 22 so a red test went green.
 */
$mutation = static function (array $stored, array $now): int {
    return count(strength_problems(['example.php' => $now], ['example.php' => $stored]));
};
$solid = ['assertions' => 12, 'predicates' => 30, 'thresholds' => [3, 23]];
$mutations = [
    'an assertion deleted' => [$solid, ['assertions' => 11, 'predicates' => 30, 'thresholds' => [3, 23]]],
    'a threshold lowered from 23 to 22' => [$solid, ['assertions' => 12, 'predicates' => 30, 'thresholds' => [3, 22]]],
    'a threshold removed altogether' => [$solid, ['assertions' => 12, 'predicates' => 30, 'thresholds' => [3]]],
    'a predicate deleted' => [$solid, ['assertions' => 12, 'predicates' => 29, 'thresholds' => [3, 23]]],
];
$caught = [];
foreach ($mutations as $label => [$stored_record, $now_record]) {
    if ($mutation($stored_record, $now_record) === 0) $caught[] = "this gate does not notice {$label}";
}
if ($mutation($solid, ['assertions' => 14, 'predicates' => 31, 'thresholds' => [3, 23]]) !== 0) {
    $caught[] = 'this gate objects to a test being strengthened, which it must not';
}
if (strength_problems([], ['gone.php' => $solid]) === []) {
    $caught[] = 'this gate does not notice a whole test file disappearing';
}
if ($caught !== []) {
    fwrite(STDERR, "This gate cannot catch what it is for:
");
    foreach ($caught as $miss) fwrite(STDERR, "  - {$miss}
");
    exit(1);
}

$problems = strength_problems($current, $stored);

if ($problems !== []) {
    fwrite(STDERR, "The suite demands less than it did, on " . count($problems) . " point(s):\n\n");
    foreach ($problems as $problem) fwrite(STDERR, "  - {$problem}\n");
    fwrite(STDERR, "\nA test made green by editing the test is a regression that shipped.\n");
    fwrite(STDERR, "That is OPAUDIT 026: 23 became 22 and the phone skin lost its menu.\n");
    fwrite(STDERR, "Run with --update only once you can say what each change is for.\n");
    exit(1);
}

echo "Test strength holds across " . count($current) . " test files.\n";
// ===== SNAPSMACK EOF =====
