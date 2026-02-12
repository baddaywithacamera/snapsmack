<?php
/**
 * SnapSmack - Category Management
 * Version: 3.1 - Clean Integration
 */
require_once 'core/auth.php';

$msg = "";

// 1. ADD CATEGORY LOGIC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_cat'])) {
    $name = trim($_POST['cat_name']);
    if (!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO snap_categories (cat_name) VALUES (?)");
        $stmt->execute([$name]);
        $msg = "Category '$name' initialized.";
    }
}

// 2. DELETE CATEGORY LOGIC
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM snap_categories WHERE id = ?")->execute([$id]);
    header("Location: smack-cats.php?msg=deleted");
    exit;
}

// 3. FETCH CATEGORIES WITH IMAGE COUNTS
$query = "SELECT c.*, COUNT(m.image_id) as img_count 
          FROM snap_categories c 
          LEFT JOIN snap_image_cat_map m ON c.id = m.cat_id 
          GROUP BY c.id 
          ORDER BY c.cat_name ASC";
$cats = $pdo->query($query)->fetchAll();

$page_title = "Manage Categories";

include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>Category Management</h2>
    
    <?php if($msg || isset($_GET['msg'])): ?>
        <div class="msg">
            > <?php echo $msg ?: "System Registry Updated."; ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 40px;">
        
        <div class="box">
            <h3>Add New Signal</h3>
            <form method="POST">
                <input type="hidden" name="new_cat" value="1">
                <label>Category Name</label>
                <input type="text" name="cat_name" placeholder="e.g. Street, 35mm, Noir" required autofocus>
                <button type="submit" style="margin-top: 20px; width: 100%;">ADD TO REGISTRY</button>
            </form>
        </div>

        <div class="box">
            <h3>Existing Registry</h3>
            <div class="cat-list">
                <?php foreach($cats as $c): ?>
                    <div class="cat-row" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #222;">
                        <div>
                            <span style="color: #eee; font-weight: 600;"><?php echo htmlspecialchars($c['cat_name']); ?></span>
                            <span class="dim" style="font-size: 0.75rem; margin-left: 10px;">(<?php echo $c['img_count']; ?> images)</span>
                        </div>
                        <a href="?delete=<?php echo $c['id']; ?>" 
                           onclick="return confirm('Archive this signal? This will unmap all photos from this category.');"
                           style="color: #ff3e3e; text-decoration: none; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">
                           [ DELETE ]
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<?php include 'core/admin-footer.php'; ?>