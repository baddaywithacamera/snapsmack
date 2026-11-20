<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 13 — a self-deleting actor is retracted in full, not 500 of it.
 *
 * THE FAULT. When a relay member deleted its own account, the hub retracted the
 * objects it had relayed with a flat `LIMIT 500`. An actor that had relayed more
 * than 500 posts left the remainder sitting in every member's reader: the posts
 * of an account that no longer exists, with no further Delete coming to clean
 * them up.
 *
 * Re-verified present at caebe17b before changing anything -- the LIMIT 500 was
 * exactly where Muse reported it.
 *
 * THE FIX REUSES sc_relay_retract_actor(), written for finding 5's block
 * retraction, which pages snap_relay_intake and calls the same
 * sc_relay_retract() the capped loop called. Behaviour per object is unchanged;
 * only the ceiling is gone.
 *
 * WHAT THIS PROVES: the real Delete path is driven with 620 relayed objects --
 * past the old cap, and not a round multiple of the page size, so an off-by-one
 * in the paging would show. Every one must be retracted. 620 is also enough to
 * fail loudly if someone reintroduces a cap at 500.
 *
 * NOT COVERED: real HTTP delivery. Deliveries are counted as they are queued.
 */

$root = dirname(__DIR__);
$failures = [];

function ad_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

// Stubs the relay policy file leans on. Everything under test is real.
function sv_actor_url(array $settings): string { return 'https://hub.invalid/ap/actor'; }
function sv_url_is_public(string $url): bool { return str_starts_with($url, 'https://'); }
$GLOBALS['ad_queued'] = [];
function sv_queue_delivery(PDO $pdo, string $inbox, string $json, string $dedupe): int {
    $GLOBALS['ad_queued'][] = $json;
    return 1;
}
// sc_relay_is_hub() and sc_relay_leave() are REAL -- they live in the relay
// policy file. The hub branch is reached by giving the settings a real hub's
// shape rather than by stubbing the predicate, and "left the relay" is checked
// in the database afterwards rather than through a stub flag. Stubbing either
// would have meant testing my stub instead of the code.
function sv_cache_actor(PDO $pdo, array $actor): void {}
function sv_url_is_ours(string $url, array $settings): bool { return false; }
function sv_notify(...$args): void {}
function sv_is_following(PDO $pdo, string $actor): bool { return false; }
function sv_resolve_target(string $url, PDO $pdo): ?array { return null; }
function sv_inbox_log(PDO $pdo, string $t, string $a, ?string $o, string $m): void {}
function sv_fetch_ap(string $url, array $settings = []): ?array { return null; }
function sv_ingest_timeline(...$args) { return null; }
function sv_capture_dm(...$args): void {}

// The REAL relay policy: sc_relay_retract_actor and sc_relay_retract.
require_once $root . '/core/smackcast-relay.php';

// The REAL inbox handler, lifted out of fediverse.php the way the existing
// federation tests do it.
$source = (string)file_get_contents($root . '/core/fediverse.php');
foreach (['sv_is_direct', 'sv_reply_is_public', 'sv_activity_object_uri', 'sv_handle_inbox'] as $name) {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) { fwrite(STDERR, "missing {$name}\n"); exit(1); }
    $end  = strpos($source, "\nfunction ", $start + 1);
    $body = substr($source, $start, $end - $start);
    eval(substr($body, 0, strrpos($body, "\n}") + 2));
}

$actor = 'https://leaving.invalid/users/gone';
$total = 620;   // past the old cap, and not a multiple of the page size

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// sc_relay_leave() is real MySQL code and uses NOW(). Teach sqlite the one
// function rather than editing the code under test or stubbing the function.
$pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
$pdo->exec('CREATE TABLE snap_relay_intake (id INTEGER PRIMARY KEY AUTOINCREMENT,
             activity_id TEXT UNIQUE, object_id TEXT, origin_actor_url TEXT)');
$pdo->exec("CREATE TABLE snap_relay_subscribers (actor_url TEXT, domain TEXT,
             inbox_url TEXT, shared_inbox_url TEXT, state TEXT, last_seen_at TEXT)");
$pdo->exec("INSERT INTO snap_relay_subscribers (actor_url, domain, inbox_url, state)
             VALUES ('https://member.invalid/ap/actor', 'member.invalid',
                     'https://member.invalid/ap/inbox', 'active')");
$pdo->prepare("INSERT INTO snap_relay_subscribers (actor_url, domain, inbox_url, state)
               VALUES (?, 'leaving.invalid', 'https://leaving.invalid/ap/inbox', 'active')")
    ->execute([$actor]);
$ins = $pdo->prepare('INSERT INTO snap_relay_intake (activity_id, object_id, origin_actor_url)
                      VALUES (?, ?, ?)');
for ($i = 1; $i <= $total; $i++) {
    $ins->execute(["act-{$i}", "https://leaving.invalid/notes/{$i}", $actor]);
}

echo "SS-1481 #13 — a self-deleting actor is retracted in full\n\n";

ad_check("the fixture has {$total} relayed objects, past the old 500 cap",
         (int)$pdo->query('SELECT COUNT(*) FROM snap_relay_intake')->fetchColumn() === $total,
         $failures);

$GLOBALS['ad_queued'] = [];
$settings = [
    'site_mode'               => 'fedistructure',
    'distribution'            => 'fedistructure',
    'node_role'               => 'hub',
    'distribution_profile'    => 'smackcast',
    'smackcast_relay_enabled' => '1',
];
$activity = ['type' => 'Delete', 'actor' => $actor, 'object' => $actor,
             'id' => 'https://leaving.invalid/activities/delete'];
$status = sv_handle_inbox($pdo, $settings, $activity, ['id' => $actor, 'preferredUsername' => 'gone']);

ad_check('the Delete is acknowledged', $status === 202, $failures);
ad_check("ALL {$total} relayed objects are retracted, not the first 500",
         count($GLOBALS['ad_queued']) === $total, $failures);
ad_check('and the count is not the old cap',
         count($GLOBALS['ad_queued']) !== 500, $failures);
ad_check('each retraction is an Undo of an Announce',
         ($GLOBALS['ad_queued'][0] ?? '') !== ''
         && str_contains($GLOBALS['ad_queued'][0], '"type":"Undo"')
         && str_contains($GLOBALS['ad_queued'][0], '"type":"Announce"'), $failures);
ad_check('the object past the old cap is among them',
         (function (int $total): bool {
             foreach ($GLOBALS['ad_queued'] as $json) {
                 if (str_contains($json, "/notes/{$total}\"")) return true;
             }
             return false;
         })($total), $failures);
$leftState = $pdo->prepare("SELECT state FROM snap_relay_subscribers WHERE actor_url = ?");
$leftState->execute([$actor]);
ad_check('the member is marked as having left the relay, as before',
         (string)$leftState->fetchColumn() === 'left', $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #13 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #13 PASS — a self-deleting actor's relayed posts are all\n";
echo "retracted, including those past the old 500 cap, and the member is still\n";
echo "removed from the relay.\n";
echo "NOT COVERED: real HTTP delivery; retractions are counted as queued.\n";
// ===== SNAPSMACK EOF =====
