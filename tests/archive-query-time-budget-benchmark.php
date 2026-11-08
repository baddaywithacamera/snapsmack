<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
//     // ===== SNAPSMACK EOF =====
// Last non-empty line of this file MUST match the line above.

/**
 * STANDING GUARD: a public archive query must not scan the posts table per image.
 * (SS-986D, after the CT110 outage.)
 *
 * The archive-visibility COUNT ran 280-363 seconds on the ~10k-row fleet DB
 * because imageFilter() nested a correlated EXISTS over snap_posts around the
 * pivot -- images x posts. The fix made it index-seekable. This benchmark seeds
 * a prod-sized table and FAILS if the archive COUNT (built with the REAL
 * imageFilter() output) exceeds a wall-clock budget, so a future change that
 * reintroduces a per-row table scan is caught before it reaches a site.
 *
 * It needs a real MySQL/MariaDB (SQLite's planner is not MySQL's, and the whole
 * point is the MySQL query plan). Give it one via env:
 *   SS_BENCH_DSN, SS_BENCH_USER, SS_BENCH_PASS   (e.g. mysql:host=127.0.0.1;port=3319)
 * Rows default to 4000 (enough that the old nested plan would blow the budget by
 * orders of magnitude); SS_BENCH_ROWS overrides it (set 10358 to match prod).
 * With no DSN it prints NOT CHECKED and passes -- stated, not skipped quietly,
 * the same contract as the pdo_sqlite guards.
 */

$root = dirname(__DIR__);
require_once $root . '/core/public-repository.php';

$pass = 0; $fail = 0;
function bx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

$dsn = getenv('SS_BENCH_DSN');
if ($dsn === false || $dsn === '') {
    echo "  --    NOT CHECKED: no SS_BENCH_DSN set, so the prod-sized timing guard did not\n";
    echo "        run. Set SS_BENCH_DSN/USER/PASS (a MySQL/MariaDB) to exercise it.\n";
    echo "\n{$pass} checks, {$fail} failed\n";
    exit(0);
}

$rows   = (int)(getenv('SS_BENCH_ROWS') ?: 4000);
$budgetMs = (int)(getenv('SS_BENCH_BUDGET_MS') ?: 2000);
$db = 'ss_bench_' . substr(md5((string)getmypid() . microtime()), 0, 8);

try {
    $pdo = new PDO($dsn, getenv('SS_BENCH_USER') ?: 'root', getenv('SS_BENCH_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    echo "  --    NOT CHECKED: SS_BENCH_DSN is set but unreachable (" . $e->getMessage() . ").\n";
    echo "\n{$pass} checks, {$fail} failed\n";
    exit(0);
}

try {
    $pdo->exec("CREATE DATABASE `{$db}`");
    $pdo->exec("USE `{$db}`");
    // The columns the visibility predicate and the archive count actually touch,
    // with the production indexes (snap_post_images uq_image, snap_posts PK +
    // the signature index this release adds).
    $pdo->exec("CREATE TABLE snap_images (id INT PRIMARY KEY, img_status VARCHAR(20), img_date DATETIME, post_id INT NULL,
                 KEY idx_images_status_date (img_status, img_date), KEY idx_post_id (post_id))");
    $pdo->exec("CREATE TABLE snap_posts (id INT PRIMARY KEY, status VARCHAR(20), created_at DATETIME,
                 exclude_from_archive TINYINT DEFAULT 0, signature_image_id INT NULL,
                 KEY idx_status (status), KEY idx_posts_signature_image (signature_image_id))");
    $pdo->exec("CREATE TABLE snap_post_images (id INT PRIMARY KEY AUTO_INCREMENT, image_id INT, post_id INT,
                 UNIQUE KEY uq_image (image_id, post_id), KEY idx_post_id (post_id))");

    // Prod-shaped seed: every image published and dated in the past; each owned
    // by one post via the pivot; ~1 in 10 posts excludes from the archive. This
    // is the shape that made the old plan quadratic.
    $pdo->beginTransaction();
    $img  = $pdo->prepare("INSERT INTO snap_images (id,img_status,img_date,post_id) VALUES (?,'published','2021-01-01',NULL)");
    $post = $pdo->prepare("INSERT INTO snap_posts (id,status,created_at,exclude_from_archive) VALUES (?,'published','2021-01-01',?)");
    $piv  = $pdo->prepare("INSERT INTO snap_post_images (image_id,post_id) VALUES (?,?)");
    for ($i = 1; $i <= $rows; $i++) {
        $img->execute([$i]);
        $post->execute([$i, $i % 10 === 0 ? 1 : 0]);
        $piv->execute([$i, $i]);
    }
    $pdo->commit();

    $repo = new SnapPublicRepository($pdo);
    $frag = $repo->imageFilter('exclude_from_archive', 'i');
    bx($frag !== '', 'imageFilter emits its predicate against the benchmark schema');

    $sql = "SELECT COUNT(i.id) FROM snap_images i
             WHERE i.img_status='published' AND i.img_date<=NOW() {$frag}";
    // Warm, then measure.
    $pdo->query($sql)->fetchColumn();
    $t0 = microtime(true);
    $count = (int)$pdo->query($sql)->fetchColumn();
    $ms = (microtime(true) - $t0) * 1000.0;

    // 1 in 10 images is owned solely by an excluding post -> hidden.
    $expectedVisible = $rows - intdiv($rows, 10);
    bx($count === $expectedVisible,
       "the archive count is correct on {$rows} rows (got {$count}, expected {$expectedVisible})");
    bx($ms <= $budgetMs,
       sprintf('the archive COUNT runs in %.0f ms on %d images, under the %d ms budget', $ms, $rows, $budgetMs));
    echo sprintf("        [timing] %d rows -> %.0f ms (budget %d ms)\n", $rows, $ms, $budgetMs);
} finally {
    try { $pdo->exec("DROP DATABASE IF EXISTS `{$db}`"); } catch (Throwable $e) {}
}

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
