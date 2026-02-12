<?php
/**
 * SnapSmack - Static Page Manager
 * Version: 1.6.2 - Admin Core Integrated
 * MASTER DIRECTIVE: Full file return. 
 */
require_once 'core/auth.php';

/**
 * Helper: Converts raw line breaks into HTML paragraphs.
 * Neutralizes line breaks after shortcodes for perfect alignment.
 */
function smack_autop($text) {
    if (trim($text) === '') return '';
    
    // 1. Protection: If it already looks like HTML, don't double-wrap
    if (preg_match('/^\s*<p/i', $text)) {
        return $text;
    }

    // 2. Shortcode Filter: Strip line breaks immediately following an image tag
    // This allows you to put the shortcode on its own line in the editor.
    $text = preg_replace('/(\[img:[^\]]+\])\s*\n+/', '$1', $text);

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    
    // Split by double newlines to find paragraph boundaries
    $chunks = preg_split('/\n\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    
    foreach ($chunks as &$chunk) {
        $chunk = '<p>' . nl2br(trim($chunk)) . '</p>';
    }
    
    return implode("\n", $chunks);
}

/**
 * Reverser: Prepares database HTML for the plain-text Edit Window.
 * Optimized to prevent double-spacing in the textarea.
 */
function smack_reverse_autop($text) {
    // 1. Remove opening <p> tags
    $text = str_replace('<p>', '', $text);
    
    // 2. Replace closing </p> tags with a SINGLE newline
    // This creates natural spacing in the textarea without massive gaps
    $text = str_replace('</p>', "\n", $text);
    
    // 3. Remove manual line breaks (<br />)
    $text = preg_replace('/<br\s*\/?>/i', '', $text);
    
    return trim($text);
}

// 1. Save/Update Logic
if (isset($_POST['save_page'])) {
    $id = $_POST['page_id'] ?: null;
    $title = $_POST['title'];
    $slug = $_POST['slug'];
    
    // Process plain text into HTML before committing
    $content = smack_autop($_POST['content']);
    
    $asset = $_POST['image_asset'];
    $order = (int)$_POST['menu_order'];

    if ($id) {
        $stmt = $pdo->prepare("UPDATE snap_pages SET title = ?, slug = ?, content = ?, image_asset = ?, menu_order = ? WHERE id = ?");
        $stmt->execute([$title, $slug, $content, $asset, $order, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO snap_pages (title, slug, content, image_asset, menu_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $slug, $content, $asset, $order]);
    }
    $msg = "Static transmission synchronized. HTML hidden in interface.";
}

// 2. Delete Logic
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM snap_pages WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header("Location: smack-pages.php");
    exit;
}

// 3. Fetch Page for Editing
$edit_page = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM snap_pages WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_page = $stmt->fetch();
    
    // Strip the tags so Sean sees plain text in the textarea
    if ($edit_page) {
        $edit_page['content'] = smack_reverse_autop($edit_page['content']);
    }
}

$pages = $pdo->query("SELECT * FROM snap_pages ORDER BY menu_order ASC, title ASC")->fetchAll();

$page_title = "Page Manager";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>PAGE MANAGER</h2>
    
    <?php if(isset($msg)) echo "<div class='msg'>> $msg</div>"; ?>

    <form method="POST">
        <div class="box">
            <h3><?php echo $edit_page ? 'MODIFY EXISTING SIGNAL' : 'CREATE STATIC TRANSMISSION'; ?></h3>
            <input type="hidden" name="page_id" value="<?php echo $edit_page['id'] ?? ''; ?>">
            
            <label>Title</label>
            <input type="text" name="title" value="<?php echo htmlspecialchars($edit_page['title'] ?? ''); ?>" required>
            
            <label>Slug (URL Hook)</label>
            <input type="text" name="slug" value="<?php echo htmlspecialchars($edit_page['slug'] ?? ''); ?>" required>

            <label>Menu Order (Lower numbers first)</label>
            <input type="number" name="menu_order" value="<?php echo htmlspecialchars($edit_page['menu_order'] ?? '0'); ?>">

            <label>Main Header Image (Optional Path)</label>
            <input type="text" name="image_asset" value="<?php echo htmlspecialchars($edit_page['image_asset'] ?? ''); ?>" placeholder="e.g. media_assets/1706821234.jpg">
            
            <label>Content (Shortcodes and plain text only)</label>
            <textarea name="content" style="height: 400px;"><?php echo htmlspecialchars($edit_page['content'] ?? ''); ?></textarea>
            
            <button type="submit" name="save_page">COMMIT TO DATABASE</button>
            <?php if($edit_page): ?>
                <a href="smack-pages.php" class="btn-clear" style="margin-left: 20px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; padding: 0 30px; height: 52px; border-radius: 4px;">ABORT</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="box">
        <h3>STORED SIGNALS</h3>
        <?php foreach ($pages as $p): ?>
            <div class="recent-item">
                <div class="item-text">
                    <div class="signal-sender">
                        [<?php echo $p['menu_order']; ?>] <?php echo htmlspecialchars($p['title']); ?> 
                        <span style="color: #444; font-size: 0.8rem; margin-left: 10px;">/<?php echo htmlspecialchars($p['slug']); ?></span>
                    </div>
                </div>
                <div class="item-actions">
                    <a href="?edit=<?php echo $p['id']; ?>" class="action-authorize">EDIT</a>
                    <a href="?delete=<?php echo $p['id']; ?>" class="action-delete" onclick="return confirm('Purge this signal?')">DELETE</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>