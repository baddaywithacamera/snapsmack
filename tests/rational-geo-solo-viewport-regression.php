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
// SS-1345 keeps the photograph and its navigation in the first viewport, then
// grows the outer stage downward when INFO opens. The article and photobox must
// not become nested scrollers: that geometry produced a blank, non-scrolling
// live post even though a synthetic fixture passed.
$expect(
    preg_match('/body:has\(\.rg-single\) > #scroll-stage\s*\{[^}]*overflow-x:\s*hidden;[^}]*overflow-y:\s*auto;/s', $css) === 1,
    'RATIONAL GEO outer stage must own solo-page scrolling.'
);
$expect(
    preg_match('/\.rg-single\s*\{[^}]*flex:\s*0 0 100%;[^}]*overflow:\s*visible;/s', $css) === 1
    && preg_match('/#rg-photobox\s*\{[^}]*overflow:\s*visible;[^}]*flex:\s*0 0 100%;[^}]*height:\s*100%;/s', $css) === 1,
    'RATIONAL GEO photograph and navigation must fill the first viewport without becoming a nested scroller.'
);
$expect(
    preg_match('/\.rg-photo-wrap\s*\{[^}]*flex:\s*1 0 calc\(100% - var\(--infobox-height, 50px\)\);[^}]*min-height:\s*0;[^}]*height:\s*auto;/s', $css) === 1,
    'RATIONAL GEO image well must reserve the in-flow navigation row while keeping the photograph visible.'
);
$expect(
    preg_match('/\.rg-single > #footer\s*\{[^}]*position:\s*static;[^}]*flex:\s*0 0 auto;[^}]*max-height:\s*none;[^}]*overflow-y:\s*visible;/s', $css) === 1,
    'RATIONAL GEO INFO tray must follow the first viewport in normal flow and extend the outer stage downward.'
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
