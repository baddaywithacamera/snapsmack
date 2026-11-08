<?php
// SNAPSMACK_EOF_HEADER: keep canonical marker last.
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/public-repository.php';
try {
    $pdo = new PDO(getenv('SS_BENCH_DSN') ?: 'mysql:host=127.0.0.1;port=3319', getenv('SS_BENCH_USER') ?: 'root', getenv('SS_BENCH_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $error) {
    echo "NOT CHECKED: public query-cost benchmark requires a scratch MySQL/MariaDB connection.\n";
    exit(getenv('SS_BENCH_REQUIRED') === '1' ? 1 : 0);
}
$db = 'bench_public_cost_' . getmypid() . '_' . bin2hex(random_bytes(3));
$pdo->exec("CREATE DATABASE `$db`");
$pass = 0;
function qc(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    global $pass; $pass++; echo "PASS $message\n";
}
try {
    $pdo->exec("USE `$db`");
    $pdo->exec("CREATE TABLE snap_images (id INT PRIMARY KEY, img_status VARCHAR(20), img_date DATETIME, sort_order INT DEFAULT 0, img_title VARCHAR(30), img_slug VARCHAR(30), img_alt VARCHAR(30), img_file VARCHAR(30), img_thumb_aspect VARCHAR(30), img_width INT, img_height INT, KEY idx_date(img_date))");
    $pdo->exec("CREATE TABLE snap_image_cat_map (image_id INT, cat_id INT, PRIMARY KEY(image_id,cat_id), KEY idx_icm_category_image(cat_id,image_id))");
    $pdo->beginTransaction();
    $insert = $pdo->prepare("INSERT INTO snap_images(id,img_status,img_date) VALUES (?,'published',?)");
    $map = $pdo->prepare("INSERT INTO snap_image_cat_map VALUES (?,?)");
    for ($i=1;$i<=40000;$i++) {
        $insert->execute([$i, date('Y-m-d H:i:s', strtotime('2020-01-01') + $i*1800)]);
        $map->execute([$i, $i%100]);
    }
    $pdo->commit();
    $archive = file_get_contents(dirname(__DIR__).'/archive.php');
    preg_match_all('/\$where_clauses\[\] = "(i\.img_date >= \? AND i\.img_date < DATE_ADD\(\?, INTERVAL 1 DAY\))"/', $archive, $matches);
    qc(count($matches[1])===2, 'both actual archive calendar clauses use half-open date ranges');
    $clause = $matches[1][0];
    foreach ([['2020-02-01','2020-02-01'],['2020-02-01','2020-02-29']] as $dates) {
        $old=$pdo->prepare("SELECT id FROM snap_images i WHERE DATE(i.img_date)>=? AND DATE(i.img_date)<=? ORDER BY id");
        $new=$pdo->prepare("SELECT id FROM snap_images i WHERE $clause ORDER BY id");
        $old->execute($dates);$new->execute($dates);
        qc($old->fetchAll(PDO::FETCH_COLUMN)===$new->fetchAll(PDO::FETCH_COLUMN), 'calendar results identical, including leap-day/end-of-day bounds');
    }
    $ex=$pdo->prepare("EXPLAIN SELECT id FROM snap_images i WHERE $clause");$ex->execute(['2020-02-01','2020-02-01']);$plan=$ex->fetch(PDO::FETCH_ASSOC);
    qc($plan['type']==='range' && $plan['key']==='idx_date', 'calendar uses date index range');
    $plan=$pdo->query('EXPLAIN SELECT image_id FROM snap_image_cat_map WHERE cat_id=37')->fetch(PDO::FETCH_ASSOC);
    qc($plan['type']==='ref' && $plan['key']==='idx_icm_category_image', 'category reverse lookup seeks covering index');
    $repository=new SnapPublicRepository($pdo);
    $first=$repository->justifiedPhotographFeed();
    qc(count($first)===200, 'default justified metadata read bounded at200');
    qc(count($repository->justifiedPhotographFeed(999999))===400, 'oversized justified request bounded at400');
    $next=$repository->justifiedPhotographFeed(200,200);
    qc(count(array_intersect(array_column($first,'id'),array_column($next,'id')))===0, 'metadata batches advance without duplicates');
    qc(!str_contains($archive, 'ALTER TABLE snap_albums ADD COLUMN'), 'archive GET no longer executes album schema DDL');
    require_once dirname(__DIR__).'/core/random-photographs.php';
    $start=microtime(true);
    $old=$pdo->query("SELECT id FROM snap_images WHERE img_status='published' AND img_date<=NOW() ORDER BY RAND() LIMIT 400")->fetchAll();
    $oldMs=(microtime(true)-$start)*1000;
    $start=microtime(true);
    $random=snap_random_public_images($pdo,400,['id']);
    $newMs=(microtime(true)-$start)*1000;
    qc(count($random)===400 && count(array_unique(array_column($random,'id')))===400, 'random display pool is bounded and unique');
    $plan=$pdo->query("EXPLAIN SELECT id FROM snap_images WHERE id>=20000 AND img_status='published' AND img_date<=NOW() ORDER BY id LIMIT 50")->fetch(PDO::FETCH_ASSOC);
    qc($plan['key']==='PRIMARY' && !str_contains($plan['Extra'],'filesort'), 'random floor walk uses primary index without random sort');
    echo sprintf("TIMING 40000rows RAND %.2fms, range sampler %.2fms\n",$oldMs,$newMs);
    // Evaluate FULLTEXT without changing product search semantics or schema.
    $pdo->exec("UPDATE snap_images SET img_title=CASE WHEN MOD(id,2000)=0 THEN 'Church doorway' ELSE CONCAT('Doorway ',id) END");
    $pdo->exec("UPDATE snap_images SET img_title='unchurchlike' WHERE id=1");
    $pdo->exec("UPDATE snap_images SET img_title='churchyard' WHERE id=2");
    $pdo->exec('ALTER TABLE snap_images ADD FULLTEXT INDEX bench_title_fulltext(img_title)');
    $start=microtime(true);
    $likeIds=$pdo->query("SELECT id FROM snap_images WHERE img_title LIKE '%church%'")->fetchAll(PDO::FETCH_COLUMN);
    $likeMs=(microtime(true)-$start)*1000;
    $start=microtime(true);
    $fullIds=$pdo->query("SELECT id FROM snap_images WHERE MATCH(img_title) AGAINST('church*' IN BOOLEAN MODE)")->fetchAll(PDO::FETCH_COLUMN);
    $fullMs=(microtime(true)-$start)*1000;
    qc(in_array(1,array_map('intval',$likeIds),true) && !in_array(1,array_map('intval',$fullIds),true), 'FULLTEXT prefix differs from current substring search: migration needs explicit semantics decision');
    echo sprintf("SEARCH CANDIDATE LIKE %.2fms/%dresults, FULLTEXT %.2fms/%dresults; NOT a drop-in replacement\n",$likeMs,count($likeIds),$fullMs,count($fullIds));
    $pdo->exec('DELETE FROM snap_images WHERE id NOT IN (2,20000,39999)');
    $pdo->exec("INSERT INTO snap_images(id,img_status,img_date) VALUES(90000,'draft','2020-01-01'),(99999,'published','2099-01-01')");
    $ids=array_column(snap_random_public_images($pdo,400,['id']),'id');sort($ids);
    qc(array_map('intval',$ids)===[2,20000,39999], 'sparse small pool returns every eligible row, excludes drafts and future dates');
    $pdo->exec('DELETE FROM snap_images');
    qc(snap_random_public_images($pdo,1,['id'])===[], 'empty random pool returns no photograph');
    echo "$pass checks passed on40000 throwaway rows. No production database used.\n";
} finally { $pdo->exec("DROP DATABASE `$db`"); }
// ===== SNAPSMACK EOF =====
