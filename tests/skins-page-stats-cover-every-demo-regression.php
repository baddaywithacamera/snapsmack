<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * EVERY SKIN THE SKINS PAGE SHOWS MUST HAVE A STATS SOURCE.
 *
 * Codex found NEW HORIZON embedding data-stats=null on the live skins page
 * while all 16 stats endpoints answered HTTP 200. The cause was not the feed:
 * curbappeal.photoblogs.fyi was listed in projects/snapsmack-ca/skins.php and
 * missing from projects/snapsmack-ca/includes/skin-stats.php, so the page
 * asked for a site the helper had never been told about.
 *
 * TWO HAND-MAINTAINED LISTS OF THE SAME DOMAINS is the fault, and adding the
 * one missing line does not fix it -- the next skin added drifts the same way,
 * and the symptom is one card quietly missing its numbers on a public page
 * while every other card looks right. Sean's demo sites ARE the product
 * showcase; a card with no numbers reads as a site with nothing on it.
 *
 * So this compares the two lists. A new demo site must appear in both or this
 * fails, naming the domain.
 */

$root = dirname(__DIR__);
$failures = [];

$skins = (string)@file_get_contents($root . '/projects/snapsmack-ca/skins.php');
$stats = (string)@file_get_contents($root . '/projects/snapsmack-ca/includes/skin-stats.php');

if ($skins === '' || $stats === '') {
    throw new RuntimeException('could not read the skins page or its stats helper');
}

// The skins page names each demo by bare domain; the helper maps bare domain
// to URL. Read both as the domains they are, rather than assuming an order.
preg_match_all(
    "/'([a-z0-9][a-z0-9.-]*\\.(?:photoblogs\\.fyi|fyi|ca|ing|com))'/",
    $skins, $m);
$shown = array_values(array_unique($m[1] ?? []));

preg_match_all("/'([a-z0-9][a-z0-9.-]*)'\\s*=>\\s*'https:\\/\\//", $stats, $m2);
$sourced = array_values(array_unique($m2[1] ?? []));

if (count($shown) < 10) {
    $failures[] = 'only ' . count($shown) . ' demo domains were found on the '
        . 'skins page, which means this test is no longer reading it properly '
        . 'rather than that the page shrank';
}
if (count($sourced) < 10) {
    $failures[] = 'only ' . count($sourced) . ' domains were found in the '
        . 'stats helper; the test is not reading it properly';
}

$missing = array_values(array_diff($shown, $sourced));
if ($missing) {
    $failures[] = 'these demo sites are shown on the skins page but have no '
        . 'stats source, so their cards render with no numbers: '
        . implode(', ', $missing);
}

// The other direction is a wasted request per page build, not a visible
// fault, so it is reported rather than failed -- removing an entry nobody
// asked to remove is its own kind of mistake.
$orphans = array_values(array_diff($sourced, $shown));
if ($orphans) {
    echo "NOTE: stats are fetched for sites the skins page does not show: "
        . implode(', ', $orphans) . "\n";
}

if ($failures) {
    throw new RuntimeException("Skins page stats coverage broken:\n  - "
        . implode("\n  - ", $failures));
}

echo 'Skins page: all ' . count($shown) . " demo sites have a stats source: passed.\n";
// ===== SNAPSMACK EOF =====
