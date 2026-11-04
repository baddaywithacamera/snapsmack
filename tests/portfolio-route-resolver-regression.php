<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * /<album>/<category>/<slug> resolves, and nothing else changes. (SS-1185)
 *
 * The rule that matters most here is the one that is easiest to break without
 * noticing: EVERY EXISTING URL MUST BEHAVE EXACTLY AS IT DID BEFORE. There are
 * 25 live sites whose every photo link is a bare root slug. So most of this
 * file is about what the resolver REFUSES to claim, not about what it resolves.
 *
 * ONE CALL SITE, AND WHY THAT IS NOT A WEAKENING OF THE RULING. The approved
 * design said one resolver called by BOTH index.php and
 * core/public-controller.php. Measured: core/public-controller.php never sees a
 * path -- it is handed ['route'=>..., 'slug'=>...] already parsed -- and
 * index.php:141 is the only place in the product where a URL path becomes a
 * slug. The guard that replaces "assert both call sites" is therefore stronger
 * than it: this file asserts that the decision exists in exactly ONE file and
 * that NO second path parser has appeared anywhere. A one-sided fix is
 * impossible when there is one side, and a second side cannot be added without
 * failing this test.
 *
 * WHAT THE CALL-SITE CHECKS CANNOT DO, said plainly rather than implied:
 * index.php needs a web server, a database and a skin to run, so the four
 * assertions about it read its SOURCE. Source assertions are the exact thing
 * this repository keeps paying for -- a selector present in a file is not
 * behaviour -- so they are kept to the narrowest facts that would catch the
 * failure that actually happened before (the call deleted, or one branch of the
 * verdict dropped), and they are NOT counted as proof that the wiring serves a
 * page. That needs the rendered page, and it is listed as NOT CHECKED.
 *
 * Run: php -d extension=pdo_sqlite tests/portfolio-route-resolver-regression.php
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
    $pdo->exec("CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, slug TEXT,
        status TEXT NOT NULL DEFAULT 'published',
        created_at TEXT NOT NULL DEFAULT '2020-01-01 00:00:00',
        primary_album_id INTEGER NULL, primary_category_id INTEGER NULL)");
    $pdo->exec("CREATE TABLE snap_albums (id INTEGER PRIMARY KEY, album_name TEXT,
        album_slug TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE snap_categories (id INTEGER PRIMARY KEY, cat_name TEXT,
        cat_slug TEXT NOT NULL DEFAULT '')");
    return $pdo;
}

/** album 1 = portfolio, category 1 = people, post 1 = sports, designated to both. */
function seed(PDO $pdo): void {
    $pdo->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES
        (1,'Portfolio','portfolio'), (2,'Street','street'), (3,'No Slug Yet','')");
    $pdo->exec("INSERT INTO snap_categories (id,cat_name,cat_slug) VALUES
        (1,'People','people'), (2,'Landscape','landscape')");
    $pdo->exec("INSERT INTO snap_posts (id,slug,primary_album_id,primary_category_id) VALUES
        (1,'sports',1,1),
        (2,'undesignated',NULL,NULL),
        (3,'draft-one',1,1)");
    $pdo->exec("UPDATE snap_posts SET status='draft' WHERE id=3");
}

echo "PARSE - pure, no database\n";

cx(snap_portfolio_route_parse('portfolio/people/sports') === ['portfolio','people','sports'],
   'the three segments Sean named come back as three segments');
cx(snap_portfolio_route_parse('/portfolio/people/sports/') === ['portfolio','people','sports'],
   'leading and trailing slashes are trimmed, not counted as segments');
cx(snap_portfolio_route_parse('sports') === ['sports'],
   'a bare slug is one segment (every photo link on every live site)');
cx(snap_portfolio_route_parse('portfolio/people') === ['portfolio','people'],
   'two segments parse');
cx(snap_portfolio_route_parse('a/b/c/d') === null, 'four segments are refused');
cx(snap_portfolio_route_parse('') === null, 'an empty path is refused');
cx(snap_portfolio_route_parse('/') === null, 'a bare slash is refused');
cx(snap_portfolio_route_parse('a//b') === null, 'an empty inner segment is refused');
cx(snap_portfolio_route_parse('../etc/passwd') === null, 'traversal is refused');
cx(snap_portfolio_route_parse("a/b\0c") === null, 'a NUL byte is refused');
cx(snap_portfolio_route_parse('favicon.ico') === null, 'a dot means a file, refused');
cx(snap_portfolio_route_parse('a b/c') === null, 'a space is refused');
cx(snap_portfolio_route_parse('a%2Fb') === null, 'a percent sign is refused');
cx(snap_portfolio_route_parse('a?b=1') === null, 'a query character is refused');
cx(snap_portfolio_route_parse('<script>') === null, 'markup is refused');
// UNICODE SURVIVES, and this is not decoration. snap_slugify() in core/slug.php
// is Unicode-aware, so an album called "Café" has the slug "café". A test
// written with ASCII only would have let an ASCII-only allowlist through, and
// the album would have been unreachable at its own address.
cx(snap_portfolio_route_parse('café/people/sports') === ['café','people','sports'],
   'a non-ASCII letter survives, because snap_slugify mints them');
// UNDERSCORE SURVIVES for a measured reason: core/flkrfckr-api.php:195 DELETES
// underscores rather than converting them, so legacy slugs on disk can contain
// one. Refusing it here would make a resolver that cannot reach existing rows.
cx(snap_portfolio_route_parse('a_b') === ['a_b'],
   'an underscore survives, because legacy slugs on disk contain them');
cx(snap_portfolio_route_parse(str_repeat('x', 601)) === null,
   'a segment longer than snap_posts.slug (600) is refused');
cx(snap_portfolio_route_parse(str_repeat('x', 600)) === [str_repeat('x', 600)],
   'and a segment exactly 600 long is not');

echo "\nRESOLVE - what it refuses to claim\n";

$pdo = make_db(); seed($pdo);

// THE WHOLE POINT OF THESE FOUR. A one- or two-segment path must fall through
// EVEN WHEN IT NAMES A REAL ALBUM, because no album-by-slug page exists in this
// product and a resolver that claimed one would 404 or hijack addresses that
// work today on 25 sites.
cx(snap_portfolio_route_resolve($pdo, ['sports'])['kind'] === 'fallthrough',
   'one segment falls through');
cx(snap_portfolio_route_resolve($pdo, ['portfolio'])['kind'] === 'fallthrough',
   'one segment falls through EVEN WHEN it is a real album slug');
cx(snap_portfolio_route_resolve($pdo, ['portfolio','people'])['kind'] === 'fallthrough',
   'two segments fall through EVEN WHEN both are real');
cx(snap_portfolio_route_resolve($pdo, ['nope','people'])['kind'] === 'fallthrough',
   'two segments fall through when neither is');

cx(snap_portfolio_route_resolve($pdo, ['nope','people','sports'])['kind'] === 'fallthrough',
   'an unknown album falls through (no early 404)');
cx(snap_portfolio_route_resolve($pdo, ['portfolio','nope','sports'])['kind'] === 'fallthrough',
   'an unknown category falls through');
cx(snap_portfolio_route_resolve($pdo, ['portfolio','people','nope'])['kind'] === 'fallthrough',
   'an unknown post slug falls through');
// AN UNSET album_slug IS EMPTY, NOT NULL -- that is the backfill's contract in
// core/slug.php. An empty needle must never match an un-backfilled album, or
// album 3 would answer for a path with an empty first segment.
cx(snap_portfolio_find_album($pdo, '') === null,
   "an empty slug matches no album, so an un-backfilled album never answers");

echo "\nRESOLVE - what it does claim\n";

$hit = snap_portfolio_route_resolve($pdo, ['portfolio','people','sports']);
cx(($hit['kind'] ?? '') === 'post', 'the canonical path resolves to a post');
cx(($hit['slug'] ?? '') === 'sports', 'and hands back the FLAT slug, which the three controllers already understand');
cx(($hit['post_id'] ?? 0) === 1, 'and the right post id');
cx(($hit['redirect'] ?? true) === false, 'and does NOT redirect: it is already canonical (loop guard)');
cx(($hit['canonical'] ?? '') === '/portfolio/people/sports', 'and reports its own canonical path');

// A post reached through the WRONG album is a 301, not a 404: a link someone
// shared before the post moved must still land on the post.
$moved = snap_portfolio_route_resolve($pdo, ['street','people','sports']);
cx(($moved['kind'] ?? '') === 'post', 'the wrong album still finds the post');
cx(($moved['redirect'] ?? false) === true, 'and asks for a redirect');
cx(($moved['canonical'] ?? '') === '/portfolio/people/sports', 'to the canonical path');

$movedCat = snap_portfolio_route_resolve($pdo, ['portfolio','landscape','sports']);
cx(($movedCat['redirect'] ?? false) === true, 'the wrong CATEGORY also redirects');
cx(($movedCat['canonical'] ?? '') === '/portfolio/people/sports', 'to the same canonical path');

// NO DESIGNATION MEANS NO CANONICAL PATH, and therefore NO REDIRECT. Every post
// on every site is in this state today. A resolver that redirected here would
// send them all to '/' + null, or loop.
$undes = snap_portfolio_route_resolve($pdo, ['portfolio','people','undesignated']);
cx(($undes['kind'] ?? '') === 'post', 'an undesignated post still resolves');
// WRITTEN WITHOUT ?? ON PURPOSE, having got it wrong first: `?? 'x'` cannot
// distinguish a null canonical from a MISSING one, so the assertion could never
// be true and the first run failed on my own test rather than on the code. An
// assertion that cannot pass is as useless as one that cannot fail.
cx(array_key_exists('canonical', $undes) && $undes['canonical'] === null,
   'with a canonical key that is present and null');
cx(($undes['redirect'] ?? true) === false, 'and NO redirect, because there is nowhere to send it');

// VISIBILITY IS NOT DECIDED HERE, on purpose -- a second copy of the visibility
// predicate is how surfaces drift. A draft resolves to its slug and the
// existing resolution path 404s it, exactly as /draft-one 404s today.
$draft = snap_portfolio_route_resolve($pdo, ['portfolio','people','draft-one']);
cx(($draft['kind'] ?? '') === 'post',
   'a DRAFT resolves here: visibility is the existing read path\'s job, not a second copy');

echo "\nDUPLICATE SLUGS - deterministic, because cat_slug has no unique key\n";

// snap_categories carries PRIMARY KEY (id) and nothing else, and
// smack-cats.php:80 has never checked for a collision. Two categories CAN share
// a slug on a live site right now, so the resolver must not depend on row order.
$pdo->exec("INSERT INTO snap_categories (id,cat_name,cat_slug) VALUES (9,'People Again','people')");
cx(snap_portfolio_find_category($pdo, 'people') === 1,
   'the LOWEST id wins a duplicate category slug (id 1, not 9)');
$pdo->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES (9,'Portfolio Again','portfolio')");
cx(snap_portfolio_find_album($pdo, 'portfolio') === 1,
   'the LOWEST id wins a duplicate album slug too');

// THE REDIRECT LOOP, AND IT IS NOT HYPOTHETICAL. Mutation proof found the
// "$canonical !== $here" half of the loop guard BLIND, because no fixture here
// could make a MISMATCH whose canonical path equals the path requested. The
// duplicate album slug above is exactly that fixture: this post is designated
// to album 9, the lookup deterministically returns album 1, so the designation
// does not match -- yet both albums are slugged 'portfolio', so the canonical
// path is the path already being served. Without that half of the guard the
// visitor is 301'd to the address they are on, forever. A real live site can be
// in this state today, because cat_slug and album_slug have no unique key.
$pdo->exec("INSERT INTO snap_posts (id,slug,primary_album_id,primary_category_id)
            VALUES (4,'loopy',9,1)");
$loop = snap_portfolio_route_resolve($pdo, ['portfolio','people','loopy']);
cx(($loop['kind'] ?? '') === 'post', 'the duplicate-slug post resolves');
cx(($loop['canonical'] ?? '') === '/portfolio/people/loopy',
   'and its canonical path is the path requested');
cx(($loop['redirect'] ?? true) === false,
   'and it does NOT redirect to where it already is (the loop guard, now proved)');

echo "\nREQUEST VERDICT - the one call index.php makes\n";

cx(snap_portfolio_route_for_request($pdo, 'portfolio/people/sports')
   === ['action' => 'serve', 'slug' => 'sports'], 'canonical path -> serve the flat slug');
cx(snap_portfolio_route_for_request($pdo, 'street/people/sports')
   === ['action' => 'redirect', 'path' => '/portfolio/people/sports'], 'wrong album -> redirect');
cx(snap_portfolio_route_for_request($pdo, 'sports') === ['action' => 'none'],
   'a bare slug -> none, so index.php leaves it completely alone');
cx(snap_portfolio_route_for_request($pdo, 'favicon.ico') === ['action' => 'none'],
   'a file-shaped path -> none');
cx(snap_portfolio_route_for_request($pdo, 'a/b/c/d') === ['action' => 'none'],
   'four segments -> none');
cx(snap_portfolio_route_for_request($pdo, 'portfolio/people/undesignated')
   === ['action' => 'serve', 'slug' => 'undesignated'],
   'an undesignated post -> serve, never redirect');

echo "\nONE DECISION, ONE PLACE - the drift guard\n";

$root  = dirname(__DIR__);
$index = (string)file_get_contents($root . '/index.php');

// The four narrowest facts about the wiring. Each one is a thing that was
// actually deleted or dropped in an earlier repair on this codebase.
cx(str_contains($index, "require_once __DIR__ . '/core/portfolio-route.php'"),
   'index.php requires the resolver');
cx(str_contains($index, 'snap_portfolio_route_for_request($pdo, $requested_slug)'),
   'index.php calls it with the parsed path');
// THE WHOLE LINE, not "contains 301". Mutation proof found a looser version of
// this check blind: index.php already contains '301' in three other redirects,
// so gutting THIS one left the substring in place and the assertion passed. The
// target and the status code have to be in the same assertion as the call.
cx(str_contains($index,
     "header('Location: ' . rtrim(BASE_URL, '/') . \$_pf_route['path'], true, 301);"),
   'index.php 301s to BASE_URL + the canonical path from the verdict');
cx(str_contains($index, "\$requested_slug = \$_pf_route['slug']"),
   'index.php honours the serve verdict by replacing the slug');

// THE REPLACEMENT FOR "assert both call sites". The decision must exist in
// exactly one file, and no second path parser may appear. A one-sided fix is
// impossible when there is one side; a SECOND side fails here.
$callers = [];
foreach (['snap_portfolio_route_resolve', 'snap_portfolio_route_parse',
          'snap_portfolio_canonical_path', 'snap_portfolio_find_album',
          'snap_portfolio_find_category'] as $fn) {
    foreach (glob_php($root) as $file) {
        $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
        if ($rel === 'core/portfolio-route.php' || str_starts_with($rel, 'tests/')) continue;
        if (str_contains((string)file_get_contents($file), $fn . '(')) $callers[] = "$rel calls $fn";
    }
}
cx($callers === [],
   'no file outside core/portfolio-route.php calls the inner functions'
   . ($callers === [] ? '' : ': ' . implode('; ', $callers)));

// A SECOND PATH PARSER IS THE REAL DRIFT RISK, so it is measured rather than
// trusted. index.php is the public site's one parser; api.php is the JSON API
// and has always had its own. Anything else is a new copy of the decision.
$parsers = [];
foreach (glob_php($root) as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    if (str_starts_with($rel, 'tests/') || str_starts_with($rel, 'tools/')) continue;
    if ($rel === 'index.php' || $rel === 'api.php' || $rel === 'core/portfolio-route.php') continue;
    if (str_contains((string)file_get_contents($file), 'PATH_INFO')) $parsers[] = $rel;
}
cx($parsers === [],
   'no new reader of PATH_INFO has appeared'
   . ($parsers === [] ? '' : ': ' . implode(', ', $parsers)));

function glob_php(string $root): array {
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function ($file): bool {
                $name = $file->getFilename();
                if ($file->isDir()) {
                    return !in_array($name, ['.git', 'node_modules', 'vendor', 'uploads', 'opaudits'], true);
                }
                return str_ends_with($name, '.php');
            }
        )
    );
    foreach ($it as $file) $out[] = $file->getPathname();
    return $out;
}

echo $fail === 0
    ? "\nPASS - the new paths resolve, every existing path is untouched, one decision in one place\n"
    : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
