<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/core/public-controller.php');
$repository = (string)file_get_contents($root . '/core/public-repository.php');
foreach (['onyx_wall_page_size', 'scroll_page_size', 'htbs_grid_per_page', 'htbs_slider_assets',
    'htbs_slider_max', 'htbs_overlay_source', 'slider_items'] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('CMS control path missing: ' . $needle);
}
if (!str_contains($repository, 'function publicAssetsByIds(')
    || !str_contains($repository, 'SELECT id,asset_name,asset_path FROM snap_assets')) {
    throw new RuntimeException('Show-N-Tell selected media escaped the public repository.');
}
foreach (['galleria', 'hip-to-be-square'] as $skin) {
    $layout = (string)file_get_contents($root . '/skins/' . $skin . '/layout.php');
    $css = (string)file_get_contents($root . '/skins/' . $skin . '/style.css');
    foreach (['htbs_bevel_style', 'htbs_wood_grain', 'data-bevel', 'data-wood-grain'] as $needle) {
        if (!str_contains($layout, $needle)) throw new RuntimeException($skin . ' frame control missing: ' . $needle);
    }
    foreach (['[data-bevel="none"]', '[data-bevel="double"]', '[data-wood-grain="none"]'] as $needle) {
        if (!str_contains($css, $needle)) throw new RuntimeException($skin . ' frame behavior missing: ' . $needle);
    }
}
$show = (string)file_get_contents($root . '/skins/show-n-tell/layout.php');
foreach (["['response']['slider_items']", "['overlay_name']", "['overlay_tagline']"] as $needle) {
    if (!str_contains($show, $needle)) throw new RuntimeException('Show-N-Tell layout model missing: ' . $needle);
}

require_once $root . '/core/skin-presentation.php';
$media = snapsmack_skin_presentation(['header_image'=>'media/header.jpg','header_logo'=>'media/logo.png','retina_logo'=>'1','show_tagline'=>'0'], 'alfred');
if (($media['header_media'] ?? null) !== ['image'=>'/media/header.jpg','logo'=>'/media/logo.png','retina'=>true,'show_tagline'=>false]) {
    throw new RuntimeException('Header media controls did not cross the bounded CMS model.');
}
foreach (['alfred','telegram','tilez','stanley','writing-with-impact'] as $skin) {
    $layout = (string)file_get_contents($root . '/skins/' . $skin . '/layout.php');
    foreach (['header_media', "['logo']", "['show_tagline']"] as $needle) {
        if (!str_contains($layout, $needle)) throw new RuntimeException($skin . ' header control missing: ' . $needle);
    }
}
$writing = snapsmack_skin_presentation(['paper_style'=>'greenbar'], 'writing-with-impact');
if (($writing['writing']['paper'] ?? '') !== 'greenbar') throw new RuntimeException('Writing paper control is inert.');

echo "Remaining public control authority regression passed.\n";
