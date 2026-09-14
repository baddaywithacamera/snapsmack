<?php
/**
 * SNAPSMACK — repair-stats.php (one-off CLI, 0.7.712D)
 *
 * Re-count every completed day still held in raw stats with the behavioural
 * bot rule (one page, no referrer, never seen again that day = a fetcher, not
 * a reader), then rebuild that day's snap_stats_daily row. Nothing is deleted.
 * Idempotent: a second run reclassifies zero rows and rebuilds the same totals.
 *
 * Why: on 2026-08-29 the fleet's "human" line fell ~65%. It was not readers
 * leaving — readers clicking through the site went UP — it was a pool of
 * scrapers on residential addresses stopping, and they had been counted as
 * people. The Traffic Stats page has the same action as a button
 * (Re-count History); this is the same routine for the whole box.
 *
 *   php repair-stats.php              dry run: shows what would move, per day
 *   php repair-stats.php --apply      reclassify + rebuild
 *
 * Fleet, from the sites box (one line, every site dir under the web root):
 *   for d in /var/www/sites/SITE-DIR; do ... done   — see CHANGELOG 0.7.712D for the exact paste
 *
 * Named repair-*.php on purpose: SMACKBACK never baselines operator repair
 * scripts (core/smackback.php), so shipping, replacing or deleting this file
 * is not a TAMPERED / MISSING breach.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('CLI only.');
}

$base = dirname(__FILE__);
require_once $base . '/core/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection did not come up (core/db.php).\n");
    exit(1);
}
require_once $base . '/core/stats-logger.php';

$opts  = getopt('', ['apply']);
$apply = isset($opts['apply']);
$site  = basename($base);

if (!$apply) {
    // Dry run: count what the rule WOULD move, per day, without touching a row.
    $rows = $pdo->query("
        SELECT DATE(s.hit_at) AS d, COUNT(*) AS would_move
        FROM snap_stats s
        JOIN (
            SELECT DATE(hit_at) AS d, ip_hash
            FROM snap_stats
            WHERE DATE(hit_at) < CURDATE()
            GROUP BY DATE(hit_at), ip_hash
            HAVING COUNT(*) = 1
        ) one ON one.ip_hash = s.ip_hash AND one.d = DATE(s.hit_at)
        WHERE s.is_bot = 0 AND s.referrer_host IS NULL
        GROUP BY DATE(s.hit_at)
        ORDER BY d DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($rows as $r) { $total += (int)$r['would_move']; }
    echo "{$site}: DRY RUN — {$total} single-page no-referrer hits across " . count($rows) . " day(s) would move to the bot column.\n";
    foreach (array_slice($rows, 0, 14) as $r) echo "  {$r['d']}  {$r['would_move']}\n";
    if (count($rows) > 14) echo "  … " . (count($rows) - 14) . " more day(s)\n";
    echo "Run again with --apply to do it.\n";
    exit(0);
}

[$days, $moved] = snapsmack_reroll_all_days($pdo);
echo "{$site}: re-rolled {$days} day(s); {$moved} hit(s) moved to the bot column; daily rows rebuilt.\n";
// ===== SNAPSMACK EOF =====
