<?php
/**
 * SnapSmack - Blogroll / Network Manager
 * Version: 2.0 - Proper Category Management
 * -------------------------------------------------------------------------
 * - FIXED: Category is now a managed entity (snap_blogroll_cats table).
 * - FIXED: Redirect URLs corrected to smack-blogroll.php.
 * - FIXED: Labels uppercase, consistent SnapSmack style.
 * - FIXED: action-cell-flex on list items.
 * - ADDED: Full category add/edit/delete management.
 * -------------------------------------------------------------------------
 */

require_once 'core/auth.php';

// -------------------------------------------------------------------------
// 1. CATEGORY HANDLERS
// -------------------------------------------------------------------------

// Add category
if (isset($_POST['add_cat'])) {
    $cat_name = trim($_POST['cat_name']);
    if (!empty($cat_name)) {
        $sort = (int)$pdo->query("SELECT COUNT(*) FROM snap_blogroll_cats")->fetchColumn();
        $pdo->prepare("INSERT INTO snap_blogroll_cats (cat_name, sort_order) VALUES (?, ?)")
            ->execute([$cat_name, $sort + 1]);
    }
    header("Location: smack-blogroll.php?msg=cat_added&tab=cats");
    exit;
}

// Update category
if (isset($_POST['update_cat'])) {
    $id = (int)$_POST['cat_id'];
    $cat_name = trim($_POST['cat_name']);
    if (!empty($cat_name)) {
        $pdo->prepare("UPDATE snap_blogroll_cats SET cat_name = ? WHERE id = ?")
            ->execute([$cat_name, $id]);
    }
    header("Location: smack-blogroll.php?msg=cat_updated&tab=cats");
    exit;
}

// Delete category
if (isset($_GET['delete_cat'])) {
    $id = (int)$_GET['delete_cat'];
    // Null out references in blogroll before deleting
    $pdo->prepare("UPDATE snap_blogroll SET cat_id = NULL WHERE cat_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_blogroll_cats WHERE id = ?")->execute([$id]);
    header("Location: smack-blogroll.php?msg=cat_deleted&tab=cats");
    exit;
}

// -------------------------------------------------------------------------
// 2. PEER HANDLERS
// -------------------------------------------------------------------------

if (isset($_POST['save_peer'])) {
    $id    = $_POST['peer_id'] ?? null;
    $name  = trim($_POST['peer_name']);
    $url   = trim($_POST['peer_url']);
    $cat   = (int)$_POST['cat_id'] ?: null;
    $rss   = trim($_POST['peer_rss']);
    $desc  = trim($_POST['peer_desc']);

    if ($id) {
        $pdo->prepare("UPDATE snap_blogroll SET peer_name=?, peer_url=?, cat_id=?, peer_rss=?, peer_desc=? WHERE id=?")
            ->execute([$name, $url, $cat, $rss, $desc, (int)$id]);
        $msg = "updated";
    } else {
        $pdo->prepare("INSERT INTO snap_blogroll (peer_name, peer_url, cat_id, peer_rss, peer_desc) VALUES (?, ?, ?, ?, ?)")
            ->execute([$name, $url, $cat, $rss, $desc]);
        $msg = "added";
    }
    header("Location: smack-blogroll.php?msg=" . $msg);
    exit;
}

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM snap_blogroll WHERE id=?")->execute([(int)$_GET['delete']]);
    header("Location: smack-blogroll.php?msg=deleted");
    exit;
}

// -------------------------------------------------------------------------
// 3. DATA ACQUISITION
// -------------------------------------------------------------------------

$cats = $pdo->query("SELECT * FROM snap_blogroll_cats ORDER BY sort_order ASC, cat_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$peers = $pdo->query("
    SELECT b.*, c.cat_name 
    FROM snap_blogroll b
    LEFT JOIN snap_blogroll_cats c ON b.cat_id = c.id
    ORDER BY c.sort_order ASC, c.cat_name ASC, b.peer_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$edit_peer = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM snap_blogroll WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit_peer = $stmt->fetch(PDO::FETCH_ASSOC);
}

$edit_cat = null;
if (isset($_GET['edit_cat'])) {
    $stmt = $pdo->prepare("SELECT * FROM snap_blogroll_cats WHERE id=?");
    $stmt->execute([(int)$_GET['edit_cat']]);
    $edit_cat = $stmt->fetch(PDO::FETCH_ASSOC);
}

$active_tab = $_GET['tab'] ?? 'peers';

$page_title = "Blogroll";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="page-header-split">
        <h2>BLOGROLL</h2>
        <div class="page-header-tabs">
            <a href="?tab=peers" class="css-tab <?php echo $active_tab === 'peers' ? 'css-tab-active' : ''; ?>">THE NETWORK</a>
            <a href="?tab=cats" class="css-tab <?php echo $active_tab === 'cats' ? 'css-tab-active' : ''; ?>">CATEGORIES</a>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> <?php
            $msgs = [
                'added'       => 'PEER COMMITTED TO THE NETWORK.',
                'updated'     => 'PEER RECORD UPDATED.',
                'deleted'     => 'PEER REMOVED FROM NETWORK.',
                'cat_added'   => 'CATEGORY ADDED.',
                'cat_updated' => 'CATEGORY UPDATED.',
                'cat_deleted' => 'CATEGORY PURGED.',
            ];
            echo $msgs[$_GET['msg']] ?? 'DONE.';
        ?></div>
    <?php endif; ?>

    <?php if ($active_tab === 'peers'): ?>
    <!-- ================================================================
         PEERS TAB
    ================================================================ -->
    <div class="post-layout-grid">
        <div class="post-col-left">
            <div class="box">
                <h3><?php echo $edit_peer ? 'EDIT PEER' : 'ADD TO NETWORK'; ?></h3>
                <form method="POST">
                    <?php if ($edit_peer): ?>
                        <input type="hidden" name="peer_id" value="<?php echo $edit_peer['id']; ?>">
                    <?php endif; ?>

                    <div class="lens-input-wrapper">
                        <label>SITE / BLOGGER NAME</label>
                        <input type="text" name="peer_name" required value="<?php echo htmlspecialchars($edit_peer['peer_name'] ?? ''); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>URL</label>
                        <input type="text" name="peer_url" required value="<?php echo htmlspecialchars($edit_peer['peer_url'] ?? ''); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>CATEGORY</label>
                        <select name="cat_id">
                            <option value="">— UNCATEGORIZED —</option>
                            <?php foreach ($cats as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo (($edit_peer['cat_id'] ?? null) == $c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['cat_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>RSS FEED URL <span class="dim" style="font-weight:400;">(OPTIONAL)</span></label>
                        <input type="text" name="peer_rss" value="<?php echo htmlspecialchars($edit_peer['peer_rss'] ?? ''); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>DESCRIPTION <span class="dim" style="font-weight:400;">(OPTIONAL)</span></label>
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

        <div class="post-col-left">
            <div class="box">
                <h3>THE NETWORK</h3>
                <?php if (!$peers): ?>
                    <div class="empty-notice dim">No peers yet. The network is offline.</div>
                <?php else: ?>
                    <?php 
                    $current_cat = false;
                    $first_cat = true;
                    foreach ($peers as $p):
                        $cat_label = $p['cat_name'] ?: 'UNCATEGORIZED';
                        if ($current_cat !== $cat_label):
                            $current_cat = $cat_label;
                    ?>
                        <?php if (!$first_cat): ?>
                            <div class="section-divider"></div>
                        <?php endif; $first_cat = false; ?>
                        <p class="dim" style="font-size:0.7rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; margin: 0 0 10px 0;">
                            <?php echo htmlspecialchars($cat_label); ?>
                        </p>
                    <?php endif; ?>
                        <div class="recent-item">
                            <div class="item-text">
                                <strong><?php echo htmlspecialchars($p['peer_name']); ?></strong>
                                <span class="dim item-meta"><?php echo htmlspecialchars($p['peer_url']); ?></span>
                            </div>
                            <div class="action-cell-flex">
                                <a href="?edit=<?php echo $p['id']; ?>" class="action-edit">EDIT</a>
                                <a href="?delete=<?php echo $p['id']; ?>" class="action-delete" onclick="return confirm('Remove this peer from the network?')">DELETE</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- ================================================================
         CATEGORIES TAB
    ================================================================ -->
    <div class="post-layout-grid">
        <div class="post-col-left">
            <form method="POST">
                <div class="box">
                    <h3><?php echo $edit_cat ? 'EDIT CATEGORY' : 'ADD CATEGORY'; ?></h3>

                    <?php if ($edit_cat): ?>
                        <input type="hidden" name="cat_id" value="<?php echo $edit_cat['id']; ?>">
                    <?php endif; ?>

                    <div class="lens-input-wrapper">
                        <label>CATEGORY NAME</label>
                        <input type="text" name="cat_name" required value="<?php echo htmlspecialchars($edit_cat['cat_name'] ?? ''); ?>" autofocus>
                    </div>

                    <div class="form-action-row">
                        <button type="submit" name="<?php echo $edit_cat ? 'update_cat' : 'add_cat'; ?>" class="master-update-btn">
                            <?php echo $edit_cat ? 'UPDATE CATEGORY' : 'ADD CATEGORY'; ?>
                        </button>
                    </div>
                </div>
            </form>
            <?php if ($edit_cat): ?>
                <a href="smack-blogroll.php?tab=cats" class="back-link">&larr; ABORT EDIT</a>
            <?php endif; ?>
        </div>

        <div class="post-col-left">
            <div class="box">
                <h3>ACTIVE CATEGORIES</h3>
                <?php if (empty($cats)): ?>
                    <div class="empty-notice dim">No categories defined.</div>
                <?php else: ?>
                    <?php foreach ($cats as $c): ?>
                        <div class="recent-item">
                            <div class="item-text">
                                <strong><?php echo htmlspecialchars($c['cat_name']); ?></strong>
                            </div>
                            <div class="action-cell-flex">
                                <a href="?edit_cat=<?php echo $c['id']; ?>&tab=cats" class="action-edit">EDIT</a>
                                <a href="?delete_cat=<?php echo $c['id']; ?>" class="action-delete" onclick="return confirm('Delete this category? Peers assigned to it will become uncategorized.')">DELETE</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
