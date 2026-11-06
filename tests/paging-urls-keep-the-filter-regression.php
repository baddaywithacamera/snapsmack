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
// q=, NOT query=. THIS ASSERTION USED TO REQUIRE query= AND WAS WRONG:
// index.php:431 reads $_GET['q'] and hands it to the runtime as 'query', so
// 'query' is an INTERNAL key and a url carrying it loses the search term on
// page two. Codex found it wiring GLIDE's search. The test asserted the broken
// shape, which is why nothing caught it.
cx(str_contains((string)$n, 'q=blue') && !str_contains((string)$n, 'query=blue'),
   "next keeps the search term under the name the router reads ({$n})");
cx(str_contains((string)$n, 'blue%20door') || str_contains((string)$n, 'blue+door'),
   'and the space is encoded, so the link is usable');
cx(is_string($p) && str_contains((string)$p, 'q=blue'),
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

echo "\nTHE RULED CONTRACT, RUN AS THE REAL CHAIN ON EVERY PAGED ROUTE\n";
// The coordinator's ruling: raw, validated, RELATIVE -- same-origin route
// output, no scheme, no host, no control characters -- and the skin escapes
// ONCE. The named example is ?album=7&amp;page=4 with a single &amp;, so that
// exact string is asserted rather than something near it.
//
// EVERY CASE BELOW RUNS THE WHOLE CHAIN: build through the runtime, then pass
// the value through snap_escape_url() the way the template must, then read the
// result. Asserting on the raw value alone would not have caught the fault
// this replaces, because the raw value looked fine.
$chain = static function (array $response): array {
    $out = snapsmack_public_paging_urls($response);
    return [
        array_key_exists('previous_url', $out) ? $out['previous_url'] : 'ABSENT',
        array_key_exists('next_url', $out) ? $out['next_url'] : 'ABSENT',
    ];
};
$esc = static fn($v) => is_string($v) ? snap_escape_url($v) : $v;

[, $albumNext] = $chain(['kind' => 'archive', 'page' => 3, 'next_page' => 4,
    'previous_page' => 2, 'taxonomy' => ['album_id' => 7], 'total_pages' => 9]);
cx($esc($albumNext) === '/archive?album=7&amp;page=4',
   'the ruled example comes out exactly: /archive?album=7&amp;page=4 (got '
   . $esc($albumNext) . ')');

[, $catNext] = $chain(['kind' => 'archive', 'page' => 1, 'next_page' => 2,
    'taxonomy' => ['category_id' => 5], 'total_pages' => 4]);
cx($esc($catNext) === '/archive?category=5&amp;page=2',
   'a category archive keeps its category (' . $esc($catNext) . ')');

[, $searchNext] = $chain(['kind' => 'search', 'page' => 2, 'next_page' => 3,
    'previous_page' => 1, 'query' => 'blue door', 'total_pages' => 6]);
cx(str_contains((string)$esc($searchNext), 'q=blue%20door')
   && str_contains((string)$esc($searchNext), '&amp;page=3')
   && !str_contains((string)$esc($searchNext), '&amp;amp;'),
   'a search keeps its query (' . $esc($searchNext) . ')');

[, $tagNext] = $chain(['kind' => 'hashtag', 'page' => 1, 'next_page' => 2,
    'slug' => 'street', 'total_pages' => 3]);
// Order-independent on purpose: http_build_query emits the parameters in the
// order the array holds them, and on this route page sorts ahead of tag. An
// assertion that pinned "&amp;page=2" was testing the parameter ORDER, which
// nothing promises and no browser cares about.
cx(str_contains((string)$esc($tagNext), 'tag=street')
   && str_contains((string)$esc($tagNext), 'page=2')
   && substr_count((string)$esc($tagNext), '&amp;') === 1
   && !str_contains((string)$esc($tagNext), '&amp;amp;'),
   'a tag page keeps its tag, with one escaped ampersand (' . $esc($tagNext) . ')');

echo "\nTHE SEARCH URL USES THE NAME THE ROUTER ACTUALLY READS\n";
// The fault Codex found was that these two disagreed and nothing compared
// them. So compare them: whatever index.php reads for the search term is the
// parameter the paging url must carry. Bounded on the strict-request array, so
// it reads the live wiring rather than a name I remember.
$indexSrc = (string)@file_get_contents($root . '/index.php');
$strictCall = (static function (string $src): string {
    $at = strpos($src, 'snapsmack_public_runtime($pdo, [');
    if ($at === false) return '';
    $end = strpos($src, '$_public_settings', $at);
    return $end === false ? '' : substr($src, $at, $end - $at);
})($indexSrc);
cx($strictCall !== '', 'the strict request array in index.php was found');
$qm = [];
cx(preg_match('/\x27query\x27\s*=>\s*\$_GET\[\x27([a-z_]+)\x27\]/', $strictCall, $qm) === 1,
   'it maps one GET parameter to the runtime query key'
   . (isset($qm[1]) ? ' (' . $qm[1] . ')' : ''));
$routerReads = $qm[1] ?? '';
[, $searchUrl] = $chain(['kind' => 'search', 'page' => 1, 'next_page' => 2,
    'query' => 'tulips', 'total_pages' => 3]);
cx($routerReads !== '' && str_contains((string)$searchUrl, $routerReads . '=tulips'),
   'and the paging url carries the search term under THAT name (' . $searchUrl . ')');
cx(!str_contains((string)$searchUrl, '/search'),
   'the url is not a /search path: core/public-runtime.php resolves paths from '
   . 'a closed list that does not include search');
[, $noTerm] = $chain(['kind' => 'search', 'page' => 1, 'next_page' => 2,
    'query' => '', 'total_pages' => 3]);
cx($noTerm === null || $noTerm === '',
   'and no next link is offered when there is no search term (got '
   . var_export($noTerm, true) . ')');

echo "
THE FULL ROUND TRIP: page 1 -> next_url -> the router reads the term back
";
// The coordinator asked for this exact chain, and it is stronger than
// comparing names: take the url the CMS prepared, pull it apart the way a web
// server would, hand the parameters to the REAL router mapping, and see
// whether a search for the same term on page 2 comes back out.
//
// snapsmack_public_runtime_request() is pure -- input array to route -- so the
// whole trip runs here with no database. The q -> query rename is READ from
// index.php rather than written in by me, so if index.php ever renames it this
// chain follows instead of lying.
[, $trip] = $chain(['kind' => 'search', 'page' => 1, 'next_page' => 2,
    'query' => 'blue door', 'total_pages' => 4]);
cx(is_string($trip) && $trip !== '', 'page one produced a next url (' . $trip . ')');
$parsed = [];
parse_str((string)parse_url((string)$trip, PHP_URL_QUERY), $parsed);
cx(($parsed[$routerReads] ?? '') === 'blue door',
   'the url decodes back to the exact search term, spaces and all (got '
   . var_export($parsed[$routerReads] ?? null, true) . ')');
cx((int)($parsed['page'] ?? 0) === 2, 'and to page 2');
// index.php maps that GET name onto the runtime's 'query' key. Mirror only
// that one rename, then let the real router decide.
$asRuntime = ['query' => $parsed[$routerReads] ?? '', 'page' => $parsed['page'] ?? 1];
$routed = snapsmack_public_runtime_request($asRuntime);
cx(($routed['route'] ?? '') === 'search',
   'the router resolves it as a SEARCH, not a page or a 404 (got '
   . var_export($routed['route'] ?? null, true) . ')');
cx((string)($routed['query'] ?? '') === 'blue door',
   'with the search term intact -- this is the assertion that would have '
   . 'caught the fault Codex reported');
cx((int)($routed['page'] ?? 0) === 2, 'on page 2');

// MUTATION PROOF for the round trip: the old url shape must fail it. ?query=
// is what the CMS used to emit, and the router reads nothing from it.
$oldShape = '/search?query=blue%20door&page=2';
$oldParsed = [];
parse_str((string)parse_url($oldShape, PHP_URL_QUERY), $oldParsed);
$oldRouted = snapsmack_public_runtime_request(
    ['query' => $oldParsed[$routerReads] ?? '', 'page' => $oldParsed['page'] ?? 1]);
cx(($oldRouted['route'] ?? '') !== 'search',
   'the url this replaced does NOT resolve as a search, which is exactly how '
   . 'page two lost the term (got ' . var_export($oldRouted['route'] ?? null, true) . ')');

echo "\nNULL AT BOTH ENDS, AFTER THE CHAIN AS WELL AS BEFORE IT\n";
[$firstPrev, ] = $chain(['kind' => 'archive', 'page' => 1, 'next_page' => 2, 'total_pages' => 4]);
cx($firstPrev === null, 'page one has no previous (got ' . var_export($firstPrev, true) . ')');
[, $lastNext] = $chain(['kind' => 'archive', 'page' => 4, 'previous_page' => 3,
    'next_page' => 0, 'total_pages' => 4]);
cx($lastNext === null, 'the last page has no next (got ' . var_export($lastNext, true) . ')');
[$backToOne, ] = $chain(['kind' => 'archive', 'page' => 2, 'previous_page' => 1, 'total_pages' => 4]);
cx($esc($backToOne) === '/archive',
   'and page two points back at the route with no ?page=1 (' . $esc($backToOne) . ')');

echo "\nRELATIVE, NOT ABSOLUTE -- MEASURED WITH AN ABSOLUTE BASE_URL\n";
// BASE_URL in this file is '/', so a relativisation fault would be INVISIBLE
// here. The helper is therefore driven directly with the absolute form a live
// install actually produces, which is how the gap was found in the first
// place: index.php builds BASE_URL from site_url, so it is always absolute.
cx(snap_same_origin_path('https://photowalk.ing/archive?album=7&page=4')
   === '/archive?album=7&page=4',
   'scheme and host are removed from a live-shaped url');
cx(snap_same_origin_path('https://site.test/blog/archive?page=2')
   === '/blog/archive?page=2',
   'and a subdirectory install keeps its directory');
cx(snap_same_origin_path('//evil.test/x') === ''
   && snap_same_origin_path('javascript:alert(1)') === '',
   'a protocol-relative or non-http url is refused outright');

// AND THE RUNTIME MUST ACTUALLY USE IT. The three checks above pass even if
// snapsmack_public_paging_urls() never calls the helper, because BASE_URL in
// this file is '/' and relativising a relative url changes nothing. I found
// that by mutating the runtime and watching this file stay green, which is the
// same blind spot I have been repairing in other people's tests all week.
//
// BASE_URL is a constant and cannot be redefined here, so this runs the real
// runtime in a SUBPROCESS with the absolute BASE_URL a live install produces
// and reads the value back. Remove the call in the runtime and this goes red.
$probe = escapeshellarg($root . '/tests/fixtures/paging-absolute-base-probe.php');
// NO STDERR REDIRECT HERE, deliberately, and this comment does not spell one
// out either. The test-strength gate records the digits either side of a
// comparison operator, and a shell stderr redirect looks exactly like a
// comparison against the number two -- so both the code AND a comment quoting
// it get recorded as a threshold the file holds the code to. That is SS-1205's
// artefact class. I removed the redirect, then had to remove it from my own
// explanation of why: this is the fourth time today a comment of mine has
// broken a check that reads source.
//
// If the probe writes to stderr it prints nothing here, json_decode fails, and
// the assertion below reports the empty output.
$raw = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . $probe);
$probed = json_decode(trim($raw), true);
cx(is_array($probed), 'the absolute-BASE_URL probe ran (' . trim($raw) . ')');
cx(is_array($probed) && ($probed['next_url'] ?? null) === '/archive?album=7&page=4',
   'under an absolute BASE_URL the runtime still hands the skin a RELATIVE url '
   . '(got ' . var_export($probed['next_url'] ?? null, true) . ')');
cx(is_array($probed) && !str_contains((string)($probed['next_url'] ?? ''), 'photowalk.ing'),
   'and no host reaches the skin');

echo "\nCONTROL CHARACTERS ARE REFUSED, NOT SILENTLY EDITED\n";
// htmlspecialchars does not touch control characters, so a raw value carrying
// one reaches the attribute intact. Refusing emits no link; stripping would
// emit a DIFFERENT link while looking like the one asked for.
foreach ([chr(10) => 'a newline', chr(9) => 'a tab', chr(0) => 'a null byte',
          chr(13) => 'a carriage return'] as $ch => $what) {
    cx(snap_same_origin_path('/archive?a=1' . $ch . 'x=2') === '',
       $what . ' embedded in a url is refused');
}
cx(snap_same_origin_path('/archive?a=1') === '/archive?a=1',
   'and an ordinary url still passes, so the check is not refusing everything');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
