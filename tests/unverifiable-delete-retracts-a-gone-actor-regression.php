<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 7 — an unverifiable self-Delete acts only if the actor is gone.
 *
 * THE FAULT. The inbox answers 202 to a Delete whose signature cannot be
 * verified and takes no action. Right in general, wrong in one way: a remote
 * instance purging an account broadcasts the Delete AFTER the actor and its
 * signing key are gone, so verification can NEVER succeed. The retract path
 * never ran for exactly the accounts whose posts should disappear, and no
 * second Delete is ever coming.
 *
 * Re-verified present at caebe17b before changing anything -- the code's own
 * comment said it drops unverifiable Delete tombstones quietly.
 *
 * THE DECISION MOVED INTO core/ TO BE TESTABLE. It was inline in the
 * fediverse.php endpoint, and an endpoint script cannot be unit tested -- which
 * is a fair part of why a silent drop survived this long. The endpoint now
 * calls sv_unverifiable_delete_cleanup() and this exercises that.
 *
 * THE TRADE-OFF under test: acting on an UNVERIFIED activity, bounded to the
 * case where the named actor answers 404 or 410. A forger gains the power to
 * retract the posts of an account that no longer exists -- the same outcome the
 * real Delete would have had. A live account is untouchable, and that is the
 * check that matters most, so it is exercised first.
 */

$root = dirname(__DIR__);
$failures = [];

function ud_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

/**
 * The fetch is the input the decision turns on, so the test drives it.
 *
 * IT SETS THE STATUS ONLY ON A NON-2XX, because that is what the real
 * sv_fetch_ap() does. My first version set it on every call, and that defeated
 * Stage 5: a mutation removing the stale-status reset SURVIVED, because the
 * stub was refreshing the status the code under test was relying on being
 * reset. A stub that is kinder than reality hides the hazard it exists to
 * expose.
 */
$GLOBALS['ud_fetch'] = ['doc' => null, 'code' => 0, 'sets' => false];
function sv_fetch_ap(string $url, ?array $settings = null): ?array {
    $code = (int)$GLOBALS['ud_fetch']['code'];
    // A TRANSPORT error records NO status at all -- the real sv_fetch_ap()
    // returns before the status line on curl failure. 'sets' models that, and
    // it is the case the stale-status reset actually protects.
    if (($GLOBALS['ud_fetch']['sets'] ?? false) && ($code < 200 || $code >= 300)) {
        sv_last_fetch_status($code);
    }
    return $GLOBALS['ud_fetch']['doc'];
}
function sv_inbox_log(PDO $pdo, string $t, string $a, ?string $o, string $m): void {
    $GLOBALS['ud_log'][] = $m;
}

$source = (string)file_get_contents($root . '/core/fediverse.php');
foreach (['sv_last_fetch_status', 'sv_unverifiable_delete_cleanup'] as $name) {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) { fwrite(STDERR, "missing {$name}\n"); exit(1); }
    $end  = strpos($source, "\nfunction ", $start + 1);
    $body = substr($source, $start, $end - $start);
    eval(substr($body, 0, strrpos($body, "\n}") + 2));
}

function ud_pdo(string $actor): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_ap_followers (actor_url TEXT, is_active INTEGER DEFAULT 1)');
    $pdo->prepare('INSERT INTO snap_ap_followers (actor_url, is_active) VALUES (?, 1)')
        ->execute([$actor]);
    return $pdo;
}
function ud_active(PDO $pdo, string $actor): int {
    $q = $pdo->prepare('SELECT is_active FROM snap_ap_followers WHERE actor_url = ?');
    $q->execute([$actor]);
    return (int)$q->fetchColumn();
}
function ud_delete(string $actor, ?string $object = null): array {
    return ['type' => 'Delete', 'actor' => $actor, 'object' => $object ?? $actor,
            'id' => 'https://purged.invalid/activities/d1'];
}

$actor = 'https://purged.invalid/users/gone';
$settings = [];

echo "SS-1481 #7 — an unverifiable self-Delete acts only if the actor is gone\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — A LIVE ACTOR IS UNTOUCHABLE (the check that matters most)\n";
// ─────────────────────────────────────────────────────────────────────────────
// First, because this is the whole safety of acting on an unverified activity.
$GLOBALS['ud_fetch'] = ['doc' => ['id' => $actor], 'code' => 200, 'sets' => false];
$pdo = ud_pdo($actor);
$GLOBALS['ud_log'] = [];
ud_check('a forged Delete naming a LIVE actor does nothing',
         sv_unverifiable_delete_cleanup($pdo, $settings, ud_delete($actor)) === false, $failures);
ud_check('and that actor is still an active follower',
         ud_active($pdo, $actor) === 1, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — A GONE ACTOR IS CLEANED UP\n";
// ─────────────────────────────────────────────────────────────────────────────
foreach ([404, 410] as $code) {
    $GLOBALS['ud_fetch'] = ['doc' => null, 'code' => $code, 'sets' => true];
    $pdo = ud_pdo($actor);
    $GLOBALS['ud_log'] = [];
    ud_check("HTTP {$code} means gone, so cleanup runs",
             sv_unverifiable_delete_cleanup($pdo, $settings, ud_delete($actor)) === true, $failures);
    ud_check("and the follower is deactivated (HTTP {$code})",
             ud_active($pdo, $actor) === 0, $failures);
    ud_check("and it is recorded for the owner, saying why (HTTP {$code})",
             isset($GLOBALS['ud_log'][0])
             && str_contains($GLOBALS['ud_log'][0], 'actor gone (HTTP ' . $code . ')'), $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — ANY OTHER FAILURE IS NOT EVIDENCE OF ANYTHING\n";
// ─────────────────────────────────────────────────────────────────────────────
// A timeout or a 500 must not be read as "the account is gone". That is the
// difference between a bounded decision and a guess.
foreach ([0, 500, 429, 403, 503] as $code) {
    $GLOBALS['ud_fetch'] = ['doc' => null, 'code' => $code, 'sets' => true];
    $pdo = ud_pdo($actor);
    ud_check("HTTP {$code} does NOT trigger cleanup",
             sv_unverifiable_delete_cleanup($pdo, $settings, ud_delete($actor)) === false
             && ud_active($pdo, $actor) === 1, $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — SELF-DELETE ONLY\n";
// ─────────────────────────────────────────────────────────────────────────────
// An actor being gone says nothing about who may delete a particular note.
$GLOBALS['ud_fetch'] = ['doc' => null, 'code' => 404, 'sets' => true];
$pdo = ud_pdo($actor);
ud_check('a Delete naming some OTHER object does nothing, even with the actor gone',
         sv_unverifiable_delete_cleanup($pdo, $settings,
             ud_delete($actor, 'https://purged.invalid/notes/7')) === false
         && ud_active($pdo, $actor) === 1, $failures);

$pdo = ud_pdo($actor);
ud_check('a Delete with no actor does nothing',
         sv_unverifiable_delete_cleanup($pdo, $settings, ud_delete('', '')) === false, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — A STALE STATUS CANNOT DECIDE IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// The status is a static. If cleanup did not reset it, a 404 from an earlier
// unrelated fetch would make the NEXT Delete look like a gone actor.
// A live actor is protected by the doc check whatever the status says, so that
// is NOT the hazard. The hazard is a later fetch that fails with NO status --
// a timeout or DNS failure, where the real sv_fetch_ap() records nothing. With
// a stale 404 still sitting in the static, such a Delete reads as "actor gone"
// and a perfectly healthy account gets retracted.
$GLOBALS['ud_fetch'] = ['doc' => null, 'code' => 404, 'sets' => true];
$pdo = ud_pdo($actor);
sv_unverifiable_delete_cleanup($pdo, $settings, ud_delete($actor));   // leaves 404 behind

$other = 'https://healthy.invalid/users/live';
$GLOBALS['ud_fetch'] = ['doc' => null, 'code' => 0, 'sets' => false];  // timeout: no status
$pdo2 = ud_pdo($other);
ud_check('a timeout after an earlier 404 does NOT read as "actor gone"',
         sv_unverifiable_delete_cleanup($pdo2, $settings, ud_delete($other)) === false
         && ud_active($pdo2, $other) === 1, $failures);

// And the plain live-actor case, which the doc check covers on its own.
$GLOBALS['ud_fetch'] = ['doc' => ['id' => $actor], 'code' => 200, 'sets' => false];
$pdo3 = ud_pdo($actor);
ud_check('and a live actor is refused regardless of any leftover status',
         sv_unverifiable_delete_cleanup($pdo3, $settings, ud_delete($actor)) === false
         && ud_active($pdo3, $actor) === 1, $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #7 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #7 PASS — a gone actor (404/410) has its posts retracted and its\n";
echo "follow deactivated; a live actor, any other fetch failure, a non-self\n";
echo "Delete, and a stale status all do nothing.\n";
echo "NOT COVERED: the endpoint wrapper itself, and real HTTP. The fetch is\n";
echo "driven by the test because it is the input the decision turns on.\n";
// ===== SNAPSMACK EOF =====
