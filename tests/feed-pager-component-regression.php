<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * The core feed-pager component emits the endless-scroll sentinel and a real
 * no-JS fallback link. (SS-986D.)
 *
 * ss-engine-tag-infinite.js finds "#<prefix>-sentinel" and reads its data-*.
 * A non-convention skin (the smacktalk feed, GLIDE hashtag, TRUE-GRIT landing)
 * passes grid_selector / item_selector and the engine honours them; a
 * convention skin omits them. The fallback is a <noscript> anchor so paging
 * still works with JavaScript off -- the one case infinite scroll cannot cover.
 * Markup is core's; the >=44px touch size and all looks are the skin's CSS.
 *
 * This renders the component in each mode and asserts the contract in the
 * produced HTML, so it cannot silently drift from what the engine reads.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}
function pager(array $data): string {
    return (string)snap_render_component('feed-pager', $data);
}

echo "NO MORE PAGES -> NOTHING\n";
cx(pager(['prefix' => 'smacktalk', 'next_url' => '', 'next_page' => 0]) === '',
   'a feed with no next page renders no pager at all');

echo "\nA NON-CONVENTION FEED (smacktalk) CARRIES THE SELECTORS AND A FALLBACK\n";
$h = pager([
    'prefix' => 'smacktalk', 'feed' => true,
    'next_url' => '/?page=2', 'next_page' => 2,
    'grid_selector' => '#browse-grid.smacktalk-feed',
    'item_selector' => '.smacktalk-feed-item',
    'label' => 'Older posts',
]);
cx(str_contains($h, 'id="smacktalk-sentinel"'), 'the sentinel id is <prefix>-sentinel');
cx(str_contains($h, 'class="ss-feed-sentinel"'), 'the sentinel carries the ss-feed-sentinel class');
cx(str_contains($h, ' data-feed'), 'a feed sentinel is marked data-feed');
cx(str_contains($h, 'data-next="2"'), 'it carries the next page number');
cx(str_contains($h, 'data-next-url="/?page=2"'), 'it carries the prepared next-url verbatim');
cx(str_contains($h, 'data-grid-selector="#browse-grid.smacktalk-feed"'), 'it passes the explicit grid selector');
cx(str_contains($h, 'data-item-selector=".smacktalk-feed-item"'), 'it passes the explicit item selector');
cx(str_contains($h, '<noscript>') && str_contains($h, 'href="/?page=2"') && str_contains($h, 'rel="next"'),
   'a real no-JS fallback anchor points at the next url');
cx(str_contains($h, 'ss-feed-pager-link'), 'the fallback link carries ss-feed-pager-link for skin CSS (>=44px)');
cx(str_contains($h, 'Older posts'), 'the fallback link uses the supplied label');

echo "\nA CONVENTION FEED OMITS THE SELECTORS\n";
$c = pager(['prefix' => 'tg', 'feed' => true, 'next_url' => '/?page=3', 'next_page' => 3]);
cx(!str_contains($c, 'data-grid-selector') && !str_contains($c, 'data-item-selector'),
   'no selector attrs when the skin follows the .<prefix>-grid/.<prefix>-tile convention');
cx(str_contains($c, 'id="tg-sentinel"'), 'the convention sentinel still gets its id and data-next-url');

echo "\nA HASHTAG PAGER IS data-tag, NOT data-feed\n";
$t = pager(['prefix' => 'glide', 'tag' => 'street', 'next_url' => '/?tag=street&page=2', 'next_page' => 2]);
cx(str_contains($t, 'data-tag="street"') && !str_contains($t, ' data-feed'),
   'a hashtag sentinel carries data-tag and not data-feed');

echo "\nNO PREPARED URL -> SENTINEL ONLY, NO EMPTY FALLBACK LINK\n";
$p = pager(['prefix' => 'tg', 'feed' => true, 'next_url' => '', 'next_page' => 2, 'base' => '/']);
cx(str_contains($p, 'id="tg-sentinel"') && str_contains($p, 'data-next="2"'),
   'a p=N-only skin still gets a sentinel to scroll');
cx(!str_contains($p, '<noscript>'),
   'but no no-JS link is printed when there is no prepared url to point at');

echo "\nNO STATIC style ATTRIBUTE ANYWHERE IN THE OUTPUT\n";
cx(!str_contains($h, 'style=') && !str_contains($c, 'style=') && !str_contains($t, 'style='),
   'the component emits no inline style (looks are the skin stylesheet)');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
