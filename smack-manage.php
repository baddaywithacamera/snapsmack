<?php
/**
 * SNAPSMACK - Archive management dashboard
 *
 * Provides searchable listing of all posts with filtering by status, category, and album.
 * Supports deletion of posts with cascading removal of associated data and files.
 * Supports drag-and-drop manual sort ordering via sort_order column.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';
require_once 'core/reauth.php';   // step-up auth (password + 2FA) for the orphan purge

/**
 * Delete one standalone image: its files, row, and image-level maps + comments.
 * Photoblog unit. (For carousel content use snap_manage_delete_post().)
 */
function snap_manage_delete_image(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ?");
    $stmt->execute([$id]);
    $img = $stmt->fetch();
    if ($img && !empty($img['img_file']) && file_exists($img['img_file'])) {
        $pi = pathinfo($img['img_file']);
        $td = $pi['dirname'] . '/thumbs';
        @unlink($td . '/t_' . $pi['basename']);
        @unlink($td . '/a_' . $pi['basename']);
        @unlink($img['img_file']);
    }
    $pdo->prepare("DELETE FROM snap_images WHERE id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_comments WHERE img_id = ?")->execute([$id]);
}

/**
 * Delete an entire post container and everything that belongs to it: its images
 * (unless an image is shared with another post), the post→image links, trigram
 * slices, post-level collection membership, and the post row. A Manage grid tile
 * IS a post, so deleting a tile must remove the whole post — deleting only the
 * cover image (the old behaviour) orphaned the snap_posts row, which then stayed
 * invisible in the grid yet counted by the bulk-import guard as phantom content.
 */
function snap_manage_delete_post(PDO $pdo, int $pid): void {
    $q = $pdo->prepare("SELECT image_id FROM snap_post_images WHERE post_id = ?");
    $q->execute([$pid]);
    $image_ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

    $pdo->prepare("DELETE FROM snap_post_images WHERE post_id = ?")->execute([$pid]);

    foreach ($image_ids as $iid) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM snap_post_images WHERE image_id = ?");
        $chk->execute([$iid]);
        if ((int)$chk->fetchColumn() === 0) {
            snap_manage_delete_image($pdo, $iid); // image no longer used by any post
        }
    }

    $pdo->prepare("DELETE FROM snap_trigrams WHERE post_id_1 = ? OR post_id_2 = ? OR post_id_3 = ?")
        ->execute([$pid, $pid, $pid]);
    $pdo->prepare("DELETE FROM snap_collection_items WHERE item_type = 'post' AND item_id = ?")
        ->execute([$pid]);
    $pdo->prepare("DELETE FROM snap_posts WHERE id = ?")->execute([$pid]);
}

/**
 * Route a Manage delete keyed by image id: if the image belongs to any post,
 * delete those whole posts (carousel); otherwise delete the lone image
 * (photoblog). Either way no orphaned snap_posts are left behind.
 */
function snap_manage_delete_by_image(PDO $pdo, int $image_id): void {
    $q = $pdo->prepare("SELECT DISTINCT post_id FROM snap_post_images WHERE image_id = ?");
    $q->execute([$image_id]);
    $post_ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    if ($post_ids) {
        foreach ($post_ids as $pid) snap_manage_delete_post($pdo, $pid);
    } else {
        snap_manage_delete_image($pdo, $image_id);
    }
}

/**
 * Find sets of DUPLICATE solo images (photoblog units — post_id IS NULL).
 * A set is grouped by the strongest available identity, in priority order:
 *   1. img_checksum    — SHA-256 of the file; byte-identical = certain duplicate.
 *   2. img_source_file — the original upload filename; same source re-imported.
 *   3. img_title + img_date — the same titled capture posted more than once.
 * An image with none of those (no checksum, no source, no title) can't be safely
 * matched and is NEVER treated as a duplicate. Returns only sets of 2+, each with
 * a suggested "keeper" (the most complete copy — most categories/albums, then a
 * caption, then the oldest), so nothing carrying metadata is lost by default.
 *
 * @return array<int,array{key:string,keeper_id:int,items:array}>
 */
function snap_manage_duplicate_groups(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT i.id, i.img_title, i.img_slug, i.img_date, i.img_file,
                i.img_width, i.img_height, i.img_description,
                CASE
                  WHEN i.img_checksum    IS NOT NULL AND i.img_checksum    <> '' THEN CONCAT('c:', i.img_checksum)
                  WHEN i.img_source_file IS NOT NULL AND i.img_source_file <> '' THEN CONCAT('s:', i.img_source_file)
                  WHEN i.img_title <> '' THEN CONCAT('t:', i.img_title, '|', i.img_date)
                  ELSE CONCAT('id:', i.id)
                END AS dup_key,
                (SELECT COUNT(*) FROM snap_image_cat_map   m WHERE m.image_id = i.id) AS cat_n,
                (SELECT COUNT(*) FROM snap_image_album_map a WHERE a.image_id = i.id) AS alb_n
         FROM snap_images i
         WHERE i.post_id IS NULL
         ORDER BY dup_key, i.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $by_key = [];
    foreach ($rows as $r) {
        if (strncmp((string)$r['dup_key'], 'id:', 3) === 0) continue;  // ungroupable — never a dup
        $by_key[$r['dup_key']][] = $r;
    }

    $groups = [];
    foreach ($by_key as $k => $items) {
        if (count($items) < 2) continue;
        $keeper = $items[0];
        $best   = -1;
        foreach ($items as $it) {
            $score = ((int)$it['cat_n'] + (int)$it['alb_n']) * 2
                   + (trim((string)$it['img_description']) !== '' ? 1 : 0);
            if ($score > $best) { $best = $score; $keeper = $it; }
        }
        $groups[] = ['key' => (string)$k, 'keeper_id' => (int)$keeper['id'], 'items' => $items];
    }
    return $groups;
}

// --- BATCH DELETE HANDLER ---
// Routes each selected tile through the post-aware cascade so nothing is orphaned.
if (isset($_POST['action']) && $_POST['action'] === 'batch_delete') {
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    // In GRAM/LONGFORM the tiles ARE posts (ids are snap_posts ids), so delete the
    // post directly. In photoblog the tiles are images — route through the
    // image→post cascade as before. The form declares which via del_mode.
    $del_by_post = (($_POST['del_mode'] ?? '') === 'post');
    $deleted = 0;
    foreach ($ids as $id) {
        if ($del_by_post) {
            snap_manage_delete_post($pdo, $id);
        } else {
            snap_manage_delete_by_image($pdo, $id);
        }
        $deleted++;
    }
    header("Location: smack-manage.php?msg=batch_deleted&count=$deleted");
    exit;
}

// --- PURGE ORPHANED POSTS (DESTRUCTIVE — password + 2FA) ---
// Clears snap_posts rows that have NO images — the ghosts left by older
// image-only deletes or partial imports, which the grid can't show (no cover)
// yet the import guard counts as content. See [[feedback_stepup_auth_pass_plus_2fa]].
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['purge_orphans'])) {
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        header('Location: smack-manage.php?purge_err=' . urlencode($ra['error']));
        exit;
    }
    // Orphans = IMAGE-based posts (carousel/panorama) that lost all their images.
    // LONGFORM (SmackTalk) posts are TEXT — they legitimately have zero
    // snap_post_images rows, so they must NEVER be treated as orphans or this
    // tool silently deletes every SmackTalk post. Exclude them explicitly.
    $orphans = $pdo->query(
        "SELECT p.id FROM snap_posts p
         LEFT JOIN snap_post_images pi ON pi.post_id = p.id
         WHERE pi.id IS NULL
           AND p.post_type <> 'longform'"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($orphans as $pid) {
        $pid = (int)$pid;
        $pdo->prepare("DELETE FROM snap_trigrams WHERE post_id_1 = ? OR post_id_2 = ? OR post_id_3 = ?")->execute([$pid, $pid, $pid]);
        $pdo->prepare("DELETE FROM snap_collection_items WHERE item_type = 'post' AND item_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM snap_posts WHERE id = ?")->execute([$pid]);
    }
    header('Location: smack-manage.php?msg=orphans_purged&count=' . count($orphans));
    exit;
}

// --- REMOVE DUPLICATE POSTS (DESTRUCTIVE — password + 2FA) ---
// Deletes the ticked copies of duplicate solo posts. A safety net recomputes the
// sets server-side and NEVER lets the LAST surviving copy of a set be deleted, so
// a duplicated photo is thinned to one — it can never be wiped out entirely, even
// if every box in a set was ticked.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['remove_dupes'])) {
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        header('Location: smack-manage.php?find_dupes=1&purge_err=' . urlencode($ra['error']));
        exit;
    }
    $requested = array_flip(array_filter(array_map('intval', $_POST['dup_remove'] ?? [])));
    $groups    = snap_manage_duplicate_groups($pdo);
    $to_delete = [];
    $protected = 0;
    foreach ($groups as $g) {
        $ids = array_map(static fn($it) => (int)$it['id'], $g['items']);
        $rm  = array_values(array_filter($ids, static fn($id) => isset($requested[$id])));
        if (!$rm) continue;
        // Never delete every copy in a set — always leave one survivor (the keeper
        // if it was ticked, otherwise the first ticked copy).
        if (count($rm) >= count($ids)) {
            $survivor = in_array($g['keeper_id'], $rm, true) ? $g['keeper_id'] : $rm[0];
            $rm = array_values(array_filter($rm, static fn($id) => $id !== $survivor));
            $protected++;
        }
        foreach ($rm as $id) $to_delete[$id] = true;
    }
    foreach (array_keys($to_delete) as $id) {
        snap_manage_delete_by_image($pdo, (int)$id);
    }
    $q = 'msg=dupes_removed&count=' . count($to_delete);
    if ($protected) $q .= '&protected=' . $protected;
    header('Location: smack-manage.php?' . $q);
    exit;
}

// --- AJAX: REORDER HANDLER ---
// Receives an ordered array of post IDs for the current page and re-numbers
// sort_order globally so the new sequence is preserved across all pages.
if (isset($_POST['action']) && $_POST['action'] === 'reorder') {
    header('Content-Type: application/json');
    $new_page_order = array_map('intval', $_POST['ids'] ?? []);
    if (empty($new_page_order)) {
        echo json_encode(['ok' => false, 'error' => 'No IDs supplied']);
        exit;
    }

    // Fetch the full current order from the DB.
    $all_ids = $pdo->query("SELECT id FROM snap_images ORDER BY sort_order ASC, id DESC")
                   ->fetchAll(PDO::FETCH_COLUMN);

    // Find where the current-page IDs sit in the global list and replace that slice.
    $page_set = array_flip($new_page_order);
    $stripped  = array_values(array_filter($all_ids, fn($id) => !isset($page_set[$id])));

    // Determine insertion point: position of the first old occurrence of any page ID.
    $insert_at = count($stripped); // default: append
    foreach ($all_ids as $pos => $id) {
        if (isset($page_set[$id])) {
            // Find the equivalent position in $stripped (items before this one that aren't on the page).
            $insert_at = count(array_filter(array_slice($all_ids, 0, $pos), fn($x) => !isset($page_set[$x])));
            break;
        }
    }

    array_splice($stripped, $insert_at, 0, $new_page_order);
    $final_order = $stripped;

    // Renumber all rows.
    $stmt = $pdo->prepare("UPDATE snap_images SET sort_order = ? WHERE id = ?");
    foreach ($final_order as $pos => $id) {
        $stmt->execute([$pos + 1, $id]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// --- DELETION HANDLER ---
// Post-aware cascade (see snap_manage_delete_by_image): deleting a carousel tile
// removes the whole post + its images + links + trigram/collection refs; a lone
// photoblog image is removed on its own. Leaves no orphaned snap_posts rows.
// (Previously this deleted only the image and also ran a DELETE against
// snap_collection_items.image_id — a column dropped when collections went
// polymorphic — which 500'd every delete.)
if (isset($_GET['delete'])) {
    csrf_verify(); // SECAUDIT 047 — GET deletion must carry the CSRF token
    snap_manage_delete_by_image($pdo, (int)$_GET['delete']);
    header("Location: smack-manage.php?msg=deleted");
    exit;
}

// Post-keyed delete: GRAM/LONGFORM tiles are snap_posts rows, not images.
if (isset($_GET['delete_post'])) {
    csrf_verify(); // GET deletion must carry the CSRF token
    snap_manage_delete_post($pdo, (int)$_GET['delete_post']);
    header("Location: smack-manage.php?msg=deleted");
    exit;
}

// --- FILTER PARAMETERS ---
$search            = $_GET['search']        ?? '';
$cat_filter        = $_GET['cat_id']        ?? '';
$album_filter      = $_GET['album_id']      ?? '';
$collection_filter = $_GET['collection_id'] ?? '';
$status_filter     = $_GET['status']        ?? '';
$needs_filter      = $_GET['needs']         ?? '';   // title|caption|tags|any|autoorient|fubar
$orient_filter     = $_GET['orient']        ?? '';   // landscape|portrait|square

// Belt-and-suspenders: the auto-orient flag column is read below; guarantee it
// exists on installs that haven't posted (which would otherwise add it) since the update.
$pdo->exec("ALTER TABLE snap_images ADD COLUMN IF NOT EXISTS img_auto_orient TINYINT(1) NOT NULL DEFAULT 0");

// Drag reorder only available when showing all posts unfiltered.
$filters_active = ($search !== '' || $cat_filter !== '' || $album_filter !== '' || $collection_filter !== '' || $status_filter !== '' || $needs_filter !== '' || $orient_filter !== '');

// --- PAGINATION ---
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$params = [];
$where_clauses = [];

if ($search) {
    $where_clauses[] = "(i.img_title LIKE ? OR i.img_description LIKE ? OR i.img_film LIKE ? OR i.img_exif LIKE ?)";
    $params = array_merge($params, array_fill(0, 4, "%$search%"));
}
if ($cat_filter) {
    $where_clauses[] = "i.id IN (SELECT image_id FROM snap_image_cat_map WHERE cat_id = ?)";
    $params[] = $cat_filter;
}
if ($album_filter) {
    $where_clauses[] = "i.id IN (SELECT image_id FROM snap_image_album_map WHERE album_id = ?)";
    $params[] = $album_filter;
}
if ($collection_filter) {
    $where_clauses[] = "i.id IN (SELECT image_id FROM snap_collection_items WHERE collection_id = ?)";
    $params[] = $collection_filter;
}

$now_local = date('Y-m-d H:i:s');
if ($status_filter === 'draft') {
    $where_clauses[] = "i.img_status = 'draft'";
} elseif ($status_filter === 'scheduled') {
    $where_clauses[] = "i.img_status = 'published' AND i.img_date > ?";
    $params[] = $now_local;
} elseif ($status_filter === 'live') {
    $where_clauses[] = "i.img_status = 'published' AND i.img_date <= ?";
    $params[] = $now_local;
}

// --- "NEEDS WORK" FILTER ---
// Find posts still missing metadata so they can be enriched without paging
// through the whole archive. A title/caption is "missing" when NULL or empty;
// hashtags are "missing" when the image has no rows in snap_image_tags.
$no_title   = "(i.img_title IS NULL OR i.img_title = '')";
$no_caption = "(i.img_description IS NULL OR i.img_description = '')";
$no_tags    = "i.id NOT IN (SELECT image_id FROM snap_image_tags)";
$no_file    = "(i.img_file IS NULL OR i.img_file = '')";
if ($needs_filter === 'title') {
    $where_clauses[] = $no_title;
} elseif ($needs_filter === 'caption') {
    $where_clauses[] = $no_caption;
} elseif ($needs_filter === 'tags') {
    $where_clauses[] = $no_tags;
} elseif ($needs_filter === 'any') {
    $where_clauses[] = "($no_title OR $no_caption OR $no_tags)";
} elseif ($needs_filter === 'autoorient') {
    $where_clauses[] = "i.img_auto_orient = 1";
} elseif ($needs_filter === 'fubar') {
    // "Find the fucked-up stuff": anything incomplete OR with a missing file record.
    $where_clauses[] = "($no_title OR $no_caption OR $no_tags OR $no_file)";
}

// Orientation: 0 = landscape, 1 = portrait, 2 = square (img_orientation).
$orient_map = ['landscape' => 0, 'portrait' => 1, 'square' => 2];
if (isset($orient_map[$orient_filter])) {
    $where_clauses[] = "i.img_orientation = ?";
    $params[] = $orient_map[$orient_filter];
}

$where_sql = $where_clauses ? " WHERE " . implode(" AND ", $where_clauses) : "";

// --- DATA RETRIEVAL ---
// Site mode decides WHAT "a post" is. Photoblog: the image row IS the post, so
// the manager lists snap_images. GRAMOFSMACK (carousel) and SMACKTALK (longform):
// a post is a real snap_posts row, so the manager must list THOSE — never the raw
// images that belong to a post. Post-images are managed in the Media Gallery;
// page-images in the Media Library. (0.7.538 — stop listing images as posts.)
$mng_site_mode = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog';
$is_post_mode  = in_array($mng_site_mode, ['carousel', 'smacktalk'], true);

if (!$is_post_mode) {
    // ---- PHOTOBLOG: the image IS the post (unchanged) ----
    $count_stmt = $pdo->prepare("SELECT COUNT(i.id) FROM snap_images i $where_sql");
    $count_stmt->execute($params);
    $total_rows = $count_stmt->fetchColumn();

    $sql = "SELECT i.*,
            (SELECT GROUP_CONCAT(c.cat_name ORDER BY c.cat_name ASC SEPARATOR ', ')
             FROM snap_categories c
             JOIN snap_image_cat_map m ON c.id = m.cat_id
             WHERE m.image_id = i.id) as category_list,
            (SELECT GROUP_CONCAT(a.album_name ORDER BY a.album_name ASC SEPARATOR ', ')
             FROM snap_albums a
             JOIN snap_image_album_map am ON a.id = am.album_id
             WHERE am.image_id = i.id) as album_list,
            (SELECT GROUP_CONCAT(sc.title ORDER BY sc.title ASC SEPARATOR ', ')
             FROM snap_collections sc
             JOIN snap_collection_items sci ON sc.id = sci.collection_id
             WHERE sci.item_type = 'post' AND sci.item_id = i.id) as collection_list,
            (SELECT COUNT(*) FROM snap_comments WHERE img_id = i.id) as comment_count,
            (SELECT COUNT(*) FROM snap_likes WHERE post_id = i.id) as like_count
            FROM snap_images i
            $where_sql
            ORDER BY i.sort_order ASC, i.id DESC
            LIMIT $per_page OFFSET $offset";

    $posts = $pdo->prepare($sql);
    $posts->execute($params);
    $post_list = $posts->fetchAll();
} else {
    // ---- GRAM / LONGFORM: list real posts (snap_posts) ----
    // Columns are aliased to the img_* names the grid template already reads, so
    // rendering stays shared. Image-only filters (needs/orientation) don't apply
    // to posts and are hidden in the filter bar for these modes.
    $ptypes = ($mng_site_mode === 'smacktalk')
        ? ['longform']
        : ['single', 'carousel', 'panorama'];
    $ph       = implode(',', array_fill(0, count($ptypes), '?'));
    $p_where  = ["p.post_type IN ($ph)"];
    $p_params = $ptypes;

    if ($search) {
        $p_where[]  = "(p.title LIKE ? OR p.slug LIKE ? OR p.description LIKE ?)";
        $p_params[] = "%$search%";
        $p_params[] = "%$search%";
        $p_params[] = "%$search%";
    }
    if ($status_filter === 'draft') {
        $p_where[] = "p.status = 'draft'";
    } elseif ($status_filter === 'scheduled') {
        $p_where[]  = "p.status = 'published' AND p.created_at > ?";
        $p_params[] = $now_local;
    } elseif ($status_filter === 'live') {
        $p_where[]  = "p.status = 'published' AND p.created_at <= ?";
        $p_params[] = $now_local;
    }
    if ($cat_filter) {
        $p_where[]  = "p.id IN (SELECT post_id FROM snap_post_cat_map WHERE cat_id = ?)";
        $p_params[] = $cat_filter;
    }
    if ($album_filter) {
        $p_where[]  = "p.id IN (SELECT post_id FROM snap_post_album_map WHERE album_id = ?)";
        $p_params[] = $album_filter;
    }
    if ($collection_filter) {
        $p_where[]  = "p.id IN (SELECT item_id FROM snap_collection_items WHERE item_type = 'post' AND collection_id = ?)";
        $p_params[] = $collection_filter;
    }
    $p_where_sql = " WHERE " . implode(" AND ", $p_where);

    $count_stmt = $pdo->prepare("SELECT COUNT(p.id) FROM snap_posts p $p_where_sql");
    $count_stmt->execute($p_params);
    $total_rows = $count_stmt->fetchColumn();

    // Cover thumbnail: GRAM uses the flagged cover in snap_post_images; LONGFORM
    // uses its featured image (may be NULL → the row shows a text tile instead).
    if ($mng_site_mode === 'smacktalk') {
        $cover_file_sql = "(SELECT img_file FROM snap_images WHERE id = p.featured_image_id)";
        $cover_id_sql   = "p.featured_image_id";
    } else {
        $cover_file_sql = "(SELECT ci.img_file FROM snap_post_images pi
                             JOIN snap_images ci ON ci.id = pi.image_id
                             WHERE pi.post_id = p.id
                             ORDER BY pi.is_cover DESC, pi.sort_position ASC LIMIT 1)";
        $cover_id_sql   = "(SELECT pi.image_id FROM snap_post_images pi
                             WHERE pi.post_id = p.id
                             ORDER BY pi.is_cover DESC, pi.sort_position ASC LIMIT 1)";
    }

    $sql = "SELECT p.id,
            p.title          AS img_title,
            p.slug           AS img_slug,
            p.status         AS img_status,
            p.created_at     AS img_date,
            p.download_count AS img_download_count,
            $cover_file_sql  AS img_file,
            $cover_id_sql    AS cover_img_id,
            (SELECT GROUP_CONCAT(c.cat_name ORDER BY c.cat_name ASC SEPARATOR ', ')
             FROM snap_categories c
             JOIN snap_post_cat_map m ON c.id = m.cat_id
             WHERE m.post_id = p.id) as category_list,
            (SELECT GROUP_CONCAT(a.album_name ORDER BY a.album_name ASC SEPARATOR ', ')
             FROM snap_albums a
             JOIN snap_post_album_map am ON a.id = am.album_id
             WHERE am.post_id = p.id) as album_list,
            (SELECT GROUP_CONCAT(sc.title ORDER BY sc.title ASC SEPARATOR ', ')
             FROM snap_collections sc
             JOIN snap_collection_items sci ON sc.id = sci.collection_id
             WHERE sci.item_type = 'post' AND sci.item_id = p.id) as collection_list,
            (SELECT COUNT(*) FROM snap_comments WHERE post_id = p.id) as comment_count,
            (SELECT COUNT(*) FROM snap_likes    WHERE post_id = p.id) as like_count
            FROM snap_posts p
            $p_where_sql
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT $per_page OFFSET $offset";

    $posts = $pdo->prepare($sql);
    $posts->execute($p_params);
    $post_list = $posts->fetchAll();
}
$total_pages = ceil($total_rows / $per_page);

// Drag-reorder writes snap_images.sort_order, so it only makes sense in photoblog
// mode and only with no filters applied.
$reorder_enabled = (!$is_post_mode && !$filters_active);

$cats        = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$albums      = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();
$collections = $pdo->query("SELECT * FROM snap_collections ORDER BY title ASC")->fetchAll();

$success_msg = '';
$purge_err   = '';
if (!empty($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') {
        $success_msg = 'Transmission purged.';
    } elseif ($_GET['msg'] === 'batch_deleted') {
        $n = (int)($_GET['count'] ?? 0);
        $success_msg = "$n transmission" . ($n !== 1 ? 's' : '') . " purged.";
    } elseif ($_GET['msg'] === 'orphans_purged') {
        $n = (int)($_GET['count'] ?? 0);
        $success_msg = "$n orphaned post" . ($n !== 1 ? 's' : '') . " purged — the database is clean.";
    } elseif ($_GET['msg'] === 'dupes_removed') {
        $n = (int)($_GET['count'] ?? 0);
        $success_msg = "$n duplicate post" . ($n !== 1 ? 's' : '') . " removed.";
        if (!empty($_GET['protected'])) {
            $success_msg .= ' ' . (int)$_GET['protected'] . ' set(s) kept one copy, so nothing was fully deleted.';
        }
    }
}
if (!empty($_GET['purge_err'])) {
    $purge_err = (string)$_GET['purge_err'];
}

// Orphaned posts = snap_posts rows with no images. Invisible in the grid (no
// cover) but still counted as content by the bulk-import guard, which is what
// keeps blocking a "should-be-empty" site. Surfaced so the owner can purge them.
$orphan_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM snap_posts p
     LEFT JOIN snap_post_images pi ON pi.post_id = p.id
     WHERE pi.id IS NULL
       AND p.post_type <> 'longform'"
)->fetchColumn();

// Duplicate solo posts — the same photo posted more than once (re-imports). The
// cheap count drives a banner on the normal page; the full grouped review only
// runs when the owner opens it (?find_dupes=1). The CASE mirrors
// snap_manage_duplicate_groups() exactly so the banner and the review agree.
$dupe_extra_count = (int)$pdo->query(
    "SELECT COALESCE(SUM(cnt - 1), 0) FROM (
        SELECT COUNT(*) AS cnt
        FROM snap_images
        WHERE post_id IS NULL
        GROUP BY CASE
          WHEN img_checksum    IS NOT NULL AND img_checksum    <> '' THEN CONCAT('c:', img_checksum)
          WHEN img_source_file IS NOT NULL AND img_source_file <> '' THEN CONCAT('s:', img_source_file)
          WHEN img_title <> '' THEN CONCAT('t:', img_title, '|', img_date)
          ELSE CONCAT('id:', id)
        END
        HAVING COUNT(*) > 1
     ) d"
)->fetchColumn();
$show_dupes  = isset($_GET['find_dupes']);
$dupe_groups = $show_dupes ? snap_manage_duplicate_groups($pdo) : [];

$page_title = "Manage Archive";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>MANAGE ARCHIVE (<?php echo $total_rows; ?>)</h2>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success">> <?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <?php if ($purge_err): ?>
        <div class="alert alert-error">> <?php echo htmlspecialchars($purge_err); ?></div>
    <?php endif; ?>

    <?php if ($orphan_count > 0): ?>
    <div class="box" style="border:1px solid var(--danger, #e45735);">
        <h3 style="color:var(--danger, #e45735); margin-top:0;">
            ⚠ <?php echo $orphan_count; ?> orphaned post<?php echo $orphan_count !== 1 ? 's' : ''; ?> in the database
        </h3>
        <p style="font-size:0.85rem; opacity:0.75; max-width:640px;">
            Post records with no images — invisible in the grid, but still counted as content,
            which can block a fresh import into a site that looks empty. Purging removes the empty
            post rows and their trigram / collection references. Images are not affected.
            Requires your password and 2FA.
        </p>
        <form method="POST"
              onsubmit="return confirm('Purge <?php echo $orphan_count; ?> orphaned post(s)? This cannot be undone.');"
              style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;">
            <?php if (function_exists('csrf_field')) csrf_field(); ?>
            <input type="hidden" name="purge_orphans" value="1">
            <input type="password" name="reauth_password" placeholder="Password"
                   autocomplete="current-password" required style="width:220px;">
            <input type="text" name="reauth_totp" placeholder="2FA code" inputmode="numeric"
                   autocomplete="one-time-code" maxlength="10" required style="width:110px;">
            <button type="submit" class="btn-smack btn-danger">Purge Orphaned Posts</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($dupe_extra_count > 0 && !$show_dupes): ?>
    <div class="box dup-banner">
        <span>⚠ <?php echo $dupe_extra_count; ?> duplicate post<?php echo $dupe_extra_count !== 1 ? 's' : ''; ?> detected — the same photo posted more than once.</span>
        <a href="?find_dupes=1" class="btn-smack">Review Duplicates</a>
    </div>
    <?php endif; ?>

    <?php if ($show_dupes): ?>
    <div class="box dup-review">
        <h3 class="dup-review__title">REVIEW DUPLICATES</h3>
        <?php if (!$dupe_groups): ?>
            <p class="dim">No duplicate posts found. <a href="smack-manage.php">Back to the archive.</a></p>
        <?php else: ?>
        <p class="dim dup-review__intro">
            Each set below is the same photo posted more than once. The copy marked
            KEEP has the most categories / albums (or a caption) and is left alone;
            the others are ticked for removal. Adjust the ticks, then confirm with
            your password and 2FA. At least one copy of every set is always kept, so
            a photo can never be wiped out entirely.
        </p>
        <form method="POST" id="dup-form"
              onsubmit="return confirm('Remove the ticked duplicate copies? This cannot be undone.');">
            <?php if (function_exists('csrf_field')) csrf_field(); ?>
            <input type="hidden" name="remove_dupes" value="1">
            <?php foreach ($dupe_groups as $gi => $g): ?>
            <div class="dup-group">
                <div class="dup-group__head">Set <?php echo $gi + 1; ?> — <?php echo count($g['items']); ?> copies</div>
                <?php foreach ($g['items'] as $it):
                    $is_keeper = ((int)$it['id'] === $g['keeper_id']);
                    $_af  = (string)$it['img_file'];
                    $_api = pathinfo($_af);
                    $_tp  = $_api['dirname'] . '/thumbs/t_' . $_api['basename'];
                    $_src = ($_af !== '' && file_exists($_tp)) ? '/' . ltrim($_tp, '/') : '/' . ltrim($_af, '/');
                ?>
                <div class="recent-item dup-item<?php echo $is_keeper ? ' dup-item--keep' : ''; ?>">
                    <label class="batch-check-wrap">
                        <?php if ($is_keeper): ?>
                            <span class="dup-keep-badge" title="Kept — the most complete copy">KEEP</span>
                        <?php else: ?>
                            <input type="checkbox" name="dup_remove[]" value="<?php echo (int)$it['id']; ?>" class="batch-cb" checked>
                        <?php endif; ?>
                    </label>
                    <div class="item-details">
                        <?php if ($_af !== ''): ?>
                        <img src="<?php echo htmlspecialchars($_src); ?>" class="archive-thumb" alt="">
                        <?php endif; ?>
                        <div class="item-text">
                            <strong><?php echo htmlspecialchars($it['img_title'] ?: '(untitled)'); ?></strong>
                            <code class="slug-display">/<?php echo htmlspecialchars($it['img_slug'] ?? ''); ?></code>
                            <div class="item-meta">
                                <?php echo date("M j, Y - H:i", strtotime((string)$it['img_date'])); ?>
                                <span class="meta-reg">[ CATS: <?php echo (int)$it['cat_n']; ?> ]</span>
                                <span class="meta-mission">[ ALBUMS: <?php echo (int)$it['alb_n']; ?> ]</span>
                                <?php if (trim((string)$it['img_description']) !== ''): ?>
                                <span class="meta-collection">[ HAS CAPTION ]</span>
                                <?php endif; ?>
                                <a href="smack-edit.php?id=<?php echo (int)$it['id']; ?>" class="action-edit" target="_blank" rel="noopener">EDIT</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <div class="dup-reauth">
                <input type="password" name="reauth_password" placeholder="Password"
                       autocomplete="current-password" required>
                <input type="text" name="reauth_totp" placeholder="2FA code" inputmode="numeric"
                       autocomplete="one-time-code" maxlength="10" required>
                <button type="submit" class="btn-smack btn-danger">Remove Ticked Duplicates</button>
                <a href="smack-manage.php" class="btn-reset">Cancel</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="box box--no-header">
        <form method="GET" class="manage-filter-bar">
            <div class="filter-col-main">
                <div class="lens-input-wrapper">
                    <label>SEARCH</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Keywords...">
                </div>
            </div>

            <div class="filter-col-secondary">
                <div class="lens-input-wrapper">
                    <label>STATUS</label>
                    <select name="status">
                        <option value="">ALL POSTS</option>
                        <option value="live" <?php echo ($status_filter == 'live') ? 'selected' : ''; ?>>LIVE</option>
                        <option value="scheduled" <?php echo ($status_filter == 'scheduled') ? 'selected' : ''; ?>>SCHEDULED</option>
                        <option value="draft" <?php echo ($status_filter == 'draft') ? 'selected' : ''; ?>>DRAFT</option>
                    </select>
                </div>

                <?php if (!$is_post_mode): /* NEEDS WORK + ORIENTATION are image-only filters */ ?>
                <div class="lens-input-wrapper">
                    <label>NEEDS WORK</label>
                    <select name="needs">
                        <option value="">ANYTHING</option>
                        <option value="fubar"      <?php echo ($needs_filter == 'fubar')      ? 'selected' : ''; ?>>THE FUCKED-UP STUFF</option>
                        <option value="any"        <?php echo ($needs_filter == 'any')        ? 'selected' : ''; ?>>MISSING TITLE, CAPTION OR TAGS</option>
                        <option value="title"      <?php echo ($needs_filter == 'title')      ? 'selected' : ''; ?>>NO TITLE</option>
                        <option value="caption"    <?php echo ($needs_filter == 'caption')    ? 'selected' : ''; ?>>NO CAPTION</option>
                        <option value="tags"       <?php echo ($needs_filter == 'tags')       ? 'selected' : ''; ?>>NO HASHTAGS</option>
                        <option value="autoorient" <?php echo ($needs_filter == 'autoorient') ? 'selected' : ''; ?>>AUTO-ROTATED (CHECK ORIENTATION)</option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>ORIENTATION</label>
                    <select name="orient">
                        <option value="">ALL SHAPES</option>
                        <option value="landscape" <?php echo ($orient_filter == 'landscape') ? 'selected' : ''; ?>>LANDSCAPE</option>
                        <option value="portrait"  <?php echo ($orient_filter == 'portrait')  ? 'selected' : ''; ?>>PORTRAIT</option>
                        <option value="square"    <?php echo ($orient_filter == 'square')    ? 'selected' : ''; ?>>SQUARE</option>
                    </select>
                </div>
                <?php endif; ?>

                <div class="lens-input-wrapper">
                    <label>CATEGORY</label>
                    <select name="cat_id">
                        <option value="">ALL CATEGORIES</option>
                        <?php foreach($cats as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($cat_filter == $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['cat_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>MISSION (ALBUM)</label>
                    <select name="album_id">
                        <option value="">ALL ALBUMS</option>
                        <?php foreach($albums as $a): ?>
                            <option value="<?php echo $a['id']; ?>" <?php echo ($album_filter == $a['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['album_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!empty($collections)): ?>
                <div class="lens-input-wrapper">
                    <label>COLLECTION</label>
                    <select name="collection_id">
                        <option value="">ALL COLLECTIONS</option>
                        <?php foreach($collections as $col): ?>
                            <option value="<?php echo $col['id']; ?>" <?php echo ($collection_filter == $col['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($col['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <div class="filter-actions-group">
                <button type="submit" class="btn-smack-filter">FILTER</button>
                <a href="smack-manage.php" class="btn-reset">RESET</a>
            </div>
        </form>
    </div>

    <div class="box">
        <?php if (!$is_post_mode && $filters_active): ?>
            <p class="dim manage-reorder-note">Clear filters to enable drag reordering.</p>
        <?php endif; ?>

        <?php if (empty($post_list)): ?>
            <p class="dim text-center empty-notice">No archive entries match these criteria.</p>
        <?php else: ?>

            <form method="POST" id="batch-form" onsubmit="return confirmBatchDelete()">
                <input type="hidden" name="action" value="batch_delete">
                <?php if ($is_post_mode): ?><input type="hidden" name="del_mode" value="post"><?php endif; ?>

                <!-- Batch action bar — visible only when items are checked -->
                <div class="batch-bar" id="batch-bar">
                    <label class="batch-select-all-label">
                        <input type="checkbox" id="select-all-cb"> SELECT ALL
                    </label>
                    <span class="batch-count-label" id="batch-count-label">0 selected</span>
                    <button type="submit" class="btn-smack batch-delete-btn" id="batch-delete-btn" disabled>DELETE SELECTED</button>
                </div>

                <div id="sortable-list" class="<?php echo $reorder_enabled ? '' : 'reorder-disabled'; ?>">
                <?php foreach ($post_list as $p):
                    $is_draft = ($p['img_status'] === 'draft');
                    $is_scheduled = ($p['img_status'] === 'published' && strtotime($p['img_date']) > time());
                ?>
                    <div class="recent-item" data-id="<?php echo $p['id']; ?>">
                        <label class="batch-check-wrap">
                            <input type="checkbox" name="ids[]" value="<?php echo $p['id']; ?>" class="batch-cb">
                        </label>

                        <?php if ($reorder_enabled): ?>
                        <div class="drag-handle" title="Drag to reorder">⠿</div>
                        <?php endif; ?>

                        <div class="item-details">
                            <?php if (!empty($p['img_file'])):
                                $_af  = $p['img_file'];
                                $_api = pathinfo($_af);
                                $_tpath = $_api['dirname'] . '/thumbs/t_' . $_api['basename'];
                                $_src = file_exists($_tpath)
                                    ? '/' . ltrim($_tpath, '/')
                                    : '/' . ltrim($_af, '/');
                            ?>
                            <img src="<?php echo htmlspecialchars($_src); ?>" class="archive-thumb">
                            <?php else: ?>
                            <div class="archive-thumb archive-thumb--text" title="Text post — no cover image"
                                 style="display:flex;align-items:center;justify-content:center;font-size:1.4rem;opacity:0.55;">✎</div>
                            <?php endif; ?>

                            <div class="item-text">
                                <strong>
                                    <?php echo htmlspecialchars($p['img_title']); ?>
                                    <?php if ($is_draft): ?> <span class="badge-draft">DRAFT</span><?php endif; ?>
                                    <?php if ($is_scheduled): ?> <span class="badge-scheduled">SCHEDULED</span><?php endif; ?>
                                </strong>

                                <code class="slug-display">/<?php echo htmlspecialchars($p['img_slug'] ?? 'no-slug'); ?></code>

                                <div class="item-meta">
                                    <?php echo date("M j, Y - H:i", strtotime($p['img_date'])); ?>
                                    <span class="meta-reg">[ REG: <?php echo htmlspecialchars($p['category_list'] ?: 'NONE'); ?> ]</span>
                                    <span class="meta-mission">[ MISSION: <?php echo htmlspecialchars($p['album_list'] ?: 'NONE'); ?> ]</span>
                                    <?php if (!empty($p['collection_list'])): ?>
                                    <span class="meta-collection">[ COLLECTION: <?php echo htmlspecialchars($p['collection_list']); ?> ]</span>
                                    <?php endif; ?>
                                    <span class="meta-trans">[ TRANS: <?php echo (int)$p['comment_count']; ?> ]</span>
                                    <span class="meta-likes">[ LIKES: <?php echo (int)$p['like_count']; ?> ]</span>
                                    <span class="meta-downloads">[ DL: <?php echo (int)$p['img_download_count']; ?> ]</span>
                                </div>
                            </div>
                        </div>

                        <div class="item-actions">
                            <?php
                                // EDIT routes to the right editor for the mode:
                                //  photoblog → single-image editor (keyed by image id)
                                //  carousel  → same editor, dispatched by the cover image id
                                //  smacktalk → the longform composer (keyed by post id)
                                if ($mng_site_mode === 'smacktalk') {
                                    $edit_href = 'smack-post-long.php?edit=' . (int)$p['id'];
                                } elseif ($mng_site_mode === 'carousel') {
                                    $edit_href = 'smack-edit.php?id=' . (int)($p['cover_img_id'] ?? 0);
                                } else {
                                    $edit_href = 'smack-edit.php?id=' . (int)$p['id'];
                                }
                                $del_key = $is_post_mode ? 'delete_post' : 'delete';
                                $del_noun = $is_post_mode ? 'post' : 'transmission';
                            ?>
                            <a href="<?php echo htmlspecialchars($edit_href, ENT_QUOTES); ?>" class="action-edit">EDIT</a>
                            <?php if (!$is_post_mode): ?>
                            <a href="smack-swap.php?id=<?php echo $p['id']; ?>" class="action-swap">SWAP</a>
                            <?php endif; ?>
                            <?php if (!$is_draft && !$is_scheduled): ?>
                            <a href="<?php echo BASE_URL . htmlspecialchars($p['img_slug'] ?? '', ENT_QUOTES); ?>" class="action-view" target="_blank" rel="noopener">VIEW</a>
                            <?php endif; ?>
                            <a href="?<?php echo $del_key; ?>=<?php echo $p['id']; ?>&t=<?php echo urlencode(csrf_token()); ?>" class="action-delete" onclick="return confirm('PERMANENTLY PURGE this <?php echo $del_noun; ?>?')">DELETE</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </form>

            <div id="reorder-status" class="reorder-status" style="display:none;"></div>

            <?php if ($total_pages > 1):
                $qs = http_build_query(array_filter([
                    'search'        => $search,
                    'cat_id'        => $cat_filter,
                    'album_id'      => $album_filter,
                    'collection_id' => $collection_filter,
                    'status'        => $status_filter,
                    'needs'         => $needs_filter,
                    'orient'        => $orient_filter,
                ], 'strlen'));
                $href = function($p) use ($qs) {
                    return '?page=' . $p . ($qs ? '&' . $qs : '');
                };
                // Window: show first, last, and a range around current page
                $wing = 3;
                $range_start = max(1, $page - $wing);
                $range_end   = min($total_pages, $page + $wing);
            ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $href($page - 1); ?>">&laquo; Prev</a>
                    <?php endif; ?>

                    <?php if ($range_start > 1): ?>
                        <a href="<?php echo $href(1); ?>">1</a>
                        <?php if ($range_start > 2): ?><span class="pagination-ellipsis">&hellip;</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $range_start; $i <= $range_end; $i++): ?>
                        <a href="<?php echo $href($i); ?>"
                           class="<?php echo ($page == $i) ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($range_end < $total_pages): ?>
                        <?php if ($range_end < $total_pages - 1): ?><span class="pagination-ellipsis">&hellip;</span><?php endif; ?>
                        <a href="<?php echo $href($total_pages); ?>"><?php echo $total_pages; ?></a>
                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?php echo $href($page + 1); ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($post_list)): ?>
<script>
// ── Batch select ─────────────────────────────────────────────────────────
(function () {
    var selectAll = document.getElementById('select-all-cb');
    var countLabel = document.getElementById('batch-count-label');
    var deleteBtn = document.getElementById('batch-delete-btn');
    var batchBar  = document.getElementById('batch-bar');

    function updateBar() {
        var checked = document.querySelectorAll('.batch-cb:checked');
        var n = checked.length;
        countLabel.textContent = n + ' selected';
        deleteBtn.disabled = (n === 0);
        batchBar.classList.toggle('batch-bar--active', n > 0);
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.batch-cb').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            updateBar();
        });
    }

    document.querySelectorAll('.batch-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var all = document.querySelectorAll('.batch-cb');
            var checked = document.querySelectorAll('.batch-cb:checked');
            if (selectAll) selectAll.indeterminate = (checked.length > 0 && checked.length < all.length);
            if (selectAll) selectAll.checked = (checked.length === all.length);
            updateBar();
        });
    });
})();

function confirmBatchDelete() {
    var n = document.querySelectorAll('.batch-cb:checked').length;
    if (n === 0) return false;
    return confirm('PERMANENTLY PURGE ' + n + ' transmission' + (n !== 1 ? 's' : '') + '? This cannot be undone.');
}
</script>
<?php endif; ?>

<?php if ($reorder_enabled && !empty($post_list)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
(function () {
    var list     = document.getElementById('sortable-list');
    var statusEl = document.getElementById('reorder-status');

    if (!list) return;

    Sortable.create(list, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: function () {
            var ids = Array.from(list.querySelectorAll('.recent-item')).map(function (el) {
                return el.dataset.id;
            });
            saveOrder(ids);
        }
    });

    function saveOrder(ids) {
        statusEl.textContent = 'Saving order…';
        statusEl.className = 'reorder-status saving';
        statusEl.style.display = 'block';

        var body = 'action=reorder';
        ids.forEach(function (id) { body += '&ids[]=' + id; });

        fetch('smack-manage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                statusEl.textContent = 'Order saved.';
                statusEl.className = 'reorder-status saved';
                setTimeout(function () { statusEl.style.display = 'none'; }, 2000);
            } else {
                statusEl.textContent = 'Save failed: ' + (data.error || 'unknown error');
                statusEl.className = 'reorder-status error';
            }
        })
        .catch(function () {
            statusEl.textContent = 'Save failed.';
            statusEl.className = 'reorder-status error';
        });
    }
})();
</script>
<?php endif; ?>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
