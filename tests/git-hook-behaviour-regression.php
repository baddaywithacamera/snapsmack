<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The hooks are run, not read.
 *
 * OPAUDIT 026. A gate nobody has watched fail is not a gate — the pre-commit
 * hook sat in this repository for months, correct and unarmed, and the suite
 * had nothing to say about it. So this file does not grep the hook source. It
 * builds a throwaway git repository in the system temp directory, points it at
 * .githooks/, and makes real commits and real pushes that should be refused.
 *
 * Covered here:
 *
 *   commit-msg (step 3)  a commit saying "restore" with no Restores: trailer,
 *                        with a trailer naming a commit that does not exist,
 *                        and with one naming a commit that touched none of the
 *                        same files. All three refused. An honest rebuild and
 *                        a supported restore both go through.
 *
 *   pre-push (step 1)    a release tag whose commit has no release-gate note,
 *                        and one whose note does not pass. Both refused. A
 *                        branch push and a tag on a commit with a passing note
 *                        both go through.
 *
 * Nothing here touches the real repository: every commit, tag and note is made
 * inside the temporary directory and the directory is deleted afterwards.
 *
 * What this does NOT cover: the pre-commit hook, which has its own three
 * ratchet regressions, and whether the gate is switched on at all, which is
 * tests/git-hooks-installed-regression.php.
 */

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

// --- a throwaway repository ------------------------------------------------

$tmp = sys_get_temp_dir() . '/snapsmack-hook-test-' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);

/** Delete a directory tree, including the read-only files git leaves behind. */
function hook_rmtree(string $path): void
{
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        @chmod($item->getPathname(), 0777);
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

register_shutdown_function(static function () use ($tmp): void { hook_rmtree($tmp); });

/** Run a command inside the throwaway repository. Returns [code, output]. */
function hook_run(array $args, string $cwd, array $env = []): array
{
    $cmd = '';
    foreach ($args as $arg) $cmd .= ($cmd === '' ? '' : ' ') . escapeshellarg($arg);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // Inherit the real environment so git, php and python stay findable.
    $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env === [] ? null : ($env + getenv()));
    if (!is_resource($process)) return [127, 'could not start: ' . $cmd];
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), trim($out . "\n" . $err)];
}

function hook_git(array $args, string $cwd): array
{
    return hook_run(array_merge(['git'], $args), $cwd);
}

hook_git(['init', '--quiet', '--initial-branch=dev'], $tmp);
hook_git(['config', 'user.name', 'Hook Test'], $tmp);
hook_git(['config', 'user.email', 'hook@example.invalid'], $tmp);
hook_git(['config', 'commit.gpgsign', 'false'], $tmp);
hook_git(['config', 'core.hooksPath', $root . '/.githooks'], $tmp);

/** Write a file inside the throwaway repository and stage it. */
function hook_stage(string $tmp, string $relative, string $contents): void
{
    $path = $tmp . '/' . $relative;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    file_put_contents($path, $contents);
    hook_git(['add', $relative], $tmp);
}

/** Commit with a message, returning [code, output]. */
function hook_commit(string $tmp, string $message): array
{
    $file = $tmp . '/.git/HOOK_TEST_MSG';
    file_put_contents($file, $message);
    return hook_git(['commit', '--quiet', '--file', $file], $tmp);
}

// Two unrelated files, so "touched none of the same files" is testable.
hook_stage($tmp, 'alpha.txt', "one\n");
hook_stage($tmp, 'beta.txt', "one\n");
[$code] = hook_commit($tmp, "Add alpha and beta\n");
$check($code === 0, 'the throwaway repository could not make its first commit');
[, $base] = hook_git(['rev-parse', 'HEAD'], $tmp);
$base = trim($base);

hook_stage($tmp, 'beta.txt', "two\n");
hook_commit($tmp, "Change beta only\n");
[, $betaOnly] = hook_git(['rev-parse', 'HEAD'], $tmp);
$betaOnly = trim($betaOnly);

/* ── commit-msg: step 3 ───────────────────────────────────────────────────
 * The word "restore" has to be backed by a commit that can be looked at. */

hook_stage($tmp, 'alpha.txt', "three\n");
[$code, $out] = hook_commit($tmp, "Restore the alpha heading\n");
$check($code !== 0, 'commit-msg let an unsupported "Restore" through with no trailer at all');
$check(str_contains($out, 'Restores:'),
    'the refusal does not say what to add; it must name the Restores: trailer');

[$code] = hook_commit($tmp, "Restore the alpha heading\n\nRestores: 0000000000000000000000000000000000000000\n");
$check($code !== 0, 'commit-msg accepted a Restores: trailer naming a commit that does not exist');

[$code, $out] = hook_commit($tmp, "Restore the alpha heading\n\nRestores: {$betaOnly}\n");
$check($code !== 0,
    'commit-msg accepted a Restores: trailer naming a commit that touched none of the same files'
    . ' — exactly the b0e1eb09 case it exists to catch');

[$code, $out] = hook_commit($tmp, "Restore the alpha heading\n\nRestores: {$base}\n");
$check($code === 0, 'commit-msg refused a supported restore: ' . $out);

hook_stage($tmp, 'alpha.txt', "four\n");
[$code, $out] = hook_commit($tmp, "Rebuild the alpha heading from scratch\n");
$check($code === 0, 'commit-msg refused an honest rebuild, which claims nothing: ' . $out);

hook_stage($tmp, 'alpha.txt', "five\n");
[$code, $out] = hook_commit($tmp, "Bring back the alpha heading\n");
$check($code !== 0, 'commit-msg only watches the word "restore"; "bring back" is the same claim');

[$code] = hook_commit($tmp, "Bring back the alpha heading\n\nRestores: {$base}\n");
$check($code === 0, 'commit-msg refused a supported "bring back"');

/* ── pre-push: step 1 ─────────────────────────────────────────────────────
 * A release tag may not leave the machine without a passing gate note.
 *
 * The hook is driven directly with the ref list git would hand it, because a
 * real push would need a real remote. SNAPSMACK_HOOK_SKIP_LFS keeps the Git
 * LFS handoff out of the test; it cannot skip the gate, only the step after
 * it, and the refusal cases never reach either. */

$hook = $root . '/.githooks/pre-push';
$python = null;
foreach (['python3', 'python'] as $candidate) {
    [$code] = hook_run([$candidate, '--version'], $tmp);
    if ($code === 0) { $python = $candidate; break; }
}

/**
 * Attach a release-gate note, written from a file.
 *
 * Not -m: a JSON string carrying braces and quotes does not survive the
 * Windows command line intact, and a note that arrives mangled fails the gate
 * for the wrong reason, which would make this test pass while proving nothing.
 */
function hook_note(string $tmp, string $commit, string $json): void
{
    $file = $tmp . '/.git/HOOK_TEST_NOTE';
    file_put_contents($file, $json);
    hook_git(['notes', '--ref=refs/notes/release-gates', 'add', '-f', '-F', $file, $commit], $tmp);
}

/** Feed the hook a ref list exactly as git writes it on stdin. */
function hook_prepush(string $python, string $hook, string $cwd, string $refs): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($hook) . ' Github https://example.invalid/x.git';
    $process = proc_open($cmd, $descriptors, $pipes, $cwd, ['SNAPSMACK_HOOK_SKIP_LFS' => '1'] + getenv());
    if (!is_resource($process)) return [127, 'could not start the hook'];
    fwrite($pipes[0], $refs);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), trim($out . "\n" . $err)];
}

if ($python === null) {
    $failures[] = 'no python3 on PATH, so the pre-push gate cannot run at all on this machine';
} else {
    // The gate asks tools/release-flow.php, which reads the skin inventory
    // next to it. Both are copied in so the throwaway repository can answer.
    mkdir($tmp . '/tools', 0775, true);
    copy($root . '/tools/release-flow.php', $tmp . '/tools/release-flow.php');
    mkdir($tmp . '/skins/testskin', 0775, true);
    file_put_contents($tmp . '/skins/testskin/manifest.json', json_encode(['name' => 'testskin']));

    [, $head] = hook_git(['rev-parse', 'HEAD'], $tmp);
    $head = trim($head);
    $zero = str_repeat('0', 40);

    $refs = static fn (string $ref, string $sha): string => "{$ref} {$sha} {$ref} {$zero}\n";

    [$code, $out] = hook_prepush($python, $hook, $tmp, $refs('refs/tags/v0.7.946D', $head));
    $check($code !== 0, 'pre-push let a release tag out over a commit with no release-gate note'
        . ' — this is exactly how v0.7.946D, v0.7.947D and v0.7.948D were cut');
    $check(str_contains($out, 'v0.7.946D'), 'the refusal does not name the tag it refused');

    [$code] = hook_prepush($python, $hook, $tmp, $refs('refs/heads/dev', $head));
    $check($code === 0, 'pre-push blocked an ordinary branch push; it must gate tags, not work');

    [$code] = hook_prepush($python, $hook, $tmp, "refs/tags/v0.7.946D {$zero} refs/tags/v0.7.946D {$head}\n");
    $check($code === 0, 'pre-push blocked a tag deletion, which releases nothing');

    // A note that exists but does not pass.
    $failing = json_encode(['commit' => $head, 'security' => 'fail']);
    hook_note($tmp, $head, $failing);
    [$code, $out] = hook_prepush($python, $hook, $tmp, $refs('refs/tags/v0.7.946D', $head));
    $check($code !== 0, 'pre-push accepted a release-gate note that does not pass');
    $check(str_contains($out, 'security'),
        'the note was refused for the wrong reason — it must be read as JSON and fail on its'
        . ' security field, not fail to parse: ' . $out);

    // A note that does pass, built the way the gate asks for it.
    $passing = json_encode([
        'commit' => $head,
        'security' => 'pass',
        'parity' => 'pass',
        'skin_render_parity' => 'pass',
        'authority_review' => 'approved',
        'browser_regressions' => 'pass',
        'skin_count' => 1,
        'skin_inventory_sha256' => hash('sha256', 'testskin'),
        'skin_render_changes' => [],
    ]);
    foreach (['missing', 'fail'] as $browserResult) {
        $badBrowser = json_decode($passing, true);
        if ($browserResult === 'missing') unset($badBrowser['browser_regressions']);
        else $badBrowser['browser_regressions'] = 'fail';
        hook_note($tmp, $head, json_encode($badBrowser));
        [$code, $out] = hook_prepush($python, $hook, $tmp, $refs('refs/tags/v0.7.946D', $head));
        $check($code !== 0 && str_contains($out, 'browser_regressions'),
            'pre-push accepted missing/failed browser evidence or refused for the wrong reason: ' . $out);
    }
    hook_note($tmp, $head, $passing);
    [$code, $out] = hook_prepush($python, $hook, $tmp, $refs('refs/tags/v0.7.946D', $head));
    $check($code === 0, 'pre-push refused a tag whose commit carries a passing gate note: ' . $out);
}

if ($failures !== []) {
    fwrite(STDERR, "Git hooks did not behave (" . count($failures) . "):\n\n");
    foreach ($failures as $failure) fwrite(STDERR, "  - {$failure}\n");
    fwrite(STDERR, "\n");
    exit(1);
}

echo "Git hooks behave: commit-msg refuses 4 unsupported restore claims and passes 3 honest messages;\n";
echo "pre-push refuses 2 ungated release tags and passes branches, deletions and a gated tag.\n";
// ===== SNAPSMACK EOF =====
