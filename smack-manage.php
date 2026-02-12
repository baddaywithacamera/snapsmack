<?php
/**
 * SnapSmack - Manage Archive
 * Version: 4.1 - Universal Integration & Mission Awareness
 * MASTER DIRECTIVE: Full file return. 
 */
require_once 'core/auth.php';

// --- 1. THE DELETE PROTOCOL ---
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    $stmt = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ?");
    $stmt->execute([$id]);
    $img = $stmt->fetch();
    
    if ($img && file_exists($img['img_file'])) {
        unlink($img['img_file']); 
    }
    
    $pdo->prepare("DELETE FROM snap_images WHERE id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$id]);

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
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
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

// --- 4. DATA FETCHING ---
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
         WHERE am.image_id = i.id) as album_list
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
        <form method="GET" class="meta-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr auto; align-items: end; gap: 15px;">
            
            <div class="lens-input-wrapper">
                <label>Technical Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Keywords...">
            </div>
            
            <div class="lens-input-wrapper">
                <label>Status</label>
                <select name="status">
                    <option value="">All Posts</option>
                    <option value="live" <?php if($status_filter == 'live') echo 'selected'; ?>>Live Only</option>
                    <option value="scheduled" <?php if($status_filter == 'scheduled') echo 'selected'; ?>>Scheduled</option>
                    <option value="draft" <?php if($status_filter == 'draft') echo 'selected'; ?>>Drafts</option>
                </select>
            </div>

            <div class="lens-input-wrapper">
                <label>Registry (Cat)</label>
                <select name="cat_id">
                    <option value="">All Categories</option>
                    <?php foreach($cats as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php if($cat_filter == $c['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($c['cat_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="lens-input-wrapper">
                <label>Mission (Album)</label>
                <select name="album_id">
                    <option value="">All Albums</option>
                    <?php foreach($albums as $a): ?>
                        <option value="<?php echo $a['id']; ?>" <?php if($album_filter == $a['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($a['album_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions" style="display:flex; gap:10px;">
                <button type="submit">FILTER</button>
                <a href="smack-manage.php" class="btn-clear" style="display:inline-flex; align-items:center; justify-content:center; text-decoration:none; padding: 0 20px; height: 52px; border-radius: 4px;">RESET</a>
            </div>
        </form>
    </div>

    <div class="box">
        <?php if (empty($post_list)): ?>
            <p class="dim" style="text-align:center; padding:40px;">No archive entries match these criteria.</p>
        <?php else: ?>

            <?php foreach ($post_list as $p): ?>
                <?php 
                    $is_draft = ($p['img_status'] === 'draft');
                    $is_scheduled = ($p['img_status'] === 'published' && strtotime($p['img_date']) > time());
                    
                    $badge_style = "";
                    $badge_text = "";
                    $item_border = "";
                    
                    if ($is_draft) {
                        $badge_style = "background: #ffaa00; color: #000;";
                        $badge_text = "DRAFT";
                        $item_border = "border-left: 4px solid #ffaa00;";
                    } elseif ($is_scheduled) {
                        $badge_style = "background: #00E5FF; color: #000;";
                        $badge_text = "SCHEDULED";
                        $item_border = "border-left: 4px solid #00E5FF;";
                    }
                ?>
                <div class="recent-item" style="<?php echo $item_border; ?>">
                    
                    <div class="item-details">
                        <img src="<?php echo $p['img_file']; ?>" alt="Thumb" class="archive-thumb">
                        
                        <div class="item-text">
                            <strong class="item-title">
                                <?php if ($badge_text): ?>
                                    <span style="font-size: 0.65rem; padding: 2px 6px; border-radius: 3px; vertical-align: middle; margin-right: 8px; font-weight: 900; <?php echo $badge_style; ?>">
                                        <?php echo $badge_text; ?>
                                    </span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($p['img_title']); ?>
                            </strong>
                            
                            <code class="item-slug" style="color: #444; font-size: 0.75rem;">/<?php echo htmlspecialchars($p['img_slug'] ?? 'no-slug'); ?></code>

                            <span class="item-meta dim" style="font-size: 0.75rem; margin-top: 5px; display: block;">
                                <?php echo date("M j, Y - H:i", strtotime($p['img_date'])); ?> 
                                <span style="color:#39FF14; margin-left:10px;">
                                    [ REG: <?php echo htmlspecialchars($p['category_list'] ?: 'NONE'); ?> ]
                                </span>
                                <span style="color:#00E5FF; margin-left:10px;">
                                    [ MISSION: <?php echo htmlspecialchars($p['album_list'] ?: 'NONE'); ?> ]
                                </span>
                            </span>
                        </div>
                    </div>
                    
                    <div class="item-actions">
                        <a href="smack-edit.php?id=<?php echo $p['id']; ?>" class="action-edit">EDIT</a>
                        <a href="smack-swap.php?id=<?php echo $p['id']; ?>" class="action-swap">SWAP</a>
                        <a href="?delete=<?php echo $p['id']; ?>" class="action-delete" onclick="return confirm('PERMANENTLY PURGE this file?');">DELETE</a>
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