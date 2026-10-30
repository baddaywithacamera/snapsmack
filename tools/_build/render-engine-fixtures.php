<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Render every skin as a page a browser can RUN, to see the four engines work.
 *
 *   php tools/_build/render-engine-fixtures.php <out-dir>
 *
 * WHY THIS IS NOT render-geometry-fixtures.php. That harness exists to measure
 * WIDTHS, and it passes 'registered_assets' => [] on purpose -- a geometry
 * measurement wants no scripts moving things about. Its header also says to
 * keep it runnable in the September checkout, so it is not mine to extend.
 *
 * This one answers a different question. On 29 October 2026 the owner found,
 * by hand, on live sites: Thomas the Bear gone from 28 of 31 skins, the F1 help
 * modal gone, the accept-cookies notice gone, and the keyboard engine missing
 * from six skins. All four were repaired in core. Every check we have reads
 * MARKUP -- it asserts a <script> tag is present. None of them has ever run the
 * engine, pressed the key, or looked at the banner.
 *
 * So those tests say PASS while the honest answer is still NOT CHECKED. This
 * closes the gap by building a page complete on disk: the real resolved asset
 * set for that skin, the real engine source INLINED rather than linked, the
 * real consent banner markup from core, and the real stylesheets. Point a
 * browser at it with no server and no site, press F1, click Allow preferences.
 *
 * WHAT IT STILL CANNOT TELL YOU, said here rather than discovered later:
 *
 *   - It is not a deployed site. A page that works here can still fail on a
 *     live install over CSP, a stale cache, or a skin package nobody rebuilt.
 *     "Installed-live verification" remains a different sentence.
 *   - Scripts are INLINED, so they execute in document order rather than
 *     deferred. An engine depending on defer timing would behave differently
 *     here than in production.
 *   - window.SNAP_SITE_MODE is absent, because ss-config-bootstrap.js reads it
 *     from data-* attributes on its own tag and is stranded (SS-1042). So the
 *     carousel exemption at ss-engine-comms.js:74 fails open here, exactly as
 *     it does on a real strict page today.
 */

$root = dirname(__DIR__, 2);
$out  = $argv[1] ?? ($root . '/.engine-fixtures');

/**
 * ONE SKIN PER PROCESS, and this is not a convenience.
 *
 * snapsmack_consent_banner_tag() and snapsmack_scrolltime_tag() each hold a
 * `static $emitted` one-shot. That is RIGHT in production: one PHP process
 * serves one page request, and a banner must not print twice on a page. But
 * render thirty pages in ONE process and the first skin gets the banner while
 * the other twenty-nine silently do not.
 *
 * I hit exactly that. The first run of this harness reported the consent
 * banner present on 50-shades and missing from 29 skins. Read quickly that is
 * a fleet-wide product fault; it was a fault in my harness. So this file now
 * re-enters itself once per skin, which is the condition production runs
 * under.
 *
 *   php tools/_build/render-engine-fixtures.php <out-dir>          all skins
 *   php tools/_build/render-engine-fixtures.php <out-dir> <skin>   just one
 */
$only = isset($argv[2]) ? (string) $argv[2] : '';

if ($only === '') {
    $skins = [];
    foreach (glob($root . '/skins/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (is_file($d . '/layout.php')) $skins[] = basename($d);
    }
    sort($skins);
    @mkdir($out, 0777, true);
    foreach (glob($out . '/_index-*.json') ?: [] as $stale) @unlink($stale);

    $done = 0;
    foreach ($skins as $slug) {
        $lines = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
             . ' ' . escapeshellarg($out) . ' ' . escapeshellarg($slug) . ' 2>&1',
             $lines, $code);
        if ($code === 0) {
            $done++;
        } else {
            fwrite(STDERR, "FAILED {$slug}: " . implode(' ', array_slice($lines, -2)) . "\n");
        }
    }

    $pages = [];
    foreach (glob($out . '/_index-*.json') ?: [] as $part) {
        $one = json_decode((string) file_get_contents($part), true);
        if (is_array($one)) $pages[] = $one;
        @unlink($part);
    }
    usort($pages, static function ($a, $b) {
        return strcmp((string) $a['skin'], (string) $b['skin']);
    });
    file_put_contents($out . '/index.json', json_encode(
        ['pages' => $pages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    printf("%d of %d skin pages written to %s\n", $done, count($skins), $out);
    exit($done === count($skins) ? 0 : 1);
}

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', 'fixture');

require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/skin-view-model.php';
require_once $root . '/core/asset-registry.php';
require_once $root . '/core/archive-controls.php';
require_once $root . '/core/consent-runtime.php';

@mkdir($out, 0777, true);

/**
 * The handles core declares at its single common exit, read OUT OF CORE.
 *
 * Hard-coding them would make this harness agree with itself rather than with
 * the product: it would keep rendering Thomas after someone deleted the
 * declaration. Parsed from the statement's own terminator, for the reason
 * written into tests/thomas-the-bear-reaches-every-skin.php -- a fixed
 * character window broke the moment a comment was added inside the array.
 */
function ef_declared(string $runtime, string $group): array
{
    $marker = "['required_assets']['" . $group . "'] = array_values(array_unique(array_merge(";
    $fnAt = strpos($runtime, 'function snapsmack_public_runtime(');
    $at = $fnAt === false ? false : strrpos($runtime, $marker);
    if ($at === false || $at < $fnAt) return [];
    $stmt = substr($runtime, $at, 20000);
    $tail = strpos($stmt, ')))');
    if ($tail === false) return [];
    $stmt = substr($stmt, 0, $tail);
    $close = strrpos($stmt, ']');
    if ($close === false) return [];
    $head = strrpos(substr($stmt, 0, $close), '[');
    if ($head === false) return [];
    preg_match_all("/'([A-Za-z0-9:_-]+)'/",
        substr($stmt, $head + 1, $close - $head - 1), $hits);
    return $hits[1];
}

/** A resolved asset URL turned back into the file on disk. */
function ef_local(string $root, string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') $path = $url;
    return $root . '/' . ltrim($path, '/');
}

$runtime  = (string) file_get_contents($root . '/core/public-runtime.php');
$declared = [
    'scripts' => ef_declared($runtime, 'scripts'),
    'styles'  => ef_declared($runtime, 'styles'),
];
if (!$declared['scripts']) {
    fwrite(STDERR, "Could not read the asset declaration out of core/public-runtime.php.\n");
    exit(2);
}
printf("core declares: scripts=%s | styles=%s\n",
    implode(',', $declared['scripts']), implode(',', $declared['styles']) ?: '(none)');

// The banner shows only when the visitor has not chosen. That is the state a
// first-time visitor is in, and the only state worth looking at.
$GLOBALS['snapsmack_consent'] = ['show' => true, 'privacy_url' => '/page/privacy'];
$GLOBALS['settings'] = ['site_mode' => 'photoblog', 'site_name' => 'found textures'];

$tile = 'photo-1200x800.svg';
$port = 'photo-800x1200.svg';
foreach ([[1200, 800, $tile], [800, 1200, $port]] as $spec) {
    file_put_contents($out . '/' . $spec[2],
        '<svg xmlns="http://www.w3.org/2000/svg" width="' . $spec[0] . '" height="' . $spec[1] . '">'
        . '<rect width="100%" height="100%" fill="#777"/></svg>');
}

$items = [];
foreach (range(1, 8) as $n) {
    $src = $n % 3 === 0 ? $port : $tile;
    $items[] = [
        'id' => $n, 'img_slug' => 'photograph-' . $n,
        'img_title' => 'Photograph ' . $n, 'title' => 'Photograph ' . $n,
        'presentation_title' => 'Photograph ' . $n,
        'img_alt' => 'Photograph ' . $n, 'excerpt' => 'Some words.',
        'img_file' => $src, 'img_thumb_aspect' => $src, 'img_thumb_square' => $src,
        'featured_image_path' => $src, 'presentation_image' => $src,
        'img_width' => $n % 3 === 0 ? 800 : 1200,
        'img_height' => $n % 3 === 0 ? 1200 : 800,
        'url' => '/photograph-' . $n,
    ];
}

$nav = [
    ['label' => 'Home', 'url' => '/'],
    ['label' => 'Blogroll', 'url' => '/blogroll'],
];

$index   = [];
$written = 0;
foreach (glob($root . '/skins/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $skin = basename($dir);
    if ($skin !== $only) continue;
    if (!is_file($dir . '/layout.php')) continue;
    $manifest = is_file($dir . '/manifest.json')
        ? json_decode((string) file_get_contents($dir . '/manifest.json'), true) : [];
    if (!is_array($manifest)) $manifest = [];

    // The same resolution index.php performs: the skin's own declared assets
    // merged with what core requires at its exit.
    $assets = snapsmack_merge_registered_assets(
        snapsmack_skin_declared_assets($manifest), $declared);

    $response = [
        'kind' => 'archive', 'page_title' => 'Archive',
        'items' => $items, 'item' => [],
        'next_page' => 2, 'has_more' => true, 'total_count' => 60, 'total_pages' => 3,
        'rendered_content' => snapsmack_trusted_html('<p>Some words.</p>'),
        'navigation' => $nav,
    ];
    $controls = snapsmack_archive_controls(
        ['archive_layout' => 'thumbs', 'archive_calendar_enabled' => '1'], $manifest);
    $response['archive_layouts'] = snapsmack_archive_layout_links(
        $controls['layouts'], 'https://example.test/', ['archive' => 'archive'], '');
    $response['archive_calendar'] = $controls['calendar'];
    $response['archive_layout_active'] = $controls['active'];

    $view = snapsmack_build_skin_view($response, [
        'site_name' => 'found textures', 'language' => 'en', 'direction' => 'ltr',
        'skin_style_url' => '', 'base_style_url' => '', 'skin_variant_url' => '',
        'registered_assets' => $assets,
        'owner_custom_code' => '', 'search_dock' => [],
        'skin_slug' => $skin,
        'skin_canvas_width_option' => (string) ($manifest['features']['canvas_width_option'] ?? ''),
        'skin_presentation' => snapsmack_skin_presentation([], $skin),
        'footer' => [
            'site_name' => 'found textures', 'site_url' => 'https://example.test/',
            'slots' => [['kind' => 'copyright', 'year' => '2026', 'site_name' => 'found textures']],
        ],
    ]);

    ob_start();
    @snapsmack_render_strict_skin_template($dir, 'layout.php', $view);
    $html = (string) ob_get_clean();
    if (trim($html) === '') continue;

    $css = '';
    $sheets = [];
    foreach (['assets/css/public-base.css', 'skins/' . $skin . '/style.css'] as $p) {
        if (!is_file($root . '/' . $p)) continue;
        $sheets[] = $p;
        $css .= "\n/* ==== " . $p . " ==== */\n" . (string) file_get_contents($root . '/' . $p);
    }
    foreach ($assets['styles'] as $url) {
        $local = ef_local($root, $url);
        if (!is_file($local)) continue;
        $rel = str_replace('\\', '/', substr($local, strlen($root) + 1));
        $sheets[] = $rel;
        $css .= "\n/* ==== " . $rel . " ==== */\n" . (string) file_get_contents($local);
    }
    $html = preg_replace('#<link[^>]+rel=["\']stylesheet["\'][^>]*>#i', '', $html) ?? $html;

    // INLINE THE ENGINES. A linked src under file:// resolves against the
    // drive root and silently never loads, and a page where the engine never
    // loaded looks exactly like a page where the engine is broken. Inlining is
    // the whole reason this harness can answer the question.
    $missing = [];
    $appended = [];
    foreach ($assets['scripts'] as $url) {
        $local = ef_local($root, $url);
        $base = basename(ef_local('', $url));
        if (!is_file($local)) { $missing[] = $base; continue; }
        $inline = '<script data-inlined-from="' . htmlspecialchars($base, ENT_QUOTES) . '">'
                . "\n" . (string) file_get_contents($local) . "\n</script>";
        // MATCH with a regex, SUBSTITUTE with str_replace.
        //
        // preg_replace interprets the REPLACEMENT string: a backslash pair
        // collapses to one. Engine source is full of them, so passing it as a
        // replacement quietly rewrote the JavaScript -- ss-engine-consent.js's
        // character class [\]\\] became [\]\] and the browser threw "Invalid
        // regular expression: missing /". The engine then never published
        // window.snapConsent, and the page looked exactly like a product with
        // a broken consent engine. Exactly one character out of 4767, and it
        // cost an hour of chasing a fault that was not there.
        $pattern = '#<script[^>]+src=["\'][^"\']*' . preg_quote($base, '#') . '[^"\']*["\'][^>]*>\s*</script>#i';
        if (preg_match($pattern, $html, $hit)) {
            $html = str_replace($hit[0], $inline, $html);
            continue;
        }
        // The skin rendered no tag for it. Append, and record that it was
        // appended rather than found, so the checker can tell the difference
        // between "the skin emitted it" and "this harness added it".
        $appended[] = $base;
        $html = str_contains($html, '</body>')
            ? str_replace('</body>', $inline . "\n</body>", $html)
            : $html . $inline;
    }

    $html = str_replace('src="/photo-', 'src="photo-', $html);
    $style = '<style>' . $css . '</style>';
    $html = str_contains($html, '</head>')
        ? str_replace('</head>', $style . '</head>', $html)
        : $style . $html;

    $target = $out . '/' . $skin . '--archive.html';
    file_put_contents($target, $html);
    $index[] = [
        'skin' => $skin,
        'file' => basename($target),
        'scripts' => $assets['scripts'],
        'styles' => $assets['styles'],
        'missing_files' => $missing,
        'appended_not_emitted' => $appended,
        'stylesheets' => $sheets,
        'skin_version' => (string) ($manifest['version'] ?? ''),
    ];
    $written++;
}

// Each child process writes its own part; the parent above stitches them.
if ($index) {
    $part = $index[0];
    $part['declared'] = $declared;
    file_put_contents($out . '/_index-' . $part['skin'] . '.json',
        json_encode($part, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
printf("%s: %d page\n", $only, $written);

// ===== SNAPSMACK EOF =====
