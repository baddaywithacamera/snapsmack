<?php
/**
 * SNAPSMACK - Static page editor and manager
 * Alpha v0.7.1
 *
 * Provides creation, modification, and deletion of static pages.
 * Automatically converts plain text to HTML with paragraph wrapping.
 */

require_once 'core/auth.php';

// --- PLAIN TEXT TO HTML CONVERTER ---
// Converts plain text input into properly wrapped HTML paragraphs.
// Respects shortcodes and prevents double-wrapping of existing markup.
function smack_autop($text) {
    if (trim($text) === '') return '';

    // If the text is already HTML, return as-is to prevent double-wrapping.
    if (preg_match('/^\s*<p/i', $text)) {
        return $text;
    }

    // Remove line breaks after shortcodes to avoid extra spacing.
    $text = preg_replace('/(\[img:[^\]]+\])\s*\n+/', '$1', $text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Protect block-level HTML (lists, tables, blockquotes, etc.) from
    // paragraph wrapping. Extract them, leave placeholders, restore after.
    $protected = [];
    $text = preg_replace_callback(
        '/<(ul|ol|table|blockquote|pre|div|figure|section|aside)[\s>].*?<\/\1>/si',
        function ($m) use (&$protected) {
            $key = '<!--BLOCK:' . count($protected) . '-->';
            $protected[$key] = $m[0];
            return "\n\n" . $key . "\n\n";
        },
        $text
    );

    // Split by blank lines to identify paragraph boundaries.
    $chunks = preg_split('/\n\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($chunks as &$chunk) {
        $trimmed = trim($chunk);
        // Don't wrap placeholders or standalone shortcodes in <p> tags
        if (str_starts_with($trimmed, '<!--BLOCK:')) {
            $chunk = $trimmed;
        } elseif (preg_match('/^\[img:\s*\d+(?:\s*\|[^\]]*)*\]$/', $trimmed)) {
            $chunk = $trimmed;
        } elseif (preg_match('/^\[spacer:\s*\d+\]$/', $trimmed)) {
            $chunk = $trimmed;
        } else {
            $chunk = '<p>' . nl2br($trimmed) . '</p>';
        }
    }

    $result = implode("\n", $chunks);

    // Restore protected blocks
    foreach ($protected as $key => $block) {
        $result = str_replace($key, $block, $result);
    }

    return $result;
}

// --- HTML TO PLAIN TEXT REVERTER ---
// Converts stored HTML back to plain text for editing in the textarea.
// Removes wrapping tags and normalizes line breaks.
function smack_reverse_autop($text) {
    $text = str_replace('<p>', '', $text);
    $text = str_replace('</p>', "\n", $text);
    $text = preg_replace('/<br\s*\/?>/i', '', $text);
    return trim($text);
}

// --- FORM SUBMISSION HANDLER ---
// Saves new pages or updates existing ones.
if (isset($_POST['save_page'])) {
    $id = $_POST['page_id'] ?: null;
    $title = $_POST['title'];
    $slug = $_POST['slug'];

    // Convert plain text to HTML before storing.
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

// --- DELETION HANDLER ---
// Removes a page record from the database.
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM snap_pages WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header("Location: smack-pages.php");
    exit;
}

// --- DATA RETRIEVAL ---
// Load the page for editing if an edit parameter is present.
$edit_page = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM snap_pages WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_page = $stmt->fetch();

    // Convert HTML back to plain text for the textarea.
    if ($edit_page) {
        $edit_page['content'] = smack_reverse_autop($edit_page['content']);
    }
}

// Load all pages ordered by menu position.
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
            <h3><?php echo $edit_page ? 'MODIFY EXISTING TRANSMISSION' : 'CREATE STATIC TRANSMISSION'; ?></h3>
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
            <div class="sc-toolbar" data-target="page-content">
                <div class="sc-row">
                    <button type="button" class="sc-btn" data-action="bold" title="Bold (Ctrl+B)">B</button>
                    <button type="button" class="sc-btn" data-action="italic" title="Italic (Ctrl+I)">I</button>
                    <button type="button" class="sc-btn" data-action="underline" title="Underline (Ctrl+U)">U</button>
                    <button type="button" class="sc-btn" data-action="link" title="Insert Link">LINK</button>
                    <span class="sc-sep"></span>
                    <button type="button" class="sc-btn" data-action="h2" title="Heading 2">H2</button>
                    <button type="button" class="sc-btn" data-action="h3" title="Heading 3">H3</button>
                    <button type="button" class="sc-btn" data-action="blockquote" title="Blockquote">BQ</button>
                    <button type="button" class="sc-btn" data-action="hr" title="Horizontal Rule">HR</button>
                    <span class="sc-sep"></span>
                    <button type="button" class="sc-btn" data-action="ul" title="Bullet List">UL</button>
                    <button type="button" class="sc-btn" data-action="ol" title="Numbered List">OL</button>
                </div>
                <div class="sc-row">
                    <button type="button" class="sc-btn" data-action="img" title="Insert Image Shortcode">IMG</button>
                    <button type="button" class="sc-btn" data-action="col2" title="2-Column Layout">COL 2</button>
                    <button type="button" class="sc-btn" data-action="col3" title="3-Column Layout">COL 3</button>
                    <button type="button" class="sc-btn" data-action="dropcap" title="Dropcap">DROP</button>
                    <button type="button" class="sc-btn" data-action="spacer" title="Vertical Spacer (1-100px)">SPACER</button>
                    <button type="button" class="sc-btn sc-btn-preview" data-action="preview" title="Preview in New Tab">PREVIEW</button>
                </div>
            </div>
            <textarea id="page-content" name="content" rows="20"><?php echo htmlspecialchars($edit_page['content'] ?? ''); ?></textarea>

            <div class="form-action-row">
                <button type="submit" name="save_page" class="master-update-btn">COMMIT TO DATABASE</button>
                <?php if($edit_page): ?>
                    <a href="smack-pages.php" class="btn-reset btn-abort">ABORT</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="box">
        <h3>STORED TRANSMISSIONS</h3>
        <?php foreach ($pages as $p): ?>
            <div class="recent-item">
                <div class="item-text">
                    <div class="signal-sender">
                        [<?php echo $p['menu_order']; ?>] <?php echo htmlspecialchars($p['title']); ?>
                        <span class="dim text-sm ml-10">/<?php echo htmlspecialchars($p['slug']); ?></span>
                    </div>
                </div>
                <div class="item-actions">
                    <a href="?edit=<?php echo $p['id']; ?>" class="action-edit">EDIT</a>
                    <a href="?delete=<?php echo $p['id']; ?>" class="action-delete" onclick="return confirm('Purge this transmission?')">DELETE</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="assets/js/shortcode-toolbar.js"></script>
<?php include 'core/admin-footer.php'; ?>
