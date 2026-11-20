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
 * counted a second delivery of the same object to the same inbox -- a
 * successful queue row is DELETED, so the queue cannot answer "have we sent
 * this before". A fault with no counter is a fault nobody finds.
 *
 * A DETECTOR, NOT A FILTER, by instruction and by design. It never blocks a
 * send. A filter that is wrong refuses a legitimate re-send, and legitimate
 * re-sends exist -- sv_queue_delivery()'s own comment says a deliberate re-send
 * must remain possible after the prior one lands. A detector that is wrong
 * costs a log line.
 *
 * WHAT THIS PROVES, by running it: the same object to the same inbox trips once
 * and counts; a different object does not; a different inbox does not; and the
 * detector's own failures never reach the caller, because a bookkeeping error
 * must not stop a post going out.
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
          'sv_delivery_tripwire_check', 'sv_delivery_tripwire_record',
          'sv_duplicate_delivery_count'] as $name) {
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) { fwrite(STDERR, "missing {$name}\n"); exit(1); }
    $end  = strpos($src, "\nfunction ", $start + 1);
    $body = substr($src, $start, $end - $start);
    eval(substr($body, 0, strrpos($body, "\n}") + 2));
}

/**
 * sqlite, taught the MySQL this code writes.
 *
 * Three translations and only three, so the fixture cannot quietly accept a
 * different statement later: the InnoDB CREATE, the two upserts, and NOW().
 * Shim the database, not the code under test.
 */
class DtPdo extends PDO {
    public function exec(string $statement): int|false {
        if (stripos($statement, 'ENGINE=InnoDB') !== false) {
            $statement = 'CREATE TABLE IF NOT EXISTS snap_ap_delivered ('
                . 'object_id TEXT, inbox_url TEXT, '
                . 'delivered_at TEXT DEFAULT CURRENT_TIMESTAMP, '
                . 'UNIQUE(object_id, inbox_url))';
        }
        $statement = str_replace(
            'ON DUPLICATE KEY UPDATE setting_val = CAST(setting_val AS UNSIGNED) + 1',
            'ON CONFLICT(setting_key) DO UPDATE SET setting_val = CAST(setting_val AS INTEGER) + 1',
            $statement);
        $statement = str_replace('DATE_SUB(NOW(), INTERVAL 7 DAY)',
                                 "datetime('now','-7 days')", $statement);
        return parent::exec($statement);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace('ON DUPLICATE KEY UPDATE delivered_at = NOW()',
            'ON CONFLICT(object_id, inbox_url) DO UPDATE SET delivered_at = CURRENT_TIMESTAMP',
            $query);
        $query = str_replace('NOW()', 'CURRENT_TIMESTAMP', $query);
        return parent::prepare($query, $options);
    }
}

function dt_pdo(): PDO {
    $pdo = new DtPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
    return $pdo;
}

function dt_activity(string $objectId, string $type = 'Announce'): string {
    return (string)json_encode(['type' => $type, 'id' => $objectId . '#act',
                                'object' => $objectId]);
}

$log = sys_get_temp_dir() . '/ss_tripwire_' . bin2hex(random_bytes(5)) . '.log';
ini_set('error_log', $log);
function dt_log(string $log): string { return is_file($log) ? (string)file_get_contents($log) : ''; }

echo "Duplicate-delivery tripwire\n\n";

$inbox  = 'https://member.invalid/ap/inbox';
$other  = 'https://elsewhere.invalid/ap/inbox';
$object = 'https://source.invalid/ap/note/1';

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — which id the tripwire is about\n";
// ─────────────────────────────────────────────────────────────────────────────
// The OBJECT, not the activity. Two Announces of the same post have different
// activity ids, so keying on the activity would never trip -- which is exactly
// the duplicate SS-1481 #10 produced.
dt_check('an object given as a string is the subject',
         sv_activity_subject_id(dt_activity($object)) === $object, $failures);
dt_check('an object given as an embedded document is the subject too',
         sv_activity_subject_id((string)json_encode([
             'type' => 'Create', 'id' => 'https://h/a',
             'object' => ['id' => $object, 'type' => 'Note']])) === $object, $failures);
dt_check('with no object at all it falls back to the activity id',
         sv_activity_subject_id((string)json_encode([
             'type' => 'Follow', 'id' => 'https://h/follow/1'])) === 'https://h/follow/1',
         $failures);
dt_check('and junk gives nothing rather than guessing',
         sv_activity_subject_id('not json') === '', $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE SAME OBJECT TO THE SAME INBOX TRIPS, AND COUNTS\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = dt_pdo();
$act = dt_activity($object);

dt_check('a first send does NOT trip',
         sv_delivery_tripwire_check($pdo, $inbox, $act) === false, $failures);
dt_check('and the counter is still zero',
         sv_duplicate_delivery_count($pdo) === 0, $failures);

sv_delivery_tripwire_record($pdo, $inbox, $act);

dt_check('a SECOND send of the same object to the same inbox TRIPS',
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

// Tripping again counts again — the count is of events, not of objects.
sv_delivery_tripwire_check($pdo, $inbox, $act);
dt_check('a third send counts again, so the number means something',
         sv_duplicate_delivery_count($pdo) === 2, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — AND NOTHING ELSE TRIPS IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// The whole value of a detector is that a non-zero number means something. A
// false positive makes it noise, and noise gets switched off.
$before = sv_duplicate_delivery_count($pdo);
dt_check('a DIFFERENT object to the same inbox does not trip',
         sv_delivery_tripwire_check($pdo, $inbox, dt_activity('https://source.invalid/ap/note/2')) === false,
         $failures);
dt_check('the SAME object to a DIFFERENT inbox does not trip',
         sv_delivery_tripwire_check($pdo, $other, $act) === false, $failures);
dt_check('an activity with no identifiable object does not trip',
         sv_delivery_tripwire_check($pdo, $inbox, 'not json') === false, $failures);
dt_check('an empty inbox does not trip',
         sv_delivery_tripwire_check($pdo, '', $act) === false, $failures);
dt_check('and none of those moved the counter',
         sv_duplicate_delivery_count($pdo) === $before, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — IT CAN NEVER STOP A POST GOING OUT\n";
// ─────────────────────────────────────────────────────────────────────────────
// A detector that throws is worse than no detector: it would turn a
// bookkeeping problem into undelivered posts. Both halves are driven with the
// table gone.
// sv_delivery_tripwire_table() caches readiness in a per-PROCESS static, so a
// second handle in the same process never gets its CREATE run. That is correct
// for production -- one handle per request -- and it means this fixture has to
// make the table itself before it can take it away.
$broken = dt_pdo();
$broken->exec('CREATE TABLE snap_ap_delivered (object_id TEXT, inbox_url TEXT, '
    . 'delivered_at TEXT, UNIQUE(object_id, inbox_url))');
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

@unlink($log);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'TRIPWIRE FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "TRIPWIRE PASS — a repeat of the same object to the same inbox warns and\n";
echo "counts; a different object, a different inbox and an unidentifiable\n";
echo "activity do not; and no failure of the detector can stop a send.\n";
echo "NOT COVERED: real HTTP, the admin line that shows the counter, and the\n";
echo "7-day prune, which fires on about 1 send in 50 by design -- asserting on\n";
echo "it would be asserting on a dice roll.\n";
// ===== SNAPSMACK EOF =====
