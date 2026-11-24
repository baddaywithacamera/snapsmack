<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// THE MENU SAYS WHICH PAGE YOU ARE ON.
//
// SS-1563. The shared navigation renderer emitted links and submenu structure
// and never marked the current destination, so every skin that styles an
// active navigation item showed all of them inactive.
//
// THE PROOF THAT IT WAS REAL, and it is not an argument about best practice:
// FIVE skins already carry the rule and had nothing to apply it to.
//
//   ALFRED, TELEGRAM, TILEZ   .main-menu li.current-menu-item > a
//   ONYX, HEURISTIC           a[aria-current="page"]
//
// Two conventions, both already written, neither emitted. So core emits BOTH
// and no skin has to change. This file checks the skins still say so, because
// if that stops being true the reason for emitting both has gone.
//
// WHERE THE LOGIC LIVES. A strict skin may hold no executable code and the
// security ratchet refuses one. Deciding which destination you are standing
// on is route logic, so the CMS decides it and the skin prints the result.
//
// Run:  php tests/navigation-marks-the-current-page-regression.php

$root = dirname(__DIR__);
require_once $root . '/core/navigation-current.php';
require_once $root . '/core/skin-view-model.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

/** The labels marked current, and the labels marked ancestor. */
$marks = static function (array $items, string $request): array {
    $marked = snapsmack_navigation_mark_current($items, $request);
    $cur = []; $anc = [];
    $walk = static function (array $list) use (&$walk, &$cur, &$anc): void {
        foreach ($list as $i) {
            if (!empty($i['current'])) { $cur[] = $i['label']; }
            if (!empty($i['current_ancestor'])) { $anc[] = $i['label']; }
            if (!empty($i['children'])) { $walk($i['children']); }
        }
    };
    $walk($marked);
    return [$cur, $anc];
};

$MENU = [
    ['label' => 'Home',    'url' => '/',                        'children' => []],
    ['label' => 'Archive', 'url' => '/archive',                 'children' => []],
    ['label' => 'Pages',   'url' => '',                         'children' => [
        ['label' => 'About',   'url' => '/about',   'children' => []],
        ['label' => 'Contact', 'url' => '/contact', 'children' => []],
    ]],
    ['label' => 'Album',   'url' => '/?view=archive&album=3',   'children' => []],
];

echo "Navigation marks the current page\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE FOUR CASES SS-1563 ASKS FOR, BY NAME\n";
// ─────────────────────────────────────────────────────────────────────────────

[$cur, $anc] = $marks($MENU, '/archive');
$check('the current item is marked, and only it', $cur === ['Archive']);
$check('  nothing is marked as an ancestor of a top-level item', $anc === []);

[$cur, ] = $marks($MENU, '/archive');
$check('a non-current item is NOT marked', !in_array('Home', $cur, true));

[$cur, $anc] = $marks($MENU, '/about');
$check('a CHILD item is marked when you are on it', $cur === ['About']);
$check('  and its parent is marked as the ancestor, not as current',
       $anc === ['Pages'] && !in_array('Pages', $cur, true));
$check('  its sibling is left alone', !in_array('Contact', $cur, true));

[$cur, $anc] = $marks($MENU, '/a-page-with-no-menu-link');
$check('a page with no matching menu link marks NOTHING',
       $cur === [] && $anc === []);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE MATCH IS EXACT, WHICH IS THE WHOLE DIFFICULTY\n";
// ─────────────────────────────────────────────────────────────────────────────
// Home's URL is "/". Under any prefix rule it is the current item on every
// page of the site, which is worse than marking nothing: it is confidently
// wrong on every page but one.

[$cur, ] = $marks($MENU, '/archive');
$check('Home is not current merely because every path starts with /',
       $cur === ['Archive']);
[$cur, ] = $marks($MENU, '/');
$check('  and Home IS current on the root, so the rule above is not just strict',
       $cur === ['Home']);

[$cur, ] = $marks($MENU, '/archive/');
$check('a trailing slash is the same destination', $cur === ['Archive']);
[$cur, ] = $marks($MENU, '/ARCHIVE');
$check('case is not a different destination', $cur === ['Archive']);
[$cur, ] = $marks($MENU, 'https://example.test/archive');
$check('an absolute URL in the menu still matches its own path',
       $marks([['label' => 'A', 'url' => 'https://example.test/archive', 'children' => []]],
              '/archive')[0] === ['A']);
[$cur, ] = $marks($MENU, '/archive#top');
$check('a fragment never reached the server and is ignored', $cur === ['Archive']);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SPECIFIC ITEM BEATS THE GENERAL ONE ON THE SAME PATH\n";
// ─────────────────────────────────────────────────────────────────────────────
// MEASURED DURING THE FIX, before scoring was added: request
// /?view=archive&album=3 returned current=Home. An album and a category are
// query destinations on the root path, so a plain yes/no match made HOME the
// current item on every album and category page on the site.

[$cur, ] = $marks($MENU, '/?view=archive&album=3');
$check('an album page marks the album, not Home', $cur === ['Album']);
[$cur, ] = $marks($MENU, '/?view=archive&album=99');
$check('  a DIFFERENT album marks neither (its own item is not in this menu)',
       $cur === ['Home'] || $cur === []);

[$cur, ] = $marks($MENU, '/archive?page=2');
$check('paging does not unmark the page you are standing on', $cur === ['Archive']);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEXACTLY ONE CURRENT ITEM, WHATEVER THE OWNER SAVED\n";
// ─────────────────────────────────────────────────────────────────────────────
// photofri.day really had two menu items both reading HOME, going to two
// different places. aria-current is singular; announcing two current pages is
// worse than announcing none.

$twice = [
    ['label' => 'Home',  'url' => '/', 'children' => []],
    ['label' => 'Home2', 'url' => '/', 'children' => []],
];
[$cur, ] = $marks($twice, '/');
$check('a duplicated destination marks the first and only the first',
       $cur === ['Home']);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA CONTAINER WITH NO DESTINATION IS NEVER CURRENT\n";
// ─────────────────────────────────────────────────────────────────────────────

$check('an empty url never matches, not even an empty request',
       !snapsmack_navigation_target_is_current('', '/')
       && !snapsmack_navigation_target_is_current('', ''));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PARENT THAT IS ALSO A REAL DESTINATION IS THE ANCESTOR, NOT CURRENT\n";
// ─────────────────────────────────────────────────────────────────────────────
// FOUND BY MUTATION. The menu above gives its parent an empty url, so the
// rule that a parent of the current item is an ANCESTOR rather than the
// current item could not be observed: removing it changed nothing and the
// mutant SURVIVED. A Menu Manager parent can perfectly well be a page in its
// own right, and then both would have been marked -- two current items in one
// menu, which is exactly what aria-current cannot express.

$parent_is_a_page = [
    ['label' => 'Gallery', 'url' => '/gallery', 'children' => [
        ['label' => 'Prints', 'url' => '/gallery/prints', 'children' => []],
    ]],
];

[$cur, $anc] = $marks($parent_is_a_page, '/gallery/prints');
$check('standing on the child marks the child alone', $cur === ['Prints']);
$check('  the parent is the ancestor even though it is a real page',
       $anc === ['Gallery'] && !in_array('Gallery', $cur, true));

[$cur, $anc] = $marks($parent_is_a_page, '/gallery');
$check('and standing on the parent itself marks the parent', $cur === ['Gallery']);
$check('  with nothing marked as an ancestor', $anc === []);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND IT REACHES THE RENDERED MARKUP, IN BOTH CONVENTIONS\n";
// ─────────────────────────────────────────────────────────────────────────────
// The marking above is worth nothing if the renderer drops it. Driven through
// the real renderer and read back out of the HTML it printed.

ob_start();
snapsmack_render_navigation_items(snapsmack_navigation_mark_current($MENU, '/about'));
$html = (string)ob_get_clean();

$check('the rendered menu carries class="current-menu-item"',
       str_contains($html, 'current-menu-item'));
$check('  exactly once', substr_count($html, 'current-menu-item') === 1);
$check('the rendered menu carries aria-current="page"',
       str_contains($html, 'aria-current="page"'));
$check('  exactly once, because two would announce two current pages',
       substr_count($html, 'aria-current="page"') === 1);
$check('  and it is on the ABOUT link, not another one',
       (bool)preg_match('#<a href="/about"[^>]*aria-current="page"#', $html));
$check('the parent branch is marked as an ancestor',
       str_contains($html, 'current-menu-ancestor'));
$check('  and the ancestor does NOT carry aria-current',
       !preg_match('#<span[^>]*aria-current#', $html));
$check('the submenu class the skins already rely on is still there',
       str_contains($html, 'menu-item-has-children'));

ob_start();
snapsmack_render_navigation_items(snapsmack_navigation_mark_current($MENU, '/nowhere'));
$none = (string)ob_get_clean();
$check('on a page with no menu link the markup carries NO current marker',
       !str_contains($none, 'current-menu-item')
       && !str_contains($none, 'aria-current'));
$check('  but the menu itself still renders', substr_count($none, '<a href=') === 5);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SKINS THIS WAS FOR STILL ASK FOR IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// If every skin stopped styling a current item, emitting both conventions
// would be dead weight and somebody should be told. This states the count
// rather than asserting a list, so a new skin adopting either one is not a
// failure.

$strip = static function (string $css): string {
    return (string)preg_replace('~/\*.*?\*/~s', '', $css);
};
$klass = []; $aria = [];
foreach (glob($root . '/skins/*/style.css') ?: [] as $sheet) {
    $css = $strip((string)file_get_contents($sheet));
    $name = basename(dirname($sheet));
    if (str_contains($css, 'current-menu-item')) { $klass[] = $name; }
    if (str_contains($css, 'aria-current')) { $aria[] = $name; }
}
printf("        current-menu-item: %d skins (%s)\n", count($klass), implode(', ', $klass) ?: 'none');
printf("        aria-current     : %d skins (%s)\n", count($aria), implode(', ', $aria) ?: 'none');
$check('at least one skin still styles each convention core emits',
       $klass !== [] && $aria !== []);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — the current item is marked, once, in both conventions.\n";
echo "NOT PROVED HERE: how it LOOKS. Whether each skin's active style is\n";
echo "visible at 1920, 1440 and 1024 is a browser measurement and belongs in\n";
echo "the geometry matrix, not in a PHP test that cannot lay out a page.\n";
exit(0);

// ===== SNAPSMACK EOF =====
