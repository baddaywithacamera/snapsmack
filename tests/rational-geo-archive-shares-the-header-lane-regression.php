<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// RATIONAL GEO'S ARCHIVE WALL AND ITS HEADER SHARE ONE LANE. (SS-1517)
//
// #justified-grid declared a max-width and no side padding. Above the owner's
// Content Width setting the max-width binds and the wall is centred, so the
// fault hid; between about 1025 and 1400 the wall filled the viewport edge to
// edge while the masthead kept its 2rem inset. Measured in a browser at 1280:
// tiles at x=0, masthead at x=32.
//
// WHAT THIS FILE CAN AND CANNOT PROVE. The fault is geometry and the proof is
// geometry: the real layout rendered through the strict render function,
// served over localhost and measured at 1024, 1280, 1366 and 1920, where the
// wall's outer tile edges and the header's content now agree to 0px with no
// horizontal overflow. Those numbers are in the commit message. PHP cannot
// re-run that. What it CAN do is hold the declarations that produced it, so
// the lane cannot be unpicked by an edit that nobody renders afterwards —
// which is how it came apart in the first place.

$root = dirname(__DIR__);
define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "SS-1517: RATIONAL GEO's archive wall shares the header's lane\n\n";

$css = (string)file_get_contents($root . '/skins/rational-geo/style.css');

/** The declaration block for one selector, matched by brace depth. */
$block = static function (string $css, string $selector): string {
    $at = strpos($css, $selector . ' {');
    if ($at === false) { return ''; }
    $open = strpos($css, '{', $at);
    $depth = 0;
    for ($i = $open; $i < strlen($css); $i++) {
        if ($css[$i] === '{') { $depth++; }
        elseif ($css[$i] === '}') { $depth--; if ($depth === 0) { return substr($css, $open + 1, $i - $open - 1); } }
    }
    return '';
};

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE WALL IS INSET, IN THE SAME MEASURE AS THE HEADER\n";
// ─────────────────────────────────────────────────────────────────────────────
// Asserted as "the same token the header uses", not as a number. A hardcoded
// 32px would pass a check for 32px and still drift the day the header's
// spacing changes, which is the whole failure being repaired.
// COMMENTS ARE STRIPPED BEFORE ANY OF THIS IS READ. The first version of the
// check below looked for "55px" anywhere in the block and passed while the
// declaration had lost it, because the comment above the declaration says the
// words "55px footer clearance". A mutation that deleted the real value
// survived. A comment is not evidence here any more than anywhere else.
$decls = static fn(string $s): string => (string)preg_replace('#/\*.*?\*/#s', '', $s);
$grid = $decls($block($css, '#justified-grid'));
$head = $decls($block($css, '.rg-header-inside'));
$check('#justified-grid declares side padding at all',
       preg_match('/padding:\s*0\s+var\(--space-xl\)/', $grid) === 1);
$check('  and it is the token the header uses, not a number of its own',
       str_contains($head, 'var(--space-xl)'));
$check('  with the 55px footer clearance Sean asked for still intact',
       preg_match('/padding:\s*0\s+var\(--space-xl\)\s+55px\s*;/', $grid) === 1);

// Below 1025 the header narrows its inset. The wall has to follow, or the two
// part company by 8px at exactly 1024 — measured, before this was added.
$narrow = substr($css, (int)strpos($css, '@media (max-width: 1024px)'));
$narrow = substr($narrow, 0, (int)strpos($narrow, '@media (max-width: 768px)'));
$check('at 1024 the header steps down to the narrower inset',
       preg_match('/\.rg-header-inside\s*\{[^}]*var\(--space-lg\)/s', $narrow) === 1);
$check('  and the wall steps down with it, to the same token',
       preg_match('/#justified-grid\s*\{[^}]*padding-left:\s*var\(--space-lg\)/s', $narrow) === 1
       && preg_match('/#justified-grid\s*\{[^}]*padding-right:\s*var\(--space-lg\)/s', $narrow) === 1);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE ARCHIVE STILL RENDERS, AND STILL HAS NO archive-page CLASS\n";
// ─────────────────────────────────────────────────────────────────────────────
// core/skin-view-contract.php computes an archive-page body class; this skin's
// layout builds its body class by hand and never prints it, so every
// body.archive-page rule in the stylesheet is dead. That is recorded here as a
// measured fact rather than left for the next reader to rediscover, and it is
// why the fix is padding on the wall and NOT switching that class on: the
// class carries a rule zeroing the header's padding for an "edge-to-edge"
// wall, and the owner's own archive capture shows the wall inset and centred.
$items = [];
for ($i = 1; $i <= 8; $i++) {
    $items[] = ['id' => $i, 'img_title' => 'Photograph ' . $i, 'url' => '#',
                'img_file' => '/t' . $i . '.jpg', 'img_slug' => 'p' . $i,
                'img_width' => 1600, 'img_height' => 1067];
}
$view = snapsmack_build_skin_view([
    'kind' => 'archive', 'status' => 200, 'navigation' => [], 'page_title' => 'Archive',
    'items' => $items, 'tiles' => $items,
], [
    'site_name' => 'RATIONAL GEO', 'language' => 'en', 'direction' => 'ltr',
    'skin_style_url' => '/skins/rational-geo/style.css',
    'skin_custom_style' => snapsmack_trusted_html(''),
    'skin_presentation' => ['rational' => ['show_map' => '1']],
    'footer' => ['text' => ''], 'registered_assets' => [],
    'owner_custom_code' => snapsmack_trusted_html(''),
]);
ob_start();
$ok = snapsmack_render_strict_skin_template($root . '/skins/rational-geo', 'layout.php', $view);
$html = (string)ob_get_clean();

$check('the strict renderer accepted RATIONAL GEO\'s real layout', $ok === true);
$check('  the archive is #justified-grid', str_contains($html, 'id="justified-grid"'));
$check('  with every tile in it', substr_count($html, 'ss-masonry-item') === 8);
preg_match('/<body class="([^"]*)"/', $html, $m);
$bodyClass = $m[1] ?? '';
$check('  and the body class carries no archive-page, so those rules stay dead',
       $bodyClass !== '' && !str_contains($bodyClass, 'archive-page'));
$check('  (the CMS does compute one, which is why this is worth pinning)',
       in_array('archive-page',
           (array)(snapsmack_build_skin_view(['kind' => 'archive', 'status' => 200], [])['site']['body_class'] ?? []), true)
       || str_contains((string)file_get_contents($root . '/core/skin-view-contract.php'), "bodyClasses[] = 'archive-page'"));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE DEAD MASONRY RULES ARE GONE AND STAY GONE\n";
// ─────────────────────────────────────────────────────────────────────────────
// .rg-archive-grid had four blocks including responsive steps at 1024, 768 and
// 480. Nothing renders that element: the archive container is #justified-grid.
// Removed, and pinned so it cannot creep back and be mistaken for live rules.
$check('no .rg-archive-grid rules remain in the stylesheet',
       !str_contains($css, '.rg-archive-grid'));
$rendersIt = false;
foreach ((array)glob($root . '/skins/*/layout.php') as $layout) {
    if (str_contains((string)file_get_contents($layout), 'rg-archive-grid')) { $rendersIt = true; }
}
foreach ((array)glob($root . '/core/*.php') as $core) {
    if (str_contains((string)file_get_contents($core), 'rg-archive-grid')) { $rendersIt = true; }
}
$check('  and nothing in any skin or core renders that element',
       $rendersIt === false);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1517 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "SS-1517 PASS — the wall takes the header's own spacing token at both\n";
echo "steps, the archive still renders through the real strict path with no\n";
echo "archive-page class, and the dead masonry rules are gone.\n";
echo "NOT PROVED HERE: the rendered geometry. It was measured in a browser at\n";
echo "1024/1280/1366/1920 and those numbers are in the commit message.\n";
// ===== SNAPSMACK EOF =====
