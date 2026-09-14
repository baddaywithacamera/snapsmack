<?php
/**
 * Behavioural bot rule (0.7.712D): one page, no referrer, never seen again that
 * day = fetcher, not reader. Contract checks on the logger, the stats page and
 * the repair CLI, plus the rule's SQL shape (only is_bot=0 + NULL referrer +
 * single-hit visitors move; reversible via bot_reason).
 */
$root = dirname(__DIR__);
$log  = file_get_contents($root . '/core/stats-logger.php');
$page = file_get_contents($root . '/smack-stats.php');
$cli  = file_get_contents($root . '/repair-stats.php');
$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) $fail++;
};

$check('rule runs before every daily rollup',   preg_match('/snapsmack_reclassify_single_direct\(\$pdo, \$date\);\s+try \{\s+\/\/ One index range/', $log) === 1);
$check('only human rows with no referrer move', str_contains($log, "WHERE s.hit_at >= ? AND s.hit_at < ? AND s.is_bot = 0 AND s.referrer_host IS NULL"));
$check('only single-hit visitors that day',     str_contains($log, "GROUP BY ip_hash\n                HAVING COUNT(*) = 1"));
$check('reversible: bot_reason stamped',        str_contains($log, "SET s.is_bot = 1, s.bot_reason = 'single-direct'"));
$check('column added idempotently',             str_contains($log, "ADD COLUMN IF NOT EXISTS bot_reason"));
$check('re-roll rebuilds every held day',       str_contains($log, 'function snapsmack_reroll_all_days(') && str_contains($log, 'usleep($pace_ms * 1000)'));
$check('stats page has the re-count action',    str_contains($page, "\$_POST['action'] === 'reroll'") && str_contains($page, 'value="reroll"'));
$check('CLI is SMACKBACK-exempt (repair-*.php)', preg_match('/^repair-[a-z0-9-]+\.php$/', 'repair-stats.php') === 1 && is_file($root . '/repair-stats.php'));
$check('CLI dry-runs unless --apply',            str_contains($cli, "getopt('', ['apply'])") && str_contains($cli, 'DRY RUN'));
$check('CLI refuses the web',                    str_contains($cli, "if (PHP_SAPI !== 'cli')"));
$check('UA bot check untouched',                 str_contains($log, 'function snapsmack_is_bot($ua)'));
// Index ranges only: DATE(hit_at) = ? in a WHERE scans the whole table, and the
// re-roll runs per day for a year on 37 sites sharing one DB box.
$check('no DATE(hit_at) = ? in any WHERE',        !preg_match('/WHERE[^;"]*DATE\(hit_at\) = \?/', $log));
$check('no DATE(hit_at) = ? in the CLI',           !preg_match('/WHERE[^;"]*DATE\(hit_at\)\s*[=<>]/', $cli));

if ($fail) { fwrite(STDERR, "{$fail} check(s) failed.\n"); exit(1); }
echo "ALL PASS — single-page no-referrer visitors are counted as fetchers, reversibly, and history can be re-counted.\n";
// ===== SNAPSMACK EOF =====
