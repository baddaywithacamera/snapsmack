<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * PAGE CACHE must not serve or store ActivityPub responses (F8 cache gap).
 *
 * A profile slug serves the actor as application/activity+json through content
 * negotiation, and it is gated on fediverse_enabled. But page_cache_serve_or_start
 * runs BEFORE that gate, the cache key ignores Accept, and the store callback kept
 * any 200 regardless of Content-Type. So an AP request's actor JSON was cached
 * under the HTML key, then a later plain browser request was served that JSON by
 * readfile() -- wrong content type, and the federation gate bypassed entirely once
 * it was disabled. This pins the two guards that close it:
 *   - an AP request (Accept: activity+json / ld+json) is NOT cache-eligible;
 *   - a non-HTML 200 is NOT written to the HTML page cache.
 */

require_once dirname(__DIR__) . '/core/page-cache.php';

$fails = 0;
function check(string $label, bool $cond): void
{
    global $fails;
    if ($cond) { echo "ok: $label\n"; }
    else { $fails++; fwrite(STDERR, "FAIL: $label\n"); }
}

// ---- a baseline of otherwise-cacheable request state ----
function cacheable_request(): void
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/sean';          // pretty profile slug, no '?'
    $_SERVER['HTTP_HOST'] = 'example.ca';
    unset($_SERVER['HTTP_ACCEPT']);
    $_COOKIE = [];
    $_SESSION = [];
}
$settings = ['cache_enabled' => '1', 'cache_dev_until' => 0, 'cache_ttl' => 300];

// ---- eligibility: a plain HTML request IS cacheable (guards aren't refusing all) ----
cacheable_request();
$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,*/*';
check('a plain browser request to a profile slug is cacheable', page_cache_eligible($settings) === true);

// ---- eligibility: an ActivityPub request is NOT cacheable ----
cacheable_request();
$_SERVER['HTTP_ACCEPT'] = 'application/activity+json';
check('an activity+json request is NOT cacheable', page_cache_eligible($settings) === false);

cacheable_request();
$_SERVER['HTTP_ACCEPT'] = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';
check('an ld+json request is NOT cacheable', page_cache_eligible($settings) === false);

cacheable_request();
$_SERVER['HTTP_ACCEPT'] = 'Application/Activity+JSON';   // case-insensitive
check('AP Accept match is case-insensitive', page_cache_eligible($settings) === false);

// ---- store guard: only an HTML response may be persisted ----
check('html content-type is storable',
      page_cache_headers_are_html(['Content-Type: text/html; charset=utf-8']) === true);
check('no content-type defaults to html (storable)',
      page_cache_headers_are_html([]) === true);
check('activity+json content-type is NOT storable',
      page_cache_headers_are_html(['Content-Type: application/activity+json; charset=utf-8']) === false);
check('plain json content-type is NOT storable',
      page_cache_headers_are_html(['Content-Type: application/json']) === false);
check('lowercase header name still matches',
      page_cache_headers_are_html(['content-type: text/html']) === true);

// ---- structural: the store callback actually consults the html guard ----
$src = file_get_contents(dirname(__DIR__) . '/core/page-cache.php');
check('the store callback guards on page_cache_headers_are_html(headers_list())',
      (bool)preg_match('/ob_start\(.*page_cache_headers_are_html\(headers_list\(\)\)/s', $src));

if ($fails) { fwrite(STDERR, "\n$fails assertion(s) FAILED\n"); exit(1); }
echo "\npage cache federation bypass: all checks passed\n";

// ===== SNAPSMACK EOF =====
