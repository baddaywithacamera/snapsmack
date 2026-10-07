<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/public-runtime.php';

$items = [];
for ($i = 1; $i <= 6001; $i++) {
    $items[] = [
        'id' => $i,
        'img_width' => 600 + ($i % 7) * 100,
        'img_height' => 400 + ($i % 5) * 50,
    ];
}

$settings = ['justified_row_height' => 240, 'main_canvas_width' => 1400];
$first = snapsmack_justified_rows($items, $settings, 1);
$second = snapsmack_justified_rows($items, $settings, 2);

if (($first['total_pages'] ?? 0) < 2 || count($first['rows'] ?? []) !== 25) {
    throw new RuntimeException('Justified feed did not produce bounded row pages.');
}
$firstId = (int)($first['rows'][0]['items'][0]['id'] ?? 0);
$secondId = (int)($second['rows'][0]['items'][0]['id'] ?? 0);
if ($firstId < 1 || $secondId <= $firstId) {
    throw new RuntimeException('Justified feed page two repeated page one.');
}
$lastPage = snapsmack_justified_rows($items, $settings, (int)$first['total_pages']);
$lastId = 0;
foreach (($lastPage['rows'] ?? []) as $row) {
    foreach (($row['items'] ?? []) as $item) $lastId = max($lastId, (int)($item['id'] ?? 0));
}
if ($lastId !== 6001) {
    throw new RuntimeException('Justified feed stranded photographs beyond the former 5,000-item ceiling.');
}

$request = snapsmack_public_parse_request([
    'route' => 'archive', 'page' => 3, 'category_id' => 7, 'album_id' => 11,
]);
if (($request['page'] ?? 0) !== 3 || ($request['category_id'] ?? 0) !== 7 || ($request['album_id'] ?? 0) !== 11) {
    throw new RuntimeException('CMS archive request lost its former skin-owned filters.');
}
$aliased = snapsmack_public_runtime_request([
    'slug' => 'photos', 'route_aliases' => ['photos' => 'archive'], 'page' => 4,
    'category_id' => 9, 'album_id' => 13,
]);
if (($aliased['route'] ?? '') !== 'archive' || ($aliased['page'] ?? 0) !== 4
    || ($aliased['category_id'] ?? 0) !== 9 || ($aliased['album_id'] ?? 0) !== 13) {
    throw new RuntimeException('Aliased CMS archive route dropped its bounded filters.');
}
$technical = snapsmack_photo_technical_details(['img_exif' => json_encode([
    'Model' => 'Test Camera', 'FNumber' => '28/10', 'ExposureTime' => '1/125',
    'ISOSpeedRatings' => '400', 'FocalLength' => '50/1', 'lens' => 'Test Lens',
])]);
foreach (['Camera', 'Lens', 'Aperture', 'Shutter', 'ISO', 'Focal Length'] as $label) {
    if (!isset($technical[$label])) throw new RuntimeException("CMS photo detail lost {$label} formatting.");
}

$index = (string)file_get_contents(dirname(__DIR__) . '/index.php');
if (!str_contains($index, "\$_GET['page'] ?? (\$_GET['p'] ?? 1)")) {
    throw new RuntimeException('Historical ?p=N feed paging is not normalized by the CMS entry point.');
}
$repository = (string)file_get_contents(dirname(__DIR__) . '/core/public-repository.php');
if (!str_contains($repository, 'ORDER BY sort_order ASC,img_date DESC,id DESC')) {
    throw new RuntimeException('Justified feed lost its established manual/date ordering.');
}
foreach (['archivePhotographCount', 'photographAlbums', 'photographTags'] as $method) {
    if (!str_contains($repository, 'function ' . $method)) {
        throw new RuntimeException("Public repository does not supply {$method} through the CMS boundary.");
    }
}

echo "PASS: CMS justified feeds are complete, stable, and historically page-compatible.\n";
// ===== SNAPSMACK EOF =====
