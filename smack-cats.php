<?php
/**
 * SnapSmack - Category Registry
 * Version: 3.2 - Logic v3.1 + Trinity Sync
 */
require_once 'core/auth.php';

$msg = "";

// --- 1. ENGINE: REGISTRY ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_cat'])) {
    $name = trim($_POST['cat_name']);
    if (!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO snap_categories (cat_name) VALUES (?)");
        $stmt->execute([$name]);
        $msg = "REGISTRY UPDATED: " . strtoupper($name);
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Protocol: Unmap mappings before deleting the container
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE cat_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_categories WHERE id = ?")->execute([$id]);
    header("Location: smack-cats.php?msg=deleted");
    exit;
}

// --- 2. DATA ACQUISITION ---
$query = "SELECT c.*, COUNT(m.image_id) as img_count 
          FROM snap_categories c 
          LEFT JOIN snap_image_cat_map m ON c.id = m.cat_id 
          GROUP BY c.id 
          ORDER BY c.cat_name ASC";
$cats = $pdo->query($query)->fetchAll();

$page_title = "CATEGORY REGISTRY";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>CATEGORY REGISTRY</h2>
    
    <?php if($msg || isset($_GET['msg'])): ?>
        <div class="msg">> <?php echo $msg ?: "SYSTEM REGISTRY UPDATED."; ?></div>
    <?php endif; ?>

    <div class="post-layout-grid">
        
        <div class="post-col-left">
            <div class="box">
                <h3>INITIALIZE CATEGORY</h3>
                <form method="POST">
                    <input type="hidden" name="new_cat" value="1">
                    <label>CATEGORY NAME</label>
                    <input type="text" name="cat_name" placeholder="E.G. NOIR, STREET, 35MM" required autofocus>
                    
                    <br>
                    <button type="submit" class="btn-smack btn-block">ADD TO REGISTRY</button>
                </form>
            </div>
        </div>

        <div class="post-col-right">
            <div class="box">
                <h3>ACTIVE REGISTRY</h3>
                <div class="smack-table-wrap">
                    <table class="smack-table">
                        <thead>
                            <tr>
                                <th>IDENTITY</th>
                                <th>COUNT</th>
                                <th class="text-right">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cats as $c): ?>
                            <tr>
                                <td class="uppercase bold"><?php echo htmlspecialchars($c['cat_name']); ?></td>
                                <td class="mono"><?php echo $c['img_count']; ?></td>
                                <td class="text-right">
                                    <a href="?delete=<?php echo $c['id']; ?>" 
                                       class="action-delete" 
                                       onclick="return confirm('Archive this signal? This will unmap all photos from this category.');">
                                        [ DELETE ]
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include 'core/admin-footer.php'; ?>