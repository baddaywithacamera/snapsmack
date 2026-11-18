<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Which tickets merged since the last tag, and which of them the open
 * changelog section does not mention.
 *
 * WHY THIS IS A FILE AND NOT A SHELL LINE. I ran this check by hand twice
 * while assembling 0.7.999D and it was wrong both times, differently:
 *
 *   1. It scanned `git log --merges` only. A ticket merged through a branch
 *      whose merge subject does not name it -- "Merge branch
 *      'codex/ss1413-dev-merge' into dev" -- was invisible.
 *   2. It matched /SS-[0-9]{4}/ exactly. Branch names are written ss1413 and
 *      ss1362, lower case and unhyphenated, so even when the subject DID
 *      name the ticket the pattern did not see it.
 *
 * Between them those two missed SS-1413 entirely, and 0.7.999D came within
 * one review of being tagged with no release note for a change that stops a
 * backup tool deleting from cloud storage. A retyped shell command gets the
 * same thing wrong the same way next time, so it is written down here with
 * the two faults named.
 *
 * NOT A TEST, DELIBERATELY. Work merges before its changelog entry all the
 * time mid-cycle; that is normal, not a regression. A test would be red for
 * most of a release and get ignored by the time it mattered. This is a thing
 * you RUN when you are assembling a release, and it reports rather than
 * refuses -- the judgement about what is owner-facing is a person's.
 *
 * Usage:
 *     php tools/_build/changelog-completeness.php [<tag>] [<section>]
 *     php tools/_build/changelog-completeness.php v0.7.998D "0.7.999D"
 *
 * Defaults to the highest v0.7.NNND tag and the first "## 0.7.*" section.
 */

$root = dirname(__DIR__, 2);

function cc_git(array $args, string $root): string {
    $cmd = 'git -C ' . escapeshellarg($root);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "git failed: " . implode("\n", $out) . "\n");
        exit(2);
    }
    return implode("\n", $out);
}

/**
 * Every ticket id in a blob of text, normalised.
 *
 * CASE- AND SEPARATOR-INSENSITIVE, which is the whole point: SS-1413,
 * ss1413, ss_1413 and SS 1413 are one ticket, and a branch name uses the
 * spelling a changelog never would.
 */
function cc_tickets(string $text): array {
    if (!preg_match_all('/\bss[ _-]?(\d{4})\b/i', $text, $m)) return [];
    return array_values(array_unique(array_map(
        static fn (string $n): string => 'SS-' . $n, $m[1]
    )));
}

$tag = $argv[1] ?? '';
if ($tag === '') {
    $tags = preg_split('/\s+/', trim(cc_git(['tag', '--list', 'v0.7.*D'], $root))) ?: [];
    $tags = array_values(array_filter($tags));
    usort($tags, static function (string $a, string $b): int {
        preg_match('/(\d+)D$/', $a, $ma);
        preg_match('/(\d+)D$/', $b, $mb);
        return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
    });
    $tag = end($tags) ?: '';
}
if ($tag === '') {
    fwrite(STDERR, "no v0.7.NNND tag found, and none given\n");
    exit(2);
}

// EVERY COMMIT, not just merges. See fault 1 above.
//
// No --format here on purpose: escapeshellarg() on Windows strips '%' from
// an argument, so '--format=%s%n%b' reached git as '  s n b' and it refused
// the whole command. Plain `git log` already prints subject and body, which
// is all this needs, and it behaves the same on every platform.
//
// AND CHANGELOG-ONLY COMMITS ARE EXCLUDED. Fault 3, found by running this:
// my own commits describing the 998D split name every ticket they moved, so
// scanning all commit text reported ten tickets as "merged and undocumented"
// when the thing naming them WAS the documentation. A commit that touches
// only CHANGELOG.md is talking about work, not doing it.
//
// AND SUBJECT LINES ONLY. Fault 4, also found by running this: a commit body
// cites related tickets -- "see SS-1335", "as SS-1410 did" -- and scanning
// bodies reported those as undocumented work of their own. Three of the five
// names in my first clean run were citations inside other tickets' commits.
// A commit that DOES a ticket's work says so on its first line; a commit that
// merely refers to one does it further down.
$log = cc_git(['log', '--no-color', '--pretty=oneline', $tag . '..HEAD', '--',
               '.', ':(exclude)CHANGELOG.md'], $root);
$merged = cc_tickets($log);
sort($merged);

$changelog = (string)file_get_contents($root . '/CHANGELOG.md');
$section = $argv[2] ?? '';
$lines = explode("\n", $changelog);
$start = null; $end = count($lines);
foreach ($lines as $i => $line) {
    if (!str_starts_with($line, '## 0.7.')) continue;
    if ($start === null && ($section === '' || str_contains($line, $section))) {
        $start = $i;
        continue;
    }
    if ($start !== null) { $end = $i; break; }
}
if ($start === null) {
    fwrite(STDERR, "no matching '## 0.7.*' section in CHANGELOG.md\n");
    exit(2);
}
$documented = cc_tickets(implode("\n", array_slice($lines, $start, $end - $start)));

$missing = array_values(array_diff($merged, $documented));
$extra   = array_values(array_diff($documented, $merged));

echo "Tag:     {$tag}\n";
echo "Section: " . trim($lines[$start]) . "\n";
echo 'Merged since the tag: ' . (count($merged) ? implode(' ', $merged) : '(none)') . "\n\n";

if ($missing) {
    echo "MERGED BUT NOT IN THIS SECTION — each needs an entry or a stated reason:\n";
    foreach ($missing as $t) echo "  {$t}\n";
    echo "\n";
} else {
    echo "Every ticket merged since the tag is mentioned in this section.\n\n";
}
if ($extra) {
    // Not a fault on its own: a ticket can straddle a tag, and an entry may
    // describe work that landed earlier. SS-1362 is exactly that. Reported so
    // somebody looks, not so somebody deletes.
    echo "IN THIS SECTION BUT NOT MERGED SINCE THE TAG — check each one is\n";
    echo "describing work that really belongs here:\n";
    foreach ($extra as $t) echo "  {$t}\n";
    echo "\n";
}
echo "Non-ticketed merges are NOT covered by this tool. Read them too:\n";
echo "  git log --merges --format='%h %s' {$tag}..HEAD\n";
// ===== SNAPSMACK EOF =====
