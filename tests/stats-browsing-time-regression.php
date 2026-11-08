<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * THE PUBLIC browsing_time_ms AGGREGATE COUNTS THE RIGHT HITS AND NOTHING ELSE.
 * (Sean asked for gallery-hover Browsing time; PM 09:40.)
 *
 * stats.php adds browsing_time_ms = SUM(dwell_ms) over the SAME population the
 * fleet scrolltime metric uses: non-bot, engaged (dwell_ms >= 10000), on landing
 * or archive pages. It is an aggregate only -- no individual rows -- and is null
 * (a dash on the card) when snap_stats/dwell_ms is absent or nothing qualifies.
 *
 * This runs stats.php's ACTUAL query, extracted from the file, against a seeded
 * database, so the population filter is measured rather than asserted. It reads
 * emitted SQL, not a rendered card.
 */

$root = dirname(__DIR__);
$src = (string)file_get_contents($root . '/stats.php');

$pass = 0; $fail = 0;
function sb(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

// Pull the real query out of stats.php so the test exercises what ships.
if (!preg_match('~SELECT\s+SUM\(dwell_ms\)\s+FROM\s+snap_stats\s+WHERE[^"]*?archive\'\)~i', $src, $m)) {
    echo "  FAIL  could not find the browsing_time_ms query in stats.php\n";
    echo "1 passed, 1 failed\nStats browsing time: FAIL.\n"; exit(1);
}
$query = $m[0];
echo "THE QUERY FROM stats.php:\n  " . preg_replace('~\s+~', ' ', $query) . "\n\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE snap_stats (id INTEGER PRIMARY KEY, is_bot INT, dwell_ms INT, page_type TEXT)');
$ins = $pdo->prepare('INSERT INTO snap_stats (is_bot, dwell_ms, page_type) VALUES (?,?,?)');
// Qualifying: non-bot, >=10000, landing/archive.
$ins->execute([0, 12000, 'landing']);   // +12000
$ins->execute([0, 30000, 'archive']);   // +30000
$ins->execute([0, 10000, 'landing']);   // +10000 (boundary, inclusive)
// Excluded:
$ins->execute([1, 50000, 'archive']);   // bot
$ins->execute([0,  5000, 'landing']);   // below engaged threshold
$ins->execute([0, 90000, 'post']);      // wrong page_type
$ins->execute([0, 40000, 'single']);    // wrong page_type

echo "ONLY NON-BOT, ENGAGED, LANDING/ARCHIVE HITS ARE SUMMED\n";
$v = $pdo->query($query)->fetchColumn();
sb((int)$v === 52000, 'sum is 12000+30000+10000 = 52000, excluding bot/<10s/other pages (got ' . var_export($v, true) . ')');

echo "\nNULL WHEN NOTHING QUALIFIES (dash, not a wrong zero)\n";
$pdo->exec('DELETE FROM snap_stats');
$v2 = $pdo->query($query)->fetchColumn();
$coerced = ($v2 === null || $v2 === false) ? null : (int)$v2; // stats.php's own coercion
sb($coerced === null, 'an empty population yields null, which the card renders as a dash');

echo "\nTHE FIELD IS EMITTED AND FAIL-SOFT, LIKE EVERY OTHER METRIC\n";
sb(str_contains($src, "'browsing_time_ms' => \$browsing_time_ms"), 'stats.php emits browsing_time_ms in the JSON payload');
sb((bool)preg_match('~\$browsing_time_ms\s*=\s*null;~', $src), 'it defaults to null before the guarded query');
sb((bool)preg_match('~catch\s*\(\s*Throwable[^)]*\)\s*\{[^}]*null~s', substr($src, strpos($src, 'browsing_time_ms') ?: 0, 600)) || str_contains($src, 'leave null (dash)'),
   'a missing snap_stats/dwell_ms degrades to null rather than 500-ing the endpoint');

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) { echo "Stats browsing time: FAIL ({$fail}).\n"; exit(1); }
echo "Stats browsing time: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: the end-to-end HTTP response and the marketing-side dash\n";
echo "  formatter. This exercises stats.php's own query and output shape against a\n";
echo "  seeded database; the hover card is Codex's marketing-side display.\n";

// ===== SNAPSMACK EOF =====
