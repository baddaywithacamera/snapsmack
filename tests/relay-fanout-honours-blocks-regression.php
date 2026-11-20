<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 5 — a block stops relay fan-out, and retraction is paged.
 *
 * THE FAULT. sc_relay_join() consults snap_relay_blocklist once, when a member
 * joins. sc_relay_actor_blocked() is consulted on RECEIVE. Neither was
 * consulted on the way OUT: sc_relay_fanout() and sc_relay_refresh() asked only
 * whether the actor was a relay source. So a domain blocklisted AFTER it joined,
 * or an actor added to snap_ap_blocks, kept being broadcast to every member of
 * the relay until its subscriber row happened to change state.
 *
 * That is a defederation problem, not a tidiness one: the hub keeps amplifying
 * an actor the site has already said it wants nothing to do with.
 *
 * VERIFIED AT THE TAG BEFORE FIXING. All of SS-1481's findings were read by
 * Muse on 38c519d1e, an older ancestor; this one was re-confirmed present at
 * caebe17b (v0.7.1000D) before a line was changed.
 *
 * WHAT THIS PROVES. It calls the real sc_relay_fanout() against a sqlite
 * fixture and counts what was queued for delivery. Not that a function is
 * called -- what comes out the other end. Three states matter and all three are
 * exercised: an unblocked source relays, an actor-blocked source does not, a
 * domain-blocklisted source does not.
 *
 * NOT COVERED: real HTTP delivery, and the admin control that would record a
 * block. Nothing in the repo INSERTs into snap_relay_blocklist or
 * snap_ap_blocks today -- both are read-only in code -- so the retraction
 * helper has no automatic trigger yet. That is stated in the source and here
 * rather than worked around with an invented admin page.
 */

$root = dirname(__DIR__);
$failures = [];

function rb_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

// The relay policy file leans on helpers from fediverse.php. Only a handful are
// needed to drive fan-out, and stubbing exactly those keeps this test off the
// network while the code under test stays the real thing.
if (!function_exists('sv_actor_url')) {
    function sv_actor_url(array $settings): string { return 'https://hub.invalid/ap/actor'; }
}
if (!function_exists('sv_url_is_public')) {
    function sv_url_is_public(string $url): bool { return str_starts_with($url, 'https://'); }
}
if (!function_exists('sv_relay_actor_url')) {
    function sv_relay_actor_url(array $settings): string { return 'https://hub.invalid/ap/actor'; }
}
if (!function_exists('sv_is_following')) {
    function sv_is_following(PDO $pdo, string $actor): bool { return true; }
}
if (!function_exists('sv_fetch_ap')) {
    function sv_fetch_ap(string $url, array $settings): ?array { return null; }
}
if (!function_exists('sv_ingest_timeline')) {
    function sv_ingest_timeline(...$args): void {}
}

/** Record every queued delivery instead of sending one. */
$GLOBALS['rb_queued'] = [];
if (!function_exists('sv_queue_delivery')) {
    function sv_queue_delivery(PDO $pdo, string $inbox, string $json, string $dedupe): int {
        $GLOBALS['rb_queued'][] = ['inbox' => $inbox, 'json' => $json, 'dedupe' => $dedupe];
        return 1;
    }
}

require_once $root . '/core/smackcast-relay.php';

/** A fixture hub: one active member, one relay source, the block tables empty. */
function rb_pdo(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_relay_subscribers (actor_url TEXT, domain TEXT,
                 inbox_url TEXT, shared_inbox_url TEXT, follow_id TEXT, state TEXT,
                 last_seen_at TEXT)');
    $pdo->exec('CREATE TABLE snap_relay_intake (id INTEGER PRIMARY KEY AUTOINCREMENT,
                 activity_id TEXT UNIQUE, object_id TEXT, origin_actor_url TEXT)');
    $pdo->exec("CREATE TABLE snap_ap_blocks (kind TEXT, value TEXT)");
    $pdo->exec('CREATE TABLE snap_relay_blocklist (domain TEXT)');
    $pdo->exec('CREATE TABLE snap_relay_allowlist (domain TEXT)');
    // A curator-directory source is ALSO a relay source (sc_relay_actor_is_source)
    // and resolves to the GLOBAL feed, which is the case a local-only fixture
    // cannot see. A mutation that hardcoded the refresh feed to 'local'
    // SURVIVED until these two tables were here.
    $pdo->exec('CREATE TABLE snap_curator_directory (actor_url TEXT, follow_row_id INTEGER, state TEXT)');
    $pdo->exec('CREATE TABLE snap_ap_following (id INTEGER PRIMARY KEY, state TEXT)');
    $pdo->exec("INSERT INTO snap_relay_subscribers
                 (actor_url, domain, inbox_url, state)
                 VALUES ('https://member.invalid/ap/actor', 'member.invalid',
                         'https://member.invalid/ap/inbox', 'active')");
    return $pdo;
}

/** An ordinary public Note from the source, shaped as fan-out expects. */
function rb_activity(string $source, string $objectId): array {
    return [
        'id' => $objectId . '#create',
        'type' => 'Create',
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'object' => [
            'id' => $objectId,
            'type' => 'Note',
            'attributedTo' => $source,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ],
    ];
}

echo "SS-1481 #5 — a block stops relay fan-out\n\n";

$source = 'https://source.invalid/ap/actor';
$settings = ['smackcast_admission_mode' => 'open'];

// sc_relay_actor_is_source decides whether this actor is a relay source at all.
// It is not the subject of this test, so it is satisfied the way the real hub
// satisfies it rather than stubbed away: the source is an active subscriber.
function rb_add_source(PDO $pdo, string $source): void {
    $pdo->prepare("INSERT INTO snap_relay_subscribers (actor_url, domain, inbox_url, state)
                   VALUES (?, ?, ?, 'active')")
        ->execute([$source, strtolower((string)parse_url($source, PHP_URL_HOST)),
                   'https://source.invalid/ap/inbox']);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the control: an unblocked source IS relayed\n";
// ─────────────────────────────────────────────────────────────────────────────
// Without this the whole file would pass on a fan-out that relays nothing at
// all, which is the direction this repair can fail in.
$pdo = rb_pdo();
rb_add_source($pdo, $source);
$GLOBALS['rb_queued'] = [];
$queued = sc_relay_fanout($pdo, $settings, rb_activity($source, 'https://source.invalid/ap/note/1'), $source);
rb_check('an unblocked source is relayed to the member',
         $queued >= 1 && count($GLOBALS['rb_queued']) >= 1, $failures);
rb_check('and the queued delivery went to the member inbox, not the source',
         ($GLOBALS['rb_queued'][0]['inbox'] ?? '') === 'https://member.invalid/ap/inbox', $failures);
$sourceRelays = $queued;

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — AN ACTOR BLOCK STOPS FAN-OUT\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = rb_pdo();
rb_add_source($pdo, $source);
$pdo->prepare("INSERT INTO snap_ap_blocks (kind, value) VALUES ('actor', ?)")->execute([$source]);
$GLOBALS['rb_queued'] = [];
$blockedQueued = sc_relay_fanout($pdo, $settings, rb_activity($source, 'https://source.invalid/ap/note/2'), $source);
rb_check('a blocked ACTOR is not relayed',
         $blockedQueued === 0 && $GLOBALS['rb_queued'] === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — A DOMAIN BLOCK, AND THE RELAY'S OWN BLOCKLIST\n";
// ─────────────────────────────────────────────────────────────────────────────
// Two separate tables, two separate ways to say no. snap_ap_blocks is the
// site's fediverse block list; snap_relay_blocklist is the relay's own, the one
// sc_relay_join reads. The old code consulted neither on the way out.
$pdo = rb_pdo();
rb_add_source($pdo, $source);
$pdo->prepare("INSERT INTO snap_ap_blocks (kind, value) VALUES ('domain', ?)")
    ->execute(['source.invalid']);
$GLOBALS['rb_queued'] = [];
rb_check('a blocked DOMAIN is not relayed',
         sc_relay_fanout($pdo, $settings, rb_activity($source, 'https://source.invalid/ap/note/3'), $source) === 0
         && $GLOBALS['rb_queued'] === [], $failures);

$pdo = rb_pdo();
rb_add_source($pdo, $source);
$pdo->prepare("INSERT INTO snap_relay_blocklist (domain) VALUES (?)")->execute(['source.invalid']);
$GLOBALS['rb_queued'] = [];
rb_check("the relay's own blocklist is honoured on the way out, not only at join",
         sc_relay_fanout($pdo, $settings, rb_activity($source, 'https://source.invalid/ap/note/4'), $source) === 0
         && $GLOBALS['rb_queued'] === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — AN UPDATE IS A BROADCAST TOO\n";
// ─────────────────────────────────────────────────────────────────────────────
// sc_relay_refresh re-announces an edited post to every member. Gating fan-out
// and leaving refresh open would have left the hole open for any blocked actor
// who edits a post.
$pdo = rb_pdo();
rb_add_source($pdo, $source);
$pdo->prepare("INSERT INTO snap_ap_blocks (kind, value) VALUES ('actor', ?)")->execute([$source]);
$GLOBALS['rb_queued'] = [];
$update = rb_activity($source, 'https://source.invalid/ap/note/5');
$update['type'] = 'Update';
rb_check('a blocked actor\'s UPDATE is not re-announced',
         sc_relay_refresh($pdo, $settings, $update, $source) === 0
         && $GLOBALS['rb_queued'] === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — FAIL CLOSED when the blocklist cannot be read\n";
// ─────────────────────────────────────────────────────────────────────────────
// Relaying outward is the move that cannot be taken back, so an unreadable
// blocklist must refuse rather than broadcast. Proven by dropping the table.
$pdo = rb_pdo();
rb_add_source($pdo, $source);
$pdo->exec('DROP TABLE snap_relay_blocklist');
$GLOBALS['rb_queued'] = [];
rb_check('an unreadable blocklist refuses to relay rather than broadcasting',
         sc_relay_fanout($pdo, $settings, rb_activity($source, 'https://source.invalid/ap/note/6'), $source) === 0
         && $GLOBALS['rb_queued'] === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 6 — RETRACTION PAGES, rather than stopping at a fixed slice\n";
// ─────────────────────────────────────────────────────────────────────────────
// The companion half: stopping future fan-out does nothing about posts already
// in every member's reader. 250 intake rows with a page size of 100 needs three
// passes; a fixed LIMIT would retract part of the actor's output and call it
// done. This also exercises the paging that finding 13 asks for on the
// actor-Delete path.
$pdo = rb_pdo();
rb_add_source($pdo, $source);
$insert = $pdo->prepare("INSERT INTO snap_relay_intake (activity_id, object_id, origin_actor_url)
                         VALUES (?, ?, ?)");
for ($i = 1; $i <= 250; $i++) {
    $insert->execute(["act-{$i}", "https://source.invalid/ap/note/{$i}", $source]);
}
$GLOBALS['rb_queued'] = [];
$retracted = sc_relay_retract_actor($pdo, $settings, $source, 'https://source.invalid/ap/delete', 100);
rb_check('every one of the 250 relayed objects is retracted, not the first page',
         $retracted === 250, $failures);
rb_check('and an Undo went out for each',
         count($GLOBALS['rb_queued']) === 250, $failures);
rb_check('the Undo really is an Undo of an Announce',
         str_contains($GLOBALS['rb_queued'][0]['json'] ?? '', '"type":"Undo"')
         && str_contains($GLOBALS['rb_queued'][0]['json'] ?? '', '"type":"Announce"'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "
STAGE 7 — AN UPDATE KEEPS THE POST'S FEED (SS-1481 #3)
";
// ─────────────────────────────────────────────────────────────────────────────
// sc_relay_refresh used to build its Announce with no feed key, while
// sc_relay_fanout tags its own. An untagged Announce is read as LOCAL by
// sc_relay_announce_feed() -- deliberately, for pre-681D hubs -- so one edit to
// a GLOBAL post added a local membership and the post turned up in a tab it was
// never meant to be in.
//
// sc_relay_origin_feed() returns 'local' for an ACTIVE subscriber and 'global'
// for anything else, so the two cases below are an active member and an actor
// with no subscriber row.
$pdo = rb_pdo();
rb_add_source($pdo, $source);
$GLOBALS['rb_queued'] = [];
$upd = rb_activity($source, 'https://source.invalid/ap/note/7');
$upd['type'] = 'Update';
sc_relay_refresh($pdo, $settings, $upd, $source);
$localJson = $GLOBALS['rb_queued'][0]['json'] ?? '';
rb_check('an Update from an active member is tagged local, not left untagged',
         str_contains($localJson, '"feed":"local"'), $failures);
rb_check('and it declares the photoblogs feed term so a strict consumer keeps it',
         str_contains($localJson, 'photoblogs:feed'), $failures);
rb_check('the tag matches what fan-out would have put on the same source',
         str_contains($localJson, '"feed":"' . sc_relay_origin_feed($pdo, $source) . '"'),
         $failures);

// A GLOBAL source: curator-followed, no active subscriber row of its own.
$pdo = rb_pdo();
$pdo->prepare("INSERT INTO snap_relay_subscribers (actor_url, domain, inbox_url, state)
               VALUES (?, ?, ?, 'pending')")
    ->execute(['https://curator.invalid/ap/actor', 'curator.invalid',
               'https://curator.invalid/ap/inbox']);
rb_check('a non-member source resolves to the global feed',
         sc_relay_origin_feed($pdo, 'https://curator.invalid/ap/actor') === 'global', $failures);

// A GLOBAL source that is a real relay source: curator-followed, not a member.
// This is the case that makes the feed tag matter. Without it, 'local' can be
// hardcoded and every check above still passes.
$curator = 'https://curator.invalid/ap/actor';
$pdo = rb_pdo();
$pdo->exec("INSERT INTO snap_ap_following (id, state) VALUES (1, 'accepted')");
$pdo->prepare("INSERT INTO snap_curator_directory (actor_url, follow_row_id, state)
               VALUES (?, 1, 'following')")->execute([$curator]);
rb_check('a curator-followed actor IS a relay source',
         sc_relay_actor_is_source($pdo, $curator), $failures);
rb_check('and resolves to the GLOBAL feed',
         sc_relay_origin_feed($pdo, $curator) === 'global', $failures);

$GLOBALS['rb_queued'] = [];
$gupd = rb_activity($curator, 'https://curator.invalid/ap/note/9');
$gupd['type'] = 'Update';
$gupd['object']['attributedTo'] = $curator;
sc_relay_refresh($pdo, $settings, $gupd, $curator);
$globalJson = $GLOBALS['rb_queued'][0]['json'] ?? '';
rb_check('an Update to a GLOBAL post is tagged global, not local',
         str_contains($globalJson, '"feed":"global"'), $failures);
rb_check('so an edit cannot move a GLOBAL post into the LOCAL tab',
         !str_contains($globalJson, '"feed":"local"'), $failures);

// An actor with nothing relayed retracts nothing, rather than erroring.
$pdo = rb_pdo();
$GLOBALS['rb_queued'] = [];
rb_check('an actor with nothing relayed retracts nothing',
         sc_relay_retract_actor($pdo, $settings, 'https://nobody.invalid/ap/actor') === 0, $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #5 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #5 PASS — a blocked actor or domain is not relayed, on Create or\n";
echo "Update; an unreadable blocklist refuses rather than broadcasting; and\n";
echo "retraction pages through every relayed object.\n";
echo "NOT COVERED: real HTTP delivery, and the admin control that records a\n";
echo "block -- nothing in the repo writes either block table today, so the\n";
echo "retraction helper has no automatic trigger yet.\n";
// ===== SNAPSMACK EOF =====
