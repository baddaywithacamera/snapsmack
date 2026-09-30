<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$contracts = [
    'glide' => ['glide-page', 'glide-viewport', 'glide-field', 'glide-row', 'glide-track', 'glide-overlay', 'glide-scroll-cue', "['response']['rows']", 'presentation_aspect'],
    'full-monty' => ['fm-stage', 'fm-solo', 'fm-atmosphere', 'fm-hero-link', 'fm-first-cue', 'data-fm-src'],
    'impact-printer' => ['ip-header', 'ip-header-inside', 'ip-photobox', 'ip-photo-wrap', 'ip-ascii-frame', 'ip-ascii-frame-inner', 'inline_frame_style', 'ip-border-left', 'ip-border-right'],
    '50-shades-of-noah-grey' => ['fsog-header', 'fsog-header-inside', 'fsog-photobox', 'fsog-photo-wrap', 'fsog-image', 'justified-grid', 'ss-masonry-item', 'masonry_layout', 'ss-square-wall'],
    'rational-geo' => ['rg-header', 'rg-header-inner', 'rg-photobox', 'rg-photo-wrap', 'rg-image', 'rg-single', 'rg-drawer-inner', 'rg-exif-section'],
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

$rational = (string)file_get_contents(dirname(__DIR__) . '/skins/rational-geo/layout.php');
if (substr_count($rational, 'rg-drawer-inner') < 2
    || !str_contains($rational, 'id="pane-comments"')) {
    throw new RuntimeException('Rational Geo lost the legacy information/signal drawer pairing.');
}

$fsogManifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/skins/50-shades-of-noah-grey/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (!in_array('smack-archive-grid-switch', $fsogManifest['require_scripts'] ?? [], true)) {
    throw new RuntimeException('50 Shades dual grids lost their registered switch engine.');
}

echo "Public skin structure parity regression passed.\n";
