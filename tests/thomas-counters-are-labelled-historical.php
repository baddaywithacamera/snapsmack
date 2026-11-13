<?php
/**
 * SS-1164: SMACK CENTRAL must not present the Thomas totals as current.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 *
 * WHAT WENT WRONG. The dashboard showed Bears Spawned, Story Reads, Unique
 * Finders and Sites w/ Discovers in the same grid as live figures. The table
 * behind them, sc_thomas, was filled by releases/thomas-ping.php, and that
 * reporter was removed in the public-telemetry cleanup: commit 576fc6aa took
 * pingThomas and its calls out of the Thomas engine, and the file is gone from
 * the tree. So the numbers stopped moving and nobody was told. The owner read
 * them as today's activity, which is the whole fault — not a wrong number, a
 * number presented as something it is not.
 *
 * WHAT THIS PINS. That the block says plainly it is historical, in words a
 * reader can act on, and that it is not styled with a static style attribute.
 * It deliberately does NOT assert the counts: the counts are correct history.
 *
 * WHAT IT CANNOT SEE. Whether the rendered page reads clearly to Sean. That is
 * his eye, not a test's, and the ticket's real question — retire the counters
 * or approve a new way of counting — is his decision and is still open.
 */

$root = dirname(__DIR__);
$dash = $root . '/smack-central/sc-dashboard.php';
$css  = $root . '/smack-central/assets/css/sc-admin.css';

$fails = 0;
function check(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $fails++; }
}

$html = file_get_contents($dash);
$style = file_get_contents($css);

// ── the counters are still there; this is a labelling fix, not a removal ────
foreach (['Bears Spawned', 'Story Reads', 'Unique Finders', 'Sites w/ Discovers'] as $label) {
    check("the $label counter is still shown", strpos($html, $label) !== false);
}

// ── and they are marked as history ─────────────────────────────────────────
// The wrapper class specifically, not just any string containing it: the
// note's own class is sc-thomas-historical__note, so a loose check passes
// even when the wrapper is gone. A mutation run found exactly that.
check('the block is marked historical in the markup',
      strpos($html, 'class="sc-thomas-historical"') !== false);
check('the word historical is visible to the reader, not only in a class name',
      stripos($html, '>historical<') !== false
      || stripos($html, 'are <strong>historical</strong>') !== false);
check('it says the numbers stopped being added to',
      stripos($html, 'nothing has been added') !== false);
check('it says plainly they are not today',
      stripos($html, 'not a picture of today') !== false);

// ── the note must sit BEFORE the numbers, or it explains nothing ────────────
$note = strpos($html, 'sc-thomas-historical__note');
$first = strpos($html, 'Bears Spawned');
check('the note comes before the first counter it describes',
      $note !== false && $first !== false && $note < $first);

// ── house rule: no static style attribute was added ────────────────────────
// The repo forbids style="..."; the old grid carried one and the replacement
// uses a class. This asserts the new block did not reintroduce the habit.
$block_start = strpos($html, 'sc-thomas-historical');
$block_end   = strpos($html, 'Sites w/ Discovers');
$block = ($block_start !== false && $block_end !== false)
    ? substr($html, $block_start, $block_end - $block_start) : '';
check('the historical block carries no static style attribute',
      $block !== '' && strpos($block, 'style="') === false);

// ── the styling exists, so the class is not inert ──────────────────────────
check('the stylesheet defines the block',
      strpos($style, '.sc-thomas-historical') !== false);
check('and the note itself', strpos($style, '.sc-thomas-historical__note') !== false);

// ── the reporter really is gone, which is WHY the label is needed ──────────
check('releases/thomas-ping.php is absent, so nothing refills these totals',
      !file_exists($root . '/releases/thomas-ping.php'));

echo "\n";
if ($fails > 0) {
    echo "$fails FAILURE(S)\n";
    exit(1);
}
echo "thomas counters are labelled historical: PASS\n";
// ===== SNAPSMACK EOF =====
