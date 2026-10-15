<?php
declare(strict_types=1);

/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be the canonical PHP SNAPSMACK EOF marker.
 */

$css = (string)file_get_contents(dirname(__DIR__) . '/skins/rational-geo/style.css');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expect(
    str_contains($css, 'body:has(.rg-single) > #scroll-stage')
    && str_contains($css, 'display: flex;')
    && str_contains($css, 'flex-direction: column;'),
    'RATIONAL GEO solo pages must use the real strict-layout body/main flex column.'
);
$expect(
    preg_match('/\.rg-photo-wrap\s*\{[^}]*flex:\s*1 1 auto;[^}]*min-height:\s*0;[^}]*height:\s*auto;/s', $css) === 1,
    'RATIONAL GEO image well must yield space to the navigation row.'
);
/*
 * These two assertions described a bounded flex column on <body>. Measured on
 * the live page at 1920x950, that column was never in effect: body computes to
 * display:block there, so the image well kept its full 830px, the 84px site
 * footer had 50px of room, and 34px of it fell past the bottom of a page that
 * cannot scroll (body is overflow:hidden on a solo view). The reservation has
 * to name the footer. Verified live before and after: footer bottom 984 -> 900
 * in a 950px window, PREV/FIRST/INFO fully on screen at 766-816.
 */
$expect(
    preg_match('/#rg-photobox\s*\{[^}]*height:\s*calc\(100dvh - 70px - var\(--infobox-height, 50px\) - var\(--rg-footer-height, 84px\)\);/s', $css) === 1,
    'RATIONAL GEO solo article must reserve room for the site footer as well as the header and info bar.'
);
$expect(
    substr_count($css, '--rg-footer-height') >= 4,
    'the footer reservation must hold at every header breakpoint, not only the default one.'
);

echo "RATIONAL GEO solo viewport regression: PASS\n";

// ===== SNAPSMACK EOF =====
