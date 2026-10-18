<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');

$response = ['status'=>200,'kind'=>'archive','navigation'=>[],'items'=>[[]]];
$response['items'][0] = ['img_title'=>'Portrait transmission','img_slug'=>'portrait-transmission','img_alt'=>'A portrait photograph','img_orientation'=>1,'img_thumb_square'=>'/media/square.jpg','img_thumb_aspect'=>'/media/aspect.jpg','img_file'=>'/media/full.jpg'];

$render = static function (string $layout, string $thumbStyle) use ($response): string {
    $view = snapsmack_build_skin_view($response, ['site_name'=>'Pixhellated','language'=>'en','direction'=>'ltr','base_url'=>'/','skin_style_url'=>'/skins/impact-printer/style.css','archive_layout'=>$layout,'skin_presentation'=>['options'=>['archive_thumb_style'=>$thumbStyle]],'footer'=>[],'registered_assets'=>[]]);
    ob_start(); include dirname(__DIR__) . '/skins/impact-printer/layout.php'; return (string)ob_get_clean();
};

$cropped = $render('thumbs', 'cropped');
foreach (['cropped-grid archive-grid archive-layout-cropped','thumb-container h-entry','thumb-link orient-portrait','/media/aspect.jpg'] as $needle) if (!str_contains($cropped,$needle)) throw new RuntimeException('IMPACT PRINTER cropped archive lost: '.$needle);
if (str_contains($cropped,'<h2>') || str_contains($cropped,'ip-ascii-frame snap-inline-frame')) throw new RuntimeException('IMPACT PRINTER archive reintroduced replacement card/title markup.');

$square = $render('thumbs', 'square');
foreach (['square-grid archive-grid archive-layout-square','/media/square.jpg'] as $needle) if (!str_contains($square,$needle)) throw new RuntimeException('IMPACT PRINTER square archive lost: '.$needle);

$masonry = $render('masonry', 'cropped');
foreach (['ss-scroll-wall archive-layout-masonry','class="ss-masonry-item"','/media/aspect.jpg'] as $needle) if (!str_contains($masonry,$needle)) throw new RuntimeException('IMPACT PRINTER masonry archive lost: '.$needle);

echo "IMPACT PRINTER archive regression passed.\n";
