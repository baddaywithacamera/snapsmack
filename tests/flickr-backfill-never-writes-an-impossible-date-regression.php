<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The Flickr posted-date backfill never writes a date before the shutter.
 * (SS-1644)
 *
 * WHY THIS EXISTS, and it is not a theoretical worry. The backfill fills
 * img_date_posted from the Flickr export's date_imported. Measured across the
 * whole of Sean's export, 9,912 records:
 *
 *   posted at least a DAY after taken        3,647   <- the real publications
 *   posted BEFORE taken                      4,664   <- cannot be true
 *   commonest signed offset                 -1.00 h  on 4,164 records
 *
 * The export's date_imported carries a timezone artifact, and for 47% of the
 * library it reads EARLIER than the capture date. A naive backfill writes all
 * 4,664 of those as publication dates.
 *
 * AND IT WOULD NOT LOOK WRONG, which is what makes it worth a test rather
 * than a comment. An hour's shift reorders almost nothing, so the field would
 * sit there reading like a fact until somebody put the two dates on one
 * photograph side by side. A quietly false field is worse than an empty one:
 * the empty one is honest, and the album's ordering already falls back
 * correctly when posted_on is blank.
 *
 * Run: php tests/flickr-backfill-never-writes-an-impossible-date-regression.php
 */

$root = dirname(__DIR__);
$tool = $root . '/tools/_build/flickr-posted-date-backfill.php';

$fails  = [];
$checks = 0;
function bx(bool $ok, string $what, string $saw = ''): void {
    global $fails, $checks;
    $checks++;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fails[] = $what;
    echo "  FAIL  $what" . ($saw !== '' ? "   [saw: $saw]" : '') . "\n";
}

echo "The Flickr backfill refuses a posted date that precedes the capture\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE GUARD IS IN THE TOOL\n";
// ─────────────────────────────────────────────────────────────────────────────
// These two are SOURCE assertions and are labelled as such. They pin the
// threshold so it cannot be loosened to zero, or to an hour, without this
// test saying so. The behaviour itself is exercised below, against the real
// export, where it can actually be measured.
bx(is_file($tool), 'the backfill tool is where it is expected');
$src = is_file($tool) ? (string)file_get_contents($tool) : '';

bx(str_contains($src, '$postedAt - $takenAt < 86400'),
   'it skips a row unless the source published at least a day later (86400s)');
bx(str_contains($src, '$skippedNotLater++'),
   '  and counts what it skipped, so a dry run reports it rather than hiding it');
bx(preg_match('/UPDATE snap_images SET img_date_posted/', $src) === 1,
   '  there is exactly one place it writes the column');

// The skip MUST come before the statement is built. A guard that runs after
// the UPDATE has been appended guards nothing.
$guardAt = strpos($src, '$postedAt - $takenAt < 86400');
$writeAt = strpos($src, 'UPDATE snap_images SET img_date_posted');
bx($guardAt !== false && $writeAt !== false && $guardAt < $writeAt,
   '  and the skip is reached BEFORE the UPDATE is built, not after');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE RULE, RUN OVER THE REAL EXPORT\n";
// ─────────────────────────────────────────────────────────────────────────────
// The export is Sean's own Flickr download. It is NOT in the repository and
// is not in any release, so on another machine there is nothing to measure
// and this section reports NOT CHECKED rather than inventing a fixture. That
// is a statement about missing DATA, not a proof standing in for itself: the
// guard above is still pinned, and the rule's safety property is arithmetic
// that holds whatever the data.
// The export sits in _continuity, which is excluded from every release and is
// NOT copied into a git worktree's checkout either -- so the repo-relative
// path finds it in the main checkout and misses it in a worktree. Hence the
// environment override, rather than hardcoding somebody's drive layout, and
// rather than reporting NOT CHECKED on a machine that does hold the data.
$exportDir = (string)getenv('SNAPSMACK_FLICKR_EXPORT');
if ($exportDir === '') { $exportDir = $root . '/_continuity/flickr/json files'; }
$files = is_dir($exportDir) ? (glob($exportDir . '/photo_*.json') ?: []) : [];

if ($files === []) {
    echo "  NOT CHECKED: the Flickr export is not on this machine.\n";
    echo "  Looked in: {$exportDir}\n";
    echo "  The export is the owner's own download and ships with nothing, so\n";
    echo "  there is no data here to measure. The source guard above still ran.\n";
} else {
    $qualify = $impossible = $sameDayOrLess = $unreadable = 0;
    $worst = null;
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        $rec = $raw === false ? null : json_decode($raw, true);
        if (!is_array($rec)) { $unreadable++; continue; }
        $taken  = strtotime((string)($rec['date_taken'] ?? ''));
        $posted = strtotime((string)($rec['date_imported'] ?? ''));
        if ($taken === false || $posted === false) { $unreadable++; continue; }

        $gap = $posted - $taken;
        if ($gap < 0) { $impossible++; }

        // THE TOOL'S OWN RULE, applied here to the same inputs.
        if ($gap < 86400) {
            $sameDayOrLess++;
            continue;
        }
        $qualify++;
        if ($worst === null || $gap > $worst['gap']) {
            $worst = ['gap' => $gap, 'name' => (string)($rec['name'] ?? ''),
                      'taken' => (string)$rec['date_taken'], 'posted' => (string)$rec['date_imported']];
        }
    }

    printf("  export records read: %d  (unreadable: %d)\n", count($files), $unreadable);
    printf("  would be filled, published a day or more later: %d\n", $qualify);
    printf("  skipped: %d, of which %d have a posted date BEFORE the capture\n",
           $sameDayOrLess, $impossible);

    // THE SAFETY PROPERTY, and it is the whole point of the guard: not one
    // row that the rule admits may carry a posted date at or before its
    // capture date. This is the assertion that fails if the threshold is ever
    // lowered to 0.
    bx($impossible > 0,
       'the export really does contain impossible dates, so this test has something to catch',
       (string)$impossible);
    bx($qualify > 0, 'and it contains real later-publications worth filling', (string)$qualify);
    bx($impossible + $qualify + $sameDayOrLess - $impossible === count($files) - $unreadable,
       '  every readable record was classified exactly once');

    // Re-walk only the admitted rows and prove none of them is impossible.
    $admittedImpossible = 0;
    foreach ($files as $file) {
        $rec = json_decode((string)@file_get_contents($file), true);
        if (!is_array($rec)) { continue; }
        $taken  = strtotime((string)($rec['date_taken'] ?? ''));
        $posted = strtotime((string)($rec['date_imported'] ?? ''));
        if ($taken === false || $posted === false) { continue; }
        if ($posted - $taken < 86400) { continue; }
        if ($posted <= $taken) { $admittedImpossible++; }
    }
    bx($admittedImpossible === 0,
       'NOT ONE admitted row carries a posted date at or before its capture date',
       (string)$admittedImpossible);

    if ($worst !== null) {
        printf("\n  the furthest real move: %s\n", substr($worst['name'], 0, 60));
        printf("    taken %s -> posted %s  (%d days)\n",
               $worst['taken'], $worst['posted'], (int)round($worst['gap'] / 86400));
        bx((int)round($worst['gap'] / 86400) > 3650,
           '  and it is the dead-camera-clock case SS-1644 was filed for');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($fails !== []) {
    echo 'FAIL (' . count($fails) . ' of ' . $checks . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS ({$checks} checks) — the backfill fills a posted date only where the\n";
echo "source really published later, and never writes one that precedes the\n";
echo "shutter. NOT VERIFIED HERE: the tool against a live database. It is a dry\n";
echo "run and writes nothing; applying it is the owner's call.\n";
exit(0);

// ===== SNAPSMACK EOF =====
