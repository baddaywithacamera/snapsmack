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
require_once 'core/alt-text.php';

// Ensure media storage directory exists.
$target_dir = "media_assets/";
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0755, true);
}

// SECAUDIT 047: defence-in-depth — deny script execution inside media_assets/.
// media_assets/ is web-served and (unlike img_uploads/) was NOT covered by any
// PHP-deny rule, so an uploaded .php would run. Drop a deny-all handler rule so
// even if a bad file lands here it can never execute. Apache-only, harmless on
// nginx (which is configured separately).
$__media_htaccess = $target_dir . '.htaccess';
if (!file_exists($__media_htaccess)) {
    @file_put_contents(
        $__media_htaccess,
        "# SECAUDIT 047 — media assets are data, never executable code.\n"
        . "php_flag engine off\n"
        . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht\n"
        . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht\n"
        . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|php8|phar|pht|cgi|pl)$\">\n"
        . "  SetHandler none\n"
        . "  Require all denied\n"
        . "</FilesMatch>\n"
    );
}

// SECAUDIT 047: never trust the client-supplied file extension. Derive the
// stored extension from the real MIME type and accept only raster images.
// Returns a safe extension, or null for anything that isn't an allowed image
// (blocks .php/.svg/.html webshell and XSS uploads). Mirrors pixelfed-api.php.
if (!function_exists('snap_media_safe_ext')) {
    function snap_media_safe_ext(string $tmp_path): ?string {
        if ($tmp_path === '' || !is_file($tmp_path)) return null;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp_path) ?: '';
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        return $map[$mime] ?? null;
    }
}

// --- DEFENSIVE SCHEMA (belt-and-suspenders; canonical is source of truth) ---
// Per-asset global border controls. Pure structural add — no migration file
// needed (see CLAUDE.md new-column checklist). Applied everywhere [img:ID]
// renders, read through the parser's existing asset SELECT (zero extra query).
$pdo->exec("ALTER TABLE snap_assets ADD COLUMN IF NOT EXISTS asset_border_width TINYINT UNSIGNED NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE snap_assets ADD COLUMN IF NOT EXISTS asset_border_color VARCHAR(7) NOT NULL DEFAULT '#000000'");
$pdo->exec("ALTER TABLE snap_assets ADD COLUMN IF NOT EXISTS asset_alt VARCHAR(500) DEFAULT NULL");

// --- ALT SAVE (AJAX) ---
// Persists per-asset accessibility ALT text. Rendered everywhere [img:ID] embeds
// the asset (parser falls back to asset_name when blank).
if (isset($_POST['alt_id'])) {
    $alt_id  = (int)$_POST['alt_id'];
    $alt_val = snap_sanitize_alt($_POST['asset_alt'] ?? '');
    $ok = $pdo->prepare("UPDATE snap_assets SET asset_alt = ? WHERE id = ?")
              ->execute([$alt_val, $alt_id]);
    header('Content-Type: application/json');
    echo json_encode(['status' => $ok ? 'success' : 'error']);
    exit;
}

// --- BORDER SAVE (AJAX) ---
// Persists the per-asset global border width (0-50px) and hex colour. Border
// is global: set once here, rendered everywhere the asset is embedded.
if (isset($_POST['border_id'])) {
    $border_id    = (int)$_POST['border_id'];
    $border_width = max(0, min(50, (int)($_POST['border_width'] ?? 0)));
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

    // Store the replacement under a new filename. SECAUDIT 047: extension comes
    // from the real MIME type, never the client filename.
    $file_ext = snap_media_safe_ext($_FILES['file']['tmp_name'] ?? '');
    if ($file_ext === null) {
        header('HTTP/1.1 415 Unsupported Media Type');
        echo json_encode(['status' => 'error', 'msg' => 'Only JPEG, PNG, WebP or GIF images are allowed.']);
        exit;
    }
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
    // SECAUDIT 047: extension from real MIME, never the client filename.
    $file_ext = snap_media_safe_ext($_FILES["file"]["tmp_name"] ?? '');
    if ($file_ext === null) {
        header('HTTP/1.1 415 Unsupported Media Type');
        echo json_encode(['status' => 'error', 'msg' => 'Only JPEG, PNG, WebP or GIF images are allowed.']);
        exit;
    }
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
    csrf_verify(); // SECAUDIT 047 — GET deletion must carry the CSRF token
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
                            <img src="<?php echo htmlspecialchars($a['asset_path']); ?>" alt="<?php echo snap_alt_attr($a['asset_alt'] ?? null, $a['asset_name'] ?? ''); ?>" style="<?php echo $thumb_border; ?>">
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
                                <input type="range" class="border-width" min="0" max="50" step="1"
                                       value="<?php echo $bw; ?>"
                                       data-asset-id="<?php echo $a['id']; ?>">
                                <span class="border-width-val"><?php echo $bw === 0 ? 'Off' : $bw . 'px'; ?></span>
                            </label>
                            <input type="color" class="border-color"
                                   style="width:42px;height:26px;flex:0 0 42px;padding:0;cursor:pointer;vertical-align:middle;border:1px solid var(--border-color,#444);"
                                   value="<?php echo htmlspecialchars($bc); ?>"
                                   data-asset-id="<?php echo $a['id']; ?>"
                                   title="Border colour (applies everywhere this image is used)">
                            <span class="border-saved-note"></span>
                        </div>

                        <div class="asset-alt-control mt-6">
                            <label class="border-label" style="align-items:flex-start;gap:6px;">ALT
                                <input type="text" class="asset-alt-input" maxlength="500"
                                       value="<?php echo htmlspecialchars($a['asset_alt'] ?? '', ENT_QUOTES); ?>"
                                       placeholder="Accessibility description (screen readers)"
                                       data-asset-id="<?php echo $a['id']; ?>"
                                       style="flex:1;min-width:0;">
                            </label>
                            <span class="alt-saved-note"></span>
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
                            <a href="?delete=<?php echo $a['id']; ?>&t=<?php echo urlencode(csrf_token()); ?>" class="action-delete-link" onclick="return confirm('Purge asset #<?php echo $a['id']; ?>? Any [img:<?php echo $a['id']; ?>] shortcodes will break.')">PURGE</a>
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
