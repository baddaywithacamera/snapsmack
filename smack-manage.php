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

// --- BATCH DELETE HANDLER ---
// Routes each selected tile through the post-aware cascade so nothing is orphaned.
if (isset($_POST['action']) && $_POST['action'] === 'batch_delete') {
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    $deleted = 0;
    foreach ($ids as $id) {
        snap_manage_delete_by_image($pdo, $id);
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
    $all_ids = $pdo->query("SELECT id FROM snap_images ORDER BY sort_order ASC, img_date DESC")
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
    snap_manage_delete_by_image($pdo, (int)$_GET['delete']);
    header("Location: smack-manage.php?msg=deleted");
    exit;
}

// --- FILTER PARAMETERS ---
$search            = $_GET['search']        ?? '';
$cat_filter        = $_GET['cat_id']        ?? '';
$album_filter      = $_GET['album_id']      ?? '';
$collection_filter = $_GET['collection_id'] ?? '';
$status_filter     = $_GET['status']        ?? '';

// Drag reorder only available when showing all posts unfiltered.
$filters_active = ($search !== '' || $cat_filter !== '' || $album_filter !== '' || $collection_filter !== '' || $status_filter !== '');

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

$where_sql = $where_clauses ? " WHERE " . implode(" AND ", $where_clauses) : "";

// --- DATA RETRIEVAL ---
$count_stmt = $pdo->prepare("SELECT COUNT(i.id) FROM snap_images i $where_sql");
$count_stmt->execute($params);
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $per_page);

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
        ORDER BY i.id DESC
        LIMIT $per_page OFFSET $offset";

$posts = $pdo->prepare($sql);
$posts->execute($params);
$post_list = $posts->fetchAll();

// Site mode drives which post actions apply. In GramOfSmack (carousel) the
// standalone SWAP page is hidden — image replacement belongs in the carousel
// editor, not a single-image swap. Solo/smacktalk keep SWAP.
$mng_site_mode = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog';

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
    <div class="box" style="border:1px solid #e45735;">
        <h3 style="color:#e45735; margin-top:0;">
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

    <div class="box box--no-header">
        <form method="GET" class="manage-filter-bar">
            <div class="filter-col-main">
                <div class="lens-input-wrapper">
                    <label>TECHNICAL SEARCH</label>
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

                <div class="lens-input-wrapper">
                    <label>REGISTRY (CAT)</label>
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
        <?php if ($filters_active): ?>
            <p class="dim manage-reorder-note">Clear filters to enable drag reordering.</p>
        <?php endif; ?>

        <?php if (empty($post_list)): ?>
            <p class="dim text-center empty-notice">No archive entries match these criteria.</p>
        <?php else: ?>

            <form method="POST" id="batch-form" onsubmit="return confirmBatchDelete()">
                <input type="hidden" name="action" value="batch_delete">

                <!-- Batch action bar — visible only when items are checked -->
                <div class="batch-bar" id="batch-bar">
                    <label class="batch-select-all-label">
                        <input type="checkbox" id="select-all-cb"> SELECT ALL
                    </label>
                    <span class="batch-count-label" id="batch-count-label">0 selected</span>
                    <button type="submit" class="btn-smack batch-delete-btn" id="batch-delete-btn" disabled>DELETE SELECTED</button>
                </div>

                <div id="sortable-list" class="<?php echo $filters_active ? 'reorder-disabled' : ''; ?>">
                <?php foreach ($post_list as $p):
                    $is_draft = ($p['img_status'] === 'draft');
                    $is_scheduled = ($p['img_status'] === 'published' && strtotime($p['img_date']) > time());
                ?>
                    <div class="recent-item" data-id="<?php echo $p['id']; ?>">
                        <label class="batch-check-wrap">
                            <input type="checkbox" name="ids[]" value="<?php echo $p['id']; ?>" class="batch-cb">
                        </label>

                        <?php if (!$filters_active): ?>
                        <div class="drag-handle" title="Drag to reorder">⠿</div>
                        <?php endif; ?>

                        <div class="item-details">
                            <?php
                                $_af  = $p['img_file'];
                                $_api = pathinfo($_af);
                                $_tpath = $_api['dirname'] . '/thumbs/t_' . $_api['basename'];
                                $_src = file_exists($_tpath)
                                    ? '/' . ltrim($_tpath, '/')
                                    : '/' . ltrim($_af, '/');
                            ?>
                            <img src="<?php echo htmlspecialchars($_src); ?>" class="archive-thumb">

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
                            <a href="smack-edit.php?id=<?php echo $p['id']; ?>" class="action-edit">EDIT</a>
                            <?php if ($mng_site_mode !== 'carousel'): ?>
                            <a href="smack-swap.php?id=<?php echo $p['id']; ?>" class="action-swap">SWAP</a>
                            <?php endif; ?>
                            <?php if (!$is_draft && !$is_scheduled): ?>
                            <a href="<?php echo BASE_URL . htmlspecialchars($p['img_slug'] ?? '', ENT_QUOTES); ?>" class="action-view" target="_blank" rel="noopener">VIEW</a>
                            <?php endif; ?>
                            <a href="?delete=<?php echo $p['id']; ?>" class="action-delete" onclick="return confirm('PERMANENTLY PURGE this transmission?')">DELETE</a>
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

<?php if (!$filters_active && !empty($post_list)): ?>
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
