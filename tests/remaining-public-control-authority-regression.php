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
    $layout = (string)file_get_contents($skin === 'galleria'
        ? $root . '/core/galleria-page-component.php'
        : $root . '/skins/' . $skin . '/layout.php');
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
$tilezDefault = snapsmack_skin_presentation([], 'tilez');
if (($tilezDefault['header_media']['logo'] ?? '') !== '') {
    throw new RuntimeException('TILEZ injects site-specific branding without a configured logo.');
}
foreach (['alfred','telegram','tilez','stanley','writing-with-impact'] as $skin) {
    $layout = (string)file_get_contents($root . '/skins/' . $skin . '/layout.php');
    foreach (['header_media', "['logo']", "['show_tagline']"] as $needle) {
        if (!str_contains($layout, $needle)) throw new RuntimeException($skin . ' header control missing: ' . $needle);
    }
}
$writing = snapsmack_skin_presentation(['paper_style'=>'greenbar'], 'writing-with-impact');
if (($writing['writing']['paper'] ?? '') !== 'greenbar') throw new RuntimeException('Writing paper control is inert.');
$manifestDefaults = snapsmack_skin_presentation([], 'writing-with-impact');
if (($manifestDefaults['options']['paper_style'] ?? null) !== 'plain'
    || ($manifestDefaults['writing']['paper'] ?? null) !== 'plain') {
    throw new RuntimeException('Writing paper default no longer originates in its manifest.');
}
$helperSource = (string)file_get_contents($root . '/core/public-skin-presentation.php');
foreach (["?? 'yellow'", ": 'landscape'", ": 'plain'", 'bool $default=true'] as $duplicate) {
    if (str_contains($helperSource, $duplicate)) throw new RuntimeException('Presentation helper duplicated a manifest default: ' . $duplicate);
}
$rationalOff = snapsmack_skin_presentation(['show_map_background'=>'0','single_show_description'=>'0','single_show_signals'=>'0','hero_border_width'=>'999','image_border_color'=>'none'], 'rational-geo')['rational'];
if ($rationalOff['show_map'] || $rationalOff['show_description'] || $rationalOff['show_signals']
    || $rationalOff['border_width'] !== 30 || $rationalOff['border_color'] !== 'transparent') {
    throw new RuntimeException('Rational Geo min/max/on/off model failed.');
}
$rationalMin = snapsmack_skin_presentation(['infobox_height'=>'30'], 'rational-geo')['rational'];
$rationalMax = snapsmack_skin_presentation(['infobox_height'=>'100'], 'rational-geo')['rational'];
if ($rationalMin['infobox_height'] !== 30 || $rationalMax['infobox_height'] !== 100) {
    throw new RuntimeException('Rational Geo infobox height endpoints are not authoritative.');
}
$slickrOff = snapsmack_skin_presentation(['single_show_description'=>'0','show_exif_panel'=>'0','show_geo_link'=>'0','show_provenance_footer'=>'0'], 'slickr')['slickr'];
if (array_filter($slickrOff)) throw new RuntimeException('Slickr off controls failed.');
foreach (['photogram'=>'discover','rational-geo'=>'rational','scroll'=>'scroll','slickr'=>'slickr'] as $skin=>$model) {
    $layout = (string)file_get_contents($root . '/skins/' . $skin . '/layout.php');
    if (!str_contains($layout, "['skin_presentation']['{$model}']")) throw new RuntimeException($skin . ' does not consume its bounded presentation model.');
}
$runtime = (string)file_get_contents($root . '/core/public-runtime.php');
foreach (['img_exif', 'geo_url', 'FILTER_VALIDATE_FLOAT'] as $needle) {
    if (!str_contains($runtime, $needle)) throw new RuntimeException('Slickr CMS metadata model missing: ' . $needle);
}

echo "Remaining public control authority regression passed.\n";
