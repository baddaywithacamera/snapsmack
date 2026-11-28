<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A partly-filled POCKET ALBUM cache sorts as ONE date order. (SS-1659)
 *
 * WHY THIS EXISTS. SS-1644 made the album sort on the date the source
 * published each photograph, and SS-1644's backfill guard fills that date
 * ONLY where the source published at least a day after capture -- about a
 * third of album 191, because the Flickr export's other two-thirds carry a
 * publication date within an hour of the shutter.
 *
 * The first ordering put every row WITH a posted date ahead of every row
 * without. On an all-or-nothing cache that is right. The moment the backfill
 * lands it is wrong: /images would show a third sorted newest-first, then
 * two-thirds in the old album order, which reads as still broken. Noticed by
 * the PM before the fill ran, which is the only reason Sean did not see it.
 *
 * THE FIX, and why it does not undo SS-1644: an unfilled row falls back to its
 * CAPTURE date and joins the same ordering. That is sound because of what the
 * guard skips -- a row is skipped only when posted is less than a day after
 * taken, so for those rows the capture date IS the posting date to within a
 * day. The rows where they differ wildly, the 1980 dead-clock frames posted in
 * 2024, are exactly the ones that get FILLED. They sort on the real posting
 * date and never on the dead clock.
 *
 * THIS TEST RUNS THE REAL CLAUSE. It reads the ORDER BY out of
 * core/pocket-album-display.php and executes it against a database holding the
 * mix -- filled rows, unfilled rows, a dead-clock row, and rows with no date
 * at all. A string match on the SQL would have passed the bare-COALESCE
 * version below, which changes nothing.
 *
 * Run: php -d extension=pdo_sqlite tests/pocket-album-one-date-order-regression.php
 */

$root = dirname(__DIR__);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "FAIL: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    exit(1);
}

$fails  = [];
$checks = 0;
function ox(bool $ok, string $what, string $saw = ''): void {
    global $fails, $checks;
    $checks++;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fails[] = $what;
    echo "  FAIL  $what" . ($saw !== '' ? "   [saw: $saw]" : '') . "\n";
}

echo "A partly-filled POCKET ALBUM cache sorts as one date order\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE CLAUSE IS TAKEN FROM THE SHIPPING FILE, NOT RETYPED HERE\n";
// ─────────────────────────────────────────────────────────────────────────────
$src = (string)file_get_contents($root . '/core/pocket-album-display.php');
ox($src !== '', 'core/pocket-album-display.php was read');

// The two lines that build the ordering. Read out of the file so this test
// cannot pass against a clause the product does not use.
ox(preg_match('~\$sortDate\s*=\s*"([^"]+)"~', $src, $m) === 1,
   'the sort-date expression is where this test expects it');
$sortDate = $m[1] ?? '';
echo "        {$sortDate}\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNULLIF, NOT A BARE COALESCE — THE WHOLE POINT\n";
// ─────────────────────────────────────────────────────────────────────────────
// Both columns are VARCHAR(40) NOT NULL DEFAULT '', so posted_on is NEVER
// null. COALESCE(posted_on, taken_on) returns '' for every unfilled row and
// the fallback never fires: it reads like a fix and changes nothing. This is
// the assertion that fails if somebody "simplifies" it back.
ox(str_contains($src, "NULLIF(posted_on,'')"),
   'the posted date is NULLIF-ed, so an empty string can fall back');
ox(str_contains($src, "NULLIF(taken_on,'')"),
   '  and so is the capture date, so a row with neither sorts as undated');
// COMMENTS STRIPPED FIRST, and that is not fussiness: this assertion FAILED
// on its first run because the product file's own comment EXPLAINS the
// bare-COALESCE trap, so the pattern matched the explanation rather than any
// code. Third time today that a check of mine matched its own needle. The
// needle has to be code.
$code = (string)preg_replace('~//[^
]*|/\*.*?\*/|^\s*--.*$~ms', '', $src);
ox(!preg_match('~COALESCE\(\s*posted_on\s*,~', $code),
   '  and a bare COALESCE(posted_on, ...) is NOT what ships',
   'a bare COALESCE would be a silent no-op on NOT NULL columns');

ox(str_contains($src, 'NOT NULL DEFAULT \'\''),
   'the columns really are NOT NULL DEFAULT \'\', which is why NULLIF is needed');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND NOW IT IS RUN, AGAINST THE MIX THE BACKFILL ACTUALLY LEAVES\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE snap_pocket_album_items (
    id INTEGER PRIMARY KEY, source_url TEXT, album_id INTEGER, title TEXT,
    taken_on TEXT NOT NULL DEFAULT '', posted_on TEXT NOT NULL DEFAULT '',
    sort_rank INTEGER NOT NULL DEFAULT 0)");

// The rows are the real shapes, not invented ones:
//   dead-clock  the 1980 EXIF frame, FILLED by the guard at 2024-02-25
//   filled      a normal photograph the source published months later
//   skipped     posted within an hour of capture, so the guard left it empty
//   undated     a row cached before the source served either field
$rows = [
    // id, title,        taken_on,              posted_on,             rank
    [1, 'dead-clock',   '1980-01-01 00:01:08', '2024-02-25 21:35:53',  10],
    [2, 'filled-older', '2019-05-02 10:00:00', '2021-01-01 09:00:00',  20],
    [3, 'skipped-new',  '2025-07-27 12:00:00', '',                     30],
    [4, 'skipped-mid',  '2023-03-03 08:00:00', '',                     40],
    [5, 'undated-a',    '',                    '',                      7],
    [6, 'undated-b',    '',                    '',                      9],
];
$insert = $pdo->prepare("INSERT INTO snap_pocket_album_items
    (id, source_url, album_id, title, taken_on, posted_on, sort_rank)
    VALUES (?, 'https://src', 191, ?, ?, ?, ?)");
foreach ($rows as $r) { $insert->execute([$r[0], $r[1], $r[2], $r[3], $r[4]]); }

// THE ORDER BY IS EXTRACTED, NOT ASSEMBLED HERE, and a mutant is why.
//
// The first version of this test read $sortDate out of the file and then
// BUILT THE BUCKET CLAUSE ITSELF. So a mutant that reverted $postedOrder to
// the old two-block clause -- the exact regression this file exists to stop --
// SURVIVED, because the test went on constructing the new clause from a
// $sortDate the mutant had left alone. I was executing an ordering of my own
// and calling it the product's.
//
// Both halves now come out of the source: the $postedOrder concatenation is
// read whole, its double-quoted pieces joined the way PHP joins them, and
// {$sortDate} interpolated as PHP would. Change EITHER line in the product
// and this test changes with it.
if (!preg_match('~\$postedOrder\s*=\s*((?:"[^"]*"\s*\.?\s*)+);~', $src, $pm)) {
    ox(false, 'the assembled ordering clause is where this test expects it');
    $postedOrderSql = '';
} else {
    $pieces = [];
    preg_match_all('~"([^"]*)"~', $pm[1], $qm);
    foreach ($qm[1] as $piece) { $pieces[] = $piece; }
    $postedOrderSql = str_replace('{$sortDate}', $sortDate, implode('', $pieces));
    ox($postedOrderSql !== '', 'the assembled ordering clause was extracted',
       $postedOrderSql);
}
ox(str_contains($postedOrderSql, $sortDate),
   '  and it really uses the sort-date expression, not a second spelling');

$order = $postedOrderSql . "sort_rank DESC, id DESC";
$got = $pdo->query("SELECT title FROM snap_pocket_album_items
                    WHERE source_url = 'https://src' AND album_id = 191
                    ORDER BY {$order}")->fetchAll(PDO::FETCH_COLUMN);

echo '        ' . implode(' > ', $got) . "\n";

// 2025-07-27 (skipped), 2024-02-25 (the dead clock's REAL posting),
// 2023-03-03 (skipped), 2021-01-01 (filled), then the two undated in album
// order. One sequence, filled and unfilled interleaved by date.
ox($got === ['skipped-new', 'dead-clock', 'skipped-mid', 'filled-older',
             'undated-b', 'undated-a'],
   'filled and unfilled rows come out in ONE date order', implode(' > ', $got));

ox(array_search('dead-clock', $got, true) === 1,
   '  and the 1980 frame sits on its 2024-02-25 posting, not on the dead clock',
   'position ' . (string)array_search('dead-clock', $got, true));

ox(array_slice($got, -2) === ['undated-b', 'undated-a'],
   '  rows with NEITHER date go last, in reversed album order');

// THE REGRESSION THIS REPLACES. The old clause bucketed by posted_on alone, so
// every unfilled row went behind every filled one regardless of date. Run it
// to show the difference is real and not a matter of taste.
$oldOrder = "CASE WHEN posted_on <> '' THEN 0 ELSE 1 END ASC, posted_on DESC, "
          . "sort_rank DESC, id DESC";
$old = $pdo->query("SELECT title FROM snap_pocket_album_items
                    WHERE source_url = 'https://src' AND album_id = 191
                    ORDER BY {$oldOrder}")->fetchAll(PDO::FETCH_COLUMN);
echo '        old: ' . implode(' > ', $old) . "\n";
ox($old !== $got, '  the previous clause really did order it differently');
ox(array_search('skipped-new', $old, true) > array_search('filled-older', $old, true),
   '  under the old clause the NEWEST photograph sat behind a 2021 one');

// AND THE NO-OP NOBODY WOULD HAVE NOTICED. A bare COALESCE on these columns
// returns '' for every unfilled row, so the album comes out in a different and
// wrong order. Run it, rather than asserting it cannot happen.
$naiveSort = "COALESCE(posted_on, taken_on)";
$naive = $pdo->query("SELECT title FROM snap_pocket_album_items
                      WHERE source_url = 'https://src' AND album_id = 191
                      ORDER BY CASE WHEN {$naiveSort} IS NULL THEN 1 ELSE 0 END ASC,
                               {$naiveSort} DESC, sort_rank DESC, id DESC")
                   ->fetchAll(PDO::FETCH_COLUMN);
echo '        bare COALESCE: ' . implode(' > ', $naive) . "\n";
ox($naive !== $got,
   'a bare COALESCE gives a DIFFERENT order, so it is not an equivalent spelling',
   implode(' > ', $naive));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($fails !== []) {
    echo 'FAIL (' . count($fails) . ' of ' . $checks . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS ({$checks} checks) — a partly-filled cache sorts as one date order,\n";
echo "the dead-clock frame sits on its real posting date, and undated rows keep\n";
echo "the album's own order.\n";
echo "NOT VERIFIED HERE: the rendered page. Nothing changes on Bad Day until a\n";
echo "build carrying this is published AND the backfill has run.\n";
exit(0);

// ===== SNAPSMACK EOF =====
