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
require_once 'core/bucket.php';

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
// Updated installs receive this from canonical schema sync; keep the builder
// defensive for sites that reach this screen before their next sync pass.
try {
    $focus_col = $pdo->query("SHOW COLUMNS FROM snap_mosaics LIKE 'focus_positions'")->fetch(PDO::FETCH_ASSOC);
    if (!$focus_col) {
        $pdo->exec("ALTER TABLE snap_mosaics ADD COLUMN focus_positions LONGTEXT NULL AFTER asset_ids");
    }
    // ARRANGEMENT (emphasis). ss-engine-mosaic.js has always been able to build
    // asymmetric blocks — it reads data-emphasis and defaults to 'natural', the
    // flattest of the four. SCROLL's wall passes it; the longform [mosaic:ID]
    // path never did, so every essay mosaic silently got the do-nothing default.
    // Stored per mosaic so one essay can lean portrait and the next landscape.
    $emph_col = $pdo->query("SHOW COLUMNS FROM snap_mosaics LIKE 'emphasis'")->fetch(PDO::FETCH_ASSOC);
    if (!$emph_col) {
        $pdo->exec("ALTER TABLE snap_mosaics ADD COLUMN emphasis VARCHAR(12) NOT NULL DEFAULT 'natural' AFTER gap");
    }
    // LAYOUT. The same four the SCROLL wall offers, driven by the same four
    // shipped engines — asymmetric via ss-engine-mosaic.js, columns and square
    // via ss-engine-columns.js, rows via ss-engine-rows.js. 'asymmetric' keeps
    // the pre-0.7.532 behaviour, so nothing already published moves.
    $layout_col = $pdo->query("SHOW COLUMNS FROM snap_mosaics LIKE 'layout'")->fetch(PDO::FETCH_ASSOC);
    if (!$layout_col) {
        $pdo->exec("ALTER TABLE snap_mosaics ADD COLUMN layout VARCHAR(12) NOT NULL DEFAULT 'asymmetric' AFTER emphasis");
    }
} catch (PDOException $e) {
    // Canonical schema sync remains authoritative if this defensive add fails.
}

snap_bucket_ensure($pdo);

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
        $scope   = ($_POST['scope'] ?? 'all') === 'bucket' ? 'bucket' : 'all';
        $post_id = (int)($_POST['post_id'] ?? 0);
        $q       = trim((string)($_POST['q'] ?? ''));

        // Scoping to a post's BUCKET is the whole point of the picker knowing
        // which post it was opened from: a dozen tiles instead of the entire
        // Gallery in one endless scroll.
        $bucket_ids = ($scope === 'bucket') ? snap_bucket_ids($pdo, $post_id) : [];
        if ($scope === 'bucket' && !$bucket_ids) {
            echo json_encode([
                'images' => [], 'total' => 0, 'shown' => 0, 'capped' => false,
                'used' => (object)[], 'scope' => 'bucket',
            ]);
            exit;
        }

        $where  = ["img_status = 'published'"];
        $params = [];
        if ($scope === 'bucket') {
            $where[] = 'id IN (' . implode(',', array_fill(0, count($bucket_ids), '?')) . ')';
            $params  = array_merge($params, $bucket_ids);
        }
        if ($q !== '') {
            $where[]  = '(img_title LIKE ? OR img_file LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        // How many actually match, BEFORE the cap. The picker prints this so a
        // truncated list can never masquerade as "that is all the photos you
        // have" — which is exactly what the old bare LIMIT 500 did silently.
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images $where_sql");
        $count_stmt->execute($params);
        $total = (int)$count_stmt->fetchColumn();

        // A bucket is small and hand-picked, so show all of it. The whole
        // Gallery still needs a ceiling or the grid renders thousands of tiles;
        // search narrows server-side, so nothing is unreachable.
        $cap = ($scope === 'bucket') ? 2000 : 500;

        $stmt = $pdo->prepare(
            "SELECT id,
                    img_title AS asset_name,
                    COALESCE(NULLIF(img_thumb_aspect, ''), img_file) AS asset_path
             FROM snap_images
             $where_sql
             ORDER BY id DESC
             LIMIT $cap"
        );
        $stmt->execute($params);
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ALWAYS fetch the photos this mosaic already contains, whatever the
        // scope, the search or the cap. Without this, a mosaic holding a photo
        // older than the newest 500 renders a gap in its own strip and preview —
        // the photo is still saved, but it looks like it was lost.
        //
        // Returned SEPARATELY from the scoped list so the "showing X of Y" line
        // stays true to the scope, and the grid keeps showing what was asked for.
        $keep = json_decode($_POST['keep'] ?? '[]', true);
        $keep = is_array($keep) ? array_values(array_unique(array_map('intval', $keep))) : [];
        $have = array_map(function ($a) { return (int)$a['id']; }, $assets);
        $missing = array_values(array_diff($keep, $have));
        $extra_rows = [];
        if ($missing) {
            $ph = implode(',', array_fill(0, count($missing), '?'));
            $extra = $pdo->prepare(
                "SELECT id,
                        img_title AS asset_name,
                        COALESCE(NULLIF(img_thumb_aspect, ''), img_file) AS asset_path
                 FROM snap_images WHERE id IN ($ph)"
            );
            $extra->execute($missing);
            $extra_rows = $extra->fetchAll(PDO::FETCH_ASSOC);
        }

        // Bucket order is the order the photos were arranged in the post, not
        // newest-first — the sequence is editorial, so honour it.
        if ($scope === 'bucket') {
            $rank = array_flip($bucket_ids);
            usort($assets, function ($a, $b) use ($rank) {
                return ($rank[(int)$a['id']] ?? PHP_INT_MAX) <=> ($rank[(int)$b['id']] ?? PHP_INT_MAX);
            });
        }

        echo json_encode([
            'images' => $assets,
            'extra'  => $extra_rows,
            'total'  => $total,
            'shown'  => count($assets),
            'capped' => $total > count($assets),
            'used'   => (object)snap_bucket_mosaic_usage($pdo),
            'scope'  => $scope,
        ]);
        exit;
    }

    if ($_POST['action'] === 'save_mosaic') {
        $id        = !empty($_POST['mosaic_id']) ? (int)$_POST['mosaic_id'] : 0;
        $title     = trim($_POST['title'] ?? '') ?: 'Untitled Mosaic';
        $asset_ids = json_decode($_POST['asset_ids'] ?? '[]', true);
        $focus      = json_decode($_POST['focus_positions'] ?? '{}', true);
        $gap       = max(0, min(20, (int)($_POST['gap'] ?? 4)));
        // Must match ss-engine-mosaic.js's accepted set; anything else collapses
        // to 'natural' there anyway, so reject it here rather than store junk.
        $emphasis  = (string)($_POST['emphasis'] ?? 'natural');
        if (!in_array($emphasis, ['natural', 'balanced', 'landscape', 'portrait'], true)) {
            $emphasis = 'natural';
        }
        $layout    = (string)($_POST['layout'] ?? 'asymmetric');
        if (!in_array($layout, ['asymmetric', 'columns', 'rows', 'square'], true)) {
            $layout = 'asymmetric';
        }

        if (empty($asset_ids)) {
            echo json_encode(['ok' => false, 'error' => 'Select at least one image.']);
            exit;
        }

        $json_ids = json_encode(array_values($asset_ids));
        $json_focus = json_encode(is_array($focus) ? $focus : new stdClass());

        if ($id > 0) {
            $pdo->prepare("UPDATE snap_mosaics SET title = ?, asset_ids = ?, focus_positions = ?, gap = ?, emphasis = ?, layout = ? WHERE id = ?")
                ->execute([$title, $json_ids, $json_focus, $gap, $emphasis, $layout, $id]);
        } else {
            $pdo->prepare("INSERT INTO snap_mosaics (title, asset_ids, focus_positions, gap, emphasis, layout) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$title, $json_ids, $json_focus, $gap, $emphasis, $layout]);
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

// Which post's BUCKET this builder was opened against, if any. The longform
// editor's "build a mosaic" link carries ?post=ID so the picker can open already
// narrowed to that essay's photos instead of the whole Gallery.
$bucket_post_id    = (int)($_GET['post'] ?? 0);
$bucket_post_title = '';
$bucket_count      = 0;
if ($bucket_post_id > 0) {
    $stmt = $pdo->prepare("SELECT title FROM snap_posts WHERE id = ?");
    $stmt->execute([$bucket_post_id]);
    $bucket_post_title = (string)($stmt->fetchColumn() ?: '');
    if ($bucket_post_title === '') {
        $bucket_post_id = 0;              // stale or deleted post — fall back to the whole Gallery
    } else {
        $bucket_count = count(snap_bucket_ids($pdo, $bucket_post_id));
    }
}
// Posts that HAVE a bucket, so a builder opened cold from the sidebar can still
// pick one rather than only ever offering the whole Gallery.
$bucket_posts = snap_bucket_posts($pdo);

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
    $mosaic_focus = $editing ? (json_decode($editing['focus_positions'] ?? '{}', true) ?: []) : [];
    $mosaic_gap   = (int)($editing['gap']    ?? 4);
    // A NEW mosaic defaults to 'landscape', matching SCROLL's wall (its
    // scroll_mosaic_emphasis default). 'natural' is the flattest option, so
    // defaulting new mosaics to it meant every one started looking like the old
    // pre-0.7.530 behaviour. An EXISTING mosaic keeps whatever it was saved with.
    $mosaic_emph  = (string)($editing['emphasis'] ?? ($editing ? 'natural' : 'landscape'));
    if (!in_array($mosaic_emph, ['natural', 'balanced', 'landscape', 'portrait'], true)) $mosaic_emph = 'natural';
    $mosaic_layout = (string)($editing['layout'] ?? 'asymmetric');
    if (!in_array($mosaic_layout, ['asymmetric', 'columns', 'rows', 'square'], true)) $mosaic_layout = 'asymmetric';
    ?>

    <div class="header-row header-row--ruled">
        <h2><?php echo $mosaic_id ? 'EDIT MOSAIC #' . $mosaic_id : 'NEW MOSAIC'; ?></h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <?php /* Built from a post, the way back is to THAT POST, not a mosaic
                     list — you came here to make something to drop into an essay,
                     and the shortcode is no use until you are back in it. */ ?>
            <?php if ($bucket_post_id): ?>
            <a href="smack-post-long.php?edit=<?php echo $bucket_post_id; ?>" class="btn-secondary">← BACK TO “<?php echo htmlspecialchars($bucket_post_title); ?>”</a>
            <?php endif; ?>
            <a href="smack-mosaics.php" class="btn-secondary">← BACK TO LIST</a>
        </div>
    </div>

    <?php if ($bucket_post_id): ?>
    <div class="box" style="margin-bottom:14px;border-left:3px solid var(--accent);">
        <p style="margin:0;font-size:12px;">
            Building for <strong><?php echo htmlspecialchars($bucket_post_title); ?></strong> —
            the picker opens showing that post's bucket
            (<?php echo $bucket_count; ?> photo<?php echo $bucket_count === 1 ? '' : 's'; ?>).
            <?php if ($bucket_count === 0): ?>
            <span class="dim">That bucket is empty — fill it in the post editor, or switch the picker to All gallery photos.</span>
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="post-layout-grid">
        <div class="post-col-left">
            <div class="box">
                <div class="lens-input-wrapper">
                    <label>TITLE</label>
                    <input type="text" id="mosaic-title" value="<?php echo $mosaic_title; ?>" placeholder="Give this mosaic a name">
                </div>

                <div style="display:flex;gap:16px;align-items:flex-end;margin-top:16px;">
                    <div class="lens-input-wrapper flex-none mt-0">
                        <label>GAP (PX)</label>
                        <input type="number" id="mosaic-gap" value="<?php echo $mosaic_gap; ?>" min="0" max="20" style="width:80px;">
                    </div>
                    <div class="lens-input-wrapper flex-none mt-0">
                        <label>LAYOUT</label>
                        <?php /* The same four the SCROLL wall offers, same wording. HERO
                                 EMPHASIS below only affects Asymmetric, so the JS hides it
                                 for the other three rather than leaving a dead control. */ ?>
                        <select id="mosaic-layout" style="width:230px;">
                            <?php foreach ([
                                'columns'    => 'Columns (portraits stand tallest)',
                                'rows'       => 'Rows (landscapes lead)',
                                'square'     => 'Square (even cropped grid)',
                                'asymmetric' => 'Asymmetric (MOSAIC quilt)',
                            ] as $_l_val => $_l_label): ?>
                            <option value="<?php echo $_l_val; ?>" <?php echo $mosaic_layout === $_l_val ? 'selected' : ''; ?>><?php echo $_l_label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lens-input-wrapper flex-none mt-0" id="emphasis-wrap">
                        <label>HERO EMPHASIS</label>
                        <?php /* Wording matches SCROLL's "MOSAIC Hero Emphasis" control
                                 (skins/scroll/manifest.json) so the same setting is not
                                 called two different things in two places. The stored
                                 values are identical either way. */ ?>
                        <select id="mosaic-emphasis" style="width:230px;" title="Which shape leads each block. Follow Library Order keeps every photo near its own size — the flattest result. The Favor settings pick a hero of that shape and build the block around it, which is what gives you the asymmetric quilt.">
                            <?php foreach ([
                                'natural'   => 'Follow Library Order',
                                'balanced'  => 'Balance Portraits &amp; Landscapes',
                                'landscape' => 'Favor Landscapes',
                                'portrait'  => 'Favor Portraits',
                            ] as $_e_val => $_e_label): ?>
                            <option value="<?php echo $_e_val; ?>" <?php echo $mosaic_emph === $_e_val ? 'selected' : ''; ?>><?php echo $_e_label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-1">
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
                    <label>SELECTED IMAGES — drag to reorder, use arrows to move, × to remove</label>
                    <?php /* The CONTAINER accepts drops too, not just the tiles. Dropping in
                             the gaps or past the last tile used to hit an element with no
                             dragover handler, so the browser showed the no-entry cursor and
                             refused — indistinguishable from "drag is broken". Dropping on
                             open space now means "move to the end". */ ?>
                    <div id="mosaic-selected" ondragover="dragOver(event)" ondrop="dragDropEnd(event)" style="display:flex;flex-wrap:wrap;gap:8px;min-height:80px;padding:12px;border:1px solid var(--border);border-radius:3px;background:var(--input-bg);margin-top:6px;"></div>
                </div>

                <div class="lens-input-wrapper mt-16">
                    <button type="button" onclick="togglePicker()" class="btn-secondary w-100">+ ADD IMAGES FROM MEDIA GALLERY</button>
                </div>

                <!-- ASSET PICKER (hidden) -->
                <div id="asset-picker" style="display:none;margin-top:12px;border:1px solid var(--border);border-radius:3px;padding:12px;background:var(--card-bg);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--dim);">CHOOSE PHOTOS</span>
                        <button type="button" onclick="togglePicker()" style="background:none;border:none;color:var(--dim);cursor:pointer;font-size:18px;line-height:1;">×</button>
                    </div>

                    <?php /* SCOPE is the answer to "one endless scroll". Narrowing to a
                             post's BUCKET turns the whole Gallery into the dozen photos
                             that essay is actually built from. */ ?>
                    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;">
                        <div class="lens-input-wrapper flex-none mt-0">
                            <label>SHOWING</label>
                            <select id="picker-scope" style="width:280px;">
                                <option value="all" <?php echo $bucket_post_id ? '' : 'selected'; ?>>All gallery photos</option>
                                <?php /* Opened from a post, that post's bucket leads and is
                                         preselected. Opened cold from the sidebar, every post
                                         that HAS a bucket is still offered, so the narrowing
                                         is not something only one entry point can reach. */ ?>
                                <?php
                                $listed = [];
                                if ($bucket_post_id) {
                                    $listed[$bucket_post_id] = true;
                                    ?>
                                    <option value="bucket:<?php echo $bucket_post_id; ?>" selected>Bucket — <?php echo htmlspecialchars($bucket_post_title); ?> (<?php echo $bucket_count; ?>)</option>
                                    <?php
                                }
                                foreach ($bucket_posts as $bp):
                                    if (isset($listed[(int)$bp['id']])) continue;
                                ?>
                                <option value="bucket:<?php echo (int)$bp['id']; ?>">Bucket — <?php echo htmlspecialchars($bp['title']); ?> (<?php echo (int)$bp['bucket_count']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="lens-input-wrapper" style="flex:1;min-width:180px;margin-top:0;">
                            <label>SEARCH BY NAME</label>
                            <input type="text" id="picker-search" placeholder="Type part of a filename or title" class="w-100">
                        </div>
                    </div>

                    <?php /* Greyed and labelled, never hidden. A photo can legitimately
                             belong to two mosaics (a recurring motif, a reused cover), so
                             removing it from the picker outright would be a wall with no
                             door — and a vanished photo reads as a bug, not a rule. */ ?>
                    <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--dim);margin-bottom:10px;cursor:pointer;">
                        <input type="checkbox" id="picker-hide-used" style="width:16px;height:16px;">
                        Hide photos already used in another mosaic
                    </label>

                    <div id="picker-count" style="font-size:11px;color:var(--dim);margin-bottom:2px;"></div>
                    <div style="font-size:11px;color:var(--dim);margin-bottom:8px;">Click a photo to pick it. <strong>Shift-click</strong> to grab everything between it and your last pick.</div>
                    <?php /* user-select:none — shift-click otherwise highlights the run of
                             tiles as text instead of selecting photos. */ ?>
                    <div id="asset-picker-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:6px;max-height:360px;overflow-y:auto;user-select:none;"></div>
                </div>

            </div>
        </div>
    </div>

    <!-- LIVE PREVIEW — full width, deliberately OUTSIDE .post-layout-grid.
         A mosaic spans the post's content container, and the packer groups the
         photos to fit whatever width it is handed. Sitting in a half-width grid
         column it solved for ~50% of the page and produced a DIFFERENT layout to
         the published one — not a smaller preview, a wrong one. Do not move it
         back inside the two-column grid. -->
    <div class="box mt-20">
        <div class="lens-input-wrapper">
            <label>LIVE PREVIEW</label>
            <div class="gp-pan-hint" style="font:10px/1.3 monospace;opacity:.55;margin:5px 0 0;">drag a cropped photo to reposition it before saving</div>
            <div class="mosaic-preview-wrap" id="mosaic-preview-wrap">
                <div id="mosaic-preview" class="snap-mosaic">
                    <p class="dim" style="text-align:center;padding:20px 0;margin:0;">Add images to see preview.</p>
                </div>
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-mosaic.css">
    <script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic.js" defer></script>
    <?php /* The preview offers all four layouts, so it needs the same engines and
             chrome the published essay gets. ss-engine-scroll-wall.css is chrome
             only (borders, hover) — the engines write geometry inline. */ ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-scroll-wall.css">
    <script src="<?php echo BASE_URL; ?>assets/js/ss-engine-columns.js" defer></script>
    <script src="<?php echo BASE_URL; ?>assets/js/ss-engine-rows.js" defer></script>
    <script>
    (function () {
        var BASE          = <?php echo json_encode(BASE_URL); ?>;
        var mosaicId      = <?php echo $mosaic_id; ?>;
        var selectedIds   = <?php echo json_encode($mosaic_ids); ?>;
        var focusPositions = <?php echo json_encode($mosaic_focus); ?>;
        var allAssets     = {};   // id → {id, asset_name, asset_path}
        var pickerOrder   = [];   // ids in the order the server returned them
        var lastPickIndex = null; // anchor for shift-click range selection
        var usedInMosaic  = {};   // id → "Mosaic title (#7)" for photos already placed
        var searchTimer   = null;
        var dragSrcIndex  = null;
        var pickerOpen    = false;

        // --- Bootstrap ---
        document.addEventListener('DOMContentLoaded', function () {
            loadAssets();
            document.getElementById('mosaic-gap').addEventListener('input', updatePreview);
            document.getElementById('mosaic-emphasis').addEventListener('change', updatePreview);
            document.getElementById('mosaic-layout').addEventListener('change', function () {
                syncLayoutControls();
                updatePreview();
            });
            syncLayoutControls();

            document.getElementById('picker-scope').addEventListener('change', loadAssets);
            document.getElementById('picker-hide-used').addEventListener('change', renderPickerGrid);
            // Debounced: the search runs server-side so it can reach past the
            // display cap, but not on every keystroke.
            document.getElementById('picker-search').addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(loadAssets, 250);
            });
        });

        function loadAssets() {
            // "all", or "bucket:<postId>" — the post is carried in the value so
            // the dropdown can offer any post's bucket, not only the one this
            // builder happened to be opened from.
            var raw    = document.getElementById('picker-scope').value;
            var isBkt  = raw.indexOf('bucket:') === 0;
            var scope  = isBkt ? 'bucket' : 'all';
            var postId = isBkt ? parseInt(raw.split(':')[1], 10) : 0;
            var q      = document.getElementById('picker-search').value.trim();

            // keep: the photos already in this mosaic. The server returns them
            // whatever the scope or cap, so narrowing the picker can never blank
            // the tiles of photos this mosaic actually contains.
            ajax('list_assets', { scope: scope, post_id: postId, q: q, keep: JSON.stringify(selectedIds) }, function (resp) {
                pickerOrder  = [];
                lastPickIndex = null;   // grid order just changed; old anchor is meaningless
                usedInMosaic = resp.used || {};
                (resp.images || []).forEach(function (a) {
                    allAssets[a.id] = a;
                    pickerOrder.push(parseInt(a.id, 10));
                });
                // Known, so the strip and preview can draw them — but deliberately
                // NOT in pickerOrder, so the grid still shows only what the scope
                // and search asked for.
                (resp.extra || []).forEach(function (a) { allAssets[a.id] = a; });
                renderPickerCount(resp);
                renderPickerGrid();
                renderSelected();
                updatePreview();
            });
        }

        function renderPickerCount(resp) {
            var el = document.getElementById('picker-count');
            if (!el) return;
            var total = resp.total || 0;
            if (total === 0) {
                el.textContent = resp.scope === 'bucket'
                    ? 'This post has no photos in its bucket yet. Add them in the post editor, or switch to All gallery photos.'
                    : 'No photos match.';
                return;
            }
            // Never let a capped list look like the whole list.
            el.textContent = resp.capped
                ? 'Showing the ' + resp.shown + ' newest of ' + total + ' matching photos — search by name to reach the rest.'
                : 'Showing all ' + total + ' photo' + (total === 1 ? '' : 's') + '.';
        }

        // --- Picker toggle ---
        window.togglePicker = function () {
            var el = document.getElementById('asset-picker');
            pickerOpen = !pickerOpen;
            el.style.display = pickerOpen ? 'block' : 'none';
        };

        // --- Picker grid ---
        function renderPickerGrid() {
            var grid     = document.getElementById('asset-picker-grid');
            var hideUsed = document.getElementById('picker-hide-used').checked;
            var html     = '';
            var hidden   = 0;
            var webExts  = ['jpg','jpeg','png','gif','webp','avif','svg','bmp'];

            // pickerOrder, not Object.keys(allAssets): allAssets accumulates every
            // photo ever loaded this session so selected tiles survive a scope
            // change, but the GRID must only show the current scope's photos.
            pickerOrder.forEach(function (idNum) {
                var a = allAssets[idNum];
                if (!a) return;
                var id   = idNum;
                var ext  = (a.asset_path.split('.').pop() || '').toLowerCase();
                var sel  = selectedIds.indexOf(id) !== -1;
                var used = usedInMosaic[id];

                // A photo already in THIS mosaic is not "used elsewhere" — hiding
                // it would make deselecting it impossible.
                if (used && !sel && hideUsed) { hidden++; return; }

                var dim = (used && !sel) ? 'opacity:.42;' : '';
                html += '<div onclick="toggleAsset(event,' + id + ')" title="' + (used && !sel ? 'Already in ' + esc(used) : (a.asset_path.split('/').pop() || ''))
                      + '" style="cursor:pointer;position:relative;aspect-ratio:1;' + dim
                      + 'border:2px solid ' + (sel ? 'var(--accent)' : 'transparent') + ';border-radius:3px;overflow:hidden;background:var(--input-bg, #111);">';
                if (webExts.indexOf(ext) !== -1) {
                    // contain, not cover: a cropped square makes a portrait, a landscape
                    // and a square look identical — useless when you are picking photos
                    // for an arrangement that is entirely about their shape.
                    html += '<img src="' + BASE + a.asset_path + '" class="img-contain" loading="lazy">';
                } else {
                    html += '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--dim);font-size:10px;">' + ext.toUpperCase() + '</div>';
                }
                if (sel) {
                    html += '<div style="position:absolute;top:3px;right:3px;background:var(--accent);color:var(--text-muted, #111);'
                          + 'border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;">✓</div>';
                }
                // Say WHY it is greyed out. A dimmed tile with no reason on it is
                // indistinguishable from a broken thumbnail.
                if (used && !sel) {
                    html += '<div style="position:absolute;left:0;right:0;bottom:0;background:rgba(0,0,0,.72);color:var(--text, #fff);'
                          + 'font-size:9px;line-height:1.25;padding:2px 3px;text-align:center;">IN ' + esc(used) + '</div>';
                }
                html += '</div>';
            });

            if (!html) {
                grid.innerHTML = '<p style="color:var(--dim);font-size:12px;grid-column:1/-1;">'
                               + (hidden ? 'All ' + hidden + ' photo' + (hidden === 1 ? ' is' : 's are') + ' already used in another mosaic. Untick the box above to see them.'
                                         : 'No photos found.')
                               + '</p>';
                return;
            }
            if (hidden) {
                html += '<p style="color:var(--dim);font-size:11px;grid-column:1/-1;margin:6px 0 0;">'
                      + hidden + ' photo' + (hidden === 1 ? '' : 's') + ' hidden — already used in another mosaic.</p>';
            }
            grid.innerHTML = html;
        }

        function esc(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        window.toggleAsset = function (evt, id) {
            id = parseInt(id, 10);
            var idx = pickerOrder.indexOf(id);

            if (evt && evt.shiftKey && lastPickIndex !== null && idx !== -1) {
                // Shift-click grabs the whole run between your last pick and this
                // one, and only ADDS — a range drag can build the selection but
                // never silently strip it.
                var lo = Math.min(lastPickIndex, idx), hi = Math.max(lastPickIndex, idx);
                for (var k = lo; k <= hi; k++) {
                    var rid = pickerOrder[k];
                    if (selectedIds.indexOf(rid) === -1) selectedIds.push(rid);
                }
            } else {
                var i = selectedIds.indexOf(id);
                if (i === -1) selectedIds.push(id); else selectedIds.splice(i, 1);
            }
            lastPickIndex = idx;
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
                      + ' ondragstart="dragStart(event,' + i + ')" ondragend="dragEnd()" ondragover="dragOver(event)" ondragleave="dragLeave(event)" ondrop="dragDrop(event,' + i + ')"'
                      + ' style="position:relative;width:72px;height:72px;border:1px solid var(--border);border-radius:3px;overflow:hidden;cursor:grab;flex-shrink:0;background:var(--input-bg, #111);">'
                      // draggable=false: without it the browser starts dragging the PHOTO
                      // instead of the tile, which is the classic "drag does nothing".
                      // object-fit:contain (not cover): a cropped square hides whether the
                      // photo is portrait, landscape or square — the one thing that matters
                      // when the arrangement setting is about exactly that.
                      + '<img src="' + BASE + a.asset_path + '" draggable="false" style="width:100%;height:100%;object-fit:contain;pointer-events:none;" loading="lazy">'
                      + '<div style="position:absolute;left:2px;bottom:2px;display:flex;gap:2px;">'
                      + '<button type="button" onclick="moveAsset(' + i + ',-1)" title="Move left" aria-label="Move image left"'
                      + ' style="background:rgba(0,0,0,.72);border:none;color:var(--text, #fff);cursor:pointer;width:20px;height:20px;padding:0;line-height:1;">&#8249;</button>'
                      + '<button type="button" onclick="moveAsset(' + i + ',1)" title="Move right" aria-label="Move image right"'
                      + ' style="background:rgba(0,0,0,.72);border:none;color:var(--text, #fff);cursor:pointer;width:20px;height:20px;padding:0;line-height:1;">&#8250;</button>'
                      + '</div>'
                      + '<button type="button" onclick="removeAsset(' + i + ')" title="Remove"'
                      + ' style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.65);border:none;color:var(--danger, #ff5555);cursor:pointer;'
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

        window.moveAsset = function (index, direction) {
            var target = index + direction;
            if (target < 0 || target >= selectedIds.length) return;
            var moved = selectedIds.splice(index, 1)[0];
            selectedIds.splice(target, 0, moved);
            renderSelected();
            updatePreview();
        };

        // --- Drag reorder ---
        // Drag feedback. Previously the reorder worked but looked identical to a
        // failed drag: nothing dimmed, nothing marked where the tile would land.
        function clearDropMarks() {
            var t = document.querySelectorAll('.mosaic-thumb-wrap');
            for (var i = 0; i < t.length; i++) {
                t[i].style.outline = '';
                t[i].style.opacity = '';
            }
        }
        window.dragStart = function (e, i) {
            dragSrcIndex = i;
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', String(i)); } catch (err) {}
            var src = document.querySelector('.mosaic-thumb-wrap[data-index="' + i + '"]');
            if (src) src.style.opacity = '.35';
        };
        window.dragEnd   = function () { dragSrcIndex = null; clearDropMarks(); };
        window.dragOver  = function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var tile = e.target && e.target.closest ? e.target.closest('.mosaic-thumb-wrap') : null;
            if (tile && String(tile.getAttribute('data-index')) !== String(dragSrcIndex)) {
                tile.style.outline = '2px solid var(--accent)';
            }
        };
        window.dragLeave = function (e) {
            var tile = e.target && e.target.closest ? e.target.closest('.mosaic-thumb-wrap') : null;
            if (tile) tile.style.outline = '';
        };
        // Drop on empty space in the strip = move to the end.
        window.dragDropEnd = function (e) {
            if (e.target && e.target.closest && e.target.closest('.mosaic-thumb-wrap')) return;
            e.preventDefault();
            if (dragSrcIndex === null) return;
            var moved = selectedIds.splice(dragSrcIndex, 1)[0];
            selectedIds.push(moved);
            dragSrcIndex = null;
            clearDropMarks();
            renderSelected();
            updatePreview();
        };
        window.dragDrop  = function (e, target) {
            e.preventDefault();
            e.stopPropagation();   // don't also fire the container's "move to end"
            clearDropMarks();
            if (dragSrcIndex === null || dragSrcIndex === target) { dragSrcIndex = null; return; }
            var moved = selectedIds.splice(dragSrcIndex, 1)[0];
            selectedIds.splice(target, 0, moved);
            dragSrcIndex = null;
            renderSelected();
            updatePreview();
        };

        function focalValue(value) {
            value = parseFloat(value);
            return isFinite(value) ? Math.max(0, Math.min(100, value)) : 50;
        }


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
                var focus = focusPositions[id] || { x: 50, y: 50 };
                if (a) images.push({
                    src: BASE + a.asset_path,
                    width: 800,
                    height: 600,
                    alt: a.asset_name,
                    id: id,
                    focusX: focalValue(focus.x),
                    focusY: focalValue(focus.y)
                });
            });

            // Preload to get real dimensions, then render
            var loaded = 0;
            images.forEach(function (img, idx) {
                var t   = new Image();
                t.onload = t.onerror = function () {
                    if (t.naturalWidth) {
                        images[idx].width = t.naturalWidth;
                        allAssets[img.id].naturalWidth = t.naturalWidth;
                    }
                    if (t.naturalHeight) {
                        images[idx].height = t.naturalHeight;
                        allAssets[img.id].naturalHeight = t.naturalHeight;
                    }
                    if (++loaded === images.length) renderWithData(images, gap, container);
                };
                t.src = img.src;
            });
        }

        function currentLayout() {
            var s = document.getElementById('mosaic-layout');
            return s ? s.value : 'asymmetric';
        }

        function renderWithData(images, gap, container) {
            var emphSel = document.getElementById('mosaic-emphasis');
            var layout  = currentLayout();

            // Reset anything the previous layout left behind, or a switch leaves a
            // half-rendered block from the old engine underneath the new one.
            container.removeAttribute('style');
            container.className = (layout === 'asymmetric') ? 'snap-mosaic'
                                : (layout === 'rows')   ? 'ss-scroll-wall snap-mosaic-wall'
                                : (layout === 'square') ? 'ss-square-wall snap-mosaic-wall'
                                                        : 'ss-masonry snap-mosaic-wall';

            if (layout === 'asymmetric') {
                container.innerHTML = '';
                container.setAttribute('data-mosaic', JSON.stringify(images));
                container.setAttribute('data-gap',    gap);
                // The preview must pass the SAME arrangement the published block will
                // carry, or it previews a layout the essay will never render.
                container.setAttribute('data-emphasis', emphSel ? emphSel.value : 'natural');
                if (window.SnapMosaic) {
                    window.SnapMosaic.renderMosaic(container);
                    bindCropPanning(container);
                }
                return;
            }

            // Wall layouts: same markup core/parser.php emits, so the preview and the
            // published essay hand the engines identical input. Shape comes from
            // data-w/data-h, never the loaded image.
            container.removeAttribute('data-mosaic');
            container.removeAttribute('data-emphasis');
            container.style.setProperty('--ss-gap', gap + 'px');
            var html = '';
            images.forEach(function (im) {
                var dims = (im.width && im.height)
                    ? ' data-w="' + im.width + '" data-h="' + im.height + '"' : '';
                html += '<a class="ss-masonry-item" href="' + im.full + '">'
                      + '<img src="' + im.src + '" alt=""' + dims + ' loading="lazy"></a>';
            });
            container.innerHTML = html;

            // Square is native CSS Grid — no engine call. The other two relayout.
            if (layout === 'columns' && window.SSColumns) window.SSColumns.init(container);
            if (layout === 'rows'    && window.SSRows)    window.SSRows.init(container);
        }

        function syncLayoutControls() {
            var wrap = document.getElementById('emphasis-wrap');
            if (wrap) wrap.style.display = (currentLayout() === 'asymmetric') ? '' : 'none';
        }

        // Same focal-point drag model used by the GRAMOFSMACK square crop.
        // Movement is enabled only on an axis where object-fit:cover overflows.
        function bindCropPanning(container) {
            container.querySelectorAll('.mosaic-item img').forEach(function (image) {
                var frame = image.parentElement;
                var id = image.getAttribute('data-asset-id');
                var asset = allAssets[id];
                if (!asset) return;

                var scale = Math.max(
                    frame.clientWidth / asset.naturalWidth,
                    frame.clientHeight / asset.naturalHeight
                );
                var rangeX = asset.naturalWidth * scale - frame.clientWidth;
                var rangeY = asset.naturalHeight * scale - frame.clientHeight;
                if (rangeX <= 1 && rangeY <= 1) return;

                image.style.cursor = 'grab';
                image.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    image.style.cursor = 'grabbing';

                    var focus = focusPositions[id] || { x: 50, y: 50 };
                    var lastX = e.clientX;
                    var lastY = e.clientY;

                    function move(ev) {
                        var dx = ev.clientX - lastX;
                        var dy = ev.clientY - lastY;
                        lastX = ev.clientX;
                        lastY = ev.clientY;

                        if (rangeX > 1) focus.x = Math.max(0, Math.min(100, focus.x - (dx / rangeX) * 100));
                        if (rangeY > 1) focus.y = Math.max(0, Math.min(100, focus.y - (dy / rangeY) * 100));
                        focusPositions[id] = focus;
                        image.style.objectPosition = focus.x + '% ' + focus.y + '%';
                    }

                    function up() {
                        image.style.cursor = 'grab';
                        document.removeEventListener('mousemove', move);
                        document.removeEventListener('mouseup', up);
                    }

                    document.addEventListener('mousemove', move);
                    document.addEventListener('mouseup', up);
                });
            });
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
                focus_positions: JSON.stringify(focusPositions),
                gap:       gap,
                emphasis:  (document.getElementById('mosaic-emphasis') || {}).value || 'natural',
                layout:    (document.getElementById('mosaic-layout') || {}).value || 'asymmetric'
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
