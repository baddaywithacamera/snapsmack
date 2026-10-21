<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The arrow keys need somewhere to go.
 *
 * ss-engine-comms.js -- the hotkey engine every SMACKONEOUT skin loads as
 * smack-keyboard -- finds its destinations with
 * querySelector('a[rel="prev"][href]') and the same for "next". No link, no
 * arrow keys, and nothing anywhere says so: the engine loads, binds, and
 * quietly does nothing.
 *
 * Measured on live sites 19 October 2026: 50 SHADES, IMPACT PRINTER and
 * RATIONAL GEO had the links. GALLERIA, CHAPLIN and SLICKR had none, on
 * solo pages whose stylesheets carry 21, 9 and 9 rules for .nav-links
 * respectively -- markup they were styled for and never given.
 *
 * Sean: "key commands are a blindspot."
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
// Skins warn about presentation keys this fixture does not set; the test is
// about rel=prev/next, not about those.
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');

$response = [
    'status' => 200, 'kind' => 'photo', 'navigation' => [], 'comments' => [],
    'comments_enabled' => true,
    'item' => ['id' => 2, 'img_title' => 'Middle', 'img_slug' => 'middle',
               'img_file' => '/m.jpg', 'img_alt' => 'A photograph', 'url' => '/middle'],
    'previous' => ['url' => '/older', 'img_title' => 'Older', 'img_slug' => 'older'],
    'next'     => ['url' => '/newer', 'img_title' => 'Newer', 'img_slug' => 'newer'],
    'first'    => ['url' => '/first', 'img_title' => 'First', 'img_slug' => 'first'],
    'last'     => ['url' => '/last', 'img_title' => 'Last', 'img_slug' => 'last'],
];

$presentation = [
    'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr', 'base_url' => '/',
    'skin_presentation' => [], 'footer' => [], 'registered_assets' => [],
    'archive_grid' => ['cols' => 4, 'step' => 'm', 'thumb_width' => 200],
];

// Every skin that declares smack-keyboard is making a promise to its reader.
$failures = [];
foreach (glob(dirname(__DIR__) . '/skins/*/manifest.json') as $manifestPath) {
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest)) continue;
    if (!in_array('smack-keyboard', $manifest['require_scripts'] ?? [], true)) continue;

    $slug = basename(dirname($manifestPath));
    $layout = dirname($manifestPath) . '/layout.php';
    if (!is_file($layout)) continue;

    $view = snapsmack_build_skin_view($response, $presentation + ['skin_slug' => $slug,
        'skin_style_url' => '/skins/' . $slug . '/style.css']);
    ob_start();
    try {
        include $layout;
        $html = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $failures[] = $slug . ': layout threw -- ' . $e->getMessage();
        continue;
    }

    foreach (['prev', 'next'] as $rel) {
        if (!preg_match('/<a[^>]+rel="' . $rel . '"[^>]*href=|href=[^>]*rel="' . $rel . '"/', $html)) {
            $failures[] = $slug . ' declares smack-keyboard but renders no rel="' . $rel . '" link, '
                . 'so the arrow keys do nothing on its solo page';
        }
    }
}

// A RATCHET, not a wall. Fourteen skins arrived here already broken, and
// fixing them blind is not a change anybody should make: nine are the
// GRAMOFSMACK family, whose solo view is a modal overlay, so where the links
// belong there is a design question. What this guarantees is that the number
// only ever goes DOWN -- a skin that works today cannot quietly stop, and a
// new skin cannot join the broken list at all.
//
// Checked before writing it down: ss-engine-grid-modal, -grid-lightbox and
// -carousel-view bind no ArrowLeft or ArrowRight between them, so these are
// dead keys, not navigation handled somewhere else.
$known_broken = [
    '52-card-pickup',
    'aurora',
    'full-monty',
    'game-on',
    'glide',
    'heuristic',
    'jive-turkey',
    'parade',
    'show-n-tell',
    'sliders',
    'sudden-impact',
    'the-grid',
];

$unexpected = [];
$fixed = [];
foreach ($failures as $line) {
    $slug = strstr($line, ' declares', true);
    if (!in_array($slug, $known_broken, true)) $unexpected[] = $line;
}
foreach ($known_broken as $slug) {
    $still = false;
    foreach ($failures as $line) if (str_starts_with($line, $slug . ' ')) $still = true;
    if (!$still) $fixed[] = $slug;
}

if ($unexpected) {
    fwrite(STDERR, "FAIL: a skin lost its arrow keys" . PHP_EOL . "  - " . implode(PHP_EOL . "  - ", $unexpected) . PHP_EOL);
    exit(1);
}
if ($fixed) {
    fwrite(STDERR, "FAIL: " . implode(", ", $fixed) . " now works -- remove it from the known_broken list "
        . "so the ratchet holds the ground that was just taken." . PHP_EOL);
    exit(1);
}
echo "PASS: arrow keys work on " . (count(glob(dirname(__DIR__) . "/skins/*/manifest.json")) - count($known_broken))
    . " skins; " . count($known_broken) . " known broken and not getting worse." . PHP_EOL;
// ===== SNAPSMACK EOF =====
