<?php
/**
 * 721D: a skin can declare "min_images" and refuse to become active below it.
 * GLIDE and SLIDERS (the moving photo wall) declare a minimum — under it the wall
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

// THE THRESHOLD IS THE OWNER'S NUMBER, SO THIS ASSERTS THE MECHANISM, NOT 200.
// GLIDE dropped from 200 to 50 on 1 November 2026 at Sean's request, to launch
// portalproject on a set of about 50 portraits. A test that pins the literal
// turns an owner decision into a build failure and teaches the next person to
// edit the test to make the number go away. What must not change is that each
// of these skins DECLARES a positive minimum and that the guard refuses below
// whatever it declares -- so a skin silently losing its threshold still fails
// here, which is the thing worth guarding.
foreach (['glide', 'sliders'] as $slug) {
    $m = json_decode(file_get_contents(dirname(__DIR__) . "/skins/$slug/manifest.json"), true);
    $declared = (int)($m['min_images'] ?? 0);
    $check("$slug manifest still declares a positive min_images", $declared > 0);
    $skin = ['name' => strtoupper($slug), 'min_images' => $declared];
    $check("$slug refuses one photograph below its own declared minimum",
        snap_skin_min_images_conflict(new MinImgFakePDO($declared - 1), $skin, $slug) !== null);
    $check("$slug accepts exactly its own declared minimum",
        snap_skin_min_images_conflict(new MinImgFakePDO($declared), $skin, $slug) === null);
}
$src = file_get_contents(dirname(__DIR__) . '/smack-skin.php');
$check('gallery ACTIVATE path checks the minimum', substr_count($src, 'snap_skin_min_images_conflict(') >= 2);
$check('save-settings path checks the minimum before persisting active_skin',
    strpos($src, 'snap_skin_min_images_conflict($pdo, is_array($_requested_data)') < strpos($src, "->execute([\$active_skin, \$active_skin]);"));
// Selection behavior is CMS-owned; skins only declare the minimum and engines.
$repo=file_get_contents(dirname(__DIR__).'/core/public-repository.php');
$check('public feed selection is centralized and bounded',str_contains($repo,'function photographLanding(')&&str_contains($repo,'LIMIT ? OFFSET ?'));

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
$ic_declared = (int)($ic['min_images'] ?? 0);
$check('INSTANT CAMERA still declares a positive min_images', $ic_declared > 0);
$check('INSTANT CAMERA refuses one below its own declared minimum',
    snap_skin_min_images_conflict(new MinImgFakePDO($ic_declared - 1),
        ['name' => 'INSTANT CAMERA', 'min_images' => $ic_declared], 'instant-camera') !== null);

foreach ($fails as $f) fwrite(STDERR, "FAIL: {$f}\n");
if ($fails) exit(1);
echo "PASS: GLIDE/SLIDERS/INSTANT CAMERA refuse below their own declared minimum; the wall and the mayhem table draw at random from the whole archive.
";
// ===== SNAPSMACK EOF =====
