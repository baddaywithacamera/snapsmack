<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The CMS decides WHERE a menu separator goes; the skin decides WHAT it is.
 *
 * snap_render_navigation_tree() hard-coded '|' between top-level items. Three
 * skins already drew their own separator in CSS --
 * `.main-menu > li + li:before { content: "/" }` in ALFRED, TELEGRAM and
 * TILEZ -- so their menus rendered both at once:
 *
 *     HOME | / THE IDEA | / DIARY | / BLOGROLL
 *
 * Sean saw it on baddaywithacamera.ca (TILEZ). The placement rules are
 * genuinely shared and belong in the CMS: between top-level items only,
 * never before the first, never inside a submenu. The character is
 * presentation and belongs to the skin, and '' -- "I draw my own" -- is a
 * real answer rather than a missing one.
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$items = [
    ['label' => 'HOME', 'url' => '/'],
    ['label' => 'THE IDEA', 'url' => '/the-idea'],
    ['label' => 'DIARY', 'url' => '/diary', 'children' => [
        ['label' => 'Child', 'url' => '/child'],
    ]],
];

// --- the CMS emits a menu, never decoration -----------------------------
$html = (string)snap_render_component('navigation-tree', ['items' => $items]);
$check(!str_contains($html, 'class="sep"'),
    'the shared menu renderer must not emit a separator; that reached all 12 skins at once');
$check(substr_count($html, '<li>') + substr_count($html, '<li ') === 4,
    'it must still render every item and its child');
$check(str_contains($html, 'THE IDEA') && str_contains($html, 'Child'),
    'labels and submenus still render');

$source = (string)file_get_contents($root . '/core/skin-render-helpers.php');
$treeStart = strpos($source, 'function snap_render_navigation_tree');
$treeEnd = strpos($source, 'function ', $treeStart + 10);
$tree = substr($source, $treeStart, $treeEnd - $treeStart);
$check(!str_contains($tree, 'class="sep"'),
    'no separator may be reintroduced into the shared menu renderer');

// --- every skin that calls it draws its own ------------------------------
$callers = [];
foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    if (!str_contains((string)file_get_contents($layout), 'navigation-tree')) continue;
    $skin = basename(dirname($layout));
    $callers[] = $skin;
    $css = (string)@file_get_contents($root . '/skins/' . $skin . '/style.css');
    $check(preg_match('/li *\+ *li *(:|::)(before|after)\s*\{[^}]*content:/', $css) === 1,
        $skin . ' calls the shared menu and must draw its own separator in its own stylesheet');
}
$check(count($callers) >= 12,
    'expected at least 12 skins using the shared menu, found ' . count($callers));

printf("menu separators: %d skins use the shared renderer and each owns its own character.
",
    count($callers));

if ($failures) {
    fwrite(STDERR, "Menu separator contract FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Menu separator contract regression passed.\n";

// ===== SNAPSMACK EOF =====
