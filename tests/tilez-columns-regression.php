<?php
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('tilez');

$css = file_get_contents(__DIR__ . '/../skins/tilez/style.css');
$mosaic = file_get_contents(__DIR__ . '/../assets/js/ss-engine-mosaic.js');
if (!is_string($css) || !is_string($mosaic)
    || !str_contains($css, 'promoted by ss-engine-mosaic.js')
    || !str_contains($mosaic, 'function promoteInlineImageRuns(scope)')
    || !str_contains($mosaic, "mosaic.className = 'snap-mosaic snap-mosaic--promoted-run'")
    || str_contains($css, 'width: calc(50% - 10px) !important;')) {
    throw new RuntimeException('TILEZ consecutive post photographs must become one exact-packed asymmetric mosaic.');
}
$manifest = json_decode((string) file_get_contents(__DIR__ . '/../skins/tilez/manifest.json'), true);
if (($manifest['version'] ?? '') !== '0.2.63') {
    throw new RuntimeException('TILEZ asymmetric post-wall repair must ship as skin version 0.2.63.');
}
echo "PASS: tilez behavior is CMS-owned and its presentation contract is strict.
";
