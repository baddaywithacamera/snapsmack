<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * One thumbnail size library-wide, and the label says the number the code uses.
 *
 * Sean, 29 October 2026: "when we developed SCROLL and modified MOSAIC to
 * create the STACKER engine it uses, we discovered that 600px images were too
 * small. Medium images got moved to 900px on the long side. Small is 400px.
 * Those are the numbers to go by. If anything is using 600px instead of 900px
 * for medium it is wrong."
 *
 * The generators were already right. core/constants.php has carried
 * SNAPSMACK_THUMB_SQUARE = 400 and SNAPSMACK_THUMB_ASPECT_LONG = 900 since the
 * split was ended, and its own comment explains why: before that, a
 * thumbnail's size depended on which code path created the image.
 *
 * TWO THINGS HAD DRIFTED BACK ANYWAY.
 *
 * The ARCHIVE APPEARANCE panel offered "MEDIUM (~600px THUMBNAILS) —
 * RECOMMENDED" and a tooltip saying the same, both typed into the page. A site
 * owner reading that panel was told 600 while every generator produced 900, so
 * the one number a person can actually see was the only wrong one. Sean caught
 * it from memory, which is not a check.
 *
 * And smack-swap.php still had "$thumb_size = 400;" typed in. The value
 * happens to agree today. That is exactly the condition the constants block
 * was created to end: swap a photograph and its thumbnail size came from a
 * different place than every other path, so the two could part company without
 * anything failing.
 *
 * So this does not assert the numbers. It asserts that nothing types them.
 * Hardcoding 900 here would be the same fault in a new location.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function td_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$constants = (string) file_get_contents($root . '/core/constants.php');

echo "There is a single source of truth, and it is the one Sean stated\n";
td_test(preg_match("/define\('SNAPSMACK_THUMB_SQUARE',\s*(\d+)\)/", $constants, $sq) === 1,
    'SNAPSMACK_THUMB_SQUARE is defined');
td_test(preg_match("/define\('SNAPSMACK_THUMB_ASPECT_LONG',\s*(\d+)\)/", $constants, $as) === 1,
    'SNAPSMACK_THUMB_ASPECT_LONG is defined');
td_test(isset($sq[1]) && (int)$sq[1] === 400, 'small / square is 400px');
td_test(isset($as[1]) && (int)$as[1] === 900, 'medium / aspect long side is 900px, not the old 600');

echo "\nEvery generator reads it instead of typing a number\n";
$generators = [
    'core/thumb-generator.php',
    'core/image-ingest.php',
    'smack-post-solo.php',
    'smack-swap.php',
];
foreach ($generators as $rel) {
    $f = $root . '/' . $rel;
    if (!is_file($f)) { td_test(false, "{$rel} exists"); continue; }
    $src = (string) file_get_contents($f);
    td_test(str_contains($src, 'SNAPSMACK_THUMB_SQUARE') || str_contains($src, 'SNAPSMACK_THUMB_ASPECT_LONG'),
        "{$rel} reads the constants");
    // A bare assignment of a literal to a thumb-size variable is the drift.
    td_test(preg_match('/\$thumb_size\s*=\s*\d+\s*;/', $src) !== 1,
        "{$rel} does not assign a literal thumbnail size");
}

echo "\nThe number a site owner reads is the number the code uses\n";
$panel = (string) file_get_contents($root . '/smack-appearance-archive.php');
td_test(!preg_match('/~\s*600\s*px/i', $panel),
    'ARCHIVE APPEARANCE no longer tells the owner 600px');
td_test(str_contains($panel, 'SNAPSMACK_THUMB_ASPECT_LONG'),
    'the MASONRY IMAGE SIZE label is derived from the constant, not typed');
td_test(!preg_match('/MEDIUM \(~\s*\d+\s*px/i', $panel),
    'and no literal px figure is written into that label at all');

echo "\nNo admin panel quotes a thumbnail size it did not read\n";
foreach (glob($root . '/smack-appearance-*.php') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    $name = basename($f);
    td_test(!preg_match('/~\s*600\s*px\s*(thumbnail|thumb)/i', $src),
        "{$name} does not quote 600px thumbnails");
}

echo "\n";
echo "  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Thumbnail dimensions single source: FAIL ({$fail}).\n";
    exit(1);
}
echo "Thumbnail dimensions single source: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
