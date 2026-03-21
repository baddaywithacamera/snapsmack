<?php
/**
 * SNAPSMACK - Archive management dashboard
 * Alpha v0.7.5
 *
 * Provides searchable listing of all posts with filtering by status, category, and album.
 * Supports deletion of posts with cascading removal of associated data and files.
 * Supports drag-and-drop manual sort ordering via sort_order column.
 */

require_once 'core/auth.php';

// --- BATCH DELETE HANDLER ---
// Accepts an array of image IDs and purges each one along with its files and
// associated DB records. Redirects back with a count of deletions.
if (isset($_POST['action']) && $_POST['action'] === 'batch_delete') {
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    $deleted = 0;

    foreach ($ids as $id) {
        $stmt = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ?");
        $stmt->execute([$id]);
        $img = $stmt->fetch();

        if ($img && file_exists($img['img_file'])) {
            $pi = pathinfo($img['img_file']);
            $td = $pi['dirname'] . '/thumbs';
            @unlink($td . '/t_' . $pi['basename']);
            @unlink($td . '/a_' . $pi['basename']);
            unlink($img['img_file']);
        }

        $pdo->prepare("DELETE FROM snap_images WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM snap_comments WHERE img_id = ?")->execute([$id]);
        $deleted++;
    }

    header("Location: smack-manage.php?msg=batch_deleted&count=$deleted");
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
// Removes a post, its image files, and all associated database records.
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ?");
    $stmt->execute([$id]);
    $img = $stmt->fetch();

    // Delete the primary image file and its thumbnails from disk.
    if ($img && file_exists($img['img_file'])) {
        $pi = pathinfo($img['img_file']);
        $td = $pi['dirname'] . '/thumbs';
        @unlink($td . '/t_' . $pi['basename']);
        @unlink($td . '/a_' . $pi['basename']);
        unlink($img['img_file']);
    }

    // Remove database records in cascading order.
    $pdo->prepare("DELETE FROM snap_images WHERE id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_comments WHERE img_id = ?")->execute([$id]);

    header("Location: smack-manage.php?msg=deleted");
    exit;
}

// --- FILTER PARAMETERS ---
$search = $_GET['search'] ?? '';
$cat_filter = $_GET['cat_id'] ?? '';
$album_filter = $_GET['album_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Drag reorder only available when showing all posts unfiltered.
$filters_active = ($search !== '' || $cat_filter !== '' || $album_filter !== '' || $status_filter !== '');

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
        (SELECT COUNT(*) FROM snap_comments WHERE img_id = i.id) as comment_count,
        (SELECT COUNT(*) FROM snap_likes WHERE post_id = i.id) as like_count
        FROM snap_images i
        $where_sql
        ORDER BY i.sort_order ASC, i.img_date DESC
        LIMIT $per_page OFFSET $offset";

$posts = $pdo->prepare($sql);
$posts->execute($params);
$post_list = $posts->fetchAll();

$cats = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

$success_msg = '';
if (!empty($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') {
        $success_msg = 'Transmission purged.';
    } elseif ($_GET['msg'] === 'batch_deleted') {
        $n = (int)($_GET['count'] ?? 0);
        $success_msg = "$n transmission" . ($n !== 1 ? 's' : '') . " purged.";
    }
}

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
                            <img src="/<?php echo ltrim($p['img_file'], '/'); ?>" class="archive-thumb">

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
                                    <span class="meta-trans">[ TRANS: <?php echo (int)$p['comment_count']; ?> ]</span>
                                    <span class="meta-likes">[ LIKES: <?php echo (int)$p['like_count']; ?> ]</span>
                                    <span class="meta-downloads">[ DL: <?php echo (int)$p['img_download_count']; ?> ]</span>
                                </div>
                            </div>
                        </div>

                        <div class="item-actions">
                            <a href="smack-edit.php?id=<?php echo $p['id']; ?>" class="action-edit">EDIT</a>
                            <a href="smack-swap.php?id=<?php echo $p['id']; ?>" class="action-swap">SWAP</a>
                            <a href="?delete=<?php echo $p['id']; ?>" class="action-delete" onclick="return confirm('PERMANENTLY PURGE this transmission?')">DELETE</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </form>

            <div id="reorder-status" class="reorder-status" style="display:none;"></div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&cat_id=<?php echo $cat_filter; ?>&album_id=<?php echo $album_filter; ?>&status=<?php echo $status_filter; ?>"
                           class="<?php echo ($page == $i) ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
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
