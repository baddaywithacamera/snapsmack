<?php
/** SNAPSMACK_EOF_HEADER: actual active-skin preview with a real parsed mosaic. */
declare(strict_types=1);
define('BASE_URL', 'https://preview.invalid/');
require_once dirname(__DIR__) . '/core/admin-content-preview.php';

class PreviewFixturePDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new PreviewFixtureStatement($query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        $statement = new PreviewFixtureStatement($query); $statement->execute(); return $statement;
    }
    public function exec(string $statement): int|false { throw new RuntimeException('Preview attempted a database write'); }
}
class PreviewFixtureStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool {
        if (!preg_match('/^\s*(?:SELECT|SHOW)\b/i', $this->sql)) throw new RuntimeException('Preview attempted a database write');
        if (str_contains($this->sql, 'FROM snap_mosaics')) {
            $this->rows = [['asset_ids' => '[1,2,3,4,5]', 'focus_positions' => '{}', 'gap' => 4, 'emphasis' => 'natural', 'layout' => 'asymmetric']];
        } elseif (str_contains($this->sql, 'FROM snap_images WHERE id IN')) {
            for ($id = 1; $id <= 5; $id++) $this->rows[] = ['id' => $id, 'img_title' => 'Photo ' . $id,
                'img_alt' => 'Fixture photo', 'img_file' => 'fixture-' . $id . '.jpg', 'img_thumb_aspect' => '', 'img_width' => 1200, 'img_height' => 800];
        } else $this->rows = [];
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->rows[0] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return array_values($this->rows[0] ?? [0])[$column] ?? 0; }
}
$pdo = new PreviewFixturePDO();
$settings = ['active_skin' => 'tilez', 'site_name' => 'Preview fixture', 'site_author' => 'Photographer', 'site_url' => BASE_URL];
$html = snapsmack_admin_content_preview($pdo, $settings, ['preview_kind' => 'post', 'title' => 'UNSAVED TITLE',
    'content' => '<p>Unsaved paragraph.</p>[mosaic:489]', 'post_id' => 0]);
foreach (['class="post-content', 'class="post-record', 'UNSAVED TITLE', 'Unsaved paragraph.', 'snap-mosaic',
          'ss-engine-mosaic.js', 'ss-engine-mosaic.css', 'ss-engine-lightbox.js', 'PREVIEW — NOT PUBLISHED'] as $required) {
    if (!str_contains($html, $required)) throw new RuntimeException('Missing actual preview component: ' . $required);
}
if (!preg_match('/data-mosaic=(?:"([^"]+)"|\'([^\']+)\')/', $html, $match)) throw new RuntimeException('No parsed mosaic data');
$images = json_decode(html_entity_decode($match[1] !== '' ? $match[1] : $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
if (count($images ?? []) !== 5) throw new RuntimeException('Preview lost mosaic members');
if (!str_contains($html, '<dt>Photos</dt><dd>5</dd>')) throw new RuntimeException('Post photo count does not include the mosaic');
if (str_contains($html, 'max-width: 900px')) throw new RuntimeException('Fake preview wrapper survived');
$output = dirname(__DIR__) . '/.codex-temp/desktop-cli';
if (!is_dir($output)) mkdir($output, 0777, true);
file_put_contents($output . '/actual-post-preview.html', $html);
echo "Actual TILEZ post template, unsaved title/body, five-photo mosaic, registered engines and read-only rendering verified.\n";
// ===== SNAPSMACK EOF =====
