<?php
declare(strict_types=1);

/**
 * SS-1315. A page carrying a mosaic must load the mosaic engine.
 *
 * On installed build 0.7.993D a strict single longform page held a valid
 * 23-image mosaic payload and loaded NEITHER the mosaic engine nor its
 * stylesheet, so the mosaic rendered blank. Replacing it with 23 ordinary
 * image shortcodes made the photographs appear -- and clicking them still did
 * nothing, because the same route also omitted the lightbox.
 *
 * WHY IT WAS MISSED. snapsmack_public_parse_page_content() registers the
 * lightbox, but only for kind 'page' -- a STATIC page. A longform POST is a
 * different kind and took a branch that registered neither. Two routes, one
 * taught and the other not.
 *
 * So the fix is content-driven, and so is this test: it asks the real
 * function what a response needs, for the shapes a real page carries.
 */

require_once dirname(__DIR__) . '/core/public-runtime.php';

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) $fails[] = $what;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

$MOSAIC = '<div class="ss-masonry snap-mosaic-wall" style="--gap:4px">'
        . '<img src="/img_uploads/a.jpg" data-lightbox-src="/img_uploads/a.jpg"></div>';
$PLAIN  = '<div class="snap-inline-frame align-center"><img src="/img_uploads/b.jpg" '
        . 'class="snap-framed-img asset-full align-center" data-lightbox-src="/img_uploads/b.jpg"></div>';
$PROSE  = '<p>No photographs here at all, just words about them.</p>';

// On a tree without the fix the helper does not exist at all. Say so plainly
// rather than throwing a stack trace at whoever ran this: a reviewer checking
// the branch against dev should read one line and know why.
if (!function_exists('snapsmack_assets_required_by_content')) {
    echo "FAIL: snapsmack_assets_required_by_content() is not defined.
";
    echo "      core/public-runtime.php has no content-driven engine
";
    echo "      registration, so a mosaic on a longform page renders blank.
";
    echo "
FAIL: a page loads the engines its content needs (SS-1315)
";
    exit(1);
}

$need = static fn(array $r): array => snapsmack_assets_required_by_content($r);

// --- THE REPORTED FAULT: a longform POST, not a static page ----------------
$got = $need(['kind' => 'post', 'item' => ['content' => $MOSAIC]]);
$check('A LONGFORM POST WITH A MOSAIC LOADS THE MOSAIC ENGINE',
    in_array('asset:public:ss-engine-mosaic', $got['scripts'], true));
$check('and its stylesheet, without which it renders blank',
    in_array('asset:public:ss-engine-mosaic:css', $got['styles'], true));
$check('and the lightbox, which was the second half of the fault',
    in_array('asset:public:ss-engine-lightbox', $got['scripts'], true));

// --- the workaround Sean used must work too --------------------------------
$got = $need(['kind' => 'post', 'item' => ['content' => $PLAIN]]);
$check('plain image shortcodes get the lightbox, so clicking them does something',
    in_array('asset:public:ss-engine-lightbox', $got['scripts'], true));
$check('but a page with no mosaic does not carry the mosaic engine',
    !in_array('asset:public:ss-engine-mosaic', $got['scripts'], true));

// --- and nothing is loaded for content that needs nothing ------------------
$got = $need(['kind' => 'post', 'item' => ['content' => $PROSE]]);
$check('a prose-only page loads neither engine',
    $got['scripts'] === [] && $got['styles'] === []);
$got = $need(['kind' => 'landing']);
$check('a response with no item at all is handled, not fatal',
    $got['scripts'] === [] && $got['styles'] === []);

// --- EVERY surface, not just the post body ---------------------------------
// A mosaic in an archive card needs the engine as much as one in a post.
$got = $need(['kind' => 'archive', 'items' => [
    ['description' => $PROSE], ['description' => $MOSAIC],
]]);
$check('a mosaic in a LIST of items is found too',
    in_array('asset:public:ss-engine-mosaic', $got['scripts'], true));

$got = $need(['kind' => 'page', 'item' => ['description' => $MOSAIC]]);
$check('and in description as well as content',
    in_array('asset:public:ss-engine-mosaic', $got['scripts'], true));

// --- it reads the RENDERED markup, not the shortcode source ----------------
// Source-matching would fire on a shortcode quoted inside a code sample, and
// would miss content that arrived already rendered.
$got = $need(['kind' => 'post', 'item' => ['content' =>
    '<pre><code>Write [mosaic:12] to place one.</code></pre>']]);
$check('a shortcode QUOTED in a code sample does not load an engine',
    $got['scripts'] === []);

// --- the handles must be the ones the admin preview already proved ---------
$preview = (string)file_get_contents(dirname(__DIR__) . '/core/admin-content-preview.php');
foreach (['asset:public:ss-engine-mosaic:css', 'asset:public:ss-engine-mosaic',
          'asset:public:ss-engine-lightbox'] as $handle) {
    $check("the handle {$handle} matches the working admin preview",
        str_contains($preview, "'" . $handle . "'"));
}

// --- and the common exit actually calls it ---------------------------------
// A helper nobody calls fixes nothing; that is the defect this closes.
$runtime = (string)file_get_contents(dirname(__DIR__) . '/core/public-runtime.php');
$check('the shared public exit calls it, so every strict skin is covered',
    str_contains($runtime, 'snapsmack_assets_required_by_content($response)'));

echo (count($fails) ? "\nFAIL" : "\nPASS")
   . ": a page loads the engines its content needs (SS-1315)\n";
exit(count($fails) ? 1 : 0);
// ===== SNAPSMACK EOF =====
