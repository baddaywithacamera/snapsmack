<?php
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('tilez');

$css = file_get_contents(__DIR__ . '/../skins/tilez/style.css');
$mosaic = file_get_contents(__DIR__ . '/../assets/js/ss-engine-mosaic.js');
$layout = file_get_contents(__DIR__ . '/../skins/tilez/layout.php');
if (!is_string($css) || !is_string($mosaic) || !is_string($layout)
    || str_contains($layout, 'data-merge-adjacent-mosaics')
    || !str_contains($css, '.snap-inline-frame:has(+ .snap-inline-frame)')
    || !str_contains($css, '.content > .section-inner:has(> .tilez-posts)')
    || !str_contains($css, 'padding-bottom: 54px;')
    || substr_count($css, 'margin-bottom: 4px;') < 3
    || str_contains($css, 'width: calc(50% - 10px) !important;')) {
    throw new RuntimeException('TILEZ standalone post photographs must remain tightly stacked as separate blocks.');
}
$manifest = json_decode((string) file_get_contents(__DIR__ . '/../skins/tilez/manifest.json'), true);
// A FLOOR, not a pin. Pinning the exact version made every legitimate
// skin release fail this test, so the cheap way out was to change the
// files and leave the version alone — which is exactly how sites ended
// up frozen on an old package under a current-looking version number.
if (version_compare((string)($manifest['version'] ?? '0'), '0.2.71', '<')) {
    throw new RuntimeException('TILEZ feed/footer gutter repair must ship as skin version 0.2.71.');
}
echo "PASS: tilez behavior is CMS-owned and its presentation contract is strict.
";
