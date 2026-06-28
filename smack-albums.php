<?php
/**
 * SNAPSMACK - Album (mission) management
 *
 * Provides creation, editing, and deletion of photo album collections.
 * Maintains associations between albums and their contained images.
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
    $pdo->query("SELECT featured_post_id FROM snap_albums LIMIT 0");
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
    $cat    = (int)($_GET['cat']   ?? 0);
    $album  = (int)($_GET['album'] ?? 0);

    // Optional category / album filters — narrow the picker grid by membership.
    $joins  = '';
    $fbinds = [];                       // [value, PDO type] in placeholder order
    if ($cat > 0) {
        $joins  .= " INNER JOIN snap_image_cat_map cm ON cm.image_id = i.id AND cm.cat_id = ?";
        $fbinds[] = [$cat, PDO::PARAM_INT];
    }
    if ($album > 0) {
        $joins  .= " INNER JOIN snap_image_album_map am ON am.image_id = i.id AND am.album_id = ?";
        $fbinds[] = [$album, PDO::PARAM_INT];
    }

    $rows = $pdo->prepare(
        "SELECT i.id, i.img_title AS title,
                i.img_thumb_square, i.img_thumb_aspect, i.img_file
         FROM snap_images i" . $joins . "
         WHERE i.img_status = 'published' AND i.img_title LIKE ?
         ORDER BY i.img_date DESC
         LIMIT ? OFFSET ?"
    );
    $pos = 1;
    foreach ($fbinds as $fb) { $rows->bindValue($pos++, $fb[0], $fb[1]); }
    $rows->bindValue($pos++, $q,         PDO::PARAM_STR);
    $rows->bindValue($pos++, $limit + 1, PDO::PARAM_INT);
    $rows->bindValue($pos++, $offset,    PDO::PARAM_INT);
    $rows->execute();
    $raw   = $rows->fetchAll(PDO::FETCH_ASSOC);
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
    $name             = trim($_POST['album_name']);
    $desc             = trim($_POST['album_description']);
    $featured_post_id = !empty($_POST['featured_post_id']) ? (int)$_POST['featured_post_id'] : null;

    if (isset($_POST['new_album']) && !empty($name)) {
        $pdo->prepare("INSERT INTO snap_albums (album_name, album_description, featured_post_id) VALUES (?, ?, ?)")
            ->execute([$name, $desc, $featured_post_id]);
        header("Location: smack-albums.php?msg=MISSION+INITIALIZED");
        exit;
    }

    if (isset($_POST['update_album']) && !empty($name)) {
        $id = (int)$_POST['album_id'];
        $pdo->prepare("UPDATE snap_albums SET album_name=?, album_description=?, featured_post_id=? WHERE id=?")
            ->execute([$name, $desc, $featured_post_id, $id]);
        header("Location: smack-albums.php?msg=MISSION+MODIFIED");
        exit;
    }
}

// --- DELETION ---
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM snap_image_album_map WHERE album_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM snap_albums WHERE id = ?")->execute([$id]);
    header("Location: smack-albums.php?msg=MISSION+PURGED");
    exit;
}

// --- EDIT MODE ---
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM snap_albums WHERE id = ?");
    $stmt->execute([$id]);
    $edit_data = $stmt->fetch();
    if ($edit_data) { $edit_mode = true; }
}

$albums = $pdo->query(
    "SELECT a.*, COUNT(m.image_id) as img_count
     FROM snap_albums a
     LEFT JOIN snap_image_album_map m ON a.id = m.album_id
     GROUP BY a.id
     ORDER BY a.album_name ASC"
)->fetchAll();

// Categories for the featured-image picker filter dropdown ($albums reused below).
$pick_cats = $pdo->query(
    "SELECT id, cat_name FROM snap_categories ORDER BY cat_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Fetch featured image thumbnail for currently editing album
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

$page_title = "Mission registry";
include 'core/admin-header.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-featured-picker.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-featured-picker.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>MISSION REGISTRY (ALBUMS)</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
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
                        <input type="text" name="album_name"
                               value="<?php echo $edit_mode ? htmlspecialchars($edit_data['album_name']) : ''; ?>"
                               placeholder="E.G. PROJECT 365, SUMMER 2025" required autofocus>
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <label>MISSION BRIEFING (DESCRIPTION)</label>
                        <textarea name="album_description" placeholder="Technical or artistic intent..." rows="8"><?php echo $edit_mode ? htmlspecialchars($edit_data['album_description'] ?? '') : ''; ?></textarea>
                    </div>

                    <!-- FEATURED IMAGE -->
                    <div class="lens-input-wrapper mt-20">
                        <label>FEATURED IMAGE <span class="field-tip" data-tip="Pick any post — used as the representative thumbnail for this album.">ⓘ</span></label>
                        <input type="hidden" name="featured_post_id" id="album-featured-id"
                               value="<?php echo $edit_mode ? (int)($edit_data['featured_post_id'] ?? 0) : 0; ?>">
                        <div id="album-featured-preview" class="ssfp-preview"></div>
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <button type="submit" class="master-update-btn">
                            <?php echo $edit_mode ? "UPDATE MISSION" : "ADD TO REGISTRY"; ?>
                        </button>
                    </div>

                    <?php if ($edit_mode): ?>
                        <div class="lens-input-wrapper mt-10">
                            <a href="smack-albums.php" class="btn-reset btn-cancel-block">CANCEL EDIT</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex-1">
                <div class="box">
                    <h3>ACTIVE MISSIONS</h3>

                    <?php if (empty($albums)): ?>
                        <p class="dim empty-notice">No missions registered.</p>
                    <?php else: ?>
                        <?php foreach ($albums as $a): ?>
                            <div class="recent-item">
                                <div class="item-details">
                                    <div class="item-text">
                                        <strong><?php echo htmlspecialchars($a['album_name']); ?></strong>
                                        <code class="slug-display">TRANSMISSIONS: <?php echo (int)$a['img_count']; ?></code>
                                        <div class="item-meta">
                                            <?php echo !empty($a['album_description']) ? htmlspecialchars($a['album_description']) : "NO BRIEFING RECORDED."; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="item-actions">
                                    <a href="?edit=<?php echo $a['id']; ?>" class="action-edit">EDIT</a>
                                    <a href="?delete=<?php echo $a['id']; ?>" class="action-delete"
                                       onclick="return confirm('PURGE MISSION?')">DELETE</a>
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
    var prevEl = document.getElementById('album-featured-preview');
    var idEl   = document.getElementById('album-featured-id');
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
        endpoint:      'smack-albums.php?ajax=posts',
        previewEl:     prevEl,
        hiddenInputEl: idEl,
        baseUrl:       <?php echo json_encode(BASE_URL); ?>,
        initialThumb:  initialThumb,
        initialTitle:  initialTitle,
        filters: [
            { param: 'cat', label: 'All categories', options: <?php
                echo json_encode(array_map(fn($c) => [
                    'value' => (int)$c['id'], 'label' => $c['cat_name']
                ], $pick_cats), JSON_UNESCAPED_UNICODE); ?> },
            { param: 'album', label: 'All albums', options: <?php
                echo json_encode(array_map(fn($a) => [
                    'value' => (int)$a['id'], 'label' => $a['album_name']
                ], $albums), JSON_UNESCAPED_UNICODE); ?> }
        ]
    });
});
</script>
<?php // ===== SNAPSMACK EOF =====
