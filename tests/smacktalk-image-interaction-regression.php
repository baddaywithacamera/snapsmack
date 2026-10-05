<?php
/**
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be the canonical PHP SNAPSMACK EOF marker.
 */
declare(strict_types=1);

$shared = (string)file_get_contents(dirname(__DIR__) . '/assets/css/shortcodes.css');
$tilez = (string)file_get_contents(dirname(__DIR__) . '/skins/tilez/style.css');

$checks = [
    'shared frame shrink-wraps the displayed photograph' => str_contains($shared, 'display: inline-flex;'),
    'shared frame clips hover enlargement' => str_contains($shared, 'overflow: hidden;'),
    'shared interaction targets standalone lightbox images' => str_contains($shared, '.snap-inline-frame img[data-lightbox-src]'),
    'shared interaction targets mosaic lightbox images' => str_contains($shared, '.mosaic-item img[data-lightbox-src]'),
    'shared interaction supplies the requested zoom' => str_contains($shared, 'transform: scale(1.195);'),
    'shared interaction supplies restrained darkening' => str_contains($shared, 'filter: brightness(.83);'),
    'TILEZ no longer owns the post-image zoom' => !str_contains($tilez, 'clip-path: inset(8.16%)')
        && !str_contains($tilez, '.entry-content .snap-inline-frame:hover img'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS ' . $label . "\n";
}

echo "SMACKTALK shared image interaction regression passed.\n";

// ===== SNAPSMACK EOF =====
