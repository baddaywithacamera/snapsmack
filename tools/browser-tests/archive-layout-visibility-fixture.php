<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Render one strict skin archive for the browser visibility gate.
 *
 * Usage:
 *   php archive-layout-visibility-fixture.php --inventory
 *   php archive-layout-visibility-fixture.php SKIN LAYOUT
 *
 * One skin/layout per process mirrors one public request and avoids state held
 * by one-shot render helpers leaking between fixtures.
 */

$root = dirname(__DIR__, 2);

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://archive-fixture.test/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', 'fixture');

require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/skin-view-model.php';
require_once $root . '/core/asset-registry.php';
require_once $root . '/core/archive-controls.php';

/** Read the shared assets declared by the strict public runtime. */
function archive_gate_declared_assets(string $runtime, string $group): array
{
    $marker = "['required_assets']['" . $group . "'] = array_values(array_unique(array_merge(";
    $functionAt = strpos($runtime, 'function snapsmack_public_runtime(');
    $at = $functionAt === false ? false : strrpos($runtime, $marker);
    if ($at === false || $at < $functionAt) return [];
    $statement = substr($runtime, $at, 20000);
    $tail = strpos($statement, ')))');
    if ($tail === false) return [];
    $statement = substr($statement, 0, $tail);
    $close = strrpos($statement, ']');
    if ($close === false) return [];
    $open = strrpos(substr($statement, 0, $close), '[');
    if ($open === false) return [];
    preg_match_all("/'([A-Za-z0-9:_-]+)'/", substr($statement, $open + 1, $close - $open - 1), $matches);
    return $matches[1];
}

/** Return every strict skin and every archive layout it declares. */
function archive_gate_inventory(string $root): array
{
    $rows = [];
    foreach (glob($root . '/skins/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!is_file($dir . '/layout.php')) continue;
        $skin = basename($dir);
        $manifest = is_file($dir . '/manifest.json')
            ? json_decode((string)file_get_contents($dir . '/manifest.json'), true)
            : [];
        if (!is_array($manifest)) $manifest = [];
        $controls = snapsmack_archive_controls([], $manifest);
        $rows[] = [
            'skin' => $skin,
            'layouts' => array_values((array)($controls['modes'] ?? [])),
            'report_only' => in_array($skin, ['photogram', 'the-grid'], true),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => strcmp($a['skin'], $b['skin']));
    return $rows;
}

if (($argv[1] ?? '') === '--inventory') {
    echo json_encode(archive_gate_inventory($root), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
}

$skin = (string)($argv[1] ?? '');
$layout = (string)($argv[2] ?? '');
$skinDir = $root . '/skins/' . $skin;
if ($skin === '' || !is_file($skinDir . '/layout.php')) {
    fwrite(STDERR, "Unknown strict skin: {$skin}\n");
    exit(2);
}

$manifest = is_file($skinDir . '/manifest.json')
    ? json_decode((string)file_get_contents($skinDir . '/manifest.json'), true)
    : [];
if (!is_array($manifest)) $manifest = [];
$controls = snapsmack_archive_controls([], $manifest, ['layout' => $layout], []);
$available = array_values((array)($controls['modes'] ?? []));
if (!in_array($layout, $available, true)) {
    fwrite(STDERR, "Layout {$layout} is not declared by {$skin}\n");
    exit(2);
}

$runtime = (string)file_get_contents($root . '/core/public-runtime.php');
$required = [
    'scripts' => archive_gate_declared_assets($runtime, 'scripts'),
    'styles' => archive_gate_declared_assets($runtime, 'styles'),
];
$assets = snapsmack_merge_registered_assets(snapsmack_skin_declared_assets($manifest), $required);

$items = [];
$tiles = [];
foreach (range(1, 12) as $number) {
    $portrait = $number % 3 === 0;
    $item = [
        'id' => $number,
        'img_slug' => 'photograph-' . $number,
        'img_title' => 'Photograph ' . $number,
        'title' => 'Photograph ' . $number,
        'presentation_title' => 'Photograph ' . $number,
        'img_alt' => 'Photograph ' . $number,
        'excerpt' => 'Archive fixture photograph.',
        'img_file' => '/fixture-photo.svg',
        'img_thumb_aspect' => $portrait ? '/fixture-portrait.svg' : '/fixture-photo.svg',
        'img_thumb_square' => '/fixture-square.svg',
        'featured_image_path' => '/fixture-photo.svg',
        'presentation_image' => '/fixture-photo.svg',
        'img_width' => $portrait ? 800 : 1200,
        'img_height' => $portrait ? 1200 : 800,
        'img_orientation' => $portrait ? 1 : 0,
        'url' => '/photograph-' . $number,
    ];
    $items[] = $item;
    $tiles[] = [
        'id' => $number,
        'thumb' => $portrait ? '/fixture-portrait.svg' : '/fixture-photo.svg',
        'full' => '/fixture-photo.svg',
        'title' => 'Photograph ' . $number,
        'width' => $portrait ? 2 : 3,
        'height' => $portrait ? 3 : 2,
    ];
}

$response = [
    'kind' => 'archive',
    'page_title' => 'Archive',
    'items' => $items,
    // The strict archive contract has two established collection shapes.
    // Most skins consume items; the SmackTalk-derived skins consume tiles.
    // Populate both from the same fixture photographs so this gate measures
    // presentation rather than failing on an incomplete synthetic response.
    'tiles' => $tiles,
    'item' => [],
    'navigation' => [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'Archive View', 'url' => '/archive'],
    ],
    'archive_layouts' => snapsmack_archive_layout_links(
        (array)$controls['layouts'], BASE_URL, ['archive' => 'archive'], ''
    ),
    'archive_layout_active' => (string)$controls['active'],
    'archive_calendar' => (array)$controls['calendar'],
    'next_page' => 2,
    'has_more' => true,
    'total_count' => 60,
    'total_pages' => 5,
];

$view = snapsmack_build_skin_view($response, [
    'site_name' => 'Archive fixture',
    'language' => 'en',
    'direction' => 'ltr',
    'base_url' => BASE_URL,
    'base_style_url' => BASE_URL . 'assets/css/public-base.css',
    'skin_style_url' => BASE_URL . 'skins/' . $skin . '/style.css',
    'skin_variant_url' => '',
    'registered_assets' => $assets,
    'owner_custom_code' => '',
    'search_dock' => [],
    'skin_slug' => $skin,
    'archive_layout' => $layout,
    'archive_thumb_style' => 'cropped',
    'archive_grid' => ['cols' => 4, 'step' => 'm'],
    'skin_canvas_width_option' => (string)($manifest['features']['canvas_width_option'] ?? ''),
    'skin_presentation' => snapsmack_skin_presentation([], $skin),
    'crop_circles' => ['search' => [], 'filters' => []],
    'footer' => [
        'site_name' => 'Archive fixture',
        'site_url' => BASE_URL,
        'slots' => [['kind' => 'copyright', 'year' => '2026', 'site_name' => 'Archive fixture']],
    ],
]);

ob_start();
snapsmack_render_strict_skin_template($skinDir, 'layout.php', $view);
echo (string)ob_get_clean();

// ===== SNAPSMACK EOF =====
