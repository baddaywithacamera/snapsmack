<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * The /images page must come out of the parser as photographs.
 *
 * WHY THIS EXISTS. /images looked like a layout fault for weeks. It was not:
 * no shortcode in the build could put a gallery of the site's own photographs
 * on a static page, so the page had nothing to render and every check that
 * read markup was happy. The POCKET ALBUM block closed that, and this is the
 * check that would have caught the original state -- it renders a page whose
 * content is the shortcode and insists on real <img> tags coming out.
 *
 * It drives the REAL parser and the REAL public repository against a real
 * database built from a fixture. The only adaptation is NOW(), which SnapSmack
 * uses throughout and SQLite does not have: it is registered as a function on
 * the connection, so the production SQL runs here exactly as written. No query
 * is rewritten for the test.
 */

require_once dirname(__DIR__) . '/core/pocket-album-display.php';
require_once dirname(__DIR__) . '/core/public-repository.php';
require_once dirname(__DIR__) . '/core/parser.php';

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

if (!defined('BASE_URL')) { define('BASE_URL', 'https://example.test/'); }

// ---------------------------------------------------------------------------
// The fixture: one site, one album, four published photographs, one /images
// page whose body is the shortcode. One photograph is deliberately kept out of
// the album, and one is unpublished, so "it rendered something" is not enough.
// ---------------------------------------------------------------------------
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = class_exists('Pdo\Sqlite')
    ? Pdo\Sqlite::connect('sqlite::memory:', null, null, $opts)
    : new PDO('sqlite::memory:', null, null, $opts);
$now = static fn(): string => date('Y-m-d H:i:s');
$pdo->createFunction('NOW', $now, 0);

$pdo->exec("CREATE TABLE snap_pages (
    id INTEGER PRIMARY KEY, slug TEXT, title TEXT, content TEXT,
    image_asset TEXT DEFAULT '', image_size TEXT DEFAULT '', image_align TEXT DEFAULT '',
    image_shadow INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0,
    created_at TEXT DEFAULT '2026-01-01 00:00:00', is_active INTEGER DEFAULT 1)");
$pdo->exec("CREATE TABLE snap_images (
    id INTEGER PRIMARY KEY, img_title TEXT, img_slug TEXT, img_alt TEXT, img_file TEXT,
    img_width INTEGER DEFAULT 0, img_height INTEGER DEFAULT 0,
    img_thumb_square TEXT DEFAULT '', img_thumb_aspect TEXT DEFAULT '',
    img_orientation INTEGER DEFAULT 0, allow_comments INTEGER DEFAULT 1,
    img_status TEXT DEFAULT 'published', img_date TEXT DEFAULT '2026-01-01 00:00:00',
    img_description TEXT DEFAULT '', sort_order INTEGER DEFAULT 0,
    exclude_from_archive INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE snap_albums (
    id INTEGER PRIMARY KEY, album_name TEXT, album_description TEXT DEFAULT '',
    featured_post_id INTEGER NULL, view_count INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE snap_image_album_map (image_id INTEGER, album_id INTEGER)");
$pdo->exec("CREATE TABLE snap_image_cat_map (image_id INTEGER, cat_id INTEGER)");
$pdo->exec("CREATE TABLE snap_posts (
    id INTEGER PRIMARY KEY, signature_image_id INTEGER NULL,
    status TEXT DEFAULT 'published', created_at TEXT DEFAULT '2026-01-01 00:00:00')");
$pdo->exec("CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)");

$pdo->exec("INSERT INTO snap_albums (id, album_name) VALUES (3, 'The Yard')");
$shots = [
    [11, 'Frost on the gate',  'frost-on-the-gate',  'Frost along an iron gate', 1600, 1067, 1],
    [12, 'Blue hour',          'blue-hour',          'The yard at blue hour',     1067, 1600, 1],
    [13, 'Three crows',        'three-crows',        '',                          1600, 1600, 1],   // saved EMPTY alt: decorative, on purpose
    [17, 'Gatepost',           'gatepost',           null,                        1600, 1067, 1],   // alt never set at all
    [14, 'Snow fence',         'snow-fence',         'A leaning snow fence',      1600, 1067, 1],
    [15, 'Not in the album',   'not-in-the-album',   'Elsewhere',                 1600, 1067, 0],
];
foreach ($shots as [$id, $title, $slug, $alt, $w, $h, $in_album]) {
    $pdo->prepare("INSERT INTO snap_images
        (id, img_title, img_slug, img_alt, img_file, img_width, img_height, img_thumb_aspect)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$id, $title, $slug, $alt, 'uploads/' . $slug . '.jpg', $w, $h,
                   'uploads/thumbs/' . $slug . '-aspect.jpg']);
    if ($in_album) {
        $pdo->prepare("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (?, 3)")
            ->execute([$id]);
    }
}
// Published, in the album, but withheld: it must not reach the page.
$pdo->exec("INSERT INTO snap_images (id, img_title, img_slug, img_alt, img_file, img_thumb_aspect, img_status)
            VALUES (16, 'Draft shot', 'draft-shot', 'Draft', 'uploads/draft.jpg', 'uploads/t/draft.jpg', 'draft')");
$pdo->exec("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (16, 3)");

$pdo->exec("INSERT INTO snap_pages (id, slug, title, content) VALUES
    (7, 'images', 'Images', 'A few from the yard.

[pocket_album album=\"3\"]

That is the lot.')");

// ---------------------------------------------------------------------------
// Render the page exactly as the site does: its stored content, through the
// parser, with nothing injected.
// ---------------------------------------------------------------------------
$repository = new SnapPublicRepository($pdo);
$page = $repository->activePageById(7);
$check('the /images page is in the fixture and active', is_array($page) && ($page['slug'] ?? '') === 'images');

$parser = new SnapSmack($pdo);
$html = (string)$parser->parseContent((string)($page['content'] ?? ''));

// ---------------------------------------------------------------------------
// What must be true.
// ---------------------------------------------------------------------------
$check('THE SHORTCODE IS GONE FROM THE OUTPUT -- it rendered rather than printing itself',
    strpos($html, '[pocket_album') === false);
$check('the page is not the empty-album message',
    strpos($html, 'snap-pocket-album-empty') === false);

preg_match_all('~<img\b[^>]*>~i', $html, $imgs);
$check('FIVE PHOTOGRAPHS RENDER AS <img> TAGS, one per album member: got '
    . count($imgs[0]), count($imgs[0]) === 5);

$check('the withheld draft is not on the page', strpos($html, 'draft-shot') === false);
$check('a photograph outside the album is not on the page',
    strpos($html, 'not-in-the-album') === false);

foreach (['frost-on-the-gate', 'blue-hour', 'three-crows', 'snow-fence', 'gatepost'] as $slug) {
    $check("the page carries the photograph {$slug}", strpos($html, $slug) !== false);
}

$check('each photograph is a link to its own page',
    preg_match_all('~<a class="snap-pocket-tile" href="~', $html) === 5);
$check('every tile states its shape, so the grid does not reflow',
    preg_match_all('~<img[^>]*width="\d+" height="\d+"~', $html) === 5);
$check('photographs load lazily', preg_match_all('~loading="lazy"~', $html) === 5);
$check('the real alt text is used where the photograph has one',
    strpos($html, 'alt="Frost along an iron gate"') !== false);
// SS-1590's rule: a SAVED empty ALT is a deliberate "this photograph is
// decorative" and must be left empty. Only an ALT that was never set falls
// back to the title. The two cases are not interchangeable, so both are here.
$check('a SAVED EMPTY alt stays empty rather than being filled with the title',
    strpos($html, 'alt="Three crows"') === false && strpos($html, 'alt=""') !== false);
$check('an alt that was never set falls back to the title',
    strpos($html, 'alt="Gatepost"') !== false);
$check('the whole album is shown, so there is no "all N photographs" link',
    strpos($html, 'snap-pocket-album-all') === false);
$check('no static style attribute reaches the page', strpos($html, 'style="') === false);

// A subset DOES get the link. Same page, same album, author asked for two.
$subset = (string)$parser->parseContent('[pocket_album album="3" limit="2"]');
preg_match_all('~<img\b[^>]*>~i', $subset, $two);
$check('limit="2" renders two photographs', count($two[0]) === 2);
$check('a subset links to the rest of the album',
    strpos($subset, 'snap-pocket-album-all') !== false && strpos($subset, 'All 5 photographs') !== false);

// The local album needs no cron and no sharing switch: it is this site's own
// data, read at render time. Proven by rendering with sharing absent entirely.
// The `|| true` that used to sit here made the first half of this check
// vacuous: it asserted the photograph count and nothing about the setting.
// Muse caught it in review of 2382b8fd. fetchColumn() returns a string from
// SQLite, so the comparison has to allow for that rather than be weakened.
$share_rows = (int)$pdo->query(
    "SELECT COUNT(*) FROM snap_settings WHERE setting_key = 'pocket_album_share'")->fetchColumn();
$check('NO sharing setting is stored at all in this fixture', $share_rows === 0);
$check('the local album still rendered all five photographs without one',
    count($imgs[0]) === 5);

// An album that does not exist says which album is empty rather than nothing.
$missing = (string)$parser->parseContent('[pocket_album album="99"]');
$check('an empty album names itself instead of rendering a blank space',
    strpos($missing, 'album 99') !== false);

// ---------------------------------------------------------------------------
echo "\n";
if ($fails) {
    echo "FAILED (" . count($fails) . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS\n";

// NOT CHECKED: the page in a browser at Sean's widths, and the geometry of the
// grid. This proves the page renders photographs; it does not measure them.

// ===== SNAPSMACK EOF =====
