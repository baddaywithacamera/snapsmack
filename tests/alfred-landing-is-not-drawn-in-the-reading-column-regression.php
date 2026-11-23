<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// A LANDING IS A GRID OF PHOTOGRAPHS. A READING COLUMN IS FOR PROSE. (SS-1528)
//
// ALFRED's <main> wore "content post-inner reading-column longform" on EVERY
// page kind, landing included. .post-inner is width:800px/max-width:85%, and
// the landing's own .section-inner -- which ALFRED's stylesheet calls "ALFRED's
// 1040px lane" -- sat inside it at max-width:90%. So the landing grid measured
// 720px at a 1920 viewport instead of 1040, with 236px columns instead of 342.
// That is the cramped grid Sean photographed.
//
// MEASURED IN A BROWSER, both before and after, at 1920/1440/1024. Those
// numbers are in the commit message; this file holds the structural property
// they depend on, because a width does not exist until a browser lays the page
// out and a PHP test cannot lay anything out.
//
// WHAT THIS FILE EXECUTES, which is the part that matters: it RENDERS ALFRED's
// real layout.php through snapsmack_render_strict_skin_template for two
// response kinds and reads the class list off the rendered HTML. It does not
// grep the template. A template that produced the right source and the wrong
// output would pass a grep and fail this.
//
// HOW IT IS DONE, AND WHY NOT THE OBVIOUS WAY. The PM ruled (a): branch the
// class list in ALFRED's layout. I built that, and THE SKIN SECURITY RATCHET
// REFUSED IT, correctly -- a conditional in a template is executable logic, and
// a strict skin may not hold any. It caught me breaking the rule I had quoted
// earlier the same day.
//
// So the CMS says what KIND of page this is, through a body class, exactly as
// it already does for archive-page; and ALFRED's STYLESHEET says what a landing
// should look like, which is the skin's own business. No logic in the template:
// only an escaped echo of a CMS value, which NEW HORIZON, TRUE GRIT and HIP TO
// BE SQUARE already do.
//
// AND THAT TURNED UP SOMETHING BIGGER: only 3 of the 30 skins printed that body
// class at all. The other 27, ALFRED among them, never received archive-page,
// is-photo-page, static-transmission or page-slug-*. Core computes them so
// stylesheets can key off them -- there is a comment in core recording that
// IMPACT PRINTER's archive footer collapsed because nothing set archive-page --
// and for 27 skins nothing still does. Reported separately; this commit fixes
// ALFRED only.

$root = dirname(__DIR__);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "SS-1528: ALFRED's landing is not drawn in the post reading column\n\n";

/** Render ALFRED's layout for one response kind and return the <body> classes. */
$body_classes = static function (array $response) use ($root): string {
    $view = snapsmack_build_skin_view($response, [
        'site_name' => 'found textures', 'language' => 'en', 'direction' => 'ltr',
        'skin_style_url' => '', 'base_style_url' => '', 'skin_variant_url' => '',
        'registered_assets' => [], 'owner_custom_code' => '', 'search_dock' => [],
        'crop_circles' => [], 'skin_slug' => 'alfred',
        'skin_canvas_width_option' => '',
        'skin_presentation' => snapsmack_skin_presentation([], 'alfred'),
        'footer' => ['site_name' => 'found textures', 'site_url' => 'https://example.test/', 'slots' => []],
    ]);
    ob_start();
    @snapsmack_render_strict_skin_template($root . '/skins/alfred', 'layout.php', $view);
    $html = (string) ob_get_clean();
    if (preg_match('/<body class="([^"]*)"/', $html, $m) === 1) {
        return trim($m[1]);
    }
    return '';
};

// The CMS's own word for a landing. The stylesheet keys off it.
$marker = 'landing-page';

// ─────────────────────────────────────────────────────────────────────────────
echo "THE LANDING\n";
// ─────────────────────────────────────────────────────────────────────────────

foreach (['feed', 'landing'] as $kind) {
    $cls = $body_classes(['kind' => $kind, 'page_title' => '', 'posts' => [], 'items' => [], 'item' => []]);
    $check("the {$kind} kind renders at all (an empty class list is not a false pass)", $cls !== '');
    // BOTH controllers' words for a landing. smacktalk says 'feed', the public
    // controller says 'landing'; a stylesheet must not have to know which one
    // served the page.
    $check("  and carries {$marker}", str_contains($cls, $marker));
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PAGES THAT ARE ACTUALLY PROSE KEEP THEIR COLUMN\n";
// ─────────────────────────────────────────────────────────────────────────────
// The risk in this change is not the landing, it is everything else: the
// wrapper is shared, and Sean reads his posts in that column.

foreach ([
    'page'    => ['kind' => 'page', 'page_title' => 'About', 'item' => ['id' => 1, 'title' => 'About', 'content' => snapsmack_trusted_html('<p>Words.</p>')], 'items' => []],
    'post'    => ['kind' => 'post', 'page_title' => 'A post', 'item' => ['id' => 1, 'title' => 'A post', 'content' => snapsmack_trusted_html('<p>Words.</p>')], 'items' => []],
    'archive' => ['kind' => 'archive', 'page_title' => 'Archive', 'tiles' => [], 'items' => [], 'item' => []],
] as $name => $response) {
    $cls = $body_classes($response);
    $check("the {$name} kind still renders", $cls !== '');
    $check("  and is NOT marked as a landing", !str_contains($cls, $marker));
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nONE KIND AND ONE KIND ONLY\n";
// ─────────────────────────────────────────────────────────────────────────────
// A condition that matched more kinds than intended would silently widen other
// pages, and nothing above would notice: the three kinds checked are not all of
// them. Counting is what catches that.

$kinds = ['feed', 'landing', 'page', 'post', 'archive', 'photo', 'search', 'hashtag', 'blogroll'];
$marked = [];
foreach ($kinds as $k) {
    $cls = $body_classes(['kind' => $k, 'page_title' => '', 'posts' => [], 'tiles' => [], 'items' => [], 'item' => []]);
    if ($cls !== '' && str_contains($cls, $marker)) { $marked[] = $k; }
}
$check('exactly the TWO landing kinds are marked, and nothing else',
       $marked === ['feed', 'landing']);
if ($marked !== ['feed', 'landing']) {
    echo '        marked kinds: ' . (implode(', ', $marked) ?: '(none)') . "
";
}

// The class is only half of it: without the stylesheet rule the marker is
// decoration. And the template must still carry no logic of its own.
$css = (string) file_get_contents($root . '/skins/alfred/style.css');
$check('the stylesheet widens the landing out of the reading column',
       str_contains($css, 'body.landing-page #scroll-stage.post-inner'));
$layout = (string) file_get_contents($root . '/skins/alfred/layout.php');
$check('and the template prints the CMS class without deciding anything',
       str_contains($layout, "snap_escape_attr(\$view['site']['body_class']")
       && !str_contains($layout, "=== 'feed') ?"));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — the landing is rendered outside the reading column and every prose\n";
echo "kind keeps it, proved by rendering the real template rather than reading it.\n";
echo "NOT PROVED HERE: the widths. Those were measured in a browser at 1920,\n";
echo "1440 and 1024 and are recorded in the commit message. A PHP test cannot\n";
echo "lay out a page, which is the whole reason the geometry fixtures exist.\n";
echo "ALSO NOT FIXED: ALFRED's ARCHIVE grid still measures 720px with 236px\n";
echo "columns, the identical fault on a different page. It is outside the PM's\n";
echo "ruling and is reported rather than changed here.\n";
exit(0);

// ===== SNAPSMACK EOF =====
