<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The agreed-rules gate is shown to CATCH A PLANTED VIOLATION, every run.
 *
 * Sean, 3 November 2026: "Every time a gate is built it fails to keep back what
 * it was built for." He is right, and the pattern behind it is always the same:
 * the gate is tested for EXISTING, never for STOPPING. A green test beside a
 * guard with no callers. A check that read a source string and passed with the
 * whole skip deleted. This file exists so that the agreed-rules gate cannot
 * join that list quietly.
 *
 * HOW. For each rule under test it PLANTS a real violation in a real tracked
 * file, runs the actual gate as a subprocess, and requires that rule's message
 * to appear. Then it removes the plant and requires the message to go away.
 * Both directions matter: a gate that always complains is as useless as one
 * that never does.
 *
 * WHY THE PLANT LIVES HERE AND NOT ON A SCHEDULE. The approved design (Sean,
 * 11:11, choosing the objection I raised) is plant-and-remove INSIDE the
 * mutation run rather than a scheduled branch carrying a checked-in violation.
 * An unattended plant that nobody notices has gone missing is a new silent
 * failure of exactly the kind being prevented. Here the plant is created and
 * destroyed within one run, so there is nothing to rot, and this file is itself
 * wired into the release gate -- so a gate that STOPS catching plants fails the
 * build immediately instead of at the next scheduled plant.
 *
 * THE GATE IS CURRENTLY RED for reasons unrelated to these rules (the source
 * version is a published tag). So this test NEVER reads the exit code. It reads
 * whether the specific rule's message is present. A gate that is red for the
 * wrong reason must not be able to masquerade as a gate that caught the plant.
 *
 * RESTORATION IS NOT OPTIONAL. Every plant is removed in a finally, the bytes
 * are compared against the original, and the run FAILS LOUDLY if a file was
 * left modified -- because a test that corrupts the tree it is checking is
 * worse than no test.
 */

$root = dirname(__DIR__);
$gate = $root . '/tests/agreed-rules-gate.php';
$fail = 0;

function px(bool $ok, string $what): void {
    global $fail;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

/** Run the real gate and return its combined output. Exit code is ignored on
 *  purpose -- see the header. */
function run_gate(string $gate, string $root): string {
    $cmd = 'php ' . escapeshellarg($gate) . ' 2>&1';
    $cwd = getcwd();
    chdir($root);
    $out = (string)shell_exec($cmd);
    chdir($cwd !== false ? $cwd : $root);
    return $out;
}

echo "The agreed-rules gate catches planted violations\n";

if (!is_file($gate)) {
    echo "  FAIL  tests/agreed-rules-gate.php is missing\n\n1 CHECK(S) FAILED\n";
    exit(1);
}

$baseline = run_gate($gate, $root);
px($baseline !== '', 'the gate runs and produces output');

// ── RULE 7 — UTF-8 ──────────────────────────────────────────────────────────
// Planted into a file that IS valid UTF-8 now, so the plant is the only reason
// the gate could name it. core/public-repository.php is chosen because it is
// tracked, text, and not the known-bad file.
$victim    = $root . '/core/public-repository.php';
$needle    = 'public-repository.php';
$rule7Says = 'are NOT valid UTF-8';

// REPLAY NOW COMES FROM THE PLANT, NOT FROM A BUG LEFT IN THE TREE.
//
// This file first asserted that the gate names core/smacktalk-public-controller.php,
// which carried a Windows-1252 em-dash at offset 34. Sean approved fixing it
// (f29ea216), so that violation is gone and those assertions correctly began to
// fail. They are replaced rather than patched: a test kept green by a bug
// staying in the repository is not a test, it is a hostage.
//
// What remains is stronger. The plant below is the SAME real-world byte, created
// fresh every run, so the replay cannot rot and does not depend on anything
// staying broken. This is exactly why Sean's acceptance rules list CANARY
// alongside REPLAY.
px(!str_contains($baseline, $rule7Says),
   'the tree is now CLEAN of non-UTF-8 source, so rule 7 is a ratchet at zero');
px(!str_contains($baseline, $needle),
   'and the gate does NOT name the file about to be planted, so the plant is the signal');

$original = (string)file_get_contents($victim);
$restored = false;
try {
    // 0x97 is the exact byte that is already wrong in the other file: a
    // Windows-1252 em-dash that never became UTF-8. Planting the real-world
    // fault, not an arbitrary invalid sequence.
    $planted = substr($original, 0, 80) . "\x97" . substr($original, 80);
    file_put_contents($victim, $planted);
    px((string)file_get_contents($victim) === $planted, 'the plant reached disk');

    $withPlant = run_gate($gate, $root);
    px(str_contains($withPlant, $needle),
       'THE GATE CATCHES THE PLANT: it names core/public-repository.php');
    px(preg_match('/byte 0x97 at offset \d+/', $withPlant) === 1,
       'and reports the byte and offset, so the fix is one edit and not a hunt');
} finally {
    file_put_contents($victim, $original);
    clearstatcache(true, $victim);
    $restored = (string)file_get_contents($victim) === $original;
}
px($restored, 'THE PLANT IS REMOVED and the file is byte-identical again');

$after = run_gate($gate, $root);
px(!str_contains($after, $needle),
   'and the gate stops naming it -- the rule is not simply always complaining');
px(!str_contains($after, $rule7Says),
   'and the tree is clean again -- no residue from the plant');

// ── THE GATE IS NOT ALLOWED TO BE A NO-OP ───────────────────────────────────
// If somebody deletes rule 7 entirely, every check above would pass trivially
// on a gate that reports nothing. So require the rule's own vocabulary to be
// present in the gate source as well as in its output.
$src = (string)file_get_contents($gate);
px(str_contains($src, 'mb_check_encoding'),
   'the gate actually decodes bytes rather than pattern-matching for a character');
px(str_contains($src, 'TEXT_SOURCE_EXT'),
   'and scopes itself to text source by extension, not by guessing at binaries');

// ── THE WIRING, which is the thing that actually failed ─────────────────────
// The gate was correct and unwired for weeks. So the wiring gets its own
// checks, and they are deliberately NOT satisfied by the glob: a gate reachable
// only because its filename happens to match a pattern is how this fell out.
$flow = (string)file_get_contents($root . '/tools/release-flow.php');
px(str_contains($flow, 'RF_NAMED_GATES'),
   'the release flow has an EXPLICIT named-gate list, not just a filename glob');
px(preg_match('/RF_NAMED_GATES\s*=\s*\[[^\]]*agreed-rules-gate\.php/s', $flow) === 1,
   'and tests/agreed-rules-gate.php is named in it');
$fn = substr($flow, (int)strpos($flow, 'function rf_tests'));
$fn = substr($fn, 0, (int)strpos($fn, 'rf_browser_tests();'));
$posNamed = strpos($fn, 'RF_NAMED_GATES');
$posGlob  = strpos($fn, 'glob(');
px($posNamed !== false && $posGlob !== false && $posNamed < $posGlob,
   'rf_tests() runs the named gates BEFORE the glob, so a release stops early');
// Two precise checks, not one loose OR. The first version of this read
//     str_contains($fn, 'rf_fail') || str_contains($fn, 'is_file')
// and mutation proof caught it: replacing the rf_fail() with `continue` left
// is_file in place, so the OR was satisfied and the suite passed a release flow
// that silently SKIPS a missing gate. An OR between two signals is how a check
// passes for the wrong reason -- the same shape of fault as counting a selector
// that is merely present in a file.
px(preg_match('/if\s*\(!is_file\(\$path\)\)\s*rf_fail\(/', $fn) === 1,
   'a MISSING named gate calls rf_fail, so the release stops');
px(!preg_match('/if\s*\(!is_file\(\$path\)\)\s*(continue|return|\{\s*\})/', $fn),
   'and is never skipped, returned past, or swallowed by an empty branch');

$hook = (string)@file_get_contents($root . '/.githooks/pre-commit');
px($hook !== '', 'the pre-commit hook exists');
px(str_contains($hook, 'agreed-rules-gate.php'),
   'and the commit path runs the gate too');
// Trigger widened by Sean on 3 November after I measured the mismatch: rule 7
// scans 2,205 tracked text files and only 333 were under core/ or skins/, so
// 1,872 were covered by a rule that could not trigger its own check.
px(str_contains($hook, 'needs_agreed_rules = bool(staged)'),
   'ANY staged file triggers the gate, not a path prefix');
px(!str_contains($hook, "p.startswith('core/') or p.startswith('skins/') for p in staged"),
   'and the old core/-or-skins/ trigger is gone, not merely widened alongside');

// ── TWO SEVERITIES ──────────────────────────────────────────────────────────
// Codex's review of 85239f9c: a legitimately open build number must not turn a
// UTF-8 or boundary violation into a non-blocking warning. The first version of
// this hook blocked on nothing for exactly that reason, which meant release
// bookkeeping was silencing a source-security rule.
px(str_contains($hook, "'--commit'"),
   'the commit path asks for COMMIT severity explicitly');
px(str_contains($hook, 'blocked = blocked or bool(agreed_rules_block)'),
   'and a source-rule failure BLOCKS the commit');
px(!str_contains($flow, "--commit"),
   'while the release path passes no flag, so every rule is fatal there');

// Behaviour, not just source: run the real gate both ways and compare.
$relOut = run_gate($gate, $root);
$cmdCommit = 'php ' . escapeshellarg($gate) . ' --commit 2>&1';
$cwd = getcwd(); chdir($root); $comOut = (string)shell_exec($cmdCommit); chdir($cwd !== false ? $cwd : $root);

px(str_contains($relOut, 'AGREED RULES GATE FAILED')
   && str_contains($relOut, 'IN SERVICE on the fleet'),
   'at RELEASE severity the version finding is fatal');
px(str_contains($comOut, 'RELEASE-READINESS')
   && str_contains($comOut, 'PUBLISHED, not just deployed'),
   'at COMMIT severity the tag finding is deferred -- and still PRINTED');
px(str_contains($comOut, 'RELEASE-READINESS'),
   'and the commit run still names its deferred items rather than going quiet');

// ── COMMIT SCOPE MAKES NO NETWORK CALL ──────────────────────────────────────
// tools/_build/deployed-version.sh:57 curls the live fleet with a 10s timeout.
// Measured at 5.2s of the gate's 8.7s. On a commit hook that is friction people
// route around, it puts traffic on Sean's own sites, and -- worst -- it makes
// the hook behave DIFFERENTLY OFFLINE, because the lookup returns empty and the
// check silently does not run. Asking the fleet is release readiness, so at
// commit severity the question is not asked at all rather than asked and
// ignored.
px(str_contains($src, '$deployed = $commit_scope'),
   'the fleet lookup is gated on severity, not run unconditionally');
px(str_contains($comOut, 'the fleet was NOT asked what it is serving'),
   'and the commit run SAYS it did not ask, instead of leaving a silent gap');
px(str_contains($relOut, 'IN SERVICE on the fleet'),
   'while the release run does ask and reports the answer');

// ── RULE 7 SCOPE BY SEVERITY, proved by a plant the commit run MUST MISS ────
// At commit severity rule 7 reads the STAGED files, because the question is
// what you are adding. A pre-existing bad byte is the release run's job. That
// is also what broke the deadlock where one byte already in the tree blocked
// every commit in the repository.
px(str_contains($src, 'diff --cached --name-only'),
   'commit severity asks git for the STAGED files');
$unstagedVictim = $root . '/core/public-visibility.php';
$uOrig = (string)file_get_contents($unstagedVictim);
$uRestored = false;
try {
    file_put_contents($unstagedVictim, substr($uOrig, 0, 60) . "\x97" . substr($uOrig, 60));
    $cwd = getcwd(); chdir($root);
    $cOut2 = (string)shell_exec('php ' . escapeshellarg($gate) . ' --commit 2>&1');
    $rOut2 = (string)shell_exec('php ' . escapeshellarg($gate) . ' 2>&1');
    chdir($cwd !== false ? $cwd : $root);
    px(!str_contains($cOut2, 'public-visibility.php'),
       'an UNSTAGED bad byte is NOT a commit-severity failure -- that is the point');
    px(str_contains($rOut2, 'public-visibility.php'),
       'but the RELEASE run catches it, so nothing is actually let through');
} finally {
    file_put_contents($unstagedVictim, $uOrig);
    clearstatcache(true, $unstagedVictim);
    $uRestored = (string)file_get_contents($unstagedVictim) === $uOrig;
}
px($uRestored, 'and that plant is removed too, byte-identical');

echo $fail === 0
    ? "\nPASS - the gate catches a planted violation and releases it again\n"
    : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
