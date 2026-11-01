<?php
declare(strict_types=1);

/**
 * A promotion reads its release gate from the DEV TAG, never from HEAD.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * WHY THIS EXISTS. promote-stable called rf_require_release_gate() with no
 * argument, which asks about HEAD. HEAD during a promotion is the CANDIDATE
 * commit, and a candidate can never carry a gate note:
 *   - gate-note opens with rf_require_dev(), so it refuses off the dev branch
 *     and writes its note to dev's tip;
 *   - promote-stable refuses to run ON dev, by name;
 *   - stable-candidate-check.php requires Github/dev to still sit at the dev
 *     tag, so nothing may be added to dev in between.
 * Only dev's tip can receive a note, only a non-dev-tip commit can be promoted,
 * and dev may not move. No commit satisfied all three, so promote-stable could
 * not pass for ANY version -- the same shape of fault as SS-1077, left inside
 * the function SS-1077 fixed. Found 1 November 2026 while preparing the 0.7.974
 * promotion, before the command was handed to the owner.
 *
 * WHAT THIS FILE IS CAREFUL ABOUT. The case below is a THROWAWAY GIT
 * REPOSITORY with its own commits and its own tag. Nothing here reads this
 * repository's refs, for the reason the sibling test states: a check asserted
 * against our live dev tip passes by agreeing with today's state and keeps
 * passing after the rule is deleted.
 *
 * Run: php tests/promote-stable-reads-the-gate-on-the-dev-tag.php
 */

require_once dirname(__DIR__) . '/tools/_build/stable-candidate-check.php';

$checks = 0;
$failures = [];
function g_ok(bool $cond, string $why): void
{
    global $checks, $failures;
    $checks++;
    if (!$cond) {
        $failures[] = $why;
    }
}

function g_run(string $dir, array $args): string
{
    $cmd = 'cd ' . escapeshellarg($dir) . ' && '
        . implode(' ', array_map('escapeshellarg', array_merge(['git'], $args))) . ' 2>&1';
    exec($cmd, $lines, $code);
    return trim(implode("\n", $lines));
}

$dir = sys_get_temp_dir() . '/ss-gate-tag-' . bin2hex(random_bytes(6));
mkdir($dir, 0775, true);

try {
    g_run($dir, ['init', '-q', '-b', 'dev']);
    g_run($dir, ['config', 'user.email', 'test@example.invalid']);
    g_run($dir, ['config', 'user.name', 'Fixture']);

    // The tested commit, and the dev tag that marks it.
    file_put_contents($dir . '/constants.php', "<?php define('V', '1.2.3D');\n");
    g_run($dir, ['add', '-A']);
    g_run($dir, ['commit', '-q', '-m', 'the tested development commit']);
    $tagged = g_run($dir, ['rev-parse', 'HEAD']);
    g_run($dir, ['tag', 'v1.2.3D']);

    // The candidate, one commit above it. This is what HEAD is during a
    // promotion, and it is the commit that can never carry a gate note.
    file_put_contents($dir . '/constants.php', "<?php define('V', '1.2.3');\n");
    g_run($dir, ['add', '-A']);
    g_run($dir, ['commit', '-q', '-m', 'promote to stable 1.2.3']);
    $candidate = g_run($dir, ['rev-parse', 'HEAD']);

    g_ok(
        $tagged !== '' && $candidate !== '' && $tagged !== $candidate,
        'fixture is not usable: the tag target and HEAD must be different commits'
    );

    $cwd = getcwd();
    chdir($dir);
    try {
        $gate = rfc_promotion_gate_commit('1.2.3');

        // THE WHOLE POINT. Asking HEAD asks the candidate, which has no note.
        g_ok(
            $gate === $tagged,
            "the promotion gate commit is {$gate}; it must be the v1.2.3D target {$tagged}"
        );
        g_ok(
            $gate !== $candidate,
            'the promotion gate commit is HEAD (the candidate); a candidate can never '
                . 'carry a gate note, so promote-stable could never pass'
        );

        // No dev tag means nothing ever tested this, so the caller must refuse
        // rather than promote. '' is how it says so.
        g_ok(
            rfc_promotion_gate_commit('9.9.9') === '',
            'a version with no dev tag must yield no gate commit, so the promotion refuses'
        );
    } finally {
        chdir($cwd);
    }

    // A belt, and labelled as one: the behavioural checks above prove what
    // rfc_promotion_gate_commit() answers, not that promote-stable asks it.
    // This reads the call site so a revert to the no-argument form is caught.
    $flow = (string) file_get_contents(dirname(__DIR__) . '/tools/release-flow.php');
    g_ok(
        strpos($flow, 'rf_require_release_gate($gate_commit)') !== false,
        'promote-stable no longer passes a gate commit to rf_require_release_gate()'
    );
    g_ok(
        strpos($flow, 'rfc_promotion_gate_commit($version)') !== false,
        'promote-stable no longer derives its gate commit from the dev tag'
    );
    g_ok(
        strpos($flow, 'function rf_require_release_gate(?string $commit = null)') !== false,
        'rf_require_release_gate() no longer accepts a commit, so HEAD is forced again'
    );
} finally {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        @chmod($entry->getPathname(), 0666);
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
}

if ($failures) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS: a promotion reads its gate from the dev tag, not from HEAD ({$checks} checks)\n";

// ===== SNAPSMACK EOF =====
