<?php
declare(strict_types=1);

/**
 * GLIDE SLICE 3: PAGING A FILTERED ARCHIVE MUST STAY FILTERED.
 *
 * previous_page and next_page are numbers. Turning one into a link means
 * knowing the route and the active filters, and a strict skin assembling its
 * own query string would drop the filter the moment anybody paged a filtered
 * archive -- page 2 of "album: Doors" quietly becoming page 2 of everything.
 * That is a silent wrong answer, which is the worst kind.
 *
 * So the CMS prepares previous_url and next_url. This file CALLS
 * snapsmack_public_paging_urls() for every case that matters: each of the
 * three paged routes, both ends of the range, and the filtered and unfiltered
 * forms. The function exists as a function precisely so it can be called --
 * inline in the runtime, the only way to check it would have been to read it
 * and believe it.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/public-runtime.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}
/**
 * SECOND TIME TODAY I WROTE ?? ON A VALUE THAT IS LEGITIMATELY NULL.
 *
 * This read $out['previous_url'] ?? 'MISSING' -- and null ?? 'MISSING' is
 * 'MISSING', so three assertions about the ends of the range failed on values
 * that were exactly right. null IS the answer at both ends; a helper that
 * cannot return it cannot test for it. array_key_exists answers presence,
 * direct access answers value, and ?? has no business in either.
 */
function urls(array $response): array {
    $out = snapsmack_public_paging_urls($response);
    return [
        array_key_exists('previous_url', $out) ? $out['previous_url'] : 'ABSENT',
        array_key_exists('next_url', $out) ? $out['next_url'] : 'ABSENT',
    ];
}

cx(function_exists('snapsmack_public_paging_urls'),
   'the builder is a named function, so it can be called rather than read');

echo "\nAN ALBUM SURVIVES PAGING IN BOTH DIRECTIONS\n";
// The whole point of the slice. If this drops, a visitor browsing one album
// lands in the full library and nothing tells them.
[$p, $n] = urls(['kind' => 'archive', 'page' => 3, 'previous_page' => 2, 'next_page' => 4,
                 'taxonomy' => ['category_id' => 0, 'album_id' => 7]]);
cx(str_contains((string)$p, 'album=7'), "previous keeps the album ({$p})");
cx(str_contains((string)$n, 'album=7'), "next keeps the album ({$n})");
cx(str_contains((string)$p, 'page=2') && str_contains((string)$n, 'page=4'),
   'and both carry the right page number');

echo "\nSO DOES A CATEGORY, AND BOTH TOGETHER\n";
[$p, $n] = urls(['kind' => 'archive', 'page' => 2, 'previous_page' => 1, 'next_page' => 3,
                 'taxonomy' => ['category_id' => 3, 'album_id' => 9]]);
cx(str_contains((string)$n, 'category=3') && str_contains((string)$n, 'album=9'),
   "next keeps category AND album together ({$n})");

echo "\nPAGE ONE IS THE ROUTE WITH NO PAGE NUMBER\n";
// A ?page=1 link works and still looks like a mistake. The filter must stay.
[$p, $n] = urls(['kind' => 'archive', 'page' => 2, 'previous_page' => 1, 'next_page' => 3,
                 'taxonomy' => ['category_id' => 3, 'album_id' => 0]]);
cx(!str_contains((string)$p, 'page='), "going back to page one drops the page parameter ({$p})");
cx(str_contains((string)$p, 'category=3'), 'but keeps the filter');

echo "\nBOTH ENDS ARE NULL, NOT AN EMPTY STRING AND NOT A BROKEN LINK\n";
// A skin tests these with a conditional. '' would render an anchor to nowhere.
[$p, $n] = urls(['kind' => 'archive', 'page' => 1, 'previous_page' => null, 'next_page' => 2,
                 'taxonomy' => []]);
cx($p === null, 'page one has no previous_url (' . var_export($p, true) . ')');
cx(is_string($n) && $n !== '', 'and still has a next');
[$p, $n] = urls(['kind' => 'archive', 'page' => 5, 'previous_page' => 4, 'next_page' => null,
                 'taxonomy' => []]);
cx($n === null, 'the last page has no next_url (' . var_export($n, true) . ')');
cx(is_string($p) && $p !== '', 'and still has a previous');

echo "\nA SEARCH KEEPS ITS QUERY\n";
[$p, $n] = urls(['kind' => 'search', 'page' => 2, 'next_page' => 3, 'query' => 'blue door']);
cx(str_contains((string)$n, 'query=blue') , "next keeps the query ({$n})");
cx(str_contains((string)$n, 'blue%20door') || str_contains((string)$n, 'blue+door'),
   'and the space is encoded, so the link is usable');
cx(is_string($p) && str_contains((string)$p, 'query=blue'),
   'and a previous is DERIVED even though the search response has no previous_page');

echo "\nSEARCH SAYS next_page = 0 AT THE END AND THAT IS NOT PAGE ZERO\n";
// search returns 0 rather than null. Read as a page number that would be a
// link to nothing; read correctly it means "no next".
[$p, $n] = urls(['kind' => 'search', 'page' => 4, 'next_page' => 0, 'query' => 'doors']);
cx($n === null, 'next_page 0 becomes no next_url at all (' . var_export($n, true) . ')');
cx(!str_contains((string)$p, 'page=0'), 'and nothing anywhere links to page zero');

echo "\nA HASHTAG KEEPS ITS TAG\n";
[$p, $n] = urls(['kind' => 'hashtag', 'page' => 2, 'previous_page' => 1, 'next_page' => 3,
                 'slug' => 'blue']);
cx(str_contains((string)$n, 'tag=blue'), "next keeps the tag ({$n})");
cx(str_contains((string)$p, 'tag=blue'), 'and so does previous');

echo "\nA PAGE THAT DOES NOT PAGE IS LEFT ALONE\n";
// Only three kinds page. A photo or a static page must not grow paging keys it
// has no meaning for.
$untouched = snapsmack_public_paging_urls(['kind' => 'photo', 'page' => 1, 'next_page' => 2]);
cx(!array_key_exists('next_url', $untouched) && !array_key_exists('previous_url', $untouched),
   'a photo response gets no paging urls');

echo "\nTHE URLS ARE RAW. THE SKIN ESCAPES THEM ONCE. CONTRACT REVERSED, ON PURPOSE\n";
// THIS BLOCK USED TO ASSERT THE OPPOSITE AND IT WAS WRONG. It said the urls
// arrive pre-escaped and "ready for an href", and its own comment spotted the
// hazard -- "a skin that escaped them again would show &amp;amp;" -- and then
// concluded the pre-escaped form was correct anyway. It is not, because a
// strict skin HAS NO CHOICE about escaping: core/skin-security-policy.php
// refuses any echo in a layout that does not begin with one of seven output
// helpers, so the template always writes snap_escape_url(...). Pre-escaped in,
// double-escaped out, and the FILTER IS LOST on page two.
//
// Codex hit it wiring GLIDE's paging and reported it. So core now builds these
// two with snap_route_url_raw() and the template does the single escape.
//
// This is a deliberate contract change, not a check being softened: the
// assertion below is stronger than the one it replaces, because it performs
// the escape the skin will perform and checks the RESULT.
[$p, $n] = urls(['kind' => 'archive', 'page' => 2, 'previous_page' => 1, 'next_page' => 3,
                 'taxonomy' => ['category_id' => 3, 'album_id' => 9]]);
cx(str_contains((string)$n, '&') && !str_contains((string)$n, '&amp;'),
   'core hands over a raw ampersand (' . $n . ')');
$escaped = snap_escape_url((string)$n);
cx(str_contains($escaped, '&amp;'), 'the skin\'s own escape makes it an entity (' . $escaped . ')');
cx(!str_contains($escaped, '&amp;amp;'), 'exactly once, so the link still carries the filter');
cx(substr_count($escaped, '&amp;') === substr_count((string)$n, '&'),
   'every ampersand survives the round trip, none doubled and none dropped');
// Raw must still mean validated. The scheme check is shared with
// snap_escape_url() via snap_validate_url(), so a raw url cannot be a
// javascript: or protocol-relative one.
cx(snap_route_url_raw('archive', ['page' => 2]) !== '',
   'the raw builder produces a url at all');
cx(snap_validate_url('javascript:alert(1)') === '' && snap_validate_url('//evil.test/x') === '',
   'and the validation raw shares with the escaper still refuses a bad scheme '
   . 'and a protocol-relative url');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
