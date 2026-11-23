<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 14 — the cache and the reader both have a ceiling.
 *
 * WHAT WAS WRONG, measured at caebe17b. The remote-image cache had a 7-day TTL
 * and no total cap, and the TTL was enforced by `random_int(1, 100) === 1` on
 * each store -- so on a quiet site it might not run for days, and on a busy one
 * it ran far more often than anything needed. The inbound timeline had no
 * retention at all: every relayed post and every post from a followed account
 * kept for ever on a photographer's shared host.
 *
 * THE NUMBERS ARE SEAN'S, approved 2026-11-20. 512 MB total and owner-settable;
 * 4 MB per file as a CACHE limit, not a fetch limit; the 7-day TTL kept; 90 days
 * or 2,000 rows per feed, whichever is more generous; and never a row the owner
 * was notified about.
 *
 * A DICE ROLL CANNOT BE TESTED, which is half the reason it had to go. Every
 * assertion below is on a deterministic outcome: the same files and the same
 * limit always evict the same things.
 *
 * NOT COVERED: the proxy endpoint itself (it needs an authenticated request and
 * a remote server), and the admin page's rendering. The sweep, the per-file
 * refusal, the limit clamp and the retention rules are all driven directly.
 */

$root = dirname(__DIR__);
$failures = [];

function mc_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

require_once $root . '/core/media-retention.php';

/** A scratch cache directory, removed on the way out however this run ends. */
$mc_tmp = sys_get_temp_dir() . '/ss_mediacache_' . bin2hex(random_bytes(5));
mkdir($mc_tmp, 0770, true);
register_shutdown_function(static function () use ($mc_tmp): void {
    foreach (glob($mc_tmp . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($mc_tmp);
});

/** One cached pair of a given size and age. */
function mc_put(string $dir, string $key, int $bytes, int $ageSeconds = 0): void {
    file_put_contents($dir . '/' . $key . '.bin', str_repeat('x', $bytes));
    file_put_contents($dir . '/' . $key . '.json', json_encode(['type' => 'image/jpeg']));
    if ($ageSeconds > 0) {
        touch($dir . '/' . $key . '.bin', time() - $ageSeconds);
        touch($dir . '/' . $key . '.json', time() - $ageSeconds);
    }
}

function mc_exists(string $dir, string $key): bool {
    return is_file($dir . '/' . $key . '.bin');
}

function mc_clear(string $dir): void {
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
}

echo "SS-1481 #14 — the cache and the reader both have a ceiling\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE OWNER'S LIMIT, AND ITS GUARD RAILS\n";
// ─────────────────────────────────────────────────────────────────────────────
mc_check('the default is Sean\'s 512 MB',
         snap_media_cache_limit_bytes([]) === 512 * 1048576, $failures);
mc_check('an owner setting is honoured',
         snap_media_cache_limit_bytes(['fedi_media_cache_mb' => 1024]) === 1024 * 1048576,
         $failures);
mc_check('a silly small number is raised to 16 MB, not left to thrash',
         snap_media_cache_limit_bytes(['fedi_media_cache_mb' => 1]) === 16 * 1048576, $failures);
mc_check('a silly large number is capped at 32 GB',
         snap_media_cache_limit_bytes(['fedi_media_cache_mb' => 999999]) === 32768 * 1048576,
         $failures);
foreach (['0', '', 'banana', '-40'] as $junk) {
    mc_check('junk ' . var_export($junk, true) . ' gives the default, never no limit',
             snap_media_cache_limit_bytes(['fedi_media_cache_mb' => $junk]) === 512 * 1048576,
             $failures);
}
mc_check('the per-file CACHE limit is 4 MB',
         snap_media_cache_max_file_bytes() === 4194304, $failures);
mc_check('and it is well under the 15 MB the proxy will FETCH, which is a '
         . 'different limit',
         snap_media_cache_max_file_bytes() < 15728640, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE TTL IS ENFORCED EVERY TIME, NOT ONE RUN IN A HUNDRED\n";
// ─────────────────────────────────────────────────────────────────────────────
mc_clear($mc_tmp);
mc_put($mc_tmp, 'fresh', 1000, 0);
mc_put($mc_tmp, 'stale', 1000, 604800 + 60);   // just past seven days
mc_put($mc_tmp, 'ancient', 1000, 30 * 86400);

$r = snap_media_cache_sweep($mc_tmp, 512 * 1048576);
mc_check('both stale files went', $r['expired'] === 2, $failures);
mc_check('  and the fresh one stayed', mc_exists($mc_tmp, 'fresh'), $failures);
mc_check('  with nothing evicted for size', $r['evicted'] === 0, $failures);

// The point of removing the lottery: run it again and the answer is the same.
$again = snap_media_cache_sweep($mc_tmp, 512 * 1048576);
mc_check('a second sweep finds nothing left to do, every time',
         $again['expired'] === 0 && $again['evicted'] === 0, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — OVER THE CEILING, THE OLDEST GO FIRST\n";
// ─────────────────────────────────────────────────────────────────────────────
// Five 1 MB entries and a 3 MB ceiling: the two oldest go, and which two is
// not a matter of luck.
mc_clear($mc_tmp);
foreach ([['a', 500], ['b', 400], ['c', 300], ['d', 200], ['e', 100]] as [$k, $age]) {
    mc_put($mc_tmp, $k, 1048576, $age);
}
// The ceiling is three images' worth PLUS the few bytes of json beside each
// one, because the sweep counts the pair. Picking a flat 3 MB here evicted
// three files rather than two, on 120 bytes of metadata — a reminder that the
// unit under test is the pair, not the image.
$mc_pair = 1048576 + strlen((string)json_encode(['type' => 'image/jpeg']));
$r = snap_media_cache_sweep($mc_tmp, 3 * $mc_pair);
mc_check('two files were evicted', $r['evicted'] === 2, $failures);
mc_check('  the OLDEST two', !mc_exists($mc_tmp, 'a') && !mc_exists($mc_tmp, 'b'), $failures);
mc_check('  and the newest three survived',
         mc_exists($mc_tmp, 'c') && mc_exists($mc_tmp, 'd') && mc_exists($mc_tmp, 'e'),
         $failures);
mc_check('  leaving the cache under its ceiling', $r['bytes'] <= 3 * $mc_pair, $failures);
mc_check('  and the json half went with its image',
         !is_file($mc_tmp . '/a.json') && !is_file($mc_tmp . '/b.json'), $failures);

// A cache already under the ceiling is left completely alone.
$r = snap_media_cache_sweep($mc_tmp, 512 * 1048576);
mc_check('a cache inside its limit loses nothing',
         $r['evicted'] === 0 && mc_exists($mc_tmp, 'c'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — A BIG IMAGE IS SERVED, NOT KEPT\n";
// ─────────────────────────────────────────────────────────────────────────────
// The distinction that matters: 4 MB is a CACHE limit. The reader still sees
// the photograph; it just does not take a slice of the disk.
mc_clear($mc_tmp);
$small = str_repeat('s', 1000);
$big   = str_repeat('b', snap_media_cache_max_file_bytes() + 1);

mc_check('a normal image is cached',
         snap_media_cache_store($mc_tmp, 'small', $small, 'image/jpeg', 512 * 1048576) === true,
         $failures);
mc_check('  and is on disk', mc_exists($mc_tmp, 'small'), $failures);
mc_check('an image over 4 MB is NOT cached',
         snap_media_cache_store($mc_tmp, 'big', $big, 'image/jpeg', 512 * 1048576) === false,
         $failures);
mc_check('  and left nothing behind', !mc_exists($mc_tmp, 'big'), $failures);

// Storing keeps the cache bounded on the way in, so one store can never leave
// it over the line.
mc_clear($mc_tmp);
foreach (['p', 'q', 'r'] as $i => $k) { mc_put($mc_tmp, $k, 1048576, 900 - ($i * 100)); }
snap_media_cache_store($mc_tmp, 'new', str_repeat('n', 1048576), 'image/jpeg', 2 * 1048576);
$left = 0;
foreach (snap_media_cache_entries($mc_tmp) as $e) { $left += $e['size']; }
mc_check('a store that would overflow the cache sweeps it back under',
         $left <= 2 * 1048576, $failures);
mc_check('  and the file just written is the one that survived',
         mc_exists($mc_tmp, 'new'), $failures);

// An unwritable directory is "served, not kept" — never an error.
$gone = $mc_tmp . '/nope/deeper';
mc_check('an impossible cache directory refuses quietly rather than throwing',
         snap_media_cache_store('/\\/nonexistent-device-path', 'x', $small, 'image/jpeg', 1048576) === false,
         $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — THE PROXY USES ALL OF IT, AND STILL REFUSES VIDEO\n";
// ─────────────────────────────────────────────────────────────────────────────
$proxy = (string)file_get_contents($root . '/smack-media-proxy.php');
mc_check('the proxy stores through the bounded path',
         str_contains($proxy, 'snap_media_cache_store('), $failures);
mc_check('and asks the owner setting for the limit',
         str_contains($proxy, 'snap_media_cache_limit_bytes($proxy_settings)'), $failures);
mc_check('THE LOTTERY IS GONE',
         !str_contains($proxy, 'random_int(1, 100)'), $failures);
mc_check('the 15 MB fetch ceiling is untouched',
         str_contains($proxy, '15728640'), $failures);

// Sean's standing video ruling, 2026-11-20. The allowlist is five image types
// and nothing else; a video/* type here would make this server a video host
// for other people's files.
foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'] as $type) {
    mc_check("the allowlist still carries {$type}",
             str_contains($proxy, "'{$type}'"), $failures);
}
mc_check('and carries no video type at all',
         !preg_match("~'video/~", $proxy), $failures);
// THE COMMENT HAS TO MATCH THE RULE IN FORCE, and this check used to REQUIRE
// the superseded sentence — "never ... boosts or shares video" — which the
// 2026-11-21 revision made false while the test held it in place. Codex's
// FAIL 3. So the assertion is now on what the proxy forbids (bytes) and on the
// absence of the sentence the ruling retired.
mc_check('the comment says the exclusion is deliberate, not a to-do',
         stripos($proxy, 'not an oversight and it is') !== false, $failures);
mc_check('and names what is actually forbidden: caching, proxying, storing',
         stripos($proxy, 'never caches, proxies, re-encodes,') !== false, $failures);
mc_check('and NO LONGER claims boosting video is forbidden, which it is not',
         stripos($proxy, 'boosts or shares video') === false, $failures);
foreach (['core/media-retention.php', 'smack-media-proxy.php',
          'core/fediverse.php'] as $file) {
    mc_check("{$file} carries no copy of the superseded rule",
             stripos((string)file_get_contents($root . '/' . $file),
                     're-publishes, boosts or shares') === false, $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 6 — THE READER KEEPS 90 DAYS OR 2,000 PER FEED\n";
// ─────────────────────────────────────────────────────────────────────────────
function mc_pdo(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_ap_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 object_id TEXT UNIQUE, actor_url TEXT DEFAULT "", source TEXT DEFAULT "home",
                 published TEXT, fetched_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE snap_ap_timeline_membership (timeline_id INTEGER, feed TEXT,
                 first_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
                 PRIMARY KEY (timeline_id, feed))');
    $pdo->exec('CREATE TABLE snap_ap_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 ntype TEXT, actor_url TEXT, object_id TEXT)');
    // The boost record the finding-2 repair added on the other branch. The
    // merge brought the two together, and Codex's FAIL 2 was that retention
    // never pruned it -- a fixture without this table cannot see that.
    $pdo->exec('CREATE TABLE snap_ap_timeline_boosts (object_id TEXT, actor_url TEXT,
                 seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
                 UNIQUE(object_id, actor_url))');
    return $pdo;
}

/** One timeline row, $ageDays old, in $feed. */
function mc_row(PDO $pdo, string $objectId, int $ageDays, string $feed = 'home'): int {
    $when = gmdate('Y-m-d H:i:s', time() - ($ageDays * 86400));
    $pdo->prepare('INSERT INTO snap_ap_timeline (object_id, source, published, fetched_at)
                   VALUES (?,?,?,?)')->execute([$objectId, $feed, $when, $when]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO snap_ap_timeline_membership (timeline_id, feed) VALUES (?,?)')
        ->execute([$id, $feed]);
    return $id;
}

function mc_count(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline')->fetchColumn();
}

// A quiet feed: 40 posts spread over 80 days. Fewer than 2,000, all inside 90
// days, so retention must take nothing. This is the case a rows-only rule gets
// right by luck and a days-only rule gets right on purpose.
$pdo = mc_pdo();
for ($i = 0; $i < 40; $i++) { mc_row($pdo, 'https://a.invalid/n/' . $i, $i * 2); }
$r = snap_timeline_retention($pdo);
mc_check('a quiet feed inside 90 days loses nothing',
         $r['deleted'] === 0 && mc_count($pdo) === 40, $failures);

// THE DAY WINDOW ON ITS OWN. More rows than the per-feed count, but every one
// inside 90 days, so the window is the only thing keeping them. Without this
// case the quiet-feed check above passes whether the window exists or not,
// because 40 is already fewer than N — which is exactly what a mutation
// removing the window proved.
$pdo = mc_pdo();
for ($i = 0; $i < 10; $i++) { mc_row($pdo, 'https://a.invalid/recent/' . $i, $i * 3); }
$r = snap_timeline_retention($pdo, 90, 2);
mc_check('rows inside the day window survive even past the per-feed count',
         $r['deleted'] === 0 && mc_count($pdo) === 10, $failures);

// Old, and beyond any feed's newest N: these go.
$pdo = mc_pdo();
for ($i = 0; $i < 10; $i++) { mc_row($pdo, 'https://a.invalid/old/' . $i, 200 + $i); }
for ($i = 0; $i < 5; $i++)  { mc_row($pdo, 'https://a.invalid/new/' . $i, $i); }
$r = snap_timeline_retention($pdo, 90, 5);
mc_check('rows past 90 days and past the per-feed count are pruned',
         $r['deleted'] === 10, $failures);
mc_check('  and the recent ones stayed', mc_count($pdo) === 5, $failures);

// WHICHEVER IS MORE GENEROUS, the other way round: a busy feed where 2,000
// reaches further back than 90 days.
$pdo = mc_pdo();
for ($i = 0; $i < 12; $i++) { mc_row($pdo, 'https://a.invalid/busy/' . $i, 100 + $i); }
$r = snap_timeline_retention($pdo, 90, 12);
mc_check('a feed whose newest N reaches past the day window keeps all of it',
         $r['deleted'] === 0 && mc_count($pdo) === 12, $failures);
$r = snap_timeline_retention($pdo, 90, 4);
mc_check('  and with a smaller N, only the newest N survive',
         mc_count($pdo) === 4, $failures);

// Each feed counts separately: 'local' being full must not evict 'home'.
$pdo = mc_pdo();
for ($i = 0; $i < 6; $i++) { mc_row($pdo, 'https://a.invalid/h/' . $i, 200 + $i, 'home'); }
for ($i = 0; $i < 6; $i++) { mc_row($pdo, 'https://a.invalid/l/' . $i, 200 + $i, 'local'); }
snap_timeline_retention($pdo, 90, 3);
$homeLeft = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_timeline WHERE source='home'")->fetchColumn();
$localLeft = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_timeline WHERE source='local'")->fetchColumn();
mc_check('each feed keeps its own newest N', $homeLeft === 3 && $localLeft === 3, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 7 — A POST YOU WERE TOLD ABOUT IS NEVER PRUNED\n";
// ─────────────────────────────────────────────────────────────────────────────
// Sean's instruction, and the rule that makes this safe to run unattended: a
// notification pointing at a post that no longer exists is a broken reader.
$pdo = mc_pdo();
for ($i = 0; $i < 10; $i++) { mc_row($pdo, 'https://a.invalid/x/' . $i, 300 + $i); }
$pdo->prepare("INSERT INTO snap_ap_notifications (ntype, actor_url, object_id)
               VALUES ('boost', 'https://b.invalid/u', ?)")
    ->execute(['https://a.invalid/x/3']);
$pdo->prepare("INSERT INTO snap_ap_notifications (ntype, actor_url, object_id)
               VALUES ('reply', 'https://c.invalid/u', ?)")
    ->execute(['https://a.invalid/x/7']);
snap_timeline_retention($pdo, 90, 0);
$survivors = $pdo->query('SELECT object_id FROM snap_ap_timeline ORDER BY object_id')
                 ->fetchAll(PDO::FETCH_COLUMN);
mc_check('only the two notified posts survived a prune that wanted everything',
         $survivors === ['https://a.invalid/x/3', 'https://a.invalid/x/7'], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 7a — PRUNING A POST TAKES ITS BOOST RECORD WITH IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// CODEX'S FAIL 2. snap_ap_timeline_boosts is keyed on object_id, not on the
// timeline row's id, so deleting the row leaves the boost rows behind with
// nothing left to find them by. They then grow outside every ceiling in this
// file, and a post re-ingested later reads as still boosted by actors who
// withdrew months ago.
//
// The table arrived with the finding-2 repair on the other branch and the
// merge brought the two together without this. Adding a table and not teaching
// retention about it is the same omission as adding one and not declaring it
// in the schema.
$pdo = mc_pdo();
$ins = $pdo->prepare('INSERT INTO snap_ap_timeline_boosts (object_id, actor_url) VALUES (?,?)');
for ($i = 0; $i < 6; $i++) {
    mc_row($pdo, 'https://a.invalid/b/' . $i, 300 + $i);
    $ins->execute(['https://a.invalid/b/' . $i, 'https://booster.invalid/u/1']);
    $ins->execute(['https://a.invalid/b/' . $i, 'https://booster.invalid/u/2']);
}
mc_row($pdo, 'https://a.invalid/keep', 1);
$ins->execute(['https://a.invalid/keep', 'https://booster.invalid/u/3']);

mc_check('the fixture holds twelve boost records plus one for the recent post',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts')->fetchColumn() === 13,
         $failures);
$r = snap_timeline_retention($pdo, 90, 0);
mc_check('the six old posts were pruned', $r['deleted'] === 6, $failures);
mc_check('  and their boost records went with them',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts')->fetchColumn() === 1,
         $failures);
mc_check('  leaving only the surviving post\'s record',
         (string)$pdo->query('SELECT object_id FROM snap_ap_timeline_boosts')->fetchColumn()
             === 'https://a.invalid/keep', $failures);
mc_check('  and no boost record orphaned from a post',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts b
                            WHERE NOT EXISTS (SELECT 1 FROM snap_ap_timeline t
                                               WHERE t.object_id = b.object_id)')
                  ->fetchColumn() === 0, $failures);

// A site that has never federated may not have the table at all, and that must
// not stop the prune.
$noBoosts = mc_pdo();
for ($i = 0; $i < 4; $i++) { mc_row($noBoosts, 'https://a.invalid/nb/' . $i, 300 + $i); }
$noBoosts->exec('DROP TABLE snap_ap_timeline_boosts');
$r = snap_timeline_retention($noBoosts, 90, 0);
mc_check('a missing boost table does not stop the prune', $r['deleted'] === 4, $failures);

// A DELETE THAT FAILS IS NOT A TABLE THAT IS ABSENT. Codex's second review.
// The first version of this repair caught everything here, logged it, and
// carried on deleting the posts — so a permissions error, a lock or a deadlock
// left behind exactly the orphan boost records the repair exists to prevent,
// while the log line scrolled past. My regression only drove a successful
// delete and a missing table, which is why it passed.
//
// Driven with the table PRESENT and the DELETE refused, which is the case that
// was wrong. The posts must survive, so the next run can try again.
class McBoostDeleteFails extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (stripos($query, 'DELETE FROM snap_ap_timeline_boosts') !== false) {
            throw new PDOException('SQLSTATE[42000]: delete refused by the fixture');
        }
        return parent::prepare($query, $options);
    }
}

function mc_pdo_boost_delete_fails(): PDO {
    $pdo = new McBoostDeleteFails('sqlite::memory:', null, null,
                                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_ap_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 object_id TEXT UNIQUE, actor_url TEXT DEFAULT "", source TEXT DEFAULT "home",
                 published TEXT, fetched_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE snap_ap_timeline_membership (timeline_id INTEGER, feed TEXT,
                 first_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
                 PRIMARY KEY (timeline_id, feed))');
    $pdo->exec('CREATE TABLE snap_ap_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 ntype TEXT, actor_url TEXT, object_id TEXT)');
    $pdo->exec('CREATE TABLE snap_ap_timeline_boosts (object_id TEXT, actor_url TEXT,
                 seen_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(object_id, actor_url))');
    return $pdo;
}

$pdo = mc_pdo_boost_delete_fails();
for ($i = 0; $i < 5; $i++) {
    mc_row($pdo, 'https://a.invalid/fd/' . $i, 300 + $i);
    $pdo->exec("INSERT INTO snap_ap_timeline_boosts (object_id, actor_url) VALUES "
        . "('https://a.invalid/fd/{$i}', 'https://booster.invalid/u/1')");
}
mc_check('the fixture can tell the table is present',
         snap_timeline_boosts_table_exists($pdo) === true, $failures);

$r = snap_timeline_retention($pdo, 90, 0);
mc_check('a FAILED boost delete prunes nothing, rather than orphaning records',
         $r['deleted'] === 0, $failures);
mc_check('  the posts are still there for the next run',
         mc_count($pdo) === 5, $failures);
mc_check('  and so are their boost records, still attached to posts',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts')->fetchColumn() === 5,
         $failures);
mc_check('  with nothing orphaned',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts b
                            WHERE NOT EXISTS (SELECT 1 FROM snap_ap_timeline t
                                               WHERE t.object_id = b.object_id)')
                  ->fetchColumn() === 0, $failures);
mc_check('  and it still reports how many are over the line',
         $r['candidates'] === 5, $failures);

// ONE BATCH SKIPPED IS NOT THE WHOLE RUN ABANDONED. The prune works in chunks
// of 500, so with a single chunk "skip this batch" and "abort everything" look
// identical — a mutation swapping continue for throw survived on exactly that.
// This drives 600 doomed posts with the delete refused ONLY for the first
// chunk, so the second must still prune.
class McBoostDeleteFailsOnce extends PDO {
    public int $refusals = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (stripos($query, 'DELETE FROM snap_ap_timeline_boosts') !== false
            && $this->refusals === 0) {
            $this->refusals++;
            throw new PDOException('SQLSTATE[42000]: first batch refused by the fixture');
        }
        return parent::prepare($query, $options);
    }
}

$pdo = new McBoostDeleteFailsOnce('sqlite::memory:', null, null,
                                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE snap_ap_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT,
             object_id TEXT UNIQUE, actor_url TEXT DEFAULT "", source TEXT DEFAULT "home",
             published TEXT, fetched_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE snap_ap_timeline_membership (timeline_id INTEGER, feed TEXT,
             first_seen_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (timeline_id, feed))');
$pdo->exec('CREATE TABLE snap_ap_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,
             ntype TEXT, actor_url TEXT, object_id TEXT)');
$pdo->exec('CREATE TABLE snap_ap_timeline_boosts (object_id TEXT, actor_url TEXT,
             seen_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(object_id, actor_url))');
$pdo->beginTransaction();
for ($i = 0; $i < 600; $i++) { mc_row($pdo, 'https://a.invalid/chunk/' . $i, 300); }
$pdo->commit();

$r = snap_timeline_retention($pdo, 90, 0, 600);
mc_check('the first batch was refused, so its 500 posts stay',
         mc_count($pdo) === 500, $failures);
mc_check('  and the SECOND batch still pruned its 100',
         $r['deleted'] === 100, $failures);

// AND THE ABSENT CASE IS STILL THE OTHER ANSWER, which is the distinction.
$absent = mc_pdo();
for ($i = 0; $i < 4; $i++) { mc_row($absent, 'https://a.invalid/ab/' . $i, 300 + $i); }
$absent->exec('DROP TABLE snap_ap_timeline_boosts');
mc_check('a site with no boost table is seen as having none',
         snap_timeline_boosts_table_exists($absent) === false, $failures);
$r = snap_timeline_retention($absent, 90, 0);
mc_check('  and its prune carries on as normal', $r['deleted'] === 4, $failures);
// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 8 — IT IS BOUNDED, AND IT NEVER TAKES THE SITE DOWN\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = mc_pdo();
for ($i = 0; $i < 30; $i++) { mc_row($pdo, 'https://a.invalid/many/' . $i, 400 + $i); }
$r = snap_timeline_retention($pdo, 90, 0, 10);
mc_check('one run deletes at most its limit', $r['deleted'] === 10, $failures);
mc_check('  and says how many are still over the line', $r['candidates'] === 30, $failures);
mc_check('  with the rest still there', mc_count($pdo) === 20, $failures);

// Memberships must not be orphaned, on a schema whose constraint predates the
// cascade.
$orphans = (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_membership m
                              WHERE NOT EXISTS (SELECT 1 FROM snap_ap_timeline t
                                                 WHERE t.id = m.timeline_id)')->fetchColumn();
mc_check('and no membership row is left pointing at a deleted post',
         $orphans === 0, $failures);

$broken = mc_pdo();
$broken->exec('DROP TABLE snap_ap_notifications');
$threw = false;
try { $r = snap_timeline_retention($broken); } catch (Throwable $e) { $threw = true; }
mc_check('a missing notifications table does not throw', !$threw, $failures);
mc_check('  and nothing is pruned when the safety rule cannot be read',
         $r['deleted'] === 0, $failures);

$empty = mc_pdo();
$r = snap_timeline_retention($empty);
mc_check('an empty timeline is not a special case',
         $r['deleted'] === 0 && $r['candidates'] === 0, $failures);

echo "\n";
// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 9 — WHICH POSTS CARRY VIDEO AND NOTHING ELSE\n";
// ─────────────────────────────────────────────────────────────────────────────
// sv_post_is_video_only() answers one question and is no longer a gate: Sean's
// revised ruling of 2026-11-21 allows boosting a post that contains video,
// because a boost references the object and this server never touches the
// bytes. The predicate stays because Stage 10 needs it to build those cases,
// and because a rule about video ORIGINATING here will want it.
//
// sv_boost_remote() is still the one door every boost goes through -- pixel.php,
// smack-pixelfed.php and photochallenge's auto-boost all call it -- which is
// why one place is enough to assert any of this.
$fedi  = (string)file_get_contents($root . '/core/fediverse.php');
$start = strpos($fedi, 'function sv_post_is_video_only(');
$chunk = substr($fedi, $start, strpos($fedi, "\nfunction ", $start + 1) - $start);
eval(substr($chunk, 0, strrpos($chunk, "\n}") + 2));

function mc_att(array $attachments): array {
    return ['type' => 'Note', 'id' => 'https://a.invalid/n/1', 'attachment' => $attachments];
}
$vid = ['type' => 'Document', 'mediaType' => 'video/mp4', 'url' => 'https://a.invalid/v.mp4'];
$img = ['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://a.invalid/i.jpg'];

mc_check('a post whose only attachment is video is video-only',
         sv_post_is_video_only(mc_att([$vid])) === true, $failures);
mc_check('two videos and nothing else is still video-only',
         sv_post_is_video_only(mc_att([$vid, $vid])) === true, $failures);
mc_check('a Peertube-style type=Video attachment counts too',
         sv_post_is_video_only(mc_att([['type' => 'Video', 'url' => 'https://a.invalid/v']])) === true,
         $failures);
mc_check('a lone attachment object, not wrapped in a list, counts',
         sv_post_is_video_only(['type' => 'Note', 'attachment' => $vid]) === true, $failures);
mc_check('and it looks inside a Create or Announce wrapper',
         sv_post_is_video_only(['type' => 'Create', 'object' => mc_att([$vid])]) === true,
         $failures);

// VIDEO-ONLY, not video-at-all. Refusing a photograph that happens to carry a
// clip would make the rule wider than Sean wrote it.
mc_check('a photograph post is NOT video-only',
         sv_post_is_video_only(mc_att([$img])) === false, $failures);
mc_check('a post with a photograph AND a clip is NOT video-only',
         sv_post_is_video_only(mc_att([$img, $vid])) === false, $failures);
mc_check('a text post with no attachments is NOT video-only',
         sv_post_is_video_only(['type' => 'Note', 'id' => 'https://a.invalid/n/2']) === false,
         $failures);
mc_check('an empty attachment list is NOT video-only',
         sv_post_is_video_only(mc_att([])) === false, $failures);
mc_check('and an unfetchable post is NOT video-only, so a failed fetch is '
         . 'never read as "this is a video post"',
         sv_post_is_video_only(null) === false, $failures);

// CORRECTED AFTER CODEX'S FINAL REVIEW OF a526f067. This used to say "the gate
// sits in the boost path, ahead of the test-mode branch, so a test boost cannot
// do what a real one may not". THERE IS NO SUCH GATE ANY MORE. Sean's revised
// ruling of 2026-11-21 allows boosting a post containing video, video-only or
// mixed, because a boost REFERENCES the remote object and receiving servers
// fetch the media from its origin -- this server never touches the bytes. Stage
// 10 of this file proves exactly that, so the sentence contradicted the stage
// it sat above.
//
// WHAT THE SINGLE-ENTRY-POINT CHECK BELOW IS STILL FOR: sv_boost_remote() is
// where the TEST-MODE WHITELIST lives -- a test boost goes only to whitelisted
// accounts that follow this blog, never Public and never the real follower
// crowd. One caller means one place that restriction can be bypassed, and the
// check is that there is still only one.
$boost = substr($fedi, strpos($fedi, 'function sv_boost_remote('));
$boost = substr($boost, 0, strpos($boost, "\nfunction ", 1));
mc_check('every boost caller goes through that one function',
         substr_count((string)file_get_contents($root . '/pixel.php'), 'sv_boost_remote(') === 1
         && substr_count((string)file_get_contents($root . '/smack-pixelfed.php'), 'sv_boost_remote(') === 1,
         $failures);

// WHAT FOLLOWS IS THE HARNESS FOR STAGE 10, not a refusal test. It also used to
// claim a refusal "has to come back before anything is queued", which is the
// same removed gate described twice.
//
// sv_boost_remote() IS still driven for real, with test mode ON and a
// video-only post -- but what Stage 10 then asserts is the opposite of a
// refusal: the boost IS queued, the activity references the remote object by
// id, and NO VIDEO URL APPEARS IN THE FETCH LOG. The fetch log is the point:
// the ruling turns on this server never touching video bytes, and the only way
// to show that is to record every URL the path asks for and assert what is
// ABSENT from the list.
//
// The two checks above remain source positions, and a source position is not
// an execution order. That caveat was true when it was written and is still
// true; it was the thing being checked that changed, not the caveat.
$GLOBALS['mc_fetched']   = null;
$GLOBALS['mc_queued']    = [];
$GLOBALS['mc_json']      = [];
$GLOBALS['mc_fetch_log'] = [];
if (!function_exists('sv_fetch_ap')) {
    function sv_fetch_ap(string $url, ?array $settings = null): ?array {
        // Every URL this path asks for is recorded. Stage 10 asserts on what is
        // ABSENT from that list: no video URL is ever requested.
        $GLOBALS['mc_fetch_log'][] = $url;
        return $GLOBALS['mc_fetched'];
    }
}
if (!function_exists('sv_url_is_public')) {
    function sv_url_is_public(string $u): bool { return str_starts_with($u, 'https://'); }
}
if (!function_exists('sv_actor_url')) {
    function sv_actor_url(array $s): string { return 'https://me.invalid/ap/actor'; }
}
if (!function_exists('sv_followers_url')) {
    function sv_followers_url(array $s): string { return 'https://me.invalid/ap/followers'; }
}
if (!function_exists('sv_follower_inboxes')) {
    function sv_follower_inboxes(PDO $pdo): array { return ['https://f.invalid/inbox']; }
}
if (!function_exists('sv_queue_delivery')) {
    function sv_queue_delivery(PDO $pdo, string $inbox, string $json, ?string $d = null, ?int $p = null): int {
        $GLOBALS['mc_queued'][] = $inbox;
        $GLOBALS['mc_json'][]   = $json;   // Stage 10 reads the activity itself
        return 1;
    }
}
if (!function_exists('sv_process_deliveries')) {
    function sv_process_deliveries(PDO $pdo, array $s, int $n = 20): array { return [0, 0]; }
}
if (!function_exists('pc_test_allowed')) {
    function pc_test_allowed(): bool { return true; }
}
if (!function_exists('sv_test_whitelist_recipients')) {
    function sv_test_whitelist_recipients(PDO $pdo, array $s): array {
        return ['inboxes' => ['https://test.invalid/inbox'], 'actors' => ['https://test.invalid/u']];
    }
}
$mc_boost_src = substr($fedi, strpos($fedi, 'function sv_boost_remote('));
$mc_boost_src = substr($mc_boost_src, 0, strpos($mc_boost_src, "\nfunction ", 1));
eval(substr($mc_boost_src, 0, strrpos($mc_boost_src, "\n}") + 2));

$mc_db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// TEST MODE ON. This is the branch a gate placed too late would skip.
$GLOBALS['mc_fetched'] = mc_att([$img]);
$GLOBALS['mc_queued']  = [];
$res = sv_boost_remote($mc_db, [], 'https://a.invalid/n/1');
mc_check('an ordinary photograph post boosts', $res[0] === true, $failures);
mc_check('  and was actually queued', $GLOBALS['mc_queued'] !== [], $failures);


// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 10 — BOOSTING VIDEO TOUCHES NO VIDEO BYTES\n";
// ─────────────────────────────────────────────────────────────────────────────
// SEAN'S REVISED RULING, 2026-11-21 00:17, superseding "no boosting of video".
// A boost is an Announce that REFERENCES the remote object by id; receiving
// servers fetch the media from its origin, so this server never touches the
// bytes. Boosting a post that contains video is therefore allowed, video-only
// or mixed. What stays forbidden is caching, proxying, re-encoding, storing or
// uploading video, and video in posts that originate here.
//
// So the question this stage asks is not "did it refuse" but "did it TOUCH
// anything". The stubs above count every sv_fetch_ap() call and every cache
// write, and the assertions are about those counts.
$GLOBALS['mc_fetch_log'] = [];

$mc_before = count(snap_media_cache_entries($mc_tmp));

$GLOBALS['mc_fetched'] = mc_att([$vid]);
$GLOBALS['mc_queued']  = [];
$GLOBALS['mc_json']    = [];
$res = sv_boost_remote($mc_db, [], 'https://a.invalid/n/1');
mc_check('a VIDEO-ONLY post boosts, under the revised ruling',
         $res[0] === true, $failures);
mc_check('  and it was queued to somebody', $GLOBALS['mc_queued'] !== [], $failures);

$mc_sent = json_decode((string)($GLOBALS['mc_json'][0] ?? '{}'), true) ?: [];
mc_check('  the activity is an Announce', ($mc_sent['type'] ?? '') === 'Announce', $failures);
mc_check('  that REFERENCES the object by id, rather than embedding it',
         ($mc_sent['object'] ?? null) === 'https://a.invalid/n/1', $failures);

// THE BYTES. Only the object and its author were fetched, to find the inbox;
// no attachment URL was ever requested.
$mc_video_fetches = array_values(array_filter($GLOBALS['mc_fetch_log'],
    static fn (string $u): bool => str_contains($u, '.mp4')));
mc_check('  NO request was made for the video URL',
         $mc_video_fetches === [], $failures);
mc_check('  and nothing was written to the media cache',
         count(snap_media_cache_entries($mc_tmp)) === $mc_before, $failures);

// A MIXED post, the case the first ruling's code refused and the revised one
// allows. Codex's FAIL was that refusing only video-ONLY was wider permission
// than the instruction then said; under the revised instruction both boost.
$GLOBALS['mc_fetched'] = mc_att([$img, $vid]);
$GLOBALS['mc_queued']  = [];
$GLOBALS['mc_json']    = [];
$res = sv_boost_remote($mc_db, [], 'https://a.invalid/n/1');
mc_check('a MIXED photograph-and-video post boosts too', $res[0] === true, $failures);
$mc_sent = json_decode((string)($GLOBALS['mc_json'][0] ?? '{}'), true) ?: [];
mc_check('  still by reference, not by value',
         ($mc_sent['object'] ?? null) === 'https://a.invalid/n/1', $failures);
mc_check('  and still without fetching the clip',
         array_values(array_filter($GLOBALS['mc_fetch_log'],
             static fn (string $u): bool => str_contains($u, '.mp4'))) === [], $failures);

// NO PROXY URL ANYWHERE IN THE ACTIVITY. If a future change ever embeds the
// object instead of referencing it, the media must still point at the origin:
// a proxy URL here would mean this server had offered to serve the bytes.
$mc_body = (string)($GLOBALS['mc_json'][0] ?? '');
mc_check('  and the activity names no proxy URL of ours',
         !str_contains($mc_body, 'smack-media-proxy'), $failures);

// AND THE PROXY ITSELF STILL REFUSES VIDEO. The ruling narrowed to boosting;
// it did not open the cache.
mc_check('the media proxy allowlist is still image-only',
         !preg_match("~'video/~", $proxy), $failures);

// The predicate is kept, unused by the boost path, because this stage needs it
// to build the cases and a rule about ORIGINATING video will want it.
mc_check('sv_boost_remote() no longer refuses on video',
         !str_contains($boost, 'video only, so it is not boosted'), $failures);

if ($failures) {
    fwrite(STDERR, 'SS-1481 #14 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #14 PASS - the image cache has an owner-settable ceiling, refuses to
";
echo "KEEP anything over 4 MB while still serving it, enforces the 7-day TTL on
";
echo "every store and evicts oldest-first instead of by dice roll; the reader keeps
";
echo "90 days or N per feed, whichever is more generous, and never a post the owner
";
echo "was notified about; and boosting a post that contains video sends an Announce
";
echo "referencing the object, fetching no video URL and writing nothing to the
";
echo "cache, which is what Sean's revised ruling of 2026-11-21 turns on.
";
echo "NOT COVERED: the proxy endpoint end to end (it needs an authenticated request
";
echo "and a remote server) and the admin page's rendering. Stage 5 reads the proxy's
";
echo "source and says so.
";
// ===== SNAPSMACK EOF =====
