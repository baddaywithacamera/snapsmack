<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * A SEARCH MUST REACH AN IMPORTED CAPTION AND ITS HASHTAGS. SS-1409.
 *
 * Sean searched "drumheller" on fauxlaroid.fyi and the page said "Not found",
 * on a site whose caption reads "...in Drumheller." Three separate things were
 * wrong and each one alone was enough to produce that page:
 *
 *  1. INSTANT CAMERA had no branch for kind='search', so it fell through to
 *     its catch-all. Covered by the companion test,
 *     tests/every-skin-renders-a-search-result-regression.php.
 *  2. The skins that DID render a search printed the title and nothing else.
 *     An Instagram import is stored with an EMPTY img_title and the caption in
 *     img_description (core/threeacross-api.php:483), so the result was a
 *     blank, unclickable heading. That is this file's first half.
 *  3. The tag term kept a leading #, which snap_tags never stores, so
 *     "#drumheller" could not match the tag at all. That is the second half.
 *
 * WHAT THIS DOES NOT PROVE: nothing here touches a database or a live site.
 * It executes the shared component and the two pure request/term functions.
 * The SQL is read as text for the one property that can be read that way.
 */

require_once dirname(__DIR__) . '/core/custom-code-policy.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/public-runtime.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);

$failures = [];
$root = dirname(__DIR__);

/** Render the shared component and return its HTML. */
$render = static function (array $data): string {
    return (string)snap_render_component('search-results', $data);
};

// ── 1. AN IMPORTED PHOTOGRAPH: no title, caption only ─────────────────────
$imported = [
    'id' => 41, 'img_slug' => 'ig-20260701-041832', 'img_title' => '',
    'img_description' => 'Hoodoos at golden hour, somewhere east of here in Drumheller. #drumheller #badlands',
    'img_alt' => '', 'img_thumb_aspect' => 'img_uploads/thumbs/a_ig-20260701-041832.jpg',
    'img_file' => 'img_uploads/ig-20260701-041832.jpg',
];
$html = $render(['results' => ['photographs' => [$imported], 'posts' => []],
    'query' => 'drumheller']);

if (!str_contains($html, 'ig-20260701-041832')) {
    $failures[] = 'a matched photograph produced no link to itself; the row '
        . 'was found and the visitor still cannot reach it';
}
if (!str_contains($html, 'Drumheller')) {
    $failures[] = 'the caption was not used as the label, so a photograph with '
        . 'no title renders as a blank heading -- which is every Instagram '
        . 'import on the fleet';
}
if (!str_contains($html, 'a_ig-20260701-041832.jpg')) {
    $failures[] = 'no thumbnail was rendered for a matched photograph';
}
// The caption is owner HTML and this is a heading, not a body.
$withTags = $render(['results' => ['photographs' => [
    ['img_slug' => 'x1', 'img_title' => '',
     'img_description' => 'caption <script>alert(1)</script> <b>bold</b>'],
], 'posts' => []], 'query' => 'caption']);
if (str_contains($withTags, '<script>') || str_contains($withTags, '<b>bold</b>')) {
    $failures[] = 'caption markup reached the page unescaped from a search result';
}

// A long caption is cut, not printed whole into a heading.
$long = $render(['results' => ['photographs' => [
    ['img_slug' => 'x2', 'img_title' => '', 'img_description' => str_repeat('word ', 80)],
], 'posts' => []], 'query' => 'word']);
if (strlen(strip_tags($long)) > 600) {
    $failures[] = 'a long caption was printed in full as a result heading';
}

// A real title still wins over the caption.
$titled = $render(['results' => ['photographs' => [
    ['img_slug' => 'x3', 'img_title' => 'Hoodoos', 'img_description' => 'a caption'],
], 'posts' => []], 'query' => 'hoodoos']);
if (!str_contains($titled, 'Hoodoos') || str_contains($titled, 'a caption')) {
    $failures[] = 'the caption displaced a real title';
}

// A row with no slug cannot be linked and must not be emitted as a dead entry.
$noSlug = $render(['results' => ['photographs' => [
    ['img_slug' => '', 'img_title' => 'Orphan'],
], 'posts' => []], 'query' => 'orphan']);
if (str_contains($noSlug, 'Orphan')) {
    $failures[] = 'a photograph with no slug was listed with no way to open it';
}

// ── 2. POSTS ARE LISTED AND LINKED ────────────────────────────────────────
$posts = $render(['results' => ['photographs' => [],
    'posts' => [['slug' => 'start-your-engines', 'title' => 'Start your engines?']]],
    'query' => 'engines']);
if (!str_contains($posts, 'start-your-engines') || !str_contains($posts, 'Start your engines?')) {
    $failures[] = 'a matching post was not linked in the results';
}

// ── 3. NOTHING FOUND SAYS SO, AND SAYS WHAT WAS SEARCHED ─────────────────
$empty = $render(['results' => ['photographs' => [], 'posts' => []],
    'query' => 'nothinghere']);
if (!str_contains($empty, 'nothinghere')) {
    $failures[] = 'an empty result did not repeat the term back, so the visitor '
        . 'cannot tell a typo from an empty library';
}
if (stripos($empty, 'Not found') !== false) {
    $failures[] = 'an empty search still says "Not found", which is the exact '
        . 'wording Sean reported and reads as a broken page rather than a '
        . 'search with no matches';
}

// ── 4. "#drumheller" AND "drumheller" REACH THE SAME TAG ─────────────────
// Without the hash, the term goes to search and the tag slug is matched there.
// With it, the request becomes the tag archive outright. Either way the
// visitor lands on the photographs, which is the property Sean asked for.
$plain = snapsmack_public_runtime_request(['query' => 'drumheller']);
if (($plain['route'] ?? '') !== 'search' || ($plain['query'] ?? '') !== 'drumheller') {
    $failures[] = 'a plain term no longer routes to search: '
        . var_export($plain, true);
}
$hashed = snapsmack_public_runtime_request(['query' => '#drumheller']);
if (($hashed['route'] ?? '') !== 'hashtag' || ($hashed['slug'] ?? '') !== 'drumheller') {
    $failures[] = '"#drumheller" did not reach the tag archive: '
        . var_export($hashed, true);
}
$upper = snapsmack_public_runtime_request(['query' => '#Drumheller']);
if (($upper['slug'] ?? '') !== 'drumheller') {
    $failures[] = 'a capitalised #Tag was not lowered to the stored slug';
}
// Not every # is a tag. "#1 of 3" must stay an ordinary search.
foreach (['#1 of 3', '# spaced', '#'] as $notATag) {
    $r = snapsmack_public_runtime_request(['query' => $notATag]);
    if (($r['route'] ?? '') !== 'search') {
        $failures[] = 'a # that is not a tag name was routed to the tag '
            . 'archive: ' . var_export($notATag, true);
    }
}

// ── 5. THE TAG TERM DROPS THE HASH BEFORE IT MEETS THE SLUG ──────────────
// snap_tags stores 'drumheller'. '%#drumheller%' matches nothing, and the
// query only appeared to work because the raw caption still contains the #.
foreach (['core/public-repository.php', 'core/search-engine.php'] as $file) {
    $body = (string)@file_get_contents($root . '/' . $file);
    if ($body === '') {
        $failures[] = "could not read {$file}";
        continue;
    }
    if (!preg_match('/ltrim\(\s*strtolower\(\s*\$[a-z_]+\s*\)\s*,\s*[\'"]#[\'"]\s*\)/', $body)) {
        $failures[] = "{$file} still builds its tag term without stripping a "
            . 'leading #, so searching "#tag" cannot match the stored slug';
    }
}

// ── 6. THE QUERY STILL READS THE CAPTION COLUMN AT ALL ───────────────────
// The importer writes the caption to snap_images.img_description and
// snap_posts.description. If a future edit drops either from the search, the
// symptom is again "nothing found" with no error anywhere.
$repo = (string)@file_get_contents($root . '/core/public-repository.php');
$start = strpos($repo, 'public function search(');
$end = $start === false ? false : strpos($repo, 'public function hashtagPhotographs', $start);
if ($start === false || $end === false) {
    $failures[] = 'could not isolate the repository search method';
} else {
    $sql = substr($repo, $start, $end - $start);
    foreach (['i.img_title', 'i.img_description', 'i.img_alt', 't.slug'] as $column) {
        if (!str_contains($sql, $column . ' LIKE ?')) {
            $failures[] = "the photograph search no longer matches {$column}";
        }
    }
    foreach (['title LIKE ?', 'description LIKE ?', 'content LIKE ?'] as $clause) {
        if (!str_contains($sql, $clause)) {
            $failures[] = "the post search no longer matches {$clause}";
        }
    }
}

if ($failures) {
    throw new RuntimeException("Search cannot reach imported content:\n  - "
        . implode("\n  - ", $failures));
}

echo "Search reaches an imported caption and its hashtags: passed.\n";
// ===== SNAPSMACK EOF =====
