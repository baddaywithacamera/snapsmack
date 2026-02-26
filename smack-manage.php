<?php
/**
 * SNAPSMACK - Manage Archive.
 * Primary library dashboard. Handles complex filtering, searching, 
 * and paginated display of all registered transmissions.
 * Executes the complete delete protocol for data and asset removal.
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

// --- 1. THE DELETE PROTOCOL ---
// Completely purges a post, its primary media file, and all relational data.
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ?");
    $stmt->execute([$id]);
    $img = $stmt->fetch();
    
    // Remove the primary physical file from the server.
    if ($img && file_exists($img['img_file'])) { 
        unlink($img['img_file']); 
    }
    
    // Cascading removal of DB records.
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

// Build dynamic WHERE clauses based on active filters.
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

if ($status_filter === 'draft') { 
    $where_clauses[] = "i.img_status = 'draft'"; 
} elseif ($status_filter === 'scheduled') { 
    $where_clauses[] = "i.img_status = 'published' AND i.img_date > NOW()"; 
} elseif ($status_filter === 'live') { 
    $where_clauses[] = "i.img_status = 'published' AND i.img_date <= NOW()"; 
}

$where_sql = $where_clauses ? " WHERE " . implode(" AND ", $where_clauses) : "";

// --- 4. DATA ACQUISITION ---
// Calculate total records for pagination limits.
$count_stmt = $pdo->prepare("SELECT COUNT(i.id) FROM snap_images i $where_sql");
$count_stmt->execute($params);
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $per_page);

// Fetch the current page of posts, injecting taxonomy lists and comment counts via subqueries.
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

// Load full taxonomy lists for the filter dropdowns.
$cats = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

$page_title = "Manage Archive";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>MANAGE ARCHIVE (<?php echo $total_rows; ?>)</h2>
    </div>

    <div class="box">
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
        <?php if (empty($post_list)): ?>
            <p class="dim text-center empty-notice">No archive entries match these criteria.</p>
        <?php else: ?>
            <?php foreach ($post_list as $p): 
                // Determine display badges based on status and timestamp.
                $is_draft = ($p['img_status'] === 'draft');
                $is_scheduled = ($p['img_status'] === 'published' && strtotime($p['img_date']) > time());
            ?>
                <div class="recent-item">
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

<?php include 'core/admin-footer.php'; ?>