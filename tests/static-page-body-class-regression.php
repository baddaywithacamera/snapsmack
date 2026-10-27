<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A skin that writes static-page rules must be given the class they need.
 *
 * Sean, on curbappeal.photoblogs.fyi running NEW HORIZON: "why is box around
 * photo weird with line at bottom?" The hero on his About page sat in a dark
 * box far taller than the photograph, with a band underneath it.
 *
 * core/skin-view-contract.php computes a body class for every page -- it adds
 * `static-transmission` for a static page or the blogroll, `archive-page`,
 * `is-photo-page`, `page-slug-<slug>` and more -- and publishes it at
 * $view['site']['body_class']. NEW HORIZON's layout hardcoded
 * <body class="new-horizon-v2"> and threw it away, so its SEVEN
 * `.static-transmission #photobox.page-hero` rules never matched a single
 * page. Those rules exist precisely to undo the photo-page treatment on a
 * static page.
 *
 * With them dead, the hero inherited the photo-page rules instead:
 *   #photobox { background: var(--bg-page); height: calc(100dvh - 140px); }
 * and, on top, the saved value of NEW HORIZON's own OPTICAL VERTICAL LIFT
 * control, which compiles to `#photobox { padding-bottom: 50px }`. A control
 * for the photograph page was padding the bottom of his About page. That band
 * is what he was looking at.
 *
 * The id is shared on purpose and must stay shared: core/skin-render-helpers.php
 * emits `id="photobox" class="page-hero"` for every static-page hero, and
 * SEVEN skins style `#photobox.page-hero`. Renaming it would break all of
 * them, which is how skins got broken three times in a month. The class is
 * what distinguishes the two pages, so the class has to arrive.
 *
 * THE CONTRACT: a skin whose stylesheet writes rules under
 * `.static-transmission` must put the CMS body class on its own <body>.
 * This renders each skin's static page and checks that it does.
 */

$root = dirname(__DIR__);
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';

$fail = 0;
function bc_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

/** The <body> tag a skin renders for a static page. */
function bc_body(string $root, string $skin): string {
    $response = ['status' => 200, 'kind' => 'page', 'item' => [
        'id' => 1, 'title' => 'About', 'slug' => 'about',
        'content' => snapsmack_trusted_html('<p>Hello</p>'),
        'image_asset' => '/media/me.jpg',
    ], 'navigation' => []];
    $view = snapsmack_build_skin_view($response, [
        'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr',
        'skin_style_url' => "/skins/{$skin}/style.css",
        'skin_presentation' => snapsmack_skin_presentation([], $skin),
    ]);
    ob_start();
    @snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $view);
    $html = (string)ob_get_clean();
    return preg_match('/<body[^>]*>/', $html, $m) === 1 ? $m[0] : '';
}

// The CMS still computes the class. If this ever stops being true the rest of
// the file is checking a contract that no longer exists.
$contract = (string)file_get_contents($root . '/core/skin-view-contract.php');
bc_test(str_contains($contract, "\$bodyClasses[] = 'static-transmission'")
     && str_contains($contract, "'body_class'"),
    'the CMS still computes a body class and publishes it for skins');

// Every skin whose stylesheet writes .static-transmission rules must receive
// the class. A skin that writes no such rules is not required to carry it.
$needs = [];
foreach (glob($root . '/skins/*/style.css') ?: [] as $css) {
    $skin = basename(dirname($css));
    if (!is_file($root . '/skins/' . $skin . '/layout.php')) continue;
    if (str_contains((string)file_get_contents($css), '.static-transmission')) $needs[] = $skin;
}
sort($needs);
bc_test($needs !== [], 'at least one skin writes static-page rules (otherwise this check is vacuous)');

foreach ($needs as $skin) {
    $body = bc_body($root, $skin);
    bc_test($body !== '' && str_contains($body, 'static-transmission'),
        "{$skin}: its static page carries static-transmission, so its own static-page rules match");
}

// And the shared hero keeps the id and class seven skins are written against.
$helpers = (string)file_get_contents($root . '/core/skin-render-helpers.php');
bc_test(str_contains($helpers, '<div id="photobox" class="page-hero'),
    'the static-page hero still emits id="photobox" class="page-hero" that the skins target');

if ($fail > 0) {
    fwrite(STDERR, "Static-page rules cannot match. {$fail} check(s) failed.\n");
    exit(1);
}

echo "Static-page body class reaches the skins: PASS (" . count($needs) . " skins write static-page rules).\n";

// ===== SNAPSMACK EOF =====
