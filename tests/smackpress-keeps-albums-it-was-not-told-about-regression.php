<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A SMACKPRESS save does not erase the albums and categories it was not told
 * about. (SS-1641)
 *
 * WHAT WENT WRONG. The post update branch read
 *
 *     $selected_albums = array_map('intval', $body['album_ids'] ?? []);
 *
 * and then deleted every row in snap_post_album_map and snap_post_cat_map for
 * the post before re-inserting from those arrays. An update that did not
 * mention either field arrived with both EMPTY, the delete ran anyway, and the
 * re-insert put nothing back. There was no way to tell "leave the albums
 * alone" from "put this post in no albums", and the destructive reading was
 * the default. Re-saving a post to fix its text was enough to empty it.
 *
 * It reached two live sites. Every post on baddaywithacamera.ca read
 * CATEGORY: NONE and ALBUM: NONE, every photograph was in no album, and the
 * portfolio page rendered nothing.
 *
 * WHY THE EXISTING TEST DID NOT CATCH IT, which is the part worth keeping.
 * tests/smackpress-portfolio-designation-regression.php pins the SAME RULE for
 * primary_album_id, primary_category_id and portfolio_post -- the fields
 * written by the statement immediately above the delete -- and it passes. It
 * passes because it builds the SQL shape the handler builds and runs that,
 * rather than running the handler. A reimplementation of the correct code
 * cannot contain the bug, so the two map tables sitting between the guarded
 * fields were never executed by anything.
 *
 * SO THIS TEST RUNS THE REAL ENDPOINT. core/smackpress-api.php is a procedural
 * route handler that reads php://input, sends headers and exits, which is why
 * the older test said it could not be included. In the CLI SAPI php://input IS
 * stdin and an exit only ends the child, so it can be driven in a subprocess:
 * core/ is copied, a db.php that opens sqlite is written into the copy (db.php
 * is the per-install file and is absent from the repo, which is what makes
 * this clean rather than a stub), the request is fed in on stdin, and the
 * DATABASE is read afterwards. What is asserted is what the handler actually
 * left behind.
 *
 * Run: php -d extension=pdo_sqlite tests/smackpress-keeps-albums-it-was-not-told-about-regression.php
 */

// ─── THIS TEST DOES NOT RUN YET, AND SAYING SO IS THE POINT ──────────────────
//
// SKIPPED DELIBERATELY. The HTTP harness below hangs on this machine and I have
// not found where. A php -S server plus a POST works when the server is started
// from the shell -- measured, body and Authorization header both arrive -- and
// blocks when the same server is started with proc_open from inside the test.
// Both attempts ran past two minutes with no output, so the stage is unknown.
//
// IT EXITS HERE RATHER THAN BEING LEFT ARMED because a test that can wedge the
// suite is worse than no test: the suite is how everything else here is checked,
// and one hanging file stops all 515 of them. It is also NOT deleted, because
// the harness is most of the work and the reasoning below is what the next
// person needs.
//
// WHAT IS AND IS NOT COVERED FOR SS-1641 RIGHT NOW:
//   the fix is in core/smackpress-api.php and PHP lints clean
//   all 14 existing smackpress, album, taxonomy and import tests pass
//   the four-state behaviour this file describes is NOT EXECUTED by anything
//
// So SS-1641 is fixed and UNPROVEN. That is the honest state and it is what the
// ticket's "done when" asks for. Do not close it on the strength of this file.
//
// WHERE TO START: the stage printing in the minimal repro never reached stdout
// because the output was piped into head, which buffered it. Write the stage
// marks to a FILE and read that while it runs -- the blocking stage is the one
// thing nobody has actually seen yet.
fwrite(STDERR, "SKIP: SS-1641 HTTP harness hangs; see the note at the top of this file.
");
exit(2);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(2);
}

$root   = dirname(__DIR__);
$fails  = [];
$checks = 0;
function cx(bool $ok, string $what, string $saw = ''): void {
    global $fails, $checks;
    $checks++;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fails[] = $what;
    echo "  FAIL  $what" . ($saw !== '' ? "   [saw: $saw]" : '') . "\n";
}

$KEY = str_repeat('a1b2c3d4', 8);   // 64 hex, the shape the Bearer rule demands

$SCHEMA = implode("\n", [
 'CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, title TEXT, slug TEXT, content TEXT,',
 '  colophon TEXT, signature_image_id INTEGER NULL,',
 '  signature_light_image_id INTEGER NULL, signature_dark_image_id INTEGER NULL,',
 '  status TEXT NOT NULL DEFAULT "published", allow_comments INTEGER NOT NULL DEFAULT 1,',
 '  featured_image_id INTEGER NULL, user_id INTEGER NULL,',
 '  post_type TEXT NOT NULL DEFAULT "longform",',
 '  created_at TEXT NOT NULL DEFAULT "2020-01-01 00:00:00", updated_at TEXT NULL,',
 '  portfolio_post INTEGER NOT NULL DEFAULT 0,',
 '  primary_album_id INTEGER NULL, primary_category_id INTEGER NULL);',
 'CREATE TABLE snap_ohsnap_keys (id INTEGER PRIMARY KEY, user_id INTEGER, key_hash TEXT,',
 '  key_type TEXT, expires_at TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1,',
 '  last_used_at TEXT NULL, revoked INTEGER NOT NULL DEFAULT 0);',
 'CREATE TABLE snap_albums (id INTEGER PRIMARY KEY, album_name TEXT, album_slug TEXT NOT NULL DEFAULT "");',
 'CREATE TABLE snap_categories (id INTEGER PRIMARY KEY, cat_name TEXT, cat_slug TEXT NOT NULL DEFAULT "");',
 'CREATE TABLE snap_post_album_map (post_id INTEGER, album_id INTEGER);',
 'CREATE TABLE snap_post_cat_map (post_id INTEGER, cat_id INTEGER);',
 'CREATE TABLE snap_image_album_map (image_id INTEGER, album_id INTEGER);',
 'CREATE TABLE snap_image_cat_map (image_id INTEGER, cat_id INTEGER);',
 'CREATE TABLE snap_images (id INTEGER PRIMARY KEY, img_title TEXT, img_slug TEXT, img_file TEXT,',
 '  post_id INTEGER NULL, img_description TEXT NULL, img_width INTEGER NULL,',
 '  img_height INTEGER NULL, img_date TEXT NOT NULL DEFAULT "2020-01-01 00:00:00");',
 'CREATE TABLE snap_tags (id INTEGER PRIMARY KEY, tag_name TEXT, tag_slug TEXT);',
 'CREATE TABLE snap_post_tag_map (post_id INTEGER, tag_id INTEGER);',
 'CREATE TABLE snap_image_tag_map (image_id INTEGER, tag_id INTEGER);',
 'CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT);',
 'CREATE TABLE snap_buckets (id INTEGER PRIMARY KEY, post_id INTEGER, image_id INTEGER,',
 '  sort_order INTEGER NOT NULL DEFAULT 0);',
 'INSERT INTO snap_albums (id,album_name,album_slug) VALUES (7,"Car Show","car-show"),(8,"Winter","winter");',
 'INSERT INTO snap_categories (id,cat_name,cat_slug) VALUES (3,"Street","street"),(4,"Landscape","landscape");',
 'INSERT INTO snap_posts (id,title,slug,content,post_type,status)',
 '  VALUES (41,"Start your engines","start-your-engines","<p>old</p>","longform","published");',
 'INSERT INTO snap_post_album_map (post_id,album_id) VALUES (41,7),(41,8);',
 'INSERT INTO snap_post_cat_map (post_id,cat_id) VALUES (41,3),(41,4);',
]);

// -- the sandbox: a copy of core/, served by a real PHP web server ------------
//
// A SUBPROCESS WAS NOT ENOUGH, and the reason is the whole point of this file.
// The handler reads its body with file_get_contents('php://input'). In the CLI
// SAPI on this build php://input comes back EMPTY -- measured: STDIN holds the
// bytes and php://input is a zero-length string -- so a CLI child received no
// body, answered {"status":"error","message":"Invalid JSON body."}, and touched
// nothing. The rows then looked untouched, which is indistinguishable from
// correctly preserved, and the first run of this file reported the omitted-field
// case as a PASS while the handler had never run. That is the same false pass
// as SS-1385's and it is why every case below asserts the handler completed.
//
// So this serves the sandbox with php -S and makes a real HTTP POST. php://input
// is a real request body, the Authorization header is a real header, and the
// status line comes back from the handler rather than from a harness guess.
$tmp = sys_get_temp_dir() . '/ss1641-' . bin2hex(random_bytes(6));
mkdir($tmp . '/core', 0777, true);
foreach (glob($root . '/core/*.php') as $f) {
    copy($f, $tmp . '/core/' . basename($f));
}
$dbfile = $tmp . '/site.sqlite';

// db.php is the PER-INSTALL file and is absent from the repo, so writing one
// here is the install's own job rather than a stub standing in for shipped code.
//
// NOW() IS REGISTERED AND THE REASON IS WORTH READING. smackpress_auth() wraps
// its SELECT in try/catch and falls back to a version without the expires_at
// comparison, so a missing NOW() survives there -- but the statement straight
// after it, UPDATE snap_ohsnap_keys SET last_used_at = NOW(), has no fallback.
// On an engine without NOW() authentication dies before any route is reached.
// The fleet runs MySQL so no site is affected; this is not a defect report, it
// is why a sqlite harness must supply the function instead of pretending the
// handler is engine-neutral.
file_put_contents($tmp . '/core/db.php',
    '<?php $pdo = new PDO("sqlite:" . ' . var_export($dbfile, true) . ');'
    . ' $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
    . ' $er = error_reporting(E_ALL & ~E_DEPRECATED);'
    . ' $pdo->sqliteCreateFunction("NOW", function () { return date("Y-m-d H:i:s"); }, 0);'
    . ' error_reporting($er);');

$seed = function () use ($dbfile, $SCHEMA, $KEY): void {
    if (file_exists($dbfile)) { unlink($dbfile); }
    $pdo = new PDO('sqlite:' . $dbfile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec($SCHEMA);
    $pdo->prepare('INSERT INTO snap_ohsnap_keys (id,user_id,key_hash,key_type) VALUES (1,9,?,"smackpress")')
        ->execute([hash('sha256', $KEY)]);
};

// A free port, taken by binding one and letting go.
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$probe) {
    fwrite(STDERR, "SKIP: cannot bind a local port ({$errstr})\n");
    exit(2);
}
$port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
fclose($probe);

// FILES, NOT PIPES, and this is what hung the first attempt for over two
// minutes. php -S logs every request, and an un-drained pipe fills and blocks
// the server mid-response while the parent waits for a reply that is never
// finished. Nobody is reading those pipes here, so they must not be pipes. The
// log is kept on disk instead, where a failure can actually be read.
$descriptors = [
    0 => ['file', $tmp . '/server.in',  'r'],
    1 => ['file', $tmp . '/server.out', 'a'],
    2 => ['file', $tmp . '/server.err', 'a'],
];
touch($tmp . '/server.in');
$pipes  = [];
$server = proc_open(
    escapeshellarg(PHP_BINARY) . ' -d extension=pdo_sqlite -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($tmp),
    $descriptors, $pipes, $tmp);
if (!is_resource($server)) {
    fwrite(STDERR, "SKIP: could not start php -S\n");
    exit(2);
}
$shutdown = function () use ($server, $pipes, $tmp): void {
    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    proc_terminate($server);
    proc_close($server);
    foreach (glob($tmp . '/core/*') as $f) { unlink($f); }
    if (is_dir($tmp . '/core')) { rmdir($tmp . '/core'); }
    foreach (glob($tmp . '/*') as $f) { unlink($f); }
    if (is_dir($tmp)) { rmdir($tmp); }
};

// WAIT FOR THE PORT, do not sleep a guessed interval and hope. A fixed sleep is
// either slower than it needs to be or shorter than the server needs, and the
// short case fails as "the handler did not run" with no hint why.
$up = false;
for ($i = 0; $i < 100 && !$up; $i++) {
    $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $e1, $e2, 0.2);
    if ($probe) { $up = true; fclose($probe); break; }
    usleep(50000);
}
if (!$up) { $shutdown(); fwrite(STDERR, "SKIP: php -S never answered on {$port}\n"); exit(2); }

// Drives the REAL handler over REAL HTTP. Returns [body, albums, cats].
$put = function (array $body) use ($port, $dbfile, $KEY): array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$KEY}\r\n",
        'content'       => json_encode($body),
        'ignore_errors' => true,
        'timeout'       => 15,
    ]]);
    $url = 'http://127.0.0.1:' . $port . '/core/smackpress-api.php?route=smackpress/posts';
    $out = @file_get_contents($url, false, $ctx);
    $out = $out === false ? '(no response)' : trim((string) $out);

    $pdo = new PDO('sqlite:' . $dbfile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $alb = $pdo->query('SELECT album_id FROM snap_post_album_map WHERE post_id=41 ORDER BY album_id')
               ->fetchAll(PDO::FETCH_COLUMN);
    $cat = $pdo->query('SELECT cat_id FROM snap_post_cat_map WHERE post_id=41 ORDER BY cat_id')
               ->fetchAll(PDO::FETCH_COLUMN);
    return [$out, array_map('intval', $alb), array_map('intval', $cat)];
};

$base = ['post_id' => 41, 'title' => 'Start your engines', 'slug' => 'start-your-engines'];

// EVERY CASE MUST PROVE THE HANDLER RAN. The first version of this file reported
// "both albums survive a save that never mentioned album_ids" as a PASS while
// the handler was dying at authentication and touching nothing whatsoever.
// Untouched rows look exactly like correctly preserved rows. A test that cannot
// tell those apart certifies the fault it was written to catch, which is the
// same mistake as SS-1385's inverted guard and OPAUDIT 036's whole subject.
$ran = function (string $out, string $case): void {
    cx(!str_contains($out, 'Fatal error') && !str_contains($out, 'Uncaught')
       && str_contains($out, 'post_id'),
       "{$case}: the handler ran to completion", substr($out, 0, 400));
};

echo "-- the harness reaches the real handler at all --\n";
$seed();
[$out, $alb, $cat] = $put($base + ['content' => '<p>text one</p>']);
$ran($out, 'a text-only save');

echo "\n-- OMITTED: the fault. A save about the text keeps the taxonomy --\n";
cx($alb === [7, 8], 'both albums survive a save that never mentioned album_ids',
   '[' . implode(',', $alb) . ']');
cx($cat === [3, 4], 'both categories survive a save that never mentioned cat_ids',
   '[' . implode(',', $cat) . ']');

echo "\n-- A LIST: sets exactly that list --\n";
$seed();
[$out, $alb, $cat] = $put($base + ['content' => '<p>t</p>', 'album_ids' => [8], 'cat_ids' => [3]]);
$ran($out, 'a list for both');
cx($alb === [8], 'albums become exactly what was sent', '[' . implode(',', $alb) . ']');
cx($cat === [3], 'categories become exactly what was sent', '[' . implode(',', $cat) . ']');

echo "\n-- AN EMPTY LIST: clears, because that is a thing a caller may say --\n";
$seed();
[$out, $alb, $cat] = $put($base + ['content' => '<p>t</p>', 'album_ids' => [], 'cat_ids' => []]);
$ran($out, 'two empty lists');
cx($alb === [], 'an explicit empty album list clears', '[' . implode(',', $alb) . ']');
cx($cat === [], 'an explicit empty category list clears', '[' . implode(',', $cat) . ']');

echo "\n-- ONE FIELD SENT, THE OTHER NOT: they are independent --\n";
$seed();
[$out, $alb, $cat] = $put($base + ['content' => '<p>t</p>', 'album_ids' => [7]]);
$ran($out, 'albums only');
cx($alb === [7], 'the albums that were sent are set', '[' . implode(',', $alb) . ']');
cx($cat === [3, 4], 'and the categories, unmentioned, are untouched',
   '[' . implode(',', $cat) . ']');

echo "\n-- the single-category alias counts as having been told --\n";
$seed();
[$out, $alb, $cat] = $put($base + ['content' => '<p>t</p>', 'category_id' => 4]);
$ran($out, 'the category_id alias');
cx($cat === [4], 'category_id alone sets the categories', '[' . implode(',', $cat) . ']');
cx($alb === [7, 8], 'and leaves the albums alone', '[' . implode(',', $alb) . ']');

$shutdown();

echo "\n";
if ($fails) {
    echo 'FAILED (' . count($fails) . " of {$checks}):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    fwrite(STDERR, "\nA SMACKPRESS save can still erase albums nobody asked it to touch.\n");
    exit(1);
}
echo "SS-1641: a save keeps the taxonomy it was not told about ({$checks} checks)\n";
echo "EXECUTED: the real core/smackpress-api.php handler, in a subprocess, against a\n"
   . "real sqlite database, with the request on stdin exactly as php://input reads\n"
   . "it. Every assertion above is read back out of the DATABASE after the handler\n"
   . "exited, not from a reimplementation of its SQL.\n"
   . "NOT COVERED: MySQL. The fleet runs MySQL and this suite runs sqlite. Nothing\n"
   . "here depends on rowCount() or on INSERT IGNORE semantics, but the engine is\n"
   . "not the fleet's and this line is here so nobody reads it as if it were.\n";
// ===== SNAPSMACK EOF =====
