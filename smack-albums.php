<?php
/**
 * SNAPSMACK - Album management (Mission Registry).
 * Handles the creation, modification, and deletion of photo albums.
 * Manages the relationship between albums and images in the database.
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

$msg = "";
$edit_mode = false;
$edit_data = [];

// --- FORM SUBMISSION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['album_name']);
    $desc = trim($_POST['album_description']);
    
    // Create a new album entry in the registry.
    if (isset($_POST['new_album']) && !empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO snap_albums (album_name, album_description) VALUES (?, ?)");
        $stmt->execute([$name, $desc]);
        header("Location: smack-albums.php?msg=MISSION+INITIALIZED");
        exit;
    }
    
    // Update an existing album's metadata.
    if (isset($_POST['update_album']) && !empty($name)) {
        $id = (int)$_POST['album_id'];
        $stmt = $pdo->prepare("UPDATE snap_albums SET album_name = ?, album_description = ? WHERE id = ?");
        $stmt->execute([$name, $desc, $id]);
        header("Location: smack-albums.php?msg=MISSION+MODIFIED");
        exit;
    }
}

// --- DELETION HANDLER ---
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Remove image mappings first to ensure data integrity.
    $pdo->prepare("DELETE FROM snap_image_album_map WHERE album_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_albums WHERE id = ?")->execute([$id]);
    header("Location: smack-albums.php?msg=MISSION+PURGED");
    exit;
}

// --- EDIT MODE DETECTION ---
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM snap_albums WHERE id = ?");
    $stmt->execute([$id]);
    $edit_data = $stmt->fetch();
    if ($edit_data) { $edit_mode = true; }
}

// Load all registered albums and calculate the count of associated images for each.
$albums = $pdo->query("SELECT a.*, COUNT(m.image_id) as img_count FROM snap_albums a LEFT JOIN snap_image_album_map m ON a.id = m.album_id GROUP BY a.id ORDER BY a.album_name ASC")->fetchAll();

$page_title = "Mission registry";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>MISSION REGISTRY (ALBUMS)</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="post-layout-grid">
            <div class="post-col-left">
                <div class="box">
                    <h3><?php echo $edit_mode ? "MODIFY MISSION" : "INITIALIZE MISSION"; ?></h3>
                    
                    <input type="hidden" name="<?php echo $edit_mode ? 'update_album' : 'new_album'; ?>" value="1">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="album_id" value="<?php echo $edit_data['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="lens-input-wrapper">
                        <label>MISSION NAME</label>
                        <input type="text" name="album_name" value="<?php echo $edit_mode ? htmlspecialchars($edit_data['album_name']) : ''; ?>" placeholder="E.G. PROJECT 365, SUMMER 2025" required autofocus>
                    </div>
                    
                    <div class="lens-input-wrapper">
                        <label>MISSION BRIEFING (DESCRIPTION)</label>
                        <textarea name="album_description" placeholder="Technical or artistic intent..." rows="8"><?php echo $edit_mode ? htmlspecialchars($edit_data['album_description'] ?? '') : ''; ?></textarea>
                    </div>

                    <?php if ($edit_mode): ?>
                        <div class="lens-input-wrapper" style="margin-top: 20px;">
                            <a href="smack-albums.php" class="btn-reset" style="display:block; text-align:center; text-decoration:none; padding:15px; border-radius:4px;">CANCEL EDIT</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex-1">
                <div class="box">
                    <h3>ACTIVE MISSIONS</h3>
                    
                    <?php if (empty($albums)): ?>
                        <p class="dim" style="padding:20px;">No missions registered.</p>
                    <?php else: ?>
                        <?php foreach ($albums as $a): ?>
                            <div class="recent-item">
                                <div class="item-details">
                                    <div class="item-text">
                                        <strong>
                                            <?php echo htmlspecialchars($a['album_name']); ?>
                                        </strong>
                                        <code class="slug-display">SIGNALS: <?php echo (int)$a['img_count']; ?></code>
                                        <div class="item-meta">
                                            <?php echo !empty($a['album_description']) ? htmlspecialchars($a['album_description']) : "NO BRIEFING RECORDED."; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="item-actions">
                                    <a href="?edit=<?php echo $a['id']; ?>" class="action-edit">EDIT</a>
                                    <a href="?delete=<?php echo $a['id']; ?>" class="action-delete" onclick="return confirm('PURGE MISSION?')">DELETE</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-action-row">
            <button type="submit" class="master-update-btn">
                <?php echo $edit_mode ? "UPDATE MISSION" : "ADD TO REGISTRY"; ?>
            </button>
        </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>