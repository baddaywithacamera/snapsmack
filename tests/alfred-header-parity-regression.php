<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// SS-1528 PIECE 2. ALFRED's masthead consumes only owner-configured values
// and the shared social-dock component. Nothing here, or in the skin, knows
// which blog is rendering it.

$root = dirname(__DIR__);
define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/asset-registry.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) $failures[] = $what;
};

echo "SS-1528 piece 2: ALFRED owner masthead parity\n\n";

$layout = (string)file_get_contents($root . '/skins/alfred/layout.php');
$css = (string)file_get_contents($root . '/skins/alfred/style.css');
$manifest = json_decode((string)file_get_contents($root . '/skins/alfred/manifest.json'), true,
                        512, JSON_THROW_ON_ERROR);

$check('the logo remains an owner image setting',
       ($manifest['options']['header_logo']['type'] ?? '') === 'image');
$check('the skin prints the bounded header logo supplied by core',
       str_contains($layout, "['header_media']['logo']"));
$check('the masthead places the shared dock component',
       substr_count($layout, "snap_render_component('social-dock'") === 2);
$check('the same dedicated CMS model feeds both placements',
       substr_count($layout, "'model' => \$view['site']['social_dock']") === 2);
$check('the header dock is centred by ALFRED presentation CSS',
       preg_match('/\.site-header \.social-dock-inline\s*\{[^}]*justify-content:\s*center;[^}]*margin:\s*24px auto 0;/s', $css) === 1);
$check('the header circles use the owner-approved compact size',
       preg_match('/\.site-header \.dock-link\s*\{[^}]*flex:\s*0 0 36px;[^}]*width:\s*36px;[^}]*height:\s*36px;/s', $css) === 1);
$check('header and footer use a transparent ring coloured by the shared appearance setting',
       preg_match('/\.site-header \.dock-link,[\s\S]*?#system-footer \.dock-link:hover\s*\{[^}]*background:\s*transparent;[^}]*border-color:\s*var\(--dock-icon\);/s', $css) === 1);

$forbidden = ['mccormickphotography', 'old.mccormickphotography',
              'Saving the beauty', 'Sean McCormick Signature'];
foreach ($forbidden as $value) {
    $check("no blog-specific value appears: {$value}",
           stripos($layout . $css . json_encode($manifest), $value) === false);
}

$social = [
    ['url' => 'https://example.test/one', 'label' => 'One', 'icon' => 'website'],
    ['url' => 'https://example.test/two', 'label' => 'Two', 'icon' => 'flickr'],
];
$dock = static fn(array $items): array => [
    'identity' => 'Example Owner', 'home_url' => '/', 'navigation' => [],
    'icon_sprite_url' => '/assets/icons/crop-circles.svg',
    'search' => ['action' => '/', 'placeholder' => 'Search'], 'filters' => [],
    'social' => $items,
    'appearance' => ['icon' => '#fff', 'background' => '#111',
                     'background_hover' => '#222', 'border' => '#777',
                     'border_hover' => '#fff', 'opacity' => 1],
];

$render = static function (array $headerMedia, array $social) use ($root, $dock): string {
    $view = snapsmack_build_skin_view([
        'kind' => 'feed', 'status' => 200, 'navigation' => [], 'posts' => [],
    ], [
        'site_name' => 'Example Blog', 'tagline' => 'An owner supplied tagline',
        'language' => 'en', 'direction' => 'ltr',
        'skin_style_url' => '/skins/alfred/style.css',
        'skin_custom_style' => snapsmack_trusted_html(''),
        'skin_presentation' => ['header_media' => $headerMedia],
        'social_dock' => $dock($social),
        'footer' => ['text' => 'Copyright', 'lowercase' => false],
        'registered_assets' => [], 'owner_custom_code' => snapsmack_trusted_html(''),
    ]);
    ob_start();
    $ok = snapsmack_render_strict_skin_template($root . '/skins/alfred', 'layout.php', $view);
    $html = (string)ob_get_clean();
    if (!$ok) throw new RuntimeException('ALFRED strict render failed');
    return $html;
};

$html = $render([
    'image' => '', 'logo' => '/uploads/owner-logo.png',
    'retina' => false, 'show_tagline' => true,
], $social);
$flat = str_replace("\n", '', $html);
$headerStart = strpos($flat, '<header id="header"');
$headerEnd = strpos($flat, '</header>');
$logoAt = strpos($flat, '/uploads/owner-logo.png');
$taglineAt = strpos($flat, 'An owner supplied tagline');
$firstDockAt = strpos($flat, 'class="social-dock');
$footerAt = strpos($flat, '<footer id="system-footer"');

$check('a configured owner logo renders in the real strict template', $logoAt !== false);
$check('the owner tagline renders beneath the logo',
       $logoAt !== false && $taglineAt !== false && $taglineAt > $logoAt);
$check('the first shared dock renders beneath the tagline inside the header',
       $headerStart !== false && $headerEnd !== false && $firstDockAt !== false
       && $taglineAt !== false && $firstDockAt > $taglineAt
       && $firstDockAt < $headerEnd);
$check('the second shared dock remains in the footer',
       $footerAt !== false && strrpos($flat, 'class="social-dock') > $footerAt);
$check('both placements carry every configured owner link',
       substr_count($html, 'class="dock-link u-url"') === 4);

$empty = $render([
    'image' => '', 'logo' => '', 'retina' => false, 'show_tagline' => true,
], []);
$check('an empty/default install falls back to the site title',
       str_contains($empty, '<span class="site-title-text">Example Blog</span>'));
$check('an empty/default install emits no empty social dock',
       !str_contains($empty, 'class="social-dock'));
$check('an empty/default install still renders its owner tagline',
       str_contains($empty, 'An owner supplied tagline'));

echo "\n";
if ($failures) {
    fwrite(STDERR, 'ALFRED header parity FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $failure) fwrite(STDERR, "  - {$failure}\n");
    exit(1);
}
echo "ALFRED header parity: PASS — owner logo, centred tagline and the shared\n";
echo "dock render in the masthead without blog-specific values; the default\n";
echo "install falls back cleanly and emits no empty dock.\n";
// ===== SNAPSMACK EOF =====
