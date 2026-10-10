<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$root = dirname(__DIR__);
$lazy = (string)file_get_contents($root . '/assets/js/ss-engine-lazyload.js');
$fade = (string)file_get_contents($root . '/assets/js/ss-engine-image-fade-load.js');
$css = (string)file_get_contents($root . '/skins/tilez/style.css');
$manifest = json_decode((string)file_get_contents($root . '/skins/tilez/manifest.json'), true);

if (!str_contains($lazy, 'window.getComputedStyle(img).transition')) {
    throw new RuntimeException('Lazy loading can overwrite a skin-owned image transition.');
}
if (str_contains($lazy, "img.style.transition = 'opacity ' + fadeDuration + 'ms ease-in';")) {
    throw new RuntimeException('Lazy loading still replaces the complete transition shorthand.');
}
if (!str_contains($fade, 'window.getComputedStyle(img).transition')) {
    throw new RuntimeException('Post-image fade loading can overwrite a skin-owned image transition.');
}
if (str_contains($fade, "img.style.transition = 'opacity 0.4s ease-in-out';")) {
    throw new RuntimeException('Post-image fade loading still replaces the complete transition shorthand.');
}
if (!str_contains($css, 'transition: filter .3s ease, transform .35s ease;')) {
    throw new RuntimeException('TILEZ lost its smooth image zoom transition.');
}
foreach ([
    '.show-preview-titles .has-post-thumbnail .archive-post-header { bottom: 35px; }',
    '.has-post-thumbnail:hover .archive-post-header { bottom: 25px; }',
] as $anchor) {
    if (!str_contains($css, $anchor)) {
        throw new RuntimeException('TILEZ caption can move vertically on hover: ' . $anchor);
    }
}
// A FLOOR, not a pin. Pinning the exact version made every legitimate
// skin release fail this test, so the cheap way out was to change the
// files and leave the version alone — which is exactly how sites ended
// up frozen on an old package under a current-looking version number.
if (version_compare((string)($manifest['version'] ?? '0'), '0.2.63', '<')) {
    throw new RuntimeException('TILEZ version was not advanced to 0.2.63.');
}

echo "TILEZ hover transition regression passed\n";
// ===== SNAPSMACK EOF =====
