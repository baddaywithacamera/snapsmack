<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 6 — withdrawing a boost withdraws the boosted post.
 *
 * THE FAULT. An Announce from someone the owner follows pulls the boosted post
 * into the reader. The Undo branch dropped the boost notification and the boost
 * tally, and left the POST sitting there. The only reason it was in the reader
 * was the boost, so the reader kept showing something nobody was sharing any
 * more.
 *
 * Re-verified present at caebe17b before changing anything.
 *
 * WHAT MAKES THIS DELICATE, and why the test spends most of its length on it:
 * snap_ap_timeline is UNIQUE on object_id, so ONE row serves every reason a
 * post might be present. A blunt `DELETE WHERE object_id = ?` would also remove
 * a post the owner follows the author of. The delete is therefore conditioned
 * on is_boost = 1 AND boosted_by = the un-boosting actor, and both negative
 * cases are exercised here -- another actor's boost, and a non-boost row.
 *
 * NOT COVERED: relay memberships. A relayed post's presence lives in
 * snap_ap_timeline_membership and removing that is sc_relay_remove_membership()
 * on a relay retract, not this branch. Also not covered: a post that arrived as
 * a boost FIRST while the owner also follows its author -- the row goes and
 * does not return until that author posts again. Stated in the source too.
 */

$root = dirname(__DIR__);
$failures = [];

function ua_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

$source = (string)file_get_contents($root . '/core/fediverse.php');
foreach (['sv_is_direct', 'sv_reply_is_public', 'sv_activity_object_uri', 'sv_handle_inbox'] as $name) {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) { fwrite(STDERR, "missing {$name}\n"); exit(1); }
    $end  = strpos($source, "\nfunction ", $start + 1);
    $body = substr($source, $start, $end - $start);
    eval(substr($body, 0, strrpos($body, "\n}") + 2));
}

function sv_actor_url(array $settings): string { return 'https://local.invalid/ap/actor'; }
function sv_cache_actor(PDO $pdo, array $actor): void {}
function sv_url_is_ours(string $url, array $settings): bool { return false; }
function sv_notify(...$args): void {}
function sv_is_following(PDO $pdo, string $actor): bool { return true; }
function sv_resolve_target(string $url, PDO $pdo): ?array { return null; }
function sv_inbox_log(PDO $pdo, string $t, string $a, ?string $o, string $m): void {}
function sv_fetch_ap(string $url, array $settings = []): ?array { return null; }
function sv_ingest_timeline(...$args) { return null; }
function sv_capture_dm(...$args): void {}

/** A timeline holding one row, however it got there. */
function ua_pdo(string $objectId, int $isBoost, ?string $boostedBy): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_ap_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 object_id TEXT UNIQUE, is_boost INTEGER DEFAULT 0, boosted_by TEXT)');
    $pdo->exec('CREATE TABLE snap_ap_notifications (ntype TEXT, actor_url TEXT, object_id TEXT)');
    $pdo->exec('CREATE TABLE snap_ap_boosts (target_type TEXT, target_id INTEGER, actor_url TEXT)');
    $pdo->prepare('INSERT INTO snap_ap_timeline (object_id, is_boost, boosted_by) VALUES (?,?,?)')
        ->execute([$objectId, $isBoost, $boostedBy]);
    return $pdo;
}

function ua_rows(PDO $pdo, string $objectId): int {
    $q = $pdo->prepare('SELECT COUNT(*) FROM snap_ap_timeline WHERE object_id = ?');
    $q->execute([$objectId]);
    return (int)$q->fetchColumn();
}

/** The real handler, given an Undo of an Announce. */
function ua_undo(PDO $pdo, string $booster, string $objectId): void {
    $activity = [
        'type' => 'Undo',
        'actor' => $booster,
        'object' => ['type' => 'Announce', 'actor' => $booster, 'object' => $objectId],
    ];
    // sv_handle_inbox takes $settings BY REFERENCE, so it needs a variable.
    $settings = [];
    sv_handle_inbox($pdo, $settings, $activity, ['id' => $booster, 'preferredUsername' => 'booster']);
}

echo "SS-1481 #6 — an Undo of an Announce removes the boosted post\n\n";

$booster  = 'https://booster.invalid/users/b';
$other    = 'https://someoneelse.invalid/users/c';
$objectId = 'https://author.invalid/notes/1';

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE DEFECT: the boosted post is removed\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = ua_pdo($objectId, 1, $booster);
ua_check('the fixture starts with the boosted post in the reader',
         ua_rows($pdo, $objectId) === 1, $failures);
ua_undo($pdo, $booster, $objectId);
ua_check('after the Undo the boosted post is GONE from the reader',
         ua_rows($pdo, $objectId) === 0, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — AND NOTHING ELSE IS TAKEN WITH IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// The timeline is UNIQUE on object_id, so one row serves every reason a post
// might be present. These two cases are what stops the delete being blunt.
$pdo = ua_pdo($objectId, 1, $other);
ua_undo($pdo, $booster, $objectId);
ua_check("ANOTHER actor's boost of the same post is left alone",
         ua_rows($pdo, $objectId) === 1, $failures);

$pdo = ua_pdo($objectId, 0, null);
ua_undo($pdo, $booster, $objectId);
ua_check('a post present on its own merits (is_boost = 0) is left alone',
         ua_rows($pdo, $objectId) === 1, $failures);

// A different post entirely must not be touched.
$pdo = ua_pdo($objectId, 1, $booster);
$pdo->prepare('INSERT INTO snap_ap_timeline (object_id, is_boost, boosted_by) VALUES (?,1,?)')
    ->execute(['https://author.invalid/notes/2', $booster]);
ua_undo($pdo, $booster, $objectId);
ua_check('an unrelated post boosted by the same actor survives',
         ua_rows($pdo, 'https://author.invalid/notes/2') === 1, $failures);
ua_check('while the un-boosted one is gone',
         ua_rows($pdo, $objectId) === 0, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the notification and tally still go, as they always did\n";
// ─────────────────────────────────────────────────────────────────────────────
// The old behaviour was not wrong, only incomplete. It must survive the change.
$pdo = ua_pdo($objectId, 1, $booster);
$pdo->prepare("INSERT INTO snap_ap_notifications (ntype, actor_url, object_id)
               VALUES ('boost', ?, ?)")->execute([$booster, $objectId]);
ua_undo($pdo, $booster, $objectId);
$left = $pdo->prepare("SELECT COUNT(*) FROM snap_ap_notifications
                        WHERE ntype='boost' AND actor_url=? AND object_id=?");
$left->execute([$booster, $objectId]);
ua_check('the boost notification is still dropped', (int)$left->fetchColumn() === 0, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — a missing timeline table does not break the Undo\n";
// ─────────────────────────────────────────────────────────────────────────────
// Fresh installs and lagging schema syncs are real. The Undo must still answer.
$pdo = ua_pdo($objectId, 1, $booster);
$pdo->exec('DROP TABLE snap_ap_timeline');
$threw = false;
try { ua_undo($pdo, $booster, $objectId); } catch (Throwable $e) { $threw = true; }
ua_check('the Undo survives a missing timeline table', !$threw, $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #6 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #6 PASS — withdrawing a boost removes the post it pulled in, and\n";
echo "leaves another actor's boost, a post present on its own merits, and\n";
echo "unrelated posts untouched.\n";
echo "NOT COVERED: relay memberships, which belong to the relay retract path;\n";
echo "and a post that arrived as a boost first whose author the owner also\n";
echo "follows -- its row goes and returns on the next arrival.\n";
// ===== SNAPSMACK EOF =====
