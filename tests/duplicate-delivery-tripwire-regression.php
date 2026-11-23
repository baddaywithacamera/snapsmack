<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * The duplicate-delivery tripwire (Sean's order, 2026-11-20).
 *
 * WHY IT EXISTS. SS-1481 #10 found the relay re-delivering already-sent posts
 * every ten minutes. The reason that went unnoticed is that nothing anywhere
 * counted a second delivery of the same thing to the same inbox -- a
 * successful queue row is DELETED, so the queue cannot answer "have we sent
 * this before". A fault with no counter is a fault nobody finds.
 *
 * A DETECTOR, NOT A FILTER, by instruction and by design. It never blocks a
 * send. A filter that is wrong refuses a legitimate re-send, and legitimate
 * re-sends exist -- sv_queue_delivery()'s own comment says a deliberate
 * re-send must remain possible after the prior one lands. A detector that is
 * wrong costs a log line.
 *
 * WHAT THIS FILE GOT WRONG THE FIRST TIME. It keyed on (object, inbox) and
 * said in this header that two Announces of the same post have different
 * activity ids, so keying on the activity "would never trip". Codex's review
 * finding 5 showed that backwards. The SS-1481 #10 repeat re-sent the
 * IDENTICAL deterministic id -- sc_relay_fanout() builds
 * `#announce-<hash of object>` every time -- so the activity id is precisely
 * what catches it. Meanwhile the cases that are NOT duplicates do differ: a
 * Create and the Update that follows it, and the relay Announce versus
 * sc_relay_refresh()'s `#announce-update-<hash>`. Keyed on the object, all of
 * those counted, and a counter whose non-zero means nothing gets switched off.
 *
 * So Stage 3 now drives the real id shapes out of core/smackcast-relay.php
 * rather than inventing ones that suit the test.
 *
 * NOT COVERED: real HTTP, the admin line that shows the counter, and the TTL
 * prune (it fires on roughly 1 send in 50 by design, so asserting on it would
 * be asserting on a dice roll).
 */

$root = dirname(__DIR__);
$failures = [];

function dt_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

$src = (string)file_get_contents($root . '/core/fediverse.php');
foreach (['sv_delivery_tripwire_table', 'sv_activity_subject_id',
          'sv_activity_identity', 'sv_delivery_tripwire_check',
          'sv_delivery_tripwire_record', 'sv_duplicate_delivery_count'] as $name) {
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) { fwrite(STDERR, "missing {$name}\n"); exit(1); }
    $end  = strpos($src, "\nfunction ", $start + 1);
    $body = substr($src, $start, $end - $start);
    eval(substr($body, 0, strrpos($body, "\n}") + 2));
}

/**
 * sqlite, taught the MySQL this code writes.
 *
 * Four translations and only four, so the fixture cannot quietly accept a
 * different statement later: the InnoDB CREATE, SHOW COLUMNS, the two upserts,
 * and NOW(). Shim the database, not the code under test.
 */
class DtPdo extends PDO {
    public function exec(string $statement): int|false {
        if (stripos($statement, 'ENGINE=InnoDB') !== false) {
            $statement = 'CREATE TABLE IF NOT EXISTS snap_ap_delivered ('
                . 'activity_key TEXT, inbox_url TEXT, object_id TEXT, '
                . 'delivered_at TEXT DEFAULT CURRENT_TIMESTAMP, '
                . 'UNIQUE(activity_key, inbox_url))';
        }
        $statement = str_replace(
            'ON DUPLICATE KEY UPDATE setting_val = CAST(setting_val AS UNSIGNED) + 1',
            'ON CONFLICT(setting_key) DO UPDATE SET setting_val = CAST(setting_val AS INTEGER) + 1',
            $statement);
        $statement = str_replace('DATE_SUB(NOW(), INTERVAL 7 DAY)',
                                 "datetime('now','-7 days')", $statement);
        return parent::exec($statement);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        // SHOW COLUMNS is the one piece of MySQL introspection this code uses.
        // sqlite answers the same question through pragma_table_info, so the
        // legacy-shape check is EXERCISED here rather than silently throwing
        // into the catch that turns the detector off.
        if (preg_match("/^SHOW COLUMNS FROM `(\w+)` LIKE '(\w+)'/", $query, $m)) {
            $query = "SELECT name FROM pragma_table_info('{$m[1]}') WHERE name = '{$m[2]}'";
        }
        return parent::query($query);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace('ON DUPLICATE KEY UPDATE delivered_at = NOW()',
            'ON CONFLICT(activity_key, inbox_url) DO UPDATE SET delivered_at = CURRENT_TIMESTAMP',
            $query);
        $query = str_replace('NOW()', 'CURRENT_TIMESTAMP', $query);
        return parent::prepare($query, $options);
    }
}

/**
 * A fixture database.
 *
 * sv_delivery_tripwire_table() caches readiness in a per-PROCESS static, so
 * only the FIRST handle in this run gets its CREATE executed. That is right
 * for production -- one handle per request -- and it means every later handle
 * has to be given the table by the fixture. $withTable is how, and Stage 0
 * passes false because driving the real CREATE is the whole point of it.
 */
function dt_pdo(bool $withTable = true): PDO {
    $pdo = new DtPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
    if ($withTable) {
        $pdo->exec('CREATE TABLE snap_ap_delivered (activity_key TEXT, inbox_url TEXT, '
            . 'object_id TEXT, delivered_at TEXT DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE(activity_key, inbox_url))');
    }
    return $pdo;
}

/** An activity with an explicit id, the way the relay builds them. */
function dt_activity(string $objectId, string $type = 'Announce', string $id = ''): string {
    return (string)json_encode([
        'type' => $type,
        'id' => $id !== '' ? $id : $objectId . '#act',
        'object' => $objectId,
    ]);
}

$log = sys_get_temp_dir() . '/ss_tripwire_' . bin2hex(random_bytes(5)) . '.log';
ini_set('error_log', $log);
function dt_log(string $log): string { return is_file($log) ? (string)file_get_contents($log) : ''; }

echo "Duplicate-delivery tripwire\n\n";

$inbox  = 'https://member.invalid/ap/inbox';
$other  = 'https://elsewhere.invalid/ap/inbox';
$object = 'https://source.invalid/ap/note/1';

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 0 — A TABLE OF THE OLD SHAPE IS REBUILT, NOT USED\n";
// ─────────────────────────────────────────────────────────────────────────────
// FIRST, before anything else calls sv_delivery_tripwire_table(): its readiness
// is a per-PROCESS static, so this is the only point in the run where the real
// function can still be driven against a table it did not make.
//
// The old shape is keyed (object, inbox), which is the behaviour Codex's
// finding 5 is about. Left in place it would quietly give the wrong answers,
// so it is dropped and rebuilt. The row in it is detector bookkeeping with a
// seven-day life, not anything a site needs.
$legacy = dt_pdo(false);
$legacy->exec('CREATE TABLE snap_ap_delivered (object_id TEXT, inbox_url TEXT, '
    . 'delivered_at TEXT, UNIQUE(object_id, inbox_url))');
$legacy->exec("INSERT INTO snap_ap_delivered (object_id, inbox_url, delivered_at) "
    . "VALUES ('x', 'y', CURRENT_TIMESTAMP)");
dt_check('the old table is there to begin with',
         (int)$legacy->query('SELECT COUNT(*) FROM snap_ap_delivered')->fetchColumn() === 1,
         $failures);

dt_check('readiness is reported', sv_delivery_tripwire_table($legacy) === true, $failures);

$after = $legacy->query("SELECT name FROM pragma_table_info('snap_ap_delivered')")
                ->fetchAll(PDO::FETCH_COLUMN);
dt_check('and the table now carries activity_key',
         in_array('activity_key', $after, true), $failures);
dt_check('the old rows went with the old shape',
         (int)$legacy->query('SELECT COUNT(*) FROM snap_ap_delivered')->fetchColumn() === 0,
         $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 1 — WHAT THE TRIPWIRE IS ABOUT\n";
// ─────────────────────────────────────────────────────────────────────────────
// Two questions, deliberately separate: which OBJECT an activity concerns (for
// the warning text) and which ACTIVITY it is (the key).
dt_check('an object given as a string is the subject',
         sv_activity_subject_id(dt_activity($object)) === $object, $failures);
dt_check('an object given as an embedded document is the subject too',
         sv_activity_subject_id((string)json_encode([
             'type' => 'Create', 'id' => 'https://h/a',
             'object' => ['id' => $object, 'type' => 'Note']])) === $object, $failures);
dt_check('with no object at all the subject falls back to the activity id',
         sv_activity_subject_id((string)json_encode([
             'type' => 'Follow', 'id' => 'https://h/follow/1'])) === 'https://h/follow/1',
         $failures);
dt_check('and junk gives nothing rather than guessing',
         sv_activity_subject_id('not json') === '', $failures);

dt_check('the activity key is the activity id',
         sv_activity_identity(dt_activity($object, 'Announce', 'https://h/ap#announce-abc'))
             === 'https://h/ap#announce-abc', $failures);
dt_check('an activity with no id of its own is keyed by a hash of its bytes',
         str_starts_with(sv_activity_identity('{"type":"Announce","object":"x"}'), 'sha256:'),
         $failures);
dt_check('the same bytes give the same hash key, so a repeat is still a repeat',
         sv_activity_identity('{"type":"Announce","object":"x"}')
             === sv_activity_identity('{"type":"Announce","object":"x"}'), $failures);
dt_check('and junk gives no key at all',
         sv_activity_identity('not json') === '', $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE SAME ACTIVITY TO THE SAME INBOX TRIPS, AND COUNTS\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = dt_pdo();
$act = dt_activity($object);

dt_check('a first send does NOT trip',
         sv_delivery_tripwire_check($pdo, $inbox, $act) === false, $failures);
dt_check('and the counter is still zero',
         sv_duplicate_delivery_count($pdo) === 0, $failures);

sv_delivery_tripwire_record($pdo, $inbox, $act);

dt_check('a SECOND send of the same activity to the same inbox TRIPS',
         sv_delivery_tripwire_check($pdo, $inbox, $act) === true, $failures);
dt_check('the counter reads 1', sv_duplicate_delivery_count($pdo) === 1, $failures);

$logged = dt_log($log);
dt_check('a warning was logged', str_contains($logged, 'DUPLICATE DELIVERY'), $failures);
dt_check('naming the object', str_contains($logged, $object), $failures);
dt_check('naming the inbox', str_contains($logged, $inbox), $failures);
dt_check('naming the activity type', str_contains($logged, 'Announce'), $failures);
dt_check('naming the caller', str_contains($logged, 'delivery-worker'), $failures);
dt_check('and saying plainly that it was NOT blocked',
         str_contains($logged, 'Not blocked'), $failures);

// Tripping again counts again — the count is of events, not of activities.
sv_delivery_tripwire_check($pdo, $inbox, $act);
dt_check('a third send counts again, so the number means something',
         sv_duplicate_delivery_count($pdo) === 2, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE REAL RELAY REPEAT TRIPS; LEGITIMATE RE-SENDS DO NOT\n";
// ─────────────────────────────────────────────────────────────────────────────
// The id shapes come from core/smackcast-relay.php, not from this test's
// imagination, which is the mistake Codex's finding 5 caught. If either shape
// changes, the first two checks fail and say so.
$relay = (string)file_get_contents($root . '/core/smackcast-relay.php');
dt_check('the fanout Announce id is deterministic from the object',
         str_contains($relay, "'#announce-' . hash('sha256', \$object_id)"), $failures);
dt_check('and the refresh Announce id is a DIFFERENT shape',
         str_contains($relay, "'#announce-update-'"), $failures);

$fanout_id  = 'https://hub.invalid/ap/actor#announce-' . hash('sha256', $object);
$refresh_id = 'https://hub.invalid/ap/actor#announce-update-' . hash('sha256', $object);

$fresh = dt_pdo();
$fanout  = dt_activity($object, 'Announce', $fanout_id);
$again   = dt_activity($object, 'Announce', $fanout_id);   // the #10 bug: identical id
$refresh = dt_activity($object, 'Announce', $refresh_id);
$create  = dt_activity($object, 'Create',  'https://source.invalid/ap/create/1');
$update  = dt_activity($object, 'Update',  'https://source.invalid/ap/update/1');

sv_delivery_tripwire_record($fresh, $inbox, $fanout);
dt_check('THE SS-1481 #10 REPEAT TRIPS: the same deterministic Announce id again',
         sv_delivery_tripwire_check($fresh, $inbox, $again) === true, $failures);

$before = sv_duplicate_delivery_count($fresh);
dt_check('the deliberate refresh Announce of the same post does NOT trip',
         sv_delivery_tripwire_check($fresh, $inbox, $refresh) === false, $failures);

sv_delivery_tripwire_record($fresh, $inbox, $create);
dt_check('an Update following a Create of the same object does NOT trip',
         sv_delivery_tripwire_check($fresh, $inbox, $update) === false, $failures);

dt_check('a DIFFERENT object to the same inbox does not trip',
         sv_delivery_tripwire_check($fresh, $inbox, dt_activity('https://source.invalid/ap/note/2')) === false,
         $failures);
dt_check('the SAME activity to a DIFFERENT inbox does not trip',
         sv_delivery_tripwire_check($fresh, $other, $fanout) === false, $failures);
dt_check('an activity with no identifiable id does not trip',
         sv_delivery_tripwire_check($fresh, $inbox, 'not json') === false, $failures);
dt_check('an empty inbox does not trip',
         sv_delivery_tripwire_check($fresh, '', $fanout) === false, $failures);
dt_check('and none of those moved the counter',
         sv_duplicate_delivery_count($fresh) === $before, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — IT CAN NEVER STOP A POST GOING OUT\n";
// ─────────────────────────────────────────────────────────────────────────────
// A detector that throws is worse than no detector: it would turn a
// bookkeeping problem into undelivered posts. Both halves are driven with the
// table gone.
// dt_pdo() explains why the fixture, not the code under test, makes the table
// for every handle after the first.
$broken = dt_pdo();
sv_delivery_tripwire_record($broken, $inbox, $act);
$broken->exec('DROP TABLE snap_ap_delivered');

$threw = false;
try {
    $tripped = sv_delivery_tripwire_check($broken, $inbox, $act);
} catch (Throwable $e) { $threw = true; $tripped = null; }
dt_check('the check survives its table vanishing', !$threw, $failures);
dt_check('and answers "no" rather than guessing', $tripped === false, $failures);

$threw = false;
try { sv_delivery_tripwire_record($broken, $inbox, $act); }
catch (Throwable $e) { $threw = true; }
dt_check('the record survives its table vanishing', !$threw, $failures);

$noSettings = dt_pdo();
$noSettings->exec('DROP TABLE snap_settings');
$threw = false;
try { sv_duplicate_delivery_count($noSettings); } catch (Throwable $e) { $threw = true; }
dt_check('and reading the counter survives no settings table', !$threw, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — THE TABLE IS DECLARED WHERE A TABLE BELONGS\n";
// ─────────────────────────────────────────────────────────────────────────────
// Codex's review finding 6. A runtime CREATE is not a declaration: on a host
// where it cannot run, the detector switches itself off and nothing says so.
$canon = (string)file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
dt_check('snap_ap_delivered is in the canonical schema',
         str_contains($canon, 'CREATE TABLE IF NOT EXISTS `snap_ap_delivered`'), $failures);
dt_check('with the activity_key the code writes',
         str_contains($canon, '`activity_key` varchar(500)'), $failures);
dt_check('and the same unique key the runtime CREATE uses',
         substr_count($canon . $src, 'UNIQUE KEY `uq_ap_delivered` (`activity_key`(170), `inbox_url`(170))') === 2,
         $failures);

// The rebuild itself is Stage 0: it has to run before anything else touches
// the readiness static.

echo "\n";
if ($failures) {
    fwrite(STDERR, 'TRIPWIRE FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
@unlink($log);
echo "TRIPWIRE PASS — the SS-1481 #10 repeat (the identical deterministic\n";
echo "Announce id, sent again) warns and counts; a refresh Announce, an Update\n";
echo "after a Create, another object, another inbox and an unidentifiable\n";
echo "activity do not; no failure of the detector can stop a send; and the\n";
echo "table is declared in the canonical schema, not only at runtime.\n";
echo "NOT COVERED: real HTTP, the admin line that shows the counter, and the\n";
echo "7-day prune, which fires on about 1 send in 50 by design -- asserting on\n";
echo "it would be asserting on a dice roll.\n";
// ===== SNAPSMACK EOF =====
