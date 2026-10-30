<?php
declare(strict_types=1);

/**
 * promote-stable accepts ONE shape of commit and refuses every other.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * WHY THIS EXISTS. SS-1077. promote-stable could not run for ANY version,
 * because tag-dev required SNAPSMACK_VERSION_SHORT to read <version>D at the
 * tagged commit and promote-stable required it to read <version> at the same
 * commit. One constant, two values, no commit passing both. The stable channel
 * sat on 0.7.670 from 9 September to 30 October 2026 with no way off it.
 *
 * The fix is NOT to let the D through into stable -- the suffix has to keep
 * meaning development. It is to accept a candidate commit sitting exactly one
 * commit above the dev tag, whose entire difference from that tag is the two
 * version lines and an added changelog section. Anything else refuses.
 *
 * WHAT THIS FILE IS CAREFUL ABOUT. Every case below is built as a THROWAWAY
 * GIT REPOSITORY with its own commits. Nothing here reads this repository's
 * refs. A test that asserted against our live dev tip would pass by agreeing
 * with today's state and would keep passing after the rule was deleted, which
 * is the exact mistake that put a comment-matching bug in
 * tests/engines-reach-every-controller.php earlier the same week.
 *
 * Run: php tests/promote-stable-accepts-only-the-version-commit.php
 */

require_once dirname(__DIR__) . '/tools/_build/stable-candidate-check.php';

$checks = 0;
$failures = [];
function p_ok(bool $cond, string $why): void {
    global $checks, $failures;
    $checks++;
    if (!$cond) $failures[] = $why;
}

// -- fixture plumbing ------------------------------------------------------

function fx_run(string $dir, array $args): array {
    $cmd = 'cd ' . escapeshellarg($dir) . ' && '
         . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $lines, $code);
    return [$code, trim(implode("\n", $lines))];
}

function fx_git(string $dir, array $args): string {
    [$code, $out] = fx_run($dir, array_merge(['git'], $args));
    if ($code !== 0) {
        fwrite(STDERR, "fixture git failed in {$dir}: " . implode(' ', $args) . "\n{$out}\n");
        exit(2);
    }
    return $out;
}

function fx_write(string $dir, string $rel, string $body): void {
    $path = $dir . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $body);
}

function fx_constants(string $version): string {
    // Shaped like the real file: the two version lines among other content, so
    // a check that cannot tell them apart from their neighbours will fail.
    return "<?php\n"
        . "// SNAPSMACK - System Constants\n"
        . "define('SNAPSMACK_MIN_PHP', '8.3.0');\n"
        . "\n"
        . "define('SNAPSMACK_VERSION', 'Alpha {$version}');\n"
        . "define('SNAPSMACK_VERSION_SHORT', '{$version}');\n"
        . "define(\"SNAPSMACK_VERSION_CODENAME\", \"FIXTURE\");\n"
        . "\n"
        . "define('SNAPSMACK_THUMB_LONG_EDGE', 600);\n"
        . "// ===== SNAPSMACK EOF =====\n";
}

function fx_changelog(array $sections): string {
    $out = "# Changelog\n\n";
    foreach ($sections as $s) {
        $out .= "## {$s}\n\nsomething happened\n\n";
    }
    return $out;
}

/**
 * A repository with:
 *   commit 1  base
 *   commit 2  the dev cut, source reads <version>D, tagged v<version>D,
 *             branch dev left pointing here
 * and HEAD left on a candidate branch at commit 2, for the caller to build on.
 */
function fx_repo_at_dev_tag(string $version): string {
    $dir = sys_get_temp_dir() . '/ss-promote-fx-' . getmypid() . '-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0777, true);
    fx_git($dir, ['init', '--quiet', '--initial-branch=dev']);
    fx_git($dir, ['config', 'user.email', 'fixture@example.test']);
    fx_git($dir, ['config', 'user.name', 'Fixture']);
    fx_git($dir, ['config', 'commit.gpgsign', 'false']);

    fx_write($dir, 'README.md', "fixture\n");
    fx_git($dir, ['add', '-A']);
    fx_git($dir, ['commit', '--quiet', '-m', 'base']);

    fx_write($dir, 'core/constants.php', fx_constants($version . 'D'));
    fx_write($dir, 'CHANGELOG.md', fx_changelog([$version . 'D - 2026-10-30 - FIXTURE']));
    fx_git($dir, ['add', '-A']);
    fx_git($dir, ['commit', '--quiet', '-m', "cut {$version}D"]);
    fx_git($dir, ['tag', 'v' . $version . 'D']);
    fx_git($dir, ['switch', '--quiet', '-c', 'candidate']);
    return $dir;
}

/** The correct candidate commit: the two version lines, plus an ADDED section. */
function fx_add_good_candidate(string $dir, string $version): void {
    fx_write($dir, 'core/constants.php', fx_constants($version));
    fx_write($dir, 'CHANGELOG.md', fx_changelog([
        $version . ' - 2026-10-30 - FIXTURE STABLE',
        $version . 'D - 2026-10-30 - FIXTURE',
    ]));
    fx_git($dir, ['add', '-A']);
    fx_git($dir, ['commit', '--quiet', '-m', "stable candidate {$version}"]);
}

/**
 * Run the rule against a fixture. The dev ref is a parameter ONLY so a fixture
 * can stand in for refs/remotes/Github/dev; release-flow.php always passes the
 * real remote ref and nothing else may.
 */
function fx_refusals(string $dir, string $version, string $devRef = 'refs/heads/dev'): array {
    $cwd = getcwd();
    chdir($dir);
    try {
        return rfc_stable_candidate_refusals($version, 'HEAD', $devRef);
    } finally {
        chdir($cwd);
    }
}

function fx_cleanup(string $dir): void {
    // Windows leaves read-only objects behind; best effort is enough for a temp
    // directory, and a failure here must never fail the test.
    if (DIRECTORY_SEPARATOR === '\\') {
        @exec('rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $dir)) . ' 2>nul');
    } else {
        @exec('rm -rf ' . escapeshellarg($dir));
    }
}

$V = '0.7.999';
$made = [];

// -- 1. THE ONE SHAPE THAT MUST PASS --------------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_add_good_candidate($dir, $V);
$refusals = fx_refusals($dir, $V);
p_ok($refusals === [],
    'THE CORRECT CANDIDATE IS REFUSED, so the rule rejects everything and the '
    . 'release path is still shut: ' . implode(' | ', $refusals));

// Every case below is a separate repository, so none of them can be passing
// because of state left behind by another.

// -- 2. A THIRD FILE IN THE DIFF ------------------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_write($dir, 'core/constants.php', fx_constants($V));
fx_write($dir, 'CHANGELOG.md', fx_changelog([$V . ' - FIXTURE STABLE', $V . 'D - FIXTURE']));
fx_write($dir, 'core/db.php', "<?php // slipped in\n");
fx_git($dir, ['add', '-A']);
fx_git($dir, ['commit', '--quiet', '-m', 'version bump and one more thing']);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate carrying a THIRD file is accepted - any change at all could '
    . 'ride into stable under cover of a version bump');

// -- 3. CHANGELOG MISSING FROM THE DIFF -----------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_write($dir, 'core/constants.php', fx_constants($V));
fx_git($dir, ['add', '-A']);
fx_git($dir, ['commit', '--quiet', '-m', 'version only, no notes']);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate with no changelog section is accepted - stable would ship with '
    . 'nothing written down about it');

// -- 4. ANOTHER LINE CHANGED IN constants.php -----------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
$sneaky = str_replace(
    "define('SNAPSMACK_THUMB_LONG_EDGE', 600);",
    "define('SNAPSMACK_THUMB_LONG_EDGE', 4000);",
    fx_constants($V));
fx_write($dir, 'core/constants.php', $sneaky);
fx_write($dir, 'CHANGELOG.md', fx_changelog([$V . ' - FIXTURE STABLE', $V . 'D - FIXTURE']));
fx_git($dir, ['add', '-A']);
fx_git($dir, ['commit', '--quiet', '-m', 'version bump plus a constant']);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate changing another line of constants.php is accepted - the two '
    . 'version lines are not actually being isolated');

// -- 5. THE WRONG VERSION STRING ------------------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_write($dir, 'core/constants.php', fx_constants('0.7.998'));
fx_write($dir, 'CHANGELOG.md', fx_changelog([$V . ' - FIXTURE STABLE', $V . 'D - FIXTURE']));
fx_git($dir, ['add', '-A']);
fx_git($dir, ['commit', '--quiet', '-m', 'wrong number']);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate whose version lines read a DIFFERENT version is accepted - the '
    . 'tag and the build would disagree about what they are');

// -- 6. THE D LEFT ON -----------------------------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_write($dir, 'CHANGELOG.md', fx_changelog([$V . ' - FIXTURE STABLE', $V . 'D - FIXTURE']));
fx_git($dir, ['add', '-A']);
fx_git($dir, ['commit', '--quiet', '-m', 'notes only, D still on']);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate still reading <version>D is accepted - a stable install would '
    . 'report itself as a development build, which is the whole thing this rule '
    . 'exists to prevent');

// -- 7. A REMOVED CHANGELOG LINE ------------------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_write($dir, 'core/constants.php', fx_constants($V));
// The D section's body is rewritten rather than added to.
fx_write($dir, 'CHANGELOG.md',
    "# Changelog\n\n## {$V} - FIXTURE STABLE\n\nsomething happened\n\n"
    . "## {$V}D - 2026-10-30 - FIXTURE\n\nTHIS REPLACED THE ORIGINAL LINE\n\n");
fx_git($dir, ['add', '-A']);
fx_git($dir, ['commit', '--quiet', '-m', 'version bump and history rewritten']);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate that REMOVES changelog lines is accepted - a promotion becomes '
    . 'a way to rewrite what earlier releases said they did');

// -- 8. TWO COMMITS ABOVE THE TAG -----------------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_add_good_candidate($dir, $V);
// The second commit must be PURELY ADDITIVE and touch only an allowed path,
// or this case gets caught by the changed-paths or removed-lines rules and
// proves nothing about the parent rule. An earlier version of this fixture
// regenerated CHANGELOG.md with different headings, which rewrote lines --
// so it passed with the parent check disabled, and the mutation run caught it.
fx_write($dir, 'CHANGELOG.md',
    (string) file_get_contents($dir . '/CHANGELOG.md') . "## Unreleased extra\n\nmore\n\n");
fx_git($dir, ['add', '-A']);
fx_git($dir, ['commit', '--quiet', '-m', 'and another']);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate TWO commits above the dev tag is accepted - the shape is no '
    . 'longer one commit holding only the version change');

// -- 9. dev HAS MOVED PAST THE TAG ----------------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_add_good_candidate($dir, $V);
// dev gains an unrelated commit of its own.
fx_git($dir, ['switch', '--quiet', 'dev']);
fx_write($dir, 'core/something-new.php', "<?php // landed after the tag\n");
fx_git($dir, ['add', '-A']);
fx_git($dir, ['commit', '--quiet', '-m', 'work after the cut']);
fx_git($dir, ['switch', '--quiet', 'candidate']);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate is accepted while dev has moved past the dev tag - stable would '
    . 'be cut from a commit that is no longer what the line is building on');

// -- 10. THE STABLE TAG ALREADY EXISTS ------------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_add_good_candidate($dir, $V);
fx_git($dir, ['tag', 'v' . $V]);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate is accepted when the stable tag already exists - a published '
    . 'release would be silently redefined');

// -- 11. NO DEV TAG AT ALL ------------------------------------------------

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_add_good_candidate($dir, $V);
fx_git($dir, ['tag', '-d', 'v' . $V . 'D']);
p_ok(fx_refusals($dir, $V) !== [],
    'a candidate is accepted with no v<version>D tag present - there is nothing '
    . 'establishing that this code was ever cut and tested as a dev build');

// -- 12. THE REFUSALS SAY WHICH RULE FAILED -------------------------------
//
// A gate that refuses without naming the reason sends the operator hunting,
// which is how release-flow.php's earlier clean check wasted tag after tag.

$dir = fx_repo_at_dev_tag($V);
$made[] = $dir;
fx_write($dir, 'core/constants.php', fx_constants($V));
fx_write($dir, 'CHANGELOG.md', fx_changelog([$V . ' - FIXTURE STABLE', $V . 'D - FIXTURE']));
fx_write($dir, 'index.php', "<?php // slipped in\n");
fx_git($dir, ['add', '-A']);
fx_git($dir, ['commit', '--quiet', '-m', 'with an extra file']);
$named = fx_refusals($dir, $V);
p_ok($named !== [] && str_contains(implode(' ', $named), 'index.php'),
    'the refusal does not name the offending path, so the operator cannot tell '
    . 'what to remove: ' . implode(' | ', $named));

foreach ($made as $d) {
    fx_cleanup($d);
}

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
printf("PASS: promote-stable accepts only the version commit (%d checks)\n", $checks);
// ===== SNAPSMACK EOF =====
