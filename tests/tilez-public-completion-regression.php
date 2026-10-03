<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/core/smacktalk-public-controller.php');
$repository = (string)file_get_contents($root . '/core/public-repository.php');
$navigation = (string)file_get_contents($root . '/core/skin-view-model.php');
$layout = (string)file_get_contents($root . '/skins/tilez/layout.php');
$style = (string)file_get_contents($root . '/skins/tilez/style.css');
$manifest = (string)file_get_contents($root . '/skins/tilez/manifest.json');
$menuBuilder = (string)file_get_contents($root . '/assets/js/ss-engine-menu-builder.js');
$routes = (string)file_get_contents($root . '/core/skin-render-helpers.php');

$expect = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$expect(str_contains($repository, 'ownedPhotographsForPost'), 'Single posts lost their ownership fallback.');
$expect(str_contains($controller, "trim(strip_tags((string)\$rendered)) === ''")
    && str_contains($controller, 'smacktalk-owned-photo'), 'Empty post shells no longer recover their owned photograph.');
$expect(str_contains($controller, "\$view === 'categories' || \$view === 'albums'")
    && str_contains($layout, 'tilez-taxonomy-page'), 'Categories or albums bypass the strict TILEZ directory.');
$expect(str_contains($navigation, "'?view=categories'") || str_contains($navigation, "'categories'"),
    'Category navigation no longer resolves through the strict controller.');
$expect(str_contains($style, '.blogroll-grid { columns: 2;')
    && str_contains($style, 'break-inside: avoid'), 'Blogroll groups can create false vertical holes.');
$expect(str_contains($style, '#page > #system-footer { margin-top: auto;'), 'Footer is no longer anchored to the page.');
$expect(str_contains($manifest, '"slug": "the-idea", "label": "THE IDEA"')
    && !str_contains($manifest, '"slug": "about", "label": "ABOUT"'),
    'TILEZ restored the duplicate About text-menu default.');
$expect(str_contains($menuBuilder, 'Supports three levels of nesting')
    && str_contains($menuBuilder, 'makeChildRow')
    && str_contains($menuBuilder, 'menu-grandchildren-list')
    && str_contains($menuBuilder, 'depth < 2 ? clean(item.children, depth + 1) : []'),
    'Menu Manager no longer preserves ordered root, child, and grandchild levels.');
$expect(str_contains($controller, 'snapsmack_smacktalk_slug_url')
    && str_contains($controller, "'redirect_status' => 301")
    && !str_contains($navigation, '?view=page&slug=')
    && str_contains($routes, "\$route === 'page' || \$route === 'post'"),
    'SMACKTALK exposed internal query routing instead of canonical readable slugs.');

echo "TILEZ public completion regression passed.\n";

// ===== SNAPSMACK EOF =====
