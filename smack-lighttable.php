<?php
/**
 * SNAPSMACK - Light Table
 *
 * Drag-and-drop bulk organisation workbench. Albums, Categories, Collections.
 * Desktop-only (viewport < 1024px or pointer:coarse = block screen).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';

// ---------------------------------------------------------------------------
// AJAX — must be first, before any output
// ---------------------------------------------------------------------------

if (!empty($_POST['action']) || !empty($_GET['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // -----------------------------------------------------------------------
    // load_containers — return all albums, cats, collections with counts
    // -----------------------------------------------------------------------
    if ($action === 'load_containers') {
        $albums = $pdo->query(
            "SELECT a.id, a.album_name AS name, COUNT(m.image_id) AS cnt
               FROM snap_albums a
          LEFT JOIN snap_image_album_map m ON m.album_id = a.id
           GROUP BY a.id
           ORDER BY a.album_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $cats = $pdo->query(
            "SELECT c.id, c.cat_name AS name, COUNT(m.image_id) AS cnt
               FROM snap_categories c
          LEFT JOIN snap_image_cat_map m ON m.cat_id = c.id
           GROUP BY c.id
           ORDER BY c.cat_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $collections = $pdo->query(
            "SELECT c.id, c.title AS name, COUNT(ci.image_id) AS cnt
               FROM snap_collections c
          LEFT JOIN snap_collection_items ci ON ci.collection_id = c.id
           GROUP BY c.id
           ORDER BY c.title"
        )->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'          => true,
            'albums'      => $albums,
            'cats'        => $cats,
            'collections' => $collections,
            'col_cap'     => 30,
        ]);
        exit;
    }

    // -----------------------------------------------------------------------
    // load_photos — paginated photo list with optional filters
    // -----------------------------------------------------------------------
    if ($action === 'load_photos') {
        $page     = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
        $per_page = 60;
        $offset   = ($page - 1) * $per_page;

        $where   = ['i.img_status != "deleted"'];
        $params  = [];

        // Membership filters
        $filter = $_GET['filter'] ?? $_POST['filter'] ?? '';
        if ($filter === 'no_album') {
            $where[] = 'i.id NOT IN (SELECT image_id FROM snap_image_album_map)';
        } elseif ($filter === 'no_cat') {
            $where[] = 'i.id NOT IN (SELECT image_id FROM snap_image_cat_map)';
        } elseif ($filter === 'no_collection') {
            $where[] = 'i.id NOT IN (SELECT image_id FROM snap_collection_items)';
        }

        // Container filters
        $in_album      = (int)($_GET['in_album']      ?? $_POST['in_album']      ?? 0);
        $in_cat        = (int)($_GET['in_cat']         ?? $_POST['in_cat']        ?? 0);
        $in_collection = (int)($_GET['in_collection']  ?? $_POST['in_collection'] ?? 0);

        if ($in_album > 0) {
            $where[]  = 'i.id IN (SELECT image_id FROM snap_image_album_map WHERE album_id = ?)';
            $params[] = $in_album;
        }
        if ($in_cat > 0) {
            $where[]  = 'i.id IN (SELECT image_id FROM snap_image_cat_map WHERE cat_id = ?)';
            $params[] = $in_cat;
        }
        if ($in_collection > 0) {
            $where[]  = 'i.id IN (SELECT image_id FROM snap_collection_items WHERE collection_id = ?)';
            $params[] = $in_collection;
        }

        // Date filters
        $date_from = $_GET['date_from'] ?? $_POST['date_from'] ?? '';
        $date_to   = $_GET['date_to']   ?? $_POST['date_to']   ?? '';
        if ($date_from !== '') {
            $where[]  = 'i.img_date >= ?';
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to !== '') {
            $where[]  = 'i.img_date <= ?';
            $params[] = $date_to . ' 23:59:59';
        }

        // Free-text search
        $q = trim($_GET['q'] ?? $_POST['q'] ?? '');
        if ($q !== '') {
            $where[]  = '(i.img_title LIKE ? OR i.img_description LIKE ? OR i.img_tags LIKE ?)';
            $like     = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        // Total count
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images i WHERE $where_sql");
        $count_stmt->execute($params);
        $total = (int)$count_stmt->fetchColumn();

        // Photo rows
        $params_page   = $params;
        $params_page[] = $per_page;
        $params_page[] = $offset;

        $stmt = $pdo->prepare(
            "SELECT i.id, i.img_title, i.img_file, i.img_width, i.img_height, i.img_date,
                    i.img_thumb_aspect,
                    (SELECT COUNT(*) FROM snap_image_album_map WHERE image_id = i.id) AS album_cnt,
                    (SELECT COUNT(*) FROM snap_image_cat_map   WHERE image_id = i.id) AS cat_cnt,
                    (SELECT COUNT(*) FROM snap_collection_items WHERE image_id = i.id) AS col_cnt
               FROM snap_images i
              WHERE $where_sql
              ORDER BY i.img_date DESC, i.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute($params_page);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build thumb URLs
        $photos = [];
        foreach ($rows as $r) {
            $f    = $r['img_file'];
            $dir  = dirname($f);
            $base = basename($f);

            // Prefer aspect thumb, fall back to original
            $asp_path  = $dir . '/thumbs/a_' . $base;
            $thumb_url = file_exists($asp_path)
                ? '/' . ltrim($asp_path, '/')
                : '/' . ltrim($f, '/');

            // Dimensions for justified grid
            $w = (int)($r['img_width']  ?: 4);
            $h = (int)($r['img_height'] ?: 3);

            $photos[] = [
                'id'        => (int)$r['id'],
                'title'     => $r['img_title'],
                'thumb'     => $thumb_url,
                'url'       => '/' . ltrim($f, '/'),
                'w'         => $w,
                'h'         => $h,
                'album_cnt' => (int)$r['album_cnt'],
                'cat_cnt'   => (int)$r['cat_cnt'],
                'col_cnt'   => (int)$r['col_cnt'],
            ];
        }

        echo json_encode([
            'ok'       => true,
            'photos'   => $photos,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int)ceil($total / $per_page),
        ]);
        exit;
    }

    // -----------------------------------------------------------------------
    // get_memberships — all memberships for a single photo
    // -----------------------------------------------------------------------
    if ($action === 'get_memberships') {
        $image_id = (int)($_POST['image_id'] ?? $_GET['image_id'] ?? 0);
        if (!$image_id) { echo json_encode(['ok' => false, 'err' => 'missing image_id']); exit; }

        $albums = $pdo->prepare(
            "SELECT a.id, a.album_name AS name
               FROM snap_albums a
               JOIN snap_image_album_map m ON m.album_id = a.id
              WHERE m.image_id = ?"
        );
        $albums->execute([$image_id]);

        $cats = $pdo->prepare(
            "SELECT c.id, c.cat_name AS name
               FROM snap_categories c
               JOIN snap_image_cat_map m ON m.cat_id = c.id
              WHERE m.image_id = ?"
        );
        $cats->execute([$image_id]);

        $cols = $pdo->prepare(
            "SELECT c.id, c.title AS name
               FROM snap_collections c
               JOIN snap_collection_items ci ON ci.collection_id = c.id
              WHERE ci.image_id = ?"
        );
        $cols->execute([$image_id]);

        echo json_encode([
            'ok'          => true,
            'albums'      => $albums->fetchAll(PDO::FETCH_ASSOC),
            'cats'        => $cats->fetchAll(PDO::FETCH_ASSOC),
            'collections' => $cols->fetchAll(PDO::FETCH_ASSOC),
        ]);
        exit;
    }

    // -----------------------------------------------------------------------
    // add_to_album — batch add array of image IDs to album
    // -----------------------------------------------------------------------
    if ($action === 'add_to_album') {
        $album_id  = (int)($_POST['container_id'] ?? 0);
        $image_ids = array_map('intval', (array)($_POST['image_ids'] ?? []));
        if (!$album_id || empty($image_ids)) {
            echo json_encode(['ok' => false, 'err' => 'missing params']); exit;
        }
        $added = 0;
        $stmt  = $pdo->prepare(
            "INSERT IGNORE INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)"
        );
        foreach ($image_ids as $iid) {
            if ($iid < 1) continue;
            $stmt->execute([$iid, $album_id]);
            $added += $stmt->rowCount();
        }
        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_image_album_map WHERE album_id = ?");
        $cnt_stmt->execute([$album_id]);
        echo json_encode(['ok' => true, 'added' => $added, 'count' => (int)$cnt_stmt->fetchColumn()]);
        exit;
    }

    // -----------------------------------------------------------------------
    // add_to_cat — batch add array of image IDs to category
    // -----------------------------------------------------------------------
    if ($action === 'add_to_cat') {
        $cat_id    = (int)($_POST['container_id'] ?? 0);
        $image_ids = array_map('intval', (array)($_POST['image_ids'] ?? []));
        if (!$cat_id || empty($image_ids)) {
            echo json_encode(['ok' => false, 'err' => 'missing params']); exit;
        }
        $added = 0;
        $stmt  = $pdo->prepare(
            "INSERT IGNORE INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)"
        );
        foreach ($image_ids as $iid) {
            if ($iid < 1) continue;
            $stmt->execute([$iid, $cat_id]);
            $added += $stmt->rowCount();
        }
        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_image_cat_map WHERE cat_id = ?");
        $cnt_stmt->execute([$cat_id]);
        echo json_encode(['ok' => true, 'added' => $added, 'count' => (int)$cnt_stmt->fetchColumn()]);
        exit;
    }

    // -----------------------------------------------------------------------
    // add_to_collection — batch add, hard cap 30
    // -----------------------------------------------------------------------
    if ($action === 'add_to_collection') {
        $col_id    = (int)($_POST['container_id'] ?? 0);
        $image_ids = array_map('intval', (array)($_POST['image_ids'] ?? []));
        if (!$col_id || empty($image_ids)) {
            echo json_encode(['ok' => false, 'err' => 'missing params']); exit;
        }

        $cap      = 30;
        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_collection_items WHERE collection_id = ?");
        $cnt_stmt->execute([$col_id]);
        $current  = (int)$cnt_stmt->fetchColumn();

        $added    = 0;
        $refused  = [];
        $dupes    = [];

        $pos_stmt  = $pdo->prepare(
            "SELECT COALESCE(MAX(position), 0) + 1 FROM snap_collection_items WHERE collection_id = ?"
        );
        $ins_stmt  = $pdo->prepare(
            "INSERT IGNORE INTO snap_collection_items (collection_id, image_id, position) VALUES (?, ?, ?)"
        );
        $exist_stmt = $pdo->prepare(
            "SELECT 1 FROM snap_collection_items WHERE collection_id = ? AND image_id = ?"
        );

        foreach ($image_ids as $iid) {
            if ($iid < 1) continue;

            // Already a member?
            $exist_stmt->execute([$col_id, $iid]);
            if ($exist_stmt->fetchColumn()) {
                $dupes[] = $iid;
                continue;
            }

            // Cap check
            if ($current >= $cap) {
                $refused[] = $iid;
                continue;
            }

            $pos_stmt->execute([$col_id]);
            $pos = (int)$pos_stmt->fetchColumn();
            $ins_stmt->execute([$col_id, $iid, $pos]);
            if ($ins_stmt->rowCount()) {
                $added++;
                $current++;
            }
        }

        $cnt_stmt->execute([$col_id]);
        $new_count = (int)$cnt_stmt->fetchColumn();

        echo json_encode([
            'ok'          => true,
            'added'       => $added,
            'refused'     => $refused,
            'dupes'       => $dupes,
            'count'       => $new_count,
            'cap'         => $cap,
            'cap_reached' => ($new_count >= $cap),
        ]);
        exit;
    }

    // -----------------------------------------------------------------------
    // swap_collection — remove one image, insert another (Shift+drop on full)
    // -----------------------------------------------------------------------
    if ($action === 'swap_collection') {
        $col_id   = (int)($_POST['container_id'] ?? 0);
        $remove   = (int)($_POST['remove_id']    ?? 0);
        $add      = (int)($_POST['add_id']        ?? 0);
        if (!$col_id || !$remove || !$add) {
            echo json_encode(['ok' => false, 'err' => 'missing params']); exit;
        }

        $pdo->beginTransaction();
        try {
            // Get position of outgoing image for slot reuse
            $pos_stmt = $pdo->prepare(
                "SELECT position FROM snap_collection_items WHERE collection_id = ? AND image_id = ?"
            );
            $pos_stmt->execute([$col_id, $remove]);
            $pos = $pos_stmt->fetchColumn();

            $pdo->prepare(
                "DELETE FROM snap_collection_items WHERE collection_id = ? AND image_id = ?"
            )->execute([$col_id, $remove]);

            if ($pos === false) {
                // Use next available position
                $pos_next = $pdo->prepare(
                    "SELECT COALESCE(MAX(position), 0) + 1 FROM snap_collection_items WHERE collection_id = ?"
                );
                $pos_next->execute([$col_id]);
                $pos = (int)$pos_next->fetchColumn();
            }

            $pdo->prepare(
                "INSERT IGNORE INTO snap_collection_items (collection_id, image_id, position) VALUES (?, ?, ?)"
            )->execute([$col_id, $add, (int)$pos]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
            exit;
        }

        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_collection_items WHERE collection_id = ?");
        $cnt_stmt->execute([$col_id]);
        echo json_encode(['ok' => true, 'count' => (int)$cnt_stmt->fetchColumn(), 'cap' => 30]);
        exit;
    }

    // -----------------------------------------------------------------------
    // remove_from — single removal (album, cat, or collection)
    // -----------------------------------------------------------------------
    if ($action === 'remove_from') {
        $type         = $_POST['type']         ?? '';
        $container_id = (int)($_POST['container_id'] ?? 0);
        $image_id     = (int)($_POST['image_id']     ?? 0);
        if (!$container_id || !$image_id) {
            echo json_encode(['ok' => false, 'err' => 'missing params']); exit;
        }

        if ($type === 'album') {
            $pdo->prepare(
                "DELETE FROM snap_image_album_map WHERE album_id = ? AND image_id = ?"
            )->execute([$container_id, $image_id]);
        } elseif ($type === 'cat') {
            $pdo->prepare(
                "DELETE FROM snap_image_cat_map WHERE cat_id = ? AND image_id = ?"
            )->execute([$container_id, $image_id]);
        } elseif ($type === 'collection') {
            $pdo->prepare(
                "DELETE FROM snap_collection_items WHERE collection_id = ? AND image_id = ?"
            )->execute([$container_id, $image_id]);
        } else {
            echo json_encode(['ok' => false, 'err' => 'unknown type']); exit;
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'err' => 'unknown action']);
    exit;
}

// ---------------------------------------------------------------------------
// Page render
// ---------------------------------------------------------------------------
include 'core/admin-header.php';
?>
<div class="admin-layout">
<?php include 'core/sidebar.php'; ?>
<div class="main sorter-main" id="sorter-root">

<!-- Desktop block — shown by JS if viewport too small or touch pointer -->
<div class="sorter-touch-block" id="sorter-touch-block" style="display:none;">
    <div class="sorter-touch-msg">
        <h2>GET YOUR ASS TO A COMPUTER.</h2>
        <p>The Light Table is a desktop tool. Drag and drop with thousands of photos on a touchscreen is suffering. Come back when you're at a real keyboard.</p>
    </div>
</div>

<!-- Sorter shell — hidden until JS initialises -->
<div class="sorter-shell" id="sorter-shell" style="display:none;">

    <!-- Top bar -->
    <div class="sorter-topbar" id="sorter-topbar">
        <div class="sorter-topbar-left">
            <button class="sorter-rail-toggle" id="sorter-rail-toggle" title="Toggle container panel">☰</button>
            <span class="sorter-title">LIGHT TABLE</span>
        </div>
        <div class="sorter-topbar-filters" id="sorter-topbar-filters">
            <select id="sorter-filter-membership" title="Membership filter">
                <option value="">All photos</option>
                <option value="no_album">In no album</option>
                <option value="no_cat">In no category</option>
                <option value="no_collection">In no collection</option>
            </select>
            <select id="sorter-filter-album" title="In album...">
                <option value="">Album…</option>
            </select>
            <select id="sorter-filter-cat" title="In category...">
                <option value="">Category…</option>
            </select>
            <select id="sorter-filter-collection" title="In collection...">
                <option value="">Collection…</option>
            </select>
            <input type="text" id="sorter-filter-q" placeholder="Search…" autocomplete="off">
            <input type="date" id="sorter-filter-date-from" title="From date">
            <input type="date" id="sorter-filter-date-to" title="To date">
            <button id="sorter-filter-apply">Filter</button>
        </div>
        <div class="sorter-topbar-right">
            <span class="sorter-sel-count" id="sorter-sel-count" style="display:none;"></span>
            <button class="sorter-sel-clear" id="sorter-sel-clear" style="display:none;">Clear selection</button>
            <span class="sorter-photo-count" id="sorter-photo-count"></span>
        </div>
    </div>

    <!-- Body: left rail + centre grid -->
    <div class="sorter-body" id="sorter-body">

        <!-- Left rail -->
        <div class="sorter-rail" id="sorter-rail">
            <div class="sorter-rail-inner">

                <!-- ALBUMS section -->
                <div class="nav-section open sorter-section" data-type="album" data-section="albums">
                    <button class="nav-section-toggle sorter-section-toggle" type="button">
                        ALBUMS <span class="sorter-section-chevron">▾</span>
                    </button>
                    <ul class="nav-section-links sorter-targets" id="sorter-targets-album"></ul>
                </div>

                <!-- CATEGORIES section -->
                <div class="nav-section sorter-section" data-type="cat" data-section="cats">
                    <button class="nav-section-toggle sorter-section-toggle" type="button">
                        CATEGORIES <span class="sorter-section-chevron">▾</span>
                    </button>
                    <ul class="nav-section-links sorter-targets" id="sorter-targets-cat"></ul>
                </div>

                <!-- COLLECTIONS section -->
                <div class="nav-section sorter-section" data-type="collection" data-section="collections">
                    <button class="nav-section-toggle sorter-section-toggle" type="button">
                        COLLECTIONS <span class="sorter-section-chevron">▾</span>
                    </button>
                    <ul class="nav-section-links sorter-targets" id="sorter-targets-collection"></ul>
                </div>

            </div>
        </div><!-- /.sorter-rail -->

        <!-- Centre grid -->
        <div class="sorter-grid-wrap" id="sorter-grid-wrap">
            <div class="sorter-grid-loading" id="sorter-grid-loading">Loading photos…</div>
            <div class="justified-grid sorter-grid" id="sorter-grid"></div>
            <div class="sorter-pagination" id="sorter-pagination"></div>
        </div>

    </div><!-- /.sorter-body -->

</div><!-- /.sorter-shell -->

<!-- Drag ghost -->
<div class="sorter-drag-ghost" id="sorter-drag-ghost" style="display:none;"></div>

<!-- Context menu -->
<div class="sorter-context-menu" id="sorter-context-menu" style="display:none;">
    <ul>
        <li data-action="memberships">Show memberships</li>
        <li data-action="remove_from" class="sorter-ctx-submenu-trigger">Remove from ▶
            <ul class="sorter-ctx-submenu" id="sorter-ctx-remove-list"></ul>
        </li>
        <li data-action="edit_photo">Edit photo</li>
        <li data-action="open_original">Open original</li>
        <li data-action="delete_photo" class="sorter-ctx-danger">Delete photo</li>
    </ul>
</div>

<!-- Membership popover -->
<div class="sorter-membership-popover" id="sorter-membership-popover" style="display:none;">
    <div class="sorter-popover-header">
        <span>Memberships</span>
        <button class="sorter-popover-close" id="sorter-popover-close">✕</button>
    </div>
    <div class="sorter-popover-body" id="sorter-popover-body"></div>
</div>

<!-- Swap modal (Shift+drop on full collection) -->
<div class="sorter-overlay" id="sorter-swap-overlay" style="display:none;">
    <div class="sorter-swap-modal" id="sorter-swap-modal">
        <div class="sorter-swap-header">
            <h3 id="sorter-swap-title">Collection is full</h3>
            <button class="sorter-swap-close" id="sorter-swap-close">✕</button>
        </div>
        <p id="sorter-swap-desc">To add this image, choose which existing image to remove.</p>
        <div class="sorter-swap-grid" id="sorter-swap-grid"></div>
        <div class="sorter-swap-footer">
            <button id="sorter-swap-cancel">Cancel</button>
        </div>
    </div>
</div>

</div><!-- /.main.sorter-main -->
</div><!-- /.admin-layout -->

<style>
/* ---- Sorter layout -------------------------------------------------- */
.sorter-main           { padding: 0; display: flex; flex-direction: column; height: calc(100vh - 56px); overflow: hidden; }
.sorter-shell          { display: flex; flex-direction: column; height: 100%; }
.sorter-touch-block    { display: flex; align-items: center; justify-content: center; height: 100%; padding: 2rem; }
.sorter-touch-msg      { text-align: center; max-width: 480px; }
.sorter-touch-msg h2   { font-size: 1.6rem; margin-bottom: 1rem; }

/* Top bar */
.sorter-topbar         { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 1rem; border-bottom: 1px solid var(--border); background: var(--bg-secondary); flex-shrink: 0; flex-wrap: wrap; }
.sorter-topbar-left    { display: flex; align-items: center; gap: 0.5rem; }
.sorter-title          { font-weight: 700; letter-spacing: 0.08em; font-size: 0.9rem; color: var(--accent); }
.sorter-rail-toggle    { background: none; border: 1px solid var(--border); border-radius: 3px; padding: 0.25rem 0.5rem; cursor: pointer; color: var(--text); }
.sorter-topbar-filters { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; flex: 1; }
.sorter-topbar-filters select,
.sorter-topbar-filters input[type="text"],
.sorter-topbar-filters input[type="date"] { padding: 0.25rem 0.4rem; border: 1px solid var(--border); border-radius: 3px; background: var(--bg); color: var(--text); font-size: 0.8rem; }
.sorter-topbar-filters select             { max-width: 140px; }
#sorter-filter-apply   { padding: 0.25rem 0.75rem; background: var(--accent); color: #fff; border: none; border-radius: 3px; cursor: pointer; font-size: 0.8rem; }
.sorter-topbar-right   { display: flex; align-items: center; gap: 0.5rem; margin-left: auto; }
.sorter-sel-count      { font-size: 0.82rem; font-weight: 600; color: var(--accent); }
.sorter-sel-clear      { font-size: 0.78rem; padding: 0.2rem 0.5rem; background: none; border: 1px solid var(--border); border-radius: 3px; cursor: pointer; color: var(--text); }
.sorter-photo-count    { font-size: 0.78rem; color: var(--text-muted); }

/* Body */
.sorter-body           { display: flex; flex: 1; overflow: hidden; }

/* Left rail */
.sorter-rail           { width: 240px; min-width: 240px; border-right: 1px solid var(--border); overflow-y: auto; background: var(--bg-secondary); transform: translateX(-240px); transition: transform 0.25s ease; flex-shrink: 0; }
.sorter-rail.open      { transform: translateX(0); }
.sorter-rail-inner     { padding: 0.5rem 0; }

/* Reuse admin nav section classes — override widths/padding for sorter */
.sorter-rail .nav-section-toggle { width: 100%; text-align: left; padding: 0.5rem 0.75rem; background: none; border: none; cursor: pointer; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.06em; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; }
.sorter-rail .nav-section-toggle:hover { color: var(--text); }
.sorter-rail .nav-section-links  { list-style: none; margin: 0; padding: 0 0 0.5rem 0; display: none; }
.sorter-rail .nav-section.open .nav-section-links { display: block; }

/* Drop targets */
.sorter-target         { padding: 0.35rem 0.75rem 0.35rem 1.1rem; cursor: default; font-size: 0.82rem; border-radius: 2px; margin: 1px 4px; display: flex; justify-content: space-between; align-items: center; transition: background 0.1s; }
.sorter-target:hover   { background: var(--hover); }
.sorter-target.drag-over  { background: var(--accent); color: #fff; }
.sorter-target.drag-over .sorter-target-cnt { color: rgba(255,255,255,0.75); }
.sorter-target.flash-ok   { animation: sorter-flash-ok 0.45s ease; }
.sorter-target.flash-err  { animation: sorter-flash-err 0.45s ease; }
.sorter-target-name    { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sorter-target-cnt     { font-size: 0.72rem; color: var(--text-muted); margin-left: 0.4rem; flex-shrink: 0; }

@keyframes sorter-flash-ok  { 0%,100%{background:transparent} 30%{background:#2a7a2a;color:#fff} }
@keyframes sorter-flash-err { 0%,100%{background:transparent} 20%,60%{background:#8b1a1a;color:#fff} 40%,80%{background:transparent} }

/* Grid area */
.sorter-grid-wrap      { flex: 1; overflow-y: auto; padding: 0.75rem; position: relative; }
.sorter-grid-loading   { padding: 2rem; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

/* Photo cards */
.justified-grid        { width: 100%; }
.justified-item        { position: relative; cursor: pointer; overflow: hidden; border-radius: 2px; }
.justified-item img    { display: block; width: 100%; height: 100%; object-fit: cover; pointer-events: none; user-select: none; }
.justified-item.selected::after { content: ''; position: absolute; inset: 0; border: 3px solid var(--accent); pointer-events: none; border-radius: 2px; }
.justified-item.selected .sorter-card-check { display: flex; }
.sorter-card-check     { display: none; position: absolute; top: 4px; left: 4px; width: 18px; height: 18px; background: var(--accent); border-radius: 50%; align-items: center; justify-content: center; font-size: 10px; color: #fff; pointer-events: none; }
.sorter-card-badge     { position: absolute; bottom: 4px; right: 4px; background: rgba(0,0,0,0.65); color: #fff; font-size: 0.68rem; padding: 1px 4px; border-radius: 2px; pointer-events: none; line-height: 1.4; }
.sorter-card-badge.empty { display: none; }
.justified-item.drag-source { opacity: 0.4; }

/* Drag ghost */
.sorter-drag-ghost     { position: fixed; pointer-events: none; z-index: 9999; border-radius: 3px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.5); transform: translate(-50%, -50%); }
.sorter-drag-ghost img { display: block; width: 72px; height: 72px; object-fit: cover; }
.sorter-ghost-count    { position: absolute; top: 2px; right: 2px; background: var(--accent); color: #fff; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; }

/* Context menu */
.sorter-context-menu   { position: fixed; z-index: 8000; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 4px; box-shadow: 0 4px 16px rgba(0,0,0,0.3); min-width: 170px; }
.sorter-context-menu ul { list-style: none; margin: 0; padding: 0.25rem 0; }
.sorter-context-menu li { padding: 0.4rem 0.9rem; font-size: 0.82rem; cursor: pointer; position: relative; white-space: nowrap; }
.sorter-context-menu li:hover { background: var(--hover); }
.sorter-ctx-danger     { color: #c0392b; }
.sorter-ctx-submenu    { display: none; position: absolute; left: 100%; top: 0; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 4px; box-shadow: 0 4px 16px rgba(0,0,0,0.3); min-width: 160px; list-style: none; margin: 0; padding: 0.25rem 0; }
.sorter-ctx-submenu-trigger:hover .sorter-ctx-submenu { display: block; }
.sorter-ctx-submenu li { padding: 0.35rem 0.8rem; font-size: 0.8rem; cursor: pointer; }
.sorter-ctx-submenu li:hover { background: var(--hover); }

/* Membership popover */
.sorter-membership-popover { position: fixed; z-index: 8000; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.35); width: 280px; max-height: 420px; overflow-y: auto; }
.sorter-popover-header { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0.9rem; border-bottom: 1px solid var(--border); font-weight: 600; font-size: 0.85rem; }
.sorter-popover-close  { background: none; border: none; cursor: pointer; font-size: 1rem; color: var(--text-muted); }
.sorter-popover-body   { padding: 0.5rem 0.9rem 0.75rem; font-size: 0.82rem; }
.sorter-popover-type   { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; color: var(--text-muted); margin: 0.6rem 0 0.2rem; }
.sorter-popover-item   { display: flex; justify-content: space-between; align-items: center; padding: 0.25rem 0; border-bottom: 1px solid var(--border); }
.sorter-popover-item:last-child { border-bottom: none; }
.sorter-popover-remove { background: none; border: none; cursor: pointer; color: #c0392b; font-size: 0.78rem; padding: 0; }

/* Swap modal */
.sorter-overlay        { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9000; display: flex; align-items: center; justify-content: center; }
.sorter-swap-modal     { background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 6px; padding: 1.25rem; max-width: 560px; width: 90vw; max-height: 80vh; overflow-y: auto; }
.sorter-swap-header    { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.sorter-swap-header h3 { margin: 0; font-size: 1rem; }
.sorter-swap-close     { background: none; border: none; cursor: pointer; font-size: 1.1rem; color: var(--text-muted); }
.sorter-swap-grid      { display: flex; flex-wrap: wrap; gap: 6px; margin: 0.75rem 0; }
.sorter-swap-thumb     { width: 72px; height: 72px; cursor: pointer; border-radius: 3px; overflow: hidden; border: 2px solid transparent; position: relative; }
.sorter-swap-thumb:hover { border-color: var(--accent); }
.sorter-swap-thumb img { width: 100%; height: 100%; object-fit: cover; }
.sorter-swap-footer    { text-align: right; }
.sorter-swap-footer button { padding: 0.35rem 0.9rem; background: var(--bg); border: 1px solid var(--border); border-radius: 3px; cursor: pointer; font-size: 0.82rem; color: var(--text); }

/* Pagination */
.sorter-pagination     { display: flex; gap: 0.4rem; justify-content: center; padding: 1rem 0; flex-wrap: wrap; }
.sorter-pagination button { padding: 0.3rem 0.65rem; border: 1px solid var(--border); border-radius: 3px; background: var(--bg); cursor: pointer; font-size: 0.82rem; color: var(--text); }
.sorter-pagination button.active { background: var(--accent); color: #fff; border-color: var(--accent); }
.sorter-pagination button:hover:not(.active) { background: var(--hover); }

/* Rail collapsed */
.sorter-body.rail-hidden .sorter-rail { display: none; }
</style>

<script src="assets/js/fjGallery.min.js"></script>
<script src="assets/js/ss-engine-lighttable.js"></script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
                                                                                                                                          