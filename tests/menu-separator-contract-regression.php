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

// --- every skin that renders a menu answers for its own separator --------
// This originally looked only at skins calling 'navigation-tree', and missed
// the eleven that call the 'navigation' component instead -- so eleven skins
// lost their separator in 0.7.873D and nothing said so. It asks about BOTH
// now, and a skin may legitimately answer "none": three of them set
// .sep { display: none } on purpose and must not be given one back.
// A skin may be menu-less ON PURPOSE, and that has to be written down here or
// the next person reads a lower number and assumes a skin broke.
//
// PHOTOGRAM is the phone companion. Sean's instruction, 25 October 2026: it is
// not supposed to show a menu at all -- the avatar goes to the About page and
// that is the whole of it. SnapSmack is desktop software and the phone skin is
// a courtesy, not a destination.
//
// 0.7.946D removed PHOTOGRAM's menu and this check was lowered from 23 to 22
// to stop it complaining, which is the wrong way round: the number moved and
// nothing recorded why. Naming the skin means a menu appearing in PHOTOGRAM
// again is caught as the change it would be.
$menuless = [
    'photogram' => 'phone skin: the avatar links to About and there is no menu, by design',
];

$callers = $drawing = $deliberately_none = 0;
foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    $skin = basename(dirname($layout));
    $src = (string)file_get_contents($layout);
    $rendersMenu = str_contains($src, 'navigation-tree')
        || str_contains($src, "snap_render_component('navigation'");
    if (isset($menuless[$skin])) {
        $check(!$rendersMenu,
            $skin . ' renders the shared menu, but it is recorded here as menu-less on '
            . 'purpose (' . $menuless[$skin] . '). Either the skin changed or this note is '
            . 'out of date -- ask before deciding which.');
        continue;
    }
    if (!$rendersMenu) continue;
    $callers++;
    $css = (string)@file_get_contents($root . '/skins/' . $skin . '/style.css');
    $draws = preg_match('/li *\+ *li *(:|::)(before|after)\s*\{[^}]*content:/', $css) === 1;
    // "I draw none" is a real answer, and these three said it long before the
    // CMS stopped emitting the span.
    // "None" must be said about the MENU, not anywhere in the stylesheet. A
    // skin with no .sep rule at all was still showing the pipe the CMS drew,
    // unstyled -- so silence is not consent, it is a skin that lost one.
    $hides = preg_match('/(?:nav-menu|nav-links|header-nav)[^{]*\.sep\s*\{[^}]*display:\s*none/', $css) === 1;
    // SPACING, not just a character. ONYX hides its separator and took its
    // 22px gap from `.nav-menu > li { gap }` -- a gap INSIDE each item, which
    // only separated the link from the separator span the CMS used to emit.
    // When that span went, each item held one child, the gap did nothing, and
    // photofri.day's menu rendered as one unbroken word:
    // "HOMEBLOGROLLHOMEHOW IT WORKSF.A.QJOINABOUTTHE BOARD".
    // A menu must be spaced between its ITEMS, by a character or by space.
    $spaced = $draws
        || preg_match('/\.nav-menu\s*\{[^}]*gap\s*:/', $css) === 1
        || preg_match('/\.nav-menu[^{]*li\s*\+\s*li\s*\{[^}]*margin/', $css) === 1
        || preg_match('/\.nav-menu[^{]*a\s*\{[^}]*(?:padding|margin)\s*:/', $css) === 1;
    $check($spaced,
        $skin . ': nothing separates one menu item from the next -- no character, '
        . 'no gap on the list, no margin between items. A gap INSIDE an item does '
        . 'not space the items.');

    $check($draws || $hides,
        $skin . ' renders the shared menu but neither draws a separator nor '
        . 'declares it wants none; it lost the one the CMS used to emit');
    if ($draws) $drawing++; elseif ($hides) $deliberately_none++;
}
// 22 skins, with PHOTOGRAM accounted for by name above rather than by a
// number nobody can explain. If this count drops again, a skin lost its menu.
$check($callers >= 22,
    'expected at least 22 skins rendering the shared menu, found ' . $callers
    . ' (' . count($menuless) . ' skin(s) are menu-less on purpose and are not counted)');
$check($drawing >= 17, 'expected at least 17 skins drawing a separator, found ' . $drawing);

printf("menu separators: %d skins render the shared menu -- %d draw their own character, %d want none.
",
    $callers, $drawing, $deliberately_none);

if ($failures) {
    fwrite(STDERR, "Menu separator contract FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Menu separator contract regression passed.\n";

// ===== SNAPSMACK EOF =====
