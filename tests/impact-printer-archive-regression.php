<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');

$response = ['status'=>200,'kind'=>'archive','navigation'=>[],'next_page'=>2,'items'=>[[]]];
$response['items'][0] = ['img_title'=>'Portrait transmission','img_slug'=>'portrait-transmission','img_alt'=>'A portrait photograph','img_orientation'=>1,'img_thumb_square'=>'/media/square.jpg','img_thumb_aspect'=>'/media/aspect.jpg','img_file'=>'/media/full.jpg'];

$render = static function (string $layout, string $thumbStyle) use ($response): string {
    $view = snapsmack_build_skin_view($response, ['site_name'=>'Pixhellated','language'=>'en','direction'=>'ltr','base_url'=>'/','skin_style_url'=>'/skins/impact-printer/style.css','archive_layout'=>$layout,'archive_thumb_style'=>$thumbStyle,'skin_presentation'=>[],'footer'=>[],'registered_assets'=>[]]);
    ob_start(); include dirname(__DIR__) . '/skins/impact-printer/layout.php'; return (string)ob_get_clean();
};

$cropped = $render('thumbs', 'cropped');
foreach (['cropped-grid archive-grid archive-layout-cropped','thumb-container h-entry','thumb-link orient-portrait','/media/aspect.jpg'] as $needle) if (!str_contains($cropped,$needle)) throw new RuntimeException('IMPACT PRINTER cropped archive lost: '.$needle);
if (!str_contains($cropped, 'class="scroll-wall-sentinel" data-next="2"')) throw new RuntimeException('IMPACT PRINTER thumbnail archive lost its next-page transport.');
if (str_contains($cropped,'<h2>') || str_contains($cropped,'ip-ascii-frame snap-inline-frame')) throw new RuntimeException('IMPACT PRINTER archive reintroduced replacement card/title markup.');

// ── Orientation ─────────────────────────────────────────────────────────────
// Every tile on pixhellated.ca came back orient-landscape, portraits included,
// because SnapPublicRepository::archivePhotographs() never selected
// img_orientation and the component defaulted a missing value to landscape.
// A portrait photograph squeezed into a 3:2 box is the visible half of that.
$orientations = static function (string $html): array {
    preg_match_all('/orient-([a-z]+)/', $html, $m);
    return array_count_values($m[1]);
};
$mixed = ['status' => 200, 'kind' => 'archive', 'navigation' => [], 'next_page' => 2, 'items' => [
    ['img_title' => 'P', 'img_slug' => 'p', 'img_orientation' => 1, 'img_thumb_aspect' => '/a.jpg'],
    ['img_title' => 'S', 'img_slug' => 's', 'img_orientation' => 2, 'img_thumb_aspect' => '/b.jpg'],
    ['img_title' => 'L', 'img_slug' => 'l', 'img_orientation' => 0, 'img_thumb_aspect' => '/c.jpg'],
]];
$mixedView = snapsmack_build_skin_view($mixed, ['site_name' => 'Pixhellated', 'language' => 'en',
    'direction' => 'ltr', 'base_url' => '/', 'skin_style_url' => '/skins/impact-printer/style.css',
    'archive_layout' => 'thumbs', 'archive_thumb_style' => 'cropped',
    'archive_grid' => ['cols' => 5, 'step' => 'l', 'thumb_width' => 310],
    'skin_presentation' => [], 'footer' => [], 'registered_assets' => []]);
ob_start();
$view = $mixedView;
include dirname(__DIR__) . '/skins/impact-printer/layout.php';
$mixedHtml = (string)ob_get_clean();

$counts = $orientations($mixedHtml);
if (($counts['portrait'] ?? 0) !== 1 || ($counts['square'] ?? 0) !== 1 || ($counts['landscape'] ?? 0) !== 1) {
    throw new RuntimeException('IMPACT PRINTER archive lost per-photograph orientation: '
        . json_encode($counts));
}

// ── Grid sizing ─────────────────────────────────────────────────────────────
// The owner's column count and thumbnail step reach the stylesheet as classes.
// They used to arrive as --grid-cols/--thumb-width in a style attribute and
// the controller-rendered page sent neither, so every tile fell back to 200px
// with an 80px gap regardless of the setting.
foreach (['archive-cols-5', 'archive-thumbs-l'] as $needle) {
    if (!str_contains($mixedHtml, $needle)) {
        throw new RuntimeException('IMPACT PRINTER archive does not carry its grid sizing: ' . $needle);
    }
}
if (str_contains($mixedHtml, 'style="--grid-cols')) {
    throw new RuntimeException('Grid sizing went back into a style attribute.');
}

$square = $render('thumbs', 'square');
foreach (['square-grid archive-grid archive-layout-square','/media/square.jpg'] as $needle) if (!str_contains($square,$needle)) throw new RuntimeException('IMPACT PRINTER square archive lost: '.$needle);

$masonry = $render('masonry', 'cropped');
foreach (['ss-scroll-wall archive-layout-masonry','class="ss-masonry-item"','/media/aspect.jpg'] as $needle) if (!str_contains($masonry,$needle)) throw new RuntimeException('IMPACT PRINTER masonry archive lost: '.$needle);

echo "IMPACT PRINTER archive regression passed.\n";
