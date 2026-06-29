<?php
/**
 * SNAPSMACK - Global media library and asset management
 *
 * Handles upload, storage, and retrieval of global media assets.
 * Generates shortcodes for embedding assets in pages and posts.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

// Ensure media storage directory exists.
$target_dir = "media_assets/";
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0755, true);
}

// --- DEFENSIVE SCHEMA (belt-and-suspenders; canonical is source of truth) ---
// Per-asset global border controls. Pure structural add — no migration file
// needed (see CLAUDE.md new-column checklist). Applied everywhere [img:ID]
// renders, read through the parser's existing asset SELECT (zero extra query).
$pdo->exec("ALTER TABLE snap_assets ADD COLUMN IF NOT EXISTS asset_border_width TINYINT UNSIGNED NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE snap_assets ADD COLUMN IF NOT EXISTS asset_border_color VARCHAR(7) NOT NULL DEFAULT '#000000'");

// --- BORDER SAVE (AJAX) ---
// Persists the per-asset global border width (0-10px) and hex colour. Border
// is global: set once here, rendered everywhere the asset is embedded.
if (isset($_POST['border_id'])) {
    $border_id    = (int)$_POST['border_id'];
    $border_width = max(0, min(10, (int)($_POST['border_width'] ?? 0)));
    $border_color = (string)($_POST['border_color'] ?? '#000000');

    // Validate hex colour; fall back to black on anything malformed.
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $border_color)) {
        $border_color = '#000000';
    }

    $stmt = $pdo->prepare("UPDATE snap_assets SET asset_border_width = ?, asset_border_color = ? WHERE id = ?");
    $ok = $stmt->execute([$border_width, $border_color, $border_id]);

    header('Content-Type: application/json');
    if ($ok) {
        echo json_encode(['status' => 'success']);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// --- ASSET SWAP ---
// Replaces an existing asset's file while preserving its ID and all shortcode
// references ([img:ID|size|align] embeds in pages remain valid automatically).
if (isset($_POST['swap_id']) && isset($_FILES['file'])) {
    $swap_id = (int)$_POST['swap_id'];

    $stmt = $pdo->prepare("SELECT asset_path FROM snap_assets WHERE id = ?");
    $stmt->execute([$swap_id]);
    $old_path = $stmt->fetchColumn();

    if (!$old_path) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['status' => 'error', 'msg' => 'Asset not found']);
        exit;
    }

    // Purge the old file from disk.
    if (file_exists($old_path)) {
        unlink($old_path);
    }

    // Store the replacement under a new filename.
    $file_ext   = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
    $file_name  = time() . '_' . uniqid() . '.' . $file_ext;
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES['file']['tmp_name'], $target_file)) {
        $checksum = hash_file('sha256', $target_file);
        $pdo->prepare("UPDATE snap_assets SET asset_name = ?, asset_path = ?, asset_checksum = ? WHERE id = ?")
            ->execute([$_FILES['file']['name'], $target_file, $checksum, $swap_id]);
        echo json_encode(['status' => 'success']);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['status' => 'error', 'msg' => 'Upload failed']);
    }
    exit;
}

// --- AJAX FILE UPLOAD HANDLER ---
// Processes asynchronous file uploads and returns JSON response.
if (isset($_FILES['file'])) {
    $file_ext = pathinfo($_FILES["file"]["name"], PATHINFO_EXTENSION);
    $file_name = time() . '_' . uniqid() . '.' . $file_ext;
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES["file"]["tmp_name"], $target_file)) {
        $asset_checksum = hash_file('sha256', $target_file);
        $stmt = $pdo->prepare("INSERT INTO snap_assets (asset_name, asset_path, asset_checksum) VALUES (?, ?, ?)");
        $stmt->execute([$_FILES["file"]["name"], $target_file, $asset_checksum]);
        echo json_encode(['status' => 'success']);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// --- ASSET DELETION ---
// Removes asset file from disk and deletes its database record.
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("SELECT asset_path FROM snap_assets WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    $path = $stmt->fetchColumn();

    if ($path && file_exists($path)) {
        unlink($path);
    }

    $stmt = $pdo->prepare("DELETE FROM snap_assets WHERE id = ?");
    $stmt->execute([$_GET['delete']]);

    header("Location: smack-media.php");
    exit;
}

// Load all assets ordered by creation date.
$assets = $pdo->query("SELECT * FROM snap_assets ORDER BY created_at DESC")->fetchAll();
$page_title = "Media Library";

// Formats browsers can actually render as <img>.
$web_formats = ['jpg','jpeg','png','gif','webp','svg','avif','bmp','ico'];


include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>MEDIA LIBRARY</h2>
    </div>

    <div class="box">
        <h3>INJECT GLOBAL ASSET</h3>

        <div class="progress-container" id="p-container">
            <div class="progress-bar" id="p-bar"></div>
        </div>

        <div class="file-upload-wrapper" id="drop-zone" onclick="document.getElementById('file-input').click()">
            <div class="file-custom-btn">CHOOSE FILE</div>
            <span id="file-name-display" class="file-name-display">No signal selected... or drag & drop here.</span>
            <input type="file" id="file-input" accept="image/*" class="file-input-hidden">
        </div>
    </div>

    <div class="box">
        <h3>GLOBAL ASSET GALLERY</h3>
        <div class="asset-grid">
            <?php foreach ($assets as $a):
                $ext = strtolower(pathinfo($a['asset_path'], PATHINFO_EXTENSION));
                $is_web = in_array($ext, $web_formats);
            ?>
                <?php
                    $bw = (int)($a['asset_border_width'] ?? 0);
                    $bc = $a['asset_border_color'] ?? '#000000';
                    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$bc)) { $bc = '#000000'; }
                    $thumb_border = $bw > 0 ? "border:{$bw}px solid {$bc};" : '';
                ?>
                <div class="asset-card" id="asset-<?php echo $a['id']; ?>">
                    <div class="asset-thumb-wrapper">
                        <?php if ($is_web): ?>
                            <img src="<?php echo htmlspecialchars($a['asset_path']); ?>" alt="<?php echo htmlspecialchars($a['asset_name']); ?>" style="<?php echo $thumb_border; ?>">
                        <?php else: ?>
                            <div class="asset-no-preview">.<?php echo strtoupper($ext); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="asset-info">
                        <div class="asset-filename dim"><?php echo htmlspecialchars($a['asset_name']); ?></div>

                        <div class="asset-shortcode-row">
                            <div class="shortcode-display" onclick="copyToClipboard(this)">[img:<?php echo $a['id']; ?>|full|center]</div>
                        </div>

                        <div class="asset-controls">
                            <select class="size-select" onchange="updateShortcode(<?php echo $a['id']; ?>)">
                                <option value="full">Full</option>
                                <option value="wall">Wall</option>
                                <option value="small">Small</option>
                            </select>
                            <select class="align-select" onchange="updateShortcode(<?php echo $a['id']; ?>)">
                                <option value="center">Center</option>
                                <option value="left">Left</option>
                                <option value="right">Right</option>
                            </select>
                        </div>

                        <div class="asset-border-control">
                            <label class="border-label">BORDER
                                <input type="range" class="border-width" min="0" max="10" step="1"
                                       value="<?php echo $bw; ?>"
                                       data-asset-id="<?php echo $a['id']; ?>">
                                <span class="border-width-val"><?php echo $bw === 0 ? 'Off' : $bw . 'px'; ?></span>
                            </label>
                            <input type="color" class="border-color"
                                   value="<?php echo htmlspecialchars($bc); ?>"
                                   data-asset-id="<?php echo $a['id']; ?>"
                                   title="Border colour (applies everywhere this image is used)">
                            <span class="border-saved-note"></span>
                        </div>

                        <div class="asset-actions">
                            <input type="file"
                                   id="swap-input-<?php echo $a['id']; ?>"
                                   accept="image/*"
                                   style="display:none"
                                   data-asset-id="<?php echo $a['id']; ?>">
                            <button type="button"
                                    class="action-edit"
                                    onclick="document.getElementById('swap-input-<?php echo $a['id']; ?>').click()">SWAP</button>
                            <a href="?delete=<?php echo $a['id']; ?>" class="action-delete-link" onclick="return confirm('Purge asset #<?php echo $a['id']; ?>? Any [img:<?php echo $a['id']; ?>] shortcodes will break.')">PURGE</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="assets/js/ss-engine-media-library.js?v=<?php echo time(); ?>"></script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
