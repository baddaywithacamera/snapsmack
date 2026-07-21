<?php
/**
 * SNAPSMACK - Category (registry) management
 *
 * Provides creation, editing, and deletion of photo categories.
 * Maintains associations between categories and their tagged images.
 * Supports a featured image (from any post) used in gallery and collection views.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim($settings['site_url'] ?? '/', '/') . '/');
}

// Auto-run migration if featured_post_id column is missing
try {
    $pdo->query("SELECT featured_post_id FROM snap_categories LIMIT 0");
} catch (PDOException $e) {
    $mig = __DIR__ . '/migrations/039_featured_images.php';
    if (file_exists($mig)) { require_once $mig; migration_039_up($pdo); }
}

$msg = "";
$edit_mode = false;
$edit_data = [];

// --- AJAX: image picker for featured image ---
// Returns { posts: [{id, title, thumb}], hasMore: bool } for ss-engine-featured-picker.js
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'posts') {
    header('Content-Type: application/json');
    $q      = '%' . trim($_GET['q'] ?? '') . '%';
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = 30;
    $rows = $pdo->prepare(
        "SELECT i.id, i.img_title AS title,
                i.img_thumb_square, i.img_thumb_aspect, i.img_file
         FROM snap_images i
         WHERE i.img_status = 'published' AND i.img_title LIKE ?
         ORDER BY i.id DESC
         LIMIT ? OFFSET ?"
    );
    $rows->bindValue(1, $q,         PDO::PARAM_STR);
    $rows->bindValue(2, $limit + 1, PDO::PARAM_INT);
    $rows->bindValue(3, $offset,    PDO::PARAM_INT);
    $rows->execute();
    $raw = $rows->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($raw) > $limit;
    if ($hasMore) array_pop($raw);
    $posts = [];
    foreach ($raw as $r) {
        $posts[] = [
            'id'    => (int)$r['id'],
            'title' => $r['title'],
            'thumb' => $r['img_thumb_square'] ?: ($r['img_thumb_aspect'] ?: $r['img_file']),
        ];
    }
    echo json_encode(['posts' => $posts, 'hasMore' => $hasMore]);
    exit;
}

// --- FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name            = trim($_POST['cat_name']);
    $description     = trim($_POST['cat_description'] ?? '');
    $featured_post_id = !empty($_POST['featured_post_id']) ? (int)$_POST['featured_post_id'] : null;

    if (isset($_POST['new_cat']) && !empty($name)) {
        $cat_slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        $pdo->prepare("INSERT INTO snap_categories (cat_name, cat_slug, cat_description, featured_post_id) VALUES (?, ?, ?, ?)")
            ->execute([$name, $cat_slug, $description, $featured_post_id]);
        header("Location: smack-cats.php?msg=REGISTRY+ENTRY+ADDED");
        exit;
    }

    if (isset($_POST['update_cat']) && !empty($name)) {
        $id              = (int)$_POST['cat_id'];
        $show_in_archive = isset($_POST['show_in_archive']) ? 1 : 0;
        $pdo->prepare("UPDATE snap_categories SET cat_name=?, cat_description=?, show_in_archive=?, featured_post_id=? WHERE id=?")
            ->execute([$name, $description, $show_in_archive, $featured_post_id, $id]);
        header("Location: smack-cats.php?msg=REGISTRY+ENTRY+MODIFIED");
        exit;
    }
}

// --- DELETION ---
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE cat_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_categories WHERE id = ?")->execute([$id]);
    header("Location: smack-cats.php?msg=REGISTRY+ENTRY+PURGED");
    exit;
}

// --- EDIT MODE ---
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM snap_categories WHERE id = ?");
    $stmt->execute([$id]);
    $edit_data = $stmt->fetch();
    if ($edit_data) { $edit_mode = true; }
}

$cats = $pdo->query(
    "SELECT c.*, COUNT(m.image_id) as img_count
     FROM snap_categories c
     LEFT JOIN snap_image_cat_map m ON c.id = m.cat_id
     GROUP BY c.id
     ORDER BY c.cat_name ASC"
)->fetchAll();

// Fetch featured image thumbnail for currently editing category
$featured_thumb = null;
if ($edit_mode && !empty($edit_data['featured_post_id'])) {
    $fs = $pdo->prepare(
        "SELECT i.img_thumb_square, i.img_thumb_aspect, i.img_file, i.img_title AS title
         FROM snap_images i
         WHERE i.id = ? LIMIT 1"
    );
    $fs->execute([$edit_data['featured_post_id']]);
    $featured_thumb = $fs->fetch(PDO::FETCH_ASSOC) ?: null;
}

$page_title = "Registry";
include 'core/admin-header.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-featured-picker.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-featured-picker.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>REGISTRY (CATEGORIES)</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
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
                        <input type="text" name="cat_name"
                               value="<?php echo $edit_mode ? htmlspecialchars($edit_data['cat_name']) : ''; ?>"
                               placeholder="E.G. STREET, PORTRAITS, LANDSCAPE" required autofocus>
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <label>DESCRIPTION <span class="field-tip" data-tip="Optional. Shown on category pages and used for SEO meta description.">ⓘ</span></label>
                        <textarea name="cat_description" rows="3" placeholder="A short description of this category."><?php echo $edit_mode ? htmlspecialchars($edit_data['cat_description'] ?? '') : ''; ?></textarea>
                    </div>

                    <?php if ($edit_mode): ?>
                    <div class="lens-input-wrapper mt-20">
                        <label>
                            <input type="checkbox" name="show_in_archive" value="1"
                                   <?php echo (!$edit_mode || ($edit_data['show_in_archive'] ?? 1)) ? 'checked' : ''; ?>>
                            SHOW IN ARCHIVE <span class="field-tip" data-tip="Uncheck to hide this category and its images from the public archive grid.">ⓘ</span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <!-- FEATURED IMAGE -->
                    <div class="lens-input-wrapper mt-20">
                        <label>FEATURED IMAGE <span class="field-tip" data-tip="Pick any post — used as the representative thumbnail for this category.">ⓘ</span></label>
                        <input type="hidden" name="featured_post_id" id="cat-featured-id"
                               value="<?php echo $edit_mode ? (int)($edit_data['featured_post_id'] ?? 0) : 0; ?>">
                        <div id="cat-featured-preview" class="ssfp-preview"></div>
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <button type="submit" class="master-update-btn">
                            <?php echo $edit_mode ? "UPDATE CATEGORY" : "ADD TO REGISTRY"; ?>
                        </button>
                    </div>

                    <?php if ($edit_mode): ?>
                        <div class="lens-input-wrapper mt-10">
                            <a href="smack-cats.php" class="btn-reset btn-cancel-block">CANCEL EDIT</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex-1">
                <div class="box">
                    <h3>ACTIVE REGISTRY</h3>

                    <?php if (empty($cats)): ?>
                        <p class="dim empty-notice">No categories registered.</p>
                    <?php else: ?>
                        <?php foreach ($cats as $c): ?>
                            <div class="recent-item">
                                <div class="item-details">
                                    <div class="item-text">
                                        <strong><?php echo htmlspecialchars($c['cat_name']); ?></strong>
                                        <?php if (!($c['show_in_archive'] ?? 1)): ?>
                                            <code class="slug-display" style="color:#c0392b;">HIDDEN FROM ARCHIVE</code>
                                        <?php endif; ?>
                                        <code class="slug-display">TRANSMISSIONS: <?php echo (int)$c['img_count']; ?></code>
                                        <?php if (!empty($c['cat_description'])): ?>
                                            <span class="dim" style="display:block;margin-top:4px;font-size:0.85em;"><?php echo htmlspecialchars($c['cat_description']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="item-actions">
                                    <a href="?edit=<?php echo $c['id']; ?>" class="action-edit">EDIT</a>
                                    <a href="?delete=<?php echo $c['id']; ?>" class="action-delete"
                                       onclick="return confirm('PURGE CATEGORY? Images will be uncategorized but not deleted.')">DELETE</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>

<script>
// Wire the shared featured-image picker engine to the preview <div>.
// Engine script is `defer`red — wait for DOMContentLoaded so it's loaded.
document.addEventListener('DOMContentLoaded', function () {
    if (!window.ssFeaturedPicker) return;
    var prevEl = document.getElementById('cat-featured-preview');
    var idEl   = document.getElementById('cat-featured-id');
    if (!prevEl || !idEl) return;

    var initialThumb = <?php
        if (!empty($featured_thumb)) {
            $tu = BASE_URL . ltrim(
                ($featured_thumb['img_thumb_square']
                 ?: $featured_thumb['img_thumb_aspect']
                 ?: $featured_thumb['img_file']), '/');
            echo json_encode($tu);
        } else {
            echo 'null';
        }
    ?>;
    var initialTitle = <?php
        echo !empty($featured_thumb) ? json_encode($featured_thumb['title']) : "''";
    ?>;

    window.ssFeaturedPicker.attach({
        endpoint:      'smack-cats.php?ajax=posts',
        previewEl:     prevEl,
        hiddenInputEl: idEl,
        baseUrl:       <?php echo json_encode(BASE_URL); ?>,
        initialThumb:  initialThumb,
        initialTitle:  initialTitle
    });
});
</script>
<?php // ===== SNAPSMACK EOF =====
