<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * THE PHOTOGRAPHER COLUMN IS DECLARED, INDEXED, NEVER REQUIRED, AND NOT
 * BACKFILLED. SS-1407 A1.
 *
 * snap_posts has carried user_id since the canonical schema. snap_images never
 * did -- and two places already query it: archive.php's author filter and
 * core/skin-view-model.php's photographer filter. Both are guarded and degrade
 * quietly, so nothing was broken, but the feature was half built: the column
 * existed only where an owner happened to have run the Flickr importer, which
 * adds it ad hoc. This declares it so schema sync gives it to everyone.
 *
 * THREE THINGS MUST STAY TRUE, and each fails in a different direction:
 *
 *  1. DECLARED AND INDEXED. Without the declaration the updater never delivers
 *     it; without the index an author filter table-scans snap_images, which on
 *     this fleet is the query that took 280-363 seconds before the 987D index
 *     work.
 *
 *  2. NEVER HARD-REQUIRED. An install can report a new version while running
 *     old code -- SS-1342 and SS-1335 are exactly that, a protected_paths.json
 *     pinning core/constants.php so the version line is rewritten and the code
 *     is not. On those sites the column may be absent however new the version
 *     looks, so every reader must still degrade.
 *
 *  3. NOTHING BACKFILLS IT. Writing every unattributed photograph to one
 *     account is a false authorship claim baked into a database with no way
 *     afterwards to tell which rows were guessed -- and there is no "primary
 *     admin" in the code to guess with. Attribution is the owner's decision.
 *     This test fails if anyone adds a backfill here.
 *
 * The schema half is EXECUTED through the product's own parser. The call sites
 * are read, and the file says so.
 */

$root = dirname(__DIR__);
$failures = [];

// ── 1. DECLARED, NULLABLE, INDEXED — through the real parser ─────────────
require_once $root . '/core/schema-sync.php';

$meta = snap_canonical_col_meta();
$imageCols = $meta['snap_images'] ?? [];
if (!$imageCols) {
    throw new RuntimeException('the canonical parser found no snap_images '
        . 'columns, so every assertion below would be vacuous');
}
if (!isset($imageCols['user_id'])) {
    $failures[] = 'snap_images.user_id is not in the canonical schema, so the '
        . 'updater will never deliver it and the two call sites that already '
        . 'query it stay permanently degraded';
} else {
    $def = (string)($imageCols['user_id']['def_sql'] ?? '');
    if (!preg_match('/int\s+unsigned/i', $def)) {
        $failures[] = 'snap_images.user_id is not int unsigned, so it cannot '
            . 'match snap_users.id: ' . $def;
    }
    if (preg_match('/NOT\s+NULL/i', $def)) {
        $failures[] = 'snap_images.user_id is NOT NULL. Every existing row has '
            . 'no recorded photographer, so a non-nullable column forces a '
            . 'value to be invented for all of them.';
    }
    if (preg_match('/\bDEFAULT\s+(?!NULL)/i', $def)) {
        $failures[] = 'snap_images.user_id has a non-NULL default, which '
            . 'attributes every future photograph to whoever that is: ' . $def;
    }
}
// TYPE PARITY WITH ITS SIBLING, not with snap_users.id, and the difference is
// worth writing down because the obvious assertion failed first.
//
// snap_users.id is signed `int NOT NULL AUTO_INCREMENT`, while
// snap_posts.user_id -- the column this one is modelled on, in the canonical
// schema since long before SS-1407 -- is `int unsigned`. So the established
// author column ALREADY disagrees with the key it references. MySQL converts
// and the join works for positive ids, which is why nothing has broken, but
// the mismatch is real and fleet-wide.
//
// I matched the SIBLING. Consistency with the column the feature is built on
// matters more than consistency with a mismatch, and changing snap_users.id or
// snap_posts.user_id is a migration on 28 live databases that nobody asked
// for. Flagged in the room rather than fixed here.
$postCols = $meta['snap_posts'] ?? [];
if (isset($postCols['user_id'], $imageCols['user_id'])) {
    $sibling = preg_match('/int\s+unsigned/i', (string)$postCols['user_id']['def_sql']);
    $mine = preg_match('/int\s+unsigned/i', (string)$imageCols['user_id']['def_sql']);
    if ($sibling !== $mine) {
        $failures[] = 'snap_images.user_id and snap_posts.user_id are different '
            . 'types. The two author columns must agree, or a query that joins '
            . 'photographs and posts by author compares badly.';
    }
}
// If anyone ever reconciles snap_users.id, this says so instead of leaving two
// columns quietly out of step with it.
$userCols = $meta['snap_users'] ?? [];
if (isset($userCols['id']) && preg_match('/int\s+unsigned/i', (string)$userCols['id']['def_sql'])) {
    $failures[] = 'snap_users.id is now int unsigned, so the pre-existing '
        . 'mismatch this test documents is gone -- update snap_posts.user_id '
        . 'and snap_images.user_id to match it and delete this check';
}

$tables = snap_parse_canonical_schema();
$imagesDdl = (string)($tables['snap_images'] ?? '');
if ($imagesDdl === '') {
    $failures[] = 'snap_images has no parsed CREATE TABLE, so a fresh install '
        . 'would not get the column at all';
} else {
    if (!preg_match('/KEY\s+`?idx_images_user_id`?\s*\(\s*`?user_id`?\s*\)/i', $imagesDdl)) {
        $failures[] = 'user_id is not indexed. An author or photographer filter '
            . 'would table-scan snap_images, which is the shape of query that '
            . 'measured 280-363 seconds on this fleet before the 987D indexes.';
    }
    if (preg_match('/FOREIGN\s+KEY[^,]*user_id/i', $imagesDdl)) {
        $failures[] = 'user_id has a FOREIGN KEY. Deleting a user must not '
            . 'cascade away their photographs, and an updater cannot force '
            . 'referential integrity onto an install that predates it.';
    }
    // The DDL a fresh install runs must be valid: a column cannot be declared
    // after the PRIMARY KEY line in a way that reads as an index definition.
    $colAt = strpos($imagesDdl, '`user_id`');
    $pkAt = stripos($imagesDdl, 'PRIMARY KEY');
    if ($colAt !== false && $pkAt !== false && $colAt > $pkAt) {
        $failures[] = 'the user_id COLUMN is declared after PRIMARY KEY, mixed '
            . 'in with the index definitions. Keep columns with the columns.';
    }
}

// ── 2. NO BACKFILL, ANYWHERE ─────────────────────────────────────────────
// The one that matters most, and the one someone will add in good faith.
foreach (['core/schema-sync.php', 'core/updater.php', 'database/schema/snapsmack_canonical.sql'] as $file) {
    $body = (string)@file_get_contents($root . '/' . $file);
    if ($body === '') {
        $failures[] = "could not read {$file}";
        continue;
    }
    if (preg_match('/UPDATE\s+`?snap_images`?\s+SET\s+`?user_id`?/i', $body)) {
        $failures[] = "{$file} backfills snap_images.user_id. Writing every "
            . 'unattributed photograph to one account is a false authorship '
            . 'claim with no way afterwards to tell which rows were guessed. '
            . "That is the owner's decision, not an updater's.";
    }
}

// ── 3. THE READERS STILL DEGRADE, AND PROBE PORTABLY ─────────────────────
// SOURCE assertions: these run inside a page with a request and a database.
$archive = (string)php_strip_whitespace($root . '/archive.php');
if (!str_contains($archive, '$has_img_user_id')) {
    $failures[] = 'archive.php no longer guards on the column at all, so an '
        . 'install running old code behind a new version line would 500 its '
        . 'whole archive instead of losing one filter';
}
if (preg_match('/information_schema/i', $archive)) {
    $failures[] = 'archive.php probes for the column through INFORMATION_SCHEMA '
        . 'again. core/public-repository.php abandoned that after its own '
        . 'regression caught it reporting EVERY column absent on another '
        . 'engine -- here that silently drops the author filter on every page '
        . 'rather than failing visibly. Use SELECT <col> FROM <table> WHERE 0.';
}
if (!preg_match('/SELECT\s+`?user_id`?\s+FROM\s+snap_images\s+WHERE\s+0/i', $archive)) {
    $failures[] = 'archive.php does not probe with the house '
        . '"SELECT <col> FROM <table> WHERE 0" pattern';
}
// The guard must actually gate the author dimension, not just exist.
if (!preg_match('/\$has_img_user_id/', $archive)
    || substr_count($archive, '$has_img_user_id') < 2) {
    $failures[] = 'archive.php computes the guard and never consults it, so the '
        . 'author filter runs whether or not the column exists';
}

$viewModel = (string)php_strip_whitespace($root . '/core/skin-view-model.php');
if (str_contains($viewModel, 'user_id') && !preg_match('/try\s*{|catch\s*\(/', $viewModel)) {
    $failures[] = 'core/skin-view-model.php queries user_id with no failure '
        . 'path, so the photographer filter would take the page down on an '
        . 'install without the column';
}

if ($failures) {
    throw new RuntimeException("The photographer column is not safe:\n  - "
        . implode("\n  - ", $failures));
}

echo "snap_images.user_id: declared, nullable, indexed, no foreign key, no "
    . "backfill, and still not required by its readers: passed.\n";
// ===== SNAPSMACK EOF =====
