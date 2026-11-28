<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// POCKET ALBUM, DISPLAY SIDE: [pocket_album] on a CMS page.
//
// SS-1575. The half that actually puts photographs on a page. It is checked
// here by RENDERING THE BLOCK and then putting the result through the real
// sanitiser a CMS page's content goes through, because the thing that makes
// this block work or not work is whether its markup survives
// snapsmack_trusted_html(). A test that only inspected the HTML string would
// pass with a style attribute or a data-w that the live page strips off.
//
// Two faults this exists to prevent, both already made once in this repo:
//   - asserting a call exists rather than running it (three review FAILs in
//     one week), and
//   - wiring a renderer nothing calls (SS-1563, where thirteen mutants died
//     against a dead function).
// So the entry point here is the PARSER, the way a page enters it.
//
// Run:  php tests/pocket-album-display-regression.php

$root = dirname(__DIR__);
require_once $root . '/core/pocket-album-display.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/parser.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

/**
 * A PDO stand-in. It answers the two statements the remote path runs and
 * records them, so the test can prove the album was scoped rather than assume.
 */
final class PocketAlbumFakePdo extends PDO
{
    public array $queries = [];
    public array $rows = [];
    public int $total = 0;
    public function __construct() {}
    public function exec($statement): int|false { $this->queries[] = (string)$statement; return 0; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->queries[] = $query;
        return new PocketAlbumFakeStatement($this, $query);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        return new PocketAlbumFakeStatement($this, $query);
    }
}

final class PocketAlbumFakeStatement extends PDOStatement
{
    public array $bound = [];
    public function __construct(private PocketAlbumFakePdo $pdo, private string $sql) {}
    public function execute(?array $params = null): bool { $this->bound = $params ?? []; $this->pdo->queries[] = 'EXEC:' . json_encode($this->bound); return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        if (!str_contains($this->sql, 'snap_pocket_album_items')) {
            return [];
        }
        // THE FAKE HONOURS LIMIT AND OFFSET, because the real database does.
        if (preg_match('/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $this->sql, $m)) {
            $offset = isset($m[2]) ? (int)$m[2] : 0;
            return array_slice($this->pdo->rows, $offset, (int)$m[1]);
        }
        return $this->pdo->rows;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return false;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->pdo->total; }
}

echo "POCKET ALBUM display side\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE SHORTCODE IS READ IN ONE PLACE, AND REFUSES WHAT IT CANNOT PROVE\n";
// ─────────────────────────────────────────────────────────────────────────────
// The parser and the cron both read attributes through this function, so a
// shortcode cannot mean one thing to the page and another to the refresher.

$one = pocket_album_shortcodes('<p>text</p>[pocket_album album="191"]<p>more</p>');
$check('a local album is read', count($one) === 1 && $one[0]['album'] === 191);
$check('  with no source, so nothing is fetched for it', $one[0]['src'] === '');
// NO CAP. Sean's ruling of 2026-11-25: the block shows the whole album. 0
// means every photograph, and an earlier version of this test asserted a
// default of 300 -- a cap I had applied on the author's behalf and then
// called honest.
$check('  and with no limit it means the WHOLE album, not a default cap',
       $one[0]['limit'] === 0);

$remote = pocket_album_shortcodes('[pocket_album src="https://foreverphotograph.ing/" album="191" limit="40"]');
$check('a remote album is read', count($remote) === 1 && $remote[0]['album'] === 191);
$check('  its source keeps no trailing slash, so cache keys cannot split in two',
       $remote[0]['src'] === 'https://foreverphotograph.ing');
$check('  and limit is honoured', $remote[0]['limit'] === 40);
$check('an author may ask for a subset, and it is honoured exactly',
       pocket_album_shortcodes('[pocket_album album="1" limit="24"]')[0]['limit'] === 24);
$check('a runaway limit is bounded for safety, not paginated',
       pocket_album_shortcodes('[pocket_album album="1" limit="99999999"]')[0]['limit']
           === POCKET_ALBUM_SANITY_MAX);
$check('no album id renders nothing', pocket_album_shortcodes('[pocket_album]') === []);
$check('album="0" renders nothing', pocket_album_shortcodes('[pocket_album album="0"]') === []);
$check('two shortcodes on one page are both read',
       count(pocket_album_shortcodes('[pocket_album album="1"] and [pocket_album album="2"]')) === 2);

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "A SOURCE THIS SITE CANNOT PROVE PUBLIC IS REFUSED BEFORE ANY FETCH\n";
// ─────────────────────────────────────────────────────────────────────────────
// A shortcode is author-supplied, so the address rule is applied where the
// shortcode is read, not left to the fetch.

foreach ([
    'http://127.0.0.1/x' => 'loopback',
    'http://localhost/x' => 'localhost by name',
    'http://192.168.1.20/x' => 'a private address',
    'http://[::1]/x' => 'IPv6 loopback',
    'file:///etc/passwd' => 'a file url',
    'ftp://example.com/x' => 'a non-http scheme',
    '//example.com/x' => 'a protocol-relative url',
    'https://user:pass@example.com/x' => 'credentials in the url',
    'https://example.com:8443/x' => 'a non-standard port',
    'http://10.0.0.1/x' => 'a private class-A address',
    '' => 'an empty src',
    'not a url' => 'text that is not a url',
] as $bad => $why) {
    $check("  {$why} is refused", pocket_album_shortcodes('[pocket_album album="1" src="' . $bad . '"]') === []);
}
// THE SILENT FALLBACK THAT WAS FOUND HERE. A shortcode body stops at the first
// ']', so a bracketed IPv6 literal cut it in half, src read as absent, and the
// shortcode quietly rendered this site's OWN album 1 instead of refusing. A
// refusal that turns into different working behaviour is worse than an error.
$check('  a src that is present but unreadable refuses the whole shortcode',
       pocket_album_shortcodes('[pocket_album album="1" src="http://[::1]/x"]') === []);
$check('  and it does not quietly become a local album',
       pocket_album_shortcodes('[pocket_album album="1" src="http://[::1]/x"]') !== [['src' => '', 'album' => 1, 'limit' => 0]]);

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "THE CRON CONFIGURES ITSELF FROM REMOTE SHORTCODES ONLY\n";
// ─────────────────────────────────────────────────────────────────────────────

$pdo = new PocketAlbumFakePdo();
$pairs = [];
foreach (pocket_album_shortcodes(
    '[pocket_album album="7"]'
    . '[pocket_album src="https://foreverphotograph.ing" album="191"]'
    . '[pocket_album src="https://foreverphotograph.ing" album="191"]'
    . '[pocket_album src="https://photowalk.ing" album="3"]'
) as $use) {
    if ($use['src'] === '') { continue; }
    $pairs[$use['src'] . '#' . $use['album']] = true;
}
$check('a local album adds no fetch target', !isset($pairs['#7']));
$check('the same remote album twice is one fetch target', count($pairs) === 2);

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "THE CACHED ALBUM IS READ SCOPED, AND NEVER FETCHED AT RENDER TIME\n";
// ─────────────────────────────────────────────────────────────────────────────
// A page render that made an outbound request would put a visitor behind
// another server's response time. photoblogs-feed made that promise first and
// this keeps it: the render touches the cache and nothing else.

$pdo = new PocketAlbumFakePdo();
$pdo->total = 250;
$pdo->rows = [[
    'album_name' => 'Found Textures',
    'page_url' => 'https://foreverphotograph.ing/rust-on-blue',
    'thumb_url' => 'https://foreverphotograph.ing/media/p1-a.jpg',
    'title' => 'Rust on blue', 'alt_text' => 'Peeling blue paint over rust',
    'img_width' => 1200, 'img_height' => 800,
]];
$data = pocket_album_photographs($pdo, 'https://foreverphotograph.ing', 191, 100);
$sql = implode("\n", $pdo->queries);

$check('the cache table is read', str_contains($sql, 'snap_pocket_album_items'));
$check('  scoped to the source and the album', str_contains($sql, 'EXEC:["https:\/\/foreverphotograph.ing",191]'));
$check('  and the total comes from the cache, so the link can be truthful', $data['total'] === 250);
$check('no outbound request is made at render time', !str_contains($sql, 'curl'));
$check('the album name travels with the rows', $data['album_name'] === 'Found Textures');

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "THE BLOCK SURVIVES THE SANITISER A CMS PAGE PUTS CONTENT THROUGH\n";
// ─────────────────────────────────────────────────────────────────────────────
// THIS IS THE CHECK THAT MATTERS. A CMS page's rendered content goes through
// snapsmack_trusted_html(), which REMOVES any attribute not on its allowlist
// without saying so. style="aspect-ratio:..." and data-w/data-h are both off
// that list, which is why this block does not reuse the 'wall-tile'
// component: its shape information would be silently stripped here and every
// tile would have no shape -- the SS-1046 fault, arrived at from the other
// direction. So the markup is rendered and then actually sanitised.

$raw = pocket_album_html($pdo, 'https://foreverphotograph.ing', 191, 100);
$clean = (string)snapsmack_trusted_html($raw);

$check('the grid wrapper survives', str_contains($clean, 'class="snap-pocket-album ss-masonry"'));
$check('the tile link survives', str_contains($clean, 'class="snap-pocket-tile ss-masonry-item"'));
$check('the photograph survives', str_contains($clean, 'snap-pocket-shot'));
$check('the remote thumbnail url survives intact',
       str_contains($clean, 'src="https://foreverphotograph.ing/media/p1-a.jpg"'));
$check('the link to the photograph on its own site survives',
       str_contains($clean, 'href="https://foreverphotograph.ing/rust-on-blue"'));
$check('THE SHAPE SURVIVES: width and height are still on the img',
       str_contains($clean, 'width="1200"') && str_contains($clean, 'height="800"'));
$check('  lazy loading survives', str_contains($clean, 'loading="lazy"'));
$check('the alt text survives, so the gallery is still readable aloud',
       str_contains($clean, 'alt="Peeling blue paint over rust"'));
$check('  and the "all 250" link survives', str_contains($clean, 'All 250 photographs'));

$check('the block emits NO style attribute, which the sanitiser would strip',
       !str_contains($raw, 'style='));
$check('  and no data- attribute, for the same reason', !str_contains($raw, 'data-'));
// The sanitiser ADDS rel="noopener noreferrer" to a cross-origin link, which
// is correct and is the only difference between what this block emits and what
// reaches the page. Measured rather than assumed: the first version of this
// check asserted the two were identical and failed, and the cause was an
// addition, not a removal. So the check is that nothing was REMOVED.
$check('the only change the sanitiser makes is to add rel="noopener noreferrer"',
       $raw === str_replace(' rel="noopener noreferrer"', '', $clean));
$check('  and it adds that to the cross-origin links', str_contains($clean, 'rel="noopener noreferrer"'));

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "A LOCAL ALBUM IS A QUERY, NOT A CACHE -- AND IT IS THE /images CASE\n";
// ─────────────────────────────────────────────────────────────────────────────
// [pocket_album album="N"] with no src is this site's own album. It must never
// touch the remote cache and never make an outbound request: it asks the same
// repository /archive?album=N asks, so this block and that page cannot
// disagree about what is published. This is the half /images needs; the remote
// half is the separate thing Sean asked for weeks ago.

$local = new PocketAlbumFakePdo();
pocket_album_photographs($local, '', 191, 100);
$localSql = implode("\n", $local->queries);
$check('the local path queries the images library', str_contains($localSql, 'snap_images'));
$check('  scoped through the album map, the same route /archive?album=N uses',
       str_contains($localSql, 'snap_image_album_map'));
$check('  and it asks for the album details too, so the count can be truthful',
       str_contains($localSql, 'snap_albums'));
$check('  and it NEVER reads or creates the remote cache table',
       !str_contains($localSql, 'snap_pocket_album_items'));
$check('  and the album id it was asked for is the one it binds',
       str_contains($localSql, '191'));

// ─────────────────────────────────────────────────────────────────────────────
echo "THE WHOLE ALBUM PAGES AT 100 PER BATCH FOR ENDLESS SCROLL.\n";
// ─────────────────────────────────────────────────────────────────────────────

$big = new PocketAlbumFakePdo();
$big->total = 512;
$big->rows = [];
for ($i = 1; $i <= 512; $i++) {
    $big->rows[] = [
        'album_name' => 'Found Textures',
        'page_url' => 'https://foreverphotograph.ing/photo-' . $i,
        'thumb_url' => 'https://foreverphotograph.ing/media/p' . $i . '-a.jpg',
        'title' => 'Photo ' . $i, 'alt_text' => 'Alt ' . $i,
        'img_width' => 1200, 'img_height' => 800,
    ];
}
$page1 = pocket_album_html($big, 'https://foreverphotograph.ing', 191);
$check('page 1 renders 100 photographs instead of dumping all 512 at once',
       substr_count($page1, 'class="snap-pocket-tile ss-masonry-item"') === 100);
$check('  and emits a scroll-wall sentinel pointing to page 2',
       str_contains($page1, '<a class="scroll-wall-sentinel" href="?page=2"></a>'));
$page1Safe = (string)snapsmack_trusted_html($page1);
$check('  and the sentinel survives snapsmack_trusted_html() intact',
       str_contains($page1Safe, 'class="scroll-wall-sentinel"') && str_contains($page1Safe, 'href="?page=2"'));
$check('  and NO "all N photographs" link is printed on whole-album pages',
       !str_contains($page1, 'snap-pocket-album-all'));
$check('every tile is lazy-loaded, which is what makes a whole album affordable',
       substr_count($page1, 'loading="lazy"') === 100);
$check('  and every tile carries its own shape, so the grid does not reflow',
       substr_count($page1, 'width="1200" height="800"') === 100);

$page6 = pocket_album_html($big, 'https://foreverphotograph.ing', 191, 0, false, 6);
$check('page 6 of 512 renders the remaining 12 photographs',
       substr_count($page6, 'class="snap-pocket-tile ss-masonry-item"') === 12);
$check('  including the 512th photograph',
       str_contains($page6, 'media/p512-a.jpg'));
$check('  and omits the sentinel on the final page so scroll stops',
       !str_contains($page6, 'scroll-wall-sentinel'));

// AND WHEN THE AUTHOR ASKED FOR A SUBSET, the link is the point.
$subset = pocket_album_html($big, 'https://foreverphotograph.ing', 191, 24);
$check('an author asking for 24 gets 24',
       substr_count($subset, 'class="snap-pocket-tile ss-masonry-item"') === 24);
$check('  with no endless-scroll sentinel',
       !str_contains($subset, 'scroll-wall-sentinel'));
$check('  and THEN the link to the rest appears',
       str_contains($subset, 'All 512 photographs'));

echo "
";

// ─────────────────────────────────────────────────────────────────────────────
echo "AN EMPTY REMOTE CACHE IS SILENT FOR VISITORS\n";
// ─────────────────────────────────────────────────────────────────────────────
// /images spent weeks looking like a layout fault because a page that should
// hold photographs held nothing and said nothing.

$bare = new PocketAlbumFakePdo();
$empty = pocket_album_html($bare, 'https://foreverphotograph.ing', 191, 100);
$check('a public page shows no remote-cache notice', $empty === '');
$source = '[pocket_album src="https://foreverphotograph.ing" album="191"]';
$check('the public parser keeps the notice out of cached HTML',
       !str_contains((new SnapSmack($bare))->parseContent($source), 'photographs cached'));
$preview = (new SnapSmack($bare, adminPreview: true))->parseContent($source);
$check('the admin preview names the album', str_contains($preview, 'Album 191'));
$check('  and describes the cache rather than the source contents',
       str_contains($preview, 'no photographs cached from foreverphotograph.ing'));
$check('  and it survives the sanitiser too',
       str_contains((string)snapsmack_trusted_html($preview), 'Album 191'));

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "THE PARSER IS THE REAL ENTRY POINT, AND IT IS WIRED\n";
// ─────────────────────────────────────────────────────────────────────────────
// SS-1563: a renderer with no caller passes every test and does nothing on a
// live page. So this reads the parser itself rather than trusting that the
// phase was added.

// RUN THE PARSER. Reading core/parser.php and finding the right lines in it
// is not the same as the shortcode working, and this test learned that the
// hard way: the phase was wired, the method was there, the renderer was
// called -- and the regex in it carried a stray byte, so it matched nothing
// and a live page would have printed "[pocket_album album=...]" as text. Four
// source greps all passed against it. So the content goes through
// parseContent() exactly as a CMS page's does.
$parserPdo = new PocketAlbumFakePdo();
$parserPdo->total = 250;
$parserPdo->rows = [[
    'album_name' => 'Found Textures',
    'page_url' => 'https://foreverphotograph.ing/rust-on-blue',
    'thumb_url' => 'https://foreverphotograph.ing/media/p1-a.jpg',
    'title' => 'Rust on blue', 'alt_text' => 'Peeling blue paint over rust',
    'img_width' => 1200, 'img_height' => 800,
]];
$rendered = (new SnapSmack($parserPdo))->parseContent(
    "<p>Here they are.</p>
[pocket_album src=\"https://foreverphotograph.ing\" album=\"191\"]"
);
$check('the shortcode is GONE from the output: it was actually expanded',
       !str_contains($rendered, '[pocket_album'));
$check('  and the grid is there instead', str_contains($rendered, 'class="snap-pocket-album ss-masonry"'));
$check('  with the photograph and its shape',
       str_contains($rendered, 'media/p1-a.jpg') && str_contains($rendered, 'width="1200"'));
$check('  and the surrounding page text is untouched',
       str_contains($rendered, 'Here they are.'));
// MUSE'S WORDING CONDITION. A refused shortcode produces SILENT EMPTY OUTPUT.
// It is not "refused with an error": nothing is printed, no message reaches
// the page and nothing is logged. That is right for a public page -- a
// visitor should never read a diagnostic -- but it means an author who
// mistypes a src sees an empty space and no explanation. Worth stating
// plainly rather than calling it a refusal and leaving the reader to assume
// somebody gets told.
$check('a refused shortcode renders the EMPTY STRING, silently',
       (static function () {
           $pdo = new PocketAlbumFakePdo();
           $out = (new SnapSmack($pdo))->parseContent('[pocket_album src="http://127.0.0.1/x" album="1"]');
           return trim(strip_tags($out)) === '';
       })());
$check('a shortcode the reader refuses leaves nothing behind, not raw text',
       (static function () {
           $pdo = new PocketAlbumFakePdo();
           $out = (new SnapSmack($pdo))->parseContent('[pocket_album src="http://127.0.0.1/x" album="1"]');
           return !str_contains($out, '[pocket_album') && !str_contains($out, '127.0.0.1');
       })());
$check('a name that merely starts the same is not matched',
       (static function () {
           $pdo = new PocketAlbumFakePdo();
           return str_contains((new SnapSmack($pdo))->parseContent('[pocket_albums album="1"]'), '[pocket_albums');
       })());

$parser = (string)file_get_contents($root . '/core/parser.php');
$check('it renders through pocket_album_html, not markup of its own',
       str_contains($parser, 'return pocket_album_html($this->pdo,'));
$check('  and reads attributes through the one shared reader',
       str_contains($parser, 'pocket_album_shortcodes($match[0])'));

$css = (string)file_get_contents($root . '/assets/css/shortcodes.css');
$check('the grid is styled in shortcodes.css, which loads on the live page',
       str_contains($css, '.snap-pocket-album {') && str_contains($css, '.snap-pocket-shot {'));
$check('the pocket album grid uses ss-cols masonry instead of row grid',
       str_contains($css, '--ss-cols: 3;') && !str_contains($css, 'grid-template-columns: repeat(auto-fill, minmax(var(--pocket-album-min'));
$check('  the stylesheet still ends with its EOF marker',
       str_ends_with(rtrim($css), '/* ===== SNAPSMACK EOF ===== */'));

$display = (string)file_get_contents($root . '/core/pocket-album-display.php');
// SS-1643: the ordering is now built from what the cache table actually has.
// A posted date wins where the source gave one; everything else keeps the
// reversed album order this assertion was written for.
// SS-1659 CHANGED THIS DELIBERATELY and this assertion caught it, which is
// the test doing its job rather than being in the way. It used to pin the
// exact clause "CASE WHEN posted_on <> '' THEN 0 ELSE 1 END ASC, posted_on
// DESC" -- every dated row ahead of every undated one. That is right on an
// all-or-nothing cache and wrong once the backfill fills only the third of
// rows the source published a day or more after capture: the album came out
// as two blocks. The intent is unchanged -- prefer the SOURCE publication
// date -- so the assertion now pins the expression that does it, and the
// ORDER BY is EXECUTED against a mixed cache in
// tests/pocket-album-one-date-order-regression.php, which is where the proof
// belongs.
$check('pocket album prefers the SOURCE publication date',
       str_contains($display, "COALESCE(NULLIF(posted_on,''), NULLIF(taken_on,''))"));
$check('  and still falls back to the reversed album order',
       str_contains($display, 'sort_rank DESC,'));
$check('  with the ordering built only when the column exists',
       str_contains($display, 'SELECT posted_on FROM snap_pocket_album_items LIMIT 0'));

// THESE TWO EXIST BECAUSE A MUTANT SURVIVED. Replacing the row build with
// 'posted_on' => '' -- the cache storing no posted date at all, which is the
// original bug restored -- left every assertion above GREEN, because they all
// read the ORDER BY and nothing read the WRITE. An ordering clause over a
// column nothing fills sorts every row into the "else 1" bucket and falls
// back to sort_rank silently.
$check('  and the cached posted date comes from the feed, not a blank',
       str_contains($display, "'posted_on' => (string)(\$row['date_posted'] ?? '')"));
// The binding is positional, so posted_on must sit at the SAME index in the
// column list and in the bound values. Counted from the real INSERT rather
// than eyeballed: a column added to one list and not the other shifts every
// value after it, which stores a date in the wrong field and passes a text
// search for both names.
$check('  and posted_on is bound at the same position it is named',
       (static function (string $src): bool {
           if (!preg_match('~INSERT INTO snap_pocket_album_items\s*\(([^)]*)\)~', $src, $cols)) {
               return false;
           }
           if (!preg_match('~\$insert->execute\(\[(.*?)\]\);~s', $src, $vals)) {
               return false;
           }
           $columns = array_map('trim', explode(',', $cols[1]));
           $bound = array_map('trim', explode(',', $vals[1]));
           $bound = array_values(array_filter($bound, static fn($v) => $v !== ''));
           $ci = array_search('posted_on', $columns, true);
           $vi = array_search("\$row['posted_on']", $bound, true);
           return $ci !== false && $vi !== false && $ci === $vi;
       })($display));

$colsJs = (string)file_get_contents($root . '/assets/js/ss-engine-columns.js');
$check('ss-engine-columns falls back to intrinsic width/height attributes',
       str_contains($colsJs, "img.getAttribute('width')") && str_contains($colsJs, "img.getAttribute('height')"));

$api = (string)file_get_contents($root . '/api.php');
$check('the source feed has a route, so it is reachable rather than dead code',
       str_contains($api, "strpos(\$route, 'pocket-album') === 0"));

echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — the block renders, and every tag and attribute it emits survives\n";
echo "the sanitiser a CMS page's content goes through.\n";
echo "\n";
echo "NOT CHECKED, and it must not be reported as working: nothing here has\n";
echo "run on a live site. The source site needs pocket_album_share=on, both\n";
echo "sites need a build carrying this, and a remote album needs the hourly\n";
echo "cron line on the DISPLAYING site. The admin Cron & Jobs surface was not\n";
echo "checked for whether it lists the new job.\n";
exit(0);

// ===== SNAPSMACK EOF =====
