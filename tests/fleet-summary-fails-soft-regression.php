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

// --- 6. POSTS ONLY WHEN EVERY REPORTER ANSWERED -------------------------
// post_count arrives with 998D. While the fleet is mixed, a partial sum is a
// wrong number that LOOKS right -- smaller than the truth, on the front page,
// with nothing on screen to say it is incomplete. The card must keep its
// photographs-only wording until every reporting site can answer.
$rowp = static fn(int $photos, ?int $posts): array =>
    ['site_name' => 'A site', 'posts' => $photos, 'post_count' => $posts];

$all = $summarise(['a.ca' => $rowp(207, 180), 'b.ca' => $rowp(1000, 900)]);
if (($all['posts'] ?? null) !== 1080) {
    $failures[] = 'a fully updated fleet did not sum post_count: got '
        . var_export($all['posts'] ?? null, true) . ', expected 1080';
}
if (($all['photos'] ?? null) !== 1207) {
    $failures[] = 'adding posts disturbed the photograph total';
}

$mixed = $summarise(['a.ca' => $rowp(207, 180), 'b.ca' => $rowp(1000, null)]);
if (array_key_exists('posts', (array)$mixed)) {
    $failures[] = 'a MIXED fleet published a partial post total ('
        . var_export($mixed['posts'] ?? null, true) . '). One site short of '
        . '998D and the front page understates the project.';
}
if (($mixed['photos'] ?? null) !== 1207) {
    $failures[] = 'a mixed fleet lost its photograph total too';
}

// A site reporting zero posts HAS answered, so it counts as a reporter.
$zero = $summarise(['a.ca' => $rowp(207, 180), 'b.ca' => $rowp(5, 0)]);
if (($zero['posts'] ?? null) !== 180) {
    $failures[] = 'a site honestly reporting 0 posts was treated as not '
        . 'having answered';
}

// Pre-998D fleet: nobody reports it, so no posts figure at all.
$older = $summarise(['a.ca' => $row(207), 'b.ca' => $row(1000)]);
if (array_key_exists('posts', (array)$older)) {
    $failures[] = 'a fleet with no post_count anywhere still published a '
        . 'posts figure';
}

// Junk must not count as an answer.
$junkp = $summarise(['a.ca' => $rowp(207, 180),
                     'b.ca' => ['posts' => 5, 'post_count' => 'lots']]);
if (array_key_exists('posts', (array)$junkp)) {
    $failures[] = 'a non-numeric post_count was accepted as a report';
}

if (strpos($helper, '20423') !== false || strpos($helper, '24553') !== false) {
    $failures[] = "yesterday's hub figures are hard-coded in the helper";
}
if (strpos($helper, '20423') !== false || strpos($helper, '24553') !== false) {
    $failures[] = "yesterday's hub figures are hard-coded in the helper; they "
        . 'would go stale silently on a public page';
}

// --- 7. THE RESPONSE MAPPING, executed -----------------------------------
// A mutation turned the post_count mapping into (int)(... ?? 0), which makes
// every pre-998D site claim ZERO POSTS rather than "unknown". That counts as
// an answer, so the guard above would publish a partial fleet total as a
// complete one. The guard cannot save you from a lie told upstream of it, so
// the mapping is a function now and this runs it.
require_once $root . '/projects/snapsmack-ca/includes/stats-row-shim.php';

$absent = ss_skin_stats_row(['site_name' => 'A site', 'posts' => 207]);
if ($absent['post_count'] !== null) {
    $failures[] = 'a response with no post_count stored '
        . var_export($absent['post_count'], true) . ' instead of null, so a '
        . 'site that has not updated would be counted as having answered';
}
$present = ss_skin_stats_row(['posts' => 207, 'post_count' => 180]);
if ($present['post_count'] !== 180) {
    $failures[] = 'a reported post_count was not kept';
}
$zeroed = ss_skin_stats_row(['posts' => 5, 'post_count' => 0]);
if ($zeroed['post_count'] !== 0) {
    $failures[] = 'an honest zero was discarded; a site with no posts has '
        . 'still answered';
}
foreach (['lots', '', [], null] as $junk) {
    $row = ss_skin_stats_row(['posts' => 5, 'post_count' => $junk]);
    if ($row['post_count'] !== null) {
        $failures[] = 'a non-numeric post_count (' . var_export($junk, true)
            . ') was kept as ' . var_export($row['post_count'], true);
    }
}
if ($absent['posts'] !== 207) {
    $failures[] = 'the photograph count was lost from the row';
}

if ($failures) {
    throw new RuntimeException("Fleet summary contract broken:\n  - "
        . implode("\n  - ", $failures));
}

echo "Fleet summary: real numbers or none, never zeros: passed.\n";
// ===== SNAPSMACK EOF =====
