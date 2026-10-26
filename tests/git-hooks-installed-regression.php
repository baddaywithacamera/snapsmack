<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The commit and push gates are switched on.
 *
 * OPAUDIT 026, step 0. `.githooks/pre-commit` blocks null bytes, PHP syntax
 * errors and three skin ratchet regressions. It had never run. Its docstring
 * says "Install once: git config core.hooksPath .githooks" and measurement on
 * 25 October 2026 found that line had never been typed: core.hooksPath unset
 * in the main checkout, unset globally, unset in every worktree, and no
 * pre-commit in .git/hooks. Months of commits went past a gate that was off.
 *
 * Nothing in the repository could tell, because nothing asked. This file
 * asks. While the gate is off the suite is red and says the one command:
 *
 *     php tools/install-hooks.php
 *
 * Also checked here: the four hooks git-lfs owns are mirrored into .githooks/.
 * Setting core.hooksPath stops git reading .git/hooks, so turning this gate on
 * without those copies would turn Git LFS off and nobody would notice until a
 * binary file came back as a text pointer.
 *
 * What this does NOT cover. It proves the hooks are wired up and present, not
 * that each hook is correct; every hook has its own regression beside this
 * one. It cannot see another clone on another machine — core.hooksPath is per
 * clone, so each clone runs the installer once.
 */

$root = dirname(__DIR__);
$failures = [];

/** Run a git command from the repository root. Returns [code, output]. */
function hooks_git(array $args): array
{
    $cmd = 'git -C ' . escapeshellarg(dirname(__DIR__));
    foreach ($args as $arg) $cmd .= ' ' . escapeshellarg($arg);
    exec($cmd . ' 2>&1', $lines, $code);
    return [$code, trim(implode("\n", $lines))];
}

[$code] = hooks_git(['rev-parse', '--git-dir']);
if ($code !== 0) {
    // An unpacked release tarball is not a checkout and has no hooks to arm.
    echo "Not a git checkout; there are no hooks to install here.\n";
    // ===== SNAPSMACK EOF =====
    return;
}

/* ── The gate is on ───────────────────────────────────────────────────────
 * core.hooksPath lives in the repository config, so one setting covers the
 * main checkout and every worktree of this clone. */
[$code, $configured] = hooks_git(['config', '--local', 'core.hooksPath']);
if ($code !== 0 || $configured === '') {
    $failures[] = "core.hooksPath is not set, so .githooks/ never runs:\n"
        . "      every commit since the hook was written went past an unarmed gate.\n"
        . "      Fix with one command:  php tools/install-hooks.php";
} elseif ($configured !== '.githooks') {
    $failures[] = "core.hooksPath is '{$configured}', not '.githooks', so the repository's own"
        . " hooks do not run.\n      Fix with one command:  php tools/install-hooks.php";
}

/* ── Every hook the repository relies on is present ────────────────────── */
foreach (['pre-commit', 'commit-msg', 'pre-push'] as $hook) {
    if (!is_file($root . '/.githooks/' . $hook)) {
        $failures[] = ".githooks/{$hook} is missing; the gate it carries cannot run.";
    }
}

/* ── Git LFS still runs ────────────────────────────────────────────────────
 * git-lfs installs these four into .git/hooks, which core.hooksPath bypasses.
 * Each must be mirrored into .githooks/ and must still call git lfs. */
foreach (['post-checkout', 'post-commit', 'post-merge', 'pre-push'] as $hook) {
    $path = $root . '/.githooks/' . $hook;
    if (!is_file($path)) {
        $failures[] = ".githooks/{$hook} is missing; core.hooksPath would disable the Git LFS {$hook} hook.";
        continue;
    }
    // Matches the shell form  git lfs post-commit  and the python form
    // ['git', 'lfs', 'pre-push'] the gated pre-push hook uses.
    $pattern = '/\bgit[\'",\s]+lfs[\'",\s]+' . preg_quote($hook, '/') . '/';
    if (!preg_match($pattern, (string)file_get_contents($path))) {
        $failures[] = ".githooks/{$hook} no longer calls 'git lfs {$hook}'; Git LFS is disabled for that step.";
    }
}

/* ── The installer exists and is the thing the message names ───────────── */
if (!is_file($root . '/tools/install-hooks.php')) {
    $failures[] = 'tools/install-hooks.php is missing; the failure message above names a command that does not exist.';
} elseif (!str_contains((string)file_get_contents($root . '/tools/install-hooks.php'), 'core.hooksPath')) {
    $failures[] = 'tools/install-hooks.php no longer sets core.hooksPath.';
}

if ($failures !== []) {
    fwrite(STDERR, "Git hook gate is not armed (" . count($failures) . "):\n\n");
    foreach ($failures as $failure) fwrite(STDERR, "  - {$failure}\n");
    fwrite(STDERR, "\n");
    exit(1);
}

echo "Git hooks armed: core.hooksPath = .githooks, pre-commit, commit-msg and pre-push present, Git LFS preserved.\n";
// ===== SNAPSMACK EOF =====
