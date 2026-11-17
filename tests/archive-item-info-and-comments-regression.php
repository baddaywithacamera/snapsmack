<?php
declare(strict_types=1);

/**
 * GLIDE SLICE 2: AN ARCHIVE TILE CARRIES ITS OWN INFO AND COMMENT STATE.
 *
 * Codex's archive overlay opens an Info/Comments pane over a tile. It needs,
 * per item, the two answers the single-photograph view has always had -- is
 * this photograph open for comments, and how many are there -- plus the link
 * to the photograph itself. An archive item reached the skin with none of
 * them, so an overlay could only always offer the pane or never.
 *
 * WHAT IS TESTED HOW.
 *
 *   photo_url is prepared in snapsmack_build_skin_view(), which needs no
 *   database, so it is CALLED here with all three input shapes.
 *
 *   approvedCommentCountsFor() is a real query, so it is run against a REAL
 *   PDO -- an in-memory SQLite database with a snap_comments table -- and the
 *   count, the filtering and the one-query promise are all observed rather
 *   than asserted from the source.
 *
 *   The per-item comments_enabled rule lives inside snapsmack_public_runtime(),
 *   which needs a live site, so that one is a SOURCE CHECK and says so. What
 *   it checks is the thing that matters: that the rule is the SAME three
 *   conditions as the single-photograph gate and not a second, subtly
 *   different copy.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/public-repository.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

echo "THE LINK TO THE PHOTOGRAPH IS PREPARED ONCE, NOT IN SIX COMPONENTS\n";
$view = snapsmack_build_skin_view(
    ['kind' => 'archive', 'status' => 200, 'item' => [], 'items' => [
        ['id' => 1, 'img_slug' => 'blue-door', 'img_title' => 'Blue Door', 'img_alt' => 'A blue door'],
        ['id' => 2, 'img_slug' => 'ignored', 'url' => '/custom/place'],
        ['id' => 3, 'img_title' => 'No slug at all'],
    ]],
    ['site_name' => 'Fixture', 'base_url' => '/', 'skin_slug' => 'glide']
);
$items = $view['response']['items'] ?? [];
cx(count($items) === 3, 'all three items survived the contract');
cx(($items[0]['photo_url'] ?? '') !== '' && str_contains((string)$items[0]['photo_url'], 'blue-door'),
   'a slug becomes a photo route (' . ($items[0]['photo_url'] ?? '') . ')');
cx(($items[1]['photo_url'] ?? '') === '/custom/place',
   'an explicit url is preferred over the route, so a skin can override it');
cx(($items[2]['photo_url'] ?? 'unset') === '',
   'and an item with neither gets an empty string, not a route to nowhere');

echo "\nTITLE AND ALT REACH THE TILE UNCHANGED\n";
// The overlay's Info pane is built from these. They were already passed; this
// is here so a future item filter cannot quietly drop them.
cx(($items[0]['img_title'] ?? '') === 'Blue Door', 'img_title arrives');
cx(($items[0]['img_alt'] ?? '') === 'A blue door', 'img_alt arrives');

echo "\nTHE COMMENT COUNTS, AGAINST A REAL DATABASE\n";
if (!extension_loaded('pdo_sqlite')) {
    echo "  --    NOT CHECKED: pdo_sqlite is not loaded on this host, so the query\n";
    echo "        cannot be run. Stated, not skipped quietly.\n";
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_comments (id INTEGER PRIMARY KEY, img_id INTEGER,
                post_id INTEGER, is_approved INTEGER, is_spam INTEGER)');
    // Photograph 1: two approved. Photograph 2: one approved, one unapproved,
    // one spam -- so a naive COUNT(*) would say three. Photograph 3: none.
    $rows = [
        [1, 1, null, 1, 0], [2, 1, null, 1, 0],
        [3, 2, null, 1, 0], [4, 2, null, 0, 0], [5, 2, null, 1, 1],
        [6, 9, null, 1, 0],
    ];
    $ins = $pdo->prepare('INSERT INTO snap_comments (id,img_id,post_id,is_approved,is_spam) VALUES (?,?,?,?,?)');
    foreach ($rows as $r) $ins->execute($r);

    $repo = new SnapPublicRepository($pdo);
    $counts = $repo->approvedCommentCountsFor([1, 2, 3]);

    cx(($counts[1] ?? null) === 2, 'a photograph with two approved comments counts 2');
    cx(($counts[2] ?? null) === 1,
       'unapproved and spam are EXCLUDED, so the one with three rows counts 1 (got '
       . var_export($counts[2] ?? null, true) . ')');
    cx(!array_key_exists(3, $counts),
       'a photograph with none is absent rather than zero, which the caller reads as ?? 0');
    cx(!array_key_exists(9, $counts),
       'and a photograph that was not asked about is not returned, even though it has comments');

    echo "\nIT IS ONE QUERY, NOT ONE PER TILE\n";
    // The reason the method exists. Fifty tiles must not be fifty queries, and
    // the only honest way to check that is to count them.
    $counting = new class('sqlite::memory:') extends PDO {
        public int $prepares = 0;
        public function prepare(string $query, array $options = []): PDOStatement|false {
            $this->prepares++;
            return parent::prepare($query, $options);
        }
    };
    $counting->exec('CREATE TABLE snap_comments (id INTEGER PRIMARY KEY, img_id INTEGER,
                     post_id INTEGER, is_approved INTEGER, is_spam INTEGER)');
    $counting->exec('INSERT INTO snap_comments VALUES (1,1,NULL,1,0),(2,2,NULL,1,0)');
    $countingRepo = new SnapPublicRepository($counting);
    $countingRepo->approvedCommentCountsFor(range(1, 50));
    cx($counting->prepares === 1,
       'fifty ids cost exactly one prepared statement (counted ' . $counting->prepares . ')');

    echo "\nAN EMPTY PAGE TOUCHES THE DATABASE NOT AT ALL\n";
    $before = $counting->prepares;
    $countingRepo->approvedCommentCountsFor([]);
    $countingRepo->approvedCommentCountsFor([0, -1, 'nonsense']);
    cx($counting->prepares === $before,
       'no ids, and no usable ids, run no query at all (still ' . $counting->prepares . ')');
}

echo "\nTHE PER-ITEM RULE IS THE SAME RULE AS THE PHOTOGRAPH GATE\n";
// SOURCE CHECK, LABELLED. snapsmack_public_runtime() needs a live site, so the
// rule cannot be called from here. What is asserted is that it is not a second
// copy: a permission test written twice is how one page ends up disagreeing
// with another about whether comments are on.
$runtime = (string)file_get_contents($root . '/core/public-runtime.php');
foreach (['global_comments_enabled', 'community_comments_enabled', 'allow_comments'] as $cond) {
    cx(substr_count($runtime, $cond) >= 2,
       "the per-item rule consults {$cond}, as the single-photograph gate does");
}
cx(str_contains($runtime, 'approvedCommentCountsFor('),
   'and it asks for the counts in one call rather than per item');
cx(str_contains($runtime, 'if ($_siteComments)'),
   'and skips the query entirely when the site-wide switches are off, because then every answer is zero');

echo "\nSEARCH RESULTS GET THE SAME TWO ANSWERS AS AN ARCHIVE TILE\n";
// Codex found this gap wiring GLIDE's search: "search results lack prepared
// comment metadata, unlike archive items", and said he would show only a post
// link when the state was unavailable. He should not have to -- the same
// photograph in a search deserves the same answers as in an archive.
//
// Search is shaped differently: it returns 'results', a map whose rows sit
// under group keys, not a flat 'items'. So the enrichment walks both shapes,
// and it has to do that WITHOUT a second copy of the permission test and
// WITHOUT a query per group.
//
// SOURCE CHECK, labelled: the enrichment lives inside
// snapsmack_public_runtime(), which needs a live site. Bounded on the block
// itself, not on a character count.
$enrich = (static function (string $src): string {
    $at = strpos($src, '$_lists = [];');
    if ($at === false) return '';
    $end = strpos($src, 'unset($_lists);', $at);
    return $end === false ? '' : substr($src, $at, $end - $at);
})($runtime);
cx($enrich !== '', 'the comment-enrichment block was found');
cx($enrich !== '' && str_contains($enrich, "=== 'search'"),
   'search is one of the kinds it enriches');
cx($enrich !== '' && str_contains($enrich, "'results'"),
   "and it reaches into the 'results' map, which is how a search returns rows");
cx($enrich !== '' && substr_count($enrich, 'approvedCommentCountsFor(') === 1,
   'the counts are still fetched in exactly ONE call across every list on the '
   . 'page, so a search with two groups is not two queries');
cx($enrich !== '' && str_contains($enrich, 'array_unique($_ids)'),
   'and an id appearing in two groups is asked for once');
cx($enrich !== '' && substr_count($enrich, "\$_it['allow_comments']") === 1,
   'the per-item permission test appears ONCE, not once per shape');
cx($enrich !== '' && substr_count($enrich, "'comments_enabled'") === 1
   && substr_count($enrich, "'comment_count'") === 1,
   'and so does each field it stamps');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
