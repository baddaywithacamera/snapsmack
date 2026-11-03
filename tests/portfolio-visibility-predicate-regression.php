<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The portfolio visibility predicates decide correctly, and fail SAFE.
 *
 * WHAT THIS PROVES AND WHAT IT DOES NOT, stated first because the audit this
 * feature sits next to is about tests that prove less than they appear to.
 *
 * PROVED HERE, by executing real SQL against a real database engine:
 *   - the qualifying-owner rule: a draft owner does not keep an image visible,
 *     and a shared image survives when ANY published owner is not excluded;
 *   - an image with no owner at all is never touched;
 *   - the legacy snap_images.post_id route is honoured, not just the pivot;
 *   - the post-keyed filter excludes the flagged post and nothing else;
 *   - a listing and its count carry the IDENTICAL predicate;
 *   - and the absence fallback: with the columns missing, both helpers return
 *     '' and the queries still run instead of raising "Unknown column".
 *
 * NOT PROVED HERE, and nobody should read this file as proving it:
 *   - MySQL/MariaDB execution. This runs on SQLite because the dev box has no
 *     MySQL and PHP here ships every PDO driver disabled. The predicate text is
 *     identical except NOW(), which SQLite spells differently and which this
 *     harness rewrites for its own run only. NOW() is used throughout
 *     core/public-repository.php already, so it is not the part at risk; the
 *     qualifying-owner logic is the part I invented and that is what runs here.
 *   - that any PAGE honours the flag. This is the predicate, not the surface.
 *   - that the admin control SAVES. That needs the real route and is Codex's.
 *   - anything on an installed site. Unknown is not a pass.
 *
 * Run: php -d extension=pdo_sqlite tests/portfolio-visibility-predicate-regression.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(2);
}

require_once dirname(__DIR__) . '/core/public-repository.php';

$fail = 0;
function tx(bool $ok, string $what): void {
    global $fail;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

/** SQLite stand-in for the MySQL schema, with and without the flag columns. */
function make_db(bool $withFlags, bool $withDesignation = true, bool $withPrimary = true): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // $withDesignation separates the four EXCLUSION columns from the fifth
    // DESIGNATION column. Added after mutation proof: with the five always
    // arriving together, dropping the designation check from
    // portfolioSchemaReady() changed nothing observable and the suite passed.
    // A five-column save must not be certified by a four-column answer, which
    // is the exact readiness gap Codex raised.
    $flagCols = $withFlags
        ? ', exclude_from_archive INTEGER NOT NULL DEFAULT 0,
             exclude_from_search  INTEGER NOT NULL DEFAULT 0,
             exclude_from_feed    INTEGER NOT NULL DEFAULT 0'
          . ($withDesignation ? ', portfolio_post INTEGER NOT NULL DEFAULT 0' : '')
          . ($withPrimary ? ', primary_album_id INTEGER NULL, primary_category_id INTEGER NULL' : '')
        : '';
    $pdo->exec("CREATE TABLE snap_posts (
        id INTEGER PRIMARY KEY, title TEXT, slug TEXT,
        status TEXT NOT NULL DEFAULT 'published',
        created_at TEXT NOT NULL DEFAULT '2020-01-01 00:00:00'{$flagCols})");
    $pdo->exec("CREATE TABLE snap_images (
        id INTEGER PRIMARY KEY, img_slug TEXT, post_id INTEGER NULL,
        img_status TEXT NOT NULL DEFAULT 'published',
        img_date TEXT NOT NULL DEFAULT '2020-01-01 00:00:00')");
    $pdo->exec("CREATE TABLE snap_post_images (
        id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL, image_id INTEGER NOT NULL,
        sort_position INTEGER NOT NULL DEFAULT 0)");
    return $pdo;
}

/** The only rewrite: MySQL NOW() -> SQLite CURRENT_TIMESTAMP, for this run. */
function sqlite_sql(string $sql): string {
    return str_replace('NOW()', "CURRENT_TIMESTAMP", $sql);
}

/** Which image ids survive the archive predicate. */
function visible_images(PDO $pdo, string $filter): array {
    $sql = sqlite_sql(
        "SELECT i.id FROM snap_images i
          WHERE i.img_status='published' AND i.img_date <= NOW() {$filter}
          ORDER BY i.id"
    );
    return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

echo "Portfolio visibility predicates\n";

// ── 1. THE QUALIFYING-OWNER RULE ────────────────────────────────────────────
$pdo = make_db(true);
$repo = new SnapPublicRepository($pdo);

$pdo->exec("INSERT INTO snap_posts (id,slug,status,exclude_from_archive) VALUES
    (1,'portfolio-excluded','published',1),
    (2,'ordinary-visible','published',0),
    (3,'draft-excluded','draft',1),
    (4,'draft-not-excluded','draft',0)");
$pdo->exec("INSERT INTO snap_images (id,img_slug,post_id) VALUES
    (10,'only-in-excluded',NULL),
    (11,'shared-excluded-and-ordinary',NULL),
    (12,'orphan-no-post',NULL),
    (13,'only-in-a-draft',NULL),
    (14,'legacy-linked-to-excluded',1),
    (15,'owned-by-draft-not-excluded',NULL),
    -- THE DISCRIMINATING CASE. Owned by a PUBLISHED EXCLUDED post and also by
    -- a DRAFT NOT-excluded one. Correct answer: HIDDEN, because the draft is
    -- not a qualifying owner and cannot rescue it.
    --
    -- Added after mutation proof: without this row, deleting the
    -- published/created_at test from the is-any-owner-visible subquery
    -- changed NOTHING observable and the suite still passed. The draft-owner
    -- hole was the exact thing Codex caught in review, and my first test could
    -- not have caught it coming back.
    (16,'excluded-plus-draft-rescuer',NULL)");
$pdo->exec("INSERT INTO snap_post_images (post_id,image_id) VALUES
    (1,10),
    (1,11),(2,11),
    (3,13),
    (4,15),
    (1,16),(4,16)");

$f = $repo->imageFilter('exclude_from_archive', 'i');
tx($f !== '', 'imageFilter returns a predicate when the column exists');
$visible = visible_images($pdo, $f);

tx(!in_array(10, $visible, true),
   'HIDDEN: image whose only published owner is excluded');
tx(in_array(11, $visible, true),
   'VISIBLE: shared image kept by its ordinary published owner');
tx(in_array(12, $visible, true),
   'VISIBLE: orphan image with no post is never touched');
tx(in_array(13, $visible, true),
   'VISIBLE: a DRAFT excluded owner cannot hide an image (not a qualifying owner)');
tx(!in_array(14, $visible, true),
   'HIDDEN: legacy snap_images.post_id route is honoured, not only the pivot');
tx(in_array(15, $visible, true),
   'VISIBLE: a draft non-excluded owner is not an owner either, so no change');
tx(!in_array(16, $visible, true),
   'HIDDEN: a DRAFT non-excluded owner cannot rescue an image whose only '
   . 'published owner is excluded');

// ── 2. POST-KEYED FILTER ────────────────────────────────────────────────────
$pf = $repo->postFilter('exclude_from_archive', 'p');
tx($pf !== '', 'postFilter returns a predicate when the column exists');
$rows = $pdo->query(sqlite_sql(
    "SELECT p.id FROM snap_posts p
      WHERE p.status='published' AND p.created_at <= NOW() {$pf} ORDER BY p.id"
))->fetchAll(PDO::FETCH_COLUMN);
tx(array_map('intval', $rows) === [2],
   'post-keyed: the excluded published post is dropped and the ordinary one kept');

// ── 3. COUNT AND LISTING CARRY THE SAME PREDICATE ───────────────────────────
// The bug this prevents: count says N, list yields fewer, endless scroll never
// finishes. Asserting both call the SAME helper for the SAME flag is the check;
// asserting two numbers match is not, because both could be wrong together.
tx($repo->imageFilter('exclude_from_archive', 'i')
   === $repo->imageFilter('exclude_from_archive', 'i'),
   'the predicate is deterministic for one flag and alias');
$count = (int)$pdo->query(sqlite_sql(
    "SELECT COUNT(i.id) FROM snap_images i
      WHERE i.img_status='published' AND i.img_date <= NOW() {$f}"
))->fetchColumn();
$unfiltered = (int)$pdo->query(sqlite_sql(
    "SELECT COUNT(i.id) FROM snap_images i
      WHERE i.img_status='published' AND i.img_date <= NOW()"
))->fetchColumn();
tx($count === count($visible),
   "count ($count) equals the listing (" . count($visible) . ") under one predicate");
// Without this, the check above passes when the predicate filters NOTHING --
// which is exactly how the INFORMATION_SCHEMA probe bug got through its first
// run: 6 === 6 while no filtering happened at all.
tx($count < $unfiltered,
   "and the predicate actually removed rows ($count of $unfiltered), so the "
   . "equality above is not two unfiltered numbers agreeing");

// ── 4. ALIASES ──────────────────────────────────────────────────────────────
tx(str_contains($repo->postFilter('exclude_from_feed', 'x'), 'x.`exclude_from_feed`'),
   'postFilter honours a caller alias');
tx(str_contains($repo->postFilter('exclude_from_feed', ''), '`exclude_from_feed`'),
   'postFilter supports an unaliased FROM snap_posts');

// ── 5. UNKNOWN FLAG IS NOT INTERPOLATED ─────────────────────────────────────
tx($repo->postFilter('exclude_from_archive; DROP TABLE snap_posts', 'p') === '',
   'an unknown flag name yields no SQL at all, so nothing can be injected');
tx($repo->imageFilter('portfolio_post', 'i') === '',
   'portfolio_post is a designation and filters nothing, by design');

// ── 6. THE ABSENCE FALLBACK — the fleet-outage case ─────────────────────────
// core/updater.php is the only caller of snap_schema_sync(); no public
// bootstrap runs it. A site on new core files with an unsynced database must
// not 500 on a public page, which is what happened fleet-wide with
// fedi_enabled (core/threeacross-api.php:907).
$bare = make_db(false);
$bareRepo = new SnapPublicRepository($bare);
tx($bareRepo->visibilityFlagsPresent() === [],
   'with the columns absent, no flag is reported present');
tx($bareRepo->visibilityFlagsComplete() === false,
   'and the incomplete state is reportable, so absence is never silent');
tx($bareRepo->postFilter('exclude_from_archive', 'p') === '',
   'postFilter degrades to no filtering rather than an Unknown column fatal');
tx($bareRepo->imageFilter('exclude_from_archive', 'i') === '',
   'imageFilter degrades the same way');
$ran = true;
try {
    $bare->query(sqlite_sql(
        "SELECT i.id FROM snap_images i WHERE i.img_status='published'"
        . $bareRepo->imageFilter('exclude_from_archive', 'i')
    ))->fetchAll();
} catch (Throwable $e) { $ran = false; }
tx($ran, 'a public query still RUNS against a database without the columns');

tx((new SnapPublicRepository(make_db(true)))->visibilityFlagsComplete() === true,
   'with all four columns present the feature reports itself complete');

// ── 7. THE THREE STATES, all from Codex's review of the helper ──────────────
tx($bareRepo->schemaState() === 'absent',
   'a database genuinely missing the columns reports absent');
tx((new SnapPublicRepository(make_db(true)))->schemaState() === 'complete',
   'a database with them reports complete');

// 7a. DESIGNATION IS ASKED SEPARATELY. visibilityFlagsComplete() covers four
// exclusion columns; portfolio_post is a fifth and filters nothing, so a
// five-column admin save cannot take a four-column answer as its go-ahead.
$full = new SnapPublicRepository(make_db(true));
tx($full->designationAvailable() === true,
   'designationAvailable() sees portfolio_post when it exists');
tx($full->primaryTaxonomyAvailable() === true,
   'primaryTaxonomyAvailable() sees the two primary columns');
tx($full->portfolioSchemaReady() === true,
   'portfolioSchemaReady() certifies all SIX columns for the admin save');

// THE DISCRIMINATING CASE for the primary columns, added at the same time as
// the columns rather than after a mutant found it missing: everything present
// EXCEPT the two primary ones. Without this row, dropping
// primaryTaxonomyAvailable() from the gate would change nothing observable --
// the same hole the designation check had.
$noPrimary = new SnapPublicRepository(make_db(true, true, false));
tx($noPrimary->visibilityFlagsComplete() === true,
   'the exclusions alone still report complete');
tx($noPrimary->designationAvailable() === true,
   'and the designation is present');
tx($noPrimary->primaryTaxonomyAvailable() === false,
   'but the primary columns are correctly seen as missing');
tx($noPrimary->portfolioSchemaReady() === false,
   'so a six-column save is NOT certified without them');
$nd = new SnapPublicRepository(make_db(false));
tx($nd->designationAvailable() === false,
   'and reports it missing when it is');
tx($nd->portfolioSchemaReady() === false,
   'so the admin save is NOT certified on an unapplied schema');

// THE DISCRIMINATING CASE for readiness: all four EXCLUSIONS present, the
// DESIGNATION absent. Without this row the suite could not tell
// portfolioSchemaReady() from visibilityFlagsComplete() at all.
$half = new SnapPublicRepository(make_db(true, false));
tx($half->visibilityFlagsComplete() === true,
   'four exclusions alone DO satisfy visibilityFlagsComplete()');
tx($half->designationAvailable() === false,
   'while the designation column is correctly seen as missing');
tx($half->portfolioSchemaReady() === false,
   'so a five-column save is NOT certified by a four-column answer');

// 7b. A FAILING DATABASE IS NOT AN ABSENT SCHEMA. The first version caught
// every Throwable and called it "absent", which would have turned filtering
// off site-wide while reporting a tidy, wrong reason. A dropped table stands
// in for any non-schema failure here.
$broken = make_db(true);
$broken->exec("DROP TABLE snap_posts");
$brokenRepo = new SnapPublicRepository($broken);
tx($brokenRepo->visibilityFlagsPresent() === [],
   'an unanswerable database yields no flags, so nothing is filtered');
tx($brokenRepo->schemaState() === 'undetermined',
   'and it reports UNDETERMINED, never absent -- the two must not be confused');
tx($brokenRepo->visibilityFlagsComplete() === false
   && $brokenRepo->portfolioSchemaReady() === false,
   'an undetermined schema never certifies a save as supported');

echo $fail === 0
    ? "\nPASS - predicates decide correctly and degrade without filtering\n"
    : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
