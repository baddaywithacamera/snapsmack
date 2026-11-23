<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 4 — a missing membership table says so, once, when it matters.
 *
 * THE FAULT WAS THE SILENCE, not the inertness. sc_relay_add_membership()
 * returned quietly when snap_ap_timeline_membership was absent.
 *
 * That table is what makes the LOCAL and GLOBAL tabs deterministic: its primary
 * key is (timeline_id, feed), so a direct follow and a relay arrival of the same
 * post each add their own row. Without it the reader falls back to the single
 * `source` column, and sv_ingest_timeline() never updates source on a duplicate
 * -- so FIRST ARRIVAL WINS and a post can simply miss a tab. The table is
 * created by schema-sync and not by runtime self-heal, so a hub whose sync has
 * not run looks like it is working while filing posts in the wrong tab.
 *
 * Re-verified present at caebe17b before changing anything: a bare return, no
 * log of any kind.
 *
 * WHY IT IS CONDITIONAL, and the test holds it to that. This function runs on
 * every timeline ingest on EVERY site, and an ordinary blog has no relay tables
 * by design. Logging there would put a line in the log for every post forever,
 * which is its own way of saying nothing. A 'home' feed needs no membership
 * row; a 'local' or 'global' feed is a relay arrival, and that is when the
 * absence matters.
 *
 * WHAT THIS PROVES: the log is driven and read back from a real error_log file
 * -- the behaviour, not the presence of an error_log() call in the source.
 */

$root = dirname(__DIR__);
$failures = [];

function mm_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

/**
 * The child script, written as a heredoc rather than concatenated fragments.
 *
 * My first version glued quoted strings together and I broke it twice editing
 * it. A heredoc is the same code a person would read.
 *
 * TWO MySQL-ISMS ARE SHIMMED, and only those two, because the dialect is not
 * what is under test:
 *   - information_schema.TABLES is how the code asks whether the table exists.
 *     sqlite has no such schema, so without this the probe always throws, the
 *     code always believes the table is absent, and the "table present" case
 *     would pass while never being exercised. That actually happened: the
 *     assertion was green against the wrong branch.
 *   - INSERT IGNORE is MySQL; sqlite spells it INSERT OR IGNORE.
 * Shim the database, not the code under test.
 */
function mm_child_source(string $root, string $log, string $feed, int $posts, bool $withTable): string {
    $relay  = var_export($root . '/core/smackcast-relay.php', true);
    $logv   = var_export($log, true);
    $feedv  = var_export($feed, true);
    $create = $withTable
        ? "\$pdo->exec('CREATE TABLE snap_ap_timeline_membership "
          . "(timeline_id INTEGER, feed TEXT, discovered_via_actor TEXT)');"
        : '';

    return <<<CHILD
<?php
ini_set('error_log', {$logv});
require {$relay};

class MmPdo extends PDO {
    public function query(string \$q, ?int \$m = null, mixed ...\$a): PDOStatement|false {
        if (str_contains(\$q, 'information_schema.TABLES')) {
            return parent::query("SELECT 1 FROM sqlite_master WHERE type='table'"
                . " AND name='snap_ap_timeline_membership' LIMIT 1");
        }
        return parent::query(\$q, \$m, ...\$a);
    }
    public function prepare(string \$q, array \$o = []): PDOStatement|false {
        \$q = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', \$q);
        return parent::prepare(\$q, \$o);
    }
}

\$pdo = new MmPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
\$pdo->exec('CREATE TABLE snap_ap_timeline (id INTEGER PRIMARY KEY, object_id TEXT)');
\$pdo->exec("INSERT INTO snap_ap_timeline (id, object_id) VALUES (1, 'https://x.invalid/n/0')");
{$create}

for (\$i = 0; \$i < {$posts}; \$i++) {
    sc_relay_add_membership(\$pdo, 'https://x.invalid/n/' . \$i, {$feedv}, 'https://a.invalid/actor');
}

\$rows = 0;
try {
    \$rows = (int)\$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_membership')->fetchColumn();
} catch (Throwable \$e) { \$rows = -1; }
echo 'RAN rows=' . \$rows;
CHILD;
}

/**
 * Run N ingests in a CHILD process and count the warnings in its log.
 *
 * A child per case, because the warning is deliberately once-per-request and
 * held in a static. Two cases in one process would share that static and the
 * second would silently prove nothing.
 */
function mm_run(string $root, string $feed, int $posts, bool $withTable): array {
    $log    = sys_get_temp_dir() . '/ss1481_mm_' . bin2hex(random_bytes(5)) . '.log';
    $script = sys_get_temp_dir() . '/ss1481_mm_' . bin2hex(random_bytes(5)) . '.php';
    file_put_contents($script, mm_child_source($root, $log, $feed, $posts, $withTable));

    $out = [];
    exec('php ' . escapeshellarg($script) . ' 2>&1', $out);
    $stdout = implode("\n", $out);
    $logged = is_file($log) ? (string)file_get_contents($log) : '';
    @unlink($script);
    @unlink($log);

    $rows = null;
    if (preg_match('/RAN rows=(-?\d+)/', $stdout, $m)) $rows = (int)$m[1];

    return [
        'ran'      => str_contains($stdout, 'RAN'),
        'rows'     => $rows,
        'stdout'   => $stdout,
        'warnings' => substr_count($logged, 'snap_ap_timeline_membership is MISSING'),
        'log'      => $logged,
    ];
}

echo "SS-1481 #4 — a missing membership table says so, once, when it matters\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — A RELAY FEED WITH NO TABLE COMPLAINS\n";
// ─────────────────────────────────────────────────────────────────────────────
foreach (['local', 'global'] as $feed) {
    $r = mm_run($root, $feed, 3, false);
    if (!$r['ran']) echo "  (child said: " . $r['stdout'] . ")\n";
    mm_check("the {$feed} ingest ran without throwing", $r['ran'], $failures);
    mm_check("a {$feed} relay post with no membership table warns",
             $r['warnings'] >= 1, $failures);
    mm_check("and warns ONCE for three posts, not three times ({$feed})",
             $r['warnings'] === 1, $failures);
    mm_check("the warning names the table, the consequence and the fix ({$feed})",
             str_contains($r['log'], 'snap_ap_timeline_membership')
             && str_contains($r['log'], 'first arrival wins')
             && str_contains($r['log'], 'schema-sync'), $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — AN ORDINARY BLOG STAYS QUIET\n";
// ─────────────────────────────────────────────────────────────────────────────
// The half that keeps this from being noise. Every site runs this function on
// every ingest, and a blog with no relay has nothing to fix.
$home = mm_run($root, 'home', 5, false);
if (!$home['ran']) echo "  (child said: " . $home['stdout'] . ")\n";
mm_check('the home ingest ran', $home['ran'], $failures);
mm_check('a home-feed post with no membership table says NOTHING',
         $home['warnings'] === 0, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — AND NOTHING IS SAID WHEN THE TABLE IS THERE\n";
// ─────────────────────────────────────────────────────────────────────────────
// This case passed while never running until the information_schema shim went
// in: the probe threw, the code assumed "absent", and the assertion that it
// stayed quiet was measuring the wrong branch. The row count is checked so the
// case cannot go quiet again without being noticed.
$ok = mm_run($root, 'local', 3, true);
if (!$ok['ran']) echo "  (child said: " . $ok['stdout'] . ")\n";
mm_check('a local ingest with the table present ran', $ok['ran'], $failures);
mm_check('and warns about nothing', $ok['warnings'] === 0, $failures);
mm_check('and a membership row really was written, so the branch was exercised',
         $ok['rows'] === 1, $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #4 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #4 PASS — a relay arrival with no membership table warns once per\n";
echo "request, naming the table, the consequence and the fix; a home-feed post\n";
echo "and a present table stay silent, and the present-table case really writes\n";
echo "its row.\n";
echo "NOT COVERED: that schema-sync actually creates the table on a live hub.\n";
echo "This proves the warning, not the remedy.\n";
// ===== SNAPSMACK EOF =====
