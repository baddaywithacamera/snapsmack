<?php
declare(strict_types=1);

/**
 * SS-1185. A post marked "keep out of the feed" must be absent from the REAL
 * RSS query, not merely have a flag stored.
 *
 * Sean, 2026-11-14: "Portfolio currently appears in the blog feed." The
 * existing surface test covers landing and search; the FEED -- the surface he
 * actually reported -- was not covered by anything.
 *
 * THIS RUNS rss.php's OWN SQL. The statement is lifted out of rss.php at test
 * time rather than copied here, because a copied query is a second definition
 * and the two drift. If somebody edits the feed query, this test runs the
 * edited one.
 *
 * Run: php -d extension=pdo_sqlite tests/rss-feed-honours-the-portfolio-exclusion.php
 */

require_once __DIR__ . '/../core/public-repository.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP: no pdo_sqlite in this PHP\n";
    exit(0);
}

$fails = [];
$sx = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) $fails[] = $what;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE snap_posts (
    id INTEGER PRIMARY KEY, title TEXT, slug TEXT, description TEXT,
    content TEXT, post_type TEXT, status TEXT, created_at TEXT,
    featured_image_id INTEGER, signature_image_id INTEGER,
    exclude_from_archive INTEGER NOT NULL DEFAULT 0,
    exclude_from_search  INTEGER NOT NULL DEFAULT 0,
    exclude_from_feed    INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE snap_images (
    id INTEGER PRIMARY KEY, img_slug TEXT, img_title TEXT, img_file TEXT,
    img_description TEXT, img_status TEXT, img_date TEXT, post_id INTEGER,
    img_checksum TEXT)");
$pdo->exec("CREATE TABLE snap_post_images (post_id INTEGER, image_id INTEGER, is_cover INTEGER)");
$pdo->exec("CREATE TABLE snap_bucket_items (image_id INTEGER)");

// TWO longform posts, identical but for the flag. A feed that returns nothing
// would make "the excluded one is gone" pass for the wrong reason, so the
// ordinary one must be PRESENT in the same result.
$pdo->exec("INSERT INTO snap_images (id,img_slug,img_title,img_file,img_status,img_date,post_id)
            VALUES (10,'a','Cover A','a.jpg','published','2020-01-01',1),
                   (11,'b','Cover B','b.jpg','published','2020-01-01',2)");
$pdo->exec("INSERT INTO snap_posts (id,title,slug,description,post_type,status,created_at,featured_image_id,exclude_from_feed)
            VALUES (1,'The portfolio','portfolio','p','longform','published','2020-01-01',10,1),
                   (2,'An ordinary post','ordinary','o','longform','published','2020-01-01',11,0)");

$repo = new SnapPublicRepository($pdo);
$sx('the fixture carries the visibility flags',
    in_array('exclude_from_feed', $repo->visibilityFlagsPresent(), true));

// --- lift the REAL query out of rss.php -------------------------------------
$rss = (string)file_get_contents(__DIR__ . '/../rss.php');
$sx('rss.php asks the shared predicate for the feed exclusion',
    str_contains($rss, "postFilter('exclude_from_feed', 'p')"));

if (!preg_match('/\$rss_stream_sql\s*=\s*"(.*?)\n";/s', $rss, $m)) {
    fwrite(STDERR, "FAIL: could not find \$rss_stream_sql in rss.php\n");
    exit(1);
}
$rss_feed_filter = $repo->postFilter('exclude_from_feed', 'p');
$sx('the predicate is not empty on a schema that has the column',
    trim($rss_feed_filter) !== '');

// Interpolate exactly as rss.php does, then keep only the two post branches:
// the third UNION arm is the orphan-photograph feed and uses sqlite-hostile
// joins that are not what is under test here.
$sql = str_replace('{$rss_feed_filter}', $rss_feed_filter, $m[1]);
$arms = preg_split('/\n\s*UNION ALL\s*\n/', $sql);
$sx('the feed query still has its three arms', count($arms) === 3);
$post_sql = $arms[0] . "\n UNION ALL \n" . $arms[1];

$now = '2030-01-01';
$stmt = $pdo->prepare($post_sql);
$stmt->execute([$now, $now, $now, $now]);
$slugs = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'item_slug');

$sx('THE FEED RETURNED ROWS AT ALL (or nothing below proves anything)', $slugs !== []);
$sx('THE DESIGNATED POST IS ABSENT FROM THE REAL FEED QUERY',
    !in_array('portfolio', $slugs, true));
$sx('and the ordinary post is still in the feed',
    in_array('ordinary', $slugs, true));

// --- and it is the FLAG doing it, not the fixture ---------------------------
$pdo->exec("UPDATE snap_posts SET exclude_from_feed = 0 WHERE id = 1");
$stmt = $pdo->prepare($post_sql);
$stmt->execute([$now, $now, $now, $now]);
$cleared = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'item_slug');
$sx('clearing the flag puts it back, so the flag is what removed it',
    in_array('portfolio', $cleared, true));

echo (count($fails) ? "\nFAIL" : "\nPASS")
   . ": the RSS feed honours exclude_from_feed (SS-1185)\n";
exit(count($fails) ? 1 : 0);
// ===== SNAPSMACK EOF =====
