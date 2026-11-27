<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A filename is not evidence. (SS-1624, PM order 8492)
 *
 * WHAT WAS REPORTED. The badday archive offered 633 "duplicates" for removal.
 * The identity rule falls back to img_source_file -- the original filename --
 * whenever img_checksum is empty, which it is for every row five of the import
 * paths ever wrote. Camera filenames repeat. So two unrelated photographs were
 * presented as one picture with a tick box next to the copy to delete.
 *
 * WHAT THIS FILE DRIVES. The real functions in core/duplicate-detection.php,
 * through the real SQL, against rows shaped like the ones that caused it:
 * a wrapped file counter on one camera, a re-import that kept its date, a
 * re-import that lost it, and a row that knows nothing about itself.
 *
 * SQLITE, NOT MYSQL. CONCAT is registered on the connection so the production
 * SQL runs unchanged; it is the only accommodation. The rule itself is PHP and
 * runs exactly as it does on a live site.
 *
 * NO LOCAL COPY OF THE RULE. Every expectation below comes out of
 * snap_manage_duplicate_groups() or snap_manage_duplicate_extra_count(). If a
 * check here passes while the shipped function is wrong, this file is broken.
 *
 * Run: php -d extension=pdo_sqlite tests/duplicate-detection-needs-corroboration-regression.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/duplicate-detection.php';

$fail = 0;
$ran  = 0;
function check(bool $ok, string $what): void {
    global $fail, $ran;
    $ran++;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

/**
 * A database shaped like the live one, with CONCAT so the shipped SQL runs.
 */
function dupe_db(): PDO {
    $concat = static function (...$parts): string {
        $out = '';
        foreach ($parts as $p) { $out .= (string)$p; }
        return $out;
    };
    // PHP 8.5 deprecated PDO::sqliteCreateFunction() in favour of the driver
    // subclass. Use whichever this interpreter has, so the run is clean on both
    // and a deprecation notice never hides a real one.
    if (class_exists('Pdo\Sqlite')) {
        $pdo = Pdo\Sqlite::connect('sqlite::memory:', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->createFunction('CONCAT', $concat, -1);
    } else {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->sqliteCreateFunction('CONCAT', $concat, -1);
    }
    $pdo->exec("CREATE TABLE snap_images (
        id INTEGER PRIMARY KEY,
        img_title TEXT NOT NULL DEFAULT '',
        img_slug TEXT NOT NULL DEFAULT '',
        img_description TEXT DEFAULT '',
        img_date TEXT NOT NULL DEFAULT '',
        img_file TEXT NOT NULL DEFAULT '',
        img_source_file TEXT DEFAULT NULL,
        img_checksum TEXT DEFAULT NULL,
        img_width INTEGER DEFAULT 0,
        img_height INTEGER DEFAULT 0,
        post_id INTEGER DEFAULT NULL)");
    $pdo->exec("CREATE TABLE snap_image_cat_map   (image_id INTEGER, cat_id INTEGER)");
    $pdo->exec("CREATE TABLE snap_image_album_map (image_id INTEGER, album_id INTEGER)");
    return $pdo;
}

/** Insert one solo image. Anything not named is left as the schema default. */
function img(PDO $pdo, int $id, array $row): void {
    $cols = array_merge([
        'img_title' => '', 'img_slug' => 'i' . $id, 'img_description' => '',
        'img_date' => '', 'img_file' => $id . '.jpg', 'img_source_file' => null,
        'img_checksum' => null, 'img_width' => 0, 'img_height' => 0,
        'post_id' => null,
    ], $row);
    $names = array_keys($cols);
    $pdo->prepare("INSERT INTO snap_images (id, " . implode(', ', $names) . ")
                   VALUES (?" . str_repeat(', ?', count($names)) . ")")
        ->execute(array_merge([$id], array_values($cols)));
}

/** The ids the shipped review would offer as one set, sorted, as strings. */
function sets_of(PDO $pdo): array {
    $out = [];
    foreach (snap_manage_duplicate_groups($pdo) as $g) {
        $ids = array_map(static fn($it) => (int)$it['id'], $g['items']);
        sort($ids);
        $out[] = implode(',', $ids);
    }
    sort($out);
    return $out;
}

echo "A filename is not evidence: the duplicate rule needs corroboration\n";

// ---------------------------------------------------------------------------
// THE BADDAY SHAPE. One camera, one filename, two different photographs. Both
// frames are 4000x3000 because every frame that camera takes is 4000x3000 --
// which is why dimensions alone cannot corroborate anything, and why two known
// and different dates end it.
// ---------------------------------------------------------------------------
$pdo = dupe_db();
img($pdo, 1, ['img_source_file' => 'DSC_0042.JPG', 'img_date' => '2014-06-02 11:04:00',
              'img_width' => 4000, 'img_height' => 3000, 'img_title' => 'Harbour']);
img($pdo, 2, ['img_source_file' => 'DSC_0042.JPG', 'img_date' => '2019-11-18 16:40:00',
              'img_width' => 4000, 'img_height' => 3000, 'img_title' => 'Back fence']);

check(sets_of($pdo) === [], 'a wrapped file counter is NOT a duplicate set');
check(snap_manage_duplicate_extra_count($pdo) === 0,
    'and the banner offers nothing to remove');

// The old rule did group them, which is the defect. Proven by the identity key
// itself: both rows still land on the same key, and the key is no longer enough.
$rows = snap_dupe_fetch_rows($pdo, false);
check(count($rows) === 2, 'both rows are still fetched');
check(($rows[0]['dup_key'] ?? 'x') === ($rows[1]['dup_key'] ?? 'y'),
    'THE KEY STILL MATCHES -- it is the corroboration that refuses them');
check(strncmp((string)$rows[0]['dup_key'], SNAP_DUPE_FILENAME_ARM,
    strlen(SNAP_DUPE_FILENAME_ARM)) === 0, 'and it is the filename arm doing it');

// ---------------------------------------------------------------------------
// A REAL RE-IMPORT. Same filename, same capture moment: that is the same
// photograph, and the owner should still be offered the choice.
// ---------------------------------------------------------------------------
$pdo = dupe_db();
img($pdo, 10, ['img_source_file' => 'IMG_1234.JPG', 'img_date' => '2021-03-04 10:12:33',
               'img_width' => 3024, 'img_height' => 4032]);
img($pdo, 11, ['img_source_file' => 'IMG_1234.JPG', 'img_date' => '2021-03-04 10:12:33',
               'img_width' => 3024, 'img_height' => 4032]);
check(sets_of($pdo) === ['10,11'], 'same filename AND same capture date IS a duplicate set');
check(snap_manage_duplicate_extra_count($pdo) === 1, 'one copy could be removed');

// ---------------------------------------------------------------------------
// A RE-IMPORT THAT LOST ITS DATE. One side has no usable date, so dimensions
// are allowed to corroborate -- this is the job the dimensions arm is for.
// ---------------------------------------------------------------------------
$pdo = dupe_db();
img($pdo, 20, ['img_source_file' => 'IMG_1234.JPG', 'img_date' => '2021-03-04 10:12:33',
               'img_width' => 3024, 'img_height' => 4032]);
img($pdo, 21, ['img_source_file' => 'IMG_1234.JPG', 'img_date' => '0000-00-00 00:00:00',
               'img_width' => 3024, 'img_height' => 4032]);
check(sets_of($pdo) === ['20,21'], 'a dateless re-import corroborates on dimensions');

// A dateless re-import at a DIFFERENT size is a different file, not a copy.
$pdo = dupe_db();
img($pdo, 30, ['img_source_file' => 'IMG_1234.JPG', 'img_date' => '2021-03-04 10:12:33',
               'img_width' => 3024, 'img_height' => 4032]);
img($pdo, 31, ['img_source_file' => 'IMG_1234.JPG', 'img_date' => '',
               'img_width' => 1024, 'img_height' => 768]);
check(sets_of($pdo) === [], 'a dateless row at another size is not a duplicate');

// ---------------------------------------------------------------------------
// A ROW THAT KNOWS NOTHING ABOUT ITSELF corroborates with nobody. Two of them
// together are not evidence either -- that was the bug in its purest form.
// ---------------------------------------------------------------------------
$pdo = dupe_db();
img($pdo, 40, ['img_source_file' => 'scan.jpg']);
img($pdo, 41, ['img_source_file' => 'scan.jpg']);
check(sets_of($pdo) === [], 'two rows with no date and no dimensions are not a set');
check(snap_manage_duplicate_extra_count($pdo) === 0, 'and nothing is offered for removal');

// ---------------------------------------------------------------------------
// A BUCKET SPLITS. Three rows share a filename; two are the same photograph and
// the third is not. The set is the two, and the third is left alone -- the old
// rule put all three in one group and ticked two of them.
// ---------------------------------------------------------------------------
$pdo = dupe_db();
img($pdo, 50, ['img_source_file' => 'P1000777.JPG', 'img_date' => '2018-08-01 09:00:00',
               'img_width' => 4608, 'img_height' => 3456]);
img($pdo, 51, ['img_source_file' => 'P1000777.JPG', 'img_date' => '2018-08-01 09:00:00',
               'img_width' => 4608, 'img_height' => 3456]);
img($pdo, 52, ['img_source_file' => 'P1000777.JPG', 'img_date' => '2022-01-09 14:30:00',
               'img_width' => 4608, 'img_height' => 3456]);
check(sets_of($pdo) === ['50,51'], 'a mixed bucket splits to the corroborated pair only');
check(snap_manage_duplicate_extra_count($pdo) === 1,
    'the banner counts one removable copy, not two');

// TRANSITIVE. A and B agree on the date, B and C only on dimensions because C
// has no date. All three are the same photograph.
$pdo = dupe_db();
img($pdo, 60, ['img_source_file' => 'a7r00123.arw', 'img_date' => '2020-05-05 08:00:00',
               'img_width' => 7952, 'img_height' => 5304]);
img($pdo, 61, ['img_source_file' => 'a7r00123.arw', 'img_date' => '2020-05-05 08:00:00',
               'img_width' => 7952, 'img_height' => 5304]);
img($pdo, 62, ['img_source_file' => 'a7r00123.arw', 'img_date' => '',
               'img_width' => 7952, 'img_height' => 5304]);
check(sets_of($pdo) === ['60,61,62'], 'corroboration is followed transitively');

// ---------------------------------------------------------------------------
// THE OTHER TWO ARMS ARE UNTOUCHED. A checksum is byte-identity and needs no
// corroborating; the title arm already carries the date inside its own key.
// Both must still group even when date and dimensions disagree entirely.
// ---------------------------------------------------------------------------
$pdo = dupe_db();
img($pdo, 70, ['img_checksum' => str_repeat('a', 64), 'img_date' => '2010-01-01 00:00:00',
               'img_width' => 100, 'img_height' => 100]);
img($pdo, 71, ['img_checksum' => str_repeat('a', 64), 'img_date' => '2024-12-31 23:59:00',
               'img_width' => 900, 'img_height' => 600]);
check(sets_of($pdo) === ['70,71'], 'a matching CHECKSUM still groups without corroboration');

$pdo = dupe_db();
img($pdo, 80, ['img_title' => 'Low tide', 'img_date' => '2016-02-02 07:00:00',
               'img_width' => 100, 'img_height' => 100]);
img($pdo, 81, ['img_title' => 'Low tide', 'img_date' => '2016-02-02 07:00:00',
               'img_width' => 900, 'img_height' => 600]);
check(sets_of($pdo) === ['80,81'], 'the TITLE+DATE arm still groups on its own key');

$pdo = dupe_db();
img($pdo, 90, []);
img($pdo, 91, []);
check(sets_of($pdo) === [], 'a row with no identity at all is never a duplicate');

// ---------------------------------------------------------------------------
// FILED IMAGES ARE NOT IN SCOPE. post_id IS NULL is the photoblog unit; a
// carousel member belongs to its post.
// ---------------------------------------------------------------------------
$pdo = dupe_db();
img($pdo, 100, ['img_source_file' => 'IMG_9.JPG', 'img_date' => '2021-01-01 00:00:00',
                'img_width' => 10, 'img_height' => 10, 'post_id' => 5]);
img($pdo, 101, ['img_source_file' => 'IMG_9.JPG', 'img_date' => '2021-01-01 00:00:00',
                'img_width' => 10, 'img_height' => 10, 'post_id' => 5]);
check(sets_of($pdo) === [], 'images belonging to a post are left out');

// ---------------------------------------------------------------------------
// THE KEEPER IS STILL THE MOST COMPLETE COPY, and the banner can no longer
// disagree with the review because both call the same function.
// ---------------------------------------------------------------------------
$pdo = dupe_db();
img($pdo, 110, ['img_source_file' => 'IMG_7.JPG', 'img_date' => '2021-01-01 00:00:00',
                'img_width' => 10, 'img_height' => 10]);
img($pdo, 111, ['img_source_file' => 'IMG_7.JPG', 'img_date' => '2021-01-01 00:00:00',
                'img_width' => 10, 'img_height' => 10, 'img_description' => 'the one with words']);
$pdo->exec("INSERT INTO snap_image_cat_map (image_id, cat_id) VALUES (111, 1)");
$groups = snap_manage_duplicate_groups($pdo);
check(count($groups) === 1 && (int)$groups[0]['keeper_id'] === 111,
    'the copy carrying a caption and a category is the keeper');

$pdo = dupe_db();
$expected = 0;
img($pdo, 120, ['img_source_file' => 'DSC_1.JPG', 'img_date' => '2014-01-01 00:00:00',
                'img_width' => 4000, 'img_height' => 3000]);
img($pdo, 121, ['img_source_file' => 'DSC_1.JPG', 'img_date' => '2019-01-01 00:00:00',
                'img_width' => 4000, 'img_height' => 3000]);
img($pdo, 122, ['img_source_file' => 'DSC_2.JPG', 'img_date' => '2015-01-01 00:00:00',
                'img_width' => 4000, 'img_height' => 3000]);
img($pdo, 123, ['img_source_file' => 'DSC_2.JPG', 'img_date' => '2015-01-01 00:00:00',
                'img_width' => 4000, 'img_height' => 3000]);
img($pdo, 124, ['img_checksum' => str_repeat('b', 64)]);
img($pdo, 125, ['img_checksum' => str_repeat('b', 64)]);
foreach (snap_manage_duplicate_groups($pdo) as $g) { $expected += count($g['items']) - 1; }
check(snap_manage_duplicate_extra_count($pdo) === $expected && $expected === 2,
    'THE BANNER AND THE REVIEW AGREE, because they are one function');

// ---------------------------------------------------------------------------
// ONE COPY OF THE RULE, IN SOURCE. The page used to hold the identity CASE
// twice: once in its own copy of the grouping function and once in the banner
// count, under a comment promising the two "mirror" each other. A mutant that
// changes only one copy has to be impossible, so there must be only one.
// ---------------------------------------------------------------------------
$page = (string)file_get_contents($root . '/smack-manage.php');
$core = (string)file_get_contents($root . '/core/duplicate-detection.php');

// Assembled from pieces so this check cannot match itself.
$needle = 'img_source_file' . ' IS NOT NULL';
check(substr_count($page, $needle) === 0,
    'smack-manage.php holds NO copy of the identity rule');
check(substr_count($core, $needle) === 1,
    'core/duplicate-detection.php holds exactly one');
check(str_contains($page, 'snap_manage_duplicate_extra_count($pdo)'),
    'the banner count calls the shared function');
check(str_contains($page, "require_once 'core/duplicate-detection.php'"),
    'and the page requires the file that defines it');

// The partition is reached through the fetch, not around it: both public
// entry points must run the corroboration. Driven, not read -- a row pair the
// old rule grouped comes back empty from BOTH.
$pdo = dupe_db();
img($pdo, 130, ['img_source_file' => 'X.JPG', 'img_date' => '2001-01-01 00:00:00',
                'img_width' => 4000, 'img_height' => 3000]);
img($pdo, 131, ['img_source_file' => 'X.JPG', 'img_date' => '2002-02-02 00:00:00',
                'img_width' => 4000, 'img_height' => 3000]);
check(snap_manage_duplicate_groups($pdo) === [] && snap_manage_duplicate_extra_count($pdo) === 0,
    'BOTH entry points refuse the uncorroborated pair');

echo "\n$ran checks, " . ($fail === 0 ? "all passed\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
