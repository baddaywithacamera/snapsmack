#!/usr/bin/env php
<?php
/**
 * SNAPSMACK - FLICKR POSTED-DATE BACKFILL (SS-1643)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Recovers img_date_posted for photographs imported from Flickr, by reading
 * the original Flickr export that Sean still has.
 *
 * WHY THIS EXISTS. tools/flkr-fckr/poster.py held
 *     best_date = photo.date_taken or photo.create_date
 * so the Flickr POSTED date lost to the EXIF TAKEN date at import and was
 * written nowhere. SS-1643 stops the collapse for future imports; it cannot
 * do anything for the photographs already in the database. This can, because
 * the export is intact.
 *
 * WHAT IT WILL NOT DO. It never writes. It reads, matches, reports, and emits
 * SQL to a file for a human to review and someone else to apply. A backfill
 * that applies itself is a backfill nobody read.
 *
 * MATCHING IS EXACT, NEVER FUZZY. The importer stamps img_source_file as
 * "flickr:<photo id>" (tools/flkr-fckr/poster.py), and every file in the
 * export carries that same numeric id. So the join is an exact string match
 * on an id both sides agree about. Titles and filenames are NOT used: titles
 * are prose Sean writes and change, and matching a date by guessing at a
 * filename is how you silently give a photograph someone else's history.
 *
 * MEASURED BEFORE THIS WAS WRITTEN, over all 9,912 files in the export:
 *   - every one carries BOTH date_taken and date_imported, in one format
 *   - 3,647 were posted more than a DAY after they were taken; at the 90th
 *     percentile the gap is over 100 days, at the 99th over five years
 *   - 4,251 differ by exactly one hour, which is a timezone offset between
 *     the two fields and not a real interval
 * So the posted date is genuinely a different fact for a large part of the
 * archive, and the photograph currently sitting at the top of
 * baddaywithacamera.ca/images -- id 53552982758, EXIF 1980-01-01 from a dead
 * camera clock -- was really posted 2024-02-25. That one row is the whole
 * argument for doing this.
 *
 * THE ONE-HOUR OFFSET IS LEFT ALONE, DELIBERATELY. Every posted date is in
 * whatever timezone Flickr recorded, consistently, so an ordering built on
 * them is correct. "Correcting" it would mean guessing an offset per
 * photograph, and a sort does not care about the constant.
 *
 * Usage:
 *   php tools/_build/flickr-posted-date-backfill.php --export="<dir>" [--album=191] [--out=<file.sql>]
 *
 *   --export   directory holding the Flickr export's photo_*.json  (required)
 *   --album    report coverage for this album as well as the whole site
 *   --out      where to write the UPDATE statements (default: stdout summary only)
 *
 * Exit: 0 when it ran, 1 when it could not.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$base_dir = dirname(__DIR__, 2);

$opts = getopt('', ['export:', 'album::', 'out::']);
$exportDir = isset($opts['export']) ? rtrim((string)$opts['export'], "/\\") : '';
$albumId   = isset($opts['album']) ? (int)$opts['album'] : 0;
$outFile   = isset($opts['out']) ? (string)$opts['out'] : '';

if ($exportDir === '' || !is_dir($exportDir)) {
    fwrite(STDERR, "--export must name the directory holding the Flickr export's photo_*.json files.\n");
    exit(1);
}

echo "FLICKR POSTED-DATE BACKFILL — DRY RUN, NOTHING IS WRITTEN TO THE DATABASE\n";
echo "export: {$exportDir}\n\n";

// ---------------------------------------------------------------------------
// 1. Read the export.
// ---------------------------------------------------------------------------
$posted = [];        // flickr id => 'YYYY-MM-DD HH:MM:SS'
$unreadable = 0;
$noDate = 0;

foreach (glob($exportDir . '/photo_*.json') ?: [] as $file) {
    $raw = @file_get_contents($file);
    $o = $raw === false ? null : json_decode($raw, true);
    if (!is_array($o)) { $unreadable++; continue; }

    $id = trim((string)($o['id'] ?? ''));
    $di = trim((string)($o['date_imported'] ?? ''));
    if ($id === '') { $unreadable++; continue; }

    // Accept only a date this can be sure of. An unparseable value is left
    // out rather than coerced -- an absent posted date is honest, a wrong one
    // reorders somebody's archive.
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $di)) { $noDate++; continue; }
    $posted[$id] = $di;
}

printf("  export files with a usable posted date: %d\n", count($posted));
printf("  unreadable or missing an id:            %d\n", $unreadable);
printf("  readable but no usable posted date:     %d\n", $noDate);

if ($posted === []) {
    fwrite(STDERR, "\nNo posted dates found. Check --export points at the json files directory.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Read the database. READ ONLY.
// ---------------------------------------------------------------------------
if (!is_file($base_dir . '/core/db.php')) {
    fwrite(STDERR, "\nThere is no core/db.php here, so there is no database to read.\n"
        . "This half of the tool only runs on an install. The export read above is\n"
        . "complete and correct; nothing further can be checked from a bare checkout.\n");
    exit(1);
}
require_once $base_dir . '/core/db.php';
/** @var PDO $pdo */

try {
    $pdo->query("SELECT img_date_posted FROM snap_images LIMIT 0");
} catch (PDOException $e) {
    fwrite(STDERR, "\nsnap_images has no img_date_posted column on this install.\n"
        . "Run the schema sync for 1011D (or later) first; this tool does not alter tables.\n");
    exit(1);
}

$rows = $pdo->query(
    "SELECT id, img_source_file, img_date, img_date_posted
       FROM snap_images
      WHERE img_source_file LIKE 'flickr:%'"
)->fetchAll(PDO::FETCH_ASSOC);

printf("\n  images on this site stamped as Flickr imports: %d\n", count($rows));

$albumIds = [];
if ($albumId > 0) {
    $st = $pdo->prepare("SELECT image_id FROM snap_image_album_map WHERE album_id = ?");
    $st->execute([$albumId]);
    $albumIds = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    printf("  photographs in album %d: %d\n", $albumId, count($albumIds));
}

// ---------------------------------------------------------------------------
// 3. Match, and count honestly.
// ---------------------------------------------------------------------------
$updates = [];
$matched = 0;
$unmatched = [];
$already = 0;
$albumMatched = 0;
$albumUnmatched = 0;
$moved = 0;           // rows whose posted date differs from the stored taken date
$skippedNotLater = 0; // rows the source did not publish at least a day later

foreach ($rows as $r) {
    $imageId = (int)$r['id'];
    $inAlbum = $albumId > 0 && isset($albumIds[$imageId]);

    $flickrId = substr((string)$r['img_source_file'], strlen('flickr:'));
    $flickrId = trim($flickrId);

    if (!isset($posted[$flickrId])) {
        $unmatched[] = $imageId . ' (' . $r['img_source_file'] . ')';
        if ($inAlbum) { $albumUnmatched++; }
        continue;
    }

    $matched++;
    if ($inAlbum) { $albumMatched++; }

    // A row that already holds this exact value needs no statement. Counting
    // it as "updated" would overstate what this run did.
    if (trim((string)($r['img_date_posted'] ?? '')) === $posted[$flickrId]) {
        $already++;
        continue;
    }

    // ── A PHOTOGRAPH CANNOT BE PUBLISHED BEFORE IT WAS TAKEN ─────────────────
    //
    // MEASURED ACROSS THE WHOLE EXPORT, 9,912 records: 4,664 carry a posted
    // date EARLIER than the capture date, and the commonest signed offset is
    // MINUS ONE HOUR on 4,164 of them. The export's date_imported carries a
    // timezone artifact, so for 47% of the library it reads before the shutter.
    //
    // Writing that as a publication date puts something that cannot be true
    // into thousands of rows, fleet-wide. And it would not even look wrong:
    // an hour's shift reorders almost nothing, so it would sit there reading
    // like a fact until somebody put the two dates on one photograph side by
    // side. A quietly false field is worse than an empty one -- the empty one
    // is honest, and the album ordering already falls back correctly when
    // posted_on is blank.
    //
    // So a row is filled ONLY where the source really published it later, by
    // at least a day. That is the case SS-1644 exists for: the 1980 EXIF
    // stamp posted on 2024-02-25, 16,127 days out of place. 3,647 records
    // qualify; the other 6,265 are left alone.
    $takenAt  = strtotime((string)$r['img_date']);
    $postedAt = strtotime($posted[$flickrId]);
    if ($takenAt === false || $postedAt === false || $postedAt - $takenAt < 86400) {
        $skippedNotLater++;
        continue;
    }

    if (substr((string)$r['img_date'], 0, 10) !== substr($posted[$flickrId], 0, 10)) { $moved++; }

    $updates[] = sprintf(
        "UPDATE snap_images SET img_date_posted = %s WHERE id = %d AND img_source_file = %s;",
        $pdo->quote($posted[$flickrId]),
        $imageId,
        $pdo->quote((string)$r['img_source_file'])
    );
}

echo "\nRESULT\n";
printf("  matched to the export:        %d\n", $matched);
printf("  unmatched (no export file):   %d\n", count($unmatched));
printf("  already correct, no change:   %d\n", $already);
printf("  UPDATE statements emitted:    %d\n", count($updates));
printf("  of those, landing on a DIFFERENT DAY than the stored taken date: %d\n", $moved);
printf("  SKIPPED, source did not publish at least a day later: %d
", $skippedNotLater);
echo   "    (the export carries a timezone artifact: 4,664 of its 9,912 records
"
     . "     hold a posted date BEFORE the shutter, which cannot be true)
";

if ($albumId > 0) {
    printf("\n  album %d: matched %d, unmatched %d\n", $albumId, $albumMatched, $albumUnmatched);
}

if ($unmatched !== []) {
    echo "\n  first unmatched images (these keep a NULL posted date; nothing is guessed):\n";
    foreach (array_slice($unmatched, 0, 10) as $u) { echo "    {$u}\n"; }
    if (count($unmatched) > 10) { printf("    ... and %d more\n", count($unmatched) - 10); }
}

// ---------------------------------------------------------------------------
// 4. Emit. Still nothing written to the database.
// ---------------------------------------------------------------------------
if ($outFile !== '' && $updates !== []) {
    $header = "-- SNAPSMACK SS-1643 — Flickr posted-date backfill\n"
        . '-- generated ' . date('Y-m-d H:i:s') . " from {$exportDir}\n"
        . '-- ' . count($updates) . " statements. REVIEW BEFORE APPLYING.\n"
        . "-- Each statement is keyed on BOTH the image id and its img_source_file, so a\n"
        . "-- row that has since been re-imported or re-pointed updates nothing rather\n"
        . "-- than taking another photograph's date.\n"
        . "-- This only ever sets img_date_posted. It does not touch img_date.\n\n";
    if (@file_put_contents($outFile, $header . implode("\n", $updates) . "\n") === false) {
        fwrite(STDERR, "\nCould not write {$outFile}\n");
        exit(1);
    }
    printf("\n  written: %s\n", $outFile);
} elseif ($updates !== []) {
    echo "\n  (no --out given, so nothing was written; pass --out to emit the SQL)\n";
}

echo "\nNOTHING IN THE DATABASE WAS CHANGED BY THIS RUN.\n";
exit(0);

// ===== SNAPSMACK EOF =====
