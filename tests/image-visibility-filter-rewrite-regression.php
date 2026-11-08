<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
//     // ===== SNAPSMACK EOF =====
// Last non-empty line of this file MUST match the line above.

/**
 * The archive-visibility predicate stays correct after the index-seek rewrite.
 * (SS-987D, CT110 outage.)
 *
 * imageFilter() hides an image iff it has at least one published, currently
 * visible owner AND none of those owners keeps it in the archive. The original
 * form nested an EXISTS over snap_posts around an EXISTS over the snap_post_images
 * pivot, so the planner scanned every published post for every image (images x
 * posts) -- measured at 280-363 seconds per archive COUNT on the fleet DB, which
 * pegged CT110. The rewrite flattens each test into an index seek (pivot by
 * snap_post_images(image_id), posts by PK) WITHOUT changing the result.
 *
 * This runs the ACTUAL method output against the old nested form over a seeded
 * set of every ownership shape, and asserts the two agree and match the hand-
 * computed expected set. The EXPLAIN before/after (old: snap_posts type=ALL;
 * new: eq_ref/ref index seeks) is in the commit message and the hotfix note.
 */

$root = dirname(__DIR__);
require_once $root . '/core/public-repository.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

// The old nested predicate, kept here as the reference oracle: the rewrite must
// return exactly this set on the same data. (NOW() works because the test
// registers it as a SQLite function below.)
function old_image_filter(string $img, string $flag): string {
    $owner = "(EXISTS (SELECT 1 FROM snap_post_images vpi WHERE vpi.image_id = {$img}.id AND vpi.post_id = vp.id)
               OR {$img}.post_id = vp.id)";
    return " AND NOT (
        EXISTS (SELECT 1 FROM snap_posts vp WHERE vp.status='published' AND vp.created_at<=NOW() AND {$owner})
        AND NOT EXISTS (SELECT 1 FROM snap_posts vp WHERE vp.status='published' AND vp.created_at<=NOW() AND vp.{$flag}=0 AND {$owner})
    )";
}

if (!extension_loaded('pdo_sqlite')) {
    echo "  --    NOT CHECKED: pdo_sqlite is not loaded on this host, so the predicate\n";
    echo "        cannot be run. Stated, not skipped quietly.\n";
    echo "\n{$pass} checks, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// imageFilter() emits NOW(); give SQLite the same function so the real fragment runs verbatim.
@$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec('CREATE TABLE snap_images (id INTEGER PRIMARY KEY, img_status TEXT, img_date TEXT, post_id INTEGER)');
$pdo->exec('CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, status TEXT, created_at TEXT, exclude_from_archive INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE snap_post_images (id INTEGER PRIMARY KEY, image_id INTEGER, post_id INTEGER)');

// Posts: 1 excl(pub), 2 visible(pub), 3 draft, 4 published-but-future.
$pdo->exec("INSERT INTO snap_posts (id,status,created_at,exclude_from_archive) VALUES
    (1,'published','2020-01-01',1),(2,'published','2020-01-01',0),
    (3,'draft','2020-01-01',1),(4,'published','2999-01-01',1)");
// Images: see the comment on each; every published-image row below is dated past.
$pdo->exec("INSERT INTO snap_images (id,img_status,img_date,post_id) VALUES
    (1,'published','2021-01-01',NULL),  -- orphan            -> visible
    (2,'published','2021-01-01',NULL),  -- pivot excl only   -> HIDDEN
    (3,'published','2021-01-01',NULL),  -- pivot vis only    -> visible
    (4,'published','2021-01-01',NULL),  -- pivot excl+vis    -> visible
    (5,'published','2021-01-01',NULL),  -- pivot draft only  -> visible (no published owner)
    (6,'published','2021-01-01',1),     -- legacy excl       -> HIDDEN
    (7,'published','2021-01-01',2),     -- legacy vis        -> visible
    (8,'published','2021-01-01',NULL),  -- pivot future excl -> visible (owner not visible yet)
    (9,'published','2021-01-01',2),     -- legacy vis + pivot excl -> visible
    (10,'draft','2021-01-01',NULL)      -- not published     -> excluded by img_status");
$pdo->exec("INSERT INTO snap_post_images (image_id,post_id) VALUES
    (2,1),(3,2),(4,1),(4,2),(5,3),(8,4),(9,1)");

$repo = new SnapPublicRepository($pdo);
$newFrag = $repo->imageFilter('exclude_from_archive', 'i');
cx($newFrag !== '', 'imageFilter emits a predicate when the flag column is present');

$visible = static function (PDO $pdo, string $frag): array {
    $sql = "SELECT id FROM snap_images i WHERE i.img_status='published' AND i.img_date<=NOW() {$frag} ORDER BY i.id";
    return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
};

$expected = [1, 3, 4, 5, 7, 8, 9];
$new = $visible($pdo, $newFrag);
$old = $visible($pdo, old_image_filter('i', 'exclude_from_archive'));

cx($new === $expected, 'the rewritten predicate returns exactly the hand-computed visible set (got ' . implode(',', $new) . ')');
cx($new === $old, 'the rewritten predicate returns the SAME set as the original nested form (identical results)');

echo "\nTHE REWRITE IS FLAT, NOT NESTED (the cubic form is gone)\n";
$src = (string)file_get_contents($root . '/core/public-repository.php');
// The method body between imageFilter( and the next function.
$body = substr($src, (int)strpos($src, 'function imageFilter'));
$body = substr($body, 0, (int)strpos($body, 'private function qualify'));
cx(str_contains($body, 'JOIN snap_posts vp ON vp.id ='),
   'the pivot route is a JOIN to snap_posts by primary key (index seek), not a nested EXISTS');
cx(!preg_match('/FROM\s+snap_posts\s+vp[\s\S]{0,200}EXISTS\s*\(\s*SELECT 1 FROM snap_post_images/', $body),
   'no EXISTS over snap_posts WRAPS an EXISTS over the pivot (the images x posts scan is removed)');

echo "\nABSENT FLAG COLUMN -> NO PREDICATE (schema tolerance preserved)\n";
$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
@$bare->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$bare->exec('CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, status TEXT, created_at TEXT)'); // no flag column
$bareRepo = new SnapPublicRepository($bare);
cx($bareRepo->imageFilter('exclude_from_archive', 'i') === '',
   'a database missing the flag column filters nothing instead of 500ing the page');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
