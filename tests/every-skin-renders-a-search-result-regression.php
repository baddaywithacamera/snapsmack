<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * A SKIN THAT CANNOT RENDER A SEARCH SAYS "Not found". SS-1409.
 *
 * Sean searched "drumheller" on fauxlaroid.fyi and got "Not found", on a site
 * whose caption says "...in Drumheller." The search was correct. The query
 * reads the caption, the importer writes the caption, the rows matched.
 * INSTANT CAMERA's layout.php had branches for landing, archive, hashtag,
 * photo, post, page and blogroll and then a catch-all that prints "Not found",
 * and the kind was 'search'. Every search on that skin returned Not found, for
 * every term, and nothing in the logs or the markup said why.
 *
 * NOTHING COULD HAVE CAUGHT THIS. The checks in this repository read markup
 * for the pages they know about, and a missing branch produces a perfectly
 * valid page. So the test is the inventory: every skin must render kind
 * 'search', and a skin that cannot must be listed here with a reason.
 *
 * HOW A SKIN SATISFIES THIS: its layout.php handles kind 'search', or it
 * delegates to a core page component that does. Both are resolved below,
 * because reading layout.php alone is what made my first count wrong -- six
 * thin layouts delegate to core/<name>-page-component.php and those DO
 * handle it.
 */

$root = dirname(__DIR__);
$failures = [];

/**
 * SMACKTALK skins answer a search WITHOUT a kind='search' branch, and that is
 * correct, not an omission. core/smacktalk-public-controller.php reads ?q= at
 * the archive address and returns it as kind 'archive' with search_posts and
 * filtered tiles, which these layouts already render. Their search boxes post
 * there. Verified in source, not assumed.
 */
$exempt = [
    'alfred' => 'SMACKTALK: search is answered as a filtered archive by core/smacktalk-public-controller.php',
    'stanley' => 'SMACKTALK: same route',
    'telegram' => 'SMACKTALK: same route',
    'tilez' => 'SMACKTALK: same route',
    'writing-with-impact' => 'SMACKTALK: same route',
];

/**
 * THE REPAIR LIST. These skins print "Not found" for every search today. The
 * branch is one line of markup in each layout.php, which is Codex's lane, so
 * the core half (the shared search-results component) lands first and these
 * come off this list as each skin gains the line.
 *
 * THIS LIST MUST SHRINK TO EMPTY. A skin listed here that HAS the branch
 * fails below, deliberately: a held list nobody prunes stops being a record
 * of what is broken and starts hiding the next regression.
 */
$held = [
    // EMPTY, and it must stay that way. Every skin renders a search result.
    // THE GRID was the last entry: it was repaired on its own, by hand, in
    // its own commit, because it carries a standing "it works, don't change
    // it" note (Sean, 2026-11-17: that never meant "no edits", it meant not
    // as part of a sweep). A skin that cannot render kind='search' now fails
    // this test outright.
];

/** Does this file, or a core page component it delegates to, handle kind 'search'? */
$handlesSearch = static function (string $layout) use ($root): bool {
    $body = (string)@file_get_contents($layout);
    if ($body === '') return false;
    if (preg_match('/==\s*[\'"]search[\'"]/', $body)) return true;
    // Thin layout -> core page component.
    if (preg_match_all("/snap_render_component\(\s*'([a-z0-9-]+-page)'/", $body, $m)) {
        foreach ($m[1] as $component) {
            $file = $root . '/core/' . $component . '-component.php';
            if (!is_file($file)) continue;
            if (preg_match('/==\s*[\'"]search[\'"]/', (string)@file_get_contents($file))) return true;
        }
    }
    return false;
};

$skins = [];
foreach ((array)glob($root . '/skins/*', GLOB_ONLYDIR) as $dir) {
    $layout = $dir . '/layout.php';
    if (is_file($layout)) $skins[basename($dir)] = $layout;
}
ksort($skins);

if (count($skins) < 25) {
    throw new RuntimeException('only ' . count($skins) . ' skins were found, so '
        . 'this test is not reading skins/ properly rather than the product '
        . 'having almost none');
}

$renders = [];
$missing = [];
foreach ($skins as $skin => $layout) {
    if (isset($exempt[$skin])) continue;
    if ($handlesSearch($layout)) $renders[] = $skin; else $missing[] = $skin;
}

foreach ($missing as $skin) {
    if (in_array($skin, $held, true)) continue;
    $failures[] = $skin . ' cannot render a search result. Its layout falls '
        . 'through to its catch-all, so every search on it prints "Not found" '
        . 'whatever the visitor typed. Print the shared component: '
        . "snap_render_component('search-results', ['results' => "
        . "\$view['response']['results'] ?? [], 'query' => \$view['response']['query'] ?? '']).";
}

foreach ($held as $skin) {
    if (!isset($skins[$skin])) {
        $failures[] = "the held list names '{$skin}', which is not a skin in "
            . 'this repository; the list is stale';
        continue;
    }
    if (isset($exempt[$skin])) {
        $failures[] = "'{$skin}' is both held and exempt; pick one";
        continue;
    }
    if (!in_array($skin, $missing, true)) {
        $failures[] = "'{$skin}' now renders a search result -- remove it from "
            . 'the $held list in this file. SS-1409 is not closed until that '
            . 'list is empty, and a held entry that is actually fixed would '
            . 'hide the skin losing the branch again later.';
    }
}

foreach ($exempt as $skin => $reason) {
    if (!isset($skins[$skin])) {
        $failures[] = "the exempt list names '{$skin}', which is not a skin here";
    }
}

// The exemption itself is a claim about source. Check it rather than trust it.
$smacktalk = (string)@file_get_contents($root . '/core/smacktalk-public-controller.php');
if (!str_contains($smacktalk, "'search_posts'") || !str_contains($smacktalk, "archiveSearchPosts")) {
    $failures[] = 'core/smacktalk-public-controller.php no longer answers a '
        . 'search at the archive route, so the five SMACKTALK exemptions in '
        . 'this file are no longer true and those skins need the branch too';
}

// The component the fix depends on must exist and must emit something for a
// result. A test that only counts branches would pass against an empty one.
require_once $root . '/core/custom-code-policy.php';
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/skin-render-helpers.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
$sample = (string)snap_render_component('search-results', [
    'results' => ['photographs' => [['img_slug' => 'a-photograph', 'img_title' => 'A photograph']],
                  'posts' => []],
    'query' => 'photograph',
]);
if (!str_contains($sample, 'a-photograph') || !str_contains($sample, 'A photograph')) {
    $failures[] = 'the shared search-results component rendered nothing usable '
        . 'for a matched photograph, so adding the branch to a skin would '
        . 'change "Not found" into an empty page';
}

if ($failures) {
    throw new RuntimeException("Skins cannot render a search result:\n  - "
        . implode("\n  - ", $failures));
}

printf("Search rendering: %d skins render it, %d exempt (SMACKTALK archive route), "
    . "%d HELD awaiting the skin-side line: %s\n",
    count($renders), count($exempt), count($held), implode(', ', $held));
// ===== SNAPSMACK EOF =====
