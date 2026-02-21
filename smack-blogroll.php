<?php
/**
 * SNAPSMACK - Network Manager
 * Independent peer endorsement without hierarchy.
 */

require_once 'core/auth.php';

// --- 1. PERSISTENCE HANDLER ---
if (isset($_POST['save_peer'])) {
    $id = $_POST['peer_id'] ?? null;
    $name = trim($_POST['peer_name']);
    $url = trim($_POST['peer_url']);
    $cat = trim($_POST['peer_category']);
    $rss = trim($_POST['peer_rss']);
    $desc = trim($_POST['peer_desc']);

    if ($id) {
        $stmt = $pdo->prepare("UPDATE snap_blogroll SET peer_name=?, peer_url=?, peer_category=?, peer_rss=?, peer_desc=? WHERE id=?");
        $stmt->execute([$name, $url, $cat, $rss, $desc, $id]);
        $msg = "updated";
    } else {
        $stmt = $pdo->prepare("INSERT INTO snap_blogroll (peer_name, peer_url, peer_category, peer_rss, peer_desc) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $url, $cat, $rss, $desc]);
        $msg = "added";
    }
    header("Location: snap-blogroll.php?msg=" . $msg);
    exit;
}

// --- 2. REMOVAL HANDLER ---
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM snap_blogroll WHERE id=?")->execute([$_GET['delete']]);
    header("Location: snap-blogroll.php?msg=deleted");
    exit;
}

// --- 3. DATA ACQUISITION ---
$peers = $pdo->query("SELECT * FROM snap_blogroll ORDER BY peer_category ASC, peer_name ASC")->fetchAll(PDO::FETCH_ASSOC);

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
        <div class="alert alert-success">> THANKS FOR SHOWING SOME LINKY LOVE - YOU ROCK!</div>
    <?php endif; ?>

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
                        <input type="text" name="peer_category" value="<?php echo htmlspecialchars($edit_peer['peer_category'] ?? ''); ?>" placeholder="e.g. Local, Film, Vanguard">
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
                    <a href="snap-blogroll.php" class="back-link">&larr; ABORT EDIT</a>
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
                        if ($current_cat !== $p['peer_category']) {
                            $current_cat = $p['peer_category'];
                            echo '<div class="section-divider"></div>';
                            echo '<h4 class="dim" style="text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">' . htmlspecialchars($current_cat ?: 'UNCATEGORIZED') . '</h4>';
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