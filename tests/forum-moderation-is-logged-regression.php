<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// EVERY ACT OF MODERATOR POWER LEAVES A ROW. (SS-1510)
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
echo "\nSTAGE 2 — EVERY MODERATOR POWER HAS A CALL SITE\n";
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
] as $action => $what) {
    // The action name has to appear AS AN ARGUMENT to the logger, not merely
    // somewhere in the file. The first version of this checked the two
    // separately, so a stray mention of the string in a comment would have
    // satisfied it while nothing was logged at all.
    $check("{$what} is logged ({$action})",
           preg_match('/forum_log_mod_action\(\$pdo,[^;]*\'' . preg_quote($action, '/') . '\'/s', $src) === 1);
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
echo "thirteen moderator powers have a call site; nothing can edit the log; the\n";
echo "table ships in both schemas; and the panel no longer holds the God key.\n";
echo "NOT PROVED HERE: the call sites firing. The router dispatches on include\n";
echo "and cannot be executed by a test, so Stage 2 reads it. A live check needs\n";
echo "the forum server, which is Codex's to run.\n";
// ===== SNAPSMACK EOF =====
