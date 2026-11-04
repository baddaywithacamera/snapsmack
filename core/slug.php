<?php
declare(strict_types=1);

/**
 * SNAPSMACK - the one place a slug is minted.
 *
 * WHY THIS FILE EXISTS. There were already THREE slug implementations and no
 * canonical one:
 *
 *   smack-cats.php:80                 an INLINE one-liner for cat_slug, with no
 *                                     uniqueness check of any kind
 *   core/flkrfckr-api.php:195,:203    flkrfckr_slugify + flkrfckr_unique_slug,
 *                                     a real pair, named after a TOOL
 *   core/gram-client-authoring.php:11 snapsmack_gram_unique_post_slug
 *
 * WEB asked me to "reuse the same slugify and uniqueness helpers" for
 * album_slug and not write a second one. Measuring found there was no such pair
 * for categories to reuse, and writing a fourth would have been the wrong
 * answer to that. So: one pair, named by FUNCTION rather than by the tool that
 * happened to need it first. The existing three become callers later, through
 * Codex's rename contract -- they are NOT rewritten here.
 *
 * THE TWO EXISTING IMPLEMENTATIONS DISAGREE, and that is worth knowing before
 * anyone assumes this is a no-op refactor. The cat_slug one-liner is
 * ASCII-only: preg_replace('/[^a-z0-9]+/i', '-', $name). An album called "Café"
 * becomes "caf" under it and "café" under the flkrfckr version, which is
 * Unicode-aware. THIS FILE COPIES THE UNICODE-AWARE ONE, because it is the
 * better of the two and because copying working code beats improvising. It is
 * therefore NOT byte-identical to how cat_slug behaves today, and category
 * behaviour is deliberately left alone rather than quietly changed underneath
 * existing rows.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * Text to URL slug. Unicode-aware: letters and numbers in any script survive.
 *
 * Copied from core/flkrfckr-api.php:195 rather than reinvented. The only
 * change is the name.
 */
function snap_slugify(string $text): string {
    $s = mb_strtolower(trim($text), 'UTF-8');
    // UNDERSCORE IS PRESERVED HERE so the next line can turn it into a hyphen.
    // The implementation this was copied from omits it from this character
    // class, which DELETES underscores before the second pass can convert
    // them: "spaced__out" came out as "spacedout" rather than "spaced-out",
    // even though that second pattern plainly intends to handle "_". A real
    // defect in the original, found by running it rather than reading it.
    //
    // It is still live in core/flkrfckr-api.php:195, where it affects image
    // slugs minted from titles containing underscores. NOT fixed there by me --
    // that file is not mine to change today and the fix belongs with whoever
    // makes it a caller of this helper. Reported, not silently diverged from.
    $s = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $s) ?? '';
    $s = preg_replace('/[\s_-]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/**
 * A slug that is not already taken in $table.$column.
 *
 * $keepId: the id of the row being UPDATED, if any. Without it an edit
 * collides with its own existing slug and walks to -2 on every save, so a
 * category edited three times ends up at name-4 having never had a conflict
 * with anything but itself.
 *
 * $fallback is used when the text slugifies to nothing at all -- "???" or an
 * emoji-only name. An empty slug in a URL path is worse than an ugly one.
 *
 * TABLE AND COLUMN ARE ALLOWLISTED, not interpolated on trust. They come from
 * calling code today, but a helper that takes an identifier and splices it into
 * SQL is one careless caller away from being an injection point, and the cost
 * of refusing an unknown name is nothing.
 */
function snap_unique_slug(
    PDO $pdo,
    string $table,
    string $column,
    string $text,
    ?int $keepId = null,
    string $fallback = 'item'
): string {
    $allowed = [
        'snap_albums'     => ['album_slug'],
        'snap_categories' => ['cat_slug'],
        'snap_posts'      => ['slug'],
        'snap_images'     => ['img_slug'],
    ];
    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        throw new InvalidArgumentException(
            "snap_unique_slug refuses an unlisted target: {$table}.{$column}"
        );
    }

    $base = snap_slugify($text);
    if ($base === '') $base = $fallback;

    $sql = "SELECT id FROM `{$table}` WHERE `{$column}` = ?"
         . ($keepId === null ? '' : ' AND id <> ?')
         . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);

    $slug = $base;
    $n    = 2;                       // first duplicate is -2, as WEB specified
    while (true) {
        $params = $keepId === null ? [$slug] : [$slug, $keepId];
        $stmt->execute($params);
        if ($stmt->fetch() === false) return $slug;
        $slug = $base . '-' . $n++;
        if ($n > 1000) {             // a loop that cannot run away
            return $base . '-' . bin2hex(random_bytes(4));
        }
    }
}

/**
 * Give every album without a slug one, derived from its name.
 *
 * THIS LIVES HERE RATHER THAN INLINE IN core/schema-sync.php BECAUSE OF A BLIND
 * SPOT IN MY OWN TEST. The logic was first written inline in the sync pass, and
 * the test exercised a COPY of it while asserting on the sync file's source
 * text. Mutation proof found three mutants it could not see: overwriting an
 * owner-set slug, adding the UNIQUE key unconditionally, and dropping the skip
 * report. All three changed the code AROUND a string my assertions looked for,
 * leaving the string in place -- the same "a selector is present in the file"
 * fault this repository keeps paying for, and the same fix as rel_is_dev_path()
 * in tools/_build/build-release.php: extract the decision so the test CALLS it
 * instead of reading about it.
 *
 * Returns the rows it changed plus the pre-flight verdict, so the caller can
 * decide about the UNIQUE key without re-querying.
 */
function snap_backfill_album_slugs(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT id, album_name FROM snap_albums WHERE album_slug = '' ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // The WHERE here is what makes this safe on a live site: it can only ever
    // touch a row with no slug, so a slug the owner typed is never overwritten
    // and a second pass is a no-op.
    //
    // THE "AND album_slug = ''" IS DELIBERATE REDUNDANCY AND NO TEST CAN REACH
    // IT. The SELECT above already returned only unset rows, so single-threaded
    // the guard can never be the thing that stops a write -- mutation proof
    // confirmed that removing it changes no observable behaviour. It is here
    // for the case the test cannot create: the owner saving a slug in the admin
    // BETWEEN this pass's SELECT and this row's UPDATE. Then the guard is the
    // only thing standing between a backfill and overwriting what he just
    // typed. Marked unprovable rather than deleted for being unprovable, and
    // reported as such rather than counted as covered.
    $set = $pdo->prepare(
        "UPDATE snap_albums SET album_slug = ? WHERE id = ? AND album_slug = ''"
    );

    $assigned = [];
    foreach ($rows as $row) {
        // snap_unique_slug reads the table as it goes, so each album sees the
        // slugs already given out in this same pass and the -2/-3 suffixes come
        // out deterministically in id order.
        $slug = snap_unique_slug(
            $pdo, 'snap_albums', 'album_slug',
            (string)$row['album_name'], (int)$row['id'], 'album'
        );
        $set->execute([$slug, (int)$row['id']]);
        $assigned[(int)$row['id']] = $slug;
    }

    $dupes = $pdo->query(
        "SELECT album_slug, COUNT(*) AS n FROM snap_albums GROUP BY album_slug HAVING n > 1"
    )->fetchAll(PDO::FETCH_ASSOC);
    $empties = (int)$pdo->query(
        "SELECT COUNT(*) FROM snap_albums WHERE album_slug = ''"
    )->fetchColumn();

    return [
        'assigned'   => $assigned,
        'duplicates' => $dupes,
        'unset'      => $empties,
        // The UNIQUE key may be added ONLY when this is true. A failing ALTER
        // on a live site is a worse outcome than a missing index.
        //
        // THE "$empties === 0" HALF IS ALSO UNREACHABLE BY TEST, for the same
        // reason as the UPDATE guard above: this function fills every unset row
        // before counting, so $empties is zero by construction unless an UPDATE
        // did not apply. Mutation proof confirmed dropping it changes nothing
        // observable. It is here because "every UPDATE applied" is an
        // assumption, and the consequence of it being wrong is an ALTER that
        // fails on a live site -- the exact outcome this pre-flight exists to
        // prevent. Kept, documented, and NOT claimed as proved.
        'unique_ok'  => $dupes === [] && $empties === 0,
    ];
}

// ===== SNAPSMACK EOF =====
