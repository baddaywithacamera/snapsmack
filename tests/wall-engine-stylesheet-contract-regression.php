<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Switching on a photo-wall engine must bring its geometry with it.
 *
 * The 50 Shades archive on photowalk.ing rendered as a ragged clump of
 * inline images. The markup was right, the dimensions were right, and
 * ss-engine-rows.js was on the page -- but assets/css/ss-engine-scroll-wall.css
 * was not. That stylesheet is what makes .ss-masonry-item a block and gives
 * the container its box; without it the items flow as inline images and the
 * engine lays out nothing.
 *
 * Measured in a harness built from the live page, 1600px wide, the only
 * difference being that one stylesheet:
 *
 *     without:  items display:inline, engine sized 0 of 24, 14 ragged rows
 *     with:     items display:block,  engine sized 24 of 24, 5 justified rows
 *               (1568, 1568, 1569, 1566 of 1585px, last row short as it should be)
 *
 * The stylesheet was reachable only through a page/blog gate in core/meta.php,
 * so strict public skins could request the engine and never receive it. The
 * inventory already supports a companion 'css' on a script entry -- six other
 * engines use it -- so declaring it there means every skin that asks for the
 * engine gets its structure, instead of each skin having to remember a
 * stylesheet it cannot see.
 */

require_once dirname(__DIR__) . '/core/asset-registry.php';

if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.0.0D');

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$inventory = require $root . '/core/manifest-inventory.php';
$WALL_CSS = 'assets/css/ss-engine-scroll-wall.css';
$WALL_ENGINES = ['smack-rows', 'smack-columns'];

// --- the engines must declare the stylesheet they cannot work without ----
foreach ($WALL_ENGINES as $handle) {
    $entry = $inventory['scripts'][$handle] ?? null;
    $check(is_array($entry), $handle . ' must exist in the manifest inventory');
    if (!is_array($entry)) continue;
    $check(($entry['css'] ?? '') === $WALL_CSS,
        $handle . ' must declare ' . $WALL_CSS . ' as its companion stylesheet');
}

// --- the file must really be there and really style the engine's markup --
$check(is_file($root . '/' . $WALL_CSS), $WALL_CSS . ' must exist');
$css = (string)@file_get_contents($root . '/' . $WALL_CSS);
$check(str_contains($css, '.ss-scroll-wall'), 'the stylesheet must style the wall container');
$check(str_contains($css, '.ss-masonry-item'), 'the stylesheet must style the wall items');

// --- and it must be registrable as a public style ------------------------
$asset = snapsmack_asset_by_handle('asset:public:ss-engine-scroll-wall')
    ?? snapsmack_asset_by_handle('asset:public:ss-engine-scroll-wall:css');
$check(is_array($asset) && ($asset['scope'] ?? '') === 'public' && ($asset['type'] ?? '') === 'style',
    'the wall stylesheet must be a registered public style, or the resolver will drop it');

// --- every skin asking for a wall engine must receive the stylesheet -----
$asked = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $file) {
    $manifest = json_decode((string)file_get_contents($file), true);
    if (!is_array($manifest)) continue;
    $skin = basename(dirname($file));
    $wants = array_intersect($WALL_ENGINES, (array)($manifest['require_scripts'] ?? []));
    if (!$wants) continue;
    $asked[] = $skin;
    $assets = snapsmack_skin_declared_assets($manifest);
    $got = false;
    foreach ($assets['styles'] as $url) if (str_contains($url, 'ss-engine-scroll-wall.css')) $got = true;
    $check($got, $skin . ' switches on ' . implode('+', $wants)
        . ' and must receive the wall stylesheet with it');
}

// The bug reached four skins at once, which is the point of fixing it in the
// inventory rather than in one skin.
$check(count($asked) >= 4,
    'expected at least four skins to use a wall engine, found ' . count($asked)
    . ' (' . implode(', ', $asked) . ')');

printf("wall engines: %d skin(s) switch one on and all receive the stylesheet (%s).\n",
    count($asked), implode(', ', $asked));

if ($failures) {
    fwrite(STDERR, "Wall engine stylesheet contract FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Wall engine stylesheet contract regression passed.\n";

// ===== SNAPSMACK EOF =====
