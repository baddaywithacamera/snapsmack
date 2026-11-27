<?php
declare(strict_types=1);

/**
 * SNAPSMACK - the version series, and the gate that keeps everything in it
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * WHY THIS EXISTS. SnapSmack is in alpha and every version is meant to be
 * 0.7.x. Several skins drifted to 1.x and 2.x anyway -- IMPACT PRINTER reached
 * 2.1.30, RATIONAL GEO 2.1.46 -- and 19 of the 30 skins are at 0.8 or above.
 * Sean asked for it to stop and it carried on, so asking is not the mechanism:
 * the tools refuse it now. His words, relayed by the PM on 2026-11-26: every
 * skin, desktop tool and core version is 0.7.x while SnapSmack is in alpha;
 * nothing at 0.8, 0.9 or 1.x; "stable" and "beta" are STATUS LABELS and never
 * version numbers.
 *
 * TWO RULES, both enforced here and nowhere else, so there is one spelling:
 *   1. SERIES. the version must be UNDER 0.8 -- major 0, minor at most 7.
 *      Not "exactly 0.7": Sean's mapping says the skins already below 0.8 keep
 *      their numbers and names TILEZ, GLIDE, SCROLL, ONYX and TELEGRAM among
 *      them, so 0.1.x and 0.2.x are correct as they stand. Demanding 0.7 would
 *      force a re-baseline he explicitly said not to do. His one condition is
 *      the invariant: everything under 1.0, nothing at 0.8 or above.
 *   2. STEP. a published version must be exactly the last published one plus
 *      one on the patch, with the series unchanged. No jumps, no re-publishing
 *      the same number. TILEZ 0.2.79 publishes 0.2.80, not 0.7.80.
 *
 * AND THE MAPPING, which is a one-time move Sean delegated with one condition,
 * everything stays under 1.0:
 *   - at 0.8.0 or above  -> 0.7.(its current patch + 1)
 *   - already below 0.8  -> unchanged; its next publish is just +1
 * Worked examples he gave: IMPACT PRINTER 2.1.30 -> 0.7.31, RATIONAL GEO
 * 2.1.46 -> 0.7.47, PHOTOGRAM 2.0.33 -> 0.7.34, 50 SHADES 1.7.9 -> 0.7.10,
 * ALFRED 1.0.30 -> 0.7.31, THE GRID 1.3.64 -> 0.7.65.
 *
 * A re-baselined release carries version_epoch 1 so that a site holding the
 * old 2.1.30 treats 0.7.31 as newer -- see snap_version_compare() in
 * core/constants.php. Without the epoch every one of those sites would refuse
 * the update for ever and nobody would be told.
 *
 * NOTHING HERE REPUBLISHES ANYTHING. These are rules and a table; the
 * packager, updater and installer call them.
 */

/** The only series anything may be published on while SnapSmack is in alpha. */
const SNAP_VERSION_SERIES = '0.7';

/** The epoch a re-baselined release carries. Raised only when Sean re-bases. */
const SNAP_VERSION_EPOCH = 1;

/**
 * Split a version into [major, minor, patch], or null if it is not three
 * numbers. A letter-suffixed legacy version (0.7.9p) is not publishable.
 */
function snap_version_parts(string $version): ?array
{
    $version = trim($version);
    // THE BUILD LETTER IS PART OF THE FORMAT. Every core release is
    // 0.7.1007D -- D for dev -- and the first version of this gate refused it
    // outright as "not three numbers". Muse flagged it as cosmetic; it is not.
    // The PM asked for this gate to cover core as well as skins, so a gate
    // that refuses the core's own format would have refused every core release
    // the moment it was wired in.
    // ONLY 'D'. Any letter would also accept the pre-0.7.17 legacy scheme
    // (0.7.9P, 0.7.9N), which is not a format anything may publish today --
    // my own test caught that the moment the letter was allowed at all.
    if (preg_match('/^(\d+)\.(\d+)\.(\d+)([Dd])?$/', $version, $m) !== 1) {
        return null;
    }
    return [(int)$m[1], (int)$m[2], (int)$m[3], strtoupper($m[4] ?? '')];
}

/** The build letter of a version ('D' for a dev release), or '' if it has none. */
function snap_version_build_letter(string $version): string
{
    $parts = snap_version_parts($version);
    return $parts === null ? '' : (string)($parts[3] ?? '');
}

/**
 * Why this version may not be published, or '' if it may.
 *
 * The message says WHY and names Sean as the only person who changes the
 * series, because the next agent to hit this will be tempted to edit the gate
 * rather than the version.
 */
function snap_version_series_problem(string $version): string
{
    $parts = snap_version_parts($version);
    if ($parts === null) {
        return "version '" . trim($version) . "' is not three numbers like 0.7.31, "
            . 'optionally with a D for a dev build like 0.7.1007D. Status words such as beta '
            . 'or stable are labels, never version numbers, and the pre-0.7.17 letter scheme '
            . '(0.7.9P) is not publishable.';
    }
    [$major, $minor] = $parts;
    // UNDER 0.8, not "exactly 0.7". My first version of this gate demanded
    // 0.7 and my own test caught the contradiction: Sean's mapping says the
    // skins already below 0.8 KEEP THEIR NUMBERS and names them -- TILEZ,
    // GLIDE, SCROLL, ONYX, TELEGRAM "and the rest" -- so TILEZ 0.2.79 and
    // TELEGRAM 0.1.11 are correct as they stand. Demanding 0.7 would have
    // forced a re-baseline he explicitly said not to do. The invariant is his
    // one condition: everything stays under 1.0, and nothing at 0.8 or above.
    if ($major !== 0 || $minor >= 8) {
        return "version '" . trim($version) . "' is at 0.8 or above. SnapSmack is in alpha: "
            . 'nothing is published at 0.8, 0.9 or 1.x, and "stable" and "beta" are status '
            . 'labels rather than version numbers. Sean is the only person who changes that; '
            . "re-baseline with snap_version_rebaseline() instead of publishing '"
            . trim($version) . "'.";
    }
    return '';
}

/**
 * Why this version is not the next one after the last published, or ''.
 *
 * $last_published is what the REGISTRY says, not what a manifest says: a
 * manifest is a local edit and the registry is the shipped truth. An empty
 * $last_published means nothing has been published yet, so any in-series
 * version is accepted as the first.
 */
function snap_version_step_problem(string $version, string $last_published): string
{
    $series = snap_version_series_problem($version);
    if ($series !== '') {
        return $series;
    }
    $last_published = trim($last_published);
    if ($last_published === '') {
        return '';
    }
    $next = snap_version_parts($version);
    $last = snap_version_parts($last_published);
    // THE RE-BASELINE CASE is decided by the SERIES of the last published
    // version, not by whether it parses. 2.1.30 parses perfectly well, which
    // is exactly why the first version of this check never reached this branch
    // -- found by its own test rather than by reading it.
    if ($last === null || snap_version_series_problem($last_published) !== '') {
        $expected = snap_version_rebaseline($last_published);
        if ($expected === null) {
            // Nothing can be derived from a version this gate cannot read, so
            // it says so rather than naming an empty version -- Muse's note on
            // 8faa5eab. Fail closed, and legibly.
            return "the last published version '{$last_published}' cannot be read as a version, so "
                . 'the next one cannot be derived. State the last published version as three '
                . 'numbers, optionally with a build letter, such as 0.7.1007D.';
        }
        if ($version !== $expected) {
            return "the last published version was '{$last_published}', which is at 0.8 or above, "
                . "so the only version that may follow it is '" . (string)$expected . "' "
                . '(its patch plus one, on ' . SNAP_VERSION_SERIES . '), and that release carries '
                . 'version_epoch ' . SNAP_VERSION_EPOCH . ' so sites holding the old number '
                . 'see it as newer.';
        }
        return '';
    }
    // In series: the same major.minor, one more on the patch. TILEZ 0.2.79
    // publishes 0.2.80, not 0.7.80 -- its number is already correct.
    // The expected next number keeps the build letter: after 0.7.1007D comes
    // 0.7.1008D, not 0.7.1008.
    $expected_next = $last[0] . '.' . $last[1] . '.' . ($last[2] + 1) . (string)($last[3] ?? '');
    if ($next[0] !== $last[0] || $next[1] !== $last[1]) {
        return "version '{$version}' changes the series from '{$last_published}'. A publish moves "
            . "the patch only, so the next is {$expected_next}.";
    }
    if ($next[2] !== $last[2] + 1) {
        return "version '{$version}' does not follow '{$last_published}': a published version is "
            . "exactly the last published one plus one on the patch, so the next is {$expected_next}.";
    }
    if ((string)($next[3] ?? '') !== (string)($last[3] ?? '')) {
        return "version '{$version}' changes the build letter from '{$last_published}'. A dev "
            . "release follows a dev release, so the next is {$expected_next}.";
    }
    return '';
}

/**
 * The re-baselined version for an off-series one, or null if it is already in
 * series (nothing to do) or unparseable.
 *
 * 2.1.30 -> 0.7.31. The patch carries over and gains one, so the number still
 * moves forward for anyone reading a changelog, and the epoch makes it newer
 * for every site holding the old one.
 */
function snap_version_rebaseline(string $version): ?string
{
    $parts = snap_version_parts($version);
    if ($parts === null) {
        return null;
    }
    [$major, $minor, $patch] = $parts;
    if ($major === 0 && $minor < 8) {
        return null;   // already under 0.8: Sean's rule is that it keeps its number
    }
    // The build letter survives the move: 1.2.3D re-baselines to 0.7.4D.
    return SNAP_VERSION_SERIES . '.' . ($patch + 1) . (string)($parts[3] ?? '');
}

/** Does this version need re-baselining at all? */
function snap_version_needs_rebaseline(string $version): bool
{
    return snap_version_rebaseline($version) !== null;
}

/**
 * The whole mapping, for a list of ['name' => currentVersion].
 *
 * Returns one row per package: name, from, to, epoch and why. Rows that need
 * no change say so rather than being dropped, because a table that silently
 * omits the unchanged ones cannot be checked against the fleet.
 *
 * @param array<string,string> $current
 * @return list<array<string,string|int|bool>>
 */
function snap_version_mapping(array $current): array
{
    $rows = [];
    foreach ($current as $name => $version) {
        $to = snap_version_rebaseline((string)$version);
        $rows[] = [
            'name' => (string)$name,
            'from' => (string)$version,
            'to' => $to ?? (string)$version,
            'changed' => $to !== null,
            'epoch' => $to !== null ? SNAP_VERSION_EPOCH : 0,
            'why' => $to !== null
                ? 'off the ' . SNAP_VERSION_SERIES . ' series; patch carried over plus one'
                : 'already in series; next publish is just plus one',
        ];
    }
    return $rows;
}

// ===== SNAPSMACK EOF =====
