<?php
/**
 * SNAPSMACK - Category (registry) management
 * Alpha v0.7.1
 *
 * Provides creation, editing, and deletion of photo categories.
 * Maintains associations between categories and their tagged images.
 */

require_once 'core/auth.php';

$msg = "";
$edit_mode = false;
$edit_data = [];

// --- FORM SUBMISSION HANDLER ---
// Processes creation and modification of category records.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['cat_name']);

    // Insert a new category.
    if (isset($_POST['new_cat']) && !empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO snap_categories (cat_name) VALUES (?)");
        $stmt->execute([$name]);
        header("Location: smack-cats.php?msg=REGISTRY+ENTRY+ADDED");
        exit;
    }

    // Update an existing category.
    if (isset($_POST['update_cat']) && !empty($name)) {
        $id = (int)$_POST['cat_id'];
        $stmt = $pdo->prepare("UPDATE snap_categories SET cat_name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);
        header("Location: smack-cats.php?msg=REGISTRY+ENTRY+MODIFIED");
        exit;
    }
}

// --- DELETION HANDLER ---
// Removes a category and all its image associations.
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Delete mappings first to preserve referential integrity.
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE cat_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_categories WHERE id = ?")->execute([$id]);
    header("Location: smack-cats.php?msg=REGISTRY+ENTRY+PURGED");
    exit;
}

// --- EDIT MODE ---
// Loads the category record for editing if edit parameter is present.
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM snap_categories WHERE id = ?");
    $stmt->execute([$id]);
    $edit_data = $stmt->fetch();
    if ($edit_data) { $edit_mode = true; }
}

// Load all categories with associated image counts.
$cats = $pdo->query("SELECT c.*, COUNT(m.image_id) as img_count FROM snap_categories c LEFT JOIN snap_image_cat_map m ON c.id = m.cat_id GROUP BY c.id ORDER BY c.cat_name ASC")->fetchAll();

$page_title = "Registry";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>REGISTRY (CATEGORIES)</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="post-layout-grid">
            <div class="post-col-left">
                <div class="box">
                    <h3><?php echo $edit_mode ? "MODIFY REGISTRY ENTRY" : "NEW REGISTRY ENTRY"; ?></h3>

                    <input type="hidden" name="<?php echo $edit_mode ? 'update_cat' : 'new_cat'; ?>" value="1">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="cat_id" value="<?php echo $edit_data['id']; ?>">
                    <?php endif; ?>

                    <div class="lens-input-wrapper">
                        <label>CATEGORY NAME</label>
                        <input type="text" name="cat_name" value="<?php echo $edit_mode ? htmlspecialchars($edit_data['cat_name']) : ''; ?>" placeholder="E.G. STREET, PORTRAITS, LANDSCAPE" required autofocus>
                    </div>

                    <?php if ($edit_mode): ?>
                        <div class="lens-input-wrapper mt-20">
                            <a href="smack-cats.php" class="btn-smack btn-block">CANCEL EDIT</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex-1">
                <div class="box">
                    <h3>ACTIVE REGISTRY</h3>

                    <?php if (empty($cats)): ?>
                        <p class="dim">No categories registered.</p>
                    <?php else: ?>
                        <?php foreach ($cats as $c): ?>
                            <div class="recent-item">
                                <div class="item-details">
                                    <div class="item-text">
                                        <strong><?php echo htmlspecialchars($c['cat_name']); ?></strong>
                                        <code class="slug-display">TRANSMISSIONS: <?php echo (int)$c['img_count']; ?></code>
                                    </div>
                                </div>

                                <div class="item-actions">
                                    <a href="?edit=<?php echo $c['id']; ?>" class="action-edit">EDIT</a>
                                    <a href="?delete=<?php echo $c['id']; ?>" class="action-delete" onclick="return confirm('PURGE CATEGORY? Images will be uncategorized but not deleted.')">DELETE</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-action-row">
            <button type="submit" class="master-update-btn">
                <?php echo $edit_mode ? "UPDATE CATEGORY" : "ADD TO REGISTRY"; ?>
            </button>
        </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>
