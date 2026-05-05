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


require_once 'core/auth.php';

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
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'posts') {
    header('Content-Type: application/json');
    $q     = '%' . trim($_GET['q'] ?? '') . '%';
    $posts = $pdo->prepare(
        "SELECT i.id, i.img_title AS title, i.img_date AS created_at,
                i.img_thumb_square, i.img_thumb_aspect, i.img_file
         FROM snap_images i
         WHERE i.img_status = 'published' AND i.img_title LIKE ?
         ORDER BY i.img_date DESC
         LIMIT 80"
    );
    $posts->execute([$q]);
    echo json_encode($posts->fetchAll(PDO::FETCH_ASSOC));
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
                        <div id="cat-featured-preview" style="margin-top:8px;">
                            <?php if ($featured_thumb): ?>
                                <?php
                                $thumb_url = BASE_URL . ltrim($featured_thumb['img_thumb_square'] ?: $featured_thumb['img_thumb_aspect'] ?: $featured_thumb['img_file'], '/');
                                ?>
                                <img src="<?php echo htmlspecialchars($thumb_url); ?>"
                                     style="width:80px;height:80px;object-fit:cover;border-radius:3px;border:1px solid var(--border);"
                                     alt="Featured image">
                                <span class="dim" style="display:block;font-size:11px;margin-top:4px;"><?php echo htmlspecialchars($featured_thumb['title']); ?></span>
                            <?php else: ?>
                                <div id="cat-featured-placeholder" style="width:80px;height:80px;background:var(--card-bg);border:1px dashed var(--border);border-radius:3px;display:flex;align-items:center;justify-content:center;">
                                    <span class="dim" style="font-size:10px;text-align:center;padding:4px;">NO IMAGE</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:8px;">
                            <button type="button" onclick="openFeaturedPicker('cat')" class="btn-secondary" style="font-size:11px;padding:5px 12px;">
                                <?php echo ($featured_thumb ? 'CHANGE' : 'SELECT IMAGE'); ?>
                            </button>
                            <?php if ($featured_thumb): ?>
                            <button type="button" onclick="clearFeatured('cat')" class="btn-secondary" style="font-size:11px;padding:5px 12px;color:var(--dim);">REMOVE</button>
                            <?php endif; ?>
                        </div>
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

<!-- FEATURED IMAGE PICKER MODAL -->
<div id="featured-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9000;overflow-y:auto;">
    <div style="background:var(--bg);margin:40px auto;max-width:800px;border-radius:4px;border:1px solid var(--border);padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;">SELECT FEATURED IMAGE</span>
            <button type="button" onclick="closeFeaturedPicker()" style="background:none;border:none;color:var(--dim);font-size:20px;cursor:pointer;line-height:1;">×</button>
        </div>
        <input type="text" id="featured-search" placeholder="Search posts…" oninput="loadFeaturedPosts(this.value)"
               style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:3px;background:var(--input-bg);color:var(--text);font-size:13px;margin-bottom:12px;box-sizing:border-box;">
        <div id="featured-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;max-height:480px;overflow-y:auto;"></div>
    </div>
</div>

<script>
var _featuredCtx   = null;
var _featuredBase  = <?php echo json_encode(BASE_URL); ?>;

function openFeaturedPicker(ctx) {
    _featuredCtx = ctx;
    document.getElementById('featured-modal').style.display = 'block';
    document.getElementById('featured-search').value = '';
    loadFeaturedPosts('');
}
function closeFeaturedPicker() {
    document.getElementById('featured-modal').style.display = 'none';
}
function clearFeatured(ctx) {
    document.getElementById(ctx + '-featured-id').value = '0';
    document.getElementById(ctx + '-featured-preview').innerHTML =
        '<div style="width:80px;height:80px;background:var(--card-bg);border:1px dashed var(--border);border-radius:3px;display:flex;align-items:center;justify-content:center;">'
        + '<span class="dim" style="font-size:10px;text-align:center;padding:4px;">NO IMAGE</span></div>'
        + '<div style="display:flex;gap:8px;margin-top:8px;">'
        + '<button type="button" onclick="openFeaturedPicker(\'' + ctx + '\')" class="btn-secondary" style="font-size:11px;padding:5px 12px;">SELECT IMAGE</button>'
        + '</div>';
}
function loadFeaturedPosts(q) {
    var grid = document.getElementById('featured-grid');
    grid.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;">Loading…</p>';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'smack-cats.php?ajax=posts&q=' + encodeURIComponent(q), true);
    xhr.onload = function () {
        if (xhr.status !== 200) return;
        var posts = JSON.parse(xhr.responseText);
        if (!posts.length) { grid.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;">No posts found.</p>'; return; }
        var frag = document.createDocumentFragment();
        posts.forEach(function (p) {
            var thumb = p.img_thumb_square || p.img_thumb_aspect || p.img_file;
            var src   = thumb ? _featuredBase + thumb.replace(/^\//, '') : '';
            var div = document.createElement('div');
            div.style.cssText = 'cursor:pointer;border:2px solid transparent;border-radius:3px;overflow:hidden;aspect-ratio:1;background:#111;';
            div.dataset.id    = p.id;
            div.dataset.src   = src;
            div.dataset.title = p.title;
            div.addEventListener('click', function () {
                selectFeatured(this.dataset.id, this.dataset.src, this.dataset.title);
            });
            if (src) {
                var img = document.createElement('img');
                img.src = src;
                img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                img.loading = 'lazy';
                div.appendChild(img);
            } else {
                var label = document.createElement('div');
                label.style.cssText = 'display:flex;align-items:center;justify-content:center;height:100%;color:var(--dim);font-size:10px;padding:4px;text-align:center;';
                label.textContent = p.title;
                div.appendChild(label);
            }
            frag.appendChild(div);
        });
        grid.innerHTML = '';
        grid.appendChild(frag);
    };
    xhr.send();
}
function selectFeatured(id, thumb, title) {
    var ctx = _featuredCtx;
    document.getElementById(ctx + '-featured-id').value = id;
    var wrap = document.getElementById(ctx + '-featured-preview');
    wrap.innerHTML = '';
    var img = document.createElement('img');
    img.src = thumb;
    img.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:3px;border:1px solid var(--border);';
    img.alt = 'Featured image';
    var span = document.createElement('span');
    span.className = 'dim';
    span.style.cssText = 'display:block;font-size:11px;margin-top:4px;';
    span.textContent = title;
    var btns = document.createElement('div');
    btns.style.cssText = 'display:flex;gap:8px;margin-top:8px;';
    var btnChange = document.createElement('button');
    btnChange.type = 'button';
    btnChange.className = 'btn-secondary';
    btnChange.style.cssText = 'font-size:11px;padding:5px 12px;';
    btnChange.textContent = 'CHANGE';
    btnChange.addEventListener('click', function () { openFeaturedPicker(ctx); });
    var btnRemove = document.createElement('button');
    btnRemove.type = 'button';
    btnRemove.className = 'btn-secondary';
    btnRemove.style.cssText = 'font-size:11px;padding:5px 12px;color:var(--dim);';
    btnRemove.textContent = 'REMOVE';
    btnRemove.addEventListener('click', function () { clearFeatured(ctx); });
    btns.appendChild(btnChange);
    btns.appendChild(btnRemove);
    wrap.appendChild(img);
    wrap.appendChild(span);
    wrap.appendChild(btns);
    closeFeaturedPicker();
}
// Close modal on backdrop click
document.getElementById('featured-modal').addEventListener('click', function (e) {
    if (e.target === this) closeFeaturedPicker();
});
</script>
<?php // ===== SNAPSMACK EOF =====
