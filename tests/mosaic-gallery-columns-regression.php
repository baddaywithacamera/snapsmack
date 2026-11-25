<?php
declare(strict_types=1);
// SNAPSMACK_EOF_HEADER
// Last non-empty line must be the PHP SNAPSMACK EOF marker.

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP gallery layout render: PDO SQLite is unavailable.\n";
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
    (7, 'media/portrait.jpg', '', 'Portrait', '', 800, 1200),
    (9, 'media/panorama.jpg', '', 'Panorama', '', 2100, 700)");
$save = $pdo->prepare('INSERT INTO snap_mosaics (id, asset_ids, layout, gap) VALUES (?, ?, ?, 10)');
$save->execute([1, '[9,7]', 'masonry']);
$save->execute([2, '[9,7]', 'one-column']);
$save->execute([3, '[9,7]', 'columns']);
$parser = new SnapSmack($pdo);

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
foreach ([1 => 3, 2 => 1, 3 => null] as $id => $columns) {
    $html = (string)$parser->parseContent('[mosaic:' . $id . ']');
    $check(str_contains($html, 'ss-masonry snap-mosaic-wall'), 'Gallery does not use the existing columns engine');
    $check($columns === null
        ? !str_contains($html, '--ss-cols:')
        : str_contains($html, '--ss-cols:' . $columns . ';'), 'Wrong column sizing');
    $check(str_contains($html, '--ss-gap:10px;'), 'Authored gutter lost');
    preg_match_all('~href="[^"]*/(panorama|portrait)\.jpg"~', $html, $matches);
    $check($matches[1] === ['panorama', 'portrait'], 'Image order changed');
    $check(str_contains($html, 'data-w="2100" data-h="700"'), 'Native panorama ratio lost');
}
$wide = (string)$parser->parseContent('[mosaic:1 width=260]');
$check(str_contains($wide, 'data-column-width="260"'), 'Explicit gallery width lost');
$safe = (string)snapsmack_trusted_html($wide);
$check(str_contains($safe, 'data-columns="3"') && str_contains($safe, 'data-gap="10"')
    && str_contains($safe, 'data-column-width="260"') && str_contains($safe, 'data-w="2100"'),
    'Strict rich-text rendering must preserve gallery geometry');
$check(!str_contains($safe, ' style='), 'Strict rich text must still filter inline CSS');
$check(!str_contains((string)$parser->parseContent('[mosaic:1]'), 'data-column-width='),
    'Unsized gallery should remain fluid outside the skin');
$check(!str_contains((string)$parser->parseContent('[mosaic:3 width=260]'), 'data-column-width='),
    'Ordinary columns should not get the portfolio sizing override');
echo "PASS gallery masonry and full-width layouts preserve authored order.\n";
// ===== SNAPSMACK EOF =====
