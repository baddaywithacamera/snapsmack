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
$expect(
    preg_match('/#rg-photobox\s*\{[^}]*height:\s*100%;/s', $css) === 1,
    'RATIONAL GEO solo article must fill its bounded main row.'
);
$expect(
    !str_contains($css, 'height: calc(100dvh - 70px - var(--infobox-height, 50px))'),
    'RATIONAL GEO restored the viewport formula that double-counted the navigation row.'
);

echo "RATIONAL GEO solo viewport regression: PASS\n";

// ===== SNAPSMACK EOF =====
