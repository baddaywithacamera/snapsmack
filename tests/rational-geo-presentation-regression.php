<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/skin-presentation.php';

$presentation = snapsmack_skin_presentation([
    'masthead_font' => 'Marcellus',
    'show_map_background' => '1',
    'map_opacity' => '30',
], 'rational-geo');
$style = (string)($presentation['style'] ?? '');
$layout = (string)file_get_contents(dirname(__DIR__) . '/skins/rational-geo/layout.php');
$pageComponent = (string)file_get_contents(dirname(__DIR__) . '/core/rational-geo-page-component.php');
$css = (string)file_get_contents(dirname(__DIR__) . '/skins/rational-geo/style.css');
$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/skins/rational-geo/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$inventory = include dirname(__DIR__) . '/core/manifest-inventory.php';

// A FLOOR, not a pin. Pinning the exact version made every legitimate
// skin release fail this test, so the cheap way out was to change the
// files and leave the version alone — which is exactly how sites ended
// up frozen on an old package under a current-looking version number.
if (version_compare((string)($manifest['version'] ?? '0'), '2.1.11', '<')) {
    throw new RuntimeException('RATIONAL GEO release must stay at or above 2.1.11.');
}
if (!isset($inventory['local_fonts']['Marcellus'])
    || ($inventory['local_fonts']['Marcellus']['file'] ?? '') !== 'assets/fonts/Marcellus/Marcellus-Regular.ttf') {
    throw new RuntimeException('Marcellus is absent from the bounded local font inventory.');
}
// The single-quoted form came from the @font-face block, which Marcellus no
// longer needs: it is in Google's catalogue, and the owner's rule is that the
// asset repository holds only what Google does not carry. The rule that styles
// the masthead is double-quoted and still emitted; delivery is asserted
// separately and by either route.
if (!str_contains($style, 'font-family:"Marcellus"')
    || !(str_contains($style, '/font.php?family=Marcellus')
         || (str_contains($style, 'fonts.googleapis.com') && str_contains($style, 'family=Marcellus')))) {
    throw new RuntimeException('The selected RATIONAL GEO masthead face is not delivered at all.');
}
if (!str_contains($style, '--rg-map-pct:30')
    || !str_contains($css, "background-image: url('assets/topo-map.png')")
    || !str_contains($css, 'opacity: calc(var(--rg-map-pct, 30) / 100)')) {
    throw new RuntimeException('RATIONAL GEO relief-map presentation no longer preserves its saved 30% intensity.');
}
if (!str_contains($layout, 'rg-map-hidden')) {
    throw new RuntimeException('RATIONAL GEO no longer honors the bounded map visibility setting.');
}
foreach (['rg-header-inside', 'rg-header-nav', 'rg-logo-link', 'rg-masthead'] as $hook) {
    if (!str_contains($layout, $hook)) {
        throw new RuntimeException('RATIONAL GEO lost its pre-strip landing-page hook: ' . $hook);
    }
}
foreach (["image_asset", "id=\"photobox\" class=\"page-hero\"", "'class' => 'post-image'"] as $pageContract) {
    if (!str_contains($pageComponent, $pageContract)) {
        throw new RuntimeException('RATIONAL GEO lost its original static-page contract: ' . $pageContract);
    }
}
if (!str_contains($layout, 'static-transmission') || !str_contains($layout, "snap_render_component('rational-geo-page', \$view)")) {
    throw new RuntimeException('RATIONAL GEO no longer delegates static pages to its bounded CMS component.');
}
if (!str_contains($css, '#rg-header {')
    || !preg_match('/#rg-header\s*\{[^}]*position:\s*relative;/s', $css)
    || str_contains($css, 'body.static-transmission {\n  padding-top: 70px;')) {
    throw new RuntimeException('RATIONAL GEO made its masthead fixed or put fixed-header compensation back on static pages.');
}
if (!str_contains($css, '--static-content-width: 1040px;')) {
    throw new RuntimeException('RATIONAL GEO lost the original wide static-page and page-hero measure.');
}
if (!str_contains($layout, "snap_render_component('wall-tile'")
    || !str_contains($layout, 'class="scroll-wall-sentinel"')
    || !str_contains($layout, "data-next=\"<?php echo snap_escape_attr(\$view['response']['next_page']); ?>\"")
    || !str_contains($layout, 'class="scroll-wall ss-masonry h-feed"')
    || !in_array('smack-lazyload', $manifest['require_scripts'] ?? [], true)
    || !in_array('smack-columns', $manifest['require_scripts'] ?? [], true)
    || !str_contains($css, '--ss-cols: var(--grid-cols, 4)')) {
    throw new RuntimeException('RATIONAL GEO archive no longer preserves its endless native-aspect masonry wall.');
}
if (!str_contains($layout, "skin_presentation']['style")) {
    throw new RuntimeException('RATIONAL GEO does not emit its bounded compiled presentation stylesheet.');
}
foreach (['--rg-bg-page:', '--rg-bg-chrome:', '--rg-text-primary:', '--rg-nav-color:'] as $variable) {
    if (!str_contains($css, $variable)) {
        throw new RuntimeException('RATIONAL GEO base CSS has no fail-safe palette value for ' . $variable);
    }
}
if (!str_contains($css, '.blogroll-peer:nth-child(-n + 2)')
    || !preg_match('/@media\s*\(max-width:\s*760px\)\s*\{[^}]*\.blogroll-peer:nth-child\(2\)\s*\{[^}]*padding-top:\s*2vw/s', $css)) {
    throw new RuntimeException('RATIONAL GEO blogroll first row no longer aligns without collapsing mobile spacing.');
}

echo "RATIONAL GEO presentation regression: PASS\n";
// ===== SNAPSMACK EOF =====
