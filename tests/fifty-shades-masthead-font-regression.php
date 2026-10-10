<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/skin-presentation.php';
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
define('SNAPSMACK_SKIN_RENDER', true);

$root = dirname(__DIR__);
$presentation = snapsmack_skin_presentation([
    'header_font_family' => 'Merriweather',
], '50-shades-of-noah-grey');
$style = (string)($presentation['style'] ?? '');
$manifest = json_decode((string)file_get_contents($root . '/skins/50-shades-of-noah-grey/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$inventory = include $root . '/core/manifest-inventory.php';

if (($manifest['version'] ?? '') !== '1.5.2') {
    throw new RuntimeException('50 SHADES OF NOAH GREY release is not sequentially versioned at 1.5.2.');
}
if (!isset($inventory['local_fonts']['Merriweather'])
    || ($inventory['local_fonts']['Merriweather']['file'] ?? '') !== 'assets/fonts/Merriweather/Merriweather-Regular.ttf') {
    throw new RuntimeException('Merriweather is absent from the bounded local font inventory.');
}
if (!str_contains($style, 'font-family:"Merriweather", sans-serif')
    || !str_contains($style, "font-family:'Merriweather'")
    || !str_contains($style, '/font.php?family=Merriweather')) {
    throw new RuntimeException('The selected 50 SHADES masthead face is not delivered through the same-origin provider.');
}
if (!is_file($root . '/assets/fonts/Merriweather/Merriweather-Regular.ttf')
    || !is_file($root . '/assets/fonts/Merriweather/OFL.txt')) {
    throw new RuntimeException('The Merriweather face or its licence is missing from the shared asset library.');
}

$view = snapsmack_build_skin_view(
    ['status'=>200,'kind'=>'landing','items'=>[],'navigation'=>[]],
    ['site_name'=>'Photowalk.ing','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/50-shades-of-noah-grey/style.css','skin_presentation'=>$presentation]
);
ob_start();
$rendered = snapsmack_render_strict_skin_template($root . '/skins/50-shades-of-noah-grey', 'layout.php', $view);
$html = (string)ob_get_clean();
if (!$rendered || !str_contains($html, 'font-family:"Merriweather", sans-serif')
    || !str_contains($html, '/font.php?family=Merriweather')) {
    throw new RuntimeException('The 50 SHADES layout does not emit its generated masthead font presentation.');
}

$photoView = snapsmack_build_skin_view(
    ['status'=>200,'kind'=>'photo','item'=>['id'=>1,'img_title'=>'Photo','img_file'=>'/photo.jpg'],'comments'=>[],'navigation'=>[]],
    ['site_name'=>'Photowalk.ing','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/50-shades-of-noah-grey/style.css','skin_presentation'=>$presentation]
);
ob_start();
$photoRendered = snapsmack_render_strict_skin_template($root . '/skins/50-shades-of-noah-grey', 'layout.php', $photoView);
$photoHtml = (string)ob_get_clean();
if (!$photoRendered || !str_contains($photoHtml, '</div></article><div id="infobox">')) {
    throw new RuntimeException('The 50 SHADES information panels are inside the flexing photo stage and collapse the photograph.');
}

echo "50 SHADES masthead font regression: PASS\n";
// ===== SNAPSMACK EOF =====
