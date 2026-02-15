<?php
/**
 * SnapSmack - Manage Archive
 * Version: 4.3 - Trinity Sync (Logic v4.2)
 * -------------------------------------------------------------------------
 * LOGIC: Full Search, Filter, and Delete Protocol restored from Sean's v4.2.
 * UI: Trinity Compliant. All inline styling moved to CSS infrastructure.
 */
require_once 'core/auth.php';

// --- 1. THE DELETE PROTOCOL ---
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ?");
    $stmt->execute([$id]);
    $img = $stmt->fetch();
    
    if ($img && file_exists($img['img_file'])) { unlink($img['img_file']); }
    
    $pdo->prepare("DELETE FROM snap_images WHERE id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_comments WHERE img_id = ?")->execute([$id]);

    header("Location: smack-manage.php?msg=deleted");
    exit;
}

// --- 2. SEARCH & FILTER PREPARATION ---
$search = $_GET['search'] ?? '';
$cat_filter = $_GET['cat_id'] ?? '';
$album_filter = $_GET['album_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

// --- 3. PAGINATION MATH ---
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

if ($status_filter === 'draft') { $where_clauses[] = "i.img_status = 'draft'"; } 
elseif ($status_filter === 'scheduled') { $where_clauses[] = "i.img_status = 'published' AND i.img_date > NOW()"; } 
elseif ($status_filter === 'live') { $where_clauses[] = "i.img_status = 'published' AND i.img_date <= NOW()"; }

$where_sql = $where_clauses ? " WHERE " . implode(" AND ", $where_clauses) : "";

// --- 4. DATA ACQUISITION ---
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
        (SELECT COUNT(*) FROM snap_comments WHERE img_id = i.id) as comment_count
        FROM snap_images i
        $where_sql
        ORDER BY i.img_date DESC
        LIMIT $per_page OFFSET $offset";

$posts = $pdo->prepare($sql);
$posts->execute($params);
$post_list = $posts->fetchAll();

$cats = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

$page_title = "Manage Archive";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>Manage Archive (<?php echo $total_rows; ?>)</h2>

    <div class="box">
        <form method="GET" class="dash-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr auto; align-items: end;">
            <div class="input-wrap">
                <label>Technical Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Keywords...">
            </div>
            <div class="input-wrap">
                <label>Status</label>
                <select name="status">
                    <option value="">All Posts</option>
                    <option value="live" <?php echo ($status_filter == 'live') ? 'selected' : ''; ?>>Live Only</option>
                    <option value="scheduled" <?php echo ($status_filter == 'scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="draft" <?php echo ($status_filter == 'draft') ? 'selected' : ''; ?>>Drafts</option>
                </select>
            </div>
            <div class="input-wrap">
                <label>Registry (Cat)</label>
                <select name="cat_id">
                    <option value="">All Categories</option>
                    <?php foreach($cats as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($cat_filter == $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['cat_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-wrap">
                <label>Mission (Album)</label>
                <select name="album_id">
                    <option value="">All Albums</option>
                    <?php foreach($albums as $a): ?>
                        <option value="<?php echo $a['id']; ?>" <?php echo ($album_filter == $a['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['album_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions" style="display:flex; gap:10px;">
                <button type="submit" class="btn-smack">FILTER</button>
                <a href="smack-manage.php" class="btn-secondary btn-link">RESET</a>
            </div>
        </form>
    </div>

    <div class="box">
        <?php if (empty($post_list)): ?>
            <p class="dim text-center" style="padding:40px;">No archive entries match these criteria.</p>
        <?php else: ?>
            <?php foreach ($post_list as $p): 
                $is_draft = ($p['img_status'] === 'draft');
                $is_scheduled = ($p['img_status'] === 'published' && strtotime($p['img_date']) > time());
                $is_muted = (($p['allow_comments'] ?? 1) == 0);
                
                $status_class = "";
                if ($is_draft) { $status_class = "status-draft"; } 
                elseif ($is_scheduled) { $status_class = "status-scheduled"; }
            ?>
                <div class="recent-item <?php echo $status_class; ?>">
                    <div class="item-details">
                        <img src="/<?php echo ltrim($p['img_file'], '/'); ?>" class="archive-thumb">
                        <div class="item-text">
                            <strong class="item-title">
                                <?php if ($is_draft): ?><span class="badge badge-draft">DRAFT</span><?php endif; ?>
                                <?php if ($is_scheduled): ?><span class="badge badge-scheduled">SCHEDULED</span><?php endif; ?>
                                <?php if ($is_muted): ?><span class="badge badge-muted">MUTED</span><?php endif; ?>
                                <?php echo htmlspecialchars($p['img_title']); ?>
                            </strong>
                            <code class="item-slug">/<?php echo htmlspecialchars($p['img_slug'] ?? 'no-slug'); ?></code>
                            <span class="item-meta dim">
                                <?php echo date("M j, Y - H:i", strtotime($p['img_date'])); ?> 
                                <span class="highlight-green">[ REG: <?php echo htmlspecialchars($p['category_list'] ?: 'NONE'); ?> ]</span>
                                <span class="highlight-scheduled">[ MISSION: <?php echo htmlspecialchars($p['album_list'] ?: 'NONE'); ?> ]</span>
                                <span class="highlight-draft">[ TRANS: <?php echo (int)$p['comment_count']; ?> ]</span>
                            </span>
                        </div>
                    </div>
                    <div class="item-actions">
                        <a href="smack-edit.php?id=<?php echo $p['id']; ?>" class="action-edit">[ EDIT ]</a>
                        <a href="smack-swap.php?id=<?php echo $p['id']; ?>" class="action-swap">[ SWAP ]</a>
                        <a href="?delete=<?php echo $p['id']; ?>" class="action-delete" onclick="return confirm('PERMANENTLY PURGE this file?');">[ DELETE ]</a>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($total_pages > 1): ?>
                <div class="pagination-wrap">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&cat_id=<?php echo $cat_filter; ?>&album_id=<?php echo $album_filter; ?>&status=<?php echo $status_filter; ?>" 
                           class="btn-secondary btn-compact <?php echo ($page == $i) ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>