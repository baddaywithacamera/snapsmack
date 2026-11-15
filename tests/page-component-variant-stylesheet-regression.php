<?php
declare(strict_types=1);

/**
 * SS-1343. Every page component that renders a WHOLE DOCUMENT must emit the
 * selected skin variant's stylesheet.
 *
 * core/skin-view-model.php has built site.skin_variant_url for a long time:
 * an owner picks active_skin_variant, the manifest declares it, the file
 * exists, and the view model hands the component a URL. But only three of the
 * five whole-document components ever printed the <link>. GALLERIA's did not,
 * so HIP 2 B SQUARE could not load on a real page however correct the skin
 * side was -- that is SS-1343's blocker. CHAPLIN's did not either, and nobody
 * had reported it, which is exactly why a shared fault gets an inventory
 * rather than a one-skin repair.
 *
 * This test renders each component for real and reads its output. It is not a
 * source grep: a grep would pass on a <link> inside a comment.
 */

define('SNAPSMACK_SKIN_RENDER', true);
require_once __DIR__ . '/../core/trusted-html.php';
require_once __DIR__ . '/../core/skin-render-helpers.php';

/** Whole-document components. rational-geo is NOT one: it is a body fragment
 *  with no <head> at all (13 lines, no stylesheet links), so it has nothing to
 *  emit and is exempt for a stated reason rather than by omission. */
$components = [
    '50-shades'      => '/skins/50-shades-of-noah-grey/variant-light.css',
    'onyx'           => '/skins/onyx/variant-topaz.css',
    'impact-printer' => '/skins/impact-printer/variant-plain.css',
    'galleria'       => '/skins/galleria/variant-square.css',
    'chaplin'        => '/skins/chaplin/variant-sepia.css',
];

function render_component(string $name, string $variant_url): string {
    $view = [
        'site' => [
            'language' => 'en', 'direction' => 'ltr', 'site_name' => 'Test Site',
            'base_url' => '/', 'tagline' => '',
            'base_style_url' => '/assets/css/public-base.css',
            'skin_style_url' => '/skins/x/style.css',
            'skin_variant_url' => $variant_url,
            'skin_custom_style' => '', 'skin_presentation' => ['style' => ''],
            'body_class' => '', 'footer' => [], 'navigation' => [],
            'registered_assets' => [], 'owner_custom_code' => '', 'search_dock' => [],
        ],
        'response' => [
            'kind' => 'not_found', 'page_title' => 'Not found',
            'navigation' => [], 'items' => [], 'item' => [],
        ],
    ];
    ob_start();
    require __DIR__ . '/../core/' . $name . '-page-component.php';
    return (string)ob_get_clean();
}

$fails = [];
foreach ($components as $name => $url) {
    $with = render_component($name, $url);
    if (!str_contains($with, '<link rel="stylesheet" href="' . $url . '">')) {
        $fails[] = $name . ' did not emit its selected variant stylesheet';
    }
    // And it must NOT print an empty <link> when no variant is selected,
    // which would make the browser re-request the page as a stylesheet.
    $without = render_component($name, '');
    if (str_contains($without, '<link rel="stylesheet" href="">')) {
        $fails[] = $name . ' emitted an empty variant stylesheet link';
    }
}

foreach ($fails as $f) echo "FAIL: $f\n";
echo (count($fails) ? 'FAIL' : 'PASS')
   . ": every whole-document page component emits the selected skin variant\n";
exit(count($fails) ? 1 : 0);
// ===== SNAPSMACK EOF =====
