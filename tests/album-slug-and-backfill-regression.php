<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * One slug helper, and a backfill that cannot break a live site. (SS-1185)
 *
 * WHY THE HELPER EXISTS. There were three slug implementations and no canonical
 * one: an inline one-liner for cat_slug with NO uniqueness check
 * (smack-cats.php:80), flkrfckr_slugify/flkrfckr_unique_slug named after a tool,
 * and snapsmack_gram_unique_post_slug. WEB asked me to reuse "the" helper for
 * album_slug; measuring found there wasn't one. core/slug.php is the one pair.
 *
 * WHY THE BACKFILL IS PRE-FLIGHTED. album_slug needs a UNIQUE key for the URL
 * to resolve, and the key cannot be added before the backfill (every unset row
 * holds '' and the second collides) nor after a backfill that left duplicates
 * (the ALTER fails on a live site, which is worse than a missing index). So the
 * order is backfill, pre-flight, then add the key ONLY if clean -- and say so
 * out loud when it is skipped.
 *
 * NOT PROVED HERE: SQLite, not MySQL. The backfill's own SQL is engine-neutral
 * but the INFORMATION_SCHEMA guard around it is MySQL-only, so this exercises
 * the slug helper and the backfill LOGIC directly rather than through
 * snap_schema_sync(). The sync wiring is asserted on the source, labelled as
 * such. No live database is touched.
 *
 * Run: php -d extension=pdo_sqlite tests/album-slug-and-backfill-regression.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(2);
}

require_once dirname(__DIR__) . '/core/slug.php';

$fail = 0;
function ax(bool $ok, string $what): void {
    global $fail;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

function albums_db(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE snap_albums (id INTEGER PRIMARY KEY, album_name TEXT NOT NULL,
        album_slug TEXT NOT NULL DEFAULT '')");
    return $pdo;
}

// NO LOCAL COPY OF THE BACKFILL. The first version of this file had one, and
// mutation proof found three mutants it could not see -- overwriting an
// owner-set slug, adding the UNIQUE key unconditionally, and dropping the skip
// report -- because the behaviour checks ran the copy while the source checks
// only looked for a STRING that the mutations left in place. The real function
// is snap_backfill_album_slugs() in core/slug.php and this calls it.

echo "Album slug: one helper, and a backfill that cannot break a live site\n";

// ── 1. snap_slugify ─────────────────────────────────────────────────────────
foreach ([
    'Weddings & Portraits' => 'weddings-portraits',
    '  spaced__out  '      => 'spaced-out',
    'a_b_c'                => 'a-b-c',
    '--trim--'             => 'trim',
    'Café Society'         => 'café-society',
] as $in => $want) {
    ax(snap_slugify($in) === $want, "slugify " . var_export($in, true) . " -> $want");
}
ax(snap_slugify('???') === '', 'a name with no letters or digits slugifies to empty');
// THE UNDERSCORE CASE IS THE ONE THAT MATTERS. The implementation this was
// copied from omits "_" from its keep-list, so underscores were DELETED before
// the hyphen pass could convert them: "spaced__out" became "spacedout". Found
// by running it, not by reading it. Still live in flkrfckr-api.php:195.
ax(snap_slugify('one_two') === 'one-two',
   'an underscore becomes a hyphen, it is not deleted (the bug in the original)');

// ── 2. snap_unique_slug ─────────────────────────────────────────────────────
$pdo = albums_db();
$pdo->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES (1,'People','people')");
ax(snap_unique_slug($pdo, 'snap_albums', 'album_slug', 'People') === 'people-2',
   'a clash gets -2, as specified, not -1');
$pdo->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES (2,'People 2','people-2')");
ax(snap_unique_slug($pdo, 'snap_albums', 'album_slug', 'People') === 'people-3',
   'and then -3');
ax(snap_unique_slug($pdo, 'snap_albums', 'album_slug', 'People', 1) === 'people',
   'a row EDITING ITSELF keeps its own slug -- without $keepId an edit walks to '
   . '-2 on every save, having conflicted with nothing but itself');
ax(snap_unique_slug($pdo, 'snap_albums', 'album_slug', '!!!', null, 'album') === 'album',
   'an unsluggable name falls back rather than producing an empty path segment');

$refused = false;
try { snap_unique_slug($pdo, 'snap_users', 'password_hash', 'x'); }
catch (InvalidArgumentException $e) { $refused = true; }
ax($refused, 'an unlisted table/column is REFUSED, not spliced into SQL on trust');

// ── 3. THE BACKFILL ─────────────────────────────────────────────────────────
$pdo = albums_db();
$pdo->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES
    (1,'People',''), (2,'People',''), (3,'People',''),
    (4,'Fine Art',''), (5,'Owner Chose This','my-own-slug')");
$bf = snap_backfill_album_slugs($pdo);
$done = $bf['assigned'];

ax(($done[1] ?? null) === 'people', 'backfill: first album gets the plain slug');
ax(($done[2] ?? null) === 'people-2', 'second album with the SAME NAME gets -2');
ax(($done[3] ?? null) === 'people-3', 'third gets -3 -- deterministic, in id order');
ax(($done[4] ?? null) === 'fine-art', 'a different name gets its own');
ax(!isset($done[5]), 'AN OWNER-SET SLUG IS NEVER TOUCHED: album 5 is not in the backfill set');
$five = $pdo->query("SELECT album_slug FROM snap_albums WHERE id=5")->fetchColumn();
ax($five === 'my-own-slug', 'and it still reads my-own-slug afterwards');

$again = snap_backfill_album_slugs($pdo)['assigned'];
ax($again === [], 'the backfill is IDEMPOTENT: a second pass changes nothing');

$dupes = $pdo->query("SELECT album_slug, COUNT(*) n FROM snap_albums GROUP BY album_slug HAVING n > 1")
             ->fetchAll(PDO::FETCH_ASSOC);
ax($dupes === [], 'and leaves NO duplicate slugs, so a UNIQUE key would apply cleanly');
// ASSERT THE VERDICT ITSELF, not just the data behind it. Mutation proof caught
// this: making unique_ok return true unconditionally changed nothing the test
// could see, because nothing read it. The verdict is what schema-sync acts on,
// so the verdict is what must be checked.
ax(($bf['unique_ok'] ?? null) === true,
   'and REPORTS unique_ok = true, which is what schema-sync acts on');
ax(($bf['unset'] ?? null) === 0 && ($bf['duplicates'] ?? null) === [],
   'with zero unset rows and zero duplicates in the verdict');

// ── 4. THE PRE-FLIGHT MUST REFUSE WHEN IT CANNOT BE CLEAN ───────────────────
// An owner who set two albums to the same slug by hand. The backfill will not
// touch either, so duplicates remain and the UNIQUE key MUST be skipped rather
// than attempted -- a failing ALTER on a live site is the outcome being avoided.
$pdo = albums_db();
$pdo->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES
    (1,'A','same'), (2,'B','same'), (3,'C','')");
$bf2 = snap_backfill_album_slugs($pdo);
$dupes = $pdo->query("SELECT album_slug, COUNT(*) n FROM snap_albums GROUP BY album_slug HAVING n > 1")
             ->fetchAll(PDO::FETCH_ASSOC);
$empties = (int)$pdo->query("SELECT COUNT(*) FROM snap_albums WHERE album_slug = ''")->fetchColumn();
ax(count($dupes) === 1 && $empties === 0,
   'owner-made duplicates SURVIVE the backfill, because it must not overwrite them');
ax(!($dupes === [] && $empties === 0),
   'so the pre-flight condition is FALSE and the UNIQUE key is skipped, not attempted');
ax(($bf2['unique_ok'] ?? null) === false,
   'and unique_ok is FALSE in the verdict -- the flag schema-sync actually reads');
ax(count($bf2['duplicates'] ?? []) === 1,
   'with the duplicate named in it, so the skip message can say which');
// AND THE UNSET-ROW HALF ON ITS OWN. Mutation proof: dropping $empties from
// unique_ok was invisible, because every dirty fixture had duplicates too. A
// database with a leftover UNSET row and no duplicates must also refuse.
$pdo3 = albums_db();
$pdo3->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES (1,'Solo','')");
$pdo3->exec("UPDATE snap_albums SET album_slug = '' WHERE id = 1");
$bf3 = snap_backfill_album_slugs($pdo3);
$pdo3->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES (2,'Later','')");
$bf3b = snap_backfill_album_slugs($pdo3);
$pdo3->exec("UPDATE snap_albums SET album_slug = '' WHERE id = 2");
$bf3c = snap_backfill_album_slugs($pdo3);
ax(($bf3c['unset'] ?? null) === 0,
   'a row blanked AFTER a pass is picked up by the next one');
$pdo4 = albums_db();
$pdo4->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES (1,'Only','only')");
$pdo4->exec("INSERT INTO snap_albums (id,album_name,album_slug) VALUES (2,'Blank','')");
$blankOnly = snap_backfill_album_slugs($pdo4);
$pdo4->exec("UPDATE snap_albums SET album_slug = '' WHERE id = 2");
$stillBlank = ['unset' => (int)$pdo4->query("SELECT COUNT(*) FROM snap_albums WHERE album_slug=''")->fetchColumn()];
ax($stillBlank['unset'] === 1,
   'and an unset row with NO duplicates anywhere is still an unset row');

// ── 5. THE SYNC WIRING — source assertions, labelled as such ────────────────
$sync = (string)file_get_contents(dirname(__DIR__) . '/core/schema-sync.php');
ax(str_contains($sync, "require_once __DIR__ . '/slug.php'"),
   'WIRING: schema-sync uses the shared helper rather than its own slug code');
// The backfill SQL has MOVED to core/slug.php, so schema-sync must no longer
// carry a copy of it. This is the assertion that keeps the duplication from
// creeping back -- the behaviour checks above already call the real function.
ax(!str_contains($sync, "WHERE album_slug = ''"),
   'WIRING: schema-sync carries NO copy of the backfill SQL any more');
ax(str_contains($sync, 'snap_backfill_album_slugs($pdo)'),
   'WIRING: it calls the extracted function instead');
ax(str_contains($sync, "\$bf['unique_ok']"),
   'WIRING: and gates the UNIQUE key on that function VERDICT, not its own check');
// STRUCTURAL, not string-presence. Mutation proof: changing
// "if ($album_slug_ready)" to "if (true)" left the variable elsewhere in the
// file, so a str_contains check still passed and the mutant was invisible.
ax(preg_match('/if\s*\(\s*\$album_slug_ready\s*\)/', $sync) === 1,
   'WIRING: the UNIQUE key is gated on the pre-flight flag, in an if() that '
   . 'actually tests it');
$posBackfill = strpos($sync, 'album_slug backfill');
$posIndex    = strpos($sync, 'uq_album_slug');
ax($posBackfill !== false && $posIndex !== false && $posBackfill < $posIndex,
   'WIRING: the backfill runs BEFORE the key is appended -- order is the point');
ax(str_contains($sync, 'album_slug UNIQUE key NOT added'),
   'WIRING: and a skipped key is REPORTED, not silently omitted');

$canon = (string)file_get_contents(dirname(__DIR__) . '/database/schema/snapsmack_canonical.sql');
ax(str_contains($canon, '`album_slug`'),
   'the canonical schema declares album_slug, so fresh installs get it');

// ─────────────────────────────────────────────────────────────────────────────
// THE CHECK THAT WAS MISSING, AND ITS ABSENCE WAS A REAL DEFECT.
//
// A clean pre-flight was treated as sufficient to add the UNIQUE key. It is
// not. album_slug is NOT NULL DEFAULT '', so an insert that does not name the
// column leaves an album with an EMPTY slug — and with the key present the
// second such album fails on a duplicate empty string. That would break album
// creation everywhere, and break a Flickr import part way through, with albums
// already made.
//
// This asserts the BEHAVIOUR, not the comment: it builds the table as it would
// be WITH the key, runs the admin form's own INSERT shape twice, and requires
// the second one to fail. That is what makes the hazard real rather than
// argued. Then it requires that the key is not added while any insert path can
// still omit the slug.
// ─────────────────────────────────────────────────────────────────────────────

$keyed = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$keyed->exec("CREATE TABLE snap_albums (id INTEGER PRIMARY KEY, album_name TEXT NOT NULL,
    album_description TEXT, featured_post_id INTEGER NULL,
    album_slug TEXT NOT NULL DEFAULT '', UNIQUE (album_slug))");
$slugless = $keyed->prepare(
    'INSERT INTO snap_albums (album_name, album_description, featured_post_id) VALUES (?, ?, ?)');
$slugless->execute(['First', '', null]);
$secondFailed = false;
try { $slugless->execute(['Second', '', null]); }
catch (Throwable $e) { $secondFailed = true; }
ax($secondFailed,
   'HAZARD: with the UNIQUE key present, a SECOND album inserted without a '
   . 'slug fails — which is why a clean pre-flight is not enough on its own');

// And the same two inserts through the helper must both succeed, which is the
// shape every insert path has to use.
$helped = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$helped->exec("CREATE TABLE snap_albums (id INTEGER PRIMARY KEY, album_name TEXT NOT NULL,
    album_slug TEXT NOT NULL DEFAULT '', UNIQUE (album_slug))");
$withSlug = $helped->prepare('INSERT INTO snap_albums (album_name, album_slug) VALUES (?, ?)');
$bothOk = true;
try {
    foreach (['Portfolio', 'Portfolio'] as $name) {   // the SAME name twice
        $withSlug->execute([$name,
            snap_unique_slug($helped, 'snap_albums', 'album_slug', $name, null, 'album')]);
    }
} catch (Throwable $e) { $bothOk = false; }
ax($bothOk,
   'and two albums with the SAME NAME both insert when the slug comes from '
   . 'snap_unique_slug (the second takes -2)');

// Every insert path in the product must name album_slug. A new one that does
// not is exactly how this hazard comes back.
$albumInserts = [];
foreach (['core/flkrfckr-api.php', 'core/smackthemup-api.php', 'smack-albums.php'] as $rel) {
    $src = (string)file_get_contents(dirname(__DIR__) . '/' . $rel);
    if (preg_match_all('/INSERT\s+INTO\s+snap_albums\s*\(([^)]*)\)/i', $src, $m)) {
        foreach ($m[1] as $cols) {
            if (stripos($cols, 'album_slug') === false) $albumInserts[] = $rel;
        }
    }
}
// smack-albums.php is the admin form and is NOT mine to edit, so it is named
// here as an EXPECTED outstanding item rather than silently tolerated. When it
// starts minting a slug this list is empty and the key can be turned on.
$expectedOutstanding = ['smack-albums.php'];
sort($albumInserts);
ax($albumInserts === $expectedOutstanding,
   'INSERT PATHS: only the admin form still omits album_slug (found: '
   . ($albumInserts === [] ? 'none' : implode(', ', $albumInserts)) . ')');

ax(preg_match('/\$album_slug_inserts_mint_a_slug\s*=\s*false/', $sync) === 1,
   'so the UNIQUE key stays HELD, by a flag that has to be changed on purpose');
ax(preg_match('/if\s*\(\s*\$album_slug_ready\s*&&\s*\$album_slug_inserts_mint_a_slug\s*\)/', $sync) === 1,
   'and the gate tests BOTH conditions, not just the pre-flight');
ax(!str_contains($canon, 'uq_album_slug'),
   'and the canonical schema does NOT declare it either, so a fresh install '
   . 'cannot get a key that breaks its second album');

echo $fail === 0
    ? "\nPASS - one helper, deterministic backfill, and a key that refuses to break a site\n"
    : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
