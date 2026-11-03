<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Every listing SURFACE honours the portfolio exclusions. (SS-1185)
 *
 * The predicates have their own tests
 * (tests/portfolio-visibility-predicate-regression.php). This file is the other
 * half, and it is the half that was missing: a predicate wired to nothing
 * filters nothing, and for most of today the flags saved correctly and changed
 * nothing visible. core/image-upload-policy.php is the cautionary example in
 * this repository -- a guard with a green test and no production callers.
 *
 * So these checks run the REAL repository methods against a database holding an
 * excluded post and an ordinary one, and require the excluded post to be absent
 * from each listing while still being fetchable by slug and by id.
 *
 * WHAT THIS DOES NOT PROVE, stated so nobody reads it as more than it is:
 *   - SQLite, not MySQL. This box has no MySQL and PHP here ships every PDO
 *     driver disabled; the harness rewrites NOW() for its own run. The SQL is
 *     otherwise the production text.
 *   - No page is rendered. This is the data boundary, not a skin.
 *   - The admin control is not exercised. Proving a flag SAVES needs the real
 *     route and is Codex's lane.
 *
 * Run: php -d extension=pdo_sqlite tests/portfolio-surfaces-honour-the-flags-regression.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(2);
}

require_once dirname(__DIR__) . '/core/public-repository.php';

$fail = 0;
function sx(bool $ok, string $what): void {
    global $fail;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

function make_db(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    @$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $pdo->exec("CREATE TABLE snap_posts (
        id INTEGER PRIMARY KEY, title TEXT, slug TEXT, description TEXT, content TEXT,
        post_type TEXT NOT NULL DEFAULT 'single',
        status TEXT NOT NULL DEFAULT 'published',
        created_at TEXT NOT NULL DEFAULT '2020-01-01 00:00:00',
        updated_at TEXT NOT NULL DEFAULT '2020-01-01 00:00:00',
        featured_image_id INTEGER NULL, signature_image_id INTEGER NULL,
        trigram_id INTEGER NULL, sort_order INTEGER NOT NULL DEFAULT 0,
        post_img_size_pct INTEGER NOT NULL DEFAULT 100,
        post_border_px INTEGER NOT NULL DEFAULT 0,
        post_border_color TEXT NOT NULL DEFAULT '#000000',
        post_bg_color TEXT NOT NULL DEFAULT '#ffffff',
        post_shadow INTEGER NOT NULL DEFAULT 0,
        show_featured_image INTEGER NOT NULL DEFAULT 1,
        cover_pos_x INTEGER NOT NULL DEFAULT 50, cover_pos_y INTEGER NOT NULL DEFAULT 50,
        cover_zoom INTEGER NOT NULL DEFAULT 100, colophon TEXT NULL,
        allow_comments INTEGER NOT NULL DEFAULT 1, allow_download INTEGER NOT NULL DEFAULT 0,
        download_url TEXT NULL, download_count INTEGER NOT NULL DEFAULT 0,
        panorama_rows INTEGER NOT NULL DEFAULT 1,
        fedi_enabled INTEGER NOT NULL DEFAULT 1,
        fedi_pushed_at TEXT NULL, fedi_published_at TEXT NULL,
        is_pinned INTEGER NOT NULL DEFAULT 0, is_sensitive INTEGER NOT NULL DEFAULT 0,
        content_warning TEXT NULL, user_id INTEGER NULL,
        featured_asset_id INTEGER NULL, import_source TEXT NULL, import_id TEXT NULL,
        portfolio_post       INTEGER NOT NULL DEFAULT 0,
        exclude_from_archive INTEGER NOT NULL DEFAULT 0,
        exclude_from_search  INTEGER NOT NULL DEFAULT 0,
        exclude_from_feed    INTEGER NOT NULL DEFAULT 0,
        exclude_from_sitemap INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE snap_images (
        id INTEGER PRIMARY KEY, img_slug TEXT, img_title TEXT, img_description TEXT,
        img_alt TEXT, img_file TEXT, img_date TEXT NOT NULL DEFAULT '2020-01-01 00:00:00',
        img_status TEXT NOT NULL DEFAULT 'published', post_id INTEGER NULL,
        img_width INTEGER DEFAULT 0, img_height INTEGER DEFAULT 0,
        img_orientation INTEGER DEFAULT 0, img_thumb_square TEXT, img_thumb_aspect TEXT,
        img_display_options TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
        img_film TEXT, img_license TEXT, img_download_url TEXT,
        allow_comments INTEGER NOT NULL DEFAULT 1, allow_download INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE snap_post_images (
        id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL, image_id INTEGER NOT NULL,
        sort_position INTEGER NOT NULL DEFAULT 0, is_cover INTEGER NOT NULL DEFAULT 0,
        img_size_pct INTEGER NOT NULL DEFAULT 100, img_border_px INTEGER NOT NULL DEFAULT 0,
        img_border_color TEXT NOT NULL DEFAULT '#000000',
        img_bg_color TEXT NOT NULL DEFAULT '#ffffff', img_shadow INTEGER NOT NULL DEFAULT 0,
        img_focus_x INTEGER NOT NULL DEFAULT 50, img_focus_y INTEGER NOT NULL DEFAULT 50,
        img_zoom INTEGER NOT NULL DEFAULT 100)");
    $pdo->exec("CREATE TABLE snap_image_cat_map (image_id INTEGER, cat_id INTEGER)");
    $pdo->exec("CREATE TABLE snap_image_album_map (image_id INTEGER, album_id INTEGER)");
    $pdo->exec("CREATE TABLE snap_categories (id INTEGER PRIMARY KEY, cat_name TEXT, show_in_archive INTEGER DEFAULT 1)");
    $pdo->exec("CREATE TABLE snap_albums (id INTEGER PRIMARY KEY, album_name TEXT,
        album_description TEXT, cover_image_id INTEGER NULL, featured_post_id INTEGER NULL)");
    // Columns taken from database/schema/snapsmack_canonical.sql, not guessed --
    // the first version of this fixture invented a "name" column and
    // carouselPostLanding immediately asked for tg.post_id_1.
    $pdo->exec("CREATE TABLE snap_image_tags (image_id INTEGER, tag_id INTEGER)");
    // snap_tags columns from the canonical schema: tag, slug, use_count,
    // color_family -- not the tag_slug/tag_name I first invented.
    $pdo->exec("CREATE TABLE snap_tags (id INTEGER PRIMARY KEY, tag TEXT, slug TEXT,
        use_count INTEGER NOT NULL DEFAULT 0, color_family TEXT,
        created_at TEXT NOT NULL DEFAULT '2020-01-01 00:00:00')");
    $pdo->exec("CREATE TABLE snap_trigrams (id INTEGER PRIMARY KEY,
        trigram_type TEXT, source_path TEXT, orientation TEXT,
        cut_a INTEGER, cut_b INTEGER,
        post_id_1 INTEGER NULL, post_id_2 INTEGER NULL, post_id_3 INTEGER NULL,
        created_at TEXT NOT NULL DEFAULT '2020-01-01 00:00:00')");

    // TWO carousel posts and TWO longform posts: one excluded, one ordinary of
    // each, so every surface has something that must vanish and something that
    // must stay. A surface that returns nothing proves nothing.
    $pdo->exec("INSERT INTO snap_posts (id,title,slug,description,content,post_type,exclude_from_archive,exclude_from_search,exclude_from_feed) VALUES
        (1,'Excluded carousel','excluded-carousel','needle','needle','carousel',1,1,1),
        (2,'Ordinary carousel','ordinary-carousel','needle','needle','carousel',0,0,0),
        (3,'Excluded longform','excluded-longform','needle','needle','longform',1,1,1),
        (4,'Ordinary longform','ordinary-longform','needle','needle','longform',0,0,0)");
    $pdo->exec("INSERT INTO snap_images (id,img_slug,img_title,img_description,img_file,img_thumb_square,img_thumb_aspect) VALUES
        (10,'img-excluded','needle','needle','a.jpg','ta.jpg','aa.jpg'),
        (11,'img-ordinary','needle','needle','b.jpg','tb.jpg','ab.jpg')");
    $pdo->exec("INSERT INTO snap_post_images (post_id,image_id,is_cover) VALUES
        (1,10,1),(2,11,1),(3,10,1),(4,11,1)");
    $pdo->exec("UPDATE snap_posts SET featured_image_id=10 WHERE id IN (1,3)");
    $pdo->exec("UPDATE snap_posts SET featured_image_id=11 WHERE id IN (2,4)");
    $pdo->exec("INSERT INTO snap_categories (id,cat_name) VALUES (1,'Cat')");
    $pdo->exec("INSERT INTO snap_albums (id,album_name) VALUES (1,'Album')");
    $pdo->exec("INSERT INTO snap_image_cat_map VALUES (10,1),(11,1)");
    $pdo->exec("INSERT INTO snap_image_album_map VALUES (10,1),(11,1)");
    return $pdo;
}

$pdo  = make_db();
$repo = new SnapPublicRepository($pdo);

echo "Listing surfaces honour the portfolio exclusions (SS-1185)\n";

sx($repo->portfolioSchemaReady(), 'the fixture schema carries all five columns');

/** ids present in a result set, under whichever key holds them */
function ids(array $rows, string $key = 'id'): array {
    return array_map('intval', array_column($rows, $key));
}

// ── A. LANDING ──────────────────────────────────────────────────────────────
// carouselPostLanding aliases p.id AS post_id. The first version of this used
// the default 'id' key, got an empty array, and "the excluded post is gone"
// passed because NOTHING was there -- a check passing for the wrong reason, in
// the test written to catch exactly that.
$carousel = ids($repo->carouselPostLanding(50), 'post_id');
sx($carousel !== [], 'LANDING carousel: the query returned rows at all');
sx(!in_array(1, $carousel, true), 'LANDING carousel: the excluded post is gone');
sx(in_array(2, $carousel, true),  'LANDING carousel: the ordinary post remains');

$longform = ids($repo->longformLanding(50));
sx(!in_array(3, $longform, true), 'LANDING longform: the excluded post is gone');
sx(in_array(4, $longform, true),  'LANDING longform: the ordinary post remains');

// ── B. SEARCH, both halves ──────────────────────────────────────────────────
$searchPosts = ids($repo->archiveSearchPosts('needle'));
sx(!in_array(1, $searchPosts, true) && !in_array(3, $searchPosts, true),
   'SEARCH archiveSearchPosts: both excluded posts are gone');
sx(in_array(2, $searchPosts, true) && in_array(4, $searchPosts, true),
   'SEARCH archiveSearchPosts: both ordinary posts remain');

$both = $repo->search('needle');
$sp = ids($both['posts'] ?? []);
sx(!in_array(1, $sp, true) && !in_array(3, $sp, true),
   'SEARCH search() posts half: excluded posts are gone');
sx(in_array(2, $sp, true) && in_array(4, $sp, true),
   'SEARCH search() posts half: ordinary posts remain');
$si = ids($both['photographs'] ?? []);
sx($si !== [], 'SEARCH search() images half: the query returned rows at all');
sx(!in_array(10, $si, true), 'SEARCH search() images half: the excluded post\'s image is gone');
sx(in_array(11, $si, true),  'SEARCH search() images half: the ordinary image remains');

// ── C. ARCHIVE, and its count must agree ────────────────────────────────────
$arch = ids($repo->archivePhotographs(50));
sx(!in_array(10, $arch, true), 'ARCHIVE: the excluded post\'s photograph is gone');
sx(in_array(11, $arch, true),  'ARCHIVE: the ordinary photograph remains');
$count = $repo->archivePhotographCount();
sx($count === count($arch),
   "ARCHIVE: count ($count) equals the listing (" . count($arch) . ") -- endless scroll completes");
sx($count < 2, 'ARCHIVE: and the count actually dropped, so it is not two unfiltered numbers');

// ── C2. THE POST COUNT MUST AGREE WITH THE LANDINGS ────────────────────────
// Added after mutation proof: removing the filter from publishedPostCount
// changed nothing observable, because nothing asserted on it. That is the
// count/listing drift this whole feature keeps tripping over -- the count says
// N, the list yields fewer, and endless scroll never completes.
// NOTE, found by this very assertion failing: carouselPostLanding is NOT
// type-scoped. It returns every published post that has a cover image, of any
// post_type -- so its matched count is publishedPostCount(null), not
// publishedPostCount('carousel'). My first version paired it with the typed
// count, got 1 against 2, and the "mismatch" was my pairing, not the code.
$allCount = $repo->publishedPostCount();
sx($allCount === count($carousel),
   "COUNT all posts ($allCount) equals the cover-image landing (" . count($carousel) . ")");
$longformCount = $repo->publishedPostCount('longform');
sx($longformCount === count($longform),
   "COUNT longform ($longformCount) equals its landing (" . count($longform) . ")");
sx($allCount === 2 && $longformCount === 1,
   'COUNT: and both actually dropped (4->2 and 2->1), so they are not unfiltered');

// ── D. NEXT / PREVIOUS ──────────────────────────────────────────────────────
$next = $repo->adjacentPosts(4, 'longform', false);
sx($next === null || (int)$next['id'] !== 3,
   'NEXT/PREV: navigation never lands on the excluded post');

// ── E. THE GAME ─────────────────────────────────────────────────────────────
$game = array_map('intval', array_column($repo->gameOnPuzzlePhotographs(100), 'post_id'));
sx(!in_array(1, $game, true), 'GAME: the excluded post is gone');
sx(in_array(2, $game, true),  'GAME: the ordinary post remains');

// ── F. TAXONOMY COUNTS — NOT PROVED HERE, AND SAYING SO ─────────────────────
// publicCategories() and publicAlbums() use MySQL's
// GROUP_CONCAT with a SEPARATOR clause, which SQLite cannot parse at all -- the
// query dies before any predicate matters. So their BEHAVIOUR cannot be
// exercised in this harness, and pretending otherwise would be the exact fault
// this file exists to catch.
//
// What can be checked here is that the predicate is WIRED INTO those two
// methods. That is a source check, not a behaviour check, and it is labelled as
// one. Their behaviour needs MySQL, and it is listed as NOT PROVED in the
// report to the room.
$src = (string)file_get_contents(dirname(__DIR__) . '/core/public-repository.php');
foreach (['publicCategories', 'publicAlbums'] as $method) {
    if (!preg_match('/    public function ' . $method . '\(.*?\n    \}/s', $src, $m)) {
        sx(false, "TAXONOMY: $method could not be located");
        continue;
    }
    sx(str_contains($m[0], "imageFilter('exclude_from_archive'"),
       "TAXONOMY $method: calls imageFilter (SOURCE check -- behaviour needs MySQL)");
    sx(str_contains($m[0], '{$ix}'),
       "TAXONOMY $method: and interpolates it into its SQL");
}

// ── G. THE POINT OF THE WHOLE FEATURE: it still opens on its own URL ────────
$bySlug = $repo->postBySlug('excluded-carousel');
sx($bySlug !== null && (int)$bySlug['id'] === 1,
   'DIRECT URL: the excluded post STILL opens by slug -- never filtered');
$byId = $repo->postById(3);
sx($byId !== null && (int)$byId['id'] === 3,
   'DIRECT URL: and by id');
$photos = $repo->photographsForPost(1);
sx(count($photos) === 1,
   'DIRECT URL: and its photographs still render inside it');

echo $fail === 0
    ? "\nPASS - every wired surface honours the flags, and direct links do not\n"
    : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
