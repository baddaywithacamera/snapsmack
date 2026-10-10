<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
require_once $root . '/core/public-route-aliases.php';

$manifest = json_decode((string)file_get_contents($root . '/skins/slickr/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$layout = (string)file_get_contents($root . '/skins/slickr/layout.php');
$css = (string)file_get_contents($root . '/skins/slickr/style.css');
$controller = (string)file_get_contents($root . '/core/public-controller.php');
$repository = (string)file_get_contents($root . '/core/public-repository.php');

// A FLOOR, not a pin. Pinning the exact version made every legitimate
// skin release fail this test, so the cheap way out was to change the
// files and leave the version alone — which is exactly how sites ended
// up frozen on an old package under a current-looking version number.
if (version_compare((string)($manifest['version'] ?? '0'), '1.0.32', '<')) throw new RuntimeException('SLICKR version must stay at or above 1.0.32.');
foreach (['asset:public:page-static', 'asset:public:page-collection', 'asset:public:page-blogroll', 'asset:public:shortcodes'] as $style) {
    if (!in_array($style, $manifest['require_styles'] ?? [], true)) throw new RuntimeException("SLICKR missing shared style: {$style}");
}
$aliases = snapsmack_public_route_aliases([], $manifest);
foreach (['albums' => 'albums', 'collections' => 'collections'] as $slug => $route) {
    if (($aliases[$slug] ?? '') !== $route) throw new RuntimeException("SLICKR route missing: {$slug}");
}
foreach (["=== 'albums'", "=== 'collections'", "=== 'collection'", "=== 'blogroll'", "page-hero page-hero--", "\$view['response']['rows'] ?? []"] as $hook) {
    if (!str_contains($layout, $hook)) throw new RuntimeException("SLICKR strict layout missing: {$hook}");
}
if (!str_contains($css, 'border-radius: 999px;')) throw new RuntimeException('SLICKR search is no longer a pill.');
if (!str_contains($controller, "'results' => \$results, 'rows' => \$searchRows")) throw new RuntimeException('SLICKR search lacks CMS-prepared justified rows.');
foreach (['presentation_id', 'presentation_count', 'presentation_posted'] as $field) {
    if (!str_contains($controller, $field)) throw new RuntimeException("SLICKR provider missing: {$field}");
}
foreach (['photograph_count', 'latest_date', 'cover_path'] as $field) {
    if (!str_contains($repository, $field)) throw new RuntimeException("Collection provider missing: {$field}");
}

echo "SLICKR secondary-page restoration regression passed\n";
// ===== SNAPSMACK EOF =====
