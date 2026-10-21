<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ss-engine-mosaic.js');
foreach (['lastAutomaticTripletTemplate', 'automaticTripletTemplate', 'data-auto-triplet-template', 'images.length === 3'] as $hook) {
    if (!str_contains($source, $hook)) throw new RuntimeException("Mosaic automatic-triplet protection missing: {$hook}");
}
if (!str_contains($source, "chosen === lastAutomaticTripletTemplate")) throw new RuntimeException('Mosaic engine no longer prevents an immediate automatic triplet repeat.');
if (!str_contains($source, "container.getAttribute('data-template')")) throw new RuntimeException('Mosaic engine lost explicit template selection.');
if (!str_contains($source, 'stableTripletSide(images)')) throw new RuntimeException('The first automatic triplet no longer varies its hero side between pages.');
if (!str_contains($source, "? 'one-left' : 'one-right'")) throw new RuntimeException('Automatic triplets no longer choose between left- and right-led hero compositions.');
if (str_contains($source, "var preferred = imageAR(images[0]) < 1.15 ? 'one-left' : 'three-across'")) throw new RuntimeException('Landscape triplets still default to three-across.');

echo "mosaic triplet repeat regression: ok\n";
// ===== SNAPSMACK EOF =====
