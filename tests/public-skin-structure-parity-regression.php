<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$contracts = [
    'glide' => ['glide-page', 'glide-viewport', 'glide-field', 'glide-row', 'glide-track', 'glide-overlay', 'glide-scroll-cue'],
    'full-monty' => ['fm-stage', 'fm-solo', 'fm-atmosphere', 'fm-hero-link', 'fm-first-cue'],
    'impact-printer' => ['ip-header', 'ip-header-inside', 'ip-photobox', 'ip-photo-wrap', 'ip-ascii-frame', 'ip-ascii-frame-inner'],
    '50-shades-of-noah-grey' => ['fsog-header', 'fsog-header-inside', 'fsog-photobox', 'fsog-photo-wrap', 'fsog-image'],
    'rational-geo' => ['rg-header', 'rg-header-inner', 'rg-photobox', 'rg-photo-wrap', 'rg-image'],
    '52-card-pickup' => ['pickup-header', 'pickup-photo-wrap', 'pickup-image', 'pickup-tabletop', 'data-mayhem', 'pickup-ghost-footer'],
    'true-grit' => ['tg-header', 'tg-header-inside', 'tg-photobox', 'tg-photo-wrap', 'tg-image', 'tg-pagination', 'previous_page', 'next_page'],
];

foreach ($contracts as $skin => $needles) {
    $source = (string) file_get_contents(dirname(__DIR__) . '/skins/' . $skin . '/layout.php');
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            throw new RuntimeException($skin . ' lost its presentation hook: ' . $needle);
        }
    }
}

echo "Public skin structure parity regression passed.\n";
