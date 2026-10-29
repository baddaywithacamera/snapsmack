<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * CHAPLIN's landing page has the bottom photo navigation back.
 *
 * Sean's reference capture, projects/snapsmack-ca/img/chaplin-landing.png,
 * shows it under the frame: PREV | FIRST | INFO | COMMENTS (0) | LAST | NEXT,
 * with LAST and NEXT greyed because the newest photograph is on screen. Codex
 * measured the live page at 1920 on 28 October and reported "blank bottom
 * photo nav persist".
 *
 * TWO THINGS HAD TO BE TRUE AND NEITHER WAS.
 *
 * 1. CHAPLIN declares features.has_landing, so the controller gives it the
 *    FEED response rather than the photo response. The feed response carried
 *    items, rows, navigation and a photo count -- and no previous, next,
 *    first or last. There was nothing to draw a photo navigation FROM.
 *
 * 2. The landing branch of core/chaplin-page-component.php rendered
 *    <div id="infobox" aria-hidden="true"></div>. Empty, and marked hidden
 *    from assistive technology, which is the correct marking for an empty box
 *    and the wrong box entirely.
 *
 * The neighbours are now computed with the SAME four repository calls
 * snapsmack_photo_response() uses, so the landing and the solo page cannot
 * disagree about what "next" means, and only when there is a hero photograph
 * to be adjacent to.
 *
 * This RENDERS the component with a synthetic landing view and reads the
 * markup, rather than grepping the source. A component that emits the right
 * nav for the wrong branch would pass a grep.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function cl_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$controller = (string) file_get_contents($root . '/core/public-controller.php');

echo "The feed response carries the hero's neighbours\n";
$landingAt = strpos($controller, "'kind' => 'landing'");
$block = $landingAt === false ? '' : substr($controller, max(0, $landingAt - 2200), 3000);
cl_test($block !== '', 'the landing return was located');
foreach (['previous', 'next', 'first', 'last'] as $key) {
    cl_test(preg_match("/'{$key}'\s*=>\s*\\\$heroNav\['{$key}'\]/", $block) === 1,
        "the landing response returns the hero's {$key}");
}
cl_test(str_contains($block, 'adjacentPhotograph') && str_contains($block, 'photographBoundary'),
    'it uses the same repository calls as the solo page, so the two cannot disagree');
cl_test(str_contains($block, 'if ($heroId > 0)'),
    'and only looks when there is a hero photograph to be adjacent to');

echo "\nThe landing branch renders the navigation instead of an empty box\n";
$component = (string) file_get_contents($root . '/core/chaplin-page-component.php');
cl_test(!str_contains($component, '<div id="infobox" aria-hidden="true"></div>'),
    'the empty aria-hidden infobox is gone');
cl_test(substr_count($component, "snap_render_component('photo-navigation'") >= 2,
    'the landing branch renders photo-navigation as the solo branch already did');

echo "\nRendered, not grepped: a synthetic landing view produces the links\n";
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/parser.php';

$hero = ['id' => 42, 'img_slug' => 'a-photo', 'img_title' => 'A Photo', 'img_file' => '/a.jpg',
         'img_alt' => 'A Photo', 'allow_comments' => 1];
$dest = static function (string $slug): array {
    return ['slug' => $slug, 'url' => '/' . $slug, 'title' => $slug, 'img_title' => $slug];
};

$view = snapsmack_build_skin_view([
    'status' => 200,
    'kind' => 'landing',
    'items' => [$hero],
    'previous' => $dest('older'), 'next' => [],
    'first' => $dest('oldest'), 'last' => [],
    'comments' => [], 'comments_enabled' => true,
    'navigation' => [],
], [
    'site_name' => 'Test', 'language' => 'en', 'direction' => 'ltr',
    'base_style_url' => '/assets/css/public-base.css',
    'skin_style_url' => '/skins/chaplin/style.css',
    'skin_custom_style' => snapsmack_trusted_html(''),
    'archive_layout' => 'square',
    'skin_presentation' => [
        'style' => snapsmack_trusted_html(''),
        'chaplin' => [
            'corners' => true, 'mid_tb' => true, 'mid_lr' => false,
            'corner_asset' => '/skins/chaplin/assets/svg/A-corner.svg',
            'top_asset' => '/skins/chaplin/assets/svg/A-top.svg',
            'side_asset' => '/skins/chaplin/assets/svg/A-side.svg',
            'title_position' => 'below_photo', 'line_count' => 1,
        ],
    ],
    'owner_custom_code' => snapsmack_trusted_html(''),
]);

ob_start();
$rendered = snapsmack_render_strict_skin_template($root . '/skins/chaplin', 'layout.php', $view);
$html = (string) ob_get_clean();

cl_test($rendered === true, 'the CHAPLIN landing renders');
cl_test(str_contains($html, 'id="infobox"'), 'the landing emits an infobox');
cl_test(!preg_match('/id="infobox"[^>]*aria-hidden="true"[^>]*>\s*<\/div>/', $html),
    'and it is not an empty box hidden from assistive technology');
foreach (['PREV', 'FIRST', 'INFO', 'COMMENTS', 'LAST', 'NEXT'] as $label) {
    cl_test(stripos($html, $label) !== false, "the landing shows {$label}");
}
cl_test(str_contains($html, '/older'), "a real destination reaches the markup, not just the word");
cl_test(str_contains($html, 'chap-orn-mid-top'),
    'and the centre ornaments restored earlier still render on this branch');

echo "\n";
echo "  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "CHAPLIN landing photo nav: FAIL ({$fail}).\n";
    exit(1);
}
echo "CHAPLIN landing photo nav: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
