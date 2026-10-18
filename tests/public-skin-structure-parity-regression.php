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
    $cmsComponents = [
        '50-shades-of-noah-grey' => '50-shades-page-component.php',
        'impact-printer' => 'impact-printer-page-component.php',
    ];
    $source = (string) file_get_contents(isset($cmsComponents[$skin])
        ? dirname(__DIR__) . '/core/' . $cmsComponents[$skin]
        : dirname(__DIR__) . '/skins/' . $skin . '/layout.php');
    // A CMS-rendered skin may build part of its presentation from a SHARED
    // per-function component rather than inline markup -- the wall tile is
    // the first, so the page and the endless-scroll feed cannot emit
    // different tiles. The hook must still exist in CMS-owned presentation
    // code; it is only allowed to live in the component that owns it. The
    // rendered-output check at the end proves it reaches the page.
    if (isset($cmsComponents[$skin])) {
        $source .= (string) file_get_contents(dirname(__DIR__) . '/core/skin-render-helpers.php');
        $skinLayout = dirname(__DIR__) . '/skins/' . $skin . '/layout.php';
        if (is_file($skinLayout)) $source .= (string)file_get_contents($skinLayout);
    }
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

// Grepping source proves a hook EXISTS. This proves it REACHES THE PAGE:
// render the 50 Shades archive and inspect the actual markup.
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
$view = [
    'site' => ['language' => 'en', 'direction' => 'ltr', 'site_name' => 'x',
               'skin_style_url' => 'https://example.test/s.css', 'archive_layout' => 'masonry'],
    'response' => ['kind' => 'archive', 'navigation' => [], 'next_page' => 2,
                   'items' => [['img_title' => 'a', 'img_slug' => 'a',
                                'img_thumb_aspect' => 'https://example.test/a.jpg',
                                'img_width' => 3000, 'img_height' => 2000]]],
];
ob_start();
include dirname(__DIR__) . '/core/50-shades-page-component.php';
$fsogHtml = (string)ob_get_clean();
foreach (['ss-masonry-item', 'data-w="3000"', 'data-h="2000"', 'id="justified-grid"',
          'fsog-archive-item', 'scroll-wall-sentinel'] as $needle) {
    if (!str_contains($fsogHtml, $needle)) {
        throw new RuntimeException('50 Shades archive output lost: ' . $needle);
    }
}

echo "Public skin structure parity regression passed.\n";
