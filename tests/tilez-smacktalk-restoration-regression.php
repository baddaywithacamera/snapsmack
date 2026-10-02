<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repo = file_get_contents($root . '/core/public-repository.php');
$controller = file_get_contents($root . '/core/smacktalk-public-controller.php');
$viewModel = file_get_contents($root . '/core/skin-view-model.php');
$layout = file_get_contents($root . '/skins/tilez/layout.php');
$css = file_get_contents($root . '/skins/tilez/style.css');
$manifest = json_decode((string)file_get_contents($root . '/skins/tilez/manifest.json'), true);

if (!is_array($manifest)) throw new RuntimeException('TILEZ manifest is invalid JSON.');
if (!str_contains($repo, 'p.signature_image_id=i.id')) {
    throw new RuntimeException('Signature-only media can leak into the public image archive.');
}
foreach (["img_thumb_aspect", "is_file(dirname(__DIR__)", "'width'", "'height'"] as $hook) {
    if (!str_contains($controller, $hook)) throw new RuntimeException("Archive media validation missing: {$hook}");
}
foreach (['alfred-archive-grid ss-masonry', 'alfred-archive-tile ss-masonry-item', 'data-w=', 'data-h='] as $hook) {
    if (!str_contains($layout, $hook)) throw new RuntimeException("TILEZ archive lost shared columns hook: {$hook}");
}
foreach (['archive_columns', 'archive_gap'] as $key) {
    if (!isset($manifest['options'][$key])) throw new RuntimeException("TILEZ archive control missing: {$key}");
}
if (!str_contains($viewModel, "blogroll\\.php") || !str_contains($viewModel, "type = 'blogroll'")) {
    throw new RuntimeException('Old configured Blogroll URLs are not normalized for strict SMACKTALK skins.');
}
if (!str_contains($css, '.alfred-archive-grid {') || !str_contains($css, '--ss-cols: 5')) {
    throw new RuntimeException('TILEZ archive no longer defaults to the smaller asymmetric wall.');
}

echo "TILEZ SMACKTALK restoration regression passed\n";

