<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be the canonical PHP EOF marker.
 */

$source = (string)file_get_contents(__DIR__ . '/../core/public-repository.php');
$start = strpos($source, 'public function postBySlug');
$end = strpos($source, 'public function photographsForPost');
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Could not locate public post lookup methods.');
}
$lookups = substr($source, $start, $end - $start);

foreach (['content', 'colophon', 'signature_image_id', 'featured_image_id', 'user_id'] as $required) {
    if (!str_contains($lookups, $required)) {
        throw new RuntimeException("Public post lookup lost required field: {$required}");
    }
}

foreach (['description', 'updated_at', 'allow_download', 'download_url', 'panorama_rows', 'show_featured_image', 'trigram_id', 'cover_pos_x', 'cover_pos_y', 'cover_zoom', 'is_sensitive,content_warning'] as $optional) {
    if (str_contains($lookups, $optional)) {
        throw new RuntimeException("Public post lookup still requires unrelated optional field(s): {$optional}");
    }
}

$controller = (string)file_get_contents(__DIR__ . '/../core/smacktalk-public-controller.php');
$singleStart = strpos($controller, 'function snapsmack_smacktalk_single');
$singleEnd = strpos($controller, 'function snapsmack_smacktalk_feed');
$single = substr($controller, $singleStart, $singleEnd - $singleStart);
if (!str_contains($single, 'photographCoverById') || preg_match('/featured\s*=.*photographById/', $single)) {
    throw new RuntimeException('Story routing still expands a cover through the optional full photograph schema.');
}

$coverStart = strpos($source, 'public function photographCoverById');
$cover = substr($source, $coverStart, $start - $coverStart);
if (!str_contains($cover, '$this->imageAltSelect()') || !str_contains($cover, 'SELECT img_file, {$alt} AS img_alt FROM snap_images')) {
    throw new RuntimeException('Story cover requires more than the stable path and optional saved ALT.');
}

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    require_once __DIR__ . '/../core/public-repository.php';
    $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->sqliteCreateFunction('NOW', static fn() => '2026-11-26 12:00:00');
    $db->exec("CREATE TABLE snap_images (id INTEGER, img_file TEXT, img_status TEXT, img_date TEXT)");
    $db->exec("INSERT INTO snap_images VALUES (1, 'img/cover.jpg', 'published', '2026-11-25')");
    $repo = new SnapPublicRepository($db);
    if ($repo->photographCoverById(1) !== ['img_file' => 'img/cover.jpg', 'img_alt' => null]) {
        throw new RuntimeException('Legacy story cover disappears when ALT is unavailable.');
    }
    $db->exec('ALTER TABLE snap_images ADD COLUMN img_alt TEXT');
    $saved = 'Saved ALT, with & and <example>';
    $db->prepare('UPDATE snap_images SET img_alt=? WHERE id=1')->execute([$saved]);
    if ((new SnapPublicRepository($db))->photographCoverById(1)['img_alt'] !== $saved) {
        throw new RuntimeException('Fresh story cover lookup loses the saved ALT.');
    }
}

echo "PASS: public post lookup does not require unrelated optional post columns.\n";

// ===== SNAPSMACK EOF =====
