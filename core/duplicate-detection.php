<?php
declare(strict_types=1);

/**
 * SNAPSMACK - the one place two photos are called duplicates.
 *
 * WHY THIS FILE EXISTS. The rule lived inside smack-manage.php twice: once as a
 * CASE expression in snap_manage_duplicate_groups(), and once copied into the
 * banner count further down the same page, under a comment promising the copy
 * "mirrors snap_manage_duplicate_groups() exactly". Two copies of one decision
 * is the drift that keeps costing weeks here, and a page cannot be required by
 * a test, so neither copy was ever executed by one.
 *
 * WHAT WAS WRONG WITH THE RULE. The badday archive reported 633 duplicates.
 * The grouping keys on the strongest identity available, and for most imported
 * rows that is img_source_file -- the original filename -- because five import
 * paths never wrote img_checksum at all. Camera filenames repeat: DSC_0042.JPG
 * comes round again every ten thousand frames, and IMG_1234.JPG is on every
 * phone ever sold. So two unrelated photographs taken years apart were offered
 * to the owner as the same picture, with one of them ticked for deletion.
 *
 * Flagging two posts as duplicates on filename alone is a detection defect, not
 * a product choice (PM, 2026-11-26). The filename arm now requires
 * CORROBORATION: the same filename AND either the same capture date or -- when
 * one side has no date at all -- the same pixel dimensions. A row whose
 * filename matches but which corroborates with nobody is not a duplicate of
 * anything. See snap_dupe_corroborates() for why two known and different dates
 * end it whatever the dimensions say.
 *
 * WHICH ARMS CORROBORATE:
 *   c:  img_checksum         byte-identical. Certain. No corroboration needed.
 *   s:  img_source_file      filename. NEEDS corroboration (this file's point).
 *   t:  img_title|img_date   already carries the date as its own corroboration.
 *
 * THE DIRECTION OF THE ERROR MATTERS. This rule can now miss a real duplicate
 * -- the same photo re-imported with a different recorded date AND different
 * dimensions after a resize. That is the safe direction: a missed duplicate
 * leaves two copies on the site, and a false one offers to delete a photograph
 * the owner has only once.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/** Prefix of the identity key that needs corroborating. */
const SNAP_DUPE_FILENAME_ARM = 's:';

/**
 * The identity key, as SQL. ONE copy, used by every caller.
 *
 * @param string $prefix Column prefix including the dot, e.g. "i." or "".
 */
function snap_dupe_identity_sql(string $prefix = ''): string {
    $p   = $prefix;
    $arm = SNAP_DUPE_FILENAME_ARM;
    return "CASE
                  WHEN {$p}img_checksum    IS NOT NULL AND {$p}img_checksum    <> '' THEN CONCAT('c:', {$p}img_checksum)
                  WHEN {$p}img_source_file IS NOT NULL AND {$p}img_source_file <> '' THEN CONCAT('{$arm}', {$p}img_source_file)
                  WHEN {$p}img_title <> '' THEN CONCAT('t:', {$p}img_title, '|', {$p}img_date)
                  ELSE CONCAT('id:', {$p}id)
                END";
}

/** A usable capture date, or '' when the row has none worth trusting. */
function snap_dupe_date_of(array $row): string {
    $d = trim((string)($row['img_date'] ?? ''));
    if ($d === '' || strncmp($d, '0000-00-00', 10) === 0) { return ''; }
    return $d;
}

/** Pixel dimensions as a comparable string, or '' when either is unknown. */
function snap_dupe_dimensions_of(array $row): string {
    $w = (int)($row['img_width']  ?? 0);
    $h = (int)($row['img_height'] ?? 0);
    if ($w <= 0 || $h <= 0) { return ''; }
    return $w . 'x' . $h;
}

/**
 * Do these two same-filename rows corroborate each other?
 *
 * Same capture date, or -- when at least one side has no usable date -- the same
 * pixel dimensions. A blank on either side never corroborates: two rows that
 * both know nothing are not evidence.
 *
 * TWO KNOWN AND DIFFERENT DATES END IT. The PM order was "the same filename
 * plus the same capture date OR dimensions", and measuring the dimensions arm
 * on its own showed why it needs this guard: a camera held the same way
 * produces 4000x3000 for every frame it ever takes, so "same filename, same
 * dimensions" reduces to "same camera, and the file counter came round again"
 * -- which is the badday false positive itself, not a corroboration of it.
 * Dated rows that disagree are therefore different photographs whatever their
 * size, and the dimensions arm is left to do the job it is good at: matching a
 * re-import that lost its date against the copy that kept one.
 */
function snap_dupe_corroborates(array $a, array $b): bool {
    $da = snap_dupe_date_of($a);
    $db = snap_dupe_date_of($b);
    if ($da !== '' && $db !== '') { return $da === $db; }
    $sa = snap_dupe_dimensions_of($a);
    $sb = snap_dupe_dimensions_of($b);
    return $sa !== '' && $sa === $sb;
}

/**
 * Does this candidate set hold two different capture dates?
 *
 * One set is one photograph, so it has at most one capture date. Rows with no
 * date do not contradict anything -- they are what the dimensions arm is for.
 *
 * @param array<int,array<string,mixed>> $members
 */
function snap_dupe_set_has_contradiction(array $members): bool {
    $dates = [];
    foreach ($members as $row) {
        $date = snap_dupe_date_of($row);
        if ($date !== '') { $dates[$date] = true; }
    }
    return count($dates) > 1;
}

/**
 * Partition fetched rows into duplicate sets. THE RULE LIVES HERE.
 *
 * Rows need at least: id, dup_key, img_date, img_width, img_height.
 * Returns a list of sets, each a list of 2 or more rows, in input order.
 *
 * @param  array<int,array<string,mixed>> $rows
 * @return array<int,array<int,array<string,mixed>>>
 */
function snap_dupe_partition(array $rows): array {
    $buckets = [];
    foreach ($rows as $r) {
        $key = (string)($r['dup_key'] ?? '');
        if ($key === '' || strncmp($key, 'id:', 3) === 0) { continue; }  // ungroupable
        $buckets[$key][] = $r;
    }

    $sets = [];
    foreach ($buckets as $key => $items) {
        if (count($items) < 2) { continue; }

        if (strncmp($key, SNAP_DUPE_FILENAME_ARM, strlen(SNAP_DUPE_FILENAME_ARM)) !== 0) {
            $sets[] = $items;   // checksum or title+date: the key is enough
            continue;
        }

        // The filename arm. Rows join a set only through corroboration, so a
        // bucket of unrelated photographs that merely share a camera filename
        // breaks apart into nothing. Corroboration is followed transitively:
        // A and B agree on the date, B and C on the dimensions, so all three
        // are the same picture.
        $n = count($items);

        // AMBIGUOUS DATELESS ROWS JOIN NOTHING. (Muse, on bc76944f.)
        //
        // Corroboration being pairwise and then followed transitively left a
        // bridge wide open: same filename, all 4000x3000, one dated 2014, one
        // with no date, one dated 2019. The dateless row corroborates with
        // BOTH on dimensions, so the union put all three in one set and the
        // contradicted-dates guard never saw the pair it was written for --
        // 2014 and 2019 were never compared to each other. Measured on the
        // real function: one set of three, two photographs five years apart.
        //
        // A dateless row that matches two DIFFERENT capture dates on
        // dimensions alone cannot be the same photograph as both, and nothing
        // here can say which. So it is the same photograph as neither, and it
        // joins nothing at all rather than being assigned to whichever row the
        // loop reached first. That also makes the result independent of row
        // order, which a refuse-on-merge rule would not have been.
        $ambiguous = [];
        for ($i = 0; $i < $n; $i++) {
            if (snap_dupe_date_of($items[$i]) !== '') { continue; }
            $seen = [];
            for ($j = 0; $j < $n; $j++) {
                if ($j === $i) { continue; }
                $other = snap_dupe_date_of($items[$j]);
                if ($other === '') { continue; }
                if (snap_dupe_corroborates($items[$i], $items[$j])) { $seen[$other] = true; }
            }
            if (count($seen) > 1) { $ambiguous[$i] = true; }
        }

        $parent = range(0, $n - 1);
        $find   = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) { $i = $parent[$i] = $parent[$parent[$i]]; }
            return $i;
        };
        for ($i = 0; $i < $n; $i++) {
            if (isset($ambiguous[$i])) { continue; }
            for ($j = $i + 1; $j < $n; $j++) {
                if (isset($ambiguous[$j])) { continue; }
                if (!snap_dupe_corroborates($items[$i], $items[$j])) { continue; }
                $ri = $find($i);
                $rj = $find($j);
                if ($ri !== $rj) { $parent[$ri] = $rj; }
            }
        }
        // An ambiguous row is never unioned, so it is always its own component
        // of one and the count test below drops it. It needs no skip of its
        // own here; the first version of this had one and mutation proof showed
        // it could not affect any outcome, which is the definition of dead
        // code.
        $components = [];
        for ($i = 0; $i < $n; $i++) { $components[$find($i)][] = $items[$i]; }

        foreach ($components as $members) {
            if (count($members) < 2) { continue; }
            // BELT AND BRACES, AND DELIBERATELY UNREACHABLE: no set may hold
            // two different capture dates. The pre-pass above makes this
            // impossible, so no input can reach it and no mutation of it can
            // change an answer -- it is checked directly as a unit instead, in
            // the regression. It is here so that a later change which does
            // make a contradicted set possible drops that set rather than
            // offering it to the owner with a delete box beside it.
            if (snap_dupe_set_has_contradiction($members)) { continue; }
            $sets[] = $members;
        }
    }
    return $sets;
}

/**
 * Fetch the solo-image rows the rule runs on.
 *
 * @param bool $with_scores Include the per-row category/album counts the keeper
 *                          choice needs. The banner count does not, and those
 *                          are two correlated subqueries PER ROW.
 */
function snap_dupe_fetch_rows(PDO $pdo, bool $with_scores): array {
    $identity = snap_dupe_identity_sql('i.');
    $scores   = $with_scores
        ? ",
                (SELECT COUNT(*) FROM snap_image_cat_map   m WHERE m.image_id = i.id) AS cat_n,
                (SELECT COUNT(*) FROM snap_image_album_map a WHERE a.image_id = i.id) AS alb_n"
        : '';
    $columns  = $with_scores
        ? "i.id, i.img_title, i.img_slug, i.img_date, i.img_file,
                i.img_width, i.img_height, i.img_description"
        : "i.id, i.img_date, i.img_width, i.img_height";

    return $pdo->query(
        "SELECT {$columns},
                {$identity} AS dup_key{$scores}
         FROM snap_images i
         WHERE i.post_id IS NULL
         ORDER BY dup_key, i.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Find sets of DUPLICATE solo images (photoblog units -- post_id IS NULL).
 * Each set carries a suggested "keeper": the most complete copy (most
 * categories/albums, then a caption, then the oldest), so nothing carrying
 * metadata is lost by default.
 *
 * @return array<int,array{key:string,keeper_id:int,items:array}>
 */
function snap_manage_duplicate_groups(PDO $pdo): array {
    $groups = [];
    foreach (snap_dupe_partition(snap_dupe_fetch_rows($pdo, true)) as $items) {
        $keeper = $items[0];
        $best   = -1;
        foreach ($items as $it) {
            $score = ((int)$it['cat_n'] + (int)$it['alb_n']) * 2
                   + (trim((string)$it['img_description']) !== '' ? 1 : 0);
            if ($score > $best) { $best = $score; $keeper = $it; }
        }
        $groups[] = [
            'key'       => (string)($items[0]['dup_key'] ?? ''),
            'keeper_id' => (int)$keeper['id'],
            'items'     => $items,
        ];
    }
    return $groups;
}

/**
 * How many copies could be removed -- the number the banner shows. Same rule,
 * same function, so the banner and the review can never disagree again.
 */
function snap_manage_duplicate_extra_count(PDO $pdo): int {
    $extra = 0;
    foreach (snap_dupe_partition(snap_dupe_fetch_rows($pdo, false)) as $items) {
        $extra += count($items) - 1;
    }
    return $extra;
}

// ===== SNAPSMACK EOF =====
