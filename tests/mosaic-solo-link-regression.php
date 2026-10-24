<?php
/** Regression: strict-view MOSAIC tiles must retain their solo-page links. */

function snap_route_url(string $kind, array $args = []): string {
    return $kind === 'photo' ? '/photo/' . rawurlencode((string)($args['slug'] ?? '')) : '/';
}
function snap_community_block($value = null, bool $set = false) { return null; }

require_once __DIR__ . '/../core/skin-view-contract.php';

$view = snapsmack_build_skin_view([
    'kind' => 'landing',
    'items' => [[
        'id' => 42,
        'img_slug' => 'rusty-chevrolet',
        'img_file' => '/img/rusty-chevrolet.jpg',
        'img_width' => 1200,
        'img_height' => 800,
    ]],
]);

$json = (string)($view['response']['mosaic_blocks'][0]['json'] ?? '');
$tiles = json_decode($json, true);
$href = (string)($tiles[0]['href'] ?? '');
if ($href !== '/photo/rusty-chevrolet') {
    fwrite(STDERR, "FAIL: MOSAIC solo link missing or wrong: {$href}\n");
    exit(1);
}

echo "PASS: strict MOSAIC tile carries its solo-page link.\n";
// ===== SNAPSMACK EOF =====
