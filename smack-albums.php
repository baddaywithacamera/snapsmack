<?php
/**
 * SNAPSMACK - Album Registry
 * Version: 2.8 - Edit Functionality Added
 * - ADDED: Update logic handling.
 * - ADDED: Edit mode state retrieval.
 * - MODIFIED: Form dynamically switches between CREATE and UPDATE modes.
 */
require_once 'core/auth.php';

$msg = "";
$edit_mode = false;
$edit_data = [];

// 1. HANDLE REQUESTS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // A. CREATE NEW ALBUM
    if (isset($_POST['new_album'])) {
        $name = trim($_POST['album_name']);
        $desc = trim($_POST['album_description']);
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO snap_albums (album_name, album_desc) VALUES (?, ?)");
            $stmt->execute([$name, $desc]);
            $msg = "ALBUM '$name' INITIALIZED IN REGISTRY.";
        }
    }

    // B. UPDATE EXISTING ALBUM
    if (isset($_POST['update_album'])) {
        $id = (int)$_POST['album_id'];
        $name = trim($_POST['album_name']);
        $desc = trim($_POST['album_description']);
        
        if (!empty($name)) {
            $stmt = $pdo->prepare("UPDATE snap_albums SET album_name = ?, album_desc = ? WHERE id = ?");
            $stmt->execute([$name, $desc, $id]);
            // Redirect to clear the edit state and show success message
            header("Location: smack-albums.php?msg=ALBUM+UPDATED");
            exit;
        }
    }
}

// 2. DELETE ALBUM
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Protocol: Unmap signals before deleting the container
    $pdo->prepare("DELETE FROM snap_image_album_map WHERE album_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_albums WHERE id = ?")->execute([$id]);
    header("Location: smack-albums.php?msg=DELETED");
    exit;
}

// 3. CHECK FOR EDIT MODE
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM snap_albums WHERE id = ?");
    $stmt->execute([$id]);
    $edit_data = $stmt->fetch();
    if ($edit_data) {
        $edit_mode = true;
    }
}

// 4. RETRIEVE REGISTRY DATA
$query = "SELECT a.*, COUNT(m.image_id) as img_count 
          FROM snap_albums a 
          LEFT JOIN snap_image_album_map m ON a.id = m.album_id 
          GROUP BY a.id 
          ORDER BY a.id DESC";
$albums = $pdo->query($query)->fetchAll();

$page_title = "SNAPSMACK | ALBUM REGISTRY";

// CORRECTED: Explicit Admin Core Components
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>ALBUM REGISTRY</h2>
    
    <?php if($msg || isset($_GET['msg'])): ?>
        <div class='msg'><?php echo ($msg ?: htmlspecialchars($_GET['msg'])); ?></div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 40px;">
        
        <div class="box">
            <h3><?php echo $edit_mode ? "MODIFY EXISTING ALBUM" : "INITIALIZE NEW ALBUM"; ?></h3>
            <p class="hint" style="margin-bottom: 20px; text-transform: uppercase;">
                <?php echo $edit_mode ? "Update registry metadata." : "Establish a new signal container."; ?>
            </p>
            
            <form method="POST">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="update_album" value="1">
                    <input type="hidden" name="album_id" value="<?php echo $edit_data['id']; ?>">
                <?php else: ?>
                    <input type="hidden" name="new_album" value="1">
                <?php endif; ?>
                
                <label>ALBUM NAME</label>
                <input type="text" name="album_name" 
                       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['album_name']) : ''; ?>" 
                       placeholder="e.g. SOFOBOMO_2026" required autofocus>
                
                <label>ALBUM DESCRIPTION</label>
                <textarea name="album_description" placeholder="Technical notes or series narrative..."><?php echo $edit_mode ? htmlspecialchars($edit_data['album_desc']) : ''; ?></textarea>
                
                <button type="submit" style="margin-top: 20px; width: 100%;">
                    <?php echo $edit_mode ? "UPDATE ALBUM DATA" : "ESTABLISH ALBUM"; ?>
                </button>

                <?php if ($edit_mode): ?>
                    <a href="smack-albums.php" class="btn-clear" style="margin-top: 10px; width: 100%; text-align: center; display: block; box-sizing: border-box; text-decoration: none; padding: 15px;">CANCEL MODIFICATION</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="box">
            <h3>ACTIVE ALBUMS</h3>
            <div class="album-list">
                <?php foreach($albums as $a): ?>
                    <div class="recent-item">
                        <div class="item-details">
                            <div class="item-text">
                                <strong><?php echo htmlspecialchars($a['album_name']); ?></strong>
                                <span class="dim" style="font-family: 'Courier New', monospace; font-size: 0.75rem;">
                                    <?php echo $a['album_desc'] ? htmlspecialchars($a['album_desc']) : "NO DESCRIPTION RECORDED."; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="item-actions">
                            <span style="font-family: 'Inter', sans-serif; font-size: 0.7rem; color: #444; margin-right: 15px; align-self: center;">
                                [ <?php echo $a['img_count']; ?> SIGNALS ]
                            </span>
                            
                            <a href="?edit=<?php echo $a['id']; ?>" class="action-edit">
                                [ EDIT ]
                            </a>

                            <a href="?delete=<?php echo $a['id']; ?>" 
                               class="action-delete"
                               onclick="return confirm('CONFIRM DESTRUCTION OF ALBUM REGISTRY?');">
                                [ DELETE ]
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if(empty($albums)): ?>
                    <div class="dim" style="padding: 60px; text-align: center; border: 1px dashed #222; text-transform: uppercase; letter-spacing: 2px; border-radius: 4px;">
                        REGISTRY EMPTY / NO CONTAINERS FOUND
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php 
// CORRECTED: Explicit Admin Footer for Universal Version 11.7
include 'core/admin-footer.php'; 
?>