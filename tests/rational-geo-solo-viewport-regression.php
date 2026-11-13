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
    preg_match('/\.rg-photo-wrap\s*\{[^}]*flex:\s*1 0 calc\(100% - var\(--infobox-height, 50px\)\);[^}]*min-height:\s*calc\(100% - var\(--infobox-height, 50px\)\);[^}]*height:\s*auto;/s', $css) === 1,
    'RATIONAL GEO image well must preserve the solo viewport when the in-flow information tray opens.'
);
$expect(
    preg_match('/\.rg-single\s+#infobox\s*\{[^}]*width:\s*100%;/s', $css) === 1,
    'RATIONAL GEO single-photo navigation must span the full viewer width.'
);
$expect(
    preg_match('/body:has\(\.rg-single\) > #system-footer\s*\{[^}]*display:\s*none;/s', $css) === 1,
    'RATIONAL GEO solo view must keep the system footer off the photograph screen.'
);
$expect(
    preg_match('/body:has\(\.rg-single\) > #scroll-stage\s*\{[^}]*height:\s*calc\(100dvh - 70px\);/s', $css) === 1
    && preg_match('/#rg-photobox\s*\{[^}]*height:\s*100%;/s', $css) === 1,
    'RATIONAL GEO solo view must give the full post-header viewport to the photograph and its navigation.'
);
$expect(
    !str_contains($css, '--rg-footer-height'),
    'RATIONAL GEO solo geometry must not reserve space for the hidden system footer.'
);

echo "RATIONAL GEO solo viewport regression: PASS\n";

// ===== SNAPSMACK EOF =====
