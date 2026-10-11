<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';

$model = [
    'identity' => 'SCROLL', 'home_url' => '/', 'icon_sprite_url' => '/assets/icons/crop-circles.svg',
    'navigation' => [
        ['label'=>'Home','url'=>'/'], ['label'=>'About','url'=>'/about'], ['label'=>'Blogroll','url'=>'/blogroll'],
    ],
    'search' => ['action'=>'/','placeholder'=>'Search or #tag…'],
    'filters' => [['label'=>'categories','type'=>'cat','items'=>[['id'=>1,'label'=>'Cars']]]],
    'social' => [
        ['url'=>'https://example.com/a','label'=>'Vero','icon'=>'vero'],
        ['url'=>'https://example.com/b','label'=>'Bluesky','icon'=>'bluesky'],
        ['url'=>'https://example.com/c','label'=>'Website','icon'=>'website'],
    ],
    'appearance' => ['icon'=>'#1a1a1a','background'=>'rgba(255,255,255,.7)','border'=>'rgba(26,26,26,.3)','opacity'=>.5],
];
$component = (string)snap_render_component('crop-circles', ['model'=>$model]);
foreach (['ss-grid-nav-links','scroll-nav-search','scroll-nav-filter','ss-grid-nav-actions','social-dock-inline','#home','#about','#blogroll','#search','#filter','#vero','#bluesky','#website'] as $needle) {
    if (!str_contains($component, $needle)) throw new RuntimeException("Crop Circles lost {$needle}.");
}

$view = snapsmack_build_skin_view([
    'kind'=>'photo','status'=>200,'navigation'=>$model['navigation'],'item'=>[
        'img_title'=>'Fixture','img_file'=>'/fixture.jpg','img_alt'=>'Fixture','img_description'=>snapsmack_trusted_html('<p>Fixture</p>'),
    ], 'comments'=>[], 'comments_enabled'=>true,
], [
    'site_name'=>'SCROLL','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/scroll/style.css',
    'skin_custom_style'=>snapsmack_trusted_html(''),'skin_presentation'=>[],'crop_circles'=>$model,
    'registered_assets'=>[],'owner_custom_code'=>snapsmack_trusted_html(''),
]);
ob_start();
$ok = snapsmack_render_strict_skin_template($root . '/skins/scroll', 'layout.php', $view);
$html = (string)ob_get_clean();
foreach (['id="scroll-stage"','scroll-solo-photobox','id="infobox"','id="footer"','ss-grid-nav-always-visible'] as $needle) {
    if (!$ok || !str_contains($html, $needle)) throw new RuntimeException("SCROLL solo parity lost {$needle}.");
}
echo "Crop Circles and SCROLL solo parity: PASS\n";
