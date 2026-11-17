<?php
declare(strict_types=1);

/**
 * WHAT A SKIN DECLARES AND WHAT ITS TEMPLATE DOES MUST AGREE.
 *
 * scrolltime_pages in a manifest is a capability REQUEST: the CMS grants the
 * function, and the skin's own template decides which pages call it. Two ways
 * for that to go silently wrong, and both are silent, which is the problem:
 *
 *   DECLARED BUT NEVER CALLED -- the manifest says the skin wants scroll time
 *   on a page and nothing on that page asks for it, so the site records
 *   nothing and the declaration reads like a promise that was kept.
 *
 *   CALLED BUT NOT DECLARED -- the template asks and the grant refuses, so the
 *   component returns an empty string and the page looks fine. Scroll Time
 *   sits near zero and nobody can see why.
 *
 * So this file RENDERS THE SKIN'S OWN TEMPLATE for each page kind and looks for
 * the tag, rather than reading the layout and guessing what a conditional
 * means. Codex's GLIDE call is guarded on $view['response']['kind'], which no
 * amount of grepping can evaluate.
 *
 * It runs each case in a CHILD PROCESS: snapsmack_scrolltime_tag() has a
 * one-shot guard per request, so a second case in the same process would
 * always see nothing and pass for the wrong reason.
 */

$root = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Child mode: render one skin + one kind and print whether the tag appeared.
// ---------------------------------------------------------------------------
if ($argc > 1) {
    [$slug, $kind, $mode] = [$argv[1], $argv[2], $argv[3]];
    if (!defined('BASE_URL')) define('BASE_URL', '/');
    if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
    require_once $root . '/core/trusted-html.php';
    require_once $root . '/core/skin-manifest.php';
    require_once $root . '/core/skin-render-helpers.php';
    require_once $root . '/core/skin-view-contract.php';
    require_once $root . '/core/stats-logger.php';

    $GLOBALS['settings'] = ['site_mode' => $mode, 'site_name' => 'Fixture'];
    // A real logged hit, because without one the tag never emits and every
    // case would answer "no" for a reason that has nothing to do with the skin.
    $GLOBALS['snapsmack_hit'] = ['id' => 4242, 'page_type' => $kind];

    $manifest = load_skin_manifest($slug);
    $templates = is_array($manifest['templates'] ?? null) ? $manifest['templates'] : [];
    $template = (string)($templates[$kind] ?? $templates['default'] ?? '');
    if ($template === '') { echo 'NOTEMPLATE'; exit; }

    $view = snapsmack_build_skin_view(
        ['kind' => $kind, 'status' => 200, 'items' => [], 'item' => []],
        ['site_name' => 'Fixture', 'base_url' => '/', 'skin_slug' => $slug]
    );
    ob_start();
    $ok = snapsmack_render_strict_skin_template($root . '/skins/' . $slug, $template, $view);
    $html = (string)ob_get_clean();
    if (!$ok) { echo 'RENDERFAILED'; exit; }
    echo str_contains($html, 'id="ss-scrolltime"') ? 'TAG' : 'NOTAG';
    exit;
}

// ---------------------------------------------------------------------------
// Parent mode.
// ---------------------------------------------------------------------------
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/skin-manifest.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}
function render(string $slug, string $kind, string $mode): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' '
         . escapeshellarg($slug) . ' ' . escapeshellarg($kind) . ' ' . escapeshellarg($mode);
    return trim((string)shell_exec($cmd));
}

// A declared page name -> the response kind that serves it. "album" is
// deliberately absent: SLICKR's album pages ARE archive routes
// (snap_route_url('archive', ['album' => ...])), so rendering cannot tell an
// album apart from any other archive. Stated rather than faked.
$KIND_OF = ['landing' => 'landing', 'archive' => 'archive', 'article' => 'post'];
// A mode in which that kind is eligible at all, from snapsmack_scrolltime_tag.
$MODE_FOR = ['landing' => 'photoblog', 'archive' => 'photoblog', 'post' => 'smacktalk'];

// WHICH AUTOMATIC CALLERS ARE STILL IN CORE. While any of them remains, the
// CMS emits the tracker on every eligible page by itself, so a skin's
// declaration cannot yet restrict anything and the negative half below is
// unenforceable. Named so the output says which file to look at.
$autoCallers = [];
foreach ([
    'core/skin-render-helpers.php' => "snapsmack_scrolltime_tag(\$GLOBALS['settings'] ?? [])",
    'core/footer-scripts.php'      => 'snapsmack_scrolltime_tag($settings)',
    'archive.php'                  => 'snapsmack_scrolltime_tag($settings)',
] as $file => $needle) {
    $path = $root . '/' . $file;
    if (is_file($path) && str_contains((string)file_get_contents($path), $needle)) {
        $autoCallers[] = $file;
    }
}
echo 'AUTOMATIC CALLERS STILL IN CORE: '
   . ($autoCallers === [] ? 'none - declarations now govern emission'
                          : implode(', ', $autoCallers)) . "\n";

$declaring = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $file) {
    $slug = basename(dirname($file));
    $m = load_skin_manifest($slug);
    $pages = is_array($m['scrolltime_pages'] ?? null) ? $m['scrolltime_pages'] : [];
    if ($pages !== []) $declaring[$slug] = $pages;
}

echo 'SKINS DECLARING scrolltime_pages: ' . (count($declaring) ?: 'none') . "\n";

// THIS RUNS BEFORE THE EARLY EXIT BELOW, AND THAT ORDER IS THE WHOLE POINT.
// A template that calls the component without the manifest asking is silently
// dead: the grant refuses it, the component returns an empty string, and the
// page looks perfect. The case where NO skin declares anything is exactly when
// a stray call is most likely -- and my first version exited before reaching
// this check, so removing a declaration escaped the test entirely. Found by
// mutating it; the hole was in my own ordering.
echo "\nNOBODY CALLS IT WITHOUT ASKING FOR IT\n";
$callers = 0;
foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    $slug = basename(dirname($layout));
    $src = (string)file_get_contents($layout);
    if (!str_contains($src, "snap_render_component('scroll-time'")) continue;
    $callers++;
    cx(isset($declaring[$slug]),
       "{$slug} calls the component and its manifest declares scrolltime_pages");
}
if ($callers === 0) echo "  --    no skin calls the component yet\n";

if ($declaring === []) {
    echo "\n  No declarations yet, so there is nothing to render-check. This file\n";
    echo "  asserts nothing further rather than passing vacuously.\n";
    echo "\n{$pass} checks, {$fail} failed (no declarations)\n";
    exit($fail > 0 ? 1 : 0);
}

foreach ($declaring as $slug => $pages) {
    echo "\n{$slug} declares: " . implode(', ', $pages) . "\n";

    // 1a. ENFORCEABLE TODAY, and independent of the automatic callers: a skin
    //     that declares the capability must actually ask for it somewhere in
    //     its own template. This is a source check and says so, but it is the
    //     only half of "declared but never called" that the auto-callers
    //     cannot flatter.
    $layout = $root . '/skins/' . $slug . '/layout.php';
    $src = is_file($layout) ? (string)file_get_contents($layout) : '';
    cx(str_contains($src, "snap_render_component('scroll-time'"),
       "declares scrolltime_pages and its layout.php actually calls the component");

    // 1b. The rendered half. WHILE THE AUTOMATIC CALLERS EXIST THIS CANNOT
    //     PROVE THE SKIN DID IT: registered-assets emits on every eligible
    //     page, so the tag would appear even from a template that asked for
    //     nothing. I nearly left this as a passing assertion, which would have
    //     been a check that passes for the wrong reason -- the exact fault I
    //     have spent the night finding in other people's tests and my own.
    //     Reported, not asserted, until the callers come out.
    foreach ($pages as $page) {
        if (!isset($KIND_OF[$page])) {
            echo "  --    '{$page}' cannot be rendered apart from 'archive', so it is NOT CHECKED\n";
            continue;
        }
        $kind = $KIND_OF[$page];
        $r = render($slug, $kind, $MODE_FOR[$kind]);
        if ($autoCallers !== []) {
            echo "  --    declares '{$page}', rendering kind '{$kind}' gives {$r}"
               . " -- INFORMATIONAL ONLY while the automatic callers emit anyway\n";
            continue;
        }
        cx($r === 'TAG',
           "declares '{$page}' and rendering kind '{$kind}' emits the tracker (got {$r})");
    }

    // 2. An ELIGIBLE page it did NOT declare must emit nothing. This is the
    //    half that catches an unconditional call: an ineligible kind would
    //    answer "no tag" whatever the template did, so it would prove nothing.
    //
    //    IT CANNOT BE ENFORCED WHILE THE AUTOMATIC CALLERS EXIST, and that is
    //    not a fault in anybody's wiring. registered-assets still calls
    //    snapsmack_scrolltime_tag() itself, by design, so that removing it
    //    before every skin is wired and packaged does not take scroll time off
    //    the whole fleet in between. While that caller is there, EVERY eligible
    //    page emits regardless of what a skin declares -- so this assertion
    //    would fail on correct work. I found that out by writing it and
    //    watching GLIDE "fail" on archive with a perfectly good conditional.
    //
    //    So it reports NOT CHECKED for now and starts enforcing itself the day
    //    the auto-callers come out. Nothing to remember, nothing to re-enable.
    $declaredKinds = [];
    foreach ($pages as $page) if (isset($KIND_OF[$page])) $declaredKinds[] = $KIND_OF[$page];
    foreach (['landing', 'archive'] as $kind) {
        if (in_array($kind, $declaredKinds, true)) continue;
        if ($autoCallers !== []) {
            echo "  --    does NOT declare '{$kind}': NOT CHECKED while the automatic callers"
               . " still emit on every eligible page\n";
            continue;
        }
        $r = render($slug, $kind, 'photoblog');
        if ($r === 'NOTEMPLATE' || $r === 'RENDERFAILED') {
            echo "  --    kind '{$kind}' could not be rendered for {$slug} ({$r}): NOT CHECKED\n";
            continue;
        }
        cx($r === 'NOTAG',
           "does NOT declare '{$kind}', and that page emits nothing even though the mode allows it (got {$r})");
    }
}

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
