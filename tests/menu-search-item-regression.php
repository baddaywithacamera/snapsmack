<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * SS-1578: a search box can be a menu item.
 *
 * The last of the five WordPress menu capabilities Sean needs for
 * mccormickphotography. The other four already worked and I confirmed that by
 * driving this resolver rather than by reading the renderer -- which is how I
 * got it wrong the first time and told another agent four capabilities were
 * fine without having run the supply side.
 *
 * WHAT WAS ACTUALLY MISSING WAS THE TYPE. Nothing let an owner put a search
 * box in the menu on any skin. TILEZ looks like it has one; measured on the
 * live nav at baddaywithacamera.ca, that is a <details> disclosure hand-rolled
 * inside the skin's own tilez-icon-nav block, and ul.main-menu holds six flat
 * page links and no form at all.
 *
 * THE ACCEPTANCE REFERENCE IS SEAN'S OLD SITE, read off it rather than
 * invented. old.mccormickphotography.com emits:
 *
 *   <li class="header-search">
 *     <form method="get" class="search-form" action="...">
 *       <input type="search" class="search-field" name="s" ...>
 *       <button type="submit" class="search-button">
 *         <span class="screen-reader-text">Search</span> <icon>
 *
 * Two deliberate differences, both measured rather than assumed:
 *   - the parameter is `q`, not WordPress's `s`. /archive?q=rust returned
 *     12,197 bytes and 2 photographs on the live site where /archive with no
 *     query returned 2,288,684 bytes and 3,561. `s` would have rendered a box
 *     that searched nothing.
 *   - the icon is an inline SVG, because this product does not load Font
 *     Awesome and the old site's <div class="fa fa-search"> would be an
 *     invisible button here.
 *
 * Run:  php tests/menu-search-item-regression.php
 */

$root = dirname(__DIR__);
$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
require_once $root . '/core/public-route-aliases.php';
require_once $root . '/core/skin-view-model.php';

$pdo = new class extends PDO {
    public function __construct() {}
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        throw new PDOException('no pages table in this check');
    }
};

/** A saved Menu Manager configuration, exactly as smack-menu.php stores it. */
$savedMenu = static function (array $items): array {
    return ['nav_menu_json' => json_encode($items)];
};

echo "SS-1578 search as a menu item\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE RESOLVER TURNS A SAVED search ITEM INTO SOMETHING RENDERABLE\n";
// ─────────────────────────────────────────────────────────────────────────────
// Driven through snapsmack_prepare_skin_navigation(), which is what the strict
// view model actually calls -- all 30 skins are on that path (25 declare
// cms_controller 'public', 5 'smacktalk', and none is outside it).

$nav = snapsmack_prepare_skin_navigation($pdo, $savedMenu([
    ['type' => 'home', 'label' => 'HOME', 'active' => true, 'children' => []],
    ['type' => 'search', 'label' => 'SEARCH PHOTOGRAPHS', 'active' => true, 'children' => []],
]), ['cms_controller' => 'public']);

$check('both items survive the resolver', count($nav) === 2);
$search = $nav[1] ?? [];
$check('the search item is marked as a search KIND, not guessed from its url',
       ($search['kind'] ?? '') === 'search');
$check('  it carries the owner\'s label', ($search['label'] ?? '') === 'SEARCH PHOTOGRAPHS');
$check('  and its url is the archive, which is where a search lands',
       str_contains((string)($search['url'] ?? ''), 'archive'));

$check('an ordinary item is NOT given a kind, so nothing else changes shape',
       !array_key_exists('kind', $nav[0]));
$check('  and the ordinary item still resolves as before',
       ($nav[0]['url'] ?? '') === 'https://example.test/');

$bare = snapsmack_prepare_skin_navigation($pdo, $savedMenu([
    ['type' => 'search', 'label' => '', 'active' => true, 'children' => []],
]), ['cms_controller' => 'public']);
$check('a search item with no label still says something',
       ($bare[0]['label'] ?? '') === 'Search');

$off = snapsmack_prepare_skin_navigation($pdo, $savedMenu([
    ['type' => 'search', 'label' => 'SEARCH', 'active' => false, 'children' => []],
]), ['cms_controller' => 'public']);
$check('a deactivated search item is dropped like any other', $off === []);

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "THE RENDERER PRINTS A FORM, AND THE FORM IS USABLE\n";
// ─────────────────────────────────────────────────────────────────────────────

$html = snap_render_navigation_tree($nav);

$check('the search item is a menu li, styled with the old site\'s class',
       str_contains($html, '<li class="header-search">'));
$check('it is a GET form', str_contains($html, '<form method="get" class="search-form"'));
$check('  with role=search, so it is announced as one',
       str_contains($html, 'role="search"'));
$check('  posting to the archive', preg_match('/action="[^"]*archive[^"]*"/', $html) === 1);
$check('THE INPUT IS NAMED q, which is what the archive answers on',
       str_contains($html, 'type="search" class="search-field" name="q"'));
$check('  it is NOT named s, which would search nothing here',
       !str_contains($html, 'name="s"'));
$check('  and the owner\'s label is the placeholder',
       str_contains($html, 'placeholder="SEARCH PHOTOGRAPHS"'));
$check('the submit button has screen-reader text, not an unlabelled icon',
       str_contains($html, '<span class="screen-reader-text">SEARCH PHOTOGRAPHS</span>'));
// THE LABEL WRAPS THE INPUT, so there is no id to collide. Codex found that
// my first version used for=/id= and that alfred, telegram and tilez each
// render this component TWICE -- a desktop menu and a collapsed one from the
// same items -- so the page carried two inputs with one id and the second
// search box had no working label. Rendering the tree twice is now part of
// what this test checks.
$check('  the input is wrapped in its label, so no id is needed',
       preg_match('#<label class="search-label"><span class="screen-reader-text">[^<]*</span><input type="search"#', $html) === 1);
$check('  and NO id or for attribute is emitted at all',
       !str_contains($html, 'id="snap-menu-search"') && !str_contains($html, 'for='));
$check('rendering the tree TWICE emits no duplicate id, as three skins do',
       (static function () use ($nav) {
           $twice = snap_render_navigation_tree($nav) . snap_render_navigation_tree($nav);
           return substr_count($twice, 'class="header-search"') === 2
               && !str_contains($twice, ' id=');
       })());
$check('  and the icon is an inline svg, hidden from screen readers',
       str_contains($html, '<svg class="search-icon"') && str_contains($html, 'aria-hidden="true"'));
$check('no link is emitted for the search item',
       substr_count($html, '<a ') === 1, 'only HOME should be an anchor');

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "THE OTHER FOUR CAPABILITIES STILL WORK ALONGSIDE IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// This is the whole menu Sean needs, rendered in one pass: a current page, a
// label-only dropdown parent with a child, a custom link, and the search box.

$full = snapsmack_prepare_skin_navigation($pdo, $savedMenu([
    ['type' => 'home', 'label' => 'HOME', 'active' => true, 'children' => []],
    ['type' => 'container', 'label' => 'PORTFOLIO', 'active' => true, 'children' => [
        ['type' => 'custom', 'label' => 'People', 'url' => 'https://example.test/people',
         'active' => true, 'children' => []],
    ]],
    ['type' => 'custom', 'label' => 'ELSEWHERE', 'url' => 'https://elsewhere.test/',
     'target' => '_blank', 'active' => true, 'children' => []],
    ['type' => 'search', 'label' => 'SEARCH', 'active' => true, 'children' => []],
]), ['cms_controller' => 'public']);

// snapsmack_navigation_mark_current() is the real name; my first draft
// called a snapsmack_navigation_mark_current_if_available() that does not
// exist, which is the same invented-name mistake as albumById().
require_once $root . '/core/navigation-current.php';
$marked = snapsmack_navigation_mark_current($full, '/');
$whole = snap_render_navigation_tree($marked);

$check('nested dropdown: a label-only parent with a child list',
       str_contains($whole, '<span>PORTFOLIO</span>')
       && str_contains($whole, '<ul class="nav-submenu">'));
$check('  the child is a link inside the submenu',
       preg_match('#<ul class="nav-submenu"><li><a href="[^"]*people"#', $whole) === 1);
$check('custom link survives, with its new-window handling',
       str_contains($whole, 'href="https://elsewhere.test/"')
       && str_contains($whole, 'rel="noopener noreferrer"'));
$check('and the search box is in the same menu',
       str_contains($whole, 'class="header-search"'));
$check('four root items in all', substr_count($whole, '<li') === 5,
       'four roots plus one child');

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "IT IS REACHABLE: THE OWNER CAN ADD ONE\n";
// ─────────────────────────────────────────────────────────────────────────────
// A type the Menu Manager cannot create is dead code, however well it renders.
// SS-1563 was exactly that, and so were two of my own changes this week.

$manager = (string)file_get_contents($root . '/smack-menu.php');
$builder = (string)file_get_contents($root . '/assets/js/ss-engine-menu-builder.js');
$check('the Menu Manager offers a Search Box control',
       str_contains($manager, 'id="add-search-btn"') && str_contains($manager, 'id="search-label"'));
$check('the builder wires that button', str_contains($builder, "getElementById('add-search-btn')"));
$check('  and pushes an item of type search',
       preg_match("/type:\s*'search'/", $builder) === 1);
$check('the saved JSON keeps the type verbatim, so it survives a reload',
       preg_match('/type:\s*item\.type/', $builder) === 1);

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "THE GRAM NAV SKIPS IT RATHER THAN PRINTING A DEAD LABEL\n";
// ─────────────────────────────────────────────────────────────────────────────
// core/gram-nav-links.php emits links only. An unknown type falls through to
// its empty-url branch and prints <li><span>SEARCH</span></li> -- an item that
// says SEARCH and does nothing, which is worse than its absence.

$gram = (string)file_get_contents($root . '/core/gram-nav-links.php');
$check('gram-nav-links skips a search item',
       preg_match("/\\\$_gn_type === 'search'\\) continue;/", $gram) === 1);

echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — a search box can be added to the menu and renders as a working form.\n";
echo "\n";
echo "NOT CHECKED: no live site runs this yet, and nothing has been rendered in\n";
echo "a browser. The side-by-side against old.mccormickphotography.com's menu\n";
echo "search is owner acceptance and is still owed. The WordPress menu import\n";
echo "dry run for mccormickphotography is a separate piece of the ticket.\n";
exit(0);

// ===== SNAPSMACK EOF =====
