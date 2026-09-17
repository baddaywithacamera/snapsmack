<?php
/**
 * 721D: a skin can declare "min_images" and refuse to become active below it.
 * GLIDE and SLIDERS (the moving photo wall) declare 100 — under that the wall
 * is rows of one tile and reads as broken. Sean, 2026-09-17: "make that skin
 * refuse to install with less than 100 images."
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

$glide = ['name' => 'GLIDE', 'min_images' => 100];
$check('below minimum refuses, names the skin and both numbers',
    ($m = snap_skin_min_images_conflict(new MinImgFakePDO(45), $glide, 'glide')) !== null
    && str_contains($m, 'GLIDE') && str_contains($m, '100') && str_contains($m, '45'));
$check('exactly the minimum passes', snap_skin_min_images_conflict(new MinImgFakePDO(100), $glide, 'glide') === null);
$check('above passes', snap_skin_min_images_conflict(new MinImgFakePDO(250), $glide, 'glide') === null);
$check('a skin with no minimum never refuses', snap_skin_min_images_conflict(new MinImgFakePDO(0), ['name' => 'PARADE'], 'parade') === null);

foreach (['glide', 'sliders'] as $slug) {
    $m = json_decode(file_get_contents(dirname(__DIR__) . "/skins/$slug/manifest.json"), true);
    $check("$slug manifest declares min_images 100", (int)($m['min_images'] ?? 0) === 100);
}
$src = file_get_contents(dirname(__DIR__) . '/smack-skin.php');
$check('gallery ACTIVATE path checks the minimum', substr_count($src, 'snap_skin_min_images_conflict(') >= 2);
$check('save-settings path checks the minimum before persisting active_skin',
    strpos($src, 'snap_skin_min_images_conflict($pdo, is_array($_requested_data)') < strpos($src, "->execute([\$active_skin, \$active_skin]);"));
// The wall itself is unchanged: no small-archive fill in either skin.
$check('GLIDE wall has no inventory-cycling fill', !str_contains(file_get_contents(dirname(__DIR__) . '/skins/glide/landing.php'), '% $n]'));
$check('SLIDERS wall has no inventory-cycling fill', !str_contains(file_get_contents(dirname(__DIR__) . '/skins/sliders/skin-profile.php'), '%$_sl_n]'));

foreach ($fails as $f) fwrite(STDERR, "FAIL: {$f}\n");
if ($fails) exit(1);
echo "PASS: GLIDE/SLIDERS refuse to activate under 100 published photographs; the wall is as designed.\n";
// ===== SNAPSMACK EOF =====
