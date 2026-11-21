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
// The sentinel the boost functions return and the Undo branch reads. Lifted
// from the source, not retyped, so the test cannot hold a stale copy of it.
if (preg_match("/const SV_BOOST_UNKNOWN = ('[^']*'|\"[^\"]*\");/", $source, $m)) {
    eval('const SV_BOOST_UNKNOWN = ' . $m[1] . ';');
} else {
    fwrite(STDERR, "missing SV_BOOST_UNKNOWN
"); exit(1);
}
foreach (['sv_is_direct', 'sv_reply_is_public', 'sv_activity_object_uri',
          'sv_timeline_boost_table', 'sv_timeline_boost_record',
          'sv_timeline_boost_withdraw', 'sv_handle_inbox'] as $name) {
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
// NOT STUBBED any more. Stage 6 evaluates the REAL sv_ingest_timeline() out of
// core/fediverse.php, because the boost record it writes is the thing under
// test; a no-op stub here is what let the earlier fixture build state
// production never creates.

function sv_capture_dm(...$args): void {}

/** A timeline holding one row, however it got there. */
/**
 * sqlite, taught the one piece of MySQL the boost record writes.
 *
 * INSERT IGNORE has no sqlite spelling; INSERT OR IGNORE is the same promise.
 * One translation only, so the fixture cannot quietly accept a different
 * statement later. Shim the database, not the code under test.
 */
class UaPdo extends PDO {
    public function exec(string $statement): int|false {
        // The real CREATE is MySQL with ENGINE=InnoDB. Left untranslated,
        // sqlite rejects it, sv_timeline_boost_table() caches NOT READY for
        // the whole process, and every boost record silently does nothing --
        // which is how this fixture first reported a pass on a broken fix.
        if (stripos($statement, 'snap_ap_timeline_boosts') !== false
            && stripos($statement, 'ENGINE=InnoDB') !== false) {
            $statement = 'CREATE TABLE IF NOT EXISTS snap_ap_timeline_boosts ('
                . 'object_id TEXT, actor_url TEXT, '
                . 'seen_at TEXT DEFAULT CURRENT_TIMESTAMP, '
                . 'UNIQUE(object_id, actor_url))';
        }
        return parent::exec($statement);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $query);
        // The timeline upsert. sqlite needs the conflict target named, and the
        // timeline's is object_id. Left untranslated it is a syntax error and
        // the real ingest returns "DB error" for every call, which is what the
        // first run of Stage 6 reported.
        $query = str_replace('ON DUPLICATE KEY UPDATE',
                             'ON CONFLICT(object_id) DO UPDATE SET', $query);
        $query = str_replace('`fetched_at`=NOW()', '`fetched_at`=CURRENT_TIMESTAMP', $query);
        return parent::prepare($query, $options);
    }
}

function ua_pdo(string $objectId, int $isBoost, ?string $boostedBy): PDO {
    $pdo = new UaPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_ap_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 object_id TEXT UNIQUE, is_boost INTEGER DEFAULT 0, boosted_by TEXT)');
    // Shaped like the real table (database/schema/snapsmack_canonical.sql):
    // id and created_at included, because a fixture missing a column the
    // code may order or filter on hides a fault instead of finding it.
    $pdo->exec('CREATE TABLE snap_ap_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 ntype TEXT, actor_url TEXT, object_id TEXT,
                 created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE snap_ap_boosts (target_type TEXT, target_id INTEGER, actor_url TEXT)');
    // Shaped like the canonical snap_ap_timeline_boosts. The real CREATE is
    // MySQL; sv_timeline_boost_table() caches readiness per process, so only
    // the first handle would get it anyway.
    $pdo->exec('CREATE TABLE IF NOT EXISTS snap_ap_timeline_boosts (object_id TEXT,
                 actor_url TEXT, seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
                 UNIQUE(object_id, actor_url))');
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
// THROUGH THE BOOST RECORD, because that is what production writes. These two
// stages used to build a timeline row by hand with no boost record at all, and
// after Codex's FAIL 1 that state means something specific and different: an
// upgraded install whose record has never seen the object. Stage 2a drives
// that case on purpose now; a withdrawal here has to be one the record knows
// about.
$pdo = ua_boosted_by($objectId, $booster, [$booster]);
ua_check('the fixture starts with the boosted post in the reader',
         ua_rows($pdo, $objectId) === 1, $failures);
ua_undo($pdo, $booster, $objectId);
ua_check('after the Undo the boosted post is GONE from the reader',
         ua_rows($pdo, $objectId) === 0, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — AND NOTHING ELSE IS TAKEN WITH IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// The timeline is UNIQUE on object_id, so one row serves every reason a post
// might be present. These cases are what stops the delete being blunt.
$pdo = ua_boosted_by($objectId, $other, [$other]);
ua_undo($pdo, $booster, $objectId);
ua_check("ANOTHER actor's boost of the same post is left alone",
         ua_rows($pdo, $objectId) === 1, $failures);

$pdo = ua_pdo($objectId, 0, null);
ua_undo($pdo, $booster, $objectId);
ua_check('a post present on its own merits (is_boost = 0) is left alone',
         ua_rows($pdo, $objectId) === 1, $failures);

// A different post entirely must not be touched.
$pdo = ua_boosted_by($objectId, $booster, [$booster]);
$pdo->prepare('INSERT INTO snap_ap_timeline (object_id, is_boost, boosted_by) VALUES (?,1,?)')
    ->execute(['https://author.invalid/notes/2', $booster]);
sv_timeline_boost_record($pdo, 'https://author.invalid/notes/2', $booster);
ua_undo($pdo, $booster, $objectId);
ua_check('an unrelated post boosted by the same actor survives',
         ua_rows($pdo, 'https://author.invalid/notes/2') === 1, $failures);
ua_check('while the un-boosted one is gone',
         ua_rows($pdo, $objectId) === 0, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2a — AN UPGRADED INSTALL IS NOT AN EMPTY ONE\n";
// ─────────────────────────────────────────────────────────────────────────────
// CODEX'S FAIL 1, SECOND HALF. A site upgrading into this build already has
// boosted timeline rows and an EMPTY snap_ap_timeline_boosts. Zero rows for an
// object then has two possible meanings — "the last boost was withdrawn" and
// "this record has never seen this object" — and only the first one may delete
// a post. Reading the empty record as authoritative would remove a post that
// somebody else is still boosting, on every site that upgrades.
//
// So the question the code now asks is whether the record knew about THIS
// actor's boost before the withdrawal.
$pdo = ua_pdo($objectId, 1, $booster);   // a row, and no boost record at all
ua_check('the upgraded install starts with a boosted row and no record',
         ua_rows($pdo, $objectId) === 1
         && (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts')->fetchColumn() === 0,
         $failures);
ua_undo($pdo, $booster, $objectId);
ua_check('the post is KEPT, because the record cannot say nobody is boosting',
         ua_rows($pdo, $objectId) === 1, $failures);
ua_check('  and boost ownership is left exactly as it was',
         ua_owner($pdo, $objectId) === $booster, $failures);

// The helper says so directly, which is the contract the branch depends on.
$pdo = ua_pdo($objectId, 1, $booster);
ua_check('and the helper answers "I cannot tell" rather than "nobody"',
         sv_timeline_boost_withdraw($pdo, $objectId, $booster) === SV_BOOST_UNKNOWN,
         $failures);

// Once the record HAS seen the object, it is authoritative again.
$pdo = ua_boosted_by($objectId, $booster, [$booster]);
ua_check('a recorded boost, withdrawn, answers "nobody"',
         sv_timeline_boost_withdraw($pdo, $objectId, $booster) === '', $failures);

// A TABLE THAT CANNOT BE MADE AT ALL, driven in a CHILD PROCESS.
//
// This is the exact line Codex named, and the reason my first attempt at it
// proved nothing: sv_timeline_boost_table() caches readiness in a per-process
// static, and by this point in the run it is already true. Dropping the table
// here therefore reaches the catch block, not the early return — a different
// line, with the same answer, so the check passed while the fault stood.
//
// A fresh process whose very first CREATE fails is the only way to see it. The
// probe hands the helper a PDO whose exec() throws, so readiness is false on
// the first call.
function ua_unavailable_table_answer(string $root): string {
    $dir = $root . '/data/sessions';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $probe = $dir . '/_ss1481_boosttable_' . bin2hex(random_bytes(4)) . '.php';
    $src   = var_export($root . '/core/fediverse.php', true);
    file_put_contents($probe, <<<'PHP'
<?php
$src = SRC_PLACEHOLDER;
$code = (string)file_get_contents($src);
if (preg_match("/const SV_BOOST_UNKNOWN = ('[^']*');/", $code, $m)) {
    eval('const SV_BOOST_UNKNOWN = ' . $m[1] . ';');
}
foreach (['sv_timeline_boost_table', 'sv_timeline_boost_withdraw'] as $fn) {
    $at   = strpos($code, 'function ' . $fn . '(');
    $body = substr($code, $at, strpos($code, "\nfunction ", $at + 1) - $at);
    eval(substr($body, 0, strrpos($body, "\n}") + 2));
}
/** A handle on which the CREATE can never succeed. */
class UaNoCreate extends PDO {
    public function exec(string $statement): int|false {
        throw new PDOException('CREATE refused by the fixture');
    }
}
$pdo = new UaNoCreate('sqlite::memory:', null, null,
                      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo 'ANSWER:' . sv_timeline_boost_withdraw($pdo, 'https://a.invalid/n/1',
                                            'https://b.invalid/u/1');
PHP
    );
    file_put_contents($probe, str_replace('SRC_PLACEHOLDER', $src,
        (string)file_get_contents($probe)));
    $out = [];
    exec('php ' . escapeshellarg($probe) . ' 2>&1', $out);
    @unlink($probe);
    $text = implode("\n", $out);
    $at = strpos($text, 'ANSWER:');
    return $at === false ? '[probe failed: ' . substr($text, 0, 160) . ']'
                         : substr($text, $at + 7);
}

$answer = ua_unavailable_table_answer($root);
ua_check('a boost table that cannot be CREATED answers "I cannot tell", '
         . 'never "nobody"' . ($answer === SV_BOOST_UNKNOWN ? '' : ' — got ' . var_export($answer, true)),
         $answer === SV_BOOST_UNKNOWN, $failures);


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

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — TWO LIVE BOOSTS: ONE WITHDRAWAL IS NOT THE LAST\n";
// ─────────────────────────────────────────────────────────────────────────────
// Codex's review finding 2. snap_ap_timeline is UNIQUE on object_id and keeps
// the FIRST boosted_by; duplicate ingest does not update boost ownership. So
// `is_boost = 1 AND boosted_by = <actor>` could not tell "the last boost was
// withdrawn" from "one of several was", and the first withdrawal took the post
// out of the reader while somebody else was still boosting it.
//
// The boost NOTIFICATIONS are the record of who is boosting what, so the branch
// now asks them. Both orders are driven, because they fail differently.

/**
 * A timeline row plus a BOOST RECORD for each actor given.
 *
 * Written through sv_timeline_boost_record() -- the same call
 * sv_ingest_timeline() makes on every boost ingest -- rather than by hand.
 * Codex's second review of finding 2 was that the fixture inserted
 * snap_ap_notifications rows production never creates for a remote post, so
 * the test passed around the fault. Stage 6 below drives the real ingest.
 */
function ua_boosted_by(string $objectId, string $owner, array $actors): PDO {
    $pdo = ua_pdo($objectId, 1, $owner);
    foreach ($actors as $a) { sv_timeline_boost_record($pdo, $objectId, $a); }
    return $pdo;
}

function ua_owner(PDO $pdo, string $objectId): string {
    $q = $pdo->prepare('SELECT boosted_by FROM snap_ap_timeline WHERE object_id = ?');
    $q->execute([$objectId]);
    return (string)($q->fetchColumn() ?: '');
}

// THE ROW'S OWN BOOSTER GOES FIRST. This is the order that used to delete the
// post out from under the other booster.
$pdo = ua_boosted_by($objectId, $booster, [$booster, $other]);
ua_undo($pdo, $booster, $objectId);
ua_check('the post STAYS while another actor is still boosting it',
         ua_rows($pdo, $objectId) === 1, $failures);
ua_check('  and the row now names the actor who IS still boosting',
         ua_owner($pdo, $objectId) === $other, $failures);
ua_undo($pdo, $other, $objectId);
ua_check('  and when that last boost is withdrawn, the post goes',
         ua_rows($pdo, $objectId) === 0, $failures);

// THE OTHER ORDER. Here the row keeps naming its original booster throughout,
// so the only question is whether the final withdrawal still matches it.
$pdo = ua_boosted_by($objectId, $booster, [$booster, $other]);
ua_undo($pdo, $other, $objectId);
ua_check('a non-owner withdrawing leaves the post, and the row, alone',
         ua_rows($pdo, $objectId) === 1 && ua_owner($pdo, $objectId) === $booster, $failures);
ua_undo($pdo, $booster, $objectId);
ua_check('  and the owner withdrawing last takes the post with it',
         ua_rows($pdo, $objectId) === 0, $failures);

// THREE BOOSTS, so "one left" is not quietly "exactly two".
$third = 'https://third.invalid/users/d';
$pdo = ua_boosted_by($objectId, $booster, [$booster, $other, $third]);
ua_undo($pdo, $booster, $objectId);
ua_undo($pdo, ua_owner($pdo, $objectId), $objectId);
ua_check('with three boosters the post survives two withdrawals',
         ua_rows($pdo, $objectId) === 1, $failures);
ua_undo($pdo, ua_owner($pdo, $objectId), $objectId);
ua_check('  and goes on the third', ua_rows($pdo, $objectId) === 0, $failures);

// A missing notifications table must not strand the post: the Undo still has
// to answer, and the branch is wrapped for exactly that.
$pdo = ua_pdo($objectId, 1, $booster);
$pdo->exec('DROP TABLE snap_ap_notifications');
$threw = false;
try { ua_undo($pdo, $booster, $objectId); } catch (Throwable $e) { $threw = true; }
ua_check('the Undo survives a missing notifications table', !$threw, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 6 — THE ORDINARY READER CASE, THROUGH THE REAL INGEST\n";
// ─────────────────────────────────────────────────────────────────────────────
// CODEX'S SECOND REVIEW OF FINDING 2, and the reason this stage exists rather
// than another hand-built fixture.
//
// My first repair decided "is anyone else still boosting this" by reading boost
// rows out of snap_ap_notifications. Those rows are written ONLY when somebody
// boosts one of THIS site's own posts: sv_notify() sits inside the
// sv_url_is_ours branch of the Announce handler. The ordinary reader case -- a
// REMOTE post boosted by two accounts the owner follows -- creates no
// notification at all. So the first Undo saw no other booster and deleted the
// row while the second boost was still live, and my fixture passed around that
// by inserting the notification rows production never writes.
//
// So this drives the real sv_ingest_timeline(), with sv_url_is_ours() false --
// a remote post, which is the case that was broken -- and asserts on what the
// handler actually leaves behind.
$ua_src   = (string)file_get_contents($root . '/core/fediverse.php');
$ua_start = strpos($ua_src, 'function sv_ingest_timeline(');
$ua_chunk = substr($ua_src, $ua_start, strpos($ua_src, "\nfunction ", $ua_start + 1) - $ua_start);

// The pieces sv_ingest_timeline() leans on, stubbed to the minimum that lets
// the real body run. sv_timeline_has_col answers true so the column list is
// the full one.
if (!function_exists('sv_timeline_has_col')) {
    function sv_timeline_has_col(PDO $pdo, string $col): bool { return true; }
}
if (!function_exists('sv_timeline_has_video_col')) {
    function sv_timeline_has_video_col(PDO $pdo): bool { return true; }
}
// The only other helper the real body calls. Its answer is not what this stage
// is about, so it gives the shape the insert expects and nothing more.
// The only other helper the real body calls, and it is destructured as
// [$audience, $visibility] -- a keyed array gave "Undefined array key 0" and
// four silent failures. Its answer is not what this stage is about.
if (!function_exists('sv_timeline_audience')) {
    function sv_timeline_audience(array $obj): array {
        return [[], 'public'];
    }
}
eval(substr($ua_chunk, 0, strrpos($ua_chunk, "\n}") + 2));

/** A timeline the real ingest can write into, with no rows to begin with. */
function ua_empty_pdo(): PDO {
    $pdo = new UaPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_ap_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 object_id TEXT UNIQUE, actor_url TEXT, actor_handle TEXT,
                 content TEXT, summary TEXT, visibility TEXT DEFAULT "public",
                 audience_json TEXT, media_json TEXT, media_video_json TEXT,
                 attachments_json TEXT, tags_json TEXT, url TEXT, in_reply_to TEXT,
                 sensitive INTEGER DEFAULT 0, is_boost INTEGER DEFAULT 0,
                 boosted_by TEXT, source TEXT DEFAULT "home", published TEXT,
                 fetched_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE snap_ap_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 ntype TEXT, actor_url TEXT, object_id TEXT,
                 created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE snap_ap_boosts (target_type TEXT, target_id INTEGER, actor_url TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS snap_ap_timeline_boosts (object_id TEXT,
                 actor_url TEXT, seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
                 UNIQUE(object_id, actor_url))');
    return $pdo;
}

$remote  = 'https://author.invalid/notes/remote-1';
// WITH AN IMAGE ATTACHMENT, because sv_ingest_timeline() skips an object with
// nothing renderable -- "skipped: nothing renderable (no image attachment)".
// A note without one never reaches the boost record, and the first run of this
// stage failed on exactly that.
$note    = ['type' => 'Note', 'id' => $remote, 'content' => 'a photograph',
            'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg',
                              'url' => 'https://author.invalid/i.jpg']]];
$pdo     = ua_empty_pdo();

// Two followed accounts boost the same remote post. This is the ONLY way the
// row gets into the reader, and neither call writes a notification.
$fail1 = sv_ingest_timeline($pdo, $note, 'https://author.invalid/users/a', 'a@author.invalid',
                            true, $booster);
$fail2 = sv_ingest_timeline($pdo, $note, 'https://author.invalid/users/a', 'a@author.invalid',
                            true, $other);
ua_check('the real ingest accepted the boosted post',
         $fail1 === null && $fail2 === null, $failures);
ua_check('  it is in the reader once', ua_rows($pdo, $remote) === 1, $failures);
ua_check('  and NO boost notification exists, which is the production truth '
         . 'my first repair depended on',
         (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_notifications WHERE ntype='boost'")
                  ->fetchColumn() === 0, $failures);
ua_check('  while BOTH boosters were recorded',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts')->fetchColumn() === 2,
         $failures);
ua_check('  with the row naming the first of them',
         ua_owner($pdo, $remote) === $booster, $failures);

// THE FAULT CODEX FOUND: the first Undo used to delete this row.
ua_undo($pdo, $booster, $remote);
ua_check('the first withdrawal LEAVES the post, because the other boost is live',
         ua_rows($pdo, $remote) === 1, $failures);
ua_check('  the withdrawing actor is forgotten',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts')->fetchColumn() === 1,
         $failures);
ua_check('  and ownership moved to the actor still boosting',
         ua_owner($pdo, $remote) === $other, $failures);

ua_undo($pdo, $other, $remote);
ua_check('the LAST withdrawal removes the post', ua_rows($pdo, $remote) === 0, $failures);
ua_check('  and leaves no boost records behind',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts')->fetchColumn() === 0,
         $failures);

// A post that arrived on its own merits records no booster, so an unrelated
// Undo cannot touch it.
$pdo  = ua_empty_pdo();
$own  = 'https://author.invalid/notes/remote-2';
sv_ingest_timeline($pdo, ['type' => 'Note', 'id' => $own, 'content' => 'x',
                    'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg',
                                      'url' => 'https://author.invalid/j.jpg']]],
                   'https://author.invalid/users/a', 'a@author.invalid', false, null);
ua_check('a post ingested without a boost records no booster',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts')->fetchColumn() === 0,
         $failures);
ua_undo($pdo, $booster, $own);
ua_check('  and an unrelated withdrawal leaves it alone',
         ua_rows($pdo, $own) === 1, $failures);

// TWO OBJECTS AT ONCE. A withdrawal on one post must not see the other post's
// booster and keep a row alive for the wrong reason. The record is per
// (object, actor), and a query that forgot the object would pass every check
// above -- it survived the first mutation round for exactly that reason.
$pdo  = ua_empty_pdo();
$one  = 'https://author.invalid/notes/pair-1';
$two  = 'https://author.invalid/notes/pair-2';
foreach ([$one, $two] as $oid) {
    sv_ingest_timeline($pdo, ['type' => 'Note', 'id' => $oid, 'content' => 'x',
                        'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg',
                                          'url' => 'https://author.invalid/k.jpg']]],
                       'https://author.invalid/users/a', 'a@author.invalid', true,
                       $oid === $one ? $booster : $other);
}
ua_check('two posts, one booster each', ua_rows($pdo, $one) === 1 && ua_rows($pdo, $two) === 1,
         $failures);
ua_undo($pdo, $booster, $one);
ua_check('withdrawing the only boost of ONE post removes that post',
         ua_rows($pdo, $one) === 0, $failures);
ua_check('  and leaves the other post alone', ua_rows($pdo, $two) === 1, $failures);
ua_check('  with the other post still naming its own booster',
         ua_owner($pdo, $two) === $other, $failures);

// A NON-BOOST INGEST RECORDS NOTHING, even when handed a booster. is_boost is
// what makes a row a boost row; boosted_by alone must not create a record that
// an Undo would later honour.
$pdo = ua_empty_pdo();
sv_ingest_timeline($pdo, ['type' => 'Note', 'id' => 'https://author.invalid/notes/plain',
                    'content' => 'x',
                    'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg',
                                      'url' => 'https://author.invalid/l.jpg']]],
                   'https://author.invalid/users/a', 'a@author.invalid', false, $booster);
ua_check('a non-boost ingest records no booster even when given one',
         (int)$pdo->query('SELECT COUNT(*) FROM snap_ap_timeline_boosts')->fetchColumn() === 0,
         $failures);

// AND AN UNREADABLE RECORD KEEPS THE POST. "I cannot tell" must not mean
// "delete": leaving a post in the reader is recoverable, removing one is not.
$pdo = ua_empty_pdo();
sv_ingest_timeline($pdo, $note, 'https://author.invalid/users/a', 'a@author.invalid',
                   true, $booster);
$pdo->exec('DROP TABLE snap_ap_timeline_boosts');
$threw = false;
try { ua_undo($pdo, $booster, $remote); } catch (Throwable $e) { $threw = true; }
ua_check('an unreadable boost record does not throw', !$threw, $failures);
ua_check('  and the post is KEPT rather than guessed away',
         ua_rows($pdo, $remote) === 1, $failures);
ua_check('  with boost ownership left exactly as it was',
         ua_owner($pdo, $remote) === $booster, $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #6 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #6 PASS — withdrawing a boost removes the post it pulled in, and
";
echo "leaves another actor's boost, a post present on its own merits, and
";
echo "unrelated posts untouched. A boost withdrawn while OTHERS are still live
";
echo "leaves the post, and moves the row's boost ownership to an actor who is
";
echo "still boosting, so the LAST withdrawal still matches and still removes it.
";
echo "NOT COVERED: relay memberships, which belong to the relay retract path; a
";
echo "post that arrived as a boost first whose author the owner also follows --
";
echo "its row goes and returns on the next arrival; and a boost whose
";
echo "notification was never written or has since been pruned, which reads here
";
echo "as not boosting. snap_ap_boosts would answer that, but it holds local
";
echo "targets only.
";
// ===== SNAPSMACK EOF =====
