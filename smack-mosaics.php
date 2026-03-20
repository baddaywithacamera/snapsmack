<?php
/**
 * SNAPSMACK - Mosaic Album Builder
 * Alpha v0.7.5
 *
 * Create and manage image mosaics for inline embedding via [mosaic:ID].
 * Pick assets from the media library, drag to reorder, preview the
 * tiled layout, and save. Renders via ss-engine-mosaic.js.
 */

require_once 'core/auth.php';

// --- Ensure table exists (safe to call repeatedly) ---
try {
    $pdo->query("SELECT 1 FROM snap_mosaics LIMIT 0");
} catch (PDOException $e) {
    // Table doesn't exist yet — run migration inline
    $migration = file_get_contents(__DIR__ . '/migrations/migrate-mosaic.sql');
    if ($migration) {
        $pdo->exec($migration);
    }
}

// --- AJAX HANDLERS ---
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Save mosaic (create or update)
if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    header('Content-Type: application/json');

    if ($_POST['action'] === 'save_mosaic') {
        $id        = !empty($_POST['mosaic_id']) ? (int)$_POST['mosaic_id'] : 0;
        $title     = trim($_POST['title'] ?? 'Untitled Mosaic');
        $asset_ids = json_decode($_POST['asset_ids'] ?? '[]', true);
        $gap       = max(0, min(20, (int)($_POST['gap'] ?? 4)));

        if (empty($asset_ids)) {
            echo json_encode(['status' => 'error', 'msg' => 'No assets selected']);
            exit;
        }

        $json_ids = json_encode(array_values($asset_ids));

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE snap_mosaics SET title = ?, asset_ids = ?, gap = ? WHERE id = ?"
            );
            $stmt->execute([$title, $json_ids, $gap, $id]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO snap_mosaics (title, asset_ids, gap) VALUES (?, ?, ?)"
            );
            $stmt->execute([$title, $json_ids, $gap]);
            $id = (int)$pdo->lastInsertId();
        }

        echo json_encode(['status' => 'ok', 'id' => $id, 'shortcode' => '[mosaic:' . $id . ']']);
        exit;
    }

    if ($_POST['action'] === 'delete_mosaic') {
        $id = (int)($_POST['mosaic_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM snap_mosaics WHERE id = ?")->execute([$id]);
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Return asset list as JSON (for the picker)
    if ($_POST['action'] === 'list_assets') {
        $assets = $pdo->query(
            "SELECT id, asset_name, asset_path FROM snap_assets ORDER BY created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($assets);
        exit;
    }
}

// --- PAGE RENDER ---
$page_title = 'Mosaics';

// Load all mosaics for the list view
$mosaics = $pdo->query("SELECT * FROM snap_mosaics ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// If editing, load the mosaic
$editing = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM snap_mosaics WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Load settings for BASE_URL
if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim($settings['site_url'] ?? '/', '/') . '/');
}

include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

        <?php if ($editing || isset($_GET['new'])): ?>
        <!-- ============================================================
             EDITOR MODE
             ============================================================ -->
        <?php
            $mosaic_id    = $editing['id'] ?? 0;
            $mosaic_title = htmlspecialchars($editing['title'] ?? 'Untitled Mosaic');
            $mosaic_ids   = $editing ? json_decode($editing['asset_ids'], true) : [];
            $mosaic_gap   = $editing['gap'] ?? 4;
        ?>

        <div class="header-row header-row--ruled">
            <h2><?php echo $mosaic_id ? 'EDIT MOSAIC #' . $mosaic_id : 'NEW MOSAIC'; ?></h2>
            <div class="header-actions">
                <a href="smack-mosaics.php" class="btn-smack">&larr; BACK TO LIST</a>
            </div>
        </div>

    <div class="box">
        <div class="lens-input-wrapper mb-15">
            <label>TITLE</label>
            <input type="text" id="mosaic-title" value="<?php echo $mosaic_title; ?>" placeholder="Give this mosaic a name">
        </div>

        <div class="mosaic-config-row">
            <div class="lens-input-wrapper mosaic-gap-field">
                <label>GAP (PX)</label>
                <input type="number" id="mosaic-gap" value="<?php echo $mosaic_gap; ?>" min="0" max="20">
            </div>
            <div class="mosaic-shortcode-display">
                <span id="mosaic-shortcode" class="mosaic-shortcode-copy" onclick="navigator.clipboard.writeText(this.textContent)" title="Click to copy">
                    <?php echo $mosaic_id ? '[mosaic:' . $mosaic_id . ']' : '(save to get shortcode)'; ?>
                </span>
            </div>
        </div>

        <!-- SELECTED ASSETS (drag to reorder) -->
        <div class="mb-15">
            <label class="mosaic-section-label">SELECTED ASSETS — drag to reorder</label>
            <div id="mosaic-selected" class="mosaic-dropzone">
                <!-- Populated by JS -->
            </div>
        </div>

        <!-- LIVE PREVIEW -->
        <div class="mb-20">
            <label class="mosaic-section-label">LIVE PREVIEW</label>
            <div id="mosaic-preview" class="snap-mosaic mosaic-preview-area">
                <div class="dim text-center mosaic-empty-preview">Add assets to see preview</div>
            </div>
        </div>

        <!-- ACTION BUTTONS -->
        <div class="mosaic-action-row">
            <button onclick="saveMosaic()" class="btn-smack mosaic-save-btn">SAVE MOSAIC</button>
            <button onclick="document.getElementById('asset-picker').classList.toggle('mosaic-picker-open')" class="btn-smack btn-settings">+ ADD ASSETS</button>
        </div>

        <!-- ASSET PICKER (hidden by default) -->
        <div id="asset-picker" class="mosaic-picker">
            <div class="mosaic-picker-header">
                <h3>Media Library</h3>
                <button onclick="document.getElementById('asset-picker').classList.remove('mosaic-picker-open')" class="mosaic-picker-close">&times;</button>
            </div>
            <div id="asset-picker-grid" class="mosaic-picker-grid">
                <!-- Populated by JS -->
            </div>
        </div>

        <script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic.js"></script>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-mosaic.css">
        <script>
        (function() {
            var BASE = '<?php echo BASE_URL; ?>';
            var selectedAssets = <?php echo json_encode($mosaic_ids); ?>;
            var allAssets = {};  // id → {id, asset_name, asset_path}
            var mosaicId = <?php echo $mosaic_id; ?>;
            var dragSrcIndex = null;

            // --- Load assets from server ---
            function loadAssets() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'smack-mosaics.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var assets = JSON.parse(xhr.responseText);
                        assets.forEach(function(a) { allAssets[a.id] = a; });
                        renderPickerGrid();
                        renderSelected();
                        updatePreview();
                    }
                };
                xhr.send('action=list_assets');
            }

            // --- Render the asset picker grid ---
            function renderPickerGrid() {
                var grid = document.getElementById('asset-picker-grid');
                var html = '';
                var webExts = ['jpg','jpeg','png','gif','webp','svg','avif','bmp'];

                Object.keys(allAssets).forEach(function(id) {
                    var a = allAssets[id];
                    var ext = a.asset_path.split('.').pop().toLowerCase();
                    var isImage = webExts.indexOf(ext) !== -1;
                    var isSelected = selectedAssets.indexOf(parseInt(id)) !== -1;

                    html += '<div class="picker-asset" data-id="' + id + '" onclick="toggleAsset(' + id + ')" ' +
                            'style="cursor:pointer;border:2px solid ' + (isSelected ? 'var(--accent)' : 'transparent') + ';border-radius:4px;overflow:hidden;aspect-ratio:1;position:relative;background:#222;">';
                    if (isImage) {
                        html += '<img src="' + BASE + a.asset_path + '" style="width:100%;height:100%;object-fit:cover;">';
                    } else {
                        html += '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-dim);">' + ext.toUpperCase() + '</div>';
                    }
                    if (isSelected) {
                        html += '<div style="position:absolute;top:4px;right:4px;background:var(--accent);color:#fff;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:bold;">&#10003;</div>';
                    }
                    html += '</div>';
                });

                grid.innerHTML = html;
            }

            // --- Toggle an asset in/out of the selection ---
            window.toggleAsset = function(id) {
                id = parseInt(id);
                var idx = selectedAssets.indexOf(id);
                if (idx === -1) {
                    selectedAssets.push(id);
                } else {
                    selectedAssets.splice(idx, 1);
                }
                renderPickerGrid();
                renderSelected();
                updatePreview();
            };

            // --- Render selected assets (draggable) ---
            function renderSelected() {
                var container = document.getElementById('mosaic-selected');
                if (selectedAssets.length === 0) {
                    container.innerHTML = '<div style="color:var(--text-dim);padding:20px;width:100%;text-align:center;">Click "+ ADD ASSETS" to select images</div>';
                    return;
                }
                var html = '';
                selectedAssets.forEach(function(id, i) {
                    var a = allAssets[id];
                    if (!a) return;
                    html += '<div class="selected-thumb" draggable="true" data-index="' + i + '" ' +
                            'style="width:80px;height:80px;border-radius:4px;overflow:hidden;cursor:grab;position:relative;border:2px solid var(--border);flex-shrink:0;" ' +
                            'ondragstart="dragStart(event,' + i + ')" ondragover="dragOver(event)" ondrop="dragDrop(event,' + i + ')">' +
                            '<img src="' + BASE + a.asset_path + '" style="width:100%;height:100%;object-fit:cover;">' +
                            '<div onclick="event.stopPropagation();removeAsset(' + i + ')" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.7);color:#ff4444;cursor:pointer;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:bold;">&times;</div>' +
                            '</div>';
                });
                container.innerHTML = html;
            }

            window.removeAsset = function(index) {
                selectedAssets.splice(index, 1);
                renderPickerGrid();
                renderSelected();
                updatePreview();
            };

            // --- Drag and drop reordering ---
            window.dragStart = function(e, index) {
                dragSrcIndex = index;
                e.dataTransfer.effectAllowed = 'move';
            };
            window.dragOver = function(e) { e.preventDefault(); };
            window.dragDrop = function(e, targetIndex) {
                e.preventDefault();
                if (dragSrcIndex === null || dragSrcIndex === targetIndex) return;
                var item = selectedAssets.splice(dragSrcIndex, 1)[0];
                selectedAssets.splice(targetIndex, 0, item);
                dragSrcIndex = null;
                renderSelected();
                updatePreview();
            };

            // --- Update live preview ---
            function updatePreview() {
                var container = document.getElementById('mosaic-preview');
                if (selectedAssets.length === 0) {
                    container.innerHTML = '<div style="color:var(--text-dim);text-align:center;padding:40px;">Add assets to see preview</div>';
                    container.removeAttribute('data-mosaic');
                    return;
                }

                var gap = parseInt(document.getElementById('mosaic-gap').value) || 4;
                var images = [];

                selectedAssets.forEach(function(id) {
                    var a = allAssets[id];
                    if (!a) return;
                    images.push({
                        src: BASE + a.asset_path,
                        width: 800, height: 600,  // Placeholder — engine will handle
                        alt: a.asset_name,
                        id: id
                    });
                });

                container.setAttribute('data-mosaic', JSON.stringify(images));
                container.setAttribute('data-gap', gap);

                // Preload images to get real dimensions, then render
                var loaded = 0;
                var total = images.length;

                images.forEach(function(img, i) {
                    var test = new Image();
                    test.onload = function() {
                        images[i].width = test.naturalWidth;
                        images[i].height = test.naturalHeight;
                        loaded++;
                        if (loaded === total) {
                            container.setAttribute('data-mosaic', JSON.stringify(images));
                            if (window.SnapMosaic) {
                                window.SnapMosaic.renderMosaic(container);
                            }
                        }
                    };
                    test.onerror = function() {
                        loaded++;
                        if (loaded === total) {
                            container.setAttribute('data-mosaic', JSON.stringify(images));
                            if (window.SnapMosaic) {
                                window.SnapMosaic.renderMosaic(container);
                            }
                        }
                    };
                    test.src = img.src;
                });
            }

            // Re-preview when gap changes
            document.getElementById('mosaic-gap').addEventListener('input', updatePreview);

            // --- Save mosaic ---
            window.saveMosaic = function() {
                var title = document.getElementById('mosaic-title').value.trim() || 'Untitled Mosaic';
                var gap = parseInt(document.getElementById('mosaic-gap').value) || 4;

                if (selectedAssets.length === 0) {
                    alert('Select at least one asset.');
                    return;
                }

                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'smack-mosaics.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.status === 'ok') {
                            mosaicId = resp.id;
                            document.getElementById('mosaic-shortcode').textContent = resp.shortcode;
                            // Update URL without reload
                            history.replaceState(null, '', 'smack-mosaics.php?edit=' + resp.id);
                            alert('Mosaic saved! Shortcode: ' + resp.shortcode);
                        } else {
                            alert('Error: ' + resp.msg);
                        }
                    }
                };
                xhr.send('action=save_mosaic&mosaic_id=' + mosaicId +
                         '&title=' + encodeURIComponent(title) +
                         '&asset_ids=' + encodeURIComponent(JSON.stringify(selectedAssets)) +
                         '&gap=' + gap);
            };

            // Init
            loadAssets();
        })();
        </script>

        <?php else: ?>
        <!-- ============================================================
             LIST MODE
             ============================================================ -->
    </div>

    <div class="header-row header-row--ruled">
        <h2>MOSAICS</h2>
        <div class="header-actions">
            <a href="smack-mosaics.php?new=1" class="btn-smack">+ NEW MOSAIC</a>
        </div>
    </div>

    <div class="box">
        <?php if (empty($mosaics)): ?>
            <p class="dim text-center empty-notice">No mosaics yet. Create one to embed tiled image groups in your posts and pages.</p>
        <?php else: ?>
            <table class="wp-list-table mosaic-list-table">
                <thead>
                    <tr>
                        <th class="mosaic-col-id">ID</th>
                        <th>Title</th>
                        <th class="mosaic-col-count">Images</th>
                        <th class="mosaic-col-shortcode">Shortcode</th>
                        <th class="mosaic-col-date">Updated</th>
                        <th class="mosaic-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mosaics as $m):
                    $ids = json_decode($m['asset_ids'], true);
                    $count = is_array($ids) ? count($ids) : 0;
                ?>
                    <tr>
                        <td><?php echo $m['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($m['title']); ?></strong></td>
                        <td><?php echo $count; ?> image<?php echo $count !== 1 ? 's' : ''; ?></td>
                        <td>
                            <code class="mosaic-shortcode-copy" onclick="navigator.clipboard.writeText(this.textContent)" title="Click to copy">[mosaic:<?php echo $m['id']; ?>]</code>
                        </td>
                        <td class="dim mosaic-date"><?php echo date('M j, Y', strtotime($m['updated_at'])); ?></td>
                        <td>
                            <a href="smack-mosaics.php?edit=<?php echo $m['id']; ?>" class="action-edit">EDIT</a>
                            <a href="#" onclick="deleteMosaic(<?php echo $m['id']; ?>);return false;" class="action-delete">DELETE</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

        <script>
        function deleteMosaic(id) {
            if (!confirm('Delete this mosaic? Any [mosaic:' + id + '] shortcodes will stop rendering.')) return;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'smack-mosaics.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() { location.reload(); };
            xhr.send('action=delete_mosaic&mosaic_id=' + id);
        }
        </script>

        <?php endif; ?>

</div>

<?php include 'core/admin-footer.php'; ?>
</html>
