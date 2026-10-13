<?php
declare(strict_types=1);

define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$response = [
    'status' => 200,
    'kind' => 'archive',
    'items' => [[
        'img_title' => 'Portrait',
        'img_slug' => 'portrait',
        'img_thumb_square' => '/media/square.jpg',
        'img_thumb_aspect' => '/media/natural.jpg',
        'img_file' => '/media/original.jpg',
    ]],
    'navigation' => [],
];

$render = static function (string $layout) use ($response): string {
    $view = snapsmack_build_skin_view($response, [
        'site_name' => 'Example',
        'language' => 'en',
        'direction' => 'ltr',
        'skin_style_url' => '/skins/50-shades-of-noah-grey/style.css',
        'skin_presentation' => ['options' => ['masonry_layout' => 'rows']],
        'archive_layout' => $layout,
    ]);
    ob_start();
    include dirname(__DIR__) . '/core/50-shades-page-component.php';
    return (string)ob_get_clean();
};

$square = $render('square');
if (!str_contains($square, 'data-archive-layout="thumbs"')
    || !str_contains($square, 'data-archive-thumbnail-shape="square"')
    || !str_contains($square, 'src="/media/square.jpg"')) {
    throw new RuntimeException('50 Shades square archive no longer uses square thumbnails.');
}

$cropped = $render('cropped');
if (!str_contains($cropped, 'data-archive-layout="thumbs"')
    || !str_contains($cropped, 'data-archive-thumbnail-shape="natural"')
    || !str_contains($cropped, 'src="/media/natural.jpg"')) {
    throw new RuntimeException('50 Shades natural cropped archive fell back to square thumbnails.');
}

$masonry = $render('masonry');
if (!str_contains($masonry, 'data-archive-layout="masonry"')
    || !str_contains($masonry, 'class="ss-scroll-wall"')
    || !str_contains($masonry, 'src="/media/natural.jpg"')) {
    throw new RuntimeException('50 Shades masonry archive no longer opens its native-aspect wall.');
}

echo "50 Shades archive layout regression passed.\n";
