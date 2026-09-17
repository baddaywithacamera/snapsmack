<?php
/**
 * 721D: a skin can declare "min_images" and refuse to become active below it.
 * GLIDE and SLIDERS (the moving photo wall) declare 200 — under that the wall
 * is rows of one tile and reads as broken. Sean, 2026-09-17: "make that skin
 * refuse to install with less than 200 images."
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
require dirname(__DIR__) . '/core/mode-guard.php';

class MinImgFakePDO extends PDO {
    public function __construct(private int $count) {}
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        return new MinImgFakeStmt($this->count);
    }
}
class MinImgFakeStmt extends PDOStatement {
    public function __construct(private int $count) {}
    public function fetchColumn(int $c = 0): mixed { return $this->count; }
}

$fails = [];
$check = function (string $name, bool $ok) use (&$fails) { if (!$ok) $fails[] = $name; };

$glide = ['name' => 'GLIDE', 'min_images' => 200];
$check('below minimum refuses, names the skin and both numbers',
    ($m = snap_skin_min_images_conflict(new MinImgFakePDO(45), $glide, 'glide')) !== null
    && str_contains($m, 'GLIDE') && str_contains($m, '200') && str_contains($m, '45'));
$check('exactly the minimum passes', snap_skin_min_images_conflict(new MinImgFakePDO(200), $glide, 'glide') === null);
$check('above passes', snap_skin_min_images_conflict(new MinImgFakePDO(250), $glide, 'glide') === null);
$check('a skin with no minimum never refuses', snap_skin_min_images_conflict(new MinImgFakePDO(0), ['name' => 'PARADE'], 'parade') === null);

foreach (['glide', 'sliders'] as $slug) {
    $m = json_decode(file_get_contents(dirname(__DIR__) . "/skins/$slug/manifest.json"), true);
    $check("$slug manifest declares min_images 200", (int)($m['min_images'] ?? 0) === 200);
}
$src = file_get_contents(dirname(__DIR__) . '/smack-skin.php');
$check('gallery ACTIVATE path checks the minimum', substr_count($src, 'snap_skin_min_images_conflict(') >= 2);
$check('save-settings path checks the minimum before persisting active_skin',
    strpos($src, 'snap_skin_min_images_conflict($pdo, is_array($_requested_data)') < strpos($src, "->execute([\$active_skin, \$active_skin]);"));
// The wall draws 200 at random from the whole archive; no small-archive fill.
$check('GLIDE wall is random, 200', str_contains(file_get_contents(dirname(__DIR__) . '/skins/glide/landing.php'), 'ORDER BY RAND()') && str_contains(file_get_contents(dirname(__DIR__) . '/skins/glide/landing.php'), '$limit = 200;'));
$check('SLIDERS wall is random, 200', str_contains(file_get_contents(dirname(__DIR__) . '/skins/sliders/skin-profile.php'), 'ORDER BY RAND() LIMIT 200'));
$check('GLIDE wall has no inventory-cycling fill', !str_contains(file_get_contents(dirname(__DIR__) . '/skins/glide/landing.php'), '% $n]'));
$check('SLIDERS wall has no inventory-cycling fill', !str_contains(file_get_contents(dirname(__DIR__) . '/skins/sliders/skin-profile.php'), '%$_sl_n]'));

// ── ORGANIZED MAYHEM pool: random across the WHOLE archive, not the oldest N ──
define('BASE_URL', 'https://x.test/');
require dirname(__DIR__) . '/core/mayhem-data.php';
class MayhemFakePDO extends PDO {
    public array $ids;
    public function __construct(int $n) { $this->ids = range(1, $n); }
    public function quote(string $s, int $t = PDO::PARAM_STR): string|false { return "'" . $s . "'"; }
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        return new MayhemFakeStmt($this, 'count');
    }
    public function prepare(string $q, array $o = []): PDOStatement|false {
        return new MayhemFakeStmt($this, str_contains($q, 'MIN(id)') ? 'bounds' : 'walk');
    }
}
class MayhemFakeStmt extends PDOStatement {
    private array $b = []; private array $out = [];
    public function __construct(private MayhemFakePDO $db, private string $kind) {}
    public function bindValue(string|int $p, mixed $v, int $t = PDO::PARAM_STR): bool { $this->b[$p] = $v; return true; }
    public function execute(?array $p = null): bool {
        if ($this->kind === 'walk') {
            $floor = (int)$this->b[':floor']; $lim = (int)$this->b[':lim'];
            $this->out = [];
            foreach ($this->db->ids as $id) { if ($id >= $floor) { $this->out[] = ['id' => $id, 'img_title' => '', 'img_slug' => "s$id", 'img_file' => "f$id.jpg", 'img_thumb_aspect' => '']; if (count($this->out) >= $lim) break; } }
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $o = PDO::FETCH_ORI_NEXT, int $off = 0): mixed {
        return $this->kind === 'bounds' ? ['lo' => min($this->db->ids), 'hi' => max($this->db->ids)] : false;
    }
    public function fetchColumn(int $c = 0): mixed { return count($this->db->ids); }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$a): array { return $this->out; }
}
$pool = mayhem_image_pool(new MayhemFakePDO(1000), 90);
$ids  = array_map(fn($r) => (int)$r['id'], $pool);
$check('mayhem: asks for 90, gets 90 distinct', count($ids) === 90 && count(array_unique($ids)) === 90);
$check('mayhem: NOT just the oldest 90 (before: ids 1..90 every load)', max($ids) > 300);
$check('mayhem: spread across the archive (top half represented)', count(array_filter($ids, fn($i) => $i > 500)) >= 15);
$small = mayhem_image_pool(new MayhemFakePDO(40), 90);
$check('mayhem: archive smaller than the quota returns the whole archive once', count($small) === 40 && count(array_unique(array_column($small, 'id'))) === 40);
$ic = json_decode(file_get_contents(dirname(__DIR__) . '/skins/instant-camera/manifest.json'), true);
$check('INSTANT CAMERA declares min_images 200', (int)($ic['min_images'] ?? 0) === 200);

foreach ($fails as $f) fwrite(STDERR, "FAIL: {$f}\n");
if ($fails) exit(1);
echo "PASS: GLIDE/SLIDERS refuse to activate under 200 published photographs; the wall is as designed.\n";
// ===== SNAPSMACK EOF =====
