<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A SMACKPRESS save does not erase the albums and categories it was not told
 * about. (SS-1641, harness repaired under SS-1647)
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
 * SO THIS TEST RUNS THE REAL HANDLER OVER REAL HTTP AGAINST REAL MARIADB.
 * It starts a disposable MariaDB, loads the CANONICAL schema (not a hand-typed
 * sqlite imitation of it), seeds a post with two albums and two categories,
 * starts a php -S server over a COPY of core/, and drives the five request
 * shapes through the actual endpoint with a real Bearer key. What is asserted
 * is what the handler left in the database.
 *
 * ─── WHY MARIADB AND NOT SQLITE ──────────────────────────────────────────────
 *
 * The suite runs sqlite and the fleet runs MySQL, and for THIS endpoint that
 * difference is not cosmetic: rowCount() after an UPDATE counts rows CHANGED
 * on MySQL and rows MATCHED on sqlite. A guard written against sqlite's answer
 * refuses every no-op save on all 25 sites. The previous version of this file
 * hand-wrote a sqlite schema, so it could not have caught an engine
 * difference even if it had run.
 *
 * ─── WHERE THE OLD HARNESS BLOCKED, MEASURED AT LAST ─────────────────────────
 *
 * The note that stood here said the php -S harness hung and "the blocking
 * stage is the one thing nobody has actually seen yet". It has now been seen,
 * with a minimal repro that wrote its stage marks to a FILE so nothing could
 * buffer them away:
 *
 *   proc_open with stdout/stderr as PIPES   request 0 answered, request 50
 *                                           returned FALSE, then it blocked
 *                                           until killed at 90 seconds
 *   proc_open with stdout/stderr as FILES   all 200 requests answered,
 *                                           clean exit
 *
 * php -S logs a line per request. With a pipe nobody drains, the buffer fills,
 * the CHILD blocks on write, so it stops serving, and the PARENT blocks
 * waiting for a response that will never come. Each is waiting for the other.
 * Nothing was wrong with the server or the request; the harness deadlocked
 * itself on its own logging. Hence the file descriptors below, and hence
 * every wait in this file is bounded.
 *
 * ─── IT FAILS RATHER THAN SKIPS ──────────────────────────────────────────────
 *
 * PM ruling (room sequence 9248): where MariaDB is missing this must FAIL with
 * a clear message, NOT skip, so it can never silently pass. A skip that stands
 * in for a proof is how SS-1641 shipped unproven in the first place. Point
 * SNAPSMACK_MARIADB_BIN at a MariaDB bin directory and it runs for real.
 *
 * Run:  SNAPSMACK_MARIADB_BIN=/path/to/mariadb/bin \
 *       php tests/smackpress-keeps-albums-it-was-not-told-about-regression.php
 */

$root = dirname(__DIR__);

// ─────────────────────────────────────────────────────────────────────────────
// pdo_mysql, re-exec once if the default ini does not load it
// ─────────────────────────────────────────────────────────────────────────────
// Codex measured that the Windows php.exe here reports sqlite only, while
// `php -d extension=pdo_mysql` reports mysql as well. The suite runs a bare
// `php <file>`, so the driver has to be arranged from inside. One re-exec,
// guarded by an environment flag so it can never loop.
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    if (getenv('SNAPSMACK_SS1641_REEXEC') === '1') {
        fwrite(STDERR, "FAIL: pdo_mysql is not available even with -d extension=pdo_mysql.\n"
            . "      This test proves the SMACKPRESS taxonomy contract against the engine\n"
            . "      the fleet actually runs. It will not fall back to sqlite, because an\n"
            . "      engine difference is one of the faults it exists to catch.\n");
        exit(1);
    }
    $self = escapeshellarg(__FILE__);
    $php  = escapeshellarg(PHP_BINARY);
    putenv('SNAPSMACK_SS1641_REEXEC=1');
    $code = 0;
    passthru("{$php} -d extension=pdo_mysql {$self}", $code);
    exit($code);
}

// ─────────────────────────────────────────────────────────────────────────────
// The MariaDB toolchain. Explicit, and a clear failure when it is not there.
// ─────────────────────────────────────────────────────────────────────────────
$binDir = (string)getenv('SNAPSMACK_MARIADB_BIN');
$needed = ['mariadb-install-db.exe', 'mariadbd.exe', 'mariadb.exe'];
if (PHP_OS_FAMILY !== 'Windows') {
    $needed = ['mariadb-install-db', 'mariadbd', 'mariadb'];
}
if ($binDir === '' || !is_dir($binDir)) {
    fwrite(STDERR, "FAIL: SNAPSMACK_MARIADB_BIN is not set to a MariaDB bin directory.\n"
        . "      This test drives the real SMACKPRESS endpoint against real MariaDB and\n"
        . "      DOES NOT SKIP, by PM ruling (room 9248): a skip standing in for a proof\n"
        . "      is how SS-1641 shipped unproven.\n"
        . "      Set it to a directory holding " . implode(', ', $needed) . ".\n");
    exit(1);
}
foreach ($needed as $exe) {
    if (!is_file($binDir . DIRECTORY_SEPARATOR . $exe)) {
        fwrite(STDERR, "FAIL: {$exe} is not in SNAPSMACK_MARIADB_BIN ({$binDir}).\n");
        exit(1);
    }
}
$bin = static fn (string $exe): string => escapeshellarg($binDir . DIRECTORY_SEPARATOR . $exe);

$schemaPath = $root . '/database/schema/snapsmack_canonical.sql';
if (!is_file($schemaPath)) {
    fwrite(STDERR, "FAIL: the canonical schema is missing at {$schemaPath}.\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "SMACKPRESS keeps the albums and categories it was not told about\n";
echo "  real handler, real HTTP, real MariaDB\n\n";
// ─────────────────────────────────────────────────────────────────────────────

$fails  = [];
$checks = 0;
function cx(bool $ok, string $what, string $saw = ''): void {
    global $fails, $checks;
    $checks++;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fails[] = $what;
    echo "  FAIL  $what" . ($saw !== '' ? "   [saw: $saw]" : '') . "\n";
}

/** A free localhost port. Asking the OS beats guessing and retrying. */
function ss1641_free_port(): int {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        fwrite(STDERR, "FAIL: could not bind a local port ({$errstr}).\n");
        exit(1);
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

function ss1641_rmtree(string $path): void {
    if (!is_dir($path)) { @unlink($path); return; }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ss1641-' . bin2hex(random_bytes(4));
@mkdir($work, 0777, true);
$dataDir  = $work . DIRECTORY_SEPARATOR . 'mariadb';
$siteDir  = $work . DIRECTORY_SEPARATOR . 'site';
$dbLog    = $work . DIRECTORY_SEPARATOR . 'mariadb.log';
$webLog   = $work . DIRECTORY_SEPARATOR . 'php-server.log';

$dbPort   = ss1641_free_port();
$webPort  = ss1641_free_port();
$password = bin2hex(random_bytes(16));
$key      = bin2hex(random_bytes(32));          // 64 hex: the Bearer shape

$db = null; $web = null; $pdo = null;

// Everything below runs inside this try so the two child processes and the
// temporary directory are always cleaned up, including on a failed assertion.
try {
    // ── MariaDB ──────────────────────────────────────────────────────────────
    exec($bin($needed[0]) . ' --datadir=' . escapeshellarg($dataDir)
        . ' --port=' . $dbPort . ' --password=' . escapeshellarg($password)
        . ' --silent 2>&1', $installOut, $installCode);
    cx($installCode === 0, 'a disposable MariaDB data directory was created',
       $installCode === 0 ? '' : implode(' | ', array_slice($installOut, -3)));
    if ($installCode !== 0) { throw new RuntimeException('install-db failed'); }

    $nullIn = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $dbDesc = [0 => ['file', $nullIn, 'r'],
               1 => ['file', $dbLog, 'w'], 2 => ['file', $dbLog, 'a']];
    $dbPipes = [];
    $db = proc_open(
        $bin($needed[1]) . ' --defaults-file=' . escapeshellarg($dataDir . DIRECTORY_SEPARATOR . 'my.ini')
            . ' --bind-address=127.0.0.1 --port=' . $dbPort,
        $dbDesc, $dbPipes
    );
    cx(is_resource($db), 'the database server process started');
    if (!is_resource($db)) { throw new RuntimeException('mariadbd did not start'); }

    $dsnNoDb = "mysql:host=127.0.0.1;port={$dbPort};charset=utf8mb4";
    $up = false;
    for ($i = 0; $i < 100; $i++) {          // bounded: ~15s, never forever
        try {
            $pdo = new PDO($dsnNoDb, 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $up = true;
            break;
        } catch (Throwable $e) {
            usleep(150000);
        }
    }
    cx($up, 'the database answered within the bounded wait',
       $up ? '' : 'see ' . $dbLog);
    if (!$up) { throw new RuntimeException('mariadbd never answered'); }

    $pdo->exec('CREATE DATABASE taxonomy CHARACTER SET utf8mb4');

    // The CANONICAL schema, loaded by the real client. Not a hand-typed
    // imitation: the shape under test is the shape the fleet runs.
    //
    // THREE THINGS HERE ARE SCAR TISSUE FROM WRITING THIS FILE, and every one
    // of them made the client hang rather than fail:
    //
    // 1. $_ENV IS NOT THE ENVIRONMENT. variables_order here is GPCS with no
    //    E, so $_ENV holds ZERO entries while getenv() holds 103. Passing
    //    ['MYSQL_PWD' => ...] + $_ENV handed the child an environment with the
    //    password and NOTHING ELSE -- no PATH, no SystemRoot -- and it stopped
    //    dead. Measured, not guessed: count($_ENV) is 0 on this install.
    // 2. STDIN IS GIVEN EXPLICITLY. Without descriptor 0 the child inherits
    //    the suite's stdin, so a client that decides to prompt for a password
    //    waits for an answer that will never come. Reading the null device, it
    //    gets EOF and exits instead.
    // 3. THE WAIT IS BOUNDED. proc_close() blocks for as long as the child
    //    likes, which is the same unbounded wait that made the old harness
    //    unkillable. This polls and terminates.
    $loadCmd = $bin($needed[2]) . ' -h127.0.0.1 -P' . $dbPort . ' -uroot --batch taxonomy < '
        . escapeshellarg($schemaPath);
    $loadDesc = [0 => ['file', $nullIn, 'r'], 1 => ['file', $dbLog, 'a'], 2 => ['file', $dbLog, 'a']];
    $loadPipes = [];
    $load = proc_open($loadCmd, $loadDesc, $loadPipes, null,
                      ['MYSQL_PWD' => $password] + getenv());
    $loadCode = 1;
    if (is_resource($load)) {
        for ($i = 0; $i < 600; $i++) {      // bounded: ~60s
            $status = proc_get_status($load);
            if (!$status['running']) { $loadCode = (int)$status['exitcode']; break; }
            usleep(100000);
        }
        if (proc_get_status($load)['running']) {
            proc_terminate($load);
            $loadCode = 1;
            cx(false, 'the schema load finished inside its bounded wait',
               'still running after 60s; see ' . $dbLog);
        }
        @proc_close($load);
    }
    cx($loadCode === 0, 'the canonical schema loaded into MariaDB',
       $loadCode === 0 ? '' : 'exit ' . $loadCode . '; see ' . $dbLog);
    if ($loadCode !== 0) { throw new RuntimeException('schema load failed'); }

    $pdo = new PDO($dsnNoDb . ';dbname=taxonomy', 'root', $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // ── the fixture rows ─────────────────────────────────────────────────────
    $pdo->exec("INSERT INTO snap_users (id,username,password_hash,user_role)
                VALUES (9,'fixture','fixture','admin')");
    // is_active is set EXPLICITLY rather than leaning on a schema default, so
    // the test does not quietly depend on one.
    $pdo->prepare("INSERT INTO snap_ohsnap_keys (label,key_type,key_hash,key_prefix,user_id,is_active)
                   VALUES ('SS-1641 fixture','smackpress',?,?,9,1)")
        ->execute([hash('sha256', $key), substr($key, 0, 8)]);
    $pdo->exec("INSERT INTO snap_posts (id,title,slug,post_type,user_id)
                VALUES (41,'Fixture','fixture','longform',9)");
    $pdo->exec("INSERT INTO snap_categories (id,cat_name,cat_slug)
                VALUES (3,'Street','street'),(4,'Landscape','landscape')");
    $pdo->exec("INSERT INTO snap_albums (id,album_name,album_slug)
                VALUES (7,'Car Show','car-show'),(8,'Winter','winter')");

    // ── the served copy of core/ ─────────────────────────────────────────────
    // core/db.php is the per-install file and is ABSENT from the repository,
    // which is what makes this clean rather than a stub. It is written into a
    // COPY so a crash can never leave a db.php in the working tree, where
    // SMACKBACK would rightly flag it as an unexpected file.
    @mkdir($siteDir . DIRECTORY_SEPARATOR . 'core', 0777, true);
    $copied = 0;
    foreach (glob($root . '/core/*.php') ?: [] as $file) {
        if (copy($file, $siteDir . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . basename($file))) {
            $copied++;
        }
    }
    cx($copied > 100, 'core/ was copied to a scratch web root', (string)$copied . ' files');
    cx(!is_file($root . '/core/db.php'),
       'the repository core/db.php is untouched, because it never existed');

    file_put_contents(
        $siteDir . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'db.php',
        "<?php\n\$pdo = new PDO('mysql:host=127.0.0.1;port={$dbPort};dbname=taxonomy;charset=utf8mb4',"
        . "'root','{$password}',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,"
        . "PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\n"
    );

    // ── the server, with FILE descriptors: see the note at the top ───────────
    $webDesc  = [0 => ['file', $nullIn, 'r'],
                 1 => ['file', $webLog, 'w'], 2 => ['file', $webLog, 'a']];
    $webPipes = [];
    $web = proc_open(
        escapeshellarg(PHP_BINARY) . ' -d extension=pdo_mysql -S 127.0.0.1:' . $webPort
            . ' -t ' . escapeshellarg($siteDir),
        $webDesc, $webPipes
    );
    cx(is_resource($web), 'the PHP server process started');
    if (!is_resource($web)) { throw new RuntimeException('php -S did not start'); }

    $url = "http://127.0.0.1:{$webPort}/core/smackpress-api.php?route=smackpress/posts";
    $answered = false;
    for ($i = 0; $i < 80; $i++) {           // bounded: ~12s
        $probe = @fsockopen('127.0.0.1', $webPort, $errno, $errstr, 0.3);
        if ($probe) { fclose($probe); $answered = true; break; }
        usleep(150000);
    }
    cx($answered, 'the endpoint answered within the bounded wait',
       $answered ? '' : 'see ' . $webLog);
    if (!$answered) { throw new RuntimeException('php -S never answered'); }

    /** One real POST to the real endpoint. */
    $post = static function (array $body) use ($url, $key): array {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$key}\r\n",
            'content'       => $payload,
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { $status = (int)$m[1]; }
        }
        return ['status' => $status, 'body' => (string)$raw,
                'json' => json_decode((string)$raw, true)];
    };

    $ids = static function (PDO $pdo, string $sql): array {
        return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    };

    // ── THE FOUR-STATE CONTRACT, DRIVEN THROUGH THE REAL ENDPOINT ────────────
    // SS-1612 settled this shape for the mosaic and it is the same here:
    // OMITTED preserves what is stored, an EMPTY LIST clears on purpose, and a
    // list sets exactly that list. The fifth case is the category alias, which
    // is a second spelling of the same field and used to miss the guard.
    echo "\n";
    $cases = [
        ['omitted — a save about the text alone touches neither map',
         [],                                     [7, 8], [3, 4]],
        ['both lists — each map becomes exactly what was sent',
         ['album_ids' => [8], 'cat_ids' => [3]], [8],    [3]],
        ['both empty — saying "no albums" is allowed and still clears',
         ['album_ids' => [], 'cat_ids' => []],   [],     []],
        ['albums only — the categories it said nothing about survive',
         ['album_ids' => [7]],                   [7],    [3, 4]],
        ['category_id alias only — the albums survive, and the alias still sets',
         ['category_id' => 4],                   [7, 8], [4]],
    ];

    foreach ($cases as $index => [$name, $fields, $wantAlbums, $wantCats]) {
        $pdo->exec('DELETE FROM snap_post_album_map WHERE post_id=41');
        $pdo->exec('DELETE FROM snap_post_cat_map   WHERE post_id=41');
        $pdo->exec('INSERT INTO snap_post_album_map (post_id,album_id) VALUES (41,7),(41,8)');
        $pdo->exec('INSERT INTO snap_post_cat_map   (post_id,cat_id)   VALUES (41,3),(41,4)');

        $marker = "Changed copy {$index}";
        $res = $post(['post_id' => 41, 'title' => 'Fixture', 'slug' => 'fixture',
                      'content' => "<p>{$marker}</p>"] + $fields);

        cx($res['status'] === 200 && ($res['json']['status'] ?? '') === 'ok'
           && (int)($res['json']['post_id'] ?? 0) === 41,
           "{$name}: the save was accepted",
           'HTTP ' . $res['status'] . ' ' . substr($res['body'], 0, 160));

        // THE SAVE MUST REALLY HAVE HAPPENED. Without this a handler that
        // refused every update would pass every map assertion below, because
        // nothing would have been deleted.
        $content = (string)$pdo->query('SELECT content FROM snap_posts WHERE id=41')
                               ->fetchColumn();
        cx(str_contains($content, $marker),
           "  and the text it was really about was written", substr($content, 0, 80));

        $albums = $ids($pdo, 'SELECT album_id FROM snap_post_album_map WHERE post_id=41 ORDER BY album_id');
        $cats   = $ids($pdo, 'SELECT cat_id   FROM snap_post_cat_map   WHERE post_id=41 ORDER BY cat_id');
        cx($albums === $wantAlbums, '  albums are ' . json_encode($wantAlbums),
           json_encode($albums));
        cx($cats === $wantCats,     '  categories are ' . json_encode($wantCats),
           json_encode($cats));
    }

    // ── the engine is the one the fleet runs, asserted rather than assumed ───
    echo "\n";
    $server = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    cx(stripos($server, 'maria') !== false || version_compare($server, '5.5', '>='),
       'this ran against a real MySQL-family server, not sqlite', $server);
    cx($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql',
       '  through the mysql driver');
} finally {
    foreach ([$web, $db] as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            // Bounded reap: a child that will not die must not hold the suite.
            for ($i = 0; $i < 40; $i++) {
                $status = proc_get_status($proc);
                if (!$status['running']) { break; }
                usleep(100000);
            }
            @proc_close($proc);
        }
    }
    $pdo = null;
    ss1641_rmtree($work);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($fails !== []) {
    echo 'FAIL (' . count($fails) . ' of ' . $checks . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS ({$checks} checks) — the real SMACKPRESS endpoint, over HTTP, against\n";
echo "real MariaDB, preserves the albums and categories a save did not mention,\n";
echo "and still clears them when a caller asks for that outright.\n";
exit(0);

// ===== SNAPSMACK EOF =====
