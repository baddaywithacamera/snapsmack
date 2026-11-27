<?php
declare(strict_types=1);

/**
 * SNAPSMACK - where a photograph can be referenced from. The whole list.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1633. The ticket says the dangerous half of an unused-media finder is the
 * DEFINITION, not the deleting, and that is right: a finder that misses one
 * place an image can be referenced will offer to delete a photograph that is in
 * use, and the owner will believe it. So this file is the definition, on its
 * own, executable, with a regression that re-derives it from the canonical
 * schema rather than from this list. Nothing here deletes anything.
 *
 * MY OWN TICKET'S ENUMERATION WAS WRONG AND THIS IS THE CORRECTION. SS-1633 as
 * filed says twenty-two columns. It is twenty-six reference sites, and the four
 * I missed are the four that a column scan cannot see:
 *
 *   snap_comments.img_id            A comment attaches to a PHOTOGRAPH. This is
 *                                   not an edge case: the engagement remap work
 *                                   records 1,904 comments still image-keyed,
 *                                   and they are Ray's. A finder built to the
 *                                   ticket as filed would have offered to
 *                                   delete photographs that people had
 *                                   commented on.
 *   snap_ap_likes                   target_type enum('image','post') with
 *                                   target_id. The column name says nothing.
 *   snap_collection_items           item_type ENUM(...,'image') with item_id,
 *                                   which is a SECOND way into the same table.
 *                                   I had counted its image_id column only.
 *   snap_import_map                 destination_type with destination_id, and
 *                                   the type word is 'media', NOT 'image'. The
 *                                   API route proves it: destination_type
 *                                   'media' queries snap_images. So even
 *                                   someone who knew to look for polymorphic
 *                                   tables and searched them for 'image' would
 *                                   still miss this one.
 *
 * THE GENERAL SHAPE, which the ticket names twice and should name three times:
 * a reference with no column to find it by. The ticket lists the key/value
 * settings table and the body text of posts and pages. The third is a
 * POLYMORPHIC PAIR -- a type column plus an id column -- where the id column's
 * name is deliberately generic and only the type column says what it points at.
 * Three of my four misses were that shape.
 *
 * FAIL CLOSED, AND THIS IS THE PROPERTY THAT MATTERS MOST HERE. A table or
 * column this install does not have is reported NOT CHECKED. It is never
 * reported as "no reference found". Those are opposite answers and only one of
 * them is safe to act on: an un-migrated site missing snap_ap_likes must not
 * be told its photographs are unreferenced because the table that holds the
 * references is absent. This is the same fault class as the recovery engine's
 * containment check, which skipped itself when realpath() could not resolve a
 * path and reported success -- a guard that answers "fine" when it means
 * "I could not look".
 *
 * A REFERENCE IS NOT ALWAYS A USE, and which is which is SEAN'S to decide, not
 * mine. Every site below carries a 'kind' of 'use' or 'record' and a plain
 * sentence saying what it is, so the owner can disagree with the reasoning
 * rather than trust a count. My proposed classification is in the registry and
 * the three genuinely open questions are listed at the bottom of this comment.
 * An agent must not settle them.
 *
 * OWNER DECISIONS, carried forward from the ticket and added to:
 *   - do the statistics tables and the delete audit count as uses?
 *   - does snap_import_map count as a use? It is a record of where an import
 *     landed, so by the audit argument it is not; but deleting the image
 *     orphans the map and an owner re-running an import would not be told.
 *   - do a comment and a federated like count as uses? I have them as uses,
 *     because deleting the photograph destroys somebody else's writing, and
 *     because the ones on this fleet are Ray's. Sean may rule otherwise.
 *   - is there a recoverable window, and how long?
 */

require_once __DIR__ . '/signature-contract.php';

/**
 * Every place a snap_images id can be referenced.
 *
 * ORDER IS ALPHABETICAL BY TABLE so that a reader can check it against a schema
 * dump without losing their place, and so a new entry has an obvious home. The
 * regression derives the expected set FROM THE SCHEMA and fails if this list
 * and the schema disagree in either direction, so an added column cannot sit
 * here unnoticed and a dropped one cannot linger.
 *
 * kind:  'use'    deleting the image takes something the owner or a reader has
 *        'record' a row that is ABOUT the image and is expected to outlive it
 * what:  one plain sentence, shown to the owner per row
 */
const SNAP_MEDIA_REFERENCE_SITES = [
    ['table' => 'pc_prompts',            'column' => 'image_id',   'kind' => 'use',
     'what'  => 'the photograph answering a prompt'],
    ['table' => 'snap_albums',           'column' => 'cover_image_id', 'kind' => 'use',
     'what'  => 'the cover of an album'],
    ['table' => 'snap_ap_likes',         'column' => 'target_id',  'kind' => 'use',
     'type_column' => 'target_type',     'type_value' => 'image',
     'what'  => 'a like on this photograph from the fediverse'],
    ['table' => 'snap_bucket_items',     'column' => 'image_id',   'kind' => 'use',
     'what'  => 'a photograph in a post bucket'],
    ['table' => 'snap_categories',       'column' => 'cover_image_id', 'kind' => 'use',
     'what'  => 'the cover of a category'],
    ['table' => 'snap_collection_items', 'column' => 'image_id',   'kind' => 'use',
     'what'  => 'an image-folio member of a collection'],
    ['table' => 'snap_collection_items', 'column' => 'item_id',    'kind' => 'use',
     'type_column' => 'item_type',       'type_value' => 'image',
     'what'  => 'a collection member added as a typed item'],
    ['table' => 'snap_collections',      'column' => 'cover_image_id', 'kind' => 'use',
     'what'  => 'the cover of a collection'],
    ['table' => 'snap_comments',         'column' => 'img_id',     'kind' => 'use',
     'what'  => 'a comment somebody wrote on this photograph'],
    ['table' => 'snap_group_pool',       'column' => 'image_id',   'kind' => 'use',
     'what'  => 'a photograph in a group pool'],
    ['table' => 'snap_groups',           'column' => 'cover_image_id', 'kind' => 'use',
     'what'  => 'the cover of a group'],
    ['table' => 'snap_gyss_delete_audit','column' => 'image_id',   'kind' => 'record',
     'what'  => 'an audit row recording an earlier deletion'],
    ['table' => 'snap_image_album_map',  'column' => 'image_id',   'kind' => 'use',
     'what'  => 'the photograph is in an album'],
    ['table' => 'snap_image_cat_map',    'column' => 'image_id',   'kind' => 'use',
     'what'  => 'the photograph is in a category'],
    ['table' => 'snap_image_tags',       'column' => 'image_id',   'kind' => 'use',
     'what'  => 'a tag on the photograph'],
    ['table' => 'snap_import_map',       'column' => 'destination_id', 'kind' => 'record',
     'type_column' => 'destination_type','type_value' => 'media',
     'what'  => 'a record of the import this photograph arrived in'],
    ['table' => 'snap_oauth_media',      'column' => 'image_id',   'kind' => 'use',
     'what'  => 'media attached to an OAuth client upload'],
    ['table' => 'snap_post_images',      'column' => 'image_id',   'kind' => 'use',
     'what'  => 'a photograph in a post gallery'],
    ['table' => 'snap_posts',            'column' => 'featured_asset_id', 'kind' => 'use',
     'what'  => "a post's featured asset"],
    ['table' => 'snap_posts',            'column' => 'featured_image_id', 'kind' => 'use',
     'what'  => "a post's featured image"],
    ['table' => 'snap_posts',            'column' => 'signature_dark_image_id', 'kind' => 'use',
     'what'  => "a post's signature on a dark surface"],
    ['table' => 'snap_posts',            'column' => 'signature_image_id', 'kind' => 'use',
     'what'  => "a post's signature"],
    ['table' => 'snap_posts',            'column' => 'signature_light_image_id', 'kind' => 'use',
     'what'  => "a post's signature on a light surface"],
    ['table' => 'snap_stats',            'column' => 'image_id',   'kind' => 'record',
     'what'  => 'view and like statistics, which are history'],
    ['table' => 'snap_stats_daily',      'column' => 'top_image_id', 'kind' => 'record',
     'what'  => 'the top photograph on one day, which is history'],
];

/**
 * The mosaic member list, which is JSON and not a column comparison.
 *
 * WHY asset_ids ALONE IS THE WHOLE MOSAIC. mosaic_weights is keyed by image id
 * and mosaic_mirrors is a list of image ids, so both look like further
 * reference sites. They are not independent ones: smackpress_mosaic_validate()
 * refuses a weight or a mirror naming an image that is not in asset_ids, so
 * every id in either is already in the member list. That is a guarantee from
 * the validator and it is tested there, which is the only reason it is safe to
 * rely on here. If that validator is ever loosened, this becomes a hole.
 */
const SNAP_MEDIA_MOSAIC_MEMBER_COLUMN = 'asset_ids';

/**
 * Setting KEYS whose value is an image id.
 *
 * snap_settings is key and value, so there is no column to find an id by: the
 * ticket's second trap. The site-wide signature defaults live here, and a
 * finder blind to them would offer to delete the site's own signature.
 *
 * TAKEN FROM signature-contract.php RATHER THAN RETYPED. Two copies of a key
 * list is how one gets updated and the other does not; the mosaic rules were
 * duplicated that way and so was the recovery engine's containment check.
 *
 * THE STATED LIMIT: this is a list, so a NEW id-bearing setting added
 * elsewhere would not appear here on its own. A key/value table cannot be
 * enumerated from a schema, so no check can derive this the way the column
 * list is derived. Anyone adding a setting that holds an image id must add it
 * here, and the regression says so in its failure message rather than pretending
 * the list is self-maintaining.
 */
function snap_media_setting_keys(): array {
    return array_values(SNAP_SIGNATURE_DEFAULT_KEYS);
}

/**
 * The renderer's own patterns for a photograph referenced in body text.
 *
 * COPIED FROM core/parser.php's parseImages() AND parseMosaics(), because if
 * the finder and the page disagree about what counts as a reference then the
 * finder is the one that is wrong. A hand-rolled LIKE '%753%' matches 7530.
 *
 * [img:ID] and [img:gID] are one photograph. [mosaic-images:1,2,3 layout=...]
 * is an inline regroup that has NO mosaic row at all, so its member ids exist
 * only in the body text -- the ticket's third trap, and the reason a body scan
 * cannot be skipped for sites with no mosaic rows.
 */
function snap_media_body_patterns(int $image_id): array {
    $id = (int)$image_id;
    return [
        'img_shortcode' => '/\[img:\s*g?\s*' . $id . '(?:\s*\|[^\]]*)?\s*\]/i',
        'inline_mosaic' => '/\[mosaic-images:\s*(?:\d+\s*,\s*)*' . $id
                         . '\s*(?:,\s*\d+)*\s+layout=(?:masonry|one-column)\s*\]/i',
    ];
}

/** Does this install have that table and column? */
function snap_media_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        return $pdo->query("SELECT {$column} FROM {$table} LIMIT 0") !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Every reference to one photograph, and every place that could not be checked.
 *
 * READ ONLY. No statement here writes, and the finder is not the purger: the
 * ticket requires purging to be a separate and explicit step and this file
 * gives it nothing to call.
 *
 * @return array{references: array, not_checked: array, uses: int, records: int}
 */
function snap_media_references(PDO $pdo, int $image_id): array {
    $image_id    = (int)$image_id;
    $references  = [];
    $not_checked = [];

    foreach (SNAP_MEDIA_REFERENCE_SITES as $site) {
        $table  = $site['table'];
        $column = $site['column'];
        if (!snap_media_column_exists($pdo, $table, $column)) {
            // NOT the same as "no reference". See the fail-closed note above.
            $not_checked[] = ['site' => "{$table}.{$column}",
                              'why'  => 'this install has no such table or column'];
            continue;
        }
        $sql    = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
        $params = [$image_id];
        if (isset($site['type_column'])) {
            if (!snap_media_column_exists($pdo, $table, $site['type_column'])) {
                $not_checked[] = ['site' => "{$table}.{$column}",
                                  'why'  => 'the type column that decides what this row points at is missing'];
                continue;
            }
            $sql     .= " AND {$site['type_column']} = ?";
            $params[] = $site['type_value'];
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $count = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $not_checked[] = ['site' => "{$table}.{$column}", 'why' => 'the query failed'];
            continue;
        }
        if ($count > 0) {
            $references[] = ['site' => "{$table}.{$column}", 'count' => $count,
                             'kind' => $site['kind'], 'what' => $site['what']];
        }
    }

    // ── the three places with no column to find an id by ──────────────────

    // 1. mosaic member lists, which are JSON
    $col = SNAP_MEDIA_MOSAIC_MEMBER_COLUMN;
    if (snap_media_column_exists($pdo, 'snap_mosaics', $col)) {
        try {
            $stmt = $pdo->query("SELECT id, {$col} FROM snap_mosaics");
            $in = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ids = json_decode((string)($row[$col] ?? '[]'), true);
                if (is_array($ids) && in_array($image_id, array_map('intval', $ids), true)) {
                    $in[] = (int)$row['id'];
                }
            }
            if ($in) {
                $references[] = ['site' => 'snap_mosaics.' . $col, 'count' => count($in),
                                 'kind' => 'use', 'mosaic_ids' => $in,
                                 'what' => 'a photograph in a mosaic'];
            }
        } catch (PDOException $e) {
            $not_checked[] = ['site' => 'snap_mosaics.' . $col, 'why' => 'the query failed'];
        }
    } else {
        $not_checked[] = ['site' => 'snap_mosaics.' . $col,
                          'why' => 'this install has no such table or column'];
    }

    // 2. settings VALUES, because the table is key and value
    if (snap_media_column_exists($pdo, 'snap_settings', 'setting_key')) {
        $keys = snap_media_setting_keys();
        $hits = [];
        foreach ($keys as $key) {
            try {
                $stmt = $pdo->prepare('SELECT setting_val FROM snap_settings WHERE setting_key = ? LIMIT 1');
                $stmt->execute([$key]);
                $val = $stmt->fetchColumn();
            } catch (PDOException $e) {
                $not_checked[] = ['site' => "snap_settings[{$key}]", 'why' => 'the query failed'];
                continue;
            }
            if ($val !== false && (int)$val === $image_id && $image_id > 0) { $hits[] = $key; }
        }
        if ($hits) {
            $references[] = ['site' => 'snap_settings', 'count' => count($hits),
                             'kind' => 'use', 'setting_keys' => $hits,
                             'what' => "the site's own default signature"];
        }
    } else {
        $not_checked[] = ['site' => 'snap_settings', 'why' => 'this install has no settings table'];
    }

    // 3. body text of posts and pages, with the renderer's patterns
    $patterns = snap_media_body_patterns($image_id);
    foreach ([['snap_posts', 'post'], ['snap_pages', 'page']] as [$table, $label]) {
        if (!snap_media_column_exists($pdo, $table, 'content')) {
            $not_checked[] = ['site' => "{$table}.content",
                              'why' => 'this install has no such table or column'];
            continue;
        }
        try {
            // LIKE is a PREFILTER only; every candidate is confirmed in PHP with
            // the renderer's pattern, so 7530 cannot be read as 753.
            $stmt = $pdo->prepare("SELECT id, content FROM {$table}
                                   WHERE content LIKE ? OR content LIKE ?");
            $stmt->execute(['%[img:%', '%[mosaic-images:%']);
        } catch (PDOException $e) {
            $not_checked[] = ['site' => "{$table}.content", 'why' => 'the query failed'];
            continue;
        }
        $found = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            foreach ($patterns as $kind => $pattern) {
                if (preg_match($pattern, (string)$row['content'])) {
                    $found[$kind][] = (int)$row['id'];
                }
            }
        }
        foreach ($found as $kind => $ids) {
            $references[] = ['site' => "{$table}.content", 'count' => count($ids),
                             'kind' => 'use', 'via' => $kind, $label . '_ids' => $ids,
                             'what' => $kind === 'img_shortcode'
                                 ? "the photograph is placed in a {$label}"
                                 : "the photograph is in an inline regroup in a {$label}, which has no mosaic row"];
        }
    }

    $uses = $records = 0;
    foreach ($references as $r) {
        if ($r['kind'] === 'use') { $uses++; } else { $records++; }
    }
    return ['references' => $references, 'not_checked' => $not_checked,
            'uses' => $uses, 'records' => $records];
}

/**
 * Can this photograph be offered as a purge candidate?
 *
 * REFUSES TO ANSWER rather than guessing. A single place that could not be
 * checked makes the answer unknown, and unknown is never a pass: the ticket's
 * whole risk is a finder that offers to delete something in use. Returns the
 * reason it is NOT a candidate, or null when it is one.
 */
function snap_media_purge_blocker(array $report): ?string {
    if ($report['not_checked'] !== []) {
        return 'not checked everywhere: ' . count($report['not_checked'])
             . ' place(s) could not be read, so "unused" cannot be claimed';
    }
    if ($report['uses'] > 0) {
        return 'in use in ' . $report['uses'] . ' place(s)';
    }
    if ($report['records'] > 0) {
        // Not a blocker by my proposed classification, and SEAN'S CALL. Said
        // out loud rather than silently dropped, because the ticket requires
        // the reasoning to be visible per row.
        return null;
    }
    return null;
}

// ===== SNAPSMACK EOF =====
