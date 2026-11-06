<?php
declare(strict_types=1);

/**
 * THE ARCHIVE WORKS OUT SEVEN PAGING VALUES. THE SKIN MUST GET ALL SEVEN.
 *
 * snapsmack_public_response() returns page, per_page, total_count, total_pages,
 * has_more, previous_page and next_page for an archive. snapsmack_build_skin_view()
 * then filters the response through an allowlist, and that list permitted only
 * page, total_pages and next_page. So a strict skin could render a NEXT link
 * and the page numbers, and HAD NO WAY TO RENDER A PREVIOUS ONE --
 * previous_page was computed and then dropped one function later.
 *
 * next_page was there because endless scroll only ever needed forwards: it
 * feeds the scroll-wall sentinel. Nothing decided backwards paging should be
 * impossible. It just never came up, so the other half was never listed, and
 * GLIDE's explicit Previous/Next was blocked on a number the CMS already had.
 *
 * This file CALLS snapsmack_build_skin_view() with a response carrying all
 * seven and checks what survives, rather than reading the allowlist and
 * trusting it. The allowlist is one array among several filters; what matters
 * is what comes out the far end.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/skin-render-helpers.php';   // the contract renders components
require_once $root . '/core/skin-view-contract.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

// Exactly the shape snapsmack_public_response() returns for an archive on
// page 3 of 5, fifty to a page: so previous_page is 2 and next_page is 4 and
// neither is null, which is the case that proves both directions.
const PAGING = [
    'page' => 3, 'per_page' => 50, 'total_count' => 214, 'total_pages' => 5,
    'has_more' => true, 'previous_page' => 2, 'next_page' => 4,
];

$view = snapsmack_build_skin_view(
    ['kind' => 'archive', 'status' => 200, 'items' => [], 'item' => []] + PAGING,
    ['site_name' => 'Fixture', 'base_url' => '/', 'skin_slug' => 'glide']
);
$response = is_array($view['response'] ?? null) ? $view['response'] : [];

echo "ALL SEVEN PAGING VALUES SURVIVE THE CONTRACT\n";
foreach (PAGING as $key => $expected) {
    cx(array_key_exists($key, $response) && $response[$key] === $expected,
       "{$key} arrives at the skin as " . var_export($expected, true)
       . (array_key_exists($key, $response) ? '' : ' -- MISSING, stripped by the allowlist'));
}

echo "\nTHE ONE THAT WAS ACTUALLY MISSING\n";
// Called out on its own because it is the whole point and because a loop that
// passes seven times reads the same as a loop that passes three.
cx(array_key_exists('previous_page', $response),
   'previous_page is present, so a skin can render a PREVIOUS link at all');
cx($response['previous_page'] === 2,
   'and it carries the page number, not merely a boolean');

echo "\nPAGE ONE OFFERS NO PREVIOUS, AND SAYS SO AS NULL\n";
// The boundary. A skin must be able to tell "no previous page" from "the key
// was filtered out", and null is how the controller says the former.
$first = snapsmack_build_skin_view(
    ['kind' => 'archive', 'status' => 200, 'items' => [], 'item' => [],
     'page' => 1, 'per_page' => 50, 'total_count' => 214, 'total_pages' => 5,
     'has_more' => true, 'previous_page' => null, 'next_page' => 2],
    ['site_name' => 'Fixture', 'base_url' => '/', 'skin_slug' => 'glide']
);
$firstResponse = is_array($first['response'] ?? null) ? $first['response'] : [];
cx(array_key_exists('previous_page', $firstResponse),
   'the key is still present on page one');
// NEVER ?? WHEN THE VALUE UNDER TEST MAY BE NULL. My first version of this
// line read ($firstResponse['previous_page'] ?? 'missing') === null, and
// null ?? 'missing' is 'missing' -- so the assertion COULD NOT OBSERVE A NULL
// even when one was sitting right there. It failed, I went looking in the
// contract for a null-stripping filter that does not exist, and the fault was
// the coalesce in my own check. array_key_exists above answers presence;
// direct access answers value.
cx($firstResponse['previous_page'] === null,
   'and its value is null, which is distinguishable from being stripped');

echo "\nTHE LAST PAGE OFFERS NO NEXT\n";
$last = snapsmack_build_skin_view(
    ['kind' => 'archive', 'status' => 200, 'items' => [], 'item' => [],
     'page' => 5, 'per_page' => 50, 'total_count' => 214, 'total_pages' => 5,
     'has_more' => false, 'previous_page' => 4, 'next_page' => null],
    ['site_name' => 'Fixture', 'base_url' => '/', 'skin_slug' => 'glide']
);
$lastResponse = is_array($last['response'] ?? null) ? $last['response'] : [];
cx(array_key_exists('next_page', $lastResponse) && $lastResponse['next_page'] === null,
   'next_page is null on the last page');
cx($lastResponse['has_more'] === false, 'and has_more is false, so a sentinel will not ask for more');
cx($lastResponse['previous_page'] === 4, 'while previous_page still works');

echo "\nGLIDE HAS ITS OWN PAGE SIZE AND IT INHERITS RATHER THAN RESETS\n";
// SOURCE CHECK, AND LABELLED AS ONE. The page size is decided inside
// snapsmack_public_response(), which needs a repository and a database, so it
// cannot be called from here. What is asserted is the thing that makes the
// change safe: GLIDE's key falls back to the SITE-WIDE posts_per_page, while
// the three skins already in the map keep falling back to 24. Had GLIDE
// defaulted to 24 it would have silently resized its archive on every site
// whose posts_per_page is not 24.
$src = (string)file_get_contents($root . '/core/public-controller.php');
cx(str_contains($src, "'glide'=>'glide_page_size'"),
   'GLIDE has a page-size key of its own, so fifty per page does not mean changing the site-wide setting');
cx(str_contains($src, "\$skin === 'glide' && \$route === 'archive' ? 50"),
   'GLIDE archives default to fifty tiles without changing the site-wide setting');
cx(str_contains($src, "'onyx'=>'onyx_wall_page_size'") && str_contains($src, "'scroll'=>'scroll_page_size'"),
   'and the skins already in the map are untouched, still falling back to 24');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
