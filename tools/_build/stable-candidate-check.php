<?php
declare(strict_types=1);

/**
 * SNAPSMACK - what a stable candidate commit is allowed to be.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1077. promote-stable could not run for ANY version. tag-dev requires
 * SNAPSMACK_VERSION_SHORT to read <version>D at the commit it tags;
 * promote-stable required it to read <version> at that same commit. One
 * constant cannot hold both values, so nothing satisfied both and the stable
 * channel sat on 0.7.670 from 9 September to 30 October 2026 with no way off.
 *
 * The fix is not to let the D through into stable -- the suffix has to keep
 * meaning development, or a stable install reports itself as a dev build. It
 * is to accept a CANDIDATE COMMIT: exactly one commit above the dev tag, whose
 * entire difference from that tag is the two version lines and an added
 * changelog section. Everything else refuses.
 *
 * This file is deliberately a set of PURE FUNCTIONS with no side effects: no
 * chdir, no exit, no output. It returns the reasons a candidate is refused and
 * lets the caller decide what to do with them. That is what makes it testable
 * against throwaway repositories in
 * tests/promote-stable-accepts-only-the-version-commit.php, which is the only
 * way to prove a gate refuses -- a test run against this repository's own refs
 * would pass by agreeing with today's state and keep passing after the rule
 * was deleted.
 *
 * Every function reads git in the CURRENT WORKING DIRECTORY and reads file
 * contents out of the commit rather than off disk. What is in the worktree is
 * not what gets tagged.
 */

/** Run git in the current directory. Returns [exit code, trimmed output]. */
function rfc_git(array $args): array {
    $cmd = implode(' ', array_map('escapeshellarg', array_merge(['git'], $args))) . ' 2>&1';
    exec($cmd, $lines, $code);
    return [(int) $code, trim(implode("\n", $lines))];
}

/** A ref's commit sha, or '' when the ref does not resolve. */
function rfc_rev(string $ref): string {
    [$code, $out] = rfc_git(['rev-parse', '--verify', '--quiet', $ref . '^{commit}']);
    return $code === 0 ? $out : '';
}

/** A tag's commit sha, or '' when the tag does not exist. */
function rfc_tag_target(string $tag): string {
    return rfc_rev('refs/tags/' . $tag);
}

/** A commit's parent shas, in order. */
function rfc_parents(string $ref): array {
    [$code, $out] = rfc_git(['rev-list', '--parents', '-n', '1', $ref]);
    if ($code !== 0 || $out === '') return [];
    $parts = preg_split('/\s+/', $out) ?: [];
    array_shift($parts);                       // the commit itself
    return array_values(array_filter($parts));
}

/** A file's contents at a commit, or null when the path is not in it. */
function rfc_show(string $ref, string $path): ?string {
    [$code, $out] = rfc_git(['show', $ref . ':' . $path]);
    return $code === 0 ? $out : null;
}

/**
 * The changed lines for one path, as ['added' => [...], 'removed' => [...]].
 * --unified=0 so only real changes appear; context lines would otherwise be
 * counted as changes by a naive reader of the diff.
 */
function rfc_changed_lines(string $from, string $to, string $path): array {
    // THE MARKERS ARE RENAMED SO THE HEADERS CANNOT BE CONFUSED WITH CONTENT.
    //
    // The first version of this read the ordinary diff and skipped any line
    // starting '---' or '+++' as a header. An independent read pointed out
    // that this cuts both ways, and it was right: a deleted line whose own
    // text begins with two dashes becomes '---...' once the removal marker is
    // prepended, so it was being thrown away as a header. A Markdown thematic
    // break -- a bare '---', which any changelog may contain -- could
    // therefore be deleted with the additive-only rule none the wiser.
    //
    // git can emit its own indicators for old, new and context lines, which
    // removes the ambiguity entirely. Headers keep their '---'/'+++' and
    // content is marked O and N, so a line of content is identified by its
    // marker and never by guessing which dashes are structural. Everything
    // else git prints in a diff header -- 'diff --git', 'index', 'old mode',
    // 'new file mode', '@@' -- either starts with a different character or is
    // lowercase, so none of them can be mistaken for content.
    [$code, $out] = rfc_git([
        'diff', '--unified=0', '--no-color', '--no-ext-diff',
        '--output-indicator-old=O',
        '--output-indicator-new=N',
        '--output-indicator-context=C',
        $from, $to, '--', $path,
    ]);
    $added = [];
    $removed = [];
    if ($code !== 0) return ['added' => $added, 'removed' => $removed];
    foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
        if ($line === '') continue;
        if ($line[0] === 'O') $removed[] = substr($line, 1);
        elseif ($line[0] === 'N') $added[] = substr($line, 1);
    }
    return ['added' => $added, 'removed' => $removed];
}

/** Paths that differ between two commits. */
function rfc_changed_paths(string $from, string $to): array {
    [$code, $out] = rfc_git(['diff', '--name-only', '--no-ext-diff', $from, $to]);
    if ($code !== 0 || $out === '') return [];
    $paths = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $out) ?: [])));
    sort($paths, SORT_STRING);
    return $paths;
}

/**
 * Read a version define line: ['SNAPSMACK_VERSION'|'SNAPSMACK_VERSION_SHORT',
 * value] or null for anything else.
 *
 * ANCHORED AT BOTH ENDS, DELIBERATELY. The first version matched the define
 * anywhere in the line and stopped reading at the closing bracket, so
 *
 *     define('SNAPSMACK_VERSION_SHORT', '0.7.974'); @unlink('/etc/passwd');
 *
 * was accepted as a clean version line with a passenger attached. An
 * independent read flagged the anchoring risk and a fixture proved it. The
 * WHOLE line must now be nothing but the define and its semicolon. Quote style
 * is still not pinned, because the real file mixes single and double quotes.
 */
function rfc_read_version_define(string $line): ?array {
    $pattern = '/^\s*define\(\s*([\'"])(SNAPSMACK_VERSION|SNAPSMACK_VERSION_SHORT)\1\s*,'
             . '\s*([\'"])([^\'"]*)\3\s*\)\s*;\s*$/';
    if (preg_match($pattern, $line, $m)) {
        return [$m[2], $m[4]];
    }
    return null;
}

/**
 * Every reason this commit may not be promoted to stable <version>.
 * An empty array means it may.
 *
 * $devRef lets a fixture repository stand in for the real remote while this is
 * being tested, and $isFixture is how a caller says so OUT LOUD.
 *
 * THE SEAM IS ENFORCED, NOT DOCUMENTED. The first version carried a comment
 * saying only release-flow.php may pass a different ref. An independent read
 * pointed out that a comment enforces nothing and a later caller could weaken
 * the guarantee silently, which is true. Now a caller that passes anything but
 * the production ref without declaring itself a fixture is refused by the rule
 * itself.
 */
function rfc_stable_candidate_refusals(
    string $version,
    string $head = 'HEAD',
    string $devRef = 'refs/remotes/Github/dev',
    bool $isFixture = false
): array {
    $refusals = [];
    $devTag = 'v' . $version . 'D';
    $stableTag = 'v' . $version;

    if (!$isFixture && $devRef !== 'refs/remotes/Github/dev') {
        return ["this rule may only be run against refs/remotes/Github/dev; "
            . "'{$devRef}' was passed without declaring a test fixture"];
    }

    $headSha = rfc_rev($head);
    if ($headSha === '') {
        return ["{$head} does not resolve to a commit"];
    }

    // -- the stable tag must not already exist ----------------------------
    if (rfc_tag_target($stableTag) !== '') {
        $refusals[] = "{$stableTag} already exists; a published release is never redefined";
    }

    // -- the dev tag must exist, and be this commit's only parent ---------
    $tagSha = rfc_tag_target($devTag);
    if ($tagSha === '') {
        $refusals[] = "{$devTag} does not exist, so nothing establishes that this code "
            . 'was ever cut and tested as a development build. EVERY OTHER CHECK WAS '
            . 'SKIPPED: without the tag there is nothing to compare against, so any '
            . 'further reason would be guesswork. Expect more once the tag is there.';
        // Said out loud above, at an independent reviewer's request: a gate that
        // stops early must say that it stopped, or its one reason reads as the
        // only thing wrong.
        return $refusals;
    }

    $parents = rfc_parents($headSha);
    if (count($parents) !== 1) {
        $refusals[] = count($parents) === 0
            ? 'the candidate has no parent commit'
            : 'the candidate is a merge commit (' . count($parents) . ' parents); a stable '
              . 'candidate is one commit holding one change';
    } elseif ($parents[0] !== $tagSha) {
        $refusals[] = "the candidate's parent is {$parents[0]}, not {$devTag} ({$tagSha}); "
            . 'a stable candidate sits exactly one commit above the dev tag';
    }

    // -- dev must still be AT the tag -------------------------------------
    //
    // This replaces the old "HEAD must equal Github/dev" check. The candidate
    // is one commit ahead of dev by construction, so that check could never
    // pass; what it was protecting -- nothing else has landed since the cut --
    // is exactly this.
    $devSha = rfc_rev($devRef);
    if ($devSha === '') {
        $refusals[] = "{$devRef} does not resolve; fetch before promoting";
    } elseif ($devSha !== $tagSha) {
        $refusals[] = "{$devRef} is at {$devSha}, not at {$devTag} ({$tagSha}); work has "
            . 'landed on the development line since the cut, so this is no longer the '
            . 'commit the line is building on';
    }

    // -- exactly two paths may differ -------------------------------------
    $paths = rfc_changed_paths($tagSha, $headSha);
    $allowed = ['CHANGELOG.md', 'core/constants.php'];
    $extra = array_values(array_diff($paths, $allowed));
    $absent = array_values(array_diff($allowed, $paths));
    if ($extra) {
        $refusals[] = 'the candidate changes more than the version: ' . implode(', ', $extra);
    }
    if ($absent) {
        $refusals[] = 'the candidate does not change ' . implode(' or ', $absent)
            . '; a stable release needs both the version lines and a section saying what it is';
    }

    // -- in constants.php, ONLY the two version lines ---------------------
    if (!in_array('core/constants.php', $absent, true)) {
        $c = rfc_changed_lines($tagSha, $headSha, 'core/constants.php');
        $removedNames = [];
        foreach ($c['removed'] as $line) {
            $read = rfc_read_version_define($line);
            if ($read === null) {
                $refusals[] = 'core/constants.php changes a line that is not a version line: '
                    . trim($line);
            } else {
                $removedNames[] = $read[0];
            }
        }
        $addedValues = [];
        foreach ($c['added'] as $line) {
            $read = rfc_read_version_define($line);
            if ($read === null) {
                $refusals[] = 'core/constants.php adds a line that is not a version line, '
                    . 'or has something else on the same line: ' . trim($line);
            } else {
                $addedValues[$read[0]] = $read[1];
            }
        }
        sort($removedNames, SORT_STRING);
        if ($removedNames !== ['SNAPSMACK_VERSION', 'SNAPSMACK_VERSION_SHORT']) {
            $refusals[] = 'core/constants.php does not replace exactly both version lines '
                . '(replaced: ' . (implode(', ', $removedNames) ?: 'none') . ')';
        }
        $wanted = [
            'SNAPSMACK_VERSION' => 'Alpha ' . $version,
            'SNAPSMACK_VERSION_SHORT' => $version,
        ];
        foreach ($wanted as $name => $value) {
            if (!array_key_exists($name, $addedValues)) {
                $refusals[] = "core/constants.php does not set {$name}";
            } elseif ($addedValues[$name] !== $value) {
                $refusals[] = "{$name} reads '" . (string) $addedValues[$name]
                    . "'; stable {$version} requires '{$value}'";
            }
        }
    }

    // -- the changelog may only GAIN lines --------------------------------
    //
    // Without this a promotion becomes a way to rewrite what earlier releases
    // said they did, under cover of a version bump.
    if (!in_array('CHANGELOG.md', $absent, true)) {
        $cl = rfc_changed_lines($tagSha, $headSha, 'CHANGELOG.md');
        if ($cl['removed']) {
            $refusals[] = 'the candidate removes ' . count($cl['removed'])
                . ' line(s) from CHANGELOG.md; a promotion adds a section, it does not '
                . 'rewrite history';
        }
        if (!$cl['added']) {
            $refusals[] = 'the candidate adds nothing to CHANGELOG.md';
        }
    }

    // -- the committed source and notes must agree with the version -------
    $constants = rfc_show($headSha, 'core/constants.php');
    if ($constants === null) {
        $refusals[] = 'core/constants.php is not present in the candidate commit';
    } elseif (!preg_match("/SNAPSMACK_VERSION_SHORT['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $constants, $m)) {
        $refusals[] = 'SNAPSMACK_VERSION_SHORT cannot be read from the candidate commit';
    } elseif ($m[1] !== $version) {
        $refusals[] = "the candidate's source version reads {$m[1]}; stable {$version} "
            . 'requires the version without the D';
    }

    $changelog = rfc_show($headSha, 'CHANGELOG.md');
    if ($changelog === null) {
        $refusals[] = 'CHANGELOG.md is not present in the candidate commit';
    } elseif (!preg_match('/^## ' . preg_quote($version, '/') . '\s/m', $changelog)) {
        $refusals[] = "CHANGELOG.md in the candidate has no versioned {$version} section";
    }

    return array_values(array_unique($refusals));
}
// ===== SNAPSMACK EOF =====
