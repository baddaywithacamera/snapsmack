<?php
declare(strict_types=1);

/**
 * THE min_images THRESHOLD MUST SURVIVE THE LOADER THE CALLERS USE.
 *
 * GLIDE asks for 50 published photographs before it will activate; INSTANT
 * CAMERA and SLIDERS ask for 200. Sean, 2026-09-17: "make that skin refuse to
 * install with less than 200 images." A wall skin under its minimum is rows of
 * one tile and reads as broken.
 *
 * IT NEVER REFUSED ANYTHING. min_images was not in the manifest normalizer's
 * known keys, so load_skin_manifest() dropped it with only an error_log. Both
 * production callers are handed a normalized manifest --
 * smack-skin.php:164 via load_skin_manifest(), and :478 via
 * snapsmack_load_manifest(), which delegates to the same loader -- so
 * snap_skin_min_images_conflict() read 0 every time and allowed everything.
 * Three skins declared a threshold and none of them had one.
 *
 * AND THE EXISTING TEST PASSED THROUGHOUT, because it hand-builds
 *     $glide = ['name' => 'GLIDE', 'min_images' => 200];
 * which is a shape production never produces. It proves the guard's arithmetic
 * and nothing about whether the guard is ever given a number.
 *
 * So this file LOADS THE REAL MANIFESTS THROUGH THE REAL LOADER and hands the
 * result to the real guard. Nothing here is hand-built.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/mode-guard.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/** A database that reports a given published-photograph count. */
class MinImgRealFakePDO extends PDO {
    public function __construct(private int $count) {}
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        return new MinImgRealFakeStmt($this->count);
    }
}
class MinImgRealFakeStmt extends PDOStatement {
    public function __construct(private int $count) {}
    public function fetchColumn(int $c = 0): mixed { return $this->count; }
}

echo "THE DECLARATION SURVIVES THE LOADER\n";
$expected = ['glide' => 50, 'instant-camera' => 200, 'sliders' => 200];
foreach ($expected as $slug => $want) {
    $m = load_skin_manifest($slug);
    cx(array_key_exists('min_images', $m) && $m['min_images'] === $want,
       "{$slug} comes back from load_skin_manifest() with min_images = {$want}");
}
// A skin that declares none must not acquire one.
$tg = load_skin_manifest('true-grit');
cx(!array_key_exists('min_images', $tg),
   'true-grit declares none and is not given one');

echo "\nAND THE GUARD, HANDED THAT LOADED MANIFEST, ACTUALLY REFUSES\n";
$glide = load_skin_manifest('glide');          // the real thing, not a literal
$below = snap_skin_min_images_conflict(new MinImgRealFakePDO(49), $glide, 'glide');
cx($below !== null, 'GLIDE at 49 photographs is refused');
cx($below !== null && str_contains($below, '50'), 'and the refusal names the threshold, 50');
cx($below !== null && str_contains($below, '49'), 'and what the site actually has, 49');
cx($below !== null && str_contains($below, 'GLIDE'), 'and the skin, by the name in its manifest');
if ($below !== null) echo "        -> {$below}\n";

cx(snap_skin_min_images_conflict(new MinImgRealFakePDO(50), $glide, 'glide') === null,
   'exactly 50 is allowed');
cx(snap_skin_min_images_conflict(new MinImgRealFakePDO(132), $glide, 'glide') === null,
   'and 132, which is what the Portal Project actually has, is allowed');

$sliders = load_skin_manifest('sliders');
cx(snap_skin_min_images_conflict(new MinImgRealFakePDO(199), $sliders, 'sliders') !== null,
   'SLIDERS at 199 is refused, so the 200 in its manifest is real too');

cx(snap_skin_min_images_conflict(new MinImgRealFakePDO(1), $tg, 'true-grit') === null,
   'a skin with no minimum is never refused, whatever the count');

echo "\nA MANIFEST MISTAKE IS NOT GUESSED AT\n";
// The normalizer takes an int. A string "50" is an authoring error, and
// inventing a refusal the author did not write would be worse than ignoring it.
$norm = snapsmack_normalize_skin_manifest(
    ['schema_version' => 2, 'name' => 'X', 'min_images' => '50'], 'x');
cx(!array_key_exists('min_images', $norm), 'a string min_images is dropped, not coerced');
$neg = snapsmack_normalize_skin_manifest(
    ['schema_version' => 2, 'name' => 'X', 'min_images' => -5], 'x');
cx(!array_key_exists('min_images', $neg), 'a negative min_images is dropped');
$zero = snapsmack_normalize_skin_manifest(
    ['schema_version' => 2, 'name' => 'X', 'min_images' => 0], 'x');
cx(($zero['min_images'] ?? null) === 0, 'an explicit 0 is kept, and means no minimum');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
