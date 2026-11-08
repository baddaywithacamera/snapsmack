<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * HOT-PATH SQL FIX BENCHMARK (989D, Muse's SQL audit HIGH 1-3).
 *
 * Three HIGH findings scanned snap_images / snap_stats per row on hot paths:
 *   1. RSS orphan stream: NOT EXISTS on unindexed img_checksum (machine-polled).
 *   2. cover/stats LEFT JOIN snap_images ON post_id = p.id, post_id unindexed.
 *   3. DATE(hit_at)=CURDATE() today-counts, DATE() defeating the hit_at index.
 *
 * Fixes: index snap_images(post_id) + (img_checksum); rewrite the today-count to
 * a hit_at range. This builds a prod-sized throwaway database and proves, by
 * EXPLAIN and wall-clock, that each query now seeks an index instead of scanning.
 *
 * Needs MySQL/MariaDB: SS_BENCH_DSN (e.g. mysql:host=127.0.0.1;port=3319) or it
 * SKIPS. No production data; a throwaway schema it drops at the end.
 */

$dsn = getenv('SS_BENCH_DSN') ?: 'mysql:host=127.0.0.1;port=3319';
$user = getenv('SS_BENCH_USER') ?: 'root';
$pass = getenv('SS_BENCH_PASS') ?: '';
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
} catch (Throwable $e) {
    echo "SKIP: no benchmark database ({$dsn}): " . $e->getMessage() . "\n";
    exit(0);
}

$db = 'bench_sql_' . getmypid();
$pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
$pdo->exec("CREATE DATABASE `{$db}`");
$pdo->exec("USE `{$db}`");

$pass_n = 0; $fail_n = 0;
function bk(bool $ok, string $what): void {
    global $pass_n, $fail_n;
    if ($ok) { $pass_n++; echo "  ok    {$what}\n"; }
    else     { $fail_n++; echo "  FAIL  {$what}\n"; }
}

// Tables with the fix indexes (as the canonical schema now declares them).
$pdo->exec("CREATE TABLE snap_images (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  img_status VARCHAR(20) NOT NULL,
  img_checksum VARCHAR(64) DEFAULT NULL,
  post_id INT DEFAULT NULL,
  KEY idx_images_status_date (img_status, id),
  KEY idx_images_post_id (post_id),
  KEY idx_images_checksum (img_checksum)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE snap_posts (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, post_status VARCHAR(20) NOT NULL)");
$pdo->exec("CREATE TABLE snap_stats (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hit_at DATETIME NOT NULL,
  is_bot TINYINT NOT NULL DEFAULT 0,
  ip_hash VARCHAR(64) DEFAULT NULL,
  KEY idx_hit_at (hit_at)
) ENGINE=InnoDB");

echo "SEEDING prod-sized rows...\n";
$pdo->beginTransaction();
$img = $pdo->prepare("INSERT INTO snap_images (img_status, img_checksum, post_id) VALUES ('published', ?, ?)");
for ($i = 1; $i <= 12000; $i++) { $img->execute([md5((string)($i % 11000)), ($i % 4 === 0) ? null : $i]); }
$post = $pdo->prepare("INSERT INTO snap_posts (post_status) VALUES ('published')");
for ($i = 1; $i <= 9000; $i++) { $post->execute(); }
$st = $pdo->prepare("INSERT INTO snap_stats (hit_at, is_bot, ip_hash) VALUES (?, ?, ?)");
for ($i = 1; $i <= 40000; $i++) {
    $d = date('Y-m-d H:i:s', time() - random_int(0, 86400 * 120));
    $st->execute([$d, random_int(0, 1), md5((string)($i % 5000))]);
}
$pdo->commit();
$pdo->query("ANALYZE TABLE snap_images, snap_posts, snap_stats")->fetchAll();

function explain_type(PDO $pdo, string $sql): array {
    $rows = $pdo->query("EXPLAIN " . $sql)->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}
function timed(PDO $pdo, string $sql): float {
    $t = microtime(true); $pdo->query($sql)->fetchAll(); return (microtime(true) - $t) * 1000;
}

echo "\nHIGH 2 — cover LEFT JOIN snap_images ON post_id (now idx_images_post_id)\n";
$join = "SELECT p.id FROM snap_posts p LEFT JOIN snap_images i ON i.post_id = p.id WHERE p.post_status='published' LIMIT 50";
$ex = explain_type($pdo, $join);
$usesIdx = false; foreach ($ex as $r) { if (($r['table'] ?? '')==='i' && !empty($r['key'])) $usesIdx = true; }
bk($usesIdx, 'the join probes snap_images by idx_images_post_id, not a full scan (key=' . implode('/', array_map(fn($r)=>($r['table']??'').':'.($r['key']??'NULL').'/'.($r['type']??''), $ex)) . ')');
bk(timed($pdo, $join) < 150, 'join under 150ms');

echo "\nHIGH 1 — RSS orphan NOT EXISTS on img_checksum (now idx_images_checksum)\n";
$rss = "SELECT i.id FROM snap_images i WHERE i.img_status='published' AND NOT EXISTS (SELECT 1 FROM snap_images d WHERE d.img_status='published' AND d.img_checksum=i.img_checksum AND d.id<>i.id) LIMIT 20";
$ex = explain_type($pdo, $rss);
$subUsesChecksum = false; foreach ($ex as $r) { if (strpos((string)($r['key'] ?? ''), 'checksum')!==false) $subUsesChecksum = true; }
bk($subUsesChecksum, 'the NOT EXISTS probe seeks idx_images_checksum (keys=' . implode('/', array_map(fn($r)=>($r['key']??'NULL'), $ex)) . ')');
bk(timed($pdo, $rss) < 200, 'rss orphan query under 200ms');

echo "\nHIGH 3 — today-count: hit_at range (was DATE(hit_at)=CURDATE())\n";
$today = "SELECT COUNT(*) FROM snap_stats WHERE hit_at >= CURDATE() AND hit_at < CURDATE() + INTERVAL 1 DAY AND is_bot=0";
$ex = explain_type($pdo, $today);
$usesRange = false; foreach ($ex as $r) { if (($r['type'] ?? '')==='range' && strpos((string)($r['key']??''),'hit_at')!==false) $usesRange = true; }
bk($usesRange, 'the today-count is a range seek on idx_hit_at (type/key=' . implode('/', array_map(fn($r)=>($r['type']??'').':'.($r['key']??'NULL'), $ex)) . ')');
// contrast: the OLD DATE() form scans
$old = "SELECT COUNT(*) FROM snap_stats WHERE DATE(hit_at)=CURDATE() AND is_bot=0";
$exo = explain_type($pdo, $old);
$oldScans = false; foreach ($exo as $r) { if (($r['type'] ?? '')==='ALL') $oldScans = true; }
bk($oldScans, 'and the OLD DATE(hit_at)=CURDATE() form is a full scan (type=' . implode('/', array_map(fn($r)=>($r['type']??''), $exo)) . '), which is what the rewrite removes');
bk(timed($pdo, $today) < 120, 'today-count under 120ms');

$pdo->exec("DROP DATABASE `{$db}`");

echo "\n{$pass_n} passed, {$fail_n} failed\n";
if ($fail_n > 0) { echo "Hot-path SQL benchmark: FAIL ({$fail_n}).\n"; exit(1); }
echo "Hot-path SQL benchmark: PASS ({$pass_n} assertions) on a prod-sized throwaway DB.\n";

// ===== SNAPSMACK EOF =====
