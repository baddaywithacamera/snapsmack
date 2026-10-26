<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * Every skin, every page kind, and what actually came out.
 *
 * OPAUDIT 026. The existing render checks produce roughly three hundred pages
 * and then ask three questions of each: did it crash, is it HTML, did an
 * injected script survive. A page can lose every paragraph, swap a photograph
 * for a crop of itself, drop a date or a stylesheet, and pass all three.
 *
 * That is not hypothetical. Between 11 and 25 October 2026, thirty-two commits
 * on dev said they were restoring something that had stopped appearing on a
 * page -- paragraphs, a date, solo links, a palette stylesheet, whole-print
 * thumbnails. Not one was a crash or malformed HTML. Not one was caught here.
 *
 * So this file keeps the page instead of throwing it away. For each skin and
 * page kind it records a small fingerprint -- which images, which stylesheets,
 * how many paragraphs and links, and a hash of the visible words -- and
 * compares it to the committed fixture. Output is allowed to change. Changing
 * it silently is not.
 *
 *   php tests/skin-render-snapshot-regression.php            check
 *   php tests/skin-render-snapshot-regression.php --update   accept the change
 *
 * The --update run is the point of the whole thing: it costs one deliberate
 * command and makes somebody state that they meant it.
 *
 * What this does NOT cover, so nobody reads more into a pass than is there:
 * the fixture builds its items by calling the real frame and presentation-image
 * functions, but it does not go through snapsmack_grid_landing_items(), which
 * needs a database. The CSS class prefix that function assigns is therefore
 * unverified here. OPAUDIT 026 section 4.2 has that one -- the prefix map is
 * written out by hand in three files and they disagree.
 */

define('SNAPSMACK_SKIN_RENDER', true);
$root = dirname(__DIR__);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/public-controller.php';

$fixturePath = $root . '/tests/fixtures/skin-render-snapshot.json';
$updating    = in_array('--update', $argv, true);

/**
 * One photograph carrying BOTH thumbnails.
 *
 * This matters more than it looks. The older fixtures carry img_thumb_aspect
 * alone, so a skin that switched to the square crop still rendered the aspect
 * file and nothing moved. A choice you cannot observe is a choice you cannot
 * test. Both files are present here so whichever one the skin reaches for is
 * visible in the snapshot.
 */
function snapshot_photograph(int $id, string $title, string $slug): array
{
    return [
        'id'               => $id,
        'img_title'        => $title,
        'img_slug'         => $slug,
        'img_alt'          => $title . ' alt',
        'img_file'         => '/media/' . $slug . '-full.jpg',
        'img_thumb_aspect' => '/media/' . $slug . '-whole-print.jpg',
        'img_thumb_square' => '/media/' . $slug . '-square-crop.jpg',
        'img_width'        => 741,
        'img_height'       => 900,
        'img_orientation'  => 1,
        'img_date'         => '2026-10-25',
    ];
}

/** Run the real CMS decisions over the items, exactly as a landing page would. */
function snapshot_items(string $skin): array
{
    $items = [snapshot_photograph(1, 'First', 'first'), snapshot_photograph(2, 'Second', 'second')];
    $items = snapsmack_grid_frame_items($items, [], $skin);
    foreach ($items as $index => $item) {
        $items[$index]['presentation_image'] = snapsmack_grid_presentation_image($item, $skin);
        $items[$index]['presentation_title'] = (string)($item['img_title'] ?? '');
        $items[$index]['presentation_width'] = max(1, (int)($item['img_width'] ?? 3));
        $items[$index]['presentation_height'] = max(1, (int)($item['img_height'] ?? 2));
    }
    return $items;
}

/**
 * Prose and photographs alternating, run through the real description formatter.
 *
 * This is the shape that broke twice in one day on 25 October 2026: a post
 * where the writing between the photographs lost its paragraphs and became
 * loose text with no spacing of its own. The formatter is the thing under
 * test, so the raw string goes in and whatever comes out is what gets
 * recorded -- if the wrapping changes, the snapshot says so.
 */
function snapshot_mixed_post_body(): SnapTrustedHtml
{
    $frame = '<div class="snap-inline-frame align-center"><div class="ip-ascii-frame-inner">'
           . '<img src="/media/inline.jpg" alt="Inline"></div></div>';
    return snapsmack_photo_description_html(
        '<p>An opening paragraph.</p>' . $frame
        . 'Loose prose directly after a photograph.' . $frame
        . 'A second loose run between two photographs.'
        . '<p>And a properly wrapped closing paragraph.</p>'
    );
}

function snapshot_responses(string $skin): array
{
    $items = snapshot_items($skin);
    // Keyed by label, because two of these are the same page kind.
    return [
        'landing'    => ['status' => 200, 'kind' => 'landing', 'items' => $items, 'navigation' => []],
        'archive'    => ['status' => 200, 'kind' => 'archive', 'items' => $items, 'navigation' => []],
        'photo'      => ['status' => 200, 'kind' => 'photo', 'navigation' => [], 'comments' => [],
                         'item' => $items[0] + ['img_description' => snapsmack_trusted_html('<p>A caption with words in it.</p>')]],
        'post'       => ['status' => 200, 'kind' => 'post', 'navigation' => [], 'photographs' => $items,
                         'item' => ['id' => 9, 'title' => 'A post', 'content' => snapsmack_trusted_html('<p>First paragraph.</p><p>Second paragraph.</p>')]],
        'post-mixed' => ['status' => 200, 'kind' => 'post', 'navigation' => [], 'photographs' => $items,
                         'item' => ['id' => 10, 'title' => 'A mixed post', 'content' => snapshot_mixed_post_body()]],
        'not_found'  => ['status' => 404, 'kind' => 'not_found', 'navigation' => []],
    ];
}

/**
 * Strip the things that are allowed to move on their own.
 *
 * Cache-busting version queries change on every release and say nothing about
 * what the page shows. Leaving them in would make this file fail on every
 * version bump, which is the fastest way to train somebody to run --update
 * without reading it.
 */
function snapshot_deversion(string $url): string
{
    return (string)preg_replace('/\?v=[^"\'\s]*$/', '', $url);
}

function snapshot_matches(string $html, string $pattern): array
{
    preg_match_all($pattern, $html, $found);
    $values = array_map('snapshot_deversion', $found[1] ?? []);
    sort($values);
    return array_values(array_unique($values));
}

/** The small set of facts worth holding somebody to. */
function snapshot_fingerprint(string $html): array
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    return [
        'images'      => snapshot_matches($html, '/<img[^>]+src="([^"]*)"/i'),
        'stylesheets' => snapshot_matches($html, '/<link[^>]+rel="stylesheet"[^>]+href="([^"]*)"/i'),
        'paragraphs'  => preg_match_all('/<p[\s>]/i', $html),
        'links'       => preg_match_all('/<a[\s>]/i', $html),
        'headings'    => preg_match_all('/<h[1-6][\s>]/i', $html),
        'words'       => str_word_count($text),
        'text_sha256' => hash('sha256', $text),
    ];
}

// --- render every v2 public skin ------------------------------------------

$skins = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifestPath) {
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest)) continue;
    if (($manifest['schema_version'] ?? 0) !== 2) continue;
    if (($manifest['cms_controller'] ?? '') !== 'public') continue;
    $skins[] = basename(dirname($manifestPath));
}
sort($skins);
if ($skins === []) throw new RuntimeException('No v2 public skins found; the harness would pass by rendering nothing.');

$current = [];
foreach ($skins as $skin) {
    $dir = $root . '/skins/' . $skin;
    if (!is_file($dir . '/layout.php')) continue;
    foreach (snapshot_responses($skin) as $label => $response) {
        $view = snapsmack_build_skin_view($response, [
            'site_name'         => 'Example',
            'tagline'           => 'Tag',
            'language'          => 'en',
            'direction'         => 'ltr',
            'skin_slug'         => $skin,
            'skin_style_url'    => '/skins/' . $skin . '/style.css',
            'skin_presentation' => snapsmack_skin_presentation([], $skin),
        ]);
        ob_start();
        $ok   = snapsmack_render_strict_skin_template($dir, 'layout.php', $view);
        $html = (string)ob_get_clean();
        if (!$ok) throw new RuntimeException("{$skin}/{$label} did not render.");
        $current[$skin . '/' . $label] = snapshot_fingerprint($html);
    }
}
ksort($current);

// --- compare, or accept -----------------------------------------------------

$encoded = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($updating || !is_file($fixturePath)) {
    if (!is_dir(dirname($fixturePath))) mkdir(dirname($fixturePath), 0775, true);
    file_put_contents($fixturePath, $encoded);
    $verb = $updating ? 'Updated' : 'Created';
    echo "{$verb} " . count($current) . " snapshots across " . count($skins) . " skins.\n";
    echo "Read the diff before committing it. A change here is a change on a live page.\n";
    // ===== SNAPSMACK EOF =====
    return;
}

$stored = json_decode((string)file_get_contents($fixturePath), true);
if (!is_array($stored)) throw new RuntimeException('The snapshot fixture is unreadable; regenerate it with --update.');

$problems = [];
foreach ($current as $page => $fingerprint) {
    if (!array_key_exists($page, $stored)) { $problems[] = "{$page}: new page, not in the snapshot"; continue; }
    foreach ($fingerprint as $field => $value) {
        $was = $stored[$page][$field] ?? null;
        if ($was === $value) continue;
        $problems[] = sprintf(
            "%s: %s changed\n      was: %s\n      now: %s",
            $page, $field,
            is_array($was) ? implode(', ', $was) : var_export($was, true),
            is_array($value) ? implode(', ', $value) : var_export($value, true)
        );
    }
}
foreach ($stored as $page => $ignored) {
    if (!array_key_exists($page, $current)) $problems[] = "{$page}: this page no longer renders at all";
}

if ($problems !== []) {
    fwrite(STDERR, "Rendered output changed on " . count($problems) . " point(s):\n\n");
    foreach ($problems as $problem) fwrite(STDERR, "  - {$problem}\n");
    fwrite(STDERR, "\nIf every one of these was intended, run with --update and commit the fixture.\n");
    fwrite(STDERR, "If any of them surprises you, that is the regression this file exists to find.\n");
    exit(1);
}

echo "Skin render snapshots match: " . count($current) . " pages across " . count($skins) . " skins.\n";
// ===== SNAPSMACK EOF =====
