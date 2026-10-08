<?php
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('tilez');

$css = file_get_contents(__DIR__ . '/../skins/tilez/style.css');
if (!is_string($css)
    || !str_contains($css, 'width: calc(50% - 10px) !important;')
    || !str_contains($css, 'margin-top: 4px;')
    || !str_contains($css, 'margin-bottom: 4px;')) {
    throw new RuntimeException('TILEZ consecutive post photographs can overflow and split their compact two-column wall.');
}
$manifest = json_decode((string) file_get_contents(__DIR__ . '/../skins/tilez/manifest.json'), true);
if (($manifest['version'] ?? '') !== '0.2.62') {
    throw new RuntimeException('TILEZ wall repair must ship as skin version 0.2.62.');
}
echo "PASS: tilez behavior is CMS-owned and its presentation contract is strict.
";
