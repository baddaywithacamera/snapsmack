<?php
/**
 * SNAPSMACK - Collections management (v0.2 — image-only print folios)
 *
 * v0.2 (0.7.79+): a collection is a hand-curated set of individual images,
 * capped at 30. No more albums-as-members or categories-as-members. Members
 * are snapshots, not live-resolved. Per-collection visibility toggle gates
 * public exposure. See _spec/collections-v0_2.md.
 *
 * Schema: snap_collections has published TINYINT (0=hidden, 1=live).
 *         snap_collection_items.image_id references snap_images.id.
 *         
 *
 * Hard cap: 30 members per collection, enforced server-side here AND at
 * the DB layer (UNIQUE KEY prevents dups; ENUM rejects non-'image').
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

// Auto-run migration if tables are missing
try {
    $pdo->query("SELECT 1 FROM snap_collections LIMIT 0");
} catch (PDOException $e) {
    $mig = __DIR__ . '/migrations/040_collections.php';
    if (file_exists($mig)) { require_once $mig; migration_040_up($pdo); }
}

// Defensive: ensure the v0.2 image-folio columns exist. Canonical drift left
// some installs (e.g. foreverphotograph.ing, pre-image-refactor) without
// image_id/position/caption, fataling every SELECT/INSERT below with
// "Unknown column 'ci.image_id'". Belt-and-suspenders to the canonical add;
// unblocks the install on next page load without waiting for a full update.
try {
    $pdo->exec(
        "ALTER TABLE snap_collection_items
           ADD COLUMN IF NOT EXISTS `image_id` INT UNSIGNED NOT NULL DEFAULT 0,
           ADD COLUMN IF NOT EXISTS `position` INT          NOT NULL DEFAULT 0,
           ADD COLUMN IF NOT EXISTS `caption`  TEXT                  DEFAULT NULL"
    );
} catch (PDOException $e) { /* already present, or engine lacks IF NOT EXISTS */ }

// --- AJAX HANDLERS ---
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax && !empty($_POST['action'])) {
    header('Content-Type: application/json');

    // Picker: search images only (v0.2)
    if ($_POST['action'] === 'search_items') {
        $q       = '%' . trim($_POST['q'] ?? '') . '%';
        $coll_id = (int)($_POST['collection_id'] ?? 0);

        $rows = $pdo->prepare(
            "SELECT i.id, i.img_title AS name, i.img_date AS created_at,
                    i.img_thumb_square AS thumb
             FROM snap_images i
             WHERE i.img_status = 'published' AND i.img_title LIKE ?
             ORDER BY i.img_date DESC LIMIT 60"
        );
        $rows->execute([$q]);
        $items = $rows->fetchAll(PDO::FETCH_ASSOC);

        // Mark already-added items
        $already = [];
        if ($coll_id > 0) {
            $ai = $pdo->prepare("SELECT item_id FROM snap_collection_items WHERE collection_id=? AND item_type='image'");
            $ai->execute([$coll_id]);
            $already = array_column($ai->fetchAll(PDO::FETCH_ASSOC), 'item_id');
        }
        foreach ($items as &$it) { $it['added'] = in_array($it['id'], $already); }

        echo json_encode($items);
        exit;
    }

    // Add image to collection (v0.2 — image-only, hard cap 30)
    if ($_POST['action'] === 'add_item') {
        $coll_id   = (int)$_POST['collection_id'];
        $item_id   = (int)$_POST['image_id'];

        if ($coll_id <= 0 || $item_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Bad request.']);
            exit;
        }

        // Hard cap: 30 images per collection. Reject the 31st add.
        $count = $pdo->prepare("SELECT COUNT(*) FROM snap_collection_items WHERE collection_id=?");
        $count->execute([$coll_id]);
        $current = (int)$count->fetchColumn();
        if ($current >= 30) {
            echo json_encode([
                'ok' => false,
                'error' => 'Collection is full (30/30). Remove an image before adding another.',
                'cap_reached' => true,
            ]);
            exit;
        }

        // Verify the image exists and is published.
        $img = $pdo->prepare("SELECT id FROM snap_images WHERE id=? AND img_status='published'");
        $img->execute([$item_id]);
        if (!$img->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'Image not found or not published.']);
            exit;
        }

        $max = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM snap_collection_items WHERE collection_id=? AND item_type='image'");
        $max->execute([$coll_id]);
        $next = (int)$max->fetchColumn();

        // Store on the polymorphic columns the public collection.php reads
        // (item_type/item_id/sort_order). Keeps admin and public in lockstep.
        $pdo->prepare(
            "INSERT IGNORE INTO snap_collection_items (collection_id, item_type, item_id, sort_order) VALUES (?,'image',?,?)"
        )->execute([$coll_id, $item_id, $next]);

        echo json_encode(['ok' => true, 'count' => $current + 1, 'cap' => 30]);
        exit;
    }

    // Remove image from collection (v0.2)
    if ($_POST['action'] === 'remove_item') {
        $coll_id = (int)$_POST['collection_id'];
        $item_id = (int)$_POST['image_id'];
        $pdo->prepare("DELETE FROM snap_collection_items WHERE collection_id=? AND item_type='image' AND item_id=?")
            ->execute([$coll_id, $item_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Reorder member list (v0.2 — image-only, supports both legacy
    // [[type,id]] and new [id] payloads for transition).
    if ($_POST['action'] === 'reorder') {
        $coll_id = (int)$_POST['collection_id'];
        $order   = json_decode($_POST['order'] ?? '[]', true);
        if (!is_array($order)) $order = [];
        foreach ($order as $pos => $entry) {
            // Legacy: [type, id]. New: scalar id, or [id].
            if (is_array($entry)) {
                $iid = (int)($entry[1] ?? $entry[0] ?? 0);
            } else {
                $iid = (int)$entry;
            }
            if ($iid <= 0) continue;
            $pdo->prepare(
                "UPDATE snap_collection_items SET sort_order=? WHERE collection_id=? AND item_type='image' AND item_id=?"
            )->execute([$pos, $coll_id, $iid]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // Save per-image caption
    if ($_POST['action'] === 'save_caption') {
        $coll_id  = (int)$_POST['collection_id'];
        $image_id = (int)$_POST['image_id'];
        $caption  = trim($_POST['caption'] ?? '');
        $pdo->prepare(
            "UPDATE snap_collection_items SET caption=? WHERE collection_id=? AND item_type='image' AND item_id=?"
        )->execute([$caption ?: null, $coll_id, $image_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Toggle collection visibility (v0.2 — LIVE / HIDDEN)
    if ($_POST['action'] === 'toggle_visibility') {
        $coll_id = (int)$_POST['collection_id'];
        $vis     = !empty($_POST['published']) ? 1 : 0;
        $pdo->prepare("UPDATE snap_collections SET published=? WHERE id=?")
            ->execute([$vis, $coll_id]);
        echo json_encode(['ok' => true, 'published' => $vis]);
        exit;
    }

    // Save featured image for collection
    if ($_POST['action'] === 'save_featured') {
        $coll_id = (int)$_POST['collection_id'];
        $post_id = !empty($_POST['post_id']) ? (int)$_POST['post_id'] : null;
        $pdo->prepare("UPDATE snap_collections SET cover_image_id=? WHERE id=?")
            ->execute([$post_id, $coll_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // List posts for featured image picker (GET-based)
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

// Featured image post picker (GET, reused across modals)
// Returns { posts: [{id, title, thumb}], hasMore: bool } for ss-engine-featured-picker.js
// Mirrors smack-albums.php / smack-cats.php — queries snap_images directly,
// which is where photos live on photoblog installs (snap_posts is only used
// when images are wrapped in a longform post, which the picker shouldn't
// require).
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
         ORDER BY i.img_date DESC
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

// --- PAGE-LEVEL FORM HANDLERS (save/delete collections) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {

    if (!empty($_POST['action_save'])) {
        $id   = !empty($_POST['collection_id']) ? (int)$_POST['collection_id'] : 0;
        $name = trim($_POST['col_name'] ?? '');
        $slug = trim($_POST['col_slug'] ?? '');
        $desc = trim($_POST['col_desc'] ?? '');
        if (!$slug) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
            $slug = trim($slug, '-');
        }
        $display = in_array($_POST['col_display'] ?? '', ['browse','slideshow']) ? $_POST['col_display'] : 'browse';
        if ($id > 0) {
            $pdo->prepare("UPDATE snap_collections SET title=?, slug=?, description=?, default_display=? WHERE id=?")
                ->execute([$name, $slug, $desc, $display, $id]);
        } else {
            $pdo->prepare("INSERT INTO snap_collections (title, slug, description, default_display) VALUES (?,?,?,?)")
                ->execute([$name, $slug, $desc, $display]);
            $id = (int)$pdo->lastInsertId();
        }
        header("Location: smack-collections.php?edit=$id&msg=SAVED");
        exit;
    }

    if (!empty($_POST['action_delete'])) {
        $id = (int)$_POST['collection_id'];
        $pdo->prepare("DELETE FROM snap_collections WHERE id=?")->execute([$id]);
        header("Location: smack-collections.php?msg=COLLECTION+PURGED");
        exit;
    }

    if (!empty($_POST['action_settings'])) {
        $rows = max(1, min(10, (int)($_POST['collections_index_rows'] ?? 3)));
        $sort = in_array($_POST['collections_default_sort'] ?? '', ['manual','alphabetical','newest']) ? $_POST['collections_default_sort'] : 'manual';
        foreach (['collections_index_rows' => $rows, 'collections_default_sort' => $sort] as $k => $v) {
            $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val=?")
                ->execute([$k, $v, $v]);
        }
        header("Location: smack-collections.php?msg=SETTINGS+SAVED");
        exit;
    }
}

// --- LOAD STATE ---
$collections = $pdo->query(
    "SELECT c.*, COUNT(ci.id) AS member_count
     FROM snap_collections c
     LEFT JOIN snap_collection_items ci ON ci.collection_id = c.id
     GROUP BY c.id
     ORDER BY c.sort_order ASC, c.title ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$editing        = null;
$edit_items     = [];
$featured_thumb = null;

if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM snap_collections WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editing) {
        // Load member items with their display names and thumbnails. Read the
        // polymorphic columns the public page uses; alias item_id AS image_id +
        // sort_order AS position so the enrich/render code below is unchanged.
        $items_raw = $pdo->prepare(
            "SELECT ci.*, ci.item_id AS image_id, ci.sort_order AS position
             FROM snap_collection_items ci
             WHERE ci.collection_id = ?
               AND ci.item_type = 'image'
             ORDER BY ci.sort_order ASC"
        );
        $items_raw->execute([$editing['id']]);
        $items_raw = $items_raw->fetchAll(PDO::FETCH_ASSOC);

        // v0.2: enrich image-only members. Migration 055 narrowed the ENUM
        // and converted/deleted any non-image rows.
        foreach ($items_raw as $item) {
            $enriched = $item;
            $r = $pdo->prepare(
                "SELECT i.img_title AS title, i.img_thumb_square AS thumb
                 FROM snap_images i WHERE i.id=? LIMIT 1"
            );
            $r->execute([$item['image_id']]);
            $row = $r->fetch(PDO::FETCH_ASSOC);
            $enriched['display_name'] = $row['title'] ?? '(image ' . $item['image_id'] . ')';
            $enriched['thumb']        = $row['thumb'] ?? null;
            
            $edit_items[] = $enriched;
        }

        // Featured image
        if (!empty($editing['cover_image_id'])) {
            $fs = $pdo->prepare(
                "SELECT i.img_thumb_square AS thumb, i.img_title AS title
                 FROM snap_images i
                 WHERE i.id=? LIMIT 1"
            );
            $fs->execute([$editing['cover_image_id']]);
            $featured_thumb = $fs->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
}

$page_title = 'Collections';
include 'core/admin-header.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-featured-picker.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-featured-picker.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php
include 'core/sidebar.php';
?>

<div class="main">

<?php if ($editing || isset($_GET['new'])): ?>
    <?php $cid = $editing['id'] ?? 0; ?>

    <div class="header-row header-row--ruled">
        <h2><?php echo $cid ? 'EDIT COLLECTION: ' . htmlspecialchars($editing['title']) : 'NEW COLLECTION'; ?></h2>
        <a href="smack-collections.php" class="btn-secondary">← BACK TO LIST</a>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action_save" value="1">
        <input type="hidden" name="collection_id" value="<?php echo $cid; ?>">

        <div class="post-layout-grid">
            <div class="post-col-left">
                <div class="box">
                    <h3>DETAILS</h3>

                    <div class="lens-input-wrapper">
                        <label>NAME</label>
                        <input type="text" name="col_name" id="col-name"
                               value="<?php echo htmlspecialchars($editing['title'] ?? ''); ?>"
                               placeholder="Collection name" required oninput="autoSlug(this.value)">
                    </div>
                    <div class="lens-input-wrapper mt-16">
                        <label>SLUG</label>
                        <input type="text" name="col_slug" id="col-slug"
                               value="<?php echo htmlspecialchars($editing['slug'] ?? ''); ?>"
                               placeholder="url-friendly-slug">
                    </div>
                    <div class="lens-input-wrapper mt-16">
                        <label>DESCRIPTION <span class="field-tip" data-tip="Optional. Shown on the collection page.">ⓘ</span></label>
                        <textarea name="col_desc" rows="4" placeholder="A short description of this collection."><?php echo htmlspecialchars($editing['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="lens-input-wrapper mt-16">
                        <label>DEFAULT VIEW <span class="field-tip" data-tip="How this collection is shown to visitors by default.">ⓘ</span></label>
                        <select name="col_display">
                            <option value="browse"<?php echo ($editing['default_display'] ?? 'browse') === 'browse' ? ' selected' : ''; ?>>Browse (grid)</option>
                            <option value="slideshow"<?php echo ($editing['default_display'] ?? '') === 'slideshow' ? ' selected' : ''; ?>>Slideshow</option>
                        </select>
                    </div>

                    <!-- FEATURED IMAGE -->
                    <div class="lens-input-wrapper mt-20">
                        <label>FEATURED IMAGE <span class="field-tip" data-tip="Picks any post's hero image — used as the representative thumbnail for this collection.">ⓘ</span></label>
                        <div id="col-featured-preview" class="ssfp-preview"></div>
                        <input type="hidden" id="col-featured-id" name="col_cover_image_id"
                               value="<?php echo (int)($editing['cover_image_id'] ?? 0); ?>">
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <button type="submit" class="master-update-btn">SAVE COLLECTION</button>
                    </div>

                    <?php if ($cid): ?>
                    <div class="lens-input-wrapper mt-16">
                        <form method="POST" onsubmit="return confirm('DELETE THIS COLLECTION? Members are not deleted.')">
                            <input type="hidden" name="action_delete" value="1">
                            <input type="hidden" name="collection_id" value="<?php echo $cid; ?>">
                            <button type="submit" class="btn-reset" style="width:100%;color:var(--danger,#cc4444);">DELETE COLLECTION</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex-1">
                <?php if ($cid): ?>
                <div class="box">
                    <h3>MEMBERS <span class="dim" style="font-weight:400;font-size:12px;">— drag to reorder</span></h3>

                    <div class="collection-cap-counter" style="margin-bottom:10px;font-size:12px;font-family:monospace;">
                        <span id="member-count"><?php echo count($edit_items); ?></span> / 30 IMAGES
                    </div>

                    <div id="member-list" style="min-height:60px;">
                        <?php if (empty($edit_items)): ?>
                            <p class="dim empty-notice" id="member-empty">No images yet. Pick from the search panel below — up to 30 per collection.</p>
                        <?php else: ?>
                            <?php foreach ($edit_items as $item): ?>
                            <div class="recent-item member-row"
                                 data-type="image"
                                 data-id="<?php echo $item['image_id']; ?>"
                                 draggable="true"
                                 style="cursor:grab;">
                                <div class="item-details">
                                    <?php if ($item['thumb']): ?>
                                    <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($item['thumb'], '/')); ?>"
                                         style="width:40px;height:40px;object-fit:cover;border-radius:2px;flex-shrink:0;margin-right:10px;" alt="">
                                    <?php else: ?>
                                    <div style="width:40px;height:40px;background:var(--card-bg);border-radius:2px;flex-shrink:0;margin-right:10px;display:flex;align-items:center;justify-content:center;">
                                        <span style="font-size:9px;text-transform:uppercase;color:var(--dim);"><?php echo 'img'; ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="item-text">
                                        <strong><?php echo htmlspecialchars($item['display_name']); ?></strong>
                                        <code class="slug-display"><?php echo 'IMAGE'; ?></code>
                                    </div>
                                </div>
                                <div class="item-actions">
                                    <a href="#" class="action-delete"
                                       onclick="removeMember('image',<?php echo $item['image_id']; ?>,this);return false;">REMOVE</a>
                                </div>
                                <div style="padding:4px 0 6px;width:100%;">
                                    <input type="text" class="member-caption-input"
                                           data-image-id="<?php echo $item['image_id']; ?>"
                                           value="<?php echo htmlspecialchars($item['caption'] ?? ''); ?>"
                                           placeholder="Caption (optional)"
                                           style="width:100%;padding:4px 7px;border:1px solid var(--border);border-radius:3px;background:var(--input-bg);color:var(--text);font-size:11px;box-sizing:border-box;"
                                           onblur="saveCaption(this)">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- ADD IMAGE PANEL -->
                    <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px;">
                        <p class="dim" style="font-size:11px;margin:0 0 10px;">SEARCH IMAGES TO ADD</p>
                        <input type="text" id="member-search" placeholder="Search image titles…" oninput="searchMembers(this.value)"
                               style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:3px;background:var(--input-bg);color:var(--text);font-size:13px;box-sizing:border-box;margin-bottom:8px;">
                        <div id="member-picker-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:6px;max-height:280px;overflow-y:auto;"></div>
                    </div>
                </div>
                <?php else: ?>
                <div class="box">
                    <p class="dim empty-notice">Save the collection first to add members.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <script>
    var COLL_ID    = <?php echo $cid; ?>;
    var BASE       = <?php echo json_encode(BASE_URL); ?>;
    var activeTab  = 'post';
    var dragSrc    = null;

    // --- Slug auto-generate ---
    var slugEdited = false;
    document.getElementById('col-slug').addEventListener('input', function () { slugEdited = true; });
    function autoSlug(v) {
        if (slugEdited) return;
        document.getElementById('col-slug').value = v.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
    }

    // --- Tab switching ---
    function switchTab(type, btn) {
        activeTab = type;
        document.querySelectorAll('.picker-tab').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('member-search').value = '';
        searchMembers('');
    }

    // --- Search images for picker (v0.2 — image-only) ---
    function searchMembers(q) {
        var grid = document.getElementById('member-picker-grid');
        grid.innerHTML = '<p class="dim" style="font-size:12px;padding:8px;grid-column:1/-1;">Loading…</p>';
        ajax('search_items', { q: q, collection_id: COLL_ID }, function (items) {
            if (!items.length) { grid.innerHTML = '<p class="dim" style="font-size:12px;padding:8px;grid-column:1/-1;">Nothing found.</p>'; return; }
            var frag = document.createDocumentFragment();
            items.forEach(function (it) {
                var src = it.thumb ? BASE + it.thumb.replace(/^\//,'') : '';
                var div = document.createElement('div');
                div.style.cssText = 'cursor:pointer;border:2px solid ' + (it.added ? 'var(--accent)' : 'transparent') + ';border-radius:3px;overflow:hidden;position:relative;';
                div.dataset.type  = activeTab;
                div.dataset.id    = it.id;
                div.dataset.name  = it.name || '';
                div.dataset.src   = src;
                div.addEventListener('click', function () {
                    addMember(this.dataset.type, this.dataset.id, this.dataset.name, this.dataset.src);
                });
                if (src) {
                    var inner = document.createElement('div');
                    inner.style.cssText = 'aspect-ratio:1;';
                    var img = document.createElement('img');
                    img.src = src;
                    img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                    img.loading = 'lazy';
                    inner.appendChild(img);
                    div.appendChild(inner);
                } else {
                    var inner = document.createElement('div');
                    inner.style.cssText = 'aspect-ratio:1;background:var(--card-bg);display:flex;align-items:center;justify-content:center;padding:6px;';
                    var lbl = document.createElement('span');
                    lbl.style.cssText = 'font-size:10px;color:var(--dim);text-align:center;word-break:break-word;';
                    lbl.textContent = it.name;
                    inner.appendChild(lbl);
                    div.appendChild(inner);
                }
                if (it.added) {
                    var tick = document.createElement('div');
                    tick.style.cssText = 'position:absolute;top:3px;right:3px;background:var(--accent);color:#111;border-radius:50%;width:16px;height:16px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;';
                    tick.textContent = '✓';
                    div.appendChild(tick);
                }
                frag.appendChild(div);
            });
            grid.innerHTML = '';
            grid.appendChild(frag);
        });
    }

    // --- Add member ---
    function addMember(type, id, name, thumb) {
        ajax('add_item', { collection_id: COLL_ID, image_id: id }, function (r) {
            if (!r.ok) return;
            // Remove empty notice
            var empty = document.getElementById('member-empty');
            if (empty) empty.remove();
            // Append to member list
            var list = document.getElementById('member-list');
            var div  = document.createElement('div');
            div.className = 'recent-item member-row';
            div.setAttribute('data-type', type);
            div.setAttribute('data-id',   id);
            div.draggable = true;
            div.style.cursor = 'grab';
            var details = document.createElement('div');
            details.className = 'item-details';
            if (thumb) {
                var tImg = document.createElement('img');
                tImg.src = thumb;
                tImg.style.cssText = 'width:40px;height:40px;object-fit:cover;border-radius:2px;flex-shrink:0;margin-right:10px;';
                tImg.alt = '';
                details.appendChild(tImg);
            } else {
                var tPlaceholder = document.createElement('div');
                tPlaceholder.style.cssText = 'width:40px;height:40px;background:var(--card-bg);border-radius:2px;flex-shrink:0;margin-right:10px;display:flex;align-items:center;justify-content:center;';
                var tLabel = document.createElement('span');
                tLabel.style.cssText = 'font-size:9px;text-transform:uppercase;color:var(--dim);';
                tLabel.textContent = type.substring(0, 3);
                tPlaceholder.appendChild(tLabel);
                details.appendChild(tPlaceholder);
            }
            var itemText = document.createElement('div');
            itemText.className = 'item-text';
            var strong = document.createElement('strong');
            strong.textContent = name;
            var code = document.createElement('code');
            code.className = 'slug-display';
            code.textContent = type.toUpperCase();
            itemText.appendChild(strong);
            itemText.appendChild(code);
            details.appendChild(itemText);
            var actions = document.createElement('div');
            actions.className = 'item-actions';
            var removeLink = document.createElement('a');
            removeLink.href = '#';
            removeLink.className = 'action-delete';
            removeLink.textContent = 'REMOVE';
            removeLink.addEventListener('click', function (e) { e.preventDefault(); removeMember(type, id, div); });
            actions.appendChild(removeLink);
            div.appendChild(details);
            div.appendChild(actions);
            bindDrag(div);
            list.appendChild(div);
            // Refresh picker to show ✓
            searchMembers(document.getElementById('member-search').value);
        });
    }

    // --- Remove member (v0.2 image-only) ---
    function removeMember(type, id, el) {
        ajax('remove_item', { collection_id: COLL_ID, image_id: id }, function (r) {
            if (!r.ok) return;
            var row = el.closest('.member-row');
            if (row) row.remove();
            // Update count
            var c = document.getElementById('member-count');
            if (c) c.textContent = document.querySelectorAll('.member-row').length;
            searchMembers(document.getElementById('member-search').value);
        });
    }

    // --- Drag-to-reorder ---
    function bindDrag(el) {
        el.addEventListener('dragstart', function (e) { dragSrc = el; e.dataTransfer.effectAllowed = 'move'; });
        el.addEventListener('dragover',  function (e) { e.preventDefault(); });
        el.addEventListener('drop',      function (e) {
            e.preventDefault();
            if (!dragSrc || dragSrc === el) return;
            var list = document.getElementById('member-list');
            var children = Array.from(list.querySelectorAll('.member-row'));
            var srcI = children.indexOf(dragSrc);
            var dstI = children.indexOf(el);
            if (srcI < dstI) list.insertBefore(dragSrc, el.nextSibling);
            else              list.insertBefore(dragSrc, el);
            saveOrder();
        });
    }
    document.querySelectorAll('.member-row').forEach(bindDrag);

    function saveOrder() {
        var rows  = document.getElementById('member-list').querySelectorAll('.member-row');
        var order = Array.from(rows).map(function (r) { return parseInt(r.getAttribute('data-id'), 10); });
        ajax('reorder', { collection_id: COLL_ID, order: JSON.stringify(order) }, function(){});
        // Update count for visual feedback
        var c = document.getElementById('member-count'); if (c) c.textContent = rows.length;
    }

    // --- Caption save ---
    function saveCaption(input) {
        var imageId = parseInt(input.getAttribute('data-image-id'), 10);
        if (!imageId) return;
        ajax('save_caption', { collection_id: COLL_ID, image_id: imageId, caption: input.value }, function(){});
    }

    // --- Featured image picker ---
    // Markup + behaviour live in assets/js/ss-engine-featured-picker.js.
    // The engine script is loaded with `defer`, so it isn't defined until
    // DOMContentLoaded fires. Wrap the attach() inside that listener so we
    // configure the preview AFTER the engine is on the page.
    document.addEventListener('DOMContentLoaded', function () {
        var prevEl = document.getElementById('col-featured-preview');
        var idEl   = document.getElementById('col-featured-id');
        if (prevEl && idEl && window.ssFeaturedPicker) {
            var initialThumb = <?php echo ($featured_thumb && !empty($featured_thumb['thumb']))
                ? json_encode(BASE_URL . ltrim($featured_thumb['thumb'], '/'))
                : 'null'; ?>;
            var initialTitle = <?php echo ($featured_thumb && !empty($featured_thumb['title']))
                ? json_encode($featured_thumb['title'])
                : "''"; ?>;
            window.ssFeaturedPicker.attach({
                endpoint:      'smack-collections.php?ajax=posts',
                previewEl:     prevEl,
                hiddenInputEl: idEl,
                baseUrl:       BASE,
                initialThumb:  initialThumb,
                initialTitle:  initialTitle,
                onSelect: function (id) {
                    if (COLL_ID) ajax('save_featured', { collection_id: COLL_ID, post_id: id }, function(){});
                },
                onClear: function () {
                    if (COLL_ID) ajax('save_featured', { collection_id: COLL_ID, post_id: '' }, function(){});
                }
            });
        }
        // Init member picker if editing
        if (COLL_ID) searchMembers('');
    });

    // --- XHR helper ---
    function ajax(action, data, cb) {
        data.action = action;
        var body = Object.keys(data).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
        }).join('&');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'smack-collections.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () { if (xhr.status === 200) cb(JSON.parse(xhr.responseText)); };
        xhr.send(body);
    }
    </script>

<?php else: ?>

    <div class="header-row header-row--ruled">
        <h2>COLLECTIONS</h2>
    </div>

    <div style="margin-bottom:20px;">
        <a href="smack-collections.php?new=1" class="btn-smack">+ NEW COLLECTION</a>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>

    <?php if (empty($collections)): ?>
        <div class="box">
            <p class="dim empty-notice">No collections yet. Collections let you group posts, albums, and categories into a single curated set.</p>
        </div>
    <?php else: ?>
        <div class="box">
            <?php foreach ($collections as $col): ?>
            <div class="recent-item">
                <div class="item-details">
                    <div class="item-text">
                        <strong><?php echo htmlspecialchars($col['title']); ?></strong>
                        <code class="slug-display"><?php echo (int)$col['member_count']; ?> ITEM<?php echo $col['member_count'] != 1 ? 'S' : ''; ?></code>
                        <?php if (!empty($col['description'])): ?>
                            <span class="dim" style="display:block;margin-top:4px;font-size:0.85em;"><?php echo htmlspecialchars($col['description']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="item-actions">
                    <a href="?edit=<?php echo $col['id']; ?>" class="action-edit">EDIT</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('DELETE COLLECTION?')">
                        <input type="hidden" name="action_delete" value="1">
                        <input type="hidden" name="collection_id" value="<?php echo $col['id']; ?>">
                        <button type="submit" class="action-delete" style="background:none;border:none;cursor:pointer;font-size:inherit;font-family:inherit;padding:0;color:inherit;">DELETE</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

    <div class="box" style="margin-top:24px;">
        <h3>COLLECTION SETTINGS</h3>
        <form method="POST">
            <input type="hidden" name="action_settings" value="1">
            <div class="lens-input-wrapper">
                <label>INDEX ROWS <span class="field-tip" data-tip="Number of rows shown on the /collections public page.">ⓘ</span></label>
                <select name="collections_index_rows">
                    <?php foreach ([1,2,3,4,5] as $r): ?>
                    <option value="<?php echo $r; ?>"<?php echo (int)($settings['collections_index_rows'] ?? 3) === $r ? ' selected' : ''; ?>><?php echo $r; ?> row<?php echo $r > 1 ? 's' : ''; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lens-input-wrapper mt-16">
                <label>DEFAULT SORT ORDER <span class="field-tip" data-tip="How collections are ordered on the public /collections page.">ⓘ</span></label>
                <select name="collections_default_sort">
                    <option value="manual"<?php echo ($settings['collections_default_sort'] ?? 'manual') === 'manual' ? ' selected' : ''; ?>>Manual (drag order)</option>
                    <option value="alphabetical"<?php echo ($settings['collections_default_sort'] ?? '') === 'alphabetical' ? ' selected' : ''; ?>>Alphabetical</option>
                    <option value="newest"<?php echo ($settings['collections_default_sort'] ?? '') === 'newest' ? ' selected' : ''; ?>>Date created (newest first)</option>
                </select>
            </div>
            <div class="lens-input-wrapper mt-20">
                <button type="submit" class="master-update-btn">SAVE SETTINGS</button>
            </div>
        </form>
    </div>

</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
