<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * THE HOT-PATH SQL FIXES ARE DECLARED AND THE SCANS ARE GONE (989D, Muse HIGH 1-3).
 *
 * The measured proof is tests/stats-hotpath-index-benchmark.php (EXPLAIN on a
 * prod-sized DB). This guards the same fixes without a database, so a later edit
 * that drops an index or reintroduces DATE(hit_at)=CURDATE() is caught in CI.
 */
$root = dirname(__DIR__);
$pass=0;$fail=0;
function hx(bool $ok,string $w):void{global $pass,$fail; if($ok){$pass++;echo "  ok    $w\n";}else{$fail++;echo "  FAIL  $w\n";}}

$canon=(string)@file_get_contents($root.'/database/schema/snapsmack_canonical.sql');
$sync=(string)@file_get_contents($root.'/core/schema-sync.php');
echo "INDEXES DECLARED (canonical + retrofit for existing installs)\n";
foreach (['idx_images_post_id'=>'`post_id`','idx_images_checksum'=>'`img_checksum`'] as $idx=>$col) {
    hx(str_contains($canon,"KEY `{$idx}` ({$col})"), "canonical schema declares {$idx} on {$col}");
    hx(str_contains($sync,"ADD INDEX `{$idx}`"), "schema-sync retrofits {$idx} for existing installs");
}

echo "\nNO DATE(hit_at)=CURDATE() FULL SCAN REMAINS ON THE TODAY-COUNT PATHS\n";
foreach (['core/multisite-api.php','smack-multisite-stats.php','smack-stats.php'] as $rel) {
    $src=(string)@file_get_contents($root.'/'.$rel);
    hx(!preg_match('/DATE\(hit_at\)\s*=\s*CURDATE\(\)/',$src), "{$rel}: no DATE(hit_at)=CURDATE() (index-defeating) remains");
}
hx((bool)preg_match('/hit_at >= CURDATE\(\) AND hit_at < CURDATE\(\) \+ INTERVAL 1 DAY/', (string)@file_get_contents($root.'/core/multisite-api.php')),
   'the today-count uses an index-seekable hit_at range instead');

echo "\n{$pass} passed, {$fail} failed\n";
if($fail>0){echo "Hot-path SQL regression: FAIL ({$fail}).\n";exit(1);}
echo "Hot-path SQL regression: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED here: the EXPLAIN plan and timings -- that is the sibling\n";
echo "  benchmark stats-hotpath-index-benchmark.php, which needs a MySQL DSN.\n";
// ===== SNAPSMACK EOF =====
