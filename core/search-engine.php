<?php
/**
 * SNAPSMACK - Shared Search Engine
 *
 * Full-text image search across img_title, img_description, hashtag slugs, and
 * colour families. Lifted out of the (locked) Photogram skin so every skin can
 * share ONE implementation instead of each carrying its own copy.
 *
 * Consumers:
 *   - The carousel GRAM skins (The Grid, AURORA, PARADE) via their search.php
 *   - 52 Card Pickup (solo) via its search.php
 *   - index.php routes ?q= here through the skin's search.php template.
 *
 * Photogram keeps its own skins/photogram/search.php for now (locked skin —
 * not refactored). It can be migrated onto this helper later.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * Map a plain-English colour word to the color_family value stored on
 * snap_tags, so "teal" surfaces #007a8b etc. Returns null if the term is not
 * a recognised colour.
 *
 * @param  string $q
 * @return string|null
 */
function snapsmack_search_color_family($q) {
    static $aliases = [
        'red' => 'red', 'scarlet' => 'red', 'crimson' => 'red', 'maroon' => 'red',
        'cherry' => 'red', 'ruby' => 'red', 'vermillion' => 'red',
        'orange' => 'orange', 'tangerine' => 'orange', 'amber' => 'orange',
        'rust' => 'orange', 'copper' => 'orange', 'peach' => 'orange', 'apricot' => 'orange',
        'yellow' => 'yellow', 'gold' => 'yellow', 'golden' => 'yellow', 'lemon' => 'yellow',
        'mustard' => 'yellow', 'blonde' => 'yellow',
        'green' => 'green', 'lime' => 'green', 'olive' => 'green', 'emerald' => 'green',
        'forest' => 'green', 'sage' => 'green', 'moss' => 'green', 'mint' => 'green', 'jade' => 'green',
        'teal' => 'teal', 'cyan' => 'teal', 'turquoise' => 'teal', 'aqua' => 'teal', 'aquamarine' => 'teal',
        'blue' => 'blue', 'navy' => 'blue', 'cobalt' => 'blue', 'azure' => 'blue', 'indigo' => 'blue',
        'cerulean' => 'blue', 'sapphire' => 'blue', 'royal' => 'blue', 'sky' => 'blue',
        'purple' => 'purple', 'violet' => 'purple', 'lavender' => 'purple', 'plum' => 'purple',
        'mauve' => 'purple', 'lilac' => 'purple', 'amethyst' => 'purple', 'magenta' => 'purple',
        'pink' => 'pink', 'fuchsia' => 'pink', 'fuschia' => 'pink', 'rose' => 'pink',
        'coral' => 'pink', 'salmon' => 'pink', 'blush' => 'pink', 'hot pink' => 'pink',
        'grey' => 'grey', 'gray' => 'grey', 'silver' => 'grey', 'charcoal' => 'grey',
        'slate' => 'grey', 'ash' => 'grey', 'pewter' => 'grey',
        'black' => 'black', 'ebony' => 'black', 'onyx' => 'black', 'jet' => 'black',
        'white' => 'white', 'ivory' => 'white', 'cream' => 'white', 'snow' => 'white',
        'pearl' => 'white', 'bone' => 'white', 'beige' => 'white',
    ];
    return $aliases[strtolower(trim($q))] ?? null;
}

/**
 * Run a search and return matched images, tags, and federated (network) posts.
 *
 * @param  PDO    $pdo
 * @param  string $q      Raw query string (already trimmed by caller is fine).
 * @param  int    $limit  Max image results (default 60).
 * @return array  [
 *                  'results' => [ ['id','img_title','img_slug','img_file',
 *                                  'img_thumb_square','img_thumb_aspect'], … ],
 *                  'tags'    => [ ['id','tag','slug','use_count'], … ],
 *                  'count'   => int,
 *                  'network' => [ ['object_id','actor_handle','content',
 *                                  'media_json','url','source','published'], … ],
 *                  'network_count' => int,
 *                ]
 */
function snapsmack_search($pdo, $q, $limit = 60) {
    $out = ['results' => [], 'tags' => [], 'count' => 0, 'network' => [], 'network_count' => 0];
    $q   = trim($q);
    if ($q === '') return $out;

    $now          = date('Y-m-d H:i:s');
    $color_family = snapsmack_search_color_family($q);
    $tag_term     = '%' . strtolower($q) . '%';
    $search_term  = '%' . $q . '%';
    $limit        = max(1, (int)$limit);

    try {
        // ── Matching tags (incl. colour-family matches) ─────────────────────
        if ($color_family) {
            $tag_stmt = $pdo->prepare("
                SELECT id, tag, slug, use_count
                FROM snap_tags
                WHERE (slug LIKE ? OR color_family = ?)
                  AND use_count > 0
                ORDER BY CASE WHEN color_family = ? THEN 0 ELSE 1 END, use_count DESC
                LIMIT 10
            ");
            $tag_stmt->execute([$tag_term, $color_family, $color_family]);
        } else {
            $tag_stmt = $pdo->prepare("
                SELECT id, tag, slug, use_count
                FROM snap_tags
                WHERE slug LIKE ? AND use_count > 0
                ORDER BY use_count DESC
                LIMIT 10
            ");
            $tag_stmt->execute([$tag_term]);
        }
        $out['tags'] = $tag_stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Image search: title + description + tag slug + colour family ────
        if ($color_family) {
            $img_stmt = $pdo->prepare("
                SELECT DISTINCT i.id, i.img_title, i.img_slug, i.img_file,
                       i.img_thumb_square, i.img_thumb_aspect
                FROM snap_images i
                LEFT JOIN snap_image_tags it ON it.image_id = i.id
                LEFT JOIN snap_tags t ON t.id = it.tag_id
                WHERE i.img_status = 'published'
                  AND i.img_date  <= ?
                  AND (i.img_title LIKE ? OR i.img_description LIKE ?
                       OR t.slug LIKE ? OR t.color_family = ?
                       OR EXISTS (SELECT 1 FROM snap_image_album_map _sam JOIN snap_albums _sa ON _sa.id = _sam.album_id WHERE _sam.image_id = i.id AND _sa.album_name LIKE ?)
                       OR EXISTS (SELECT 1 FROM snap_image_cat_map _scm JOIN snap_categories _sca ON _sca.id = _scm.cat_id WHERE _scm.image_id = i.id AND _sca.cat_name LIKE ?))
                ORDER BY i.sort_order ASC, i.id DESC
                LIMIT " . $limit . "
            ");
            $img_stmt->execute([$now, $search_term, $search_term, $tag_term, $color_family, $search_term, $search_term]);
        } else {
            $img_stmt = $pdo->prepare("
                SELECT DISTINCT i.id, i.img_title, i.img_slug, i.img_file,
                       i.img_thumb_square, i.img_thumb_aspect
                FROM snap_images i
                LEFT JOIN snap_image_tags it ON it.image_id = i.id
                LEFT JOIN snap_tags t ON t.id = it.tag_id
                WHERE i.img_status = 'published'
                  AND i.img_date  <= ?
                  AND (i.img_title LIKE ? OR i.img_description LIKE ? OR t.slug LIKE ?
                       OR EXISTS (SELECT 1 FROM snap_image_album_map _sam JOIN snap_albums _sa ON _sa.id = _sam.album_id WHERE _sam.image_id = i.id AND _sa.album_name LIKE ?)
                       OR EXISTS (SELECT 1 FROM snap_image_cat_map _scm JOIN snap_categories _sca ON _sca.id = _scm.cat_id WHERE _scm.image_id = i.id AND _sca.cat_name LIKE ?))
                ORDER BY i.sort_order ASC, i.id DESC
                LIMIT " . $limit . "
            ");
            $img_stmt->execute([$now, $search_term, $search_term, $tag_term, $search_term, $search_term]);
        }
        $out['results'] = $img_stmt->fetchAll(PDO::FETCH_ASSOC);
        $out['count']   = count($out['results']);

        // ── Network search: federated posts the relay cached locally ────────
        //    Reads snap_ap_timeline.content (note text, hashtags inline) + actor_handle.
        //    ADDITIVE: separate 'network' bucket, so consumers of 'results' (image
        //    rows with img_* fields) are unaffected. Excludes replies to keep it to
        //    posts, not comment chatter. snap_ap_timeline is populated by the relay;
        //    on a fresh install the table may lag — the outer catch keeps search safe.
        $net_stmt = $pdo->prepare("
            SELECT object_id, actor_url, actor_handle, content, media_json,
                   url, source, published
            FROM snap_ap_timeline
            WHERE (content LIKE ? OR actor_handle LIKE ?)
              AND (in_reply_to IS NULL OR in_reply_to = '')
              AND (published IS NULL OR published <= ?)
            ORDER BY published DESC
            LIMIT " . $limit . "
        ");
        $net_stmt->execute([$search_term, $search_term, $now]);
        $out['network']       = $net_stmt->fetchAll(PDO::FETCH_ASSOC);
        $out['network_count'] = count($out['network']);
    } catch (PDOException $e) {
        // Search must never fatal a page — return whatever we have.
    }

    return $out;
}
// ===== SNAPSMACK EOF =====
