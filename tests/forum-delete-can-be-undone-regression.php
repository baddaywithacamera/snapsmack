<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// A SOFT DELETE WITH NO UNDO IS A HARD DELETE WITH EXTRA STEPS. (SS-1529)
//
// DELETE /threads/{id} and DELETE /replies/{id} have always set is_deleted = 1
// and left the row in the database. The only restore route in the whole API was
// POST /attachments/{id}/restore, for images. Threads and replies never got the
// matching half, so recovering one meant hand-written SQL against the live
// forum database — which is the owner's to run, at 2 a.m., by hand.
//
// THE RISK IS THE COUNTER ARITHMETIC, NOT THE FLAG. Deleting a thread also took
// its replies off the board's reply_count. Deleting a reply touched the board
// only when the parent thread was still live, because deleting the thread had
// already removed all of its replies from that figure. There is a comment on
// the delete path recording that exact sum shipping wrong once: Getting Started
// showed "0 THREADS / 1 REPLY" on foundtextures.ca because delete did half of
// it. Restore is the same fault from the other direction.
//
// So this does not check that the code "has a restore". It RUNS delete and then
// restore against a real database and demands the counters come back to exactly
// what they were. That is why the arithmetic lives in restore.php rather than
// inside the router: index.php dispatches on include and cannot be loaded by a
// test, so anything in it can only be read.
//
// WHAT IS EXECUTED: forum_restore_thread() and forum_restore_reply(), for real,
// against a real PDO database, including their transactions.
// WHAT IS READ: the two route blocks in index.php, because the router cannot be
// loaded. Those checks confirm the routes are wired and mod-gated; they do not
// prove the HTTP layer works, and this file does not claim they do.

$root    = dirname(__DIR__);
$restore = $root . '/projects/forum-server/api/forum/restore.php';
$api     = $root . '/projects/forum-server/api/forum/index.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "SS-1529: a deleted thread can be brought back\n\n";

require_once $restore;

// ─────────────────────────────────────────────────────────────────────────────
// A real database, built to the shape the restore code touches.
// ─────────────────────────────────────────────────────────────────────────────
$make_db = static function (): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE ss_forum_categories (
        id INTEGER PRIMARY KEY, thread_count INTEGER NOT NULL DEFAULT 0,
        reply_count INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE ss_forum_threads (
        id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL,
        install_id INTEGER NOT NULL DEFAULT 1, reply_count INTEGER NOT NULL DEFAULT 0,
        is_deleted INTEGER NOT NULL DEFAULT 0, is_solved INTEGER NOT NULL DEFAULT 0,
        solved_reply_id INTEGER NULL)");
    $pdo->exec("CREATE TABLE ss_forum_replies (
        id INTEGER PRIMARY KEY, thread_id INTEGER NOT NULL,
        install_id INTEGER NOT NULL DEFAULT 1, is_deleted INTEGER NOT NULL DEFAULT 0)");
    return $pdo;
};

// The delete arithmetic, as the shipped router performs it. Transcribed, and
// said plainly: this stands in for the delete path so the round trip can be
// driven. It is not proof that index.php's delete is unchanged — that is what
// the source reads at the end are for.
$delete_thread = static function (PDO $pdo, int $tid): void {
    $row = $pdo->query("SELECT category_id, reply_count FROM ss_forum_threads WHERE id = $tid")
               ->fetch(PDO::FETCH_ASSOC);
    $pdo->exec("UPDATE ss_forum_threads SET is_deleted = 1 WHERE id = $tid");
    $pdo->exec("UPDATE ss_forum_categories
                   SET thread_count = MAX(0, thread_count - 1),
                       reply_count  = MAX(0, reply_count - {$row['reply_count']})
                 WHERE id = {$row['category_id']}");
};

$seed = static function (PDO $pdo, int $replies): void {
    $pdo->exec("INSERT INTO ss_forum_categories (id, thread_count, reply_count) VALUES (1, 1, $replies)");
    $pdo->exec("INSERT INTO ss_forum_threads (id, category_id, reply_count) VALUES (1, 1, $replies)");
    for ($i = 1; $i <= $replies; $i++) {
        $pdo->exec("INSERT INTO ss_forum_replies (id, thread_id) VALUES ($i, 1)");
    }
};

$counters = static function (PDO $pdo): array {
    $c = $pdo->query("SELECT thread_count, reply_count FROM ss_forum_categories WHERE id = 1")
             ->fetch(PDO::FETCH_ASSOC);
    $t = $pdo->query("SELECT reply_count, is_deleted FROM ss_forum_threads WHERE id = 1")
             ->fetch(PDO::FETCH_ASSOC);
    return [
        'board_threads' => (int)$c['thread_count'],
        'board_replies' => (int)$c['reply_count'],
        'thread_replies'=> (int)$t['reply_count'],
        'deleted'       => (int)$t['is_deleted'],
    ];
};

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE ROUND TRIP, RUN FOR REAL\n";
// ─────────────────────────────────────────────────────────────────────────────

$pdo = $make_db();
$seed($pdo, 3);
$before = $counters($pdo);

$delete_thread($pdo, 1);
$mid = $counters($pdo);
$check('deleting takes the thread AND its 3 replies off the board',
    $mid['board_threads'] === 0 && $mid['board_replies'] === 0 && $mid['deleted'] === 1);

$res = forum_restore_thread($pdo, 1);
$after = $counters($pdo);

$check('restore reports success and the reply count it put back',
    ($res['status'] ?? '') === 'restored' && ($res['reply_count'] ?? -1) === 3);
$check('the thread is live again', $after['deleted'] === 0);
$check('THE BOARD COUNTERS ARE EXACTLY WHAT THEY WERE',
    $after['board_threads'] === $before['board_threads']
    && $after['board_replies'] === $before['board_replies']);
$check('the thread\'s own reply count is right', $after['thread_replies'] === 3);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE SUM THAT SHIPPED WRONG ONCE, FROM THE OTHER SIDE\n";
// ─────────────────────────────────────────────────────────────────────────────
// A reply deleted while the thread was deleted must NOT be counted back onto
// the board when the reply is restored, because the board is not counting any
// of that thread's replies. Getting this wrong is how the board over-reports.

$pdo = $make_db();
$seed($pdo, 2);
$delete_thread($pdo, 1);

// reply 2 is deleted after the thread already went. The board figure is
// untouched by that, exactly as the shipped delete path does it.
$pdo->exec("UPDATE ss_forum_replies SET is_deleted = 1 WHERE id = 2");
$pdo->exec("UPDATE ss_forum_threads SET reply_count = reply_count - 1 WHERE id = 1");

$r = forum_restore_reply($pdo, 2);
$check('restoring a reply under a DELETED thread does not touch the board',
    ($r['counted_on_board'] ?? null) === false);
$c = $counters($pdo);
$check('the board still shows zero replies for a thread nobody can reach',
    $c['board_replies'] === 0);
$check('but the thread\'s own count went back up', $c['thread_replies'] === 2);

// And the live-thread case, where it MUST count.
$pdo = $make_db();
$seed($pdo, 2);
$pdo->exec("UPDATE ss_forum_replies SET is_deleted = 1 WHERE id = 2");
$pdo->exec("UPDATE ss_forum_threads SET reply_count = reply_count - 1 WHERE id = 1");
$pdo->exec("UPDATE ss_forum_categories SET reply_count = reply_count - 1 WHERE id = 1");

$r = forum_restore_reply($pdo, 2);
$check('restoring a reply under a LIVE thread does count on the board',
    ($r['counted_on_board'] ?? null) === true);
$check('and the board is back to 2 replies', $counters($pdo)['board_replies'] === 2);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE RECOUNT, WHICH ONLY MATTERS WHEN THE STORED FIGURE IS WRONG\n";
// ─────────────────────────────────────────────────────────────────────────────
// Added because mutation testing caught this: with every reply live and the
// stored count correct, "recount the live rows" and "trust thread.reply_count"
// give the same answer, so two mutations survived — dropping the is_deleted
// filter from the COUNT, and skipping the recount entirely. Both are real
// faults and neither was visible until a thread had BOTH a deleted reply among
// live ones AND a stored figure that disagreed.
//
// A deleted thread holding 2 live replies and 1 deleted one, with its stored
// reply_count drifted to 7. Restore must put 2 on the board. Not 3 (which is
// counting the deleted reply) and not 7 (which is trusting the drift).

$pdo = $make_db();
$pdo->exec("INSERT INTO ss_forum_categories (id, thread_count, reply_count) VALUES (1, 0, 0)");
$pdo->exec("INSERT INTO ss_forum_threads (id, category_id, reply_count, is_deleted) VALUES (1, 1, 7, 1)");
$pdo->exec("INSERT INTO ss_forum_replies (id, thread_id, is_deleted) VALUES (1, 1, 0)");
$pdo->exec("INSERT INTO ss_forum_replies (id, thread_id, is_deleted) VALUES (2, 1, 0)");
$pdo->exec("INSERT INTO ss_forum_replies (id, thread_id, is_deleted) VALUES (3, 1, 1)");

$res = forum_restore_thread($pdo, 1);
$c   = $counters($pdo);

$check('a deleted reply is NOT counted back onto the board',
    ($res['reply_count'] ?? -1) === 2 && $c['board_replies'] === 2);
$check('the drifted stored count (7) is discarded, not trusted',
    $c['thread_replies'] === 2);
$check('the board gained exactly one thread', $c['board_threads'] === 1);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — REFUSALS\n";
// ─────────────────────────────────────────────────────────────────────────────

$pdo = $make_db();
$seed($pdo, 1);
$before = $counters($pdo);
$res = forum_restore_thread($pdo, 1);   // live, not deleted
$check('restoring a LIVE thread is refused, not double-counted',
    ($res['error'] ?? '') === 'THREAD_NOT_FOUND');
$check('and nothing moved', $counters($pdo) === $before);

$res = forum_restore_thread($pdo, 9999);
$check('an unknown thread id is refused', ($res['error'] ?? '') === 'THREAD_NOT_FOUND');
$res = forum_restore_reply($pdo, 9999);
$check('an unknown reply id is refused', ($res['error'] ?? '') === 'REPLY_NOT_FOUND');

$pdo = $make_db();
$seed($pdo, 1);
$res = forum_restore_reply($pdo, 1);    // live reply
$check('restoring a LIVE reply is refused', ($res['error'] ?? '') === 'REPLY_NOT_FOUND');

// A restored reply must not silently become the accepted answer again.
$pdo = $make_db();
$seed($pdo, 1);
$pdo->exec("UPDATE ss_forum_replies SET is_deleted = 1 WHERE id = 1");
$pdo->exec("UPDATE ss_forum_threads SET is_solved = 0, solved_reply_id = NULL WHERE id = 1");
forum_restore_reply($pdo, 1);
$solved = $pdo->query("SELECT is_solved, solved_reply_id FROM ss_forum_threads WHERE id = 1")
              ->fetch(PDO::FETCH_ASSOC);
$check('a restored reply does NOT re-solve the thread',
    (int)$solved['is_solved'] === 0 && $solved['solved_reply_id'] === null);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — THE ROUTES ARE WIRED (READ FROM SOURCE, NOT EXECUTED)\n";
// ─────────────────────────────────────────────────────────────────────────────
// index.php dispatches on include, so these are source reads. Stated plainly:
// they show the routes exist and are mod-gated. They do not prove the HTTP
// layer works. Only a live call against a deployed forum proves that, and the
// forum server is Sean's to deploy.

$src = (string) file_get_contents($api);

$check('restore.php is required by the router',
    str_contains($src, "require_once __DIR__ . '/restore.php';"));
$check('POST threads/{id}/restore is routed',
    (bool) preg_match('/\$parts\[0\] === \'threads\'[^;]*\$parts\[2\] === \'restore\'/s', $src));
$check('POST replies/{id}/restore is routed',
    (bool) preg_match('/\$parts\[0\] === \'replies\'[^;]*\$parts\[2\] === \'restore\'/s', $src));
$check('the router calls the tested function, not its own copy of the sums',
    str_contains($src, 'forum_restore_thread($pdo, $thread_id)')
    && str_contains($src, 'forum_restore_reply($pdo, $reply_id)'));
// Scoped to the restore route bodies ON PURPOSE. An earlier version of this
// check searched the whole file for "thread_count = thread_count + 1" and
// failed — on the thread-CREATE path at line ~1043 and the thread-MOVE path at
// ~1162, both of which legitimately increment a board counter and predate this
// work. A whole-file search in a two-thousand-line router proves nothing about
// one route. So: slice out each restore handler and look only in there.
$slice_route = static function (string $src, string $kind): string {
    $pos = 0;
    while (($pos = strpos($src, "\$parts[0] === '" . $kind . "'", $pos)) !== false) {
        $block = substr($src, $pos, 1400);
        if (str_contains($block, "\$parts[2] === 'restore'")) {
            $end = strpos($block, 'respond(');
            return $end === false ? $block : substr($block, 0, $end);
        }
        $pos += 10;
    }
    return '';
};
foreach (['threads', 'replies'] as $kind) {
    $body = $slice_route($src, $kind);
    $check("the $kind restore handler was found in the router", $body !== '');
    $check("the $kind restore handler does no counter arithmetic of its own",
        $body !== '' && !str_contains($body, 'UPDATE ss_forum_categories')
        && !str_contains($body, 'UPDATE ss_forum_threads'));
}
$check('both restores are refused for a non-moderator',
    substr_count($src, "Only moderators can restore a deleted") === 2);
$check('both restores are logged as moderator power',
    str_contains($src, "'thread.restore'") && str_contains($src, "'reply.restore'"));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo "FAIL (" . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — a delete can be undone, and the counters come back to where they were.\n";
exit(0);

// ===== SNAPSMACK EOF =====
