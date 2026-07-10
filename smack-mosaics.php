<?php
/**
 * SNAPSMACK - Mosaic Builder
 *
 * Create and manage inline tiled image panels. Pick assets from the media
 * library, drag to reorder, preview the Jetpack-style layout live, then
 * embed via [mosaic:ID] shortcode in post or static page content.
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

// Ensure table exists before any query hits it
try {
    $pdo->query("SELECT 1 FROM snap_mosaics LIMIT 0");
} catch (PDOException $e) {
    $mig = __DIR__ . '/migrations/038_mosaics.php';
    if (file_exists($mig)) {
        require_once $mig;
        migration_038_up($pdo);
    }
}

// --- AJAX HANDLERS ---
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'list_assets') {
        // Mosaic images are POST content, so they come from the GALLERY
        // (snap_images), NOT the reusable-asset Library. Aliased to asset_name /
        // asset_path so the existing picker/preview JS reads them unchanged; the
        // saved ids are Gallery image ids, which core/parser.php resolves against
        // snap_images. Prefer the light aspect thumb for the picker/preview tile.
        $assets = $pdo->query(
            "SELECT id,
                    img_title AS asset_name,
                    COALESCE(NULLIF(img_thumb_aspect, ''), img_file) AS asset_path
             FROM snap_images
             WHERE img_status = 'published'
             ORDER BY img_date DESC LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($assets);
        exit;
    }

    if ($_POST['action'] === 'save_mosaic') {
        $id        = !empty($_POST['mosaic_id']) ? (int)$_POST['mosaic_id'] : 0;
        $title     = trim($_POST['title'] ?? '') ?: 'Untitled Mosaic';
        $asset_ids = json_decode($_POST['asset_ids'] ?? '[]', true);
        $gap       = max(0, min(20, (int)($_POST['gap'] ?? 4)));

        if (empty($asset_ids)) {
            echo json_encode(['ok' => false, 'error' => 'Select at least one image.']);
            exit;
        }

        $json_ids = json_encode(array_values($asset_ids));

        if ($id > 0) {
            $pdo->prepare("UPDATE snap_mosaics SET title = ?, asset_ids = ?, gap = ? WHERE id = ?")
                ->execute([$title, $json_ids, $gap, $id]);
        } else {
            $pdo->prepare("INSERT INTO snap_mosaics (title, asset_ids, gap) VALUES (?, ?, ?)")
                ->execute([$title, $json_ids, $gap]);
            $id = (int)$pdo->lastInsertId();
        }

        echo json_encode(['ok' => true, 'id' => $id, 'shortcode' => '[mosaic:' . $id . ']']);
        exit;
    }

    if ($_POST['action'] === 'delete_mosaic') {
        $id = (int)($_POST['mosaic_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM snap_mosaics WHERE id = ?")->execute([$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

// --- PAGE LOAD ---
$mosaics = $pdo->query("SELECT * FROM snap_mosaics ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$editing = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM snap_mosaics WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$page_title = 'Mosaics';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
<?php if ($editing || isset($_GET['new'])): ?>
    <?php
    $mosaic_id    = $editing['id']           ?? 0;
    $mosaic_title = htmlspecialchars($editing['title'] ?? 'Untitled Mosaic');
    $mosaic_ids   = $editing ? (json_decode($editing['asset_ids'], true) ?: []) : [];
    $mosaic_gap   = (int)($editing['gap']    ?? 4);
    ?>

    <div class="header-row header-row--ruled">
        <h2><?php echo $mosaic_id ? 'EDIT MOSAIC #' . $mosaic_id : 'NEW MOSAIC'; ?></h2>
        <a href="smack-mosaics.php" class="btn-secondary">← BACK TO LIST</a>
    </div>

    <div class="post-layout-grid">
        <div class="post-col-left">
            <div class="box">
                <div class="lens-input-wrapper">
                    <label>TITLE</label>
                    <input type="text" id="mosaic-title" value="<?php echo $mosaic_title; ?>" placeholder="Give this mosaic a name">
                </div>

                <div style="display:flex;gap:16px;align-items:flex-end;margin-top:16px;">
                    <div class="lens-input-wrapper" style="flex:0 0 auto;margin-top:0;">
                        <label>GAP (PX)</label>
                        <input type="number" id="mosaic-gap" value="<?php echo $mosaic_gap; ?>" min="0" max="20" style="width:80px;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--dim);display:block;margin-bottom:6px;">SHORTCODE</label>
                        <code id="mosaic-shortcode" style="color:var(--accent);cursor:pointer;font-size:13px;"
                              onclick="navigator.clipboard.writeText(this.textContent)" title="Click to copy">
                            <?php echo $mosaic_id ? '[mosaic:' . $mosaic_id . ']' : '(save first)'; ?>
                        </code>
                    </div>
                </div>

                <div class="lens-input-wrapper mt-20">
                    <button type="button" onclick="saveMosaic()" class="master-update-btn">SAVE MOSAIC</button>
                </div>
                <?php if ($mosaic_id): ?>
                <div class="lens-input-wrapper mt-10">
                    <a href="smack-mosaics.php" class="btn-reset btn-cancel-block">CANCEL</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex-1">
            <div class="box">
                <!-- SELECTED ASSETS -->
                <div class="lens-input-wrapper">
                    <label>SELECTED IMAGES — drag to reorder, × to remove</label>
                    <div id="mosaic-selected" style="display:flex;flex-wrap:wrap;gap:8px;min-height:80px;padding:12px;border:1px solid var(--border);border-radius:3px;background:var(--input-bg);margin-top:6px;"></div>
                </div>

                <div class="lens-input-wrapper mt-16">
                    <button type="button" onclick="togglePicker()" class="btn-secondary" style="width:100%;">+ ADD IMAGES FROM MEDIA GALLERY</button>
                </div>

                <!-- ASSET PICKER (hidden) -->
                <div id="asset-picker" style="display:none;margin-top:12px;border:1px solid var(--border);border-radius:3px;padding:12px;background:var(--card-bg);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--dim);">MEDIA GALLERY</span>
                        <button type="button" onclick="togglePicker()" style="background:none;border:none;color:var(--dim);cursor:pointer;font-size:18px;line-height:1;">×</button>
                    </div>
                    <div id="asset-picker-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:6px;max-height:360px;overflow-y:auto;"></div>
                </div>

                <!-- LIVE PREVIEW -->
                <div class="lens-input-wrapper mt-20">
                    <label>LIVE PREVIEW</label>
                    <div class="mosaic-preview-wrap" id="mosaic-preview-wrap">
                        <div id="mosaic-preview" class="snap-mosaic">
                            <p class="dim" style="text-align:center;padding:20px 0;margin:0;">Add images to see preview.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-mosaic.css">
    <script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic.js" defer></script>
    <script>
    (function () {
        var BASE          = <?php echo json_encode(BASE_URL); ?>;
        var mosaicId      = <?php echo $mosaic_id; ?>;
        var selectedIds   = <?php echo json_encode($mosaic_ids); ?>;
        var allAssets     = {};   // id → {id, asset_name, asset_path}
        var dragSrcIndex  = null;
        var pickerOpen    = false;

        // --- Bootstrap ---
        document.addEventListener('DOMContentLoaded', function () {
            loadAssets();
            document.getElementById('mosaic-gap').addEventListener('input', updatePreview);
        });

        function loadAssets() {
            ajax('list_assets', {}, function (assets) {
                assets.forEach(function (a) { allAssets[a.id] = a; });
                renderPickerGrid();
                renderSelected();
                updatePreview();
            });
        }

        // --- Picker toggle ---
        window.togglePicker = function () {
            var el = document.getElementById('asset-picker');
            pickerOpen = !pickerOpen;
            el.style.display = pickerOpen ? 'block' : 'none';
        };

        // --- Picker grid ---
        function renderPickerGrid() {
            var grid = document.getElementById('asset-picker-grid');
            var html = '';
            var webExts = ['jpg','jpeg','png','gif','webp','avif','svg','bmp'];
            Object.keys(allAssets).forEach(function (id) {
                var a   = allAssets[id];
                var ext = (a.asset_path.split('.').pop() || '').toLowerCase();
                var sel = selectedIds.indexOf(parseInt(id, 10)) !== -1;
                html += '<div onclick="toggleAsset(' + id + ')" style="cursor:pointer;position:relative;aspect-ratio:1;'
                      + 'border:2px solid ' + (sel ? 'var(--accent)' : 'transparent') + ';border-radius:3px;overflow:hidden;background:#111;">';
                if (webExts.indexOf(ext) !== -1) {
                    html += '<img src="' + BASE + a.asset_path + '" style="width:100%;height:100%;object-fit:cover;" loading="lazy">';
                } else {
                    html += '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--dim);font-size:10px;">' + ext.toUpperCase() + '</div>';
                }
                if (sel) {
                    html += '<div style="position:absolute;top:3px;right:3px;background:var(--accent);color:#111;'
                          + 'border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;">✓</div>';
                }
                html += '</div>';
            });
            grid.innerHTML = html || '<p style="color:var(--dim);font-size:12px;grid-column:1/-1;">No media found.</p>';
        }

        window.toggleAsset = function (id) {
            id = parseInt(id, 10);
            var i = selectedIds.indexOf(id);
            if (i === -1) selectedIds.push(id); else selectedIds.splice(i, 1);
            renderPickerGrid();
            renderSelected();
            updatePreview();
        };

        // --- Selected strip ---
        function renderSelected() {
            var el = document.getElementById('mosaic-selected');
            if (selectedIds.length === 0) {
                el.innerHTML = '<p class="dim" style="padding:12px;margin:0;font-size:12px;">No images selected.</p>';
                return;
            }
            var html = '';
            selectedIds.forEach(function (id, i) {
                var a = allAssets[id];
                if (!a) return;
                html += '<div class="mosaic-thumb-wrap" draggable="true" data-index="' + i + '"'
                      + ' ondragstart="dragStart(event,' + i + ')" ondragover="dragOver(event)" ondrop="dragDrop(event,' + i + ')"'
                      + ' style="position:relative;width:72px;height:72px;border:1px solid var(--border);border-radius:3px;overflow:hidden;cursor:grab;flex-shrink:0;">'
                      + '<img src="' + BASE + a.asset_path + '" style="width:100%;height:100%;object-fit:cover;" loading="lazy">'
                      + '<button type="button" onclick="removeAsset(' + i + ')" title="Remove"'
                      + ' style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.65);border:none;color:#ff5555;cursor:pointer;'
                      + 'width:18px;height:18px;border-radius:50%;font-size:13px;line-height:1;padding:0;display:flex;align-items:center;justify-content:center;">×</button>'
                      + '</div>';
            });
            el.innerHTML = html;
        }

        window.removeAsset = function (index) {
            selectedIds.splice(index, 1);
            renderPickerGrid();
            renderSelected();
            updatePreview();
        };

        // --- Drag reorder ---
        window.dragStart = function (e, i) { dragSrcIndex = i; e.dataTransfer.effectAllowed = 'move'; };
        window.dragOver  = function (e) { e.preventDefault(); };
        window.dragDrop  = function (e, target) {
            e.preventDefault();
            if (dragSrcIndex === null || dragSrcIndex === target) return;
            var item = selectedIds.splice(dragSrcIndex, 1)[0];
            selectedIds.splice(target, 0, item);
            dragSrcIndex = null;
            renderSelected();
            updatePreview();
        };

        // --- Live preview ---
        function updatePreview() {
            var container = document.getElementById('mosaic-preview');
            if (selectedIds.length === 0) {
                container.innerHTML = '<p class="dim" style="text-align:center;padding:20px 0;margin:0;">Add images to see preview.</p>';
                container.removeAttribute('data-mosaic');
                return;
            }

            var gap    = parseInt(document.getElementById('mosaic-gap').value, 10) || 4;
            var images = [];
            selectedIds.forEach(function (id) {
                var a = allAssets[id];
                if (a) images.push({ src: BASE + a.asset_path, width: 800, height: 600, alt: a.asset_name, id: id });
            });

            // Preload to get real dimensions, then render
            var loaded = 0;
            images.forEach(function (img, idx) {
                var t   = new Image();
                t.onload = t.onerror = function () {
                    if (t.naturalWidth)  { images[idx].width  = t.naturalWidth; }
                    if (t.naturalHeight) { images[idx].height = t.naturalHeight; }
                    if (++loaded === images.length) renderWithData(images, gap, container);
                };
                t.src = img.src;
            });
        }

        function renderWithData(images, gap, container) {
            container.setAttribute('data-mosaic', JSON.stringify(images));
            container.setAttribute('data-gap',    gap);
            if (window.SnapMosaic) window.SnapMosaic.renderMosaic(container);
        }

        // --- Save ---
        window.saveMosaic = function () {
            var title = (document.getElementById('mosaic-title').value.trim() || 'Untitled Mosaic');
            var gap   = parseInt(document.getElementById('mosaic-gap').value, 10) || 4;
            if (selectedIds.length === 0) { alert('Select at least one image.'); return; }

            ajax('save_mosaic', {
                mosaic_id: mosaicId,
                title:     title,
                asset_ids: JSON.stringify(selectedIds),
                gap:       gap
            }, function (resp) {
                if (resp.ok) {
                    mosaicId = resp.id;
                    document.getElementById('mosaic-shortcode').textContent = resp.shortcode;
                    history.replaceState(null, '', 'smack-mosaics.php?edit=' + resp.id);
                } else {
                    alert('Error: ' + (resp.error || 'Unknown error'));
                }
            });
        };

        // --- XHR helper ---
        function ajax(action, data, cb) {
            data.action = action;
            var body = Object.keys(data).map(function (k) {
                return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
            }).join('&');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'smack-mosaics.php', true);
            xhr.setRequestHeader('Content-Type',  'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () { if (xhr.status === 200) cb(JSON.parse(xhr.responseText)); };
            xhr.send(body);
        }

    }());
    </script>

<?php else: ?>

    <div class="header-row header-row--ruled">
        <h2>MOSAICS</h2>
        <a href="smack-mosaics.php?new=1"><button type="button">+ NEW MOSAIC</button></a>
    </div>

    <?php if (empty($mosaics)): ?>
        <div class="box">
            <p class="dim empty-notice">No mosaics yet. Create one to embed tiled image panels in your posts and pages via <code>[mosaic:ID]</code>.</p>
        </div>
    <?php else: ?>
        <div class="box">
            <?php foreach ($mosaics as $m):
                $ids   = json_decode($m['asset_ids'], true);
                $count = is_array($ids) ? count($ids) : 0;
            ?>
            <div class="recent-item">
                <div class="item-details">
                    <div class="item-text">
                        <strong><?php echo htmlspecialchars($m['title']); ?></strong>
                        <code class="slug-display"><?php echo $count; ?> IMAGE<?php echo $count !== 1 ? 'S' : ''; ?></code>
                        <div class="item-meta">
                            <code onclick="navigator.clipboard.writeText(this.textContent)"
                                  style="color:var(--accent);cursor:pointer;" title="Click to copy">[mosaic:<?php echo $m['id']; ?>]</code>
                            &nbsp;·&nbsp;
                            Updated <?php echo date('M j, Y', strtotime($m['updated_at'])); ?>
                        </div>
                    </div>
                </div>
                <div class="item-actions">
                    <a href="?edit=<?php echo $m['id']; ?>" class="action-edit">EDIT</a>
                    <a href="#" onclick="deleteMosaic(<?php echo $m['id']; ?>);return false;" class="action-delete">DELETE</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
        function deleteMosaic(id) {
            if (!confirm('Delete mosaic #' + id + '? Any [mosaic:' + id + '] shortcodes will stop rendering.')) return;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'smack-mosaics.php', true);
            xhr.setRequestHeader('Content-Type',     'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () { location.reload(); };
            xhr.send('action=delete_mosaic&mosaic_id=' + id);
        }
        </script>
    <?php endif; ?>

<?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
