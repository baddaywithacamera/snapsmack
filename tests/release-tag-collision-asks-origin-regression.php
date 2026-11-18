<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * THE TAG-COLLISION CHECK ASKS ORIGIN, AND IT CANNOT READ "FREE" BY MISTAKE.
 *
 * tag-dev could not run at all. Before checking whether v0.7.998D was taken
 * it ran `git fetch Github --tags --prune` so the local refs it then inspected
 * would match origin, and in this repository that fetch EXITS NON-ZERO: 1271
 * of 1290 local tags disagree with origin -- old alpha tags and archive/*
 * branch snapshots, no release tags among them -- so git refuses to clobber
 * each one and rf_run() treats a non-zero exit as fatal. The release tool
 * answered with a wall of "would clobber existing tag" and the 998D tag could
 * not be cut, over a fact that has nothing to do with it.
 *
 * The fetch is best-effort now and the collision question goes straight to
 * origin for the ONE tag, which is both narrower and stronger: origin decides
 * whether a tag is taken, and that answer cannot be stale.
 *
 * WHY THIS TEST IS ABOUT THE PARSING. The dangerous direction is reading "not
 * found" from a listing that DOES contain the tag -- that would let a release
 * re-use a tag already on origin, and a published tag is immutable. The
 * parsing is a pure function for exactly that reason; nothing here touches a
 * network.
 */

$root = dirname(__DIR__);
$failures = [];

// release-flow.php runs a command when included, so the functions are read
// out of it rather than executed. Everything below is the real source.
$src = (string)@file_get_contents($root . '/tools/release-flow.php');
if ($src === '') {
    throw new RuntimeException('could not read tools/release-flow.php');
}
$at = strpos($src, 'function rf_parse_ls_remote_tag');
if ($at === false) {
    throw new RuntimeException('rf_parse_ls_remote_tag is gone; the collision '
        . 'check no longer has a testable parser');
}
$end = strpos($src, "\n}", $at);
eval(substr($src, $at, $end - $at + 2));

$ref = 'refs/tags/v0.7.998D';

// ── 1. A TAG THAT IS ON ORIGIN IS FOUND ──────────────────────────────────
$found = [
    '8414a3e5c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5' . "\t" . 'refs/tags/v0.7.997D',
    'd78324353fd681313165c79ffaa67c67febbf43a' . "\t" . 'refs/tags/v0.7.998D',
];
if (rf_parse_ls_remote_tag($found, $ref) !== 'd78324353fd681313165c79ffaa67c67febbf43a') {
    $failures[] = 'a tag present on origin was read as free. A release would '
        . 'then try to re-use a tag that is already published and immutable.';
}

// An annotated tag also lists its peeled line; either form means taken.
$peeled = ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' . "\t" . 'refs/tags/v0.7.998D^{}'];
if (rf_parse_ls_remote_tag($peeled, $ref) === '') {
    $failures[] = 'an annotated tag listed only by its peeled ref was read as '
        . 'free';
}

// ── 2. A FREE TAG IS FREE ────────────────────────────────────────────────
if (rf_parse_ls_remote_tag([
        '8414a3e5c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5' . "\t" . 'refs/tags/v0.7.997D',
    ], $ref) !== '') {
    $failures[] = 'an unused tag was reported as taken, which would block every '
        . 'release';
}
if (rf_parse_ls_remote_tag([], $ref) !== '') {
    $failures[] = 'an empty listing reported a tag as taken';
}

// ── 3. A NEAR MISS IS NOT A MATCH ────────────────────────────────────────
// v0.7.998D1 and v0.7.998 are different tags. Prefix matching here would
// refuse a legitimate release, or worse, accept a taken one.
foreach (['refs/tags/v0.7.998D1', 'refs/tags/v0.7.998', 'refs/tags/xv0.7.998D',
          'refs/heads/v0.7.998D'] as $other) {
    if (rf_parse_ls_remote_tag(['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' . "\t" . $other], $ref) !== '') {
        $failures[] = "'{$other}' was matched against {$ref}";
    }
}

// ── 4. NOISE IS NOT AN ANSWER ────────────────────────────────────────────
// ls-remote's stderr gets captured with its stdout. A progress or warning
// line must never be read as a hash, in either direction.
$noisy = [
    'From https://github.com/baddaywithacamera/snapsmack',
    'warning: something happened',
    '',
    'd78324353fd681313165c79ffaa67c67febbf43a' . "\t" . 'refs/tags/v0.7.998D',
];
if (rf_parse_ls_remote_tag($noisy, $ref) !== 'd78324353fd681313165c79ffaa67c67febbf43a') {
    $failures[] = 'the tag was missed in a listing that also carried progress '
        . 'or warning lines';
}
$noiseOnly = ['From https://github.com/baddaywithacamera/snapsmack',
              'fatal: could not read from remote repository'];
if (rf_parse_ls_remote_tag($noiseOnly, $ref) !== '') {
    $failures[] = 'an error message was parsed as a tag object id';
}
// AND THE SHAPE THAT ACTUALLY BITES: a git message whose second word IS the
// ref. Without a check that the first field looks like an object id, this
// returns "error:" -- a non-empty string -- and the caller reads the tag as
// taken, refusing a legitimate release for a message about it. My first
// version of this test did not cover it, and removing the hash check passed.
$refInMessage = ['error: ' . $ref, 'warning: ' . $ref . ' is unreachable'];
$parsed = rf_parse_ls_remote_tag($refInMessage, $ref);
if ($parsed !== '') {
    $failures[] = 'a git message mentioning the ref was read as the tag '
        . 'existing (got ' . var_export($parsed, true) . '), which would '
        . 'refuse a release over an error line';
}

// ── 5. THE TOOL ACTUALLY USES IT, AND THE FETCH IS NO LONGER A GATE ──────
$code = (string)php_strip_whitespace($root . '/tools/release-flow.php');
if (!str_contains($code, 'rf_remote_tag_target($tag)')) {
    $failures[] = 'tag-dev no longer asks origin whether the tag is taken; it is '
        . 'back to trusting local refs after a bulk fetch';
}
if (preg_match("/rf_git\(\['fetch',\s*'Github',\s*'--tags',\s*'--prune'\],\s*false\)/", $code)) {
    $failures[] = 'the whole-repository tag fetch is fatal again, so unrelated '
        . 'historical tag drift will block the next release the same way';
}
if (!str_contains($code, 'rf_git_tolerant')) {
    $failures[] = 'rf_git_tolerant is gone, so that fetch is a gate again';
}
// The tolerant runner must not be used for anything that IS a gate.
$tolerantUses = preg_match_all('/rf_git_tolerant\(\[([^\]]*)\]/', $code, $m);
foreach ((array)($m[1] ?? []) as $args) {
    if (!str_contains($args, "'fetch'")) {
        $failures[] = 'rf_git_tolerant is being used for something other than a '
            . 'fetch (' . trim($args) . '). It swallows failures; a gate must '
            . 'never run through it.';
    }
}
if ($tolerantUses < 1) {
    $failures[] = 'rf_git_tolerant is defined but never used, so this check is '
        . 'not covering the call it was written for';
}

if ($failures) {
    throw new RuntimeException("The release tag-collision check is unsafe:\n  - "
        . implode("\n  - ", $failures));
}

echo "Release tag collision: asked of origin, parsed exactly, fetch is not a "
    . "gate: passed.\n";
// ===== SNAPSMACK EOF =====
