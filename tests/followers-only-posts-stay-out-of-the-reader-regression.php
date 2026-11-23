<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 12 — a followers-only TOP-LEVEL post stays out of the reader.
 *
 * THE FAULT, and it is narrower than "no visibility gate". The gate existed and
 * was correct; it was conditioned on `$in_reply !== ''`. So a followers-only
 * REPLY was kept out, while a followers-only TOP-LEVEL post from an account we
 * follow went straight into the home timeline with no check at all.
 *
 * Mitigated, which is why it is a privacy fault rather than a leak: the reader
 * is owner-only, so this never published to the public web. It did put a post
 * its author restricted to their own followers into a timeline it can be
 * boosted onward from.
 *
 * Re-verified present at caebe17b (v0.7.1000D) before changing anything --
 * Muse read it on 38c519d1e, an older ancestor.
 *
 * WHY THIS TEST LOOKS LIKE tests/federation-reply-audience-regression.php. It
 * borrows that file's technique rather than inventing one: eval the real
 * functions out of core/fediverse.php and let a sentinel exception from a
 * stubbed dependency report which path the handler reached. Copying a working
 * harness beats writing a second one.
 *
 * WHAT IT PROVES: the real sv_handle_inbox() is called with a real activity
 * shape, and the sentinel says whether sv_ingest_timeline() -- the home
 * timeline -- was reached. Public and unlisted must reach it; followers-only
 * and unaddressed must not.
 *
 * NOT COVERED: a post already in the reader that is later restricted by an
 * Update is left as it was rather than withdrawn. That is a retraction
 * decision, not a visibility check, and it is stated in the source too.
 */

$source = (string)file_get_contents(__DIR__ . '/../core/fediverse.php');
foreach (['sv_is_direct', 'sv_reply_is_public', 'sv_handle_inbox'] as $name) {
    $start = strpos($source, 'function ' . $name . '(');
    $end   = strpos($source, "\nfunction ", $start + 1);
    $body  = substr($source, $start, $end - $start);
    eval(substr($body, 0, strrpos($body, "\n}") + 2));
}

/** Thrown from the stubbed ingest: the post reached the home timeline. */
final class ReachedTheReader extends RuntimeException {}

function sv_actor_url(array $settings): string { return 'https://local.invalid/ap/actor'; }
function sv_cache_actor(PDO $pdo, array $actor): void {}
function sv_capture_dm(PDO $pdo, array $settings, array $obj, string $actor, string $handle): void {}
function sv_url_is_ours(string $url, array $settings): bool { return false; }
function sv_notify(...$args): void {}
function sv_is_following(PDO $pdo, string $actor): bool { return true; }
function sv_resolve_target(string $url, PDO $pdo): ?array { return null; }
function sv_inbox_log(PDO $pdo, string $type, string $actor, ?string $object, string $message): void {
    $GLOBALS['fo_receipts'][] = ['object' => $object, 'message' => $message];
}
function sv_ingest_timeline(PDO $pdo, array $obj, string $actor_url, string $handle = '', ...$rest) {
    throw new ReachedTheReader();
}

$failures = [];
function fo_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

$pdo      = new PDO('sqlite::memory:');
$settings = [];
$actor    = ['id' => 'https://remote.invalid/users/test', 'preferredUsername' => 'test'];
$public    = 'https://www.w3.org/ns/activitystreams#Public';
$followers = 'https://remote.invalid/users/test/followers';

/**
 * Run the real handler for a TOP-LEVEL post (no inReplyTo) and say whether it
 * reached the reader.
 */
function fo_reaches_reader(PDO $pdo, array $settings, array $actor,
                           array $address, array $wrapper, string $type = 'Create'): bool {
    $obj = ['id' => 'https://remote.invalid/note/1', 'type' => 'Note',
            'content' => 'Body text'] + $address;
    $activity = ['type' => $type, 'actor' => $actor['id'], 'object' => $obj] + $wrapper;
    $GLOBALS['fo_receipts'] = [];
    try {
        sv_handle_inbox($pdo, $settings, $activity, $actor);
    } catch (ReachedTheReader $e) {
        return true;
    }
    return false;
}

echo "SS-1481 #12 — followers-only top-level posts stay out of the reader\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE CONTROL: a public top-level post still reaches the reader\n";
// ─────────────────────────────────────────────────────────────────────────────
// First, because without it the file would pass on a gate that rejected
// everything -- which would empty the owner's home timeline.
fo_check('a PUBLIC top-level post reaches the reader',
         fo_reaches_reader($pdo, $settings, $actor, ['to' => [$public]], []), $failures);
fo_check('an UNLISTED post (Public in cc) also reaches the reader',
         fo_reaches_reader($pdo, $settings, $actor,
             ['to' => [$followers], 'cc' => [$public]], []), $failures);
fo_check('the compact as:Public form is accepted too',
         fo_reaches_reader($pdo, $settings, $actor, ['cc' => ['as:Public']], []), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE DEFECT: followers-only must NOT reach the reader\n";
// ─────────────────────────────────────────────────────────────────────────────
fo_check('a FOLLOWERS-ONLY top-level post does NOT reach the reader',
         !fo_reaches_reader($pdo, $settings, $actor, ['to' => [$followers]], []), $failures);
fo_check('and the refusal is recorded for the owner',
         (function (): bool {
             foreach ($GLOBALS['fo_receipts'] as $r) {
                 if (str_contains((string)$r['message'], 'non-public post not ingested')) return true;
             }
             return false;
         })(), $failures);
fo_check('and the refusal does not log the private post id',
         (function (): bool {
             foreach ($GLOBALS['fo_receipts'] as $r) {
                 if (str_contains((string)$r['message'], 'non-public post not ingested')
                     && $r['object'] !== null) return false;
             }
             return true;
         })(), $failures);
fo_check('an UNADDRESSED post does not reach the reader either',
         !fo_reaches_reader($pdo, $settings, $actor, [], []), $failures);

// A broader wrapper must not promote a restricted object. This is the rule
// sv_reply_is_public() already enforces for replies; it has to hold here too,
// or any peer could widen its own post's audience from the envelope.
fo_check('a public WRAPPER cannot promote a followers-only object',
         !fo_reaches_reader($pdo, $settings, $actor,
             ['to' => [$followers]], ['to' => [$public]]), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — AN UPDATE CAN RESTRICT A POST, AND IS GATED THE SAME WAY\n";
// ─────────────────────────────────────────────────────────────────────────────
// An Update carries its own addressing, so a post can be edited from public to
// followers-only. Gating the Create and leaving the Update open would reopen
// the hole by the back door.
fo_check('a PUBLIC update still reaches the reader',
         fo_reaches_reader($pdo, $settings, $actor, ['to' => [$public]], [], 'Update'), $failures);
fo_check('a FOLLOWERS-ONLY update does NOT reach the reader',
         !fo_reaches_reader($pdo, $settings, $actor, ['to' => [$followers]], [], 'Update'), $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #12 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #12 PASS — public and unlisted top-level posts reach the reader;\n";
echo "followers-only, unaddressed, and wrapper-promoted ones do not, on Create\n";
echo "and on Update.\n";
echo "NOT COVERED: a post already in the reader that a later Update restricts\n";
echo "is left as it was rather than withdrawn -- a retraction decision, not a\n";
echo "visibility check. Nothing installed or deployed.\n";
// ===== SNAPSMACK EOF =====
