<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 9 — a relay Announce does not re-fetch an actor it just saw.
 *
 * THE FAULT. sc_relay_receive_announce() fetched the attributed actor's
 * document to establish one thing: that the actor resolves and its id is what
 * it claims. The document was then DISCARDED -- it is not passed to the ingest.
 * snap_ap_actors is written on every ingest and never read back, so on a relay
 * with traffic that was one network round trip per Announce to re-answer a
 * question already answered minutes earlier.
 *
 * Re-verified present at caebe17b before changing anything.
 *
 * WHY THE SHORTCUT IS NARROW, and the test holds it to that: the cache stores a
 * SUBSET of the actor document -- handle, name, avatar, inboxes, summary,
 * profile url, migration metadata -- and NOT publicKey. It can never stand in
 * for a fetch whose result is used in signature verification. The only question
 * it answers is liveness.
 *
 * THE COST, tested rather than hidden: a cache hit skips a liveness check, so
 * for up to the TTL a just-deleted actor's post can still be ingested. The TTL
 * is therefore minutes. Outside it, the fetch happens as before.
 */

$root = dirname(__DIR__);
$failures = [];

function rc_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

/** Count fetches so the saving is measured, not assumed. */
$GLOBALS['rc_fetches'] = [];
function sv_fetch_ap(string $url, ?array $settings = null): ?array {
    $GLOBALS['rc_fetches'][] = $url;
    return $GLOBALS['rc_docs'][$url] ?? null;
}
function sv_actor_url(array $settings): string { return 'https://hub.invalid/ap/actor'; }
function sv_relay_actor_url(array $settings): string { return 'https://hub.invalid/ap/actor'; }
function sv_is_following(PDO $pdo, string $actor): bool { return true; }
function sv_url_is_public(string $url): bool { return str_starts_with($url, 'https://'); }
function sv_queue_delivery(PDO $pdo, string $i, string $j, string $d): int { return 1; }
function sv_cache_actor(PDO $pdo, array $doc): void { $GLOBALS['rc_cached'][] = $doc['id'] ?? ''; }
function sv_ingest_timeline(...$args) { $GLOBALS['rc_ingested'][] = $args[1]['id'] ?? ''; return null; }

$src = (string)file_get_contents($root . '/core/fediverse.php');
$start = strpos($src, 'function sv_actor_seen_recently(');
if ($start === false) { fwrite(STDERR, "missing sv_actor_seen_recently\n"); exit(1); }
$end = strpos($src, "\nfunction ", $start + 1);
$body = substr($src, $start, $end - $start);
eval(substr($body, 0, strrpos($body, "\n}") + 2));

require_once $root . '/core/smackcast-relay.php';

$actor    = 'https://source.invalid/ap/actor';
$objectId = 'https://source.invalid/ap/note/1';
$relay    = 'https://hub.invalid/ap/actor';

/**
 * sqlite, minus the one thing it cannot parse.
 *
 * sc_relay_receive_announce() calls sc_relay_ensure_ingest_jobs(), which runs a
 * MySQL CREATE TABLE with AUTO_INCREMENT. The fixture creates that table
 * itself, so the self-heal is a no-op here -- but sqlite throws on the syntax
 * rather than ignoring it. Swallowing exactly that statement keeps the code
 * under test unmodified, which is the point: shim the database, not the code.
 */
class RcPdo extends PDO {
    public function exec(string $statement): int|false {
        if (stripos($statement, 'AUTO_INCREMENT') !== false) return 0;
        return parent::exec($statement);
    }
}

function rc_pdo(): PDO {
    $pdo = new RcPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_ap_actors (actor_url TEXT UNIQUE, fetched_at TEXT)');
    $pdo->exec('CREATE TABLE snap_ap_blocks (kind TEXT, value TEXT)');
    $pdo->exec('CREATE TABLE snap_relay_ingest_jobs (relay_actor_url TEXT, object_id TEXT,
                 reason TEXT, feed TEXT, attempts INTEGER, next_try_at TEXT)');
    return $pdo;
}
function rc_seen(PDO $pdo, string $actor, int $secondsAgo): void {
    $pdo->prepare('INSERT INTO snap_ap_actors (actor_url, fetched_at) VALUES (?, ?)')
        ->execute([$actor, date('Y-m-d H:i:s', time() - $secondsAgo)]);
}

echo "SS-1481 #9 — a relay Announce reuses a recent actor cache row\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the helper's window\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = rc_pdo();
rc_check('an actor never seen is not "seen recently"',
         sv_actor_seen_recently($pdo, $actor, 900) === false, $failures);
rc_seen($pdo, $actor, 60);
rc_check('seen a minute ago, within a 15-minute window, is recent',
         sv_actor_seen_recently($pdo, $actor, 900) === true, $failures);
rc_check('and the same row is NOT recent against a 30-second window',
         sv_actor_seen_recently($pdo, $actor, 30) === false, $failures);

$pdo2 = rc_pdo();
rc_seen($pdo2, $actor, 7200);
rc_check('seen two hours ago is not recent',
         sv_actor_seen_recently($pdo2, $actor, 900) === false, $failures);
rc_check('a zero or negative TTL never shortcuts',
         sv_actor_seen_recently($pdo2, $actor, 0) === false, $failures);

$pdo3 = rc_pdo();
$pdo3->exec('DROP TABLE snap_ap_actors');
rc_check('no cache table means no shortcut, rather than an error',
         sv_actor_seen_recently($pdo3, $actor, 900) === false, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE SAVING, COUNTED\n";
// ─────────────────────────────────────────────────────────────────────────────
// Two fetches on a cold cache (the object, then the actor); one on a warm one.
$GLOBALS['rc_docs'] = [
    $objectId => ['id' => $objectId, 'type' => 'Note',
                  'attributedTo' => $actor,
                  'to' => ['https://www.w3.org/ns/activitystreams#Public']],
    $actor    => ['id' => $actor, 'type' => 'Person'],
];

$pdo = rc_pdo();
$GLOBALS['rc_fetches'] = []; $GLOBALS['rc_ingested'] = []; $GLOBALS['rc_cached'] = [];
$settings = [];
sc_relay_receive_announce($pdo, $settings, $relay, $objectId, 'local');
$cold = $GLOBALS['rc_fetches'];
rc_check('COLD: the actor document is fetched',
         in_array($actor, $cold, true), $failures);
rc_check('and the post is ingested',
         $GLOBALS['rc_ingested'] === [$objectId], $failures);
rc_check('and the actor is written to the cache, so the next one can skip it',
         in_array($actor, $GLOBALS['rc_cached'], true), $failures);

$pdo = rc_pdo();
rc_seen($pdo, $actor, 60);
$GLOBALS['rc_fetches'] = []; $GLOBALS['rc_ingested'] = [];
sc_relay_receive_announce($pdo, $settings, $relay, $objectId, 'local');
rc_check('WARM: the actor document is NOT fetched again',
         !in_array($actor, $GLOBALS['rc_fetches'], true), $failures);
rc_check('the object itself IS still fetched — only the actor lookup is saved',
         in_array($objectId, $GLOBALS['rc_fetches'], true), $failures);
rc_check('and the post is still ingested',
         $GLOBALS['rc_ingested'] === [$objectId], $failures);
rc_check('so a warm Announce costs one fetch instead of two',
         count($GLOBALS['rc_fetches']) === 1 && count($cold) === 2, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — A STALE CACHE DOES NOT WAVE THROUGH A BAD ACTOR\n";
// ─────────────────────────────────────────────────────────────────────────────
// Outside the window the fetch happens, so an actor that has since stopped
// resolving is refused exactly as before. This is the half of the trade-off
// that has to keep working.
$pdo = rc_pdo();
rc_seen($pdo, $actor, 7200);                       // last seen two hours ago
unset($GLOBALS['rc_docs'][$actor]);                // and it no longer resolves
$GLOBALS['rc_fetches'] = []; $GLOBALS['rc_ingested'] = [];
sc_relay_receive_announce($pdo, $settings, $relay, $objectId, 'local');
rc_check('an actor last seen outside the window IS re-fetched',
         in_array($actor, $GLOBALS['rc_fetches'], true), $failures);
rc_check('and a post from an actor that no longer resolves is NOT ingested',
         $GLOBALS['rc_ingested'] === [], $failures);

// An id that disagrees with the actor it claims is still refused.
$pdo = rc_pdo();
$GLOBALS['rc_docs'][$actor] = ['id' => 'https://someoneelse.invalid/ap/actor'];
$GLOBALS['rc_fetches'] = []; $GLOBALS['rc_ingested'] = [];
sc_relay_receive_announce($pdo, $settings, $relay, $objectId, 'local');
rc_check('an actor document whose id does not match is refused',
         $GLOBALS['rc_ingested'] === [], $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #9 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #9 PASS — a warm relay Announce costs one fetch instead of two,\n";
echo "and outside the window the actor is re-fetched and still refused if it no\n";
echo "longer resolves or its id disagrees.\n";
echo "NOT COVERED: real HTTP, and the count fetches in sv_crawl_actor -- those\n";
echo "counts ARE displayed (assets/js/ss-pixel.js:342, ss-pixelfed-client.js:411)\n";
echo "so that half of the finding does not hold and nothing was removed.\n";
// ===== SNAPSMACK EOF =====
