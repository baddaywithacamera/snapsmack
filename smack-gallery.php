<?php
/**
 * SNAPSMACK - Visual Media Gallery
 *
 * Visual DAM (digital asset manager) that replaces the flat archive list.
 * Browse, search, filter, and manage your entire image library from one page.
 * Runs in two modes: standalone admin page or modal picker (launched from
 * the post/page editor via data-gallery-picker attribute).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




require_once 'core/auth-smack.php';

// ── AJAX ENDPOINT ────────────────────────────────────────────────────────────
// Returns JSON for the gallery grid. Handles search, filtering, pagination.
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');

    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(10, min(100, (int)($_GET['per_page'] ?? 50)));
    $offset   = ($page - 1) * $per_page;

    // Build WHERE clauses
    $where   = [];
    $params  = [];

    // Full-text search across title, description, tags
    $search = trim($_GET['q'] ?? '');
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = "(i.img_title LIKE ? OR i.img_description LIKE ? OR EXISTS (
            SELECT 1 FROM snap_image_tags it
            JOIN snap_tags t ON t.id = it.tag_id
            WHERE it.image_id = i.id AND t.tag LIKE ?
        ))";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // Filter: album
    $album_id = (int)($_GET['album'] ?? 0);
    if ($album_id > 0) {
        $where[] = "EXISTS (SELECT 1 FROM snap_image_album_map am WHERE am.image_id = i.id AND am.album_id = ?)";
        $params[] = $album_id;
    }

    // Filter: category
    $cat_id = (int)($_GET['cat'] ?? 0);
    if ($cat_id > 0) {
        $where[] = "EXISTS (SELECT 1 FROM snap_image_cat_map cm WHERE cm.image_id = i.id AND cm.cat_id = ?)";
        $params[] = $cat_id;
    }

    // Filter: status
    $status = $_GET['status'] ?? '';
    if ($status === 'published' || $status === 'draft') {
        $where[] = "i.img_status = ?";
        $params[] = $status;
    }

    // Filter: date range
    $date_from = $_GET['date_from'] ?? '';
    $date_to   = $_GET['date_to'] ?? '';
    if ($date_from !== '') {
        $where[] = "i.img_date >= ?";
        $params[] = $date_from . ' 00:00:00';
    }
    if ($date_to !== '') {
        $where[] = "i.img_date <= ?";
        $params[] = $date_to . ' 23:59:59';
    }

    // Filter: camera (from EXIF JSON)
    $camera = trim($_GET['camera'] ?? '');
    if ($camera !== '') {
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(i.img_exif, '$.camera')) = ?";
        $params[] = $camera;
    }

    // Filter: colour palette
    $colour = trim($_GET['colour'] ?? '');
    if ($colour !== '') {
        $where[] = "i.img_display_options LIKE ?";
        $params[] = '%' . $colour . '%';
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Count total
    $count_sql = "SELECT COUNT(*) FROM snap_images i $where_sql";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    // Fetch page
    $sql = "SELECT i.id, i.img_title, i.img_file, i.img_thumb_square, i.img_thumb_aspect,
                   i.img_date, i.img_status, i.img_width, i.img_height, i.img_exif,
                   i.img_display_options, i.post_id
            FROM snap_images i
            $where_sql
            ORDER BY i.img_date DESC
            LIMIT $per_page OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich with categories and tags
    foreach ($images as &$img) {
        $cat_stmt = $pdo->prepare("SELECT c.cat_name FROM snap_image_cat_map m JOIN snap_categories c ON c.id = m.cat_id WHERE m.image_id = ?");
        $cat_stmt->execute([$img['id']]);
        $img['categories'] = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

        $tag_stmt = $pdo->prepare("SELECT t.tag FROM snap_image_tags it JOIN snap_tags t ON t.id = it.tag_id WHERE it.image_id = ?");
        $tag_stmt->execute([$img['id']]);
        $img['tags'] = $tag_stmt->fetchAll(PDO::FETCH_COLUMN);

        $album_stmt = $pdo->prepare("SELECT a.album_name FROM snap_image_album_map m JOIN snap_albums a ON a.id = m.album_id WHERE m.image_id = ?");
        $album_stmt->execute([$img['id']]);
        $img['albums'] = $album_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Decode EXIF for camera info
        $exif = json_decode($img['img_exif'] ?? '{}', true) ?: [];
        $img['camera'] = $exif['camera'] ?? '';
        $img['lens']   = $exif['lens'] ?? '';

        // Extract palette
        $display = json_decode($img['img_display_options'] ?? '{}', true) ?: [];
        $img['palette'] = $display['palette'] ?? [];

        // Thumbnail path — prefer square thumb, fall back to aspect, then main file
        $dir = dirname($img['img_file']);
        $base = basename($img['img_file']);
        $sq = $dir . '/thumbs/t_' . $base;
        $asp = $dir . '/thumbs/a_' . $base;
        if ($img['img_thumb_square'] && file_exists($img['img_thumb_square'])) {
            $img['thumb'] = $img['img_thumb_square'];
        } elseif (file_exists($sq)) {
            $img['thumb'] = $sq;
        } elseif ($img['img_thumb_aspect'] && file_exists($img['img_thumb_aspect'])) {
            $img['thumb'] = $img['img_thumb_aspect'];
        } elseif (file_exists($asp)) {
            $img['thumb'] = $asp;
        } else {
            $img['thumb'] = $img['img_file'];
        }

        // Remove raw fields from JSON response
        unset($img['img_exif'], $img['img_display_options'], $img['img_thumb_square'], $img['img_thumb_aspect']);
    }
    unset($img);

    echo json_encode([
        'images'   => $images,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => ceil($total / $per_page),
    ]);
    exit;
}

// ── AJAX: QUICK EDIT ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_edit') {
    header('Content-Type: application/json');
    $img_id = (int)($_POST['id'] ?? 0);
    if (!$img_id) {
        echo json_encode(['ok' => false, 'error' => 'No image ID']);
        exit;
    }

    $title  = trim($_POST['title'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['published', 'draft']) ? $_POST['status'] : 'published';

    $pdo->prepare("UPDATE snap_images SET img_title = ?, img_status = ? WHERE id = ?")
        ->execute([$title, $status, $img_id]);

    // Update tags
    if (isset($_POST['tags'])) {
        require_once 'core/snap-tags.php';
        $pdo->prepare("DELETE FROM snap_image_tags WHERE image_id = ?")->execute([$img_id]);
        $tag_list = array_filter(array_map('trim', explode(',', $_POST['tags'])));
        foreach ($tag_list as $tag_text) {
            $tag_id = snap_tag_find_or_create($pdo, $tag_text);
            $pdo->prepare("INSERT IGNORE INTO snap_image_tags (image_id, tag_id) VALUES (?, ?)")
                ->execute([$img_id, $tag_id]);
        }
        snap_tags_recount($pdo);
    }

    // Update categories
    if (isset($_POST['cat_ids'])) {
        $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$img_id]);
        foreach ((array)$_POST['cat_ids'] as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) {
                $pdo->prepare("INSERT IGNORE INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)")
                    ->execute([$img_id, $cid]);
            }
        }
    }

    // Update albums
    if (isset($_POST['album_ids'])) {
        $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$img_id]);
        foreach ((array)$_POST['album_ids'] as $aid) {
            $aid = (int)$aid;
            if ($aid > 0) {
                $pdo->prepare("INSERT IGNORE INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)")
                    ->execute([$img_id, $aid]);
            }
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: BULK OPERATIONS ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    header('Content-Type: application/json');
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    $op  = $_POST['bulk_op'] ?? '';

    if (empty($ids)) {
        echo json_encode(['ok' => false, 'error' => 'No images selected']);
        exit;
    }

    switch ($op) {
        case 'publish':
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE snap_images SET img_status = 'published' WHERE id IN ($placeholders)")
                ->execute($ids);
            break;

        case 'draft':
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE snap_images SET img_status = 'draft' WHERE id IN ($placeholders)")
                ->execute($ids);
            break;

        case 'assign_cat':
            $cat_id = (int)($_POST['bulk_cat_id'] ?? 0);
            if ($cat_id > 0) {
                foreach ($ids as $img_id) {
                    $pdo->prepare("INSERT IGNORE INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)")
                        ->execute([$img_id, $cat_id]);
                }
            }
            break;

        case 'assign_album':
            $album_id = (int)($_POST['bulk_album_id'] ?? 0);
            if ($album_id > 0) {
                foreach ($ids as $img_id) {
                    $pdo->prepare("INSERT IGNORE INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)")
                        ->execute([$img_id, $album_id]);
                }
            }
            break;

        case 'delete':
            foreach ($ids as $img_id) {
                $stmt = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ?");
                $stmt->execute([$img_id]);
                $img = $stmt->fetch();
                if ($img && file_exists($img['img_file'])) {
                    $pi = pathinfo($img['img_file']);
                    $td = $pi['dirname'] . '/thumbs';
                    @unlink($td . '/t_' . $pi['basename']);
                    @unlink($td . '/a_' . $pi['basename']);
                    unlink($img['img_file']);
                }
                $pdo->prepare("DELETE FROM snap_images WHERE id = ?")->execute([$img_id]);
                $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$img_id]);
                $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$img_id]);
                $pdo->prepare("DELETE FROM snap_image_tags WHERE image_id = ?")->execute([$img_id]);
                $pdo->prepare("DELETE FROM snap_comments WHERE img_id = ?")->execute([$img_id]);
            }
            break;
    }

    echo json_encode(['ok' => true, 'count' => count($ids)]);
    exit;
}

// ── AJAX: CAMERA LIST ────────────────────────────────────────────────────────
if (isset($_GET['cameras']) && $_GET['cameras'] === '1') {
    header('Content-Type: application/json');
    $rows = $pdo->query("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(img_exif, '$.camera')) AS cam FROM snap_images WHERE img_exif IS NOT NULL AND img_exif != '{}' ORDER BY cam")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(array_values(array_filter($rows, fn($v) => $v && $v !== 'null' && $v !== '')));
    exit;
}

// ── PAGE RENDER ──────────────────────────────────────────────────────────────
// Fetch categories and albums for filter dropdowns
$categories = $pdo->query("SELECT id, cat_name FROM snap_categories ORDER BY cat_name")->fetchAll(PDO::FETCH_ASSOC);
$albums     = $pdo->query("SELECT id, album_name FROM snap_albums ORDER BY album_name")->fetchAll(PDO::FETCH_ASSOC);
$total_images = (int)$pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn();

$page_title = 'Media Gallery';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<link rel="stylesheet" href="assets/css/ss-engine-gallery.css?v=079L">

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>MEDIA GALLERY</h2>
        <span class="dim" id="gallery-count"><?php echo number_format($total_images); ?> images</span>
    </div>

    <!-- ── FILTER BAR ──────────────────────────────────────────────────── -->
    <div class="gallery-filters" id="gallery-filters">
        <div class="gallery-filter-row">
            <div class="gallery-filter-main">
                <input type="text" id="gal-search" class="gallery-search" placeholder="Search titles, descriptions, tags…" autocomplete="off">

                <select id="gal-album" class="gallery-select">
                    <option value="">All Albums</option>
                    <?php foreach ($albums as $a): ?>
                    <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['album_name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="gal-cat" class="gallery-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['cat_name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="gal-status" class="gallery-select">
                    <option value="">All Status</option>
                    <option value="published">Published</option>
                    <option value="draft">Draft</option>
                </select>

                <select id="gal-camera" class="gallery-select">
                    <option value="">All Cameras</option>
                </select>
            </div>

            <div class="gallery-filter-sub">
                <div class="gallery-daterange">
                    <input type="date" id="gal-date-from" class="gallery-date" title="From date">
                    <span class="gallery-daterange-sep">—</span>
                    <input type="date" id="gal-date-to" class="gallery-date" title="To date">
                </div>
                <button type="button" id="gal-clear" class="gallery-clear-btn" title="Clear all filters">✕ Clear</button>
            </div>
        </div>

        <!-- Bulk actions bar (hidden until selection) -->
        <div class="gallery-bulk-bar" id="gallery-bulk-bar" style="display:none;">
            <span id="gal-sel-count">0</span> selected
            <button type="button" class="btn-smack btn-smack--dim" data-bulk="publish">Publish</button>
            <button type="button" class="btn-smack btn-smack--dim" data-bulk="draft">Draft</button>
            <select id="gal-bulk-cat" class="gallery-select gallery-select--sm">
                <option value="">+ Category</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['cat_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="gal-bulk-album" class="gallery-select gallery-select--sm">
                <option value="">+ Album</option>
                <?php foreach ($albums as $a): ?>
                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['album_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn-smack btn-smack--danger" data-bulk="delete">Delete</button>
            <button type="button" class="btn-smack btn-smack--dim" id="gal-deselect">Deselect All</button>
        </div>
    </div>

    <!-- ── GALLERY GRID ────────────────────────────────────────────────── -->
    <div class="gallery-grid" id="gallery-grid"></div>

    <!-- ── LOAD MORE ───────────────────────────────────────────────────── -->
    <div class="gallery-load-more" id="gallery-load-more" style="display:none;">
        <button type="button" class="btn-smack" id="gal-load-more">Load More</button>
        <span class="dim" id="gal-page-info"></span>
    </div>

    <!-- ── QUICK EDIT PANEL ────────────────────────────────────────────── -->
    <div class="gallery-quickedit" id="gallery-quickedit" style="display:none;">
        <div class="qe-header">
            <h3 id="qe-title-display">Quick Edit</h3>
            <button type="button" class="qe-close" id="qe-close">&times;</button>
        </div>
        <div class="qe-preview">
            <img id="qe-img" src="" alt="">
        </div>
        <div class="qe-fields">
            <input type="hidden" id="qe-id">
            <div class="lens-input-wrapper">
                <label>TITLE</label>
                <input type="text" id="qe-title" class="gallery-input">
            </div>
            <div class="lens-input-wrapper">
                <label>STATUS</label>
                <select id="qe-status" class="gallery-select">
                    <option value="published">Published</option>
                    <option value="draft">Draft</option>
                </select>
            </div>
            <div class="lens-input-wrapper">
                <label>TAGS <span class="field-tip" data-tip="Enter tags separated by commas.">ⓘ</span></label>
                <input type="text" id="qe-tags" class="gallery-input">
            </div>
            <div class="lens-input-wrapper">
                <label>CATEGORIES</label>
                <div class="qe-checks" id="qe-cats">
                    <?php foreach ($categories as $c): ?>
                    <label class="qe-check"><input type="checkbox" value="<?php echo $c['id']; ?>"> <?php echo htmlspecialchars($c['cat_name']); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="lens-input-wrapper">
                <label>ALBUMS</label>
                <div class="qe-checks" id="qe-albums">
                    <?php foreach ($albums as $a): ?>
                    <label class="qe-check"><input type="checkbox" value="<?php echo $a['id']; ?>"> <?php echo htmlspecialchars($a['album_name']); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="qe-meta" id="qe-meta"></div>
            <div class="qe-actions">
                <button type="button" class="btn-smack" id="qe-save">Save</button>
                <a id="qe-edit-link" href="#" class="btn-smack btn-smack--dim">Full Edit</a>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/ss-engine-gallery.js?v=079L"></script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
