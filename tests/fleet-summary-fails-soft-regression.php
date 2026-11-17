<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * THE "IT WORKS" CARD SHOWS REAL NUMBERS OR NO NUMBERS. NEVER ZEROS.
 *
 * The homepage card reads "Currently running [N] sites holding [N] photos.
 * Yes, it scales." A card claiming the fleet runs 0 sites holding 0
 * photographs, because a feed was briefly unavailable, is worse than a card
 * with no figures at all -- it is a public advertisement that the product is
 * empty, and nothing on screen would say it was a transient fetch failure.
 *
 * THE SUMMARY IS SUMMED FROM THE PER-SITE RESPONSES THE SKIN CARDS ALREADY
 * USE, so the card cannot disagree with the cards below it and costs no extra
 * request.
 *
 * WHAT IT DOES NOT PUBLISH, and why this test asserts the absence: /stats.php
 * returns no photos field. Its 'posts' is COUNT(*) FROM snap_images WHERE
 * img_status = 'published' (stats.php:54), which IS the photograph count. A
 * separate post count would need a new field and a deploy to all 28 sites, so
 * exposing one would mean inventing it.
 *
 * Runs the real helper's summary arithmetic against built responses by
 * isolating that block, so no network and no live site are touched.
 */

$root = dirname(__DIR__);
$helper = (string)@file_get_contents($root . '/projects/snapsmack-ca/includes/skin-stats.php');
$failures = [];

if ($helper === '') {
    throw new RuntimeException('could not read the stats helper');
}

// --- isolate and run the summary block on supplied data ------------------
$start = strpos($helper, '$_skin_fleet_summary = null;');
$end = strpos($helper, "unset(\$_skin_row,", $start === false ? 0 : $start);
if ($start === false || $end === false) {
    throw new RuntimeException('could not isolate the fleet summary block');
}
$block = substr($helper, $start, $end - $start);

$summarise = static function (array $rows) use ($block) {
    $_skin_demo_stats = $rows;
    $_skin_fleet_summary = null;
    eval($block);
    return $_skin_fleet_summary;
};

$row = static fn(int $posts): array => ['site_name' => 'A site', 'posts' => $posts];

// --- 1. healthy fleet ----------------------------------------------------
$summary = $summarise(['a.ca' => $row(207), 'b.ca' => $row(1000),
                       'c.ca' => $row(12)]);
if (($summary['sites'] ?? null) !== 3) {
    $failures[] = 'three reporting sites did not produce sites=3';
}
if (($summary['photos'] ?? null) !== 1219) {
    $failures[] = 'photographs were not summed: got '
        . var_export($summary['photos'] ?? null, true) . ', expected 1219';
}

// --- 2. NOTHING REPORTED: no card numbers, not zeros --------------------
if ($summarise([]) !== null) {
    $failures[] = 'an empty feed produced a summary, so the homepage would '
        . 'advertise 0 sites and 0 photographs';
}

// --- 3. sites answered but every count is zero -------------------------
// A fleet that really holds nothing is indistinguishable from a broken
// query, and the honest render is no numbers.
if ($summarise(['a.ca' => $row(0), 'b.ca' => $row(0)]) !== null) {
    $failures[] = 'all-zero counts produced a summary claiming 0 photographs';
}

// --- 4. a partial fleet still counts -----------------------------------
// One site down must not suppress the card; the numbers are "currently
// running", not a guarantee of completeness.
$partial = $summarise(['a.ca' => $row(207), 'b.ca' => $row(0)]);
if (($partial['sites'] ?? null) !== 2 || ($partial['photos'] ?? null) !== 207) {
    $failures[] = 'a site reporting zero was dropped from the site tally or '
        . 'broke the photograph sum';
}

// --- 5. junk in the cache cannot crash the homepage --------------------
$junk = $summarise(['a.ca' => $row(50), 'b.ca' => 'not an array',
                    'c.ca' => ['posts' => 'twelve'], 'd.ca' => ['posts' => -9]]);
if (($junk['photos'] ?? null) !== 50) {
    $failures[] = 'malformed rows were not ignored: photos is '
        . var_export($junk['photos'] ?? null, true) . ', expected 50';
}
if (($junk['sites'] ?? null) !== 3) {
    $failures[] = 'the site tally mishandled a non-array row';
}

// --- 6. no invented post count -----------------------------------------
if (array_key_exists('posts', (array)$summary)) {
    $failures[] = 'the summary publishes a posts figure. /stats.php has no '
        . 'field for it -- its "posts" IS the photograph count -- so any '
        . 'separate number would be invented, and it would be on the front '
        . 'page';
}
if (strpos($helper, '20423') !== false || strpos($helper, '24553') !== false) {
    $failures[] = "yesterday's hub figures are hard-coded in the helper; they "
        . 'would go stale silently on a public page';
}

if ($failures) {
    throw new RuntimeException("Fleet summary contract broken:\n  - "
        . implode("\n  - ", $failures));
}

echo "Fleet summary: real numbers or none, never zeros: passed.\n";
// ===== SNAPSMACK EOF =====
