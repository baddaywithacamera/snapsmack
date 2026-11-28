<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// SS-1643 — THE IMPORTER MUST KEEP BOTH DATES, AND NOTHING MAY SUBSTITUTE
// ONE FOR THE OTHER.
//
// Sean: "fix the goddamn importer while you're at it. it should keep both
// dates." (2026-11-28, after /images on baddaywithacamera.ca came back in the
// wrong order.)
//
// WHAT WENT WRONG. tools/flkr-fckr/poster.py held
//     best_date = photo.date_taken or photo.create_date
// where create_date is the Flickr POSTED date (date_imported in the export)
// and date_taken is EXIF. Two facts went in, one came out, and the taken date
// won. For every photograph carrying EXIF -- nearly all of them -- the posted
// date was destroyed at import and written nowhere. Album 191 then sorted by
// a camera with a dead clock reporting 1980-01-01.
//
// A SECOND FAULT MADE IT INVISIBLE. core/pocket-album-feed.php mapped
// 'date' from the row, but the archivePhotographs() SELECT that feeds it never
// asked for that column. Measured on the live source: 0 of 100 photographs on
// page 1 of album 191 carried a non-empty date. A consumer cannot order by a
// column the query does not return.
//
// WHAT THIS TEST DOES THAT A READ OF THE SOURCE CANNOT. The dangerous repair
// here is a well-meaning fallback -- filling the posted date from the taken
// date when the first is missing -- because the result LOOKS populated and is
// wrong in exactly the way that caused the bug. So the cases below RUN the
// real payload builder with real rows and assert the two values stay apart. A
// test that only searched for the field name would pass against a back-fill.
//
// Run:  php tests/flickr-import-keeps-both-dates-regression.php

$root = dirname(__DIR__);
require_once $root . '/core/pocket-album-feed.php';

$failures = [];
$check = static function (string $what, bool $ok, string $detail = '') use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if ($detail !== '' && !$ok) { printf("        %s\n", $detail); }
    if (!$ok) { $failures[] = $what; }
};

/** A repository holding exactly the rows a case needs. */
final class BothDatesRepo
{
    /** @var list<array<string,mixed>> */
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function archivePhotographs(int $limit, int $offset, int $cat, int $album): array
    {
        return array_slice($this->rows, $offset, $limit);
    }
    public function publicAlbum(int $id): array
    {
        return ['id' => $id, 'album_name' => 'A Bad Day with a Camera', 'photo_count' => count($this->rows)];
    }
}

$row = static function (int $id, string $taken, ?string $posted): array {
    $r = [
        'id' => $id,
        'img_title' => 'Photograph ' . $id,
        'img_slug' => 'photograph-' . $id,
        'img_file' => 'img_uploads/p' . $id . '.jpg',
        'img_thumb_aspect' => 'img_uploads/thumbs/a_p' . $id . '.jpg',
        'img_thumb_square' => 'img_uploads/thumbs/t_p' . $id . '.jpg',
        'img_width' => 1700,
        'img_height' => 1134,
        'img_date' => $taken,
    ];
    if ($posted !== null) { $r['img_date_posted'] = $posted; }
    return $r;
};

echo "SS-1643 the importer keeps both dates\n";

// ---------------------------------------------------------------------------
// EXECUTED: the real payload builder, not a copy of it.
// ---------------------------------------------------------------------------
echo "\n  the feed carries both dates\n";

$taken  = '2024-02-25 16:40:00';
$posted = '2013-10-12 21:22:04';

$out = snapsmack_pocket_album_payload(
    new BothDatesRepo([$row(9909, $taken, $posted)]), 191, 1, 100, 'https://example.test'
);
$p = $out['photographs'][0] ?? [];

$check('the payload was actually built', ($out['ok'] ?? false) === true && $p !== [],
    'got: ' . substr((string)json_encode($out), 0, 200));
$check('date carries the taken date', ($p['date'] ?? '') === $taken,
    'got: ' . var_export($p['date'] ?? null, true));
$check('date_posted carries the posted date', ($p['date_posted'] ?? '') === $posted,
    'got: ' . var_export($p['date_posted'] ?? null, true));
$check('the two dates are not the same value', ($p['date'] ?? '') !== ($p['date_posted'] ?? ''));

// THE CASE THAT CATCHES A BACK-FILL. A photograph with a taken date and NO
// posted date must come out with the posted field empty. If a later edit
// "helpfully" falls back to the taken date, this is the case that fails, and
// it is the only one here that can tell the difference.
echo "\n  an unknown posted date stays unknown\n";

$out2 = snapsmack_pocket_album_payload(
    new BothDatesRepo([$row(9910, $taken, null)]), 191, 1, 100, 'https://example.test'
);
$q = $out2['photographs'][0] ?? [];

$check('the payload was actually built (no-posted case)', ($out2['ok'] ?? false) === true && $q !== [],
    'got: ' . substr((string)json_encode($out2), 0, 200));
$check('date still carries the taken date', ($q['date'] ?? '') === $taken,
    'got: ' . var_export($q['date'] ?? null, true));
$check('the posted field is empty, NOT the taken date', ($q['date_posted'] ?? 'MISSING') === '',
    'got: ' . var_export($q['date_posted'] ?? null, true));
$check('the posted field did not borrow from date', ($q['date_posted'] ?? '') !== $taken);

// An empty-string posted date is also unknown, and must not become the taken
// date either.
$out3 = snapsmack_pocket_album_payload(
    new BothDatesRepo([$row(9911, $taken, '')]), 191, 1, 100, 'https://example.test'
);
$r3 = $out3['photographs'][0] ?? [];
$check('an empty posted date stays empty', ($r3['date_posted'] ?? 'MISSING') === '',
    'got: ' . var_export($r3['date_posted'] ?? null, true));

// ---------------------------------------------------------------------------
// The read path must RETURN both columns. The feed can only map what the
// query selects; that mismatch is what made the field silently empty.
// ---------------------------------------------------------------------------
echo "\n  the archive query returns both columns\n";

// RUN THE REAL QUERY, TWICE, AGAINST TWO SCHEMAS. Reading the SELECT for a
// column name proves nothing useful here, and the first version of this check
// did exactly that and matched a different method's query.
//
// What actually matters has two halves, and they pull against each other:
//   - on a synced install the archive query must RETURN the posted date, or
//     the feed has nothing to map and ships an empty string, which is the
//     original fault;
//   - on an install whose schema has NOT been synced the same query must
//     still work. Referencing the column unconditionally made the images-page
//     fixture die with "no such column: i.img_date_posted". That fixture
//     stands in for every site that takes the release before anything has
//     triggered the defensive ALTER, and the public archive page is what
//     would have gone down on them.
require_once $root . '/core/public-repository.php';

$makeDb = static function (bool $withPostedColumn): PDO {
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = class_exists('Pdo\Sqlite')
        ? Pdo\Sqlite::connect('sqlite::memory:', null, null, $opts)
        : new PDO('sqlite::memory:', null, null, $opts);
    $pdo->createFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $posted = $withPostedColumn ? ', img_date_posted TEXT DEFAULT NULL' : '';
    $pdo->exec("CREATE TABLE snap_images (
        id INTEGER PRIMARY KEY, img_title TEXT, img_slug TEXT, img_alt TEXT, img_file TEXT,
        img_width INTEGER DEFAULT 0, img_height INTEGER DEFAULT 0,
        img_thumb_square TEXT DEFAULT '', img_thumb_aspect TEXT DEFAULT '',
        img_orientation INTEGER DEFAULT 0, allow_comments INTEGER DEFAULT 1,
        img_status TEXT DEFAULT 'published', img_date TEXT DEFAULT '2024-02-25 16:40:00',
        img_description TEXT DEFAULT '', sort_order INTEGER DEFAULT 0,
        exclude_from_archive INTEGER DEFAULT 0{$posted})");
    $pdo->exec("CREATE TABLE snap_albums (
        id INTEGER PRIMARY KEY, album_name TEXT, album_description TEXT DEFAULT '',
        featured_post_id INTEGER NULL, view_count INTEGER DEFAULT 0)");
    $pdo->exec("CREATE TABLE snap_image_album_map (image_id INTEGER, album_id INTEGER)");
    $pdo->exec("CREATE TABLE snap_image_cat_map (image_id INTEGER, cat_id INTEGER)");
    $pdo->exec("CREATE TABLE snap_posts (
        id INTEGER PRIMARY KEY, signature_image_id INTEGER NULL,
        status TEXT DEFAULT 'published', created_at TEXT DEFAULT '2024-01-01 00:00:00')");
    $pdo->exec("CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)");
    $pdo->exec("INSERT INTO snap_albums (id, album_name) VALUES (191, 'A Bad Day with a Camera')");
    $cols = 'id, img_title, img_slug, img_file, img_date' . ($withPostedColumn ? ', img_date_posted' : '');
    $vals = "9909, 'Encroaching Storm', 'encroaching-storm', 'img_uploads/p.jpg', '2024-02-25 16:40:00'"
          . ($withPostedColumn ? ", '2013-10-12 21:22:04'" : '');
    $pdo->exec("INSERT INTO snap_images ({$cols}) VALUES ({$vals})");
    $pdo->exec("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (9909, 191)");
    return $pdo;
};

$synced = null;
$syncedErr = '';
try {
    $synced = (new SnapPublicRepository($makeDb(true)))->archivePhotographs(10, 0, 0, 191);
} catch (Throwable $e) {
    $syncedErr = $e->getMessage();
}
$check('the archive query runs on a synced schema', is_array($synced) && count($synced) === 1, $syncedErr);
$check('it returns the taken date', ($synced[0]['img_date'] ?? '') === '2024-02-25 16:40:00',
    'got: ' . var_export($synced[0]['img_date'] ?? null, true));
$check('it returns the posted date', ($synced[0]['img_date_posted'] ?? '') === '2013-10-12 21:22:04',
    'got: ' . var_export($synced[0]['img_date_posted'] ?? null, true));

$old = null;
$oldErr = '';
try {
    $old = (new SnapPublicRepository($makeDb(false)))->archivePhotographs(10, 0, 0, 191);
} catch (Throwable $e) {
    $oldErr = $e->getMessage();
}
$check('the SAME query still runs where the column does not exist', is_array($old) && count($old) === 1,
    $oldErr !== '' ? 'threw: ' . $oldErr : 'returned ' . var_export($old, true));
$check('it still returns the taken date there', ($old[0]['img_date'] ?? '') === '2024-02-25 16:40:00',
    'got: ' . var_export($old[0]['img_date'] ?? null, true));
$check('and simply omits the posted date rather than inventing one',
    !array_key_exists('img_date_posted', $old[0] ?? ['img_date_posted' => 'present']));

// ---------------------------------------------------------------------------
// The import INSERT must stay aligned. Adding a column to one of the three
// lists and not the others is the classic way this breaks, and PDO reports it
// as an unhelpful count mismatch at runtime, on a live import.
// ---------------------------------------------------------------------------
echo "\n  the import INSERT stays aligned\n";

$api = (string)file_get_contents($root . '/core/flkrfckr-api.php');
$i = strpos($api, 'INSERT INTO snap_images (');
$stmt = $i === false ? '' : substr($api, $i, 1400);
$check('the import INSERT was located', $stmt !== '');

$cols = [];
$vals = [];
$holders = 0;
if ($stmt !== '') {
    $colPart = substr($stmt, (int)strpos($stmt, '(') + 1);
    $colPart = substr($colPart, 0, (int)strpos($colPart, ')'));
    foreach (explode(',', $colPart) as $c) {
        $c = trim($c);
        if ($c !== '') { $cols[] = $c; }
    }
    $vStart = (int)strpos($stmt, 'VALUES (');
    $vEnd = (int)strpos($stmt, ')', $vStart);
    $holders = substr_count(substr($stmt, $vStart, $vEnd - $vStart), '?');

    $marker = '")->execute([';
    $eStart = strpos($stmt, $marker);
    if ($eStart !== false) {
        $eEnd = (int)strpos($stmt, ']);', $eStart);
        $ePart = substr($stmt, $eStart + strlen($marker), $eEnd - $eStart - strlen($marker));
        foreach (explode(',', $ePart) as $v) {
            $v = trim($v);
            if ($v !== '') { $vals[] = $v; }
        }
    }
}

$check('columns, placeholders and values are the same count',
    count($cols) > 0 && count($cols) === $holders && $holders === count($vals),
    sprintf('columns=%d placeholders=%d values=%d', count($cols), $holders, count($vals)));

$ci = array_search('img_date_posted', $cols, true);
$vi = null;
foreach ($vals as $n => $v) {
    if (str_contains($v, 'img_date_posted')) { $vi = $n; break; }
}
$check('the posted date is in the column list', $ci !== false);
$check('the posted date has a bound value', $vi !== null);
$check('its column and its value sit at the same position', $ci !== false && $ci === $vi,
    sprintf('column index=%s value index=%s', var_export($ci, true), var_export($vi, true)));

// ---------------------------------------------------------------------------
// The importer must send the posted date, derived from the SOURCE date only.
// ---------------------------------------------------------------------------
echo "\n  the importer sends the posted date\n";

$poster = (string)file_get_contents($root . '/tools/flkr-fckr/poster.py');
$check('the poster was read', $poster !== '');
$check('the posted date is put in the payload', str_contains($poster, "'img_date_posted':"));

// Pull the assignment itself. The rule is that it comes from the source date
// and NOTHING else -- if a later edit writes the taken-date fallback back in
// here, the posted date silently becomes the taken date again.
$aStart = strpos($poster, 'img_date_posted_str = (');
$assign = $aStart === false ? '' : substr($poster, $aStart, 180);
$check('the posted-date assignment was located', $assign !== '');
$check('it reads the source date', str_contains($assign, 'create_date'),
    'assignment: ' . trim($assign));
$check('it does NOT read the taken date', $assign !== '' && !str_contains($assign, 'date_taken'),
    'assignment: ' . trim($assign));

// The taken date keeps its old meaning and precedence, so nothing that already
// reads it changes behaviour.
$check('the taken date keeps its existing precedence',
    str_contains($poster, 'best_date = photo.date_taken or photo.create_date'));

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === []) {
    echo "PASS — both dates survive the import and neither stands in for the other\n";
    exit(0);
}
printf("FAIL — %d check(s) failed:\n", count($failures));
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);

// ===== SNAPSMACK EOF =====
