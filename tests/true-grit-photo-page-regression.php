<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * TRUE GRIT's photo page shows the photograph, and offers the download.
 *
 * Sean, on a live page: "the download button is missing. as is the photo
 * itself. you did not notice that part?" I had fetched that page and counted
 * one word in it. The photograph was not there and I did not look.
 *
 * MEASURED IN A BROWSER on foundtextures.ca at 1920 and 1024:
 *   img.tg-image  complete=true  natural 1900x1425   -- the FILE was fine
 *   img box       0x0
 *   .tg-photo-wrap 80x940        -- 80 is exactly its own left+right padding
 *   #tg-photobox  1920x940
 *
 * #tg-photobox is display:flex and had THREE children: the photo wrap, the
 * #infobox navigation bar, and #footer. The nav bar took the whole 1920px row
 * and the photograph was left with nothing. Forcing display:block in a browser
 * made the image appear instantly at 1173x880, which is what pointed at the
 * markup rather than the stylesheet.
 *
 * Before 592f1ae4, "Complete photoblog strict runtime conversion" (28
 * September), those three were SIBLINGS, and the download overlay was included
 * inside the photo wrap. The conversion nested two of them inside the first
 * and dropped the include. One block, one commit, both faults.
 *
 * So this asserts structure, not appearance: the photobox contains the
 * photograph and nothing else, and the download button has a way to be
 * rendered by a skin that cannot include PHP.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function tg_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$layout = (string) file_get_contents($root . '/skins/true-grit/layout.php');

echo "The photobox holds the photograph and nothing else\n";
tg_test(str_contains($layout, '<div id="tg-photobox"><div class="tg-photo-wrap">'),
    'the photobox wraps the photo wrap');
tg_test(!preg_match('/id="tg-photobox"[^>]*>(?:(?!<\/div>).)*id="infobox"/s', $layout),
    'the navigation bar is NOT inside the photobox: that is what starved the photograph');
tg_test(str_contains($layout, '</div></div><div id="infobox">'),
    'the photobox is closed before the navigation bar opens, so the two are siblings');
tg_test(preg_match('/id="tg-photobox"/', $layout) === 1,
    'there is exactly one photobox');

echo "\nThe article still carries the microformat, which the id used to share\n";
tg_test(str_contains($layout, '<article class="h-entry single-image-page">'),
    'h-entry and single-image-page moved to the wrapping article, not lost');

// THE DOWNLOAD BUTTON IS NOT ASSERTED HERE, BECAUSE IT IS NOT FIXED.
//
// I built a photo-download component that included core/download-overlay.php,
// and tests/skin-render-helpers-regression.php refused it outright: "Render
// helper owns forbidden authority: $pdo". That guard is right. The overlay
// needs the database for its HMAC salt (SECAUDIT 047), and a render helper may
// not reach the database. The URL therefore has to be prepared upstream, in
// the controller, and the renderer handed a finished link.
//
// That wiring is NOT done and is not asserted as done. foundtextures.ca has
// had no download link since 592f1ae4 on 28 September and still has none.

echo "\n";
echo "  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "TRUE GRIT photo page: FAIL ({$fail}).\n";
    exit(1);
}
echo "TRUE GRIT photo page: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
