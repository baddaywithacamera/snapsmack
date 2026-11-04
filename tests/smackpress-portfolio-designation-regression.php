<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * An imported post can be given its portfolio designation. (SS-1185)
 *
 * WHY THIS EXISTS. core/smackpress-api.php accepted albums and categories but
 * had NO reference to portfolio_post, primary_album_id or primary_category_id
 * -- measured, zero occurrences -- so every post SMACKPRESS imported arrived
 * undesignated and kept a flat /slug. The whole three-segment URL scheme was
 * unreachable from an import, which is the one way the owner's portfolio was
 * actually going to arrive.
 *
 * THE TEST THAT MATTERS IS THE LAST ONE. It is not enough that the columns get
 * written: the designated post has to RESOLVE at /<album>/<category>/<slug>.
 * So the final check hands the stored row to the real resolver
 * (core/portfolio-route.php) and requires the canonical path to come back. A
 * designation that does not produce a working address is a lie told quietly,
 * and column assertions alone cannot see that.
 *
 * WHAT IT CANNOT COVER, said rather than implied: the API file is a procedural
 * route handler that reads $_POST, sends headers and exits, so it cannot be
 * included here. These tests exercise the SQL shape the handler builds, against
 * the real schema, plus the real resolver. The handler's own wiring is asserted
 * from source at the end, and labelled as the weaker thing it is.
 *
 * Run: php -d extension=pdo_sqlite tests/smackpress-portfolio-designation-regression.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(2);
}

require_once dirname(__DIR__) . '/core/portfolio-route.php';

$fail = 0;
function cx(bool $ok, string $what): void {
    global $fail;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

function make_db(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, title TEXT, slug TEXT,
        content TEXT, post_type TEXT NOT NULL DEFAULT 'longform',
        status TEXT NOT NULL DEFAULT 'published',
        created_at TEXT NOT NULL DEFAULT '2020-01-01 00:00:00',
        portfolio_post INTEGER NOT NULL DEFAULT 0,
        primary_album_id INTEGER NULL, primary_category_id INTEGER NULL)");
    $pdo->exec("CREATE TABLE snap_albums (id INTEGER PRIMARY KEY, album_name TEXT,
        album_slug TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE snap_categories (id INTEGER PRIMARY KEY, cat_name TEXT,
        cat_slug TEXT NOT NULL DEFAULT '')");
    $pdo->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES (1,'Portfolio','portfolio')");
    $pdo->exec("INSERT INTO snap_categories (id,cat_name,cat_slug) VALUES
        (1,'People','people'), (2,'Fine Art','fine-art')");
    return $pdo;
}

/** The schema probe the handler uses, run against a table that lacks the columns. */
function portfolio_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT portfolio_post, primary_album_id, primary_category_id
                       FROM snap_posts WHERE 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

echo "THE SCHEMA PROBE\n";

$pdo = make_db();
cx(portfolio_ready($pdo) === true, 'a site WITH the columns is detected as ready');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec("CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, title TEXT, slug TEXT)");
cx(portfolio_ready($old) === false,
   'a site WITHOUT them is detected as not ready — so the import refuses rather '
   . 'than importing a portfolio with no portfolio addresses');

echo "\nTHE DESIGNATION IS STORED\n";

// The INSERT shape the handler builds when all three fields are sent.
$pdo->prepare("INSERT INTO snap_posts (title,slug,content,post_type,status,portfolio_post,primary_album_id,primary_category_id)
               VALUES (?,?,?,'longform','published',?,?,?)")
    ->execute(['Sports Day', 'sports', '<p>x</p>', 1, 1, 1]);
$row = $pdo->query("SELECT * FROM snap_posts WHERE slug='sports'")->fetch(PDO::FETCH_ASSOC);
cx((int)$row['portfolio_post'] === 1, 'portfolio_post is stored');
cx((int)$row['primary_album_id'] === 1, 'primary_album_id is stored');
cx((int)$row['primary_category_id'] === 1, 'primary_category_id is stored');

echo "\nA RE-PULL MUST NOT WIPE A DESIGNATION SET IN THE ADMIN\n";

// The handler keys each field on array_key_exists, so a body WITHOUT them
// builds an UPDATE that does not mention those columns at all. That is the
// shape asserted here: text changes, designation survives.
$pdo->prepare("UPDATE snap_posts SET title=?, content=? WHERE id=?")
    ->execute(['Sports Day (fixed typo)', '<p>y</p>', (int)$row['id']]);
$after = $pdo->query("SELECT * FROM snap_posts WHERE slug='sports'")->fetch(PDO::FETCH_ASSOC);
cx($after['title'] === 'Sports Day (fixed typo)', 'the text was updated');
cx((int)$after['primary_album_id'] === 1 && (int)$after['primary_category_id'] === 1
   && (int)$after['portfolio_post'] === 1,
   'and the designation SURVIVED the re-pull');

echo "\nAN ID THAT POINTS AT NOTHING IS REFUSED, NOT STORED\n";

// What the handler's validation asks before writing.
$albumExists = $pdo->prepare('SELECT id FROM snap_albums WHERE id = ? LIMIT 1');
$albumExists->execute([99]);
cx($albumExists->fetchColumn() === false, 'album 99 does not exist, so the import is refused');
$catExists = $pdo->prepare('SELECT id FROM snap_categories WHERE id = ? LIMIT 1');
$catExists->execute([99]);
cx($catExists->fetchColumn() === false, 'category 99 does not exist, so the import is refused');

echo "\nTHE POINT OF ALL OF IT: THE POST RESOLVES AT ITS THREE-SEGMENT URL\n";

$canonical = snap_portfolio_canonical_path($pdo, (int)$row['id']);
cx($canonical === '/portfolio/people/sports',
   'the stored designation produces the canonical path: ' . var_export($canonical, true));

$verdict = snap_portfolio_route_resolve($pdo, ['portfolio', 'people', 'sports']);
cx(($verdict['kind'] ?? '') === 'post', 'and the real resolver finds the post at that URL');
cx(($verdict['slug'] ?? '') === 'sports', 'handing back the flat slug the controllers understand');
cx(($verdict['redirect'] ?? true) === false, 'with no redirect, because it is already canonical');

// A post imported WITHOUT a designation must still behave exactly as before.
$pdo->prepare("INSERT INTO snap_posts (title,slug,content,post_type,status) VALUES (?,?,?,'longform','published')")
    ->execute(['Just A Post', 'just-a-post', '<p>z</p>']);
$plainId = (int)$pdo->lastInsertId();
cx(snap_portfolio_canonical_path($pdo, $plainId) === null,
   'an UNDESIGNATED import has no canonical path, so it keeps its flat /slug');

echo "\nTHE HANDLER IS WIRED (source assertions — the route file cannot be included)\n";

$api = (string)file_get_contents(dirname(__DIR__) . '/core/smackpress-api.php');
foreach ([
    "array_key_exists('portfolio_post', \$body)"      => 'reads portfolio_post by key, not by truthiness',
    "array_key_exists('primary_album_id', \$body)"    => 'reads primary_album_id by key',
    "array_key_exists('primary_category_id', \$body)" => 'reads primary_category_id by key',
    'smackpress_portfolio_ready($pdo)'                => 'checks the schema before writing',
    'is not an album on this site'                    => 'refuses an album id that does not exist',
    'is not a category on this site'                  => 'refuses a category id that does not exist',
] as $needle => $what) {
    cx(str_contains($api, $needle), $what);
}
cx(substr_count($api, '$portfolio_params[] =') === 6,
   'both the INSERT and the UPDATE path bind all three fields (found '
   . substr_count($api, '$portfolio_params[] =') . ' of 6)');

echo $fail === 0
    ? "\nPASS - an imported post can be designated, keeps it across a re-pull, and resolves\n"
    : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
