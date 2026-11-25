<?php
declare(strict_types=1);
// SNAPSMACK_EOF_HEADER
// Last non-empty line must be the PHP SNAPSMACK EOF marker.

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP mosaic tile sizes: PDO SQLite is unavailable.\n";
    exit(0);
}

require_once __DIR__ . '/../core/trusted-html.php';
require_once __DIR__ . '/../core/parser.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE snap_mosaics (
    id INTEGER PRIMARY KEY, asset_ids TEXT, focus_positions TEXT, gap INTEGER,
    emphasis TEXT, layout TEXT, gallery_shuffle INTEGER DEFAULT 0
)");
$pdo->exec("CREATE TABLE snap_images (
    id INTEGER PRIMARY KEY, img_file TEXT, img_thumb_aspect TEXT,
    img_title TEXT, img_alt TEXT, img_width INTEGER, img_height INTEGER
)");
$pdo->exec("INSERT INTO snap_images VALUES
    (8, 'media/first.jpg', '', 'First', '', 1200, 800),
    (9, 'media/second.jpg', '', 'Second', '', 800, 1200),
    (10, 'media/third.jpg', '', 'Third', '', 1200, 800)");
$pdo->exec("INSERT INTO snap_mosaics (id, asset_ids, gap, layout) VALUES
    (1, '[8,10,9]', 0, 'asymmetric'), (2, '[8,10,9]', 4, 'masonry')");
$parser = new SnapSmack($pdo);
$render = static fn(int $id): string => (string)$parser->parseContent('[mosaic:' . $id . ']');
$before = [$render(1), $render(2)];
$pdo->exec('ALTER TABLE snap_mosaics ADD COLUMN mosaic_weights TEXT');
if ($before !== [$render(1), $render(2)]) {
    throw new RuntimeException('Adding the optional column changed an unweighted mosaic.');
}

$pdo->exec("UPDATE snap_mosaics SET mosaic_weights = '{\"9\":1,\"8\":-1,\"77\":2}' WHERE id = 2");
$html = $render(2);
if (!preg_match('/data-mosaic="([^"]+)"/', $html, $match)) {
    throw new RuntimeException('The resized mosaic must reach the shared editorial compositor.');
}
$tiles = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
if (array_column($tiles, 'id') !== [8, 10, 9] ||
    array_map(static fn(array $tile): int => $tile['weight'] ?? 0, $tiles) !== [-1, 0, 1]) {
    throw new RuntimeException('A size moved to the wrong photograph or changed authored order.');
}
$pdo->exec('UPDATE snap_mosaics SET gallery_shuffle = 1 WHERE id = 2');
for ($i = 0; $i < 8; $i++) {
    preg_match('/data-mosaic="([^"]+)"/', $render(2), $match);
    $tiles = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
    $sizes = [];
    foreach ($tiles as $tile) $sizes[$tile['id']] = $tile['weight'] ?? 0;
    ksort($sizes);
    if ($sizes !== [8 => -1, 9 => 1, 10 => 0]) {
        throw new RuntimeException('A tile size moved to a different image when shuffled.');
    }
}
echo "PASS mosaic tile sizes follow image IDs through render and shuffle. Legacy markup is unchanged.\n";
// ===== SNAPSMACK EOF =====
