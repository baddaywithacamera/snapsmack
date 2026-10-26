<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Turn the commit and push gates on.
 *
 *     php tools/install-hooks.php
 *
 * Why this file exists. `.githooks/pre-commit` has blocked null bytes, PHP
 * syntax errors and three skin ratchet regressions since it was written. Its
 * own docstring says "Install once: git config core.hooksPath .githooks".
 * Nobody ever ran that line. Measured 25 October 2026: core.hooksPath was
 * unset in the main checkout, unset globally, unset in every worktree, and
 * .git/hooks held no pre-commit. The gate had never run once.
 *
 * A gate whose installation depends on somebody remembering a line in a
 * docstring is not a gate. So: one command here, and
 * tests/git-hooks-installed-regression.php fails while the gate is off, which
 * means the test suite itself reports it instead of it going unnoticed for
 * another few months.
 *
 * core.hooksPath is written to the repository config, not the global one, and
 * not to any file outside this repository. It covers the main checkout and
 * every worktree, so it is run once per clone and not once per branch.
 *
 * Setting core.hooksPath also stops git looking in .git/hooks, where git-lfs
 * installs post-checkout, post-commit, post-merge and pre-push. Copies of all
 * four live in .githooks/ for that reason, and this script refuses to finish
 * if any of them is missing.
 */

$root = dirname(__DIR__);
chdir($root);

/** Run a git command and return [exit code, trimmed output]. */
function ih_git(array $args): array
{
    $cmd = 'git';
    foreach ($args as $arg) $cmd .= ' ' . escapeshellarg($arg);
    exec($cmd . ' 2>&1', $lines, $code);
    return [$code, trim(implode("\n", $lines))];
}

function ih_fail(string $message): never
{
    fwrite(STDERR, "\nREFUSED: {$message}\n\n");
    exit(1);
}

[$code] = ih_git(['rev-parse', '--git-dir']);
if ($code !== 0) ih_fail('this is not a git repository.');

// The hooks git-lfs owns. If one of these is not mirrored into .githooks/,
// switching hooksPath would silently disable it.
$lfs_hooks = ['post-checkout', 'post-commit', 'post-merge', 'pre-push'];
$missing = [];
foreach ($lfs_hooks as $hook) {
    $path = $root . '/.githooks/' . $hook;
    if (!is_file($path)) { $missing[] = $hook; continue; }
    if (!str_contains((string)file_get_contents($path), 'git lfs')) $missing[] = $hook;
}
if ($missing !== []) {
    ih_fail('.githooks/ does not carry the Git LFS hook(s): ' . implode(', ', $missing)
        . ". Turning core.hooksPath on now would disable Git LFS. Copy them from .git/hooks first.");
}

$hooks = glob($root . '/.githooks/*') ?: [];
if ($hooks === []) ih_fail('.githooks/ is empty.');

[$code, $current] = ih_git(['config', '--local', 'core.hooksPath']);
$already = ($code === 0 && $current === '.githooks');

if ($already) {
    echo "Already on: core.hooksPath = .githooks\n";
} else {
    if ($code === 0 && $current !== '') {
        echo "core.hooksPath was {$current}; changing it to .githooks\n";
    }
    [$code, $out] = ih_git(['config', '--local', 'core.hooksPath', '.githooks']);
    if ($code !== 0) ih_fail('git config failed: ' . $out);
    echo "Set: core.hooksPath = .githooks\n";
}

// chmod is a no-op on Windows and harmless everywhere else.
foreach ($hooks as $hook) {
    if (is_file($hook)) @chmod($hook, 0775);
}

echo "\nHooks now live:\n";
foreach ($hooks as $hook) {
    echo '  ' . basename($hook) . "\n";
}
echo "\nThis covers the main checkout and every worktree of this clone.\n";
echo "Run it again in any other clone of SnapSmack.\n";

exit(0);
// ===== SNAPSMACK EOF =====
