<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * publicCategories / publicAlbums pick the SAME cover after the rewrite.
 *
 * WHAT CHANGED. Both methods chose the fallback cover with
 *   SUBSTRING_INDEX(GROUP_CONCAT(i.img_file ORDER BY i.id DESC SEPARATOR '..'),'..',1)
 * which is MySQL-only -- SQLite cannot parse the SEPARATOR clause, so the query
 * died before any predicate mattered and the behaviour could not be exercised
 * on this machine at all. It is now a correlated subquery asking the same
 * question, which runs on both engines.
 *
 * HOW THIS PROVES EQUIVALENCE, AND THE LIMIT OF THAT PROOF. The old SQL cannot
 * be executed here -- that is the whole reason it was replaced -- so this does
 * NOT run old against new. It compares the new SQL against an ORACLE written in
 * PHP from the old SQL's semantics:
 *
 *     GROUP_CONCAT(... ORDER BY i.id DESC) then take the first element
 *       == the img_file of the qualifying image with the HIGHEST id
 *
 * So what is proved is that the new SQL implements that rule, on fixtures chosen
 * to include the cases where a careless rewrite would diverge. What is NOT
 * proved is my reading of the old SQL, and I am saying so rather than calling
 * this a before/after run. A real MySQL comparison stays available if Sean ever
 * names a box.
 *
 * TIES CANNOT DIFFER, and that is worth stating because it was asked about: the
 * ordering key is i.id, a unique primary key. Two rows cannot share it, so there
 * is no tie for either form to break differently. The NULL cases below are the
 * ones that could actually diverge.
 *
 * Run: php -d extension=pdo_sqlite tests/taxonomy-cover-selection-regression.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(2);
}

require_once dirname(__DIR__) . '/core/public-repository.php';

$fail = 0;
function cx(bool $ok, string $what): void {
    global $fail;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

function make_db(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    @$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $pdo->exec("CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, slug TEXT,
        status TEXT NOT NULL DEFAULT 'published',
        created_at TEXT NOT NULL DEFAULT '2020-01-01 00:00:00',
        signature_image_id INTEGER NULL, post_id INTEGER NULL,
        portfolio_post INTEGER NOT NULL DEFAULT 0,
        exclude_from_archive INTEGER NOT NULL DEFAULT 0,
        exclude_from_search INTEGER NOT NULL DEFAULT 0,
        exclude_from_feed INTEGER NOT NULL DEFAULT 0,
        primary_album_id INTEGER NULL, primary_category_id INTEGER NULL)");
    $pdo->exec("CREATE TABLE snap_images (id INTEGER PRIMARY KEY, img_file TEXT,
        img_status TEXT NOT NULL DEFAULT 'published',
        img_date TEXT NOT NULL DEFAULT '2020-01-01 00:00:00', post_id INTEGER NULL)");
    $pdo->exec("CREATE TABLE snap_post_images (id INTEGER PRIMARY KEY,
        post_id INTEGER NOT NULL, image_id INTEGER NOT NULL,
        sort_position INTEGER NOT NULL DEFAULT 0, is_cover INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE snap_categories (id INTEGER PRIMARY KEY, cat_name TEXT,
        show_in_archive INTEGER NOT NULL DEFAULT 1)");
    $pdo->exec("CREATE TABLE snap_albums (id INTEGER PRIMARY KEY, album_name TEXT,
        album_description TEXT, cover_image_id INTEGER NULL, featured_post_id INTEGER NULL)");
    $pdo->exec("CREATE TABLE snap_image_cat_map (image_id INTEGER, cat_id INTEGER)");
    $pdo->exec("CREATE TABLE snap_image_album_map (image_id INTEGER, album_id INTEGER)");
    return $pdo;
}

/** The OLD SQL's rule, in PHP: highest-id qualifying image in the group. */
function oracle_cover(PDO $pdo, string $mapTable, string $fkColumn, int $groupId): ?string {
    $sql = "SELECT i.id, i.img_file
              FROM snap_images i
              JOIN {$mapTable} m ON m.image_id = i.id AND m.{$fkColumn} = :g
             WHERE i.img_status = 'published' AND i.img_date <= CURRENT_TIMESTAMP
               AND NOT EXISTS (
                    SELECT 1 FROM snap_posts vp
                     WHERE vp.status='published' AND vp.created_at <= CURRENT_TIMESTAMP
                       AND (EXISTS (SELECT 1 FROM snap_post_images vpi
                                     WHERE vpi.image_id=i.id AND vpi.post_id=vp.id)
                            OR i.post_id = vp.id)
                       AND NOT EXISTS (
                            SELECT 1 FROM snap_posts vq
                             WHERE vq.status='published' AND vq.created_at <= CURRENT_TIMESTAMP
                               AND vq.exclude_from_archive = 0
                               AND (EXISTS (SELECT 1 FROM snap_post_images vqi
                                             WHERE vqi.image_id=i.id AND vqi.post_id=vq.id)
                                    OR i.post_id = vq.id)))";
    $rows = $pdo->prepare($sql);
    $rows->execute([':g' => $groupId]);
    $best = null;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($best === null || (int)$r['id'] > (int)$best['id']) $best = $r;
    }
    return $best === null ? null : (string)$best['img_file'];
}

echo "Taxonomy cover selection survives the GROUP_CONCAT rewrite\n";

// ── FIXTURE ────────────────────────────────────────────────────────────────
$pdo = make_db();
$repo = new SnapPublicRepository($pdo);

$pdo->exec("INSERT INTO snap_categories (id,cat_name) VALUES
    (1,'Alpha'), (2,'Beta'), (3,'Gamma')");
// Albums: 2 has a manual cover; 3's manual cover points at a MISSING image row;
// 4 has featured_post_id instead; 1 has neither.
$pdo->exec("INSERT INTO snap_albums (id,album_name,cover_image_id,featured_post_id) VALUES
    (1,'NoCover',NULL,NULL),
    (2,'ManualCover',21,NULL),
    (3,'DanglingCover',999,NULL),
    (4,'FeaturedOnly',NULL,22)");
$pdo->exec("INSERT INTO snap_images (id,img_file,img_status) VALUES
    (10,'low.jpg','published'),
    (11,'mid.jpg','published'),
    (12,'high.jpg','published'),
    (13,'draft.jpg','draft'),
    (21,'manual.jpg','published'),
    (22,'featured.jpg','published'),
    -- id 30 is HIGHER than album 2's manual cover (21) and belongs to
    -- album 2. Without it the manual cover was also the highest-id image,
    -- so dropping the COALESCE produced the SAME answer and the mutant
    -- that removes the manual cover passed. Found by mutation proof.
    (30,'newer-than-manual.jpg','published')");
// Category 1: three published plus one draft, so the highest PUBLISHED id wins
// and the draft must not. Category 2: one image. Category 3: NONE.
$pdo->exec("INSERT INTO snap_image_cat_map VALUES (10,1),(11,1),(12,1),(13,1),(10,2)");
$pdo->exec("INSERT INTO snap_image_album_map VALUES
    (10,1),(11,1),(12,1),(13,1),
    (10,2),(21,2),(30,2),
    (10,3),
    (10,4),(22,4)");

// ── 1. CATEGORIES: new SQL agrees with the oracle ──────────────────────────
$cats = [];
foreach ($repo->publicCategories() as $row) $cats[(int)$row['id']] = $row;

cx(isset($cats[1]), 'publicCategories RUNS on SQLite at all -- it could not before');

foreach ([1, 2] as $cid) {
    $expected = oracle_cover($pdo, 'snap_image_cat_map', 'cat_id', $cid);
    $actual   = $cats[$cid]['cover_path'] ?? null;
    cx($actual === $expected,
       "CATEGORY $cid cover matches the old rule: expected " . var_export($expected, true)
       . ", got " . var_export($actual, true));
}
cx(($cats[1]['cover_path'] ?? null) === 'high.jpg',
   'CATEGORY 1 picks the HIGHEST id, not the first or the lowest');
cx(($cats[1]['cover_path'] ?? null) !== 'draft.jpg',
   'and never an unpublished image, even though it has the highest id of all');
cx(!isset($cats[3]),
   'CATEGORY 3 has no qualifying image, so it does not appear at all -- the JOIN drops it');

// ── 2. ALBUMS, including every NULL case ───────────────────────────────────
$albums = [];
foreach ($repo->publicAlbums() as $row) $albums[(int)$row['id']] = $row;

cx(isset($albums[1]), 'publicAlbums RUNS on SQLite at all');

cx(($albums[1]['cover_path'] ?? null) === 'high.jpg',
   'ALBUM 1, no manual cover: falls back to the highest-id image');
cx(($albums[2]['cover_path'] ?? null) === 'manual.jpg',
   'ALBUM 2, manual cover set: the MANUAL cover still wins over the fallback');
cx(($albums[2]['cover_path'] ?? null) !== 'newer-than-manual.jpg',
   'and it wins even though a HIGHER-id image exists in that album -- which is '
   . 'what makes this case able to tell the two code paths apart');
cx(($albums[3]['cover_path'] ?? null) === 'low.jpg',
   'ALBUM 3, manual cover points at a MISSING image row: falls back rather than '
   . 'returning NULL -- the COALESCE case a careless rewrite breaks');
cx(($albums[4]['cover_path'] ?? null) === 'featured.jpg',
   'ALBUM 4, featured_post_id instead of cover_image_id: still honoured');

foreach ([1, 2, 3, 4] as $aid) {
    $fallback = oracle_cover($pdo, 'snap_image_album_map', 'album_id', $aid);
    $row = $albums[$aid] ?? null;
    $manual = null;
    if ($row !== null) {
        $cid = $row['cover_image_id'] ?? $row['featured_post_id'] ?? null;
        if ($cid !== null) {
            $st = $pdo->prepare("SELECT img_file FROM snap_images WHERE id=?");
            $st->execute([$cid]);
            $manual = $st->fetchColumn() ?: null;
        }
    }
    $expected = $manual ?? $fallback;
    cx(($row['cover_path'] ?? null) === $expected,
       "ALBUM $aid matches COALESCE(manual, highest-id): expected "
       . var_export($expected, true));
}

// ── 3. THE EXCLUSION PREDICATE REACHES THE COVER, NOT JUST THE COUNT ───────
// If the cover subquery lost the filter, an excluded photograph could become a
// category's cover while the count beside it excluded the same photograph.
$pdo->exec("INSERT INTO snap_posts (id,slug,exclude_from_archive) VALUES (900,'excluded-post',1)");
$pdo->exec("INSERT INTO snap_post_images (post_id,image_id) VALUES (900,12)");
$cats2 = [];
foreach ($repo->publicCategories() as $row) $cats2[(int)$row['id']] = $row;
cx(($cats2[1]['cover_path'] ?? null) === 'mid.jpg',
   'excluding the highest-id photograph moves the cover DOWN to the next one');
cx((int)($cats2[1]['photograph_count'] ?? 0) === 2,
   'and the count drops in step with it (' . ($cats2[1]['photograph_count'] ?? 'none') . ')');
$expected2 = oracle_cover($pdo, 'snap_image_cat_map', 'cat_id', 1);
cx(($cats2[1]['cover_path'] ?? null) === $expected2,
   'and still agrees with the oracle under exclusion: ' . var_export($expected2, true));

// THE ALBUM SIDE OF THE SAME CHECK. Added after mutation proof: removing the
// exclusion predicate from the ALBUM cover subquery changed nothing observable,
// because every exclusion assertion here was about categories. Album 1 holds
// the same photographs, so its cover must move down too.
$albums2 = [];
foreach ($repo->publicAlbums() as $row) $albums2[(int)$row['id']] = $row;
cx(($albums2[1]['cover_path'] ?? null) === 'mid.jpg',
   'ALBUM 1 cover also moves down when the highest-id photograph is excluded');
$expectedA = oracle_cover($pdo, 'snap_image_album_map', 'album_id', 1);
cx(($albums2[1]['cover_path'] ?? null) === $expectedA,
   'and agrees with the oracle: ' . var_export($expectedA, true));
cx((int)($albums2[1]['photograph_count'] ?? 0) === 2,
   'and its count drops in step (' . ($albums2[1]['photograph_count'] ?? 'none') . ')');

echo $fail === 0
    ? "\nPASS - the rewrite picks the same cover, including every NULL case\n"
    : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
