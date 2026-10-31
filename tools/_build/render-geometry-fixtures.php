<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Render every skin's pages as self-contained HTML a browser can measure.
 *
 * WHY. Every check in this suite reads HTML: is the class there, is the
 * element there, does the markup match. Sean's faults are widths -- a hero
 * wider than the text under it, a title with no gap below the navigation,
 * uneven space either side of a nav separator. A width does not exist until a
 * browser lays the page out, so every one of those passed every gate and
 * landed on his screen, and he found them by opening sites one at a time.
 *
 * This writes the pages out with their REAL stylesheets inlined, so a browser
 * can be pointed at them with no server, no network and no site. Asset
 * resolution is the same path the runtime uses, via core/asset-registry.php,
 * because the whole point is to measure what a skin actually receives.
 *
 * DETERMINISM. Every photograph is a data-URI SVG of a stated intrinsic size,
 * so a measurement is reproducible and never depends on a download. A real
 * JPEG would make the numbers depend on the network.
 *
 * This file is deliberately runnable in an OLD checkout as well as this one:
 * it is copied into a detached worktree at the September reference commit so
 * the same generator produces both sides of the comparison. Keep it free of
 * anything added after that commit.
 *
 *   php tools/_build/render-geometry-fixtures.php <out-dir>
 */

$root = dirname(__DIR__, 2);
$out  = $argv[1] ?? ($root . '/.geometry-fixtures');

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/asset-registry.php';
require_once $root . '/core/archive-controls.php';

if (!is_dir($out) && !@mkdir($out, 0777, true) && !is_dir($out)) {
    fwrite(STDERR, "Cannot create {$out}\n");
    exit(1);
}

/**
 * A photograph of a stated size, written beside the fixtures and referenced
 * by a RELATIVE path.
 *
 * Not a data: URI. The first attempt used one and every photograph rendered
 * with src="" -- snap_escape_url refuses a data: URL, which is correct and is
 * the CMS protecting itself. A fixture that trips a security control is a bad
 * fixture, not a reason to weaken the control.
 */
function gf_photo(string $out, int $w, int $h): string {
    $name = 'photo-' . $w . 'x' . $h . '.svg';
    if (!is_file($out . '/' . $name)) {
        file_put_contents($out . '/' . $name,
            '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '">'
          . '<rect width="100%" height="100%" fill="#888"/></svg>');
    }
    return $name;
}

/** The stylesheets this skin's page really receives, as repo paths. */
function gf_stylesheets(string $root, string $skin, array $manifest, string $kind = ''): array {
    $paths = ['assets/css/public-base.css', 'skins/' . $skin . '/style.css'];
    // The archive control row's stylesheet. Every archive page now receives
    // it from the CMS, declared as a required asset by the runtime, because
    // the engine that used to carry it was hardcoded in a <script> tag at the
    // bottom of archive.php -- a file no strict skin runs, so twenty-five
    // skins got no archive CSS at all. The harness delivers it the same way,
    // or its pictures show an unstyled row the live site does not have.
    if ($kind === 'archive') $paths[] = 'assets/css/page-archive.css';
    if (function_exists('snapsmack_skin_declared_assets')) {
        $declared = snapsmack_skin_declared_assets($manifest);
        foreach (($declared['styles'] ?? []) as $url) {
            $path = parse_url((string)$url, PHP_URL_PATH) ?: (string)$url;
            $path = ltrim(preg_replace('#^.*?(assets/|skins/)#', '$1', $path) ?? '', '/');
            if ($path !== '') $paths[] = $path;
        }
    }
    return array_values(array_unique(array_filter($paths, static fn($p) => is_file($root . '/' . $p))));
}

$hero = gf_photo($out, 1600, 1067);
$tile = gf_photo($out, 1200, 800);
$port = gf_photo($out, 800, 1200);
$sqre = gf_photo($out, 1000, 1000);

/**
 * GLIDE's landing wall, built the way the controller builds it.
 *
 * WHY THIS EXISTS. The harness rendered every skin's archive and static page
 * and NO skin's landing, so the one page GLIDE actually is -- a full-window
 * wall of drifting rows -- was never measured. SS-1093 lived there unseen: the
 * engine wraps each row at a quarter of the track's real width, which was
 * correct when the markup repeated each row four times and has been wrong
 * since it stopped. No check could see it because no fixture drew the wall.
 *
 * The row dealing and the per-tile aspect are deliberately a MIRROR of
 * core/public-controller.php, not an invention: nine rows, photographs dealt
 * round-robin, and presentation_aspect written as width/height with a 3/2
 * fallback when a dimension is missing. If the controller changes, this has to
 * change with it, and a fixture that quietly disagrees with the runtime is
 * worse than no fixture.
 *
 * 196 photographs, because that is what the site this was asked about holds.
 * Only four SVG files are written; the items reference them by name.
 */
/**
 * A numbered plate, so a screenshot says WHICH photograph it is showing.
 *
 * The first version of the wall fixture used four shared grey rectangles.
 * Every plate looked like every other plate, so a picture of the wall could
 * not certify which photographs the wrap had skipped -- which is the whole
 * question SS-1093 asks. Codex said so after rendering it, and he was right:
 * the engine invocation was proven and the behaviour was not.
 *
 * One file per plate, a few hundred bytes each, with the index drawn large
 * enough to read in a screenshot of a 375px-wide tile. The shape still varies
 * so the aspect interlocking is unchanged.
 */
function gf_numbered_photo(string $out, int $w, int $h, int $n): string {
    $name = 'plate-' . $n . '-' . $w . 'x' . $h . '.svg';
    if (!is_file($out . '/' . $name)) {
        $font = (int)round(min($w, $h) * 0.42);
        file_put_contents($out . '/' . $name,
            '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '">'
          . '<rect width="100%" height="100%" fill="#888"/>'
          . '<text x="50%" y="50%" fill="#111" font-family="monospace" font-weight="bold"'
          . ' font-size="' . $font . '" text-anchor="middle" dominant-baseline="central">'
          . $n . '</text></svg>');
    }
    return $name;
}

function gf_glide_wall(string $out): array {
    $items = [];
    foreach (range(1, 196) as $n) {
        // A FEW WITH NO STORED DIMENSIONS, on purpose. The controller falls
        // back to 3/2 for those, and the stylesheet then object-fit covers the
        // photograph into a box that is not its shape -- so a portrait with no
        // recorded size is cropped hard. That is the same fault repaired in
        // the search results in 964D, and it has to be visible here.
        $missing = ($n % 7 === 0);
        if ($n % 3 === 0)      { $w = 800;  $h = 1200; }
        elseif ($n % 5 === 0)  { $w = 1000; $h = 1000; }
        else                   { $w = 1200; $h = 800;  }
        // NUMBERED, so a screenshot of the wall says which plates the wrap
        // reached and which it never showed.
        $src = gf_numbered_photo($out, $w, $h, $n);

        $item = [
            'id' => $n, 'img_slug' => 'photograph-' . $n,
            'img_title' => 'Photograph ' . $n,
            'img_alt' => 'Photograph ' . $n,
            'img_file' => $src, 'img_thumb_aspect' => $src, 'img_thumb_square' => $src,
            'url' => '/photograph-' . $n,
        ];
        if (!$missing) { $item['img_width'] = $w; $item['img_height'] = $h; }

        // EXACTLY core/public-controller.php: max(1, (int)(width ?? 3)) over
        // max(1, (int)(height ?? 2)), printed as "w/h".
        $aw = max(1, (int)($item['img_width'] ?? 3));
        $ah = max(1, (int)($item['img_height'] ?? 2));
        $item['presentation_aspect'] = $aw . '/' . $ah;
        $items[] = $item;
    }
    $rows = array_fill(0, 9, []);
    foreach ($items as $index => $item) $rows[$index % 9][] = $item;
    return ['items' => $items, 'rows' => $rows];
}

$gfWall = gf_glide_wall($out);

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

$kinds = [
    'page' => [
        'kind' => 'page', 'page_title' => 'About Sean',
        'item' => [
            'id' => 1, 'title' => 'About Sean', 'slug' => 'about',
            'content' => snapsmack_trusted_html(
                '<p>First paragraph of the page body, long enough to show the column it sits in '
              . 'and where its left edge falls against everything above it.</p>'
              . '<p>Second paragraph, so the block has height.</p>'),
            'image_asset' => $hero, 'image_size' => 'full', 'image_align' => 'center',
        ],
        'rendered_content' => snapsmack_trusted_html('<p>First paragraph of the page body.</p>'),
        'items' => [],
    ],
    'archive' => [
        'kind' => 'archive', 'page_title' => 'Archive',
        'items' => $items, 'item' => [],
        'next_page' => 2, 'has_more' => true, 'total_count' => 60, 'total_pages' => 3,
        'rendered_content' => snapsmack_trusted_html('<p>Some words.</p>'),
    ],
    // Rendered ONLY for skins whose manifest declares features.has_landing --
    // see the skip in the loop below. Every other skin would fall into its
    // layout's catch-all branch and write a page that looks like a landing and
    // is not one, which is exactly the kind of fixture that teaches a wrong
    // lesson.
    'landing' => [
        'kind' => 'landing', 'page_title' => '',
        'rows' => $gfWall['rows'], 'items' => $gfWall['items'], 'item' => [],
        'total_count' => 196,
        'rendered_content' => snapsmack_trusted_html(''),
    ],
];

$nav = [
    ['label' => 'Home', 'url' => '/'],
    ['label' => 'Blogroll', 'url' => '/blogroll'],
    ['label' => 'About Sean', 'url' => '/about'],
    ['label' => 'Image Use', 'url' => '/image-use'],
];

$written = 0;
$index = [];
foreach (glob($root . '/skins/*/layout.php') ?: [] as $file) {
    $skin = basename(dirname($file));
    $manifestFile = $root . '/skins/' . $skin . '/manifest.json';
    if (!is_file($manifestFile)) continue;
    $manifest = json_decode((string)file_get_contents($manifestFile), true) ?: [];

    foreach ($kinds as $name => $response) {
        // A landing page belongs only to a skin that declares one. The flag is
        // the same one core/public-controller.php reads to decide whether to
        // give the site a landing feed at all, so this matches the runtime.
        if ($name === 'landing' && empty($manifest['features']['has_landing'])) continue;
        $sheets = gf_stylesheets($root, $skin, $manifest, $name);
        $css = '';
        foreach ($sheets as $p) {
            $css .= "\n/* ===== " . $p . " ===== */\n" . (string)file_get_contents($root . '/' . $p);
        }
        // The resolved T/M toggle and the calendar button, exactly as
        // snapsmack_public_runtime() puts them on an archive response.
        // Without them the harness drew the row with [ SHOW ALL ] and a
        // search field and no controls at all -- which is what the live
        // archives looked like, so a picture of it proved nothing.
        if ($name === 'archive') {
            $gfControls = snapsmack_archive_controls(
                ['archive_layout' => 'thumbs', 'archive_calendar_enabled' => '1'],
                $manifest
            );
            $response['archive_layouts'] = snapsmack_archive_layout_links(
                $gfControls['layouts'], 'https://example.test/', ['archive' => 'archive'], '');
            $response['archive_calendar'] = $gfControls['calendar'];
            $response['archive_layout_active'] = $gfControls['active'];
        }
        $response['navigation'] = $nav;
        // The archive control row renders from crop_circles. With empty
        // filters it draws [ SHOW ALL ] and nothing else, which LOOKS like a
        // broken row in a screenshot. Give it real groups so a picture shows
        // the row a site actually gets, and so the row I wired into TRUE GRIT
        // is tested rather than assumed.
        $view = snapsmack_build_skin_view($response, [
            'crop_circles' => [
                // Shape taken from the component, not guessed: each group
                // needs a `type` and `items`, and each item an `id`. My first
                // attempt used `options` and no type, every group was skipped,
                // and the row rendered as a lonely [ SHOW ALL ] -- which in a
                // screenshot looks exactly like the fault I had just repaired.
                'filters' => [
                    ['label' => 'Registry', 'type' => 'category', 'items' => [
                        ['id' => 'walls', 'label' => 'Walls'],
                        ['id' => 'rust', 'label' => 'Rust'],
                    ]],
                    ['label' => 'Albums', 'type' => 'album', 'items' => [
                        ['id' => 'strathmore', 'label' => 'Strathmore'],
                    ]],
                ],
                'search' => ['placeholder' => 'SEARCH OR #TAG', 'name' => 'q'],
            ],
            'site_name' => 'found textures', 'language' => 'en', 'direction' => 'ltr',
            'skin_style_url' => '', 'base_style_url' => '', 'skin_variant_url' => '',
            'registered_assets' => [], 'owner_custom_code' => '', 'search_dock' => [],
            // The CMS needs to know which skin it is resolving for: the
            // archive row's width cap is nominated per skin, and without
            // the slug every fixture fell back to main_canvas_width.
            'skin_slug' => $skin,
            // The skin nominates which of its own options is the content
            // width; the archive row caps itself with it.
            'skin_canvas_width_option' => (string)($manifest['features']['canvas_width_option'] ?? ''),
            'skin_presentation' => snapsmack_skin_presentation([], $skin),
            'footer' => [
                'site_name' => 'found textures', 'site_url' => 'https://example.test/',
                'slots' => [['kind' => 'copyright', 'year' => '2026', 'site_name' => 'found textures']],
            ],
        ]);
        ob_start();
        @snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $view);
        $html = (string)ob_get_clean();
        if (trim($html) === '') continue;

        // The skin's own <link> tags point at a server that is not here. Drop
        // them and inline the resolved set instead, so the page is complete on
        // disk and a missing file can never masquerade as a passing layout.
        $html = preg_replace('#<link[^>]+rel=["\']stylesheet["\'][^>]*>#i', '', $html) ?? $html;

        // The CMS builds a hero's src as BASE_URL . path, and BASE_URL is '/'
        // here, so every hero came out as "/photo-1600x1067.svg". Under a
        // file:// URL that resolves to the DRIVE ROOT, the image never loads,
        // and an image that never loads leaves a 21px box. I read that as a
        // collapsed hero on THE GRID and was one commit from repairing a fault
        // that existed only in this fixture. Make it relative so the
        // photograph beside the page is the photograph that loads.
        $html = str_replace('src="/photo-', 'src="photo-', $html);
        $style = '<style>' . $css . '</style>';
        $html = str_contains($html, '</head>')
            ? str_replace('</head>', $style . '</head>', $html)
            : $style . $html;

        $target = $out . '/' . $skin . '--' . $name . '.html';
        file_put_contents($target, $html);
        $index[] = [
            'skin' => $skin, 'kind' => $name, 'file' => basename($target),
            'stylesheets' => $sheets,
            'skin_version' => (string)($manifest['version'] ?? ''),
        ];
        $written++;
    }
}

file_put_contents($out . '/index.json', json_encode([
    'pages' => $index,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf("%d pages written to %s\n", $written, $out);

// ===== SNAPSMACK EOF =====
