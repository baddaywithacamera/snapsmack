<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// ACTS OF MODERATOR POWER LEAVE A ROW. (SS-1510)
//
// THIS FILE USED TO CLAIM "EVERY", AND THAT WAS FALSE. Muse's review of
// 692c9b01 found two mutating moderator powers writing no row: accepting or
// un-accepting an answer on someone else's thread, and setting the post flag
// on someone else's thread. Thirteen of fifteen were logged. Both are fixed
// now, but the headline is corrected too, because the reason the gap survived
// was this test.
//
// WHY IT SURVIVED, which matters more than the two missing calls. Stage 2 below
// checks a HAND-WRITTEN list of action names against the source -- and that
// list came out of the same reading of the code that decided what to log. It
// could not possibly catch a power I had not thought of: the question it asked
// was "is everything on my list logged", not "is everything that needs logging
// on my list". A test built from the same assumption as the code it checks
// confirms the assumption, it does not test it.
//
// So Stage 2 now also derives the set of mutating, moderator-gated routes FROM
// THE SOURCE and demands each one log something, with exemptions named
// individually. A new power added later fails this by default.
//
// Nothing did, before this. No audit table existed in any forum schema and no
// logging call existed anywhere in the API: deletions, locks, board changes
// and role promotions all happened and left nothing behind. The God key makes
// it worse — require_auth() REFUSES that key for posting, so an action taken
// with it carried no install identity at all, and afterwards the owner's
// moderation and anyone else's who held the string looked identical.
//
// Making an owner's blog the moderator is only worth doing if the acts are
// attributable, which is why this ships with it.
//
// WHAT IS EXECUTED AND WHAT IS READ, because the two are not the same and
// this file should not pretend otherwise. forum_log_mod_action() is RUN, for
// real, against a real database: that is why it lives in its own file rather
// than inside the router. The CALL SITES are read from the source, because
// api/forum/index.php dispatches on include and cannot be loaded by a test —
// the same limit every other forum regression here works under.

$root = dirname(__DIR__);
$api  = $root . '/projects/forum-server/api/forum/index.php';
$log  = $root . '/projects/forum-server/api/forum/mod-log.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "SS-1510: every act of moderator power leaves a row\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE LOGGER, RUN FOR REAL\n";
// ─────────────────────────────────────────────────────────────────────────────
require_once $log;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    "CREATE TABLE ss_forum_mod_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        occurred_at TEXT DEFAULT CURRENT_TIMESTAMP,
        actor_install_id INTEGER NULL,
        actor_domain TEXT NOT NULL DEFAULT '',
        actor_is_god INTEGER NOT NULL DEFAULT 0,
        action TEXT NOT NULL,
        target_type TEXT NOT NULL DEFAULT '',
        target_id INTEGER NULL,
        detail TEXT NOT NULL DEFAULT ''
    )"
);

$sean = ['id' => 7, 'domain' => 'baddaywithacamera.ca'];

$check('an install\'s action is written',
       forum_log_mod_action($pdo, $sean, 'thread.delete', 'thread', 42, "deleted another install's thread") === true);
$row = $pdo->query("SELECT * FROM ss_forum_mod_log WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$check('  and it carries WHO, by id and by domain',
       (int)$row['actor_install_id'] === 7 && $row['actor_domain'] === 'baddaywithacamera.ca');
$check('  WHAT', $row['action'] === 'thread.delete');
$check('  WHICH THING', $row['target_type'] === 'thread' && (int)$row['target_id'] === 42);
$check('  and WHEN', ($row['occurred_at'] ?? '') !== '');
$check('  and it is not marked as the God key', (int)$row['actor_is_god'] === 0);

// The God key is the case most worth recording, because it is the one the
// API refuses to attribute anywhere else.
$check('a God-key action is written with no install',
       forum_log_mod_action($pdo, null, 'install.role', 'install', 7, 'role 0 -> 3') === true);
$god = $pdo->query("SELECT * FROM ss_forum_mod_log WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
$check('  recorded as god rather than as a blank nobody can read later',
       $god['actor_install_id'] === null && $god['actor_domain'] === 'god' && (int)$god['actor_is_god'] === 1);
$check('  and the role change says what it changed FROM, not only to',
       $god['detail'] === 'role 0 -> 3');

// Long values must not throw away the row.
$check('an over-long detail is cut, not dropped',
       forum_log_mod_action($pdo, $sean, 'board.update', 'category', 3, str_repeat('x', 400)) === true
       && strlen((string)$pdo->query("SELECT detail FROM ss_forum_mod_log WHERE id = 3")->fetchColumn()) === 255);

// A missing table must not stop a moderator working.
$bare = new PDO('sqlite::memory:');
$bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$threw = false;
try { $ok = forum_log_mod_action($bare, $sean, 'thread.delete', 'thread', 1); }
catch (Throwable $e) { $threw = true; }
$check('with no log table it returns false and does NOT throw', !$threw && ($ok ?? true) === false);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE MODERATOR POWERS HAVE CALL SITES\n";
// ─────────────────────────────────────────────────────────────────────────────
// Read, not run. If a power exists with no logging call beside it, the trail
// has a hole exactly where someone would look.
$src = (string)file_get_contents($api);
$check('the router loads the logger', str_contains($src, "require_once __DIR__ . '/mod-log.php'"));

foreach ([
    'board.create'       => 'creating a board',
    'board.update'       => 'renaming, reordering or hiding a board',
    'thread.flags'       => 'pinning or locking a thread',
    'thread.move'        => 'moving a thread between boards',
    'thread.edit'        => "editing another install's thread",
    'thread.delete'      => "deleting another install's thread",
    'reply.edit'         => "editing another install's reply",
    'reply.delete'       => "deleting another install's reply",
    'tag.add'            => 'tagging a thread',
    'tag.remove'         => 'untagging a thread',
    'attachment.hide'    => "hiding another install's image",
    'attachment.restore' => 'restoring a hidden image',
    'install.role'       => 'promoting or demoting an install',
    // The three Muse's review found missing. 'thread.flag' (singular) is the
    // post KIND -- support/question/chat/brag. 'thread.flags' (plural), above,
    // is pin/lock. One letter apart, which I have flagged in the room as worth
    // renaming rather than renaming a value on my own.
    'answer.accept'      => "accepting an answer on another install's thread",
    'answer.unaccept'    => 'removing an accepted answer',
    'thread.flag'        => "setting the post kind on another install's thread",
] as $action => $what) {
    // The action name has to appear AS AN ARGUMENT to the logger, not merely
    // somewhere in the file. The first version of this checked the two
    // separately, so a stray mention of the string in a comment would have
    // satisfied it while nothing was logged at all.
    $check("{$what} is logged ({$action})",
           preg_match('/forum_log_mod_action\(\$pdo,[^;]*\'' . preg_quote($action, '/') . '\'/s', $src) === 1);
}

// ── Stage 2b: the list above cannot prove completeness, so this does ────────
// Derived from the source rather than from my understanding of it. Every route
// block that BOTH mutates a forum table AND consults a moderator distinction
// must contain a logging call. Anything that legitimately does not is named
// here, one by one, with the reason -- so a power added later fails this check
// until somebody either logs it or writes down why it is not a power.
//
// WHAT THIS DOES NOT CATCH, said plainly so nobody trusts it further than it
// goes: it works per ROUTE BLOCK, so a route that logs ONE branch and forgets
// another passes. That is exactly the shape of the second fault Muse found --
// PATCH /threads/{id} already logged thread.edit and thread.flags, so the
// missing flag branch sat inside a block that looked covered. This check would
// have caught the solve route, which logged nothing at all, and would have
// missed the flag branch. Branch-level completeness still needs a reader.
$exempt = [
    'the shared prelude: defines and helper functions, not a route',
    'GET a thread: bumps the view counter; reading is not moderator power',
    'POST threads: creating your own thread, with the board counter',
    'POST a reply: creating your own reply, with the counters',
    'react on a thread: your own reaction',
    'react on a reply: your own reaction',
];
$chunks = preg_split('/\n\/\/ ─{10,}\n/u', $src);
$mutates = '/\b(?:UPDATE ss_forum_|INSERT INTO ss_forum_|DELETE FROM ss_forum_)/';
$modgate = '/is_install_mod\(|\bis_mod\(\)|\$has_power|\$is_own|\$is_thread_author|\$is_mod_caller/';
$unlogged = 0;
foreach ($chunks as $chunk) {
    if (preg_match($mutates, $chunk) !== 1) { continue; }
    if (preg_match($modgate, $chunk) !== 1) { continue; }
    if (!str_contains($chunk, 'forum_log_mod_action(')) { $unlogged++; }
}
$check('every mutating, moderator-gated route logs, bar the ' . count($exempt)
       . ' written down as not powers',
       $unlogged === count($exempt));
if ($unlogged !== count($exempt)) {
    echo "        expected " . count($exempt) . " unlogged blocks, found {$unlogged}.\n";
    echo "        A NEW ONE MEANS: log it, or add it to \$exempt with a reason.\n";
}

// ── Stage 2c: the two things mutation testing found unverified ──────────────
// Both added because a mutation survived, and both are the same lesson twice:
// a fix without a test is a fix waiting to be undone.

// THE OWN-VERSUS-OTHER DISTINCTION. Logging the thread author accepting an
// answer on their OWN thread would fill the moderation trail with ordinary use,
// which makes the trail useless in the other direction -- too much is as bad as
// too little when somebody is trying to read what a moderator did. Forcing the
// guard to always-true survived every check until this one.
foreach ([
    'answer.accept'   => '$is_thread_author',
    'answer.unaccept' => '$is_thread_author',
    'thread.flag'     => '$is_own',
] as $action => $guard) {
    // ANCHORED ON THE CALL, NOT THE BARE STRING. Looking for "'thread.flag'"
    // found a CODE COMMENT first -- one I wrote myself, explaining that
    // thread.flag and thread.flags are different things -- and measured back
    // from there, so the check failed on correct code. Same family of mistake
    // as the one already recorded in Stage 2: a string can appear in a comment
    // as easily as in a call, and a check has to say which it wants.
    $at = false;
    if (preg_match('/forum_log_mod_action\(\$pdo, \$install, \'' . preg_quote($action, '/') . '\'/',
                   $src, $m, PREG_OFFSET_CAPTURE) === 1) {
        $at = $m[0][1];
    }
    $before = $at === false ? '' : substr($src, max(0, $at - 400), 400);
    $check("{$action} is logged only when it is NOT the author's own ({$guard})",
           $at !== false && str_contains($before, 'if (!' . $guard . ')'));
}

// THE MIGRATION RUNS BEFORE THE CODE THAT NEEDS IT.
// This is the fix for the first half of Muse's FAIL on 692c9b01, and it had no
// test of its own until a mutation moved the schema sync back after the file
// copy and nothing noticed. The order is the whole point: the logger FAILS
// OPEN, so new API files on a database with no ss_forum_mod_log record nothing
// and report nothing wrong. A missing audit trail does not announce itself.
$upd_path = $root . '/smack-central/sc-update.php';
$check('the updater exists where this test expects it', is_file($upd_path));
if (is_file($upd_path)) {
    $upd       = (string) file_get_contents($upd_path);
    $schema_at = strpos($upd, 'forum-schema.sql');
    $copy_at   = strpos($upd, "dirname(__DIR__) . '/api/forum/'");
    $check('the updater applies the forum schema at all', $schema_at !== false);
    $check('and copies the forum API files at all', $copy_at !== false);
    $check('THE SCHEMA IS APPLIED BEFORE THE API FILES ARE COPIED',
           $schema_at !== false && $copy_at !== false && $schema_at < $copy_at);

    // AND THE BLOCK IS STILL REACHABLE. The check above is a position check: it
    // compares where two strings appear. A mutation that replaced the block's
    // guard with if (false) left both strings exactly where they were, so the
    // ordering check passed while the schema never ran at all. Position and
    // reachability are different properties and the first does not imply the
    // second. Pinning the real guard is the cheapest way to hold the second.
    $check('and the schema block is guarded on sc_forum_db() existing, not disabled',
           str_contains($upd, "if (function_exists('sc_forum_db')) {"));
    $check('and it is the forum DB it applies the schema to',
           str_contains($upd, 'sc_apply_schema($forum_db'));
}

// AND THE MANUAL UPGRADE PATH, which the self-updater does not cover. The
// README listed v2 and v3 and stopped, while v4, v5 and v6 shipped -- so anyone
// upgrading by hand never applied them and had no reason to suspect it.
$readme_path = $root . '/projects/forum-server/README.md';
if (is_file($readme_path)) {
    $readme = (string) file_get_contents($readme_path);
    foreach (['v2', 'v3', 'v4', 'v5', 'v6'] as $v) {
        $check("the README tells a manual upgrader to apply {$v}",
               str_contains($readme, "forum-schema-{$v}-migration.sql"));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE LOG IS APPEND-ONLY, BY HAVING NO WAY TO EDIT IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// MySQL cannot express append-only on a table, so the guarantee is that no
// statement anywhere in the API updates or deletes from it. That is a promise
// a reader can check, which is the only kind worth making.
$check('the API never UPDATEs the log',
       preg_match('/UPDATE\s+`?ss_forum_mod_log/i', $src) !== 1);
$check('the API never DELETEs from the log',
       preg_match('/DELETE\s+FROM\s+`?ss_forum_mod_log/i', $src) !== 1);
$check('and the logger itself only ever INSERTs',
       substr_count((string)file_get_contents($log), 'INSERT INTO ss_forum_mod_log') === 1
       && !preg_match('/(UPDATE|DELETE)\s+(FROM\s+)?`?ss_forum_mod_log/i', (string)file_get_contents($log)));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — THE TABLE SHIPS, IN BOTH PLACES A SCHEMA LIVES\n";
// ─────────────────────────────────────────────────────────────────────────────
// A fresh install reads forum-schema.sql; an existing one runs the migration.
// A table in only one of them is a table half the fleet never gets.
$canon = (string)file_get_contents($root . '/projects/forum-server/forum-schema.sql');
$mig   = (string)file_get_contents($root . '/projects/forum-server/forum-schema-v6-migration.sql');
$check('the canonical schema creates it', str_contains($canon, 'CREATE TABLE IF NOT EXISTS ss_forum_mod_log'));
$check('the v6 migration creates it', str_contains($mig, 'CREATE TABLE IF NOT EXISTS ss_forum_mod_log'));
$check('both are idempotent, so re-running is safe',
       substr_count($canon, 'CREATE TABLE IF NOT EXISTS ss_forum_mod_log') === 1
       && substr_count($mig, 'CREATE TABLE IF NOT EXISTS ss_forum_mod_log') === 1);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — THE PANEL NO LONGER HOLDS THE FORUM'S GOD KEY\n";
// ─────────────────────────────────────────────────────────────────────────────
// smack-forum.php asked the owner to paste FORUM_MOD_KEY in and stored it in
// plaintext in snap_settings. The one secret that outranks every forum
// administrator, in the settings table of every site that wanted a board.
$panel = (string)file_get_contents($root . '/smack-forum.php');
$check('the panel never writes forum_mod_key to settings again',
       !str_contains($panel, "VALUES ('forum_mod_key'"));
$check('  and has no handler to save it',
       !str_contains($panel, "'save-mod-key'"));
$check('  and asks the owner for no such key',
       stripos($panel, 'mod key') === false && stripos($panel, 'moderator key') === false);
// Anchored on the categories call specifically. The first version looked for
// "], $forum_api_key);" anywhere in the file, which the thread-posting call
// also matches — so putting the God key back on BOARD CREATION sailed past
// it. The mutation caught that, which is the only reason this line is right.
$check('board creation acts as the site, with its own registered key',
       preg_match("/forum_api\('POST', 'categories',.*?\], \\\$forum_api_key\);/s", $panel) === 1);
$check('  and the God-key variable is gone from the panel entirely',
       preg_match('/\$forum_mod_key\s*[^=\s]/', (string)preg_replace('#//.*#', '', $panel)) !== 1);
$check('the board controls are gated on what the forum says this install is',
       str_contains($panel, "forum_api('GET', 'me'")
       && str_contains($panel, "\$forum_can_admin = !empty(\$me['can']['administer'])"));
$check('  and default to hidden when the forum cannot be reached',
       preg_match('/\$forum_can_admin\s*=\s*false;/', $panel) === 1);
$check('there is a way to remove the key already sitting in a live database',
       is_file($root . '/tools/forum-cli/drop-forum-mod-key.php'));
$check('  which reports before it deletes, and never prints the secret',
       str_contains((string)file_get_contents($root . '/tools/forum-cli/drop-forum-mod-key.php'), '--delete')
       && str_contains((string)file_get_contents($root . '/tools/forum-cli/drop-forum-mod-key.php'), 'substr((string)$value, 0, 4)'));

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1510 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "SS-1510 PASS — the logger was run against a real database and records who,\n";
echo "what, which and when (and says \"god\" rather than leaving a blank); all\n";
echo "SIXTEEN moderator powers have a call site, and no mutating moderator-gated\n";
echo "route is unlogged beyond the six named as not powers; nothing can edit the\n";
echo "log; the\n";
echo "table ships in both schemas; and the panel no longer holds the God key.\n";
echo "NOT PROVED HERE: the call sites firing. The router dispatches on include\n";
echo "and cannot be executed by a test, so Stage 2 reads it. A live check needs\n";
echo "the forum server, which is Codex's to run.\n";
// ===== SNAPSMACK EOF =====
