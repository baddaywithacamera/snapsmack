<?php
/**
 * SNAPSMACK - Blogroll manager
 *
 * Manages network of independent peers and external photographers.
 * Handles CRUD operations for blogroll entries with category organization.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth.php';

// --- FETCH CATEGORIES ---
// Load categories for dropdown menu and list grouping.
$categories = $pdo->query("SELECT * FROM snap_blogroll_cats ORDER BY cat_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- CATEGORY MANAGEMENT ---
// new_blogroll_cat / rename_blogroll_cat / delete_blogroll_cat
if (isset($_POST['new_blogroll_cat'])) {
    $cat_name = trim($_POST['cat_name'] ?? '');
    if ($cat_name !== '') {
        $pdo->prepare("INSERT INTO snap_blogroll_cats (cat_name) VALUES (?)")
            ->execute([$cat_name]);
    }
    header("Location: smack-blogroll.php?msg=cat_added");
    exit;
}
if (isset($_POST['rename_blogroll_cat'])) {
    $cat_id   = (int)($_POST['cat_id'] ?? 0);
    $cat_name = trim($_POST['cat_name'] ?? '');
    if ($cat_id > 0 && $cat_name !== '') {
        $pdo->prepare("UPDATE snap_blogroll_cats SET cat_name=? WHERE id=?")
            ->execute([$cat_name, $cat_id]);
    }
    header("Location: smack-blogroll.php?msg=cat_renamed");
    exit;
}
if (isset($_POST['delete_blogroll_cat'])) {
    $cat_id = (int)($_POST['cat_id'] ?? 0);
    if ($cat_id > 0) {
        // Reassign affected entries to uncategorized first.
        $pdo->prepare("UPDATE snap_blogroll SET cat_id=0 WHERE cat_id=?")
            ->execute([$cat_id]);
        $pdo->prepare("DELETE FROM snap_blogroll_cats WHERE id=?")
            ->execute([$cat_id]);
    }
    header("Location: smack-blogroll.php?msg=cat_deleted");
    exit;
}

// --- PERSISTENCE HANDLER ---
if (isset($_POST['save_peer'])) {
    $id     = $_POST['peer_id'] ?? null;
    $name   = trim($_POST['peer_name']);
    $url    = trim($_POST['peer_url']);
    $cat_id = (int)$_POST['cat_id'];
    $rss    = trim($_POST['peer_rss']);
    $desc   = trim($_POST['peer_desc']);

    if ($id) {
        $stmt = $pdo->prepare("UPDATE snap_blogroll SET peer_name=?, peer_url=?, cat_id=?, peer_rss=?, peer_desc=? WHERE id=?");
        $stmt->execute([$name, $url, $cat_id, $rss, $desc, $id]);
        $msg = "updated";
    } else {
        $stmt = $pdo->prepare("INSERT INTO snap_blogroll (peer_name, peer_url, cat_id, peer_rss, peer_desc) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $url, $cat_id, $rss, $desc]);
        $msg = "added";
    }
    header("Location: smack-blogroll.php?msg=" . $msg);
    exit;
}

// --- REMOVAL HANDLER ---
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM snap_blogroll WHERE id=?")->execute([$_GET['delete']]);
    header("Location: smack-blogroll.php?msg=deleted");
    exit;
}

// --- DATA ACQUISITION ---
$peers = $pdo->query(
    "SELECT b.*, c.cat_name
     FROM snap_blogroll b
     LEFT JOIN snap_blogroll_cats c ON b.cat_id = c.id
     ORDER BY c.cat_name ASC, b.peer_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$edit_peer = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM snap_blogroll WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $edit_peer = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title = "Blogroll";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>BLOGROLL</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; BLOGROLL SAVED</div>
    <?php endif; ?>

    <!-- MANAGE CATEGORIES -->
    <div class="box" style="margin-bottom:20px;">
        <h3>MANAGE CATEGORIES</h3>

        <?php if (!empty($categories)): ?>
            <?php foreach ($categories as $cat): ?>
                <div class="recent-item">
                    <form method="POST" class="blogroll-cat-row">
                        <input type="hidden" name="cat_id" value="<?php echo (int)$cat['id']; ?>">
                        <input type="text" name="cat_name"
                               value="<?php echo htmlspecialchars($cat['cat_name']); ?>"
                               class="blogroll-cat-input">
                        <button type="submit" name="rename_blogroll_cat" class="btn-secondary btn-sm">SAVE</button>
                        <button type="submit" name="delete_blogroll_cat" class="btn-secondary btn-sm btn-danger"
                                onclick="return confirm('Delete this category? Peers in it will be moved to UNCATEGORIZED.');">DELETE</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="dim empty-notice">No categories yet — add one below.</p>
        <?php endif; ?>

        <form method="POST" class="blogroll-cat-row blogroll-cat-row--new">
            <input type="text" name="cat_name" placeholder="New category name…" required class="blogroll-cat-input">
            <button type="submit" name="new_blogroll_cat" class="btn-smack btn-sm">+ ADD CATEGORY</button>
        </form>
    </div>

    <div class="post-layout-grid">
        <div class="post-col-left">
            <div class="box">
                <h3><?php echo $edit_peer ? 'EDIT ENDORSEMENT' : 'ADD INDEPENDENT PEER'; ?></h3>
                <form method="POST">
                    <?php if ($edit_peer): ?>
                        <input type="hidden" name="peer_id" value="<?php echo $edit_peer['id']; ?>">
                    <?php endif; ?>

                    <div class="lens-input-wrapper">
                        <label>Site / Photographer Name</label>
                        <input type="text" name="peer_name" required value="<?php echo htmlspecialchars($edit_peer['peer_name'] ?? ''); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>Direct URL</label>
                        <input type="url" name="peer_url" required value="<?php echo htmlspecialchars($edit_peer['peer_url'] ?? ''); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>Category</label>
                        <select name="cat_id">
                            <option value="0">-- UNCATEGORIZED --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo (($edit_peer['cat_id'] ?? 0) == $cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(strtoupper($cat['cat_name'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>RSS Feed URL (Optional)</label>
                        <input type="url" name="peer_rss" value="<?php echo htmlspecialchars($edit_peer['peer_rss'] ?? ''); ?>" placeholder="Used for tactical polling">
                    </div>

                    <div class="lens-input-wrapper lens-input-wrapper--grow">
                        <label>Editorial Description</label>
                        <textarea name="peer_desc"><?php echo htmlspecialchars($edit_peer['peer_desc'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-action-row">
                        <button type="submit" name="save_peer" class="master-update-btn">
                            <?php echo $edit_peer ? 'UPDATE PEER' : 'COMMIT TO NETWORK'; ?>
                        </button>
                    </div>
                </form>
                <?php if ($edit_peer): ?>
                    <a href="smack-blogroll.php" class="back-link">&larr; ABORT EDIT</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="post-col-right">
            <div class="box box-flush-bottom">
                <h3>THE NETWORK</h3>

                <?php if (!$peers): ?>
                    <div class="empty-notice dim">No links found. The network is offline.</div>
                <?php else: ?>
                    <?php
                    $current_cat = null;
                    foreach ($peers as $p):
                        if ($current_cat !== $p['cat_name']) {
                            if ($current_cat !== null) {
                                echo '<div class="section-divider"></div>';
                            }
                            $current_cat = $p['cat_name'];
                            echo '<h4 class="dim blogroll-cat-heading">' . htmlspecialchars($current_cat ?: 'UNCATEGORIZED') . '</h4>';
                        }
                    ?>
                        <div class="recent-item">
                            <div class="item-text">
                                <strong><?php echo htmlspecialchars($p['peer_name']); ?></strong>
                                <span class="dim item-meta"><?php echo htmlspecialchars($p['peer_url']); ?></span>
                            </div>
                            <div class="item-actions">
                                <a href="?edit=<?php echo $p['id']; ?>" class="action-edit">EDIT</a>
                                <a href="?delete=<?php echo $p['id']; ?>" class="action-delete" onclick="return confirm('Remove peer?');">DEL</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
