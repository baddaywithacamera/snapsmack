<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// ALFRED'S FOOTER LINKS, READ OUT OF A REAL ALFRED RENDER.
//
// SS-1446 removed ALFRED's hand-rolled footer social markup "in favour of the
// SnapSmack social dock" and nothing replaced it: the strict SMACKTALK path
// renders the skin's layout.php and exits, and nothing on that path emits
// core/social-dock.php. The links were simply gone from the site. I reviewed
// that commit and PASSED the item; the browser proof inserted a social dock
// into its own fixture by hand and then asserted its own markup existed.
//
// So this file does the one thing neither of us did: it renders the REAL
// skins/alfred/layout.php through snapsmack_render_strict_skin_template() --
// the function index.php calls at :450-454 -- and reads the dock back out of
// what ALFRED actually produced. Nothing here is hand-written markup.
//
// It also covers the two ways the placement can be present and still be wrong:
// the stylesheet never arriving (unstyled links), and the stylesheet arriving
// but leaving the base position:fixed in force (a dock floating over the page
// instead of sitting in the footer).

$root = dirname(__DIR__);
define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/asset-registry.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "SS-1446: ALFRED's footer carries the shared social dock\n\n";

$manifest = json_decode((string)file_get_contents($root . '/skins/alfred/manifest.json'), true,
                        512, JSON_THROW_ON_ERROR);

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — ALFRED PLACES THE COMPONENT AND OWNS NO SOCIAL MARKUP\n";
// ─────────────────────────────────────────────────────────────────────────────
// The layout prints one component and passes one value. If it ever re-grows
// its own list, icons or platform names, the duplication that produced
// ALFRED's private Hitchcock social CSS is back.
$layout = (string)file_get_contents($root . '/skins/alfred/layout.php');
$check('the layout places the social-dock component',
       str_contains($layout, "snap_render_component('social-dock'"));
$check('  passing the view model and nothing else',
       str_contains($layout, "'model' => \$view['site']['social_dock']"));
$check('  and holds no social markup of its own',
       !str_contains($layout, 'alfred-footer-social') && !str_contains($layout, 'social-menu'));
$check('ALFRED\'s stylesheet holds no private dock styles',
       !str_contains((string)file_get_contents($root . '/skins/alfred/style.css'),
                     'alfred-footer-social'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE DOCK'S STYLESHEET REACHES ALFRED, FROM ALFRED'S MANIFEST\n";
// ─────────────────────────────────────────────────────────────────────────────
// Asserted against the REAL manifest, not a synthetic one: a skin that places
// the component without declaring the block renders unstyled links.
$check('ALFRED declares a social_dock block', is_array($manifest['social_dock'] ?? null));
$check('  placed inline, in the footer flow',
       ($manifest['social_dock']['position'] ?? '') === 'inline');
$styles = snapsmack_skin_declared_assets($manifest)['styles'];
$check('  so the engine stylesheet is registered for ALFRED',
       (bool)array_filter($styles,
           static fn(string $u): bool => str_contains($u, 'ss-engine-social-dock.css')));

// The stylesheet itself: an inline dock with no reset keeps position:fixed
// from the base rule and floats over the page. This is a file assertion, not
// a measurement -- the rendered geometry is in the captures named at the end.
$dockCss = (string)file_get_contents($root . '/assets/css/ss-engine-social-dock.css');
$inlineRule = (string)(preg_split('/\.social-dock-inline\s*\{/', $dockCss)[1] ?? '');
$inlineRule = (string)(explode('}', $inlineRule)[0] ?? '');
$check('the engine returns an inline dock to the page flow',
       $inlineRule !== '' && str_contains($inlineRule, 'position: static'));
$check('  and drops the floating dock\'s idle fade',
       str_contains($inlineRule, 'opacity: 1'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE REAL ALFRED RENDER, AS index.php DOES IT\n";
// ─────────────────────────────────────────────────────────────────────────────
$social = [
    ['url' => 'https://example.com/flickr',    'label' => 'Flickr',    'icon' => 'flickr'],
    ['url' => 'https://example.com/instagram', 'label' => 'Instagram', 'icon' => 'instagram'],
    ['url' => 'https://example.com/mastodon',  'label' => 'Mastodon',  'icon' => 'mastodon'],
];
$cropCircles = static fn(array $social): array => [
    'identity' => 'A Photographer', 'home_url' => '/', 'navigation' => [],
    'icon_sprite_url' => '/assets/icons/crop-circles.svg',
    'search' => ['action' => '/', 'placeholder' => 'Search'], 'filters' => [],
    'social' => $social,
    'appearance' => ['icon' => '#ffffff', 'background' => 'rgba(0,0,0,.7)',
                     'background_hover' => 'rgba(0,0,0,.9)', 'border' => 'rgba(255,255,255,.3)',
                     'border_hover' => 'rgba(255,255,255,.7)', 'opacity' => .5],
];

$render = static function (array $social) use ($root, $cropCircles): array {
    $view = snapsmack_build_skin_view([
        'kind' => 'feed', 'status' => 200, 'navigation' => [], 'posts' => [],
    ], [
        'site_name' => 'A Photographer', 'language' => 'en', 'direction' => 'ltr',
        'skin_style_url' => '/skins/alfred/style.css',
        'skin_custom_style' => snapsmack_trusted_html(''),
        'skin_presentation' => [], 'social_dock' => $cropCircles($social),
        'footer' => ['text' => 'Copyright', 'lowercase' => false],
        'registered_assets' => [], 'owner_custom_code' => snapsmack_trusted_html(''),
    ]);
    ob_start();
    $ok = snapsmack_render_strict_skin_template($root . '/skins/alfred', 'layout.php', $view);
    return [$ok, (string)ob_get_clean()];
};

[$ok, $html] = $render($social);
$flat = str_replace("\n", '', $html);

$check('the strict renderer accepted ALFRED\'s real layout', $ok === true);
$check('  and its output carries the dock', str_contains($html, 'class="social-dock social-dock-inline'));
$check('  with all three configured links in both approved placements',
       substr_count($html, 'class="dock-link u-url"') === 6);
$check('  each one rel="me", so the links stay IndieWeb-verifiable',
       substr_count($html, 'rel="me noopener"') === 6);
$check('  and each one named for a screen reader',
       str_contains($html, '>Flickr</span>') && str_contains($html, '>Mastodon</span>'));

// WHERE it rendered, not just that it rendered. The links were in the footer
// before SS-1446 removed them and that is where they have to come back.
$footerAt = strpos($flat, 'id="footer" class="inside"');
$dockAt   = strrpos($flat, 'class="social-dock');
$footerEnd = strpos($flat, '</footer>');
$check('  with a distinct dock reserved for the footer',
       substr_count($flat, 'class="social-dock social-dock-inline') === 2);
$check('  inside ALFRED\'s footer, where its links used to be',
       $footerAt !== false && $dockAt !== false && $footerEnd !== false
       && $dockAt > $footerAt && $dockAt < $footerEnd);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — NO PLATFORM CONFIGURED LEAVES ALFRED'S FOOTER ALONE\n";
// ─────────────────────────────────────────────────────────────────────────────
// core/skin-view-model.php only fills social when social_dock_enabled is '1',
// so "the owner turned the dock off" and "the owner configured no platform"
// arrive here identically. Neither may leave an empty container in the footer
// for ALFRED's stylesheet to space around.
[$okOff, $htmlOff] = $render([]);
$check('ALFRED still renders with the dock off', $okOff === true);
$check('  and nothing dock-shaped is in the page at all',
       !str_contains($htmlOff, 'social-dock') && !str_contains($htmlOff, 'dock-link'));
$check('  while the footer itself is untouched',
       str_contains($htmlOff, 'id="footer" class="inside"'));

echo "\n";
if ($failures) {
    fwrite(STDERR, 'ALFRED footer dock FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "ALFRED footer social dock: PASS — the real skins/alfred/layout.php, rendered\n";
echo "through the real strict render function, puts the shared dock inside its\n";
echo "footer with every configured link, gets the engine stylesheet from its own\n";
echo "manifest, and renders nothing when no platform is configured.\n";
echo "NOT CHECKED HERE: the rendered appearance. That is a browser measurement,\n";
echo "not a string match, and the captures are named in the commit message.\n";
// ===== SNAPSMACK EOF =====
