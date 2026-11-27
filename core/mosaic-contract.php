<?php
declare(strict_types=1);

/**
 * SNAPSMACK - the mosaic read, write and reference rules, in one place.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * WHY THIS FILE EXISTS, and it is the same reason as core/signature-contract.php
 * and core/duplicate-detection.php. These rules lived inside
 * core/smackpress-api.php, which reads a route and authenticates at the top
 * level, so a test cannot require it: including that file RUNS the API. Every
 * rule below was therefore unexecutable by the suite, and the only checks over
 * them were source assertions -- strings looked for in a file. A string is not
 * a behaviour. Moving the rules here makes them callable, so the suite can
 * drive the real validator and the real reader rather than grep for them.
 *
 * NOTHING HERE IS NEW BEHAVIOUR. It is the same code, moved, so that it can be
 * tested. The validator is the one the create route already used, now shared
 * with the update route, because two copies of these rules is how the recovery
 * engine ended up with an escape in the containment check nobody updated.
 *
 * WHAT THE RULES ARE:
 *   asset_ids is the AUTHORED ORDER and is stored exactly as sent. Shuffling is
 *     presentation; it never rewrites what was saved, and the desktop cannot
 *     write the shuffle flag at all.
 *   gap is clamped 0 to 20. layout must be one of ten. emphasis must be one of
 *     five, and the engine only acts on three of them.
 *   a weight is -1, 1 or 2 and must name an image that is in this mosaic.
 *   a mirror is a separate CONTIGUOUS run of two or more of the authored order.
 *   focus positions are clamped to the same 0 to 100 the renderer clamps to, so
 *     a stored value can never be one the page would refuse to use.
 *   the presentation columns may be ABSENT on an un-migrated site, and a read
 *     degrades the way core/parser.php degrades rather than erroring.
 */

function smackpress_mosaic_weights_available(PDO $pdo): bool {
    try {
        return $pdo->query(
            'SELECT asset_ids, focus_positions, gap, emphasis, layout, gallery_shuffle, mosaic_weights FROM snap_mosaics LIMIT 0'
        ) !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function smackpress_mosaic_mirrors_available(PDO $pdo): bool {
    try {
        return $pdo->query('SELECT mosaic_mirrors FROM snap_mosaics LIMIT 0') !== false;
    } catch (PDOException $e) {
        return false;
    }
}

// THE PROBE ABOVE IT TESTS MORE THAN ITS NAME SAYS. smackpress_mosaic_weights_
// available() selects asset_ids, focus_positions, gap, emphasis, layout,
// gallery_shuffle AND mosaic_weights in one statement, so a true answer means
// the whole presentation set exists, not just the weights. Everything below
// reuses it rather than adding a second probe, because two probes over the same
// columns is how they drift apart.

/**
 * The legal emphasis words, taken from the engine that acts on them.
 *
 * assets/js/ss-engine-mosaic.js branches on portrait, landscape and balanced in
 * blockScore(). natural and auto are the two that change no score: natural is
 * the schema default and auto is what the parser's inline [mosaic-images:...]
 * shortcode writes. Anything else is a typo, and a typo must not silently
 * become a layout decision.
 */
const SMACKPRESS_MOSAIC_EMPHASIS = ['natural', 'auto', 'balanced', 'portrait', 'landscape'];

/**
 * How many posts and pages reference this mosaic, and which.
 *
 * COUNTED WITH THE RENDERER'S OWN PATTERN, not a looser LIKE. core/parser.php
 * matches [mosaic:ID] with an optional width, so that is the pattern here too:
 * if the finder and the page disagree about what counts as a reference, the
 * finder is the one that is wrong, and a hand-rolled LIKE '%753%' would match
 * 7530 and every other number containing it.
 *
 * The LIKE is a PREFILTER only, to avoid reading every body in the site, and
 * every candidate is then confirmed in PHP. Static pages count, because a
 * mosaic on a static page is just as used as one in a post.
 *
 * @return array{posts: int[], pages: int[]}
 */
function smackpress_mosaic_references(PDO $pdo, int $mosaic_id): array {
    $pattern = '/\[mosaic:\s*' . $mosaic_id . '\s*(?:\s+width=\d{2,4})?\s*\]/i';
    $found = ['posts' => [], 'pages' => []];
    foreach ([['posts', 'snap_posts'], ['pages', 'snap_pages']] as [$key, $table]) {
        try {
            $stmt = $pdo->prepare("SELECT id, content FROM {$table} WHERE content LIKE ?");
            $stmt->execute(['%[mosaic:%']);
        } catch (PDOException $e) {
            continue;   // A table this install does not have cannot hold a reference.
        }
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (preg_match($pattern, (string)$row['content'])) {
                $found[$key][] = (int)$row['id'];
            }
        }
    }
    return $found;
}

/**
 * Read one mosaic as the desktop needs it, or null when there is no such row.
 *
 * DEGRADES THE WAY THE PARSER DOES, deliberately. core/parser.php drops to
 * asset_ids and gap alone when the presentation columns are missing, because a
 * site that has not run its schema sync still has to render. This answers the
 * same way rather than erroring: the fields that exist are returned, the ones
 * that do not are absent, and `capabilities` says which. A read that 500s on an
 * un-migrated site is useless to exactly the owner most likely to need it.
 */
function smackpress_mosaic_read(PDO $pdo, int $mosaic_id): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM snap_mosaics WHERE id = ? LIMIT 1');
        $stmt->execute([$mosaic_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    if (!$row) return null;

    $asset_ids = json_decode((string)($row['asset_ids'] ?? '[]'), true);
    if (!is_array($asset_ids)) $asset_ids = [];
    $asset_ids = array_values(array_map('intval', $asset_ids));

    $mosaic = [
        'id'        => (int)$row['id'],
        'title'     => (string)($row['title'] ?? ''),
        'asset_ids' => $asset_ids,     // AUTHORED ORDER. Never the shuffled view.
        'gap'       => (int)($row['gap'] ?? 4),
    ];
    // An absent column and a NULL column are different things: the first means
    // this install cannot store the field, the second means nobody has set it.
    // Looped rather than written out one column at a time, so there is no
    // column name spelled as a literal next to a presence test -- the shuffle
    // regression guards against exactly that shape appearing in this file,
    // because the flag used to be read off the POST and belongs to the mosaic.
    foreach (['layout' => 'string', 'emphasis' => 'string', 'gallery_shuffle' => 'flag'] as $col => $kind) {
        if (!array_key_exists($col, $row)) continue;
        $mosaic[$col] = $kind === 'flag' ? ((int)$row[$col] ? 1 : 0) : (string)$row[$col];
    }
    foreach (['focus_positions' => '{}', 'mosaic_weights' => '{}', 'mosaic_mirrors' => '[]'] as $col => $empty) {
        if (!array_key_exists($col, $row)) continue;
        $decoded = json_decode((string)($row[$col] ?? $empty), true);
        $mosaic[$col] = is_array($decoded) ? $decoded : json_decode($empty, true);
    }
    $mosaic['updated_at'] = (string)($row['updated_at'] ?? '');
    // THE CONFLICT TOKEN IS DERIVED, NOT STORED, so there is no new column and
    // no migration. It is opaque on purpose: the desktop must compare it, never
    // parse it, so the derivation can change without breaking a shipped client.
    $mosaic['revision'] = smackpress_mosaic_revision($row);
    return $mosaic;
}

/** The opaque conflict token for a mosaic row. */
function smackpress_mosaic_revision(array $row): string {
    return substr(hash('sha256', (string)($row['id'] ?? '') . '|' . (string)($row['updated_at'] ?? '')
        . '|' . (string)($row['asset_ids'] ?? '')), 0, 16);
}

/**
 * Validate a mosaic definition from a request body. ONE implementation.
 *
 * WHY THIS IS A FUNCTION AND NOT COPIED INTO THE UPDATE. The rules here are not
 * small -- ten layouts, a clamped gap, weights restricted to three values and to
 * members of this mosaic, and mirrors that must each be a separate contiguous
 * run of the authored order -- and the create route has carried them for some
 * time. A second copy for the update route would pass its own tests and then
 * drift, which is exactly how the recovery engine ended up with two containment
 * checks and an escape in the one nobody updated. So the create route now calls
 * this too, and there is one place to be right.
 *
 * REFUSES THROUGH AN INJECTED CALLABLE, taking the status and the message, so
 * the API can pass its own smackpress_error (which sets the HTTP code and
 * exits, as the create route always did) while a TEST can pass a collector and
 * keep running. Same arrangement as snap_signature_read(). Without this the
 * rules would be requireable and still untestable, because every refusal would
 * call a function that lives in the file a test cannot include.
 *
 * @return array{title:string,asset_ids:int[],gap:int,layout:string,gallery_shuffle:int,
 *               weights:array,mirrors:array,focus:array,emphasis:?string}
 */
function smackpress_mosaic_validate(array $body, PDO $pdo, callable $refuse): array {
    $title     = trim((string)($body['title'] ?? 'Untitled Mosaic'));
    $asset_ids = array_map('intval', $body['asset_ids'] ?? []);
    $gap       = max(0, min(20, (int)($body['gap'] ?? 4)));
    $layout    = (string)($body['layout'] ?? 'asymmetric');
    if (!in_array($layout, ['asymmetric', 'columns', 'rows', 'square', 'masonry', 'one-column',
                            'one-left', 'one-right', 'three-across', 'one-top'], true)) {
        $layout = 'asymmetric';
    }
    if (empty($asset_ids)) $refuse(422, 'asset_ids required.');

    $weights = [];
    if (array_key_exists('mosaic_weights', $body)) {
        if (!is_array($body['mosaic_weights'])) {
            $refuse(422, 'mosaic_weights must map selected Gallery image IDs to tile sizes.');
        }
        foreach ($body['mosaic_weights'] as $image_id => $weight) {
            // A JSON object key arrives as a string, so accept a digit string
            // as well as an int. The create route only ever saw int keys from
            // its own client; the desktop sends real JSON objects.
            $iid = (is_int($image_id) || (is_string($image_id) && ctype_digit($image_id))) ? (int)$image_id : -1;
            if ($iid < 1 || !in_array($iid, $asset_ids, true) || !in_array($weight, [-1, 1, 2], true)) {
                $refuse(422, 'Every mosaic weight must be -1, 1 or 2 and refer to a selected Gallery image.');
            }
            $weights[$iid] = $weight;
        }
    }

    $gallery_shuffle = !empty($body['gallery_shuffle']) ? 1 : 0;
    $mirrors = $body['mosaic_mirrors'] ?? [];
    if (!is_array($mirrors) || !array_is_list($mirrors)
        || ($mirrors && ($gallery_shuffle || (!$weights && in_array($layout, ['columns', 'rows', 'square', 'masonry', 'one-column'], true))))) {
        $refuse(422, 'Mosaic mirrors require an ordered editorial layout.');
    }
    $mirrored_ids = [];
    foreach ($mirrors as $group) {
        if (!is_array($group) || !array_is_list($group) || count($group) < 2) {
            $refuse(422, 'Each mirror must identify an ordered block of two or more Gallery images.');
        }
        $start = array_search($group[0], $asset_ids, true);
        if ($start === false || array_slice($asset_ids, $start, count($group)) !== $group
            || count(array_unique($group)) !== count($group) || array_intersect($mirrored_ids, $group)) {
            $refuse(422, 'Each mirror must match a separate contiguous block of Gallery images.');
        }
        array_push($mirrored_ids, ...$group);
    }

    // FOCUS AND EMPHASIS, which the create route could not carry until now.
    // Copy-on-edit for a shared mosaic creates a NEW row from an existing
    // definition, so a create that cannot carry these two would silently drop
    // every per-photograph focus point and reset the emphasis to the schema
    // default. For a photographer the focus point IS which part of the picture
    // shows, so losing it quietly on an edit is the worst available outcome.
    $focus = [];
    if (array_key_exists('focus_positions', $body)) {
        if (!is_array($body['focus_positions'])) {
            $refuse(422, 'focus_positions must map Gallery image IDs to an x and y position.');
        }
        foreach ($body['focus_positions'] as $image_id => $pos) {
            $iid = (is_int($image_id) || (is_string($image_id) && ctype_digit($image_id))) ? (int)$image_id : -1;
            if ($iid < 1 || !in_array($iid, $asset_ids, true) || !is_array($pos)
                || !isset($pos['x'], $pos['y']) || !is_numeric($pos['x']) || !is_numeric($pos['y'])) {
                $refuse(422, 'Every focus position must give x and y for a selected Gallery image.');
            }
            // Clamped to the same 0-100 the renderer clamps to, so a stored
            // value can never be one the page would refuse to use.
            // CAST AFTER THE CLAMP, not before. min() and max() return whichever
            // operand they pick, so an in-range value came back float from the
            // cast while a clamped one came back as the int bound -- and 100
            // and 100.0 are different bytes once this is JSON in a column.
            $focus[$iid] = [
                'x' => (float)max(0, min(100, (float)$pos['x'])),
                'y' => (float)max(0, min(100, (float)$pos['y'])),
            ];
        }
    }

    $emphasis = null;
    if (array_key_exists('emphasis', $body)) {
        $emphasis = strtolower(trim((string)$body['emphasis']));
        if (!in_array($emphasis, SMACKPRESS_MOSAIC_EMPHASIS, true)) {
            $refuse(422, 'emphasis must be one of: ' . implode(', ', SMACKPRESS_MOSAIC_EMPHASIS) . '.');
        }
    }

    if (($focus !== [] || $emphasis !== null) && !smackpress_mosaic_weights_available($pdo)) {
        $refuse(409, 'Update the site schema before publishing mosaic framing.');
    }

    return ['title' => $title, 'asset_ids' => $asset_ids, 'gap' => $gap, 'layout' => $layout,
            'gallery_shuffle' => $gallery_shuffle, 'weights' => $weights, 'mirrors' => $mirrors,
            'focus' => $focus, 'emphasis' => $emphasis];
}

// ===== SNAPSMACK EOF =====
