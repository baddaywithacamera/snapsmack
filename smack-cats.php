<?php
/**
 * SnapSmack - Category Registry
 * Version: 16.30 - Universal Action Row Sync + Divider Purge
 */
require_once 'core/auth.php';

$msg = "";
$edit_mode = false;
$edit_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['cat_name']);
    $desc = trim($_POST['cat_description']);
    if (isset($_POST['new_cat']) && !empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO snap_categories (cat_name, cat_description) VALUES (?, ?)");
        $stmt->execute([$name, $desc]);
        header("Location: smack-cats.php?msg=REGISTRY+INITIALIZED");
        exit;
    }
    if (isset($_POST['update_cat']) && !empty($name)) {
        $id = (int)$_POST['cat_id'];
        $stmt = $pdo->prepare("UPDATE snap_categories SET cat_name = ?, cat_description = ? WHERE id = ?");
        $stmt->execute([$name, $desc, $id]);
        header("Location: smack-cats.php?msg=REGISTRY+MODIFIED");
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE cat_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_categories WHERE id = ?")->execute([$id]);
    header("Location: smack-cats.php?msg=PURGED");
    exit;
}

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM snap_categories WHERE id = ?");
    $stmt->execute([$id]);
    $edit_data = $stmt->fetch();
    if ($edit_data) { $edit_mode = true; }
}

$cats = $pdo->query("SELECT c.*, COUNT(m.image_id) as img_count FROM snap_categories c LEFT JOIN snap_image_cat_map m ON c.id = m.cat_id GROUP BY c.id ORDER BY c.cat_name ASC")->fetchAll();

$page_title = "CATEGORY REGISTRY";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>CATEGORY REGISTRY</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="post-layout-grid">
            <div class="post-col-left">
                <div class="box">
                    <h3><?php echo $edit_mode ? "MODIFY IDENTITY" : "INITIALIZE CATEGORY"; ?></h3>
                    
                    <input type="hidden" name="<?php echo $edit_mode ? 'update_cat' : 'new_cat'; ?>" value="1">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="cat_id" value="<?php echo $edit_data['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="lens-input-wrapper">
                        <label>CATEGORY NAME</label>
                        <input type="text" name="cat_name" value="<?php echo $edit_mode ? htmlspecialchars($edit_data['cat_name']) : ''; ?>" placeholder="E.G. NOIR, STREET" required autofocus>
                    </div>
                    
                    <div class="lens-input-wrapper">
                        <label>MISSION STATEMENT (DESCRIPTION)</label>
                        <textarea name="cat_description" placeholder="Technical or artistic intent..." rows="8"><?php echo $edit_mode ? htmlspecialchars($edit_data['cat_description'] ?? '') : ''; ?></textarea>
                    </div>

                    <?php if ($edit_mode): ?>
                        <div class="lens-input-wrapper" style="margin-top: 20px;">
                            <a href="smack-cats.php" class="btn-reset" style="display:block; text-align:center; text-decoration:none; padding:15px; border-radius:4px;">CANCEL EDIT</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex-1">
                <div class="box">
                    <h3>ACTIVE REGISTRY</h3>
                    
                    <?php if (empty($cats)): ?>
                        <p class="dim" style="padding:20px;">No categories registered.</p>
                    <?php else: ?>
                        <?php foreach ($cats as $c): ?>
                            <div class="recent-item">
                                <div class="item-details">
                                    <div class="item-text">
                                        <strong>
                                            <?php echo htmlspecialchars($c['cat_name']); ?>
                                        </strong>
                                        <code class="slug-display">SIGNALS: <?php echo (int)$c['img_count']; ?></code>
                                        <div class="item-meta">
                                            <?php echo !empty($c['cat_description']) ? htmlspecialchars($c['cat_description']) : "NO MISSION STATEMENT RECORDED."; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="item-actions">
                                    <a href="?edit=<?php echo $c['id']; ?>" class="action-edit">EDIT</a>
                                    <a href="?delete=<?php echo $c['id']; ?>" class="action-delete" onclick="return confirm('PURGE IDENTITY?')">DELETE</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-action-row">
            <button type="submit" class="master-update-btn">
                <?php echo $edit_mode ? "UPDATE REGISTRY" : "ADD TO REGISTRY"; ?>
            </button>
        </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>